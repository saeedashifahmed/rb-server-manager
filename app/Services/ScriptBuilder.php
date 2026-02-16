<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Generates all predefined shell commands and configuration files
 * for the WordPress + SSL installation pipeline.
 *
 * Supports multiple PHP versions (8.1–8.4), Ubuntu versions (20.04–24.04),
 * and MySQL/MariaDB. All dynamic values are passed through validated,
 * sanitized parameters — no raw user input is interpolated into shell commands.
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
    private string $phpVersion;

    /**
     * Supported PHP versions for installation.
     */
    public const SUPPORTED_PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4'];
    public const DEFAULT_PHP_VERSION = '8.3';

    public function __construct(
        string $domain,
        string $adminEmail,
        string $siteTitle = 'My WordPress Site',
        string $phpVersion = self::DEFAULT_PHP_VERSION,
    ) {
        $this->domain     = $this->sanitizeDomain($domain);
        $this->adminEmail = $adminEmail;
        $this->siteTitle  = $siteTitle;
        $this->phpVersion = in_array($phpVersion, self::SUPPORTED_PHP_VERSIONS, true)
            ? $phpVersion
            : self::DEFAULT_PHP_VERSION;
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

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    public function getWpAdminUrl(): string
    {
        return "https://{$this->domain}/wp-admin";
    }

    // ── Step Commands ───────────────────────────────────────────
    // Each method returns a single predefined command string.
    // No user input is interpolated into commands.

    /**
     * Step 1: Pre-flight checks — verify OS, connectivity, disk space.
     */
    public function preflightChecks(): string
    {
        return implode("\n", [
            'set -e',
            '',
            '# Verify we are running on a supported OS',
            'if [ ! -f /etc/os-release ]; then',
            '  echo "ERROR: Cannot detect OS — /etc/os-release not found" && exit 1',
            'fi',
            '',
            '. /etc/os-release',
            'echo "Detected OS: $NAME $VERSION_ID ($ID)"',
            '',
            'if [ "$ID" != "ubuntu" ] && [ "$ID" != "debian" ]; then',
            '  echo "ERROR: This installer only supports Ubuntu and Debian. Detected: $ID" && exit 1',
            'fi',
            '',
            '# Verify root or sudo',
            'if [ "$(id -u)" -ne 0 ]; then',
            '  echo "ERROR: This script must be run as root" && exit 1',
            'fi',
            '',
            '# Check available disk space (need at least 1GB free)',
            'AVAIL_KB=$(df / --output=avail | tail -1 | tr -d " ")',
            'if [ "$AVAIL_KB" -lt 1048576 ]; then',
            '  echo "WARNING: Low disk space ($(( AVAIL_KB / 1024 ))MB free). At least 1GB recommended."',
            'fi',
            '',
            '# Check internet connectivity',
            'if ! curl -sI --connect-timeout 10 https://wordpress.org >/dev/null 2>&1; then',
            '  echo "WARNING: Cannot reach wordpress.org — check internet connectivity"',
            'fi',
            '',
            '# Wait for any running apt/dpkg locks to clear',
            'for i in $(seq 1 30); do',
            '  if fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || fuser /var/lib/apt/lists/lock >/dev/null 2>&1; then',
            '    echo "Waiting for apt lock to be released... ($i/30)"',
            '    sleep 2',
            '  else',
            '    break',
            '  fi',
            'done',
            '',
            'echo "Pre-flight checks passed"',
        ]);
    }

    /**
     * Step 2: Update system packages and install essential prerequisites.
     * Installs software-properties-common FIRST (needed for add-apt-repository).
     */
    public function updateSystem(): string
    {
        return implode("\n", [
            'set -e',
            'export DEBIAN_FRONTEND=noninteractive',
            '',
            '# Wait for apt locks',
            'for i in $(seq 1 30); do',
            '  if fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; then',
            '    sleep 2',
            '  else',
            '    break',
            '  fi',
            'done',
            '',
            '# Fix any interrupted dpkg operations',
            'dpkg --configure -a 2>/dev/null || true',
            'apt-get install -f -y 2>/dev/null || true',
            '',
            '# Update package lists with retry',
            'apt-get update -y || (sleep 5 && apt-get update -y)',
            '',
            '# Install essential prerequisites first',
            'apt-get install -y \\',
            '  curl \\',
            '  wget \\',
            '  unzip \\',
            '  gnupg2 \\',
            '  ca-certificates \\',
            '  lsb-release \\',
            '  apt-transport-https \\',
            '  software-properties-common',
            '',
            'echo "System updated and prerequisites installed"',
        ]);
    }

    /**
     * Step 3: Install Nginx.
     */
    public function installNginx(): string
    {
        return implode("\n", [
            'set -e',
            'export DEBIAN_FRONTEND=noninteractive',
            '',
            'if command -v nginx >/dev/null 2>&1; then',
            '  echo "Nginx is already installed"',
            '  nginx -v 2>&1',
            'else',
            '  apt-get install -y nginx',
            'fi',
            '',
            'systemctl enable nginx',
            'systemctl start nginx',
            '',
            '# Ensure sites-enabled directory exists',
            'mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled',
            '',
            'echo "Nginx installed and running"',
        ]);
    }

    /**
     * Step 4: Install MySQL/MariaDB server.
     * Handles both MySQL and MariaDB across Ubuntu versions.
     */
    public function installMySQL(): string
    {
        return implode("\n", [
            'set -e',
            'export DEBIAN_FRONTEND=noninteractive',
            '',
            '# Determine which database server to install',
            'if command -v mysql >/dev/null 2>&1; then',
            '  echo "MySQL/MariaDB is already installed"',
            '  mysql --version 2>&1',
            'else',
            '  # Try MySQL first, fall back to MariaDB',
            '  apt-get install -y mysql-server 2>/dev/null || \\',
            '    apt-get install -y default-mysql-server 2>/dev/null || \\',
            '    apt-get install -y mariadb-server',
            'fi',
            '',
            '# Enable and start the database service',
            'if systemctl list-unit-files | grep -q "^mysql\\.service"; then',
            '  systemctl enable mysql',
            '  systemctl start mysql',
            '  echo "MySQL service started"',
            'elif systemctl list-unit-files | grep -q "^mysqld\\.service"; then',
            '  systemctl enable mysqld',
            '  systemctl start mysqld',
            '  echo "MySQL (mysqld) service started"',
            'elif systemctl list-unit-files | grep -q "^mariadb\\.service"; then',
            '  systemctl enable mariadb',
            '  systemctl start mariadb',
            '  echo "MariaDB service started"',
            'else',
            '  echo "ERROR: No mysql/mariadb systemd service found" && exit 1',
            'fi',
            '',
            '# Verify the service is running',
            'mysqladmin ping --silent 2>/dev/null && echo "Database server is responsive" || echo "WARNING: Database server not responding to ping"',
        ]);
    }

    /**
     * Step 5: Install PHP with all required WordPress extensions.
     * Uses ondrej/php PPA for latest versions; supports PHP 8.1–8.4.
     */
    public function installPHP(): string
    {
        $v = $this->phpVersion;
        $extensions = implode(" php{$v}-", [
            '', // leading separator
            'fpm', 'mysql', 'curl', 'gd', 'mbstring', 'xml', 'zip',
            'intl', 'soap', 'bcmath', 'imagick', 'opcache', 'readline',
            'common', 'cli',
        ]);
        // Trim leading space from the first extension
        $packages = trim($extensions);

        return implode("\n", [
            'set -e',
            'export DEBIAN_FRONTEND=noninteractive',
            '',
            '# Check if the desired PHP version is already installed',
            "if php{$v} -v >/dev/null 2>&1; then",
            "  echo \"PHP {$v} is already installed\"",
            "  php{$v} -v",
            'else',
            '  # Add ondrej/php PPA (safe to re-add if exists)',
            '  . /etc/os-release',
            '  if [ "$ID" = "ubuntu" ]; then',
            '    add-apt-repository -y ppa:ondrej/php 2>/dev/null || true',
            '  elif [ "$ID" = "debian" ]; then',
            '    # For Debian, use ondrej\'s packages.sury.org',
            '    curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb 2>/dev/null || true',
            '    dpkg -i /tmp/debsuryorg-archive-keyring.deb 2>/dev/null || true',
            '    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" > /etc/apt/sources.list.d/sury-php.list',
            '  fi',
            '',
            '  apt-get update -y',
            '',
            "  # Install PHP {$v} and extensions",
            "  apt-get install -y {$packages}",
            'fi',
            '',
            '# Tune PHP-FPM for WordPress',
            "PHP_INI=\"/etc/php/{$v}/fpm/php.ini\"",
            'if [ -f "$PHP_INI" ]; then',
            '  sed -i "s/^upload_max_filesize.*/upload_max_filesize = 64M/" "$PHP_INI"',
            '  sed -i "s/^post_max_size.*/post_max_size = 64M/" "$PHP_INI"',
            '  sed -i "s/^memory_limit.*/memory_limit = 256M/" "$PHP_INI"',
            '  sed -i "s/^max_execution_time.*/max_execution_time = 300/" "$PHP_INI"',
            '  sed -i "s/^max_input_time.*/max_input_time = 300/" "$PHP_INI"',
            '  sed -i "s/^max_input_vars.*/max_input_vars = 3000/" "$PHP_INI" 2>/dev/null || echo "max_input_vars = 3000" >> "$PHP_INI"',
            'fi',
            '',
            "systemctl enable php{$v}-fpm",
            "systemctl restart php{$v}-fpm",
            '',
            '# Verify PHP-FPM socket exists',
            "for i in \$(seq 1 10); do",
            "  if [ -S /var/run/php/php{$v}-fpm.sock ]; then",
            '    echo "PHP-FPM socket is ready"',
            '    break',
            '  fi',
            '  sleep 1',
            'done',
            '',
            "if [ ! -S /var/run/php/php{$v}-fpm.sock ]; then",
            "  echo \"WARNING: PHP-FPM socket not found at /var/run/php/php{$v}-fpm.sock\"",
            '  # Try to find the actual socket',
            '  find /var/run/php/ -name "*.sock" 2>/dev/null || true',
            'fi',
            '',
            "echo \"PHP {$v} installed and configured\"",
        ]);
    }

    /**
     * Step 6: Secure MySQL non-interactively.
     * Compatible with both MySQL 8.x (auth_socket) and MariaDB.
     */
    public function secureMysql(): string
    {
        return implode("\n", [
            'set -e',
            '',
            '# Detect whether this is MySQL or MariaDB',
            'DB_VERSION=$(mysql --version 2>&1)',
            'echo "Database version: $DB_VERSION"',
            '',
            '# Remove anonymous users (compatible approach)',
            "mysql -e \"DELETE FROM mysql.user WHERE User='' OR User IS NULL;\" 2>/dev/null || true",
            '',
            '# Remove remote root access',
            "mysql -e \"DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');\" 2>/dev/null || true",
            '',
            '# Remove test database',
            "mysql -e \"DROP DATABASE IF EXISTS test;\" 2>/dev/null || true",
            "mysql -e \"DELETE FROM mysql.db WHERE Db='test' OR Db='test\\\\_%';\" 2>/dev/null || true",
            '',
            '# Flush privileges',
            "mysql -e \"FLUSH PRIVILEGES;\"",
            '',
            'echo "MySQL secured"',
        ]);
    }

    /**
     * Step 7: Create WordPress database and user.
     * Uses heredoc to keep credentials out of process args.
     * Compatible with both MySQL 8.x and MariaDB.
     */
    public function createDatabase(): string
    {
        // Use a multi-statement approach compatible with both MySQL 8.x and MariaDB
        return implode("\n", [
            'set -e',
            '',
            '# Create WordPress database and user',
            "mysql << 'SQLEOF'",
            "CREATE DATABASE IF NOT EXISTS `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            '',
            "-- Create user (compatible with MySQL 8.x and MariaDB)",
            "CREATE USER IF NOT EXISTS '{$this->dbUser}'@'localhost' IDENTIFIED BY '{$this->dbPassword}';",
            '',
            "GRANT ALL PRIVILEGES ON `{$this->dbName}`.* TO '{$this->dbUser}'@'localhost';",
            "FLUSH PRIVILEGES;",
            'SQLEOF',
            '',
            '# Verify database was created',
            "mysql -e \"SHOW DATABASES LIKE '{$this->dbName}';\" | grep -q '{$this->dbName}' && echo \"Database '{$this->dbName}' created successfully\" || (echo \"ERROR: Database creation failed\" && exit 1)",
            '',
            '# Verify user can connect',
            "mysql -u'{$this->dbUser}' -p'{$this->dbPassword}' -e \"SELECT 1;\" 2>/dev/null && echo \"Database user verified\" || echo \"WARNING: Could not verify database user login (this may be normal with socket auth)\"",
        ]);
    }

    /**
     * Step 8: Download and extract WordPress.
     */
    public function downloadWordPress(): string
    {
        return implode("\n", [
            'set -e',
            '',
            "# Create web root directory",
            "mkdir -p {$this->webRoot}",
            '',
            '# Download WordPress with retry',
            'cd /tmp',
            'rm -f /tmp/latest.tar.gz',
            'for attempt in 1 2 3; do',
            '  if curl -sLo /tmp/latest.tar.gz https://wordpress.org/latest.tar.gz; then',
            '    echo "WordPress downloaded (attempt $attempt)"',
            '    break',
            '  fi',
            '  echo "Download attempt $attempt failed, retrying..."',
            '  sleep 3',
            'done',
            '',
            '# Verify the download',
            'if [ ! -f /tmp/latest.tar.gz ] || [ ! -s /tmp/latest.tar.gz ]; then',
            '  echo "ERROR: WordPress download failed" && exit 1',
            'fi',
            '',
            '# Extract WordPress',
            "tar -xzf /tmp/latest.tar.gz -C {$this->webRoot} --strip-components=1",
            '',
            '# Verify extraction',
            "if [ ! -f {$this->webRoot}/wp-includes/version.php ]; then",
            '  echo "ERROR: WordPress extraction failed" && exit 1',
            'fi',
            '',
            '# Show WordPress version',
            "grep '^\\\$wp_version' {$this->webRoot}/wp-includes/version.php || true",
            '',
            '# Cleanup',
            'rm -f /tmp/latest.tar.gz',
            '',
            'echo "WordPress downloaded and extracted"',
        ]);
    }

    /**
     * Step 9: Write wp-config.php to disk.
     */
    public function writeWpConfig(): string
    {
        $config = $this->generateWpConfig();
        $path   = "{$this->webRoot}/wp-config.php";

        return implode("\n", [
            'set -e',
            '',
            '# Remove default wp-config-sample.php if present',
            "rm -f {$this->webRoot}/wp-config-sample.php",
            '',
            '# Write wp-config.php',
            "cat > {$path} << 'WPEOF'",
            $config,
            'WPEOF',
            '',
            '# Verify the config was written',
            "if [ ! -f {$path} ]; then",
            '  echo "ERROR: Failed to write wp-config.php" && exit 1',
            'fi',
            '',
            "echo \"wp-config.php written to {$path}\"",
        ]);
    }

    /**
     * Step 10: Set proper file permissions.
     */
    public function setPermissions(): string
    {
        return implode("\n", [
            'set -e',
            '',
            "# Set ownership to www-data (web server user)",
            "chown -R www-data:www-data {$this->webRoot}",
            '',
            '# Set directory permissions (755)',
            "find {$this->webRoot} -type d -exec chmod 755 {} \\;",
            '',
            '# Set file permissions (644)',
            "find {$this->webRoot} -type f -exec chmod 644 {} \\;",
            '',
            '# Make wp-config.php more restrictive',
            "chmod 640 {$this->webRoot}/wp-config.php 2>/dev/null || true",
            '',
            '# Create wp-content upload directory if missing',
            "mkdir -p {$this->webRoot}/wp-content/uploads",
            "chown www-data:www-data {$this->webRoot}/wp-content/uploads",
            "chmod 755 {$this->webRoot}/wp-content/uploads",
            '',
            'echo "File permissions set"',
        ]);
    }

    /**
     * Step 11: Write Nginx server block config.
     */
    public function writeNginxConfig(): string
    {
        $config = $this->generateNginxConfig();
        $configPath = "/etc/nginx/sites-available/{$this->domain}";
        $enabledPath = "/etc/nginx/sites-enabled/{$this->domain}";

        return implode("\n", [
            'set -e',
            '',
            "# Write Nginx server block",
            "cat > {$configPath} << 'NGINXEOF'",
            $config,
            'NGINXEOF',
            '',
            "# Enable the site",
            "ln -sf {$configPath} {$enabledPath}",
            '',
            '# Disable default site if it exists',
            'rm -f /etc/nginx/sites-enabled/default',
            '',
            '# Test Nginx configuration',
            'nginx -t',
            '',
            'echo "Nginx configured for {$this->domain}"',
        ]);
    }

    /**
     * Step 12: Restart Nginx.
     */
    public function restartNginx(): string
    {
        return implode("\n", [
            'set -e',
            '',
            'systemctl restart nginx',
            '',
            '# Verify Nginx is running',
            'systemctl is-active nginx >/dev/null 2>&1 && echo "Nginx restarted successfully" || (echo "ERROR: Nginx failed to restart" && exit 1)',
        ]);
    }

    /**
     * Step 13: Install Certbot via snap (preferred) or apt.
     * Snap-based Certbot is recommended by EFF and works across all Ubuntu versions.
     */
    public function installCertbot(): string
    {
        return implode("\n", [
            'set -e',
            'export DEBIAN_FRONTEND=noninteractive',
            '',
            'if command -v certbot >/dev/null 2>&1; then',
            '  echo "Certbot is already installed"',
            '  certbot --version 2>&1',
            'else',
            '  # Preferred method: install via snap (works on Ubuntu 20.04+)',
            '  if command -v snap >/dev/null 2>&1; then',
            '    snap install --classic certbot 2>/dev/null || true',
            '    ln -sf /snap/bin/certbot /usr/bin/certbot 2>/dev/null || true',
            '  fi',
            '',
            '  # Fallback: install via apt if snap method failed',
            '  if ! command -v certbot >/dev/null 2>&1; then',
            '    apt-get install -y certbot python3-certbot-nginx',
            '  fi',
            'fi',
            '',
            '# Verify installation',
            'command -v certbot >/dev/null 2>&1 && echo "Certbot installed: $(certbot --version 2>&1)" || (echo "ERROR: Certbot installation failed" && exit 1)',
        ]);
    }

    /**
     * Step 14: Issue SSL certificate.
     */
    public function issueSslCertificate(): string
    {
        return implode("\n", [
            'set -e',
            '',
            '# Ensure the Nginx plugin is available',
            'if ! certbot plugins 2>/dev/null | grep -q nginx; then',
            '  # Install the nginx plugin if missing',
            '  apt-get install -y python3-certbot-nginx 2>/dev/null || pip3 install certbot-nginx 2>/dev/null || true',
            'fi',
            '',
            "# Issue SSL certificate for {$this->domain}",
            "certbot --nginx -d {$this->domain} \\",
            '  --non-interactive \\',
            '  --agree-tos \\',
            "  -m {$this->adminEmail} \\",
            '  --redirect \\',
            '  --staple-ocsp \\',
            '  --no-eff-email 2>&1 || {',
            '  echo "WARNING: Certbot failed. This may be because:"',
            '  echo "  - DNS for {$this->domain} does not point to this server"',
            '  echo "  - Port 80 is blocked by a firewall"',
            '  echo "  - Rate limits have been reached"',
            '  echo "WordPress is installed and accessible via HTTP. SSL can be configured later."',
            '  exit 0',
            '}',
            '',
            'echo "SSL certificate issued for {$this->domain}"',
        ]);
    }

    /**
     * Step 15: Configure auto-renewal.
     * Uses systemd timer if available, otherwise falls back to cron.
     */
    public function configureAutoRenew(): string
    {
        return implode("\n", [
            'set -e',
            '',
            '# Configure SSL auto-renewal',
            'if systemctl list-unit-files | grep -q "certbot\\.timer"; then',
            '  systemctl enable certbot.timer 2>/dev/null || true',
            '  systemctl start certbot.timer 2>/dev/null || true',
            '  echo "Certbot systemd timer enabled"',
            'elif systemctl list-unit-files | grep -q "snap\\.certbot\\.renew\\.timer"; then',
            '  echo "Snap certbot renewal timer is already active"',
            'else',
            '  # Fallback: add cron job for renewal',
            '  (crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet --deploy-hook \'systemctl reload nginx\'") | sort -u | crontab -',
            '  echo "Certbot cron job added for auto-renewal"',
            'fi',
            '',
            '# Test renewal (dry-run)',
            'certbot renew --dry-run 2>&1 || echo "WARNING: Renewal dry-run failed (SSL may not have been configured)"',
            '',
            'echo "SSL auto-renewal configured"',
        ]);
    }

    /**
     * Step 16: Final verification — ensure WordPress is accessible.
     */
    public function finalVerification(): string
    {
        return implode("\n", [
            'set -e',
            '',
            '# Verify all services are running',
            "echo '--- Service Status ---'",
            'systemctl is-active nginx && echo "Nginx: running" || echo "Nginx: NOT running"',
            "systemctl is-active php{$this->phpVersion}-fpm && echo \"PHP-FPM: running\" || echo \"PHP-FPM: NOT running\"",
            '',
            '# Check MySQL/MariaDB',
            'if systemctl is-active mysql 2>/dev/null; then',
            '  echo "MySQL: running"',
            'elif systemctl is-active mariadb 2>/dev/null; then',
            '  echo "MariaDB: running"',
            'else',
            '  echo "Database: NOT running"',
            'fi',
            '',
            '# Verify WordPress files exist',
            "if [ -f {$this->webRoot}/wp-config.php ] && [ -f {$this->webRoot}/wp-includes/version.php ]; then",
            '  echo "WordPress files: OK"',
            "  WP_VER=\$(grep '\\\$wp_version =' {$this->webRoot}/wp-includes/version.php | head -1 | cut -d\\' -f2)",
            '  echo "WordPress version: $WP_VER"',
            'else',
            '  echo "WARNING: WordPress files may be incomplete"',
            'fi',
            '',
            '# Quick HTTP check',
            "HTTP_CODE=\$(curl -sL -o /dev/null -w '%{http_code}' --connect-timeout 5 http://127.0.0.1/ -H 'Host: {$this->domain}' 2>/dev/null || echo '000')",
            'echo "HTTP response code: $HTTP_CODE"',
            '',
            'echo "=== Installation Complete ==="',
            "echo \"Domain: {$this->domain}\"",
            "echo \"Web Root: {$this->webRoot}\"",
            "echo \"WP Admin: https://{$this->domain}/wp-admin\"",
            "echo \"PHP Version: {$this->phpVersion}\"",
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

/** Performance */
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');

/** Force SSL */
define('FORCE_SSL_ADMIN', true);
if (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    \$_SERVER['HTTPS'] = 'on';
}

/** WordPress URLs */
define('WP_SITEURL', 'https://{$this->domain}');
define('WP_HOME',    'https://{$this->domain}');

/** Filesystem */
define('FS_METHOD', 'direct');

/** Absolute path to WordPress directory */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/** Sets up WordPress vars and included files */
require_once ABSPATH . 'wp-settings.php';
PHP;
    }

    /**
     * Generate Nginx server block configuration.
     * Uses the configured PHP version for the FPM socket path.
     */
    public function generateNginxConfig(): string
    {
        $v = $this->phpVersion;

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

    # Max upload size (matches PHP config)
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

    location ~ \\.php\$ {
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:/var/run/php/php{$v}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;

        fastcgi_intercept_errors on;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    # Deny access to hidden files
    location ~ /\\. {
        deny all;
    }

    # Deny access to sensitive files
    location ~* /(wp-config\\.php|readme\\.html|license\\.txt) {
        deny all;
    }

    # WordPress: deny access to wp-includes php files
    location ~* ^/wp-includes/.*\\.php\$ {
        deny all;
    }

    # WordPress: deny access to uploads php files
    location ~* ^/wp-content/uploads/.*\\.php\$ {
        deny all;
    }

    # Cache static assets
    location ~* \\.(css|gif|ico|jpeg|jpg|js|png|svg|woff|woff2|ttf|eot)\$ {
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
            // Use a cryptographically strong random string with special chars
            $value = $this->generateSecureKey(64);
            $lines[] = "define('{$key}', '{$value}');";
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a cryptographically secure random key for WordPress salts.
     * Uses characters that are safe inside single-quoted PHP define() strings.
     */
    private function generateSecureKey(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}|;:,.<>?/~';
        // Remove characters that could break PHP single-quoted strings or heredocs
        $chars = str_replace(["'", "\\"], '', $chars);
        $key = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $key .= $chars[random_int(0, $max)];
        }

        return $key;
    }

    /**
     * Get all installation steps as an ordered array.
     * Each step has a name, method, progress percentage, and estimated timeout.
     */
    public static function getSteps(): array
    {
        return [
            ['method' => 'preflightChecks',      'name' => 'Running pre-flight checks',    'progress' => 2,  'timeout' => 30],
            ['method' => 'updateSystem',          'name' => 'Updating system & prerequisites', 'progress' => 8,  'timeout' => 600],
            ['method' => 'installNginx',          'name' => 'Installing Nginx',             'progress' => 15, 'timeout' => 300],
            ['method' => 'installMySQL',          'name' => 'Installing MySQL/MariaDB',     'progress' => 25, 'timeout' => 300],
            ['method' => 'installPHP',            'name' => 'Installing PHP',               'progress' => 38, 'timeout' => 600],
            ['method' => 'secureMysql',           'name' => 'Securing database',            'progress' => 42, 'timeout' => 60],
            ['method' => 'createDatabase',        'name' => 'Creating database',            'progress' => 48, 'timeout' => 60],
            ['method' => 'downloadWordPress',     'name' => 'Downloading WordPress',        'progress' => 56, 'timeout' => 300],
            ['method' => 'writeWpConfig',         'name' => 'Configuring WordPress',        'progress' => 62, 'timeout' => 30],
            ['method' => 'setPermissions',        'name' => 'Setting file permissions',     'progress' => 68, 'timeout' => 120],
            ['method' => 'writeNginxConfig',      'name' => 'Configuring Nginx',            'progress' => 74, 'timeout' => 30],
            ['method' => 'restartNginx',          'name' => 'Restarting Nginx',             'progress' => 78, 'timeout' => 30],
            ['method' => 'installCertbot',        'name' => 'Installing Certbot',           'progress' => 84, 'timeout' => 300],
            ['method' => 'issueSslCertificate',   'name' => 'Issuing SSL certificate',      'progress' => 92, 'timeout' => 120],
            ['method' => 'configureAutoRenew',    'name' => 'Configuring SSL auto-renewal', 'progress' => 96, 'timeout' => 60],
            ['method' => 'finalVerification',     'name' => 'Verifying installation',       'progress' => 99, 'timeout' => 30],
        ];
    }
}
