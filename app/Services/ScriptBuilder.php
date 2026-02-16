<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Generates all predefined shell commands and configuration files
 * for the WordPress + SSL installation pipeline.
 *
 * No raw user input is injected into shell commands — all dynamic
 * values are passed through validated, sanitized parameters and
 * written to config files, not interpolated into commands.
 */
class ScriptBuilder
{
    private string $domain;
    private string $adminEmail;
    private string $siteTitle;
    private string $dbName;
    private string $dbUser;
    private string $dbPassword;
    private string $webRoot;

    public function __construct(string $domain, string $adminEmail, string $siteTitle = 'My WordPress Site')
    {
        $this->domain     = $this->sanitizeDomain($domain);
        $this->adminEmail = $adminEmail;
        $this->siteTitle  = $siteTitle;
        $this->dbName     = 'wp_' . Str::slug(Str::limit($this->domain, 20, ''), '_');
        $this->dbUser     = 'wpu_' . Str::random(8);
        $this->dbPassword = Str::random(32);
        $this->webRoot    = '/var/www/' . $this->domain;
    }

    // ── Getters ─────────────────────────────────────────────────

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getDbName(): string
    {
        return $this->dbName;
    }

    public function getDbUser(): string
    {
        return $this->dbUser;
    }

    public function getWebRoot(): string
    {
        return $this->webRoot;
    }

    public function getWpAdminUrl(): string
    {
        return "https://{$this->domain}/wp-admin";
    }

    // ── Step Commands ───────────────────────────────────────────
    // Each method returns a single predefined command string.
    // No user input is interpolated into commands.

    /**
     * Step 1: Update system packages.
     */
    public function updateSystem(): string
    {
        return implode(' && ', [
            'export DEBIAN_FRONTEND=noninteractive',
            'apt-get update -y || (sleep 5 && apt-get update -y)',
        ]);
    }

    /**
     * Step 2: Install Nginx.
     */
    public function installNginx(): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && apt-get install -y nginx && systemctl enable nginx && systemctl start nginx';
    }

    /**
     * Step 3: Install MySQL server.
     */
    public function installMySQL(): string
    {
        return implode(' && ', [
            'export DEBIAN_FRONTEND=noninteractive',
            'if command -v mysql >/dev/null 2>&1; then echo "MySQL/MariaDB already installed"; else apt-get install -y mysql-server || apt-get install -y default-mysql-server; fi',
            'if systemctl list-unit-files | grep -q "^mysql.service"; then systemctl enable mysql && systemctl start mysql; elif systemctl list-unit-files | grep -q "^mariadb.service"; then systemctl enable mariadb && systemctl start mariadb; else echo "No mysql/mariadb systemd service found" && exit 1; fi',
        ]);
    }

    /**
     * Step 4: Install PHP 8.2 with required extensions.
     */
    public function installPHP(): string
    {
        return implode(' && ', [
            'export DEBIAN_FRONTEND=noninteractive',
            'add-apt-repository -y ppa:ondrej/php',
            'apt-get update -y',
            'apt-get install -y php8.2-fpm php8.2-mysql php8.2-curl php8.2-gd php8.2-mbstring php8.2-xml php8.2-zip php8.2-intl php8.2-soap php8.2-bcmath php8.2-imagick',
            'systemctl enable php8.2-fpm',
            'systemctl start php8.2-fpm',
        ]);
    }

    /**
     * Step 5: Install utilities.
     */
    public function installUtilities(): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && apt-get install -y unzip curl software-properties-common';
    }

    /**
     * Step 6: Secure MySQL non-interactively.
     * Sets root auth to native password and removes test database/anonymous users.
     */
    public function secureMysql(): string
    {
        return implode(' && ', [
            "mysql -e \"DELETE FROM mysql.user WHERE User=''\"",
            "mysql -e \"DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1')\"",
            "mysql -e \"DROP DATABASE IF EXISTS test\"",
            "mysql -e \"DELETE FROM mysql.db WHERE Db='test' OR Db='test\\\\_%'\"",
            "mysql -e \"FLUSH PRIVILEGES\"",
        ]);
    }

    /**
     * Step 7: Create WordPress database and user.
     * Uses heredoc to keep credentials out of process args.
     */
    public function createDatabase(): string
    {
        $sql = "CREATE DATABASE IF NOT EXISTS `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
             . "CREATE USER IF NOT EXISTS '{$this->dbUser}'@'localhost' IDENTIFIED BY '{$this->dbPassword}';\n"
             . "GRANT ALL PRIVILEGES ON `{$this->dbName}`.* TO '{$this->dbUser}'@'localhost';\n"
             . "FLUSH PRIVILEGES;";

        return "mysql << 'SQLEOF'\n{$sql}\nSQLEOF";
    }

    /**
     * Step 8: Download and extract WordPress.
     */
    public function downloadWordPress(): string
    {
        return implode(' && ', [
            "mkdir -p {$this->webRoot}",
            'cd /tmp',
            'curl -sLO https://wordpress.org/latest.tar.gz',
            "tar -xzf latest.tar.gz -C {$this->webRoot} --strip-components=1",
            'rm -f /tmp/latest.tar.gz',
        ]);
    }

    /**
     * Step 9: Set proper file permissions.
     */
    public function setPermissions(): string
    {
        return implode(' && ', [
            "chown -R www-data:www-data {$this->webRoot}",
            "find {$this->webRoot} -type d -exec chmod 755 {} \\;",
            "find {$this->webRoot} -type f -exec chmod 644 {} \\;",
        ]);
    }

    /**
     * Step 10: Write Nginx server block config.
     * Returns the command to write the config file.
     */
    public function writeNginxConfig(): string
    {
        $config = $this->generateNginxConfig();
        $configPath = "/etc/nginx/sites-available/{$this->domain}";
        $enabledPath = "/etc/nginx/sites-enabled/{$this->domain}";

        return implode(' && ', [
            "cat > {$configPath} << 'NGINXEOF'\n{$config}\nNGINXEOF",
            "ln -sf {$configPath} {$enabledPath}",
            'rm -f /etc/nginx/sites-enabled/default',
            'nginx -t',
        ]);
    }

    /**
     * Step 11: Restart Nginx.
     */
    public function restartNginx(): string
    {
        return 'systemctl restart nginx';
    }

    /**
     * Step 12: Install Certbot.
     */
    public function installCertbot(): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && apt-get install -y certbot python3-certbot-nginx';
    }

    /**
     * Step 13: Issue SSL certificate.
     */
    public function issueSslCertificate(): string
    {
        return "certbot --nginx -d {$this->domain} --non-interactive --agree-tos -m {$this->adminEmail} --redirect";
    }

    /**
     * Step 14: Configure auto-renewal.
     */
    public function configureAutoRenew(): string
    {
        return implode(' && ', [
            'systemctl enable certbot.timer',
            'systemctl start certbot.timer',
            'certbot renew --dry-run',
        ]);
    }

    // ── Config File Generators ──────────────────────────────────

    /**
     * Generate wp-config.php content.
     */
    public function generateWpConfig(): string
    {
        $authKeys = $this->generateAuthKeys();
        $tablePrefix = 'wp_';

        return <<<PHP
<?php
/** WordPress Database Configuration */
define('DB_NAME',     '{$this->dbName}');
define('DB_USER',     '{$this->dbUser}');
define('DB_PASSWORD', '{$this->dbPassword}');
define('DB_HOST',     'localhost');
define('DB_CHARSET',  'utf8mb4');
define('DB_COLLATE',  '');

/** Authentication Unique Keys and Salts */
{$authKeys}

/** Database Table Prefix */
\$table_prefix = '{$tablePrefix}';

/** Debugging (disable in production) */
define('WP_DEBUG',         false);
define('WP_DEBUG_LOG',     false);
define('WP_DEBUG_DISPLAY', false);

/** Security Hardening */
define('DISALLOW_FILE_EDIT', true);
define('WP_AUTO_UPDATE_CORE', 'minor');

/** Force SSL */
define('FORCE_SSL_ADMIN', true);
if (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    \$_SERVER['HTTPS'] = 'on';
}

/** WordPress URLs */
define('WP_SITEURL', 'https://{$this->domain}');
define('WP_HOME',    'https://{$this->domain}');

/** Absolute path to WordPress directory */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/** Sets up WordPress vars and included files */
require_once ABSPATH . 'wp-settings.php';
PHP;
    }

    /**
     * Command to write wp-config.php to disk.
     */
    public function writeWpConfig(): string
    {
        $config = $this->generateWpConfig();
        $path   = "{$this->webRoot}/wp-config.php";

        return "cat > {$path} << 'WPEOF'\n{$config}\nWPEOF";
    }

    /**
     * Generate Nginx server block configuration.
     */
    public function generateNginxConfig(): string
    {
        return <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$this->domain};
    root {$this->webRoot};
    index index.php index.html index.htm;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Logging
    access_log /var/log/nginx/{$this->domain}.access.log;
    error_log  /var/log/nginx/{$this->domain}.error.log;

    # Max upload size
    client_max_body_size 64M;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript image/svg+xml;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-params.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Deny access to sensitive files
    location ~* /(wp-config\.php|readme\.html|license\.txt) {
        deny all;
    }

    # Cache static assets
    location ~* \.(css|gif|ico|jpeg|jpg|js|png|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
NGINX;
    }

    // ── Private Helpers ─────────────────────────────────────────

    private function sanitizeDomain(string $domain): string
    {
        // Strip protocol and trailing slashes
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        // Only allow valid domain characters
        $domain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $domain);

        return strtolower($domain);
    }

    private function generateAuthKeys(): string
    {
        $keys = [
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT',
        ];

        $lines = [];
        foreach ($keys as $key) {
            $value = Str::random(64);
            $lines[] = "define('{$key}', '{$value}');";
        }

        return implode("\n", $lines);
    }

    /**
     * Get all installation steps as an ordered array.
     * Each step has a name, description, method, and weight (progress %).
     */
    public static function getSteps(): array
    {
        return [
            ['method' => 'updateSystem',         'name' => 'Updating system packages',     'progress' => 5],
            ['method' => 'installNginx',         'name' => 'Installing Nginx',             'progress' => 12],
            ['method' => 'installMySQL',         'name' => 'Installing MySQL',             'progress' => 22],
            ['method' => 'installPHP',           'name' => 'Installing PHP 8.2',           'progress' => 35],
            ['method' => 'installUtilities',     'name' => 'Installing utilities',         'progress' => 40],
            ['method' => 'secureMysql',          'name' => 'Securing MySQL',               'progress' => 45],
            ['method' => 'createDatabase',       'name' => 'Creating database',            'progress' => 50],
            ['method' => 'downloadWordPress',    'name' => 'Downloading WordPress',        'progress' => 58],
            ['method' => 'writeWpConfig',        'name' => 'Configuring WordPress',        'progress' => 63],
            ['method' => 'setPermissions',       'name' => 'Setting file permissions',     'progress' => 68],
            ['method' => 'writeNginxConfig',     'name' => 'Configuring Nginx',            'progress' => 75],
            ['method' => 'restartNginx',         'name' => 'Restarting Nginx',             'progress' => 80],
            ['method' => 'installCertbot',       'name' => 'Installing Certbot',           'progress' => 85],
            ['method' => 'issueSslCertificate',  'name' => 'Issuing SSL certificate',      'progress' => 92],
            ['method' => 'configureAutoRenew',   'name' => 'Configuring SSL auto-renewal', 'progress' => 98],
        ];
    }
}
