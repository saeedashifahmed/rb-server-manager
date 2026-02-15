# RB Server Manager

A production-ready Laravel 10 web application that automates WordPress + Let's Encrypt SSL installation on Ubuntu 22.04 VPS servers via secure SSH.

## Features

- **User Authentication** — Register, login, logout (Laravel Breeze-style)
- **Server Management** — Add VPS servers with encrypted SSH private key storage
- **One-Click WordPress + SSL** — Fully automated, non-interactive installation
- **Async Queue Processing** — Installations run in background via Laravel Jobs
- **Real-time Progress** — AJAX polling shows live installation status
- **Security First** — SSH keys encrypted with AES-256-CBC, no raw user input in shell commands
- **Clean SaaS Dashboard** — TailwindCSS-powered responsive UI

## What Gets Installed on Your VPS

When you click "Install WordPress + SSL", the system automatically:

1. Updates system packages (`apt update && upgrade`)
2. Installs **Nginx** web server
3. Installs **MySQL** database server
4. Installs **PHP 8.2** with all WordPress-required extensions
5. Installs **unzip** and **curl** utilities
6. Secures MySQL (removes anonymous users, test DB)
7. Creates a dedicated **WordPress database and user** with strong random credentials
8. Downloads the **latest WordPress** from wordpress.org
9. Extracts WordPress to `/var/www/{domain}`
10. Sets proper file **permissions** (www-data ownership)
11. Dynamically generates a security-hardened **wp-config.php**
12. Configures an **Nginx server block** with security headers and gzip
13. Restarts Nginx
14. Installs **Certbot** and python3-certbot-nginx
15. Issues a **Let's Encrypt SSL certificate** (non-interactive)
16. Configures **SSL auto-renewal** via systemd timer

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Laravel 10 (PHP 8.1+) |
| Database | MySQL |
| SSH Client | phpseclib v3 |
| Queue | Laravel Database Queue |
| Frontend | Blade + TailwindCSS (CDN) + Alpine.js |
| Encryption | Laravel Crypt (AES-256-CBC) |

## Project Structure

```
rb-server-manager/
├── app/
│   ├── Console/Kernel.php
│   ├── Exceptions/Handler.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   ├── AuthenticatedSessionController.php
│   │   │   │   └── RegisteredUserController.php
│   │   │   ├── Controller.php
│   │   │   ├── DashboardController.php
│   │   │   ├── InstallationController.php
│   │   │   └── ServerController.php
│   │   ├── Kernel.php
│   │   ├── Middleware/
│   │   │   ├── Authenticate.php
│   │   │   └── RedirectIfAuthenticated.php
│   │   └── Requests/
│   │       ├── Auth/LoginRequest.php
│   │       ├── StartInstallationRequest.php
│   │       └── StoreServerRequest.php
│   ├── Jobs/
│   │   └── InstallWordPressJob.php
│   ├── Models/
│   │   ├── Installation.php
│   │   ├── Server.php
│   │   └── User.php
│   ├── Providers/
│   │   ├── AppServiceProvider.php
│   │   ├── AuthServiceProvider.php
│   │   ├── EventServiceProvider.php
│   │   └── RouteServiceProvider.php
│   └── Services/
│       ├── ScriptBuilder.php          # Generates all shell commands & configs
│       └── SSHService.php             # phpseclib SSH connection wrapper
├── bootstrap/app.php
├── config/
│   ├── app.php
│   ├── auth.php
│   ├── database.php
│   ├── hashing.php
│   ├── logging.php
│   ├── queue.php
│   ├── session.php
│   └── view.php
├── database/
│   └── migrations/
│       ├── 000001_create_users_table.php
│       ├── 000002_create_password_reset_tokens_table.php
│       ├── 000003_create_servers_table.php
│       ├── 000004_create_installations_table.php
│       ├── 000005_create_queue_jobs_table.php
│       └── 000006_create_failed_jobs_table.php
├── resources/views/
│   ├── auth/
│   │   ├── login.blade.php
│   │   └── register.blade.php
│   ├── dashboard.blade.php
│   ├── installations/
│   │   ├── create.blade.php
│   │   ├── index.blade.php
│   │   └── show.blade.php            # Live progress with AJAX polling
│   ├── layouts/
│   │   ├── app.blade.php             # Dashboard layout with sidebar
│   │   ├── base.blade.php            # Root HTML template
│   │   └── guest.blade.php           # Auth pages layout
│   ├── partials/
│   │   └── status-badge.blade.php
│   └── servers/
│       ├── create.blade.php
│       ├── index.blade.php
│       └── show.blade.php
├── routes/
│   ├── api.php
│   ├── auth.php
│   ├── console.php
│   └── web.php
├── composer.json
├── .env.example
└── README.md
```

## Setup Instructions

### Prerequisites

- **PHP 8.1+** with extensions: OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath
- **Composer** (latest v2)
- **MySQL 5.7+** or **MariaDB 10.3+**
- **Node.js & npm** (optional — TailwindCSS is loaded via CDN)

### 1. Clone & Install Dependencies

```bash
cd rb-server-manager
composer install
```

### 2. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rb_server_manager
DB_USERNAME=root
DB_PASSWORD=your_password

QUEUE_CONNECTION=database
```

### 3. Create Database

```sql
CREATE DATABASE rb_server_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 4. Run Migrations

```bash
php artisan migrate
```

### 5. Start the Queue Worker

The installation jobs run asynchronously. Start the queue worker in a **separate terminal**:

```bash
php artisan queue:work --tries=1 --timeout=900
```

For production, use **Supervisor** to keep the worker running:

```ini
[program:rb-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/rb-server-manager/artisan queue:work --tries=1 --timeout=900
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/rb-server-manager-worker.log
stopwaitsecs=3600
```

### 6. Start the Development Server

```bash
php artisan serve
```

Visit **http://localhost:8000** and register your account.

## Usage Workflow

1. **Register** an account at `/register`
2. **Add a Server** — Go to Servers → Add Server, enter your VPS details and paste your SSH private key
3. **Test Connection** — Click "Test SSH" to verify connectivity  
4. **Install WordPress + SSL** — Click "Install WordPress + SSL", enter your domain and admin email
5. **Watch Progress** — The installation page shows real-time progress via AJAX polling
6. **Access WordPress** — Once complete, click the WordPress Admin URL to run the setup wizard

## Security Architecture

| Concern | Implementation |
|---------|---------------|
| SSH Key Storage | Encrypted at rest using Laravel `Crypt::encryptString()` (AES-256-CBC) |
| Command Injection | All shell commands are predefined in `ScriptBuilder` — no user input interpolation |
| Input Validation | IP format, domain regex, SSH username regex validated in Form Requests |
| SSH Transport | phpseclib v3 (pure PHP) — no `exec()` or `shell_exec()` calls |
| Authentication | Laravel's built-in auth with bcrypt password hashing |
| CSRF Protection | All forms include `@csrf` tokens |
| Authorization | Server/installation ownership verified on every request |

## VPS Requirements

Your target server must be:

- **Ubuntu 22.04** LTS (fresh installation recommended)
- Accessible via **SSH with key-based authentication**
- **Root access** or sudo-capable user
- **Port 80 and 443** open in firewall
- **Domain DNS** A record pointing to the server's IP address

## Production Deployment

For production, additionally:

```bash
# Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set proper permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Use Supervisor for queue workers (see Step 5 above)

# Set up Nginx or Apache to serve the /public directory
# Enable HTTPS on the application itself
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_KEY` | Encryption key (auto-generated) | — |
| `DB_*` | Database connection settings | MySQL localhost |
| `QUEUE_CONNECTION` | Must be `database` for async installs | `database` |
| `SESSION_DRIVER` | Session storage backend | `file` |

## License

MIT License
