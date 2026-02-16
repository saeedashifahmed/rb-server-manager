#!/usr/bin/env bash

set -euo pipefail

REPO_URL="https://github.com/saeedashifahmed/rb-server-manager.git"
BRANCH="main"
APP_DIR="/var/www/rb-server-manager"
APP_USER="www-data"
APP_GROUP="www-data"
DB_NAME="rb_server_manager"
DB_USER="rb_manager"
DB_PASS=""
DOMAIN=""
LETSENCRYPT_EMAIL=""

usage() {
  cat <<'EOF'
Usage:
  sudo bash launch-ubuntu.sh --domain example.com --email admin@example.com [options]

Required:
  --domain               Domain pointed to this server (A record already set)
  --email                Let's Encrypt email

Optional:
  --repo-url             Git repository URL
  --branch               Git branch (default: main)
  --app-dir              Install directory (default: /var/www/rb-server-manager)
  --db-name              MySQL database name (default: rb_server_manager)
  --db-user              MySQL user (default: rb_manager)
  --db-pass              MySQL password (default: auto-generated)

Example:
  sudo bash launch-ubuntu.sh \
    --domain app.example.com \
    --email ops@example.com
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)
      DOMAIN="$2"
      shift 2
      ;;
    --email)
      LETSENCRYPT_EMAIL="$2"
      shift 2
      ;;
    --repo-url)
      REPO_URL="$2"
      shift 2
      ;;
    --branch)
      BRANCH="$2"
      shift 2
      ;;
    --app-dir)
      APP_DIR="$2"
      shift 2
      ;;
    --db-name)
      DB_NAME="$2"
      shift 2
      ;;
    --db-user)
      DB_USER="$2"
      shift 2
      ;;
    --db-pass)
      DB_PASS="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root (or via sudo)."
  exit 1
fi

if [[ -z "${DOMAIN}" || -z "${LETSENCRYPT_EMAIL}" ]]; then
  echo "--domain and --email are required."
  usage
  exit 1
fi

if [[ -z "${DB_PASS}" ]]; then
  DB_PASS="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 24)"
fi

PHP_VERSION="8.3"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
NGINX_SITE="rb-server-manager"
PROJECT_NAME="RB Server Manager"

set_env() {
  local key="$1"
  local value="$2"
  local escaped
  escaped="$(printf '%s' "$value" | sed 's/[&/]/\\&/g')"

  if grep -q "^${key}=" .env; then
    sed -i "s/^${key}=.*/${key}=${escaped}/" .env
  else
    echo "${key}=${value}" >> .env
  fi
}

echo "[1/10] Updating apt packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y

echo "[2/10] Installing system dependencies..."
apt-get install -y \
  software-properties-common \
  ca-certificates \
  apt-transport-https \
  lsb-release \
  gnupg2 \
  curl \
  unzip \
  git \
  nginx \
  mysql-server \
  supervisor \
  certbot \
  python3-certbot-nginx

if ! add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1; then
  echo "[WARN] Could not add ondrej/php PPA (may already exist)."
fi

apt-get update -y
apt-get install -y \
  php${PHP_VERSION}-cli \
  php${PHP_VERSION}-fpm \
  php${PHP_VERSION}-mysql \
  php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-xml \
  php${PHP_VERSION}-curl \
  php${PHP_VERSION}-zip \
  php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-intl \
  php${PHP_VERSION}-gd \
  php${PHP_VERSION}-common

if ! command -v composer >/dev/null 2>&1; then
  echo "[3/10] Installing Composer..."
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
else
  echo "[3/10] Composer already installed."
fi

echo "[4/10] Enabling services..."
systemctl enable --now mysql
systemctl enable --now "${PHP_FPM_SERVICE}"
systemctl enable --now nginx
systemctl enable --now supervisor

echo "[5/10] Cloning/updating application..."
mkdir -p "$(dirname "${APP_DIR}")"
if [[ -d "${APP_DIR}/.git" ]]; then
  git -C "${APP_DIR}" fetch --all --prune
  git -C "${APP_DIR}" checkout "${BRANCH}"
  git -C "${APP_DIR}" pull --ff-only origin "${BRANCH}"
else
  rm -rf "${APP_DIR}"
  git clone --branch "${BRANCH}" "${REPO_URL}" "${APP_DIR}"
fi

cd "${APP_DIR}"

echo "[6/10] Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "[7/10] Configuring Laravel environment..."
if [[ ! -f .env ]]; then
  cp .env.example .env
fi

set_env "APP_NAME" "\"${PROJECT_NAME}\""
set_env "APP_ENV" "production"
set_env "APP_DEBUG" "false"
set_env "APP_URL" "https://${DOMAIN}"

set_env "DB_CONNECTION" "mysql"
set_env "DB_HOST" "127.0.0.1"
set_env "DB_PORT" "3306"
set_env "DB_DATABASE" "${DB_NAME}"
set_env "DB_USERNAME" "${DB_USER}"
set_env "DB_PASSWORD" "${DB_PASS}"
set_env "QUEUE_CONNECTION" "database"

php artisan key:generate --force

echo "[8/10] Creating database and running migrations..."
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \\`${DB_NAME}\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \\`${DB_NAME}\\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R "${APP_USER}:${APP_GROUP}" "${APP_DIR}"
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;

echo "[9/10] Configuring Nginx + PHP-FPM..."
cat > "/etc/nginx/sites-available/${NGINX_SITE}" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php index.html;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    charset utf-8;

    location / {
        try_files \\$uri \\$uri/ /index.php?\\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

ln -sf "/etc/nginx/sites-available/${NGINX_SITE}" "/etc/nginx/sites-enabled/${NGINX_SITE}"
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl reload nginx

echo "[10/10] Configuring queue workers + SSL..."
cat > /etc/supervisor/conf.d/rb-server-manager-worker.conf <<EOF
[program:rb-server-manager-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${APP_DIR}/artisan queue:work --sleep=3 --tries=1 --timeout=900
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=${APP_USER}
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/rb-server-manager-worker.log
stopwaitsecs=3600
directory=${APP_DIR}
EOF

supervisorctl reread
supervisorctl update
supervisorctl restart rb-server-manager-worker:*

certbot --nginx \
  --non-interactive \
  --agree-tos \
  --redirect \
  --email "${LETSENCRYPT_EMAIL}" \
  -d "${DOMAIN}"

systemctl reload nginx

echo
echo "============================================="
echo "${PROJECT_NAME} installation completed"
echo "============================================="
echo "App URL: https://${DOMAIN}"
echo "Project dir: ${APP_DIR}"
echo "DB name: ${DB_NAME}"
echo "DB user: ${DB_USER}"
echo "DB pass: ${DB_PASS}"
echo
echo "Queue workers are managed by Supervisor."
echo "Use: supervisorctl status"
echo "Nginx: systemctl status nginx"
echo "PHP-FPM: systemctl status ${PHP_FPM_SERVICE}"
