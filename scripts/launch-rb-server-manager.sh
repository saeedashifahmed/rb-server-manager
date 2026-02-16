#!/usr/bin/env bash
set -Eeuo pipefail

############################################
# RB Server Manager - One-shot Ubuntu Launch Script
# Repo: https://github.com/saeedashifahmed/rb-server-manager.git
############################################

# ====== USER CONFIG (edit if needed) ======
APP_DOMAIN="${APP_DOMAIN:-}"                       # e.g. manager.example.com (required for SSL)
APP_PATH="${APP_PATH:-/var/www/rb-server-manager}"
REPO_URL="${REPO_URL:-https://github.com/saeedashifahmed/rb-server-manager.git}"
PHP_VERSION="${PHP_VERSION:-8.3}"                 # supported: 8.1, 8.2, 8.3, 8.4
APP_TIMEZONE="${APP_TIMEZONE:-UTC}"

DB_NAME="${DB_NAME:-rb_server_manager}"
DB_USER="${DB_USER:-rb_manager}"
DB_PASS="${DB_PASS:-$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)}"

ENABLE_SSL="${ENABLE_SSL:-true}"                  # true/false
NONINTERACTIVE="${NONINTERACTIVE:-true}"          # true/false
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"                # recommended: you@example.com
# ==========================================

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: Run as root: sudo bash $0"
  exit 1
fi

if [[ -f /etc/os-release ]]; then
  . /etc/os-release
else
  echo "ERROR: /etc/os-release not found. Unsupported OS."
  exit 1
fi

if [[ "${ID}" != "ubuntu" ]]; then
  echo "ERROR: This launch script supports Ubuntu only. Detected: ${ID}"
  exit 1
fi

if [[ -z "${APP_DOMAIN}" ]]; then
  echo "ERROR: APP_DOMAIN is required."
  echo "Example: APP_DOMAIN=manager.example.com sudo bash $0"
  exit 1
fi

if [[ "${NONINTERACTIVE}" == "true" ]]; then
  export DEBIAN_FRONTEND=noninteractive
fi

APP_URL="https://${APP_DOMAIN}"
if [[ "${ENABLE_SSL}" != "true" ]]; then
  APP_URL="http://${APP_DOMAIN}"
fi

PHP_PKG_PREFIX="php${PHP_VERSION}"
PHP_FPM_SOCK="/run/php/${PHP_PKG_PREFIX}-fpm.sock"

log() {
  echo
  echo "[+] $*"
}

set_env_value() {
  local key="$1"
  local value="$2"
  local env_file="$3"

  if grep -qE "^${key}=" "${env_file}"; then
    sed -i.bak "s|^${key}=.*|${key}=${value}|" "${env_file}"
  else
    echo "${key}=${value}" >> "${env_file}"
  fi
}

log "Updating apt and installing base packages"
apt-get update -y
apt-get upgrade -y
apt-get install -y \
  curl wget unzip git gnupg2 ca-certificates lsb-release software-properties-common \
  nginx mysql-server supervisor certbot python3-certbot-nginx ufw

log "Installing PHP ${PHP_VERSION} and required extensions"
add-apt-repository -y ppa:ondrej/php || true
apt-get update -y
apt-get install -y \
  "${PHP_PKG_PREFIX}" \
  "${PHP_PKG_PREFIX}-cli" \
  "${PHP_PKG_PREFIX}-common" \
  "${PHP_PKG_PREFIX}-fpm" \
  "${PHP_PKG_PREFIX}-mysql" \
  "${PHP_PKG_PREFIX}-mbstring" \
  "${PHP_PKG_PREFIX}-xml" \
  "${PHP_PKG_PREFIX}-curl" \
  "${PHP_PKG_PREFIX}-zip" \
  "${PHP_PKG_PREFIX}-gd" \
  "${PHP_PKG_PREFIX}-bcmath" \
  "${PHP_PKG_PREFIX}-intl" \
  "${PHP_PKG_PREFIX}-soap" \
  "${PHP_PKG_PREFIX}-imagick" \
  "${PHP_PKG_PREFIX}-opcache"

systemctl enable "${PHP_PKG_PREFIX}-fpm"
systemctl restart "${PHP_PKG_PREFIX}-fpm"

if [[ ! -S "${PHP_FPM_SOCK}" ]]; then
  echo "ERROR: PHP-FPM socket not found at ${PHP_FPM_SOCK}"
  exit 1
fi

log "Installing Composer (if missing)"
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
  if [[ "${EXPECTED_CHECKSUM}" != "${ACTUAL_CHECKSUM}" ]]; then
    echo "ERROR: Composer installer checksum mismatch"
    rm -f composer-setup.php
    exit 1
  fi
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f composer-setup.php
fi

log "Preparing application directory"
mkdir -p "$(dirname "${APP_PATH}")"

if [[ -d "${APP_PATH}/.git" ]]; then
  log "Existing repo found - pulling latest"
  git -C "${APP_PATH}" fetch --all --prune
  git -C "${APP_PATH}" reset --hard origin/main || git -C "${APP_PATH}" reset --hard origin/master
else
  log "Cloning repository"
  rm -rf "${APP_PATH}"
  git clone "${REPO_URL}" "${APP_PATH}"
fi

cd "${APP_PATH}"

log "Installing PHP dependencies"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction

log "Configuring .env"
if [[ ! -f .env ]]; then
  cp .env.example .env
fi

set_env_value "APP_NAME" "\"RB Server Manager\"" .env
set_env_value "APP_ENV" "production" .env
set_env_value "APP_KEY" "" .env
set_env_value "APP_DEBUG" "false" .env
set_env_value "APP_URL" "${APP_URL}" .env
set_env_value "APP_TIMEZONE" "${APP_TIMEZONE}" .env

set_env_value "DB_CONNECTION" "mysql" .env
set_env_value "DB_HOST" "127.0.0.1" .env
set_env_value "DB_PORT" "3306" .env
set_env_value "DB_DATABASE" "${DB_NAME}" .env
set_env_value "DB_USERNAME" "${DB_USER}" .env
set_env_value "DB_PASSWORD" "${DB_PASS}" .env

set_env_value "QUEUE_CONNECTION" "database" .env
set_env_value "LOG_CHANNEL" "stack" .env
set_env_value "SESSION_DRIVER" "file" .env
set_env_value "CACHE_DRIVER" "file" .env

log "Generating Laravel app key"
php artisan key:generate --force --no-interaction

log "Creating MySQL database and user"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

log "Running migrations"
php artisan migrate --force --no-interaction

log "Optimizing Laravel"
php artisan config:cache
php artisan route:cache
php artisan view:cache

log "Setting file permissions"
chown -R www-data:www-data "${APP_PATH}"
find "${APP_PATH}" -type f -exec chmod 644 {} \;
find "${APP_PATH}" -type d -exec chmod 755 {} \;
chmod -R ug+rwx "${APP_PATH}/storage" "${APP_PATH}/bootstrap/cache"

log "Configuring Nginx"
cat > "/etc/nginx/sites-available/rb-server-manager.conf" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${APP_DOMAIN};

    root ${APP_PATH}/public;
    index index.php index.html;

    access_log /var/log/nginx/rb-server-manager.access.log;
    error_log /var/log/nginx/rb-server-manager.error.log;

    client_max_body_size 64M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/rb-server-manager.conf /etc/nginx/sites-enabled/rb-server-manager.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable nginx
systemctl restart nginx

log "Configuring Supervisor queue worker"
cat > /etc/supervisor/conf.d/rb-server-manager-worker.conf <<SUP
[program:rb-server-manager-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php ${APP_PATH}/artisan queue:work database --sleep=3 --tries=1 --timeout=1500 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/rb-server-manager-worker.log
stopwaitsecs=3600
directory=${APP_PATH}
SUP

systemctl enable supervisor
systemctl restart supervisor
supervisorctl reread
supervisorctl update
supervisorctl restart rb-server-manager-worker:*

log "Ensuring required services are active"
systemctl enable mysql || true
systemctl restart mysql || true
systemctl is-active nginx >/dev/null
systemctl is-active "${PHP_PKG_PREFIX}-fpm" >/dev/null
systemctl is-active supervisor >/dev/null

log "Configuring UFW (OpenSSH + HTTP/HTTPS)"
ufw allow OpenSSH || true
ufw allow 'Nginx Full' || true
ufw --force enable || true

if [[ "${ENABLE_SSL}" == "true" ]]; then
  log "Requesting Let's Encrypt SSL certificate"
  CERTBOT_ARGS=(--nginx -d "${APP_DOMAIN}" --non-interactive --agree-tos --redirect)
  if [[ -n "${CERTBOT_EMAIL}" ]]; then
    CERTBOT_ARGS+=(--email "${CERTBOT_EMAIL}")
  else
    CERTBOT_ARGS+=(--register-unsafely-without-email)
  fi

  certbot "${CERTBOT_ARGS[@]}" || {
    echo "WARNING: SSL setup failed (likely DNS not propagated yet). App is available via HTTP for now."
  }
fi

log "Final status"
PHP_VER_INSTALLED="$(php -v | head -n1)"
QUEUE_STATUS="$(supervisorctl status rb-server-manager-worker:* | tr '\n' '; ')"

echo "==============================================="
echo "RB Server Manager installed successfully"
echo "URL: ${APP_URL}"
echo "Path: ${APP_PATH}"
echo "PHP: ${PHP_VER_INSTALLED}"
echo "DB Name: ${DB_NAME}"
echo "DB User: ${DB_USER}"
echo "DB Pass: ${DB_PASS}"
echo "Queue: ${QUEUE_STATUS}"
echo "==============================================="
