# Deployment Guide — On-Premises

## Prerequisites

- PHP 8.2+ with extensions: openssl, pdo_mysql, mbstring, gd, json
- Composer 2.x
- MySQL 8.0+
- Node.js 18+ and npm (for building frontend assets)
- Local disk storage with write access for `storage/` directory

## Installation

### 1. Clone and install dependencies

```bash
cd /opt/admissions-system  # or your chosen install path
cp .env.example .env

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install frontend dependencies and build
npm ci
npm run build
```

### 2. Configure environment

Edit `.env` with your settings:

```bash
# Generate application key
php artisan key:generate

# Database connection
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=admissions
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# IMPORTANT: Generate strong random values for these
SESSION_TOKEN_SECRET=<64-character-random-string>
ENCRYPTION_KEY=<64-hex-character-key>  # 32 bytes hex-encoded
```

Generate secure keys:
```bash
# Session token secret
openssl rand -hex 32

# Encryption key (32 bytes = 64 hex chars)
openssl rand -hex 32
```

### 3. Database setup

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE admissions CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations
php artisan migrate

# Seed demo data (optional, includes default admin account)
php artisan db:seed
```

### 4. File storage setup

```bash
# Create required directories
mkdir -p storage/app/attachments
mkdir -p storage/app/artifacts
mkdir -p storage/app/quarantine
mkdir -p storage/app/exports

# Set permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

### 5. Start the application

**Development:**
```bash
php artisan serve --host=0.0.0.0 --port=8000
```

**Production (Apache):**
```apache
<VirtualHost *:80>
    ServerName admissions.local
    DocumentRoot /opt/admissions-system/public

    <Directory /opt/admissions-system/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Production (Nginx):**
```nginx
server {
    listen 80;
    server_name admissions.local;
    root /opt/admissions-system/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 6. Queue worker setup

```bash
# Start queue worker (use supervisor in production)
php artisan queue:work --queue=default --sleep=3 --tries=3
```

**Supervisor config (`/etc/supervisor/conf.d/admissions-worker.conf`):**
```ini
[program:admissions-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /opt/admissions-system/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/opt/admissions-system/storage/logs/worker.log
```

### 7. Scheduler setup

Add to crontab:
```bash
* * * * * cd /opt/admissions-system && php artisan schedule:run >> /dev/null 2>&1
```

## Default Accounts (after seeding)

| Username  | Password              | Role      |
|-----------|-----------------------|-----------|
| admin     | AdminPassword123!     | admin     |
| manager   | ManagerPassword123!   | manager   |
| advisor   | AdvisorPassword123!   | advisor   |
| steward   | StewardPassword123!   | steward   |
| applicant | ApplicantPassword123! | applicant |

**IMPORTANT:** Change all default passwords immediately after first login.

## Security Checklist

- [ ] Change all default passwords
- [ ] Set strong `SESSION_TOKEN_SECRET` and `ENCRYPTION_KEY`
- [ ] Configure admin MFA (TOTP) for all administrator accounts
- [ ] Restrict database access to application server only
- [ ] Enable HTTPS via reverse proxy (nginx/Apache)
- [ ] Set `APP_DEBUG=false` in production
- [ ] Set `APP_ENV=production`
- [ ] Review and restrict file permissions
- [ ] Configure firewall to allow only necessary ports
- [ ] Set up log rotation for `storage/logs/`

## Project Structure

```
repo/
├── backend/              # Laravel 11 API + server
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/views/
│   ├── routes/
│   ├── storage/
│   ├── tests/
│   │   ├── unit_tests/   # PHPUnit unit tests
│   │   ├── api_tests/    # PHPUnit feature/API tests
│   │   └── TestCase.php
│   ├── artisan
│   ├── composer.json
│   └── phpunit.xml
├── frontend/             # Vue.js 3 SPA
│   ├── src/
│   │   ├── components/
│   │   ├── pages/
│   │   ├── router/
│   │   ├── store/
│   │   ├── utils/
│   │   ├── css/
│   │   ├── app.js
│   │   └── App.vue
│   ├── tests/
│   │   ├── unit_tests/   # Vitest component tests
│   │   └── e2e/          # E2E tests (placeholder)
│   ├── package.json
│   └── vite.config.js
├── docker/
├── Dockerfile
├── docker-compose.yml
├── run_tests.sh          # Unified test runner
├── .env.example
├── ASSUMPTIONS.md
└── DEPLOYMENT.md
```

## Running Tests

### Unified test runner (recommended)

```bash
./run_tests.sh              # Run all tests (backend + frontend)
./run_tests.sh backend      # Run backend tests only
./run_tests.sh frontend     # Run frontend tests only
./run_tests.sh unit         # Run unit tests only (both)
./run_tests.sh api          # Run API/integration tests only
```

### Backend tests (PHPUnit) — run from `backend/`

```bash
cd backend

# All tests
vendor/bin/phpunit

# Unit tests only
vendor/bin/phpunit --testsuite Unit

# API tests only
vendor/bin/phpunit --testsuite API

# Specific test file
vendor/bin/phpunit --filter=AdmissionsPlanTest
vendor/bin/phpunit --filter=AppointmentTest
vendor/bin/phpunit --filter=AuditFixesTest
vendor/bin/phpunit --filter=AcceptanceFixesTest
```

### Frontend tests (Vitest) — run from `frontend/`

```bash
cd frontend

# All tests
npx vitest run

# Watch mode
npx vitest

# Specific test
npx vitest run tests/unit_tests/Appointments.spec.js
npx vitest run tests/unit_tests/RouterGuard.spec.js
```

## Operational Runbook

### Verify audit chain integrity
```bash
# Via artisan
php artisan audit:verify-chain
php artisan audit:verify-chain --from=100   # start from specific ID

# Via API (requires admin token with audit.view permission)
curl -H "Authorization: Bearer <token>" http://localhost:8000/api/audit-logs/verify/chain
```

### Unlock a locked user
```bash
# Via API
curl -X POST -H "Authorization: Bearer <admin-token>" \
  http://localhost:8000/api/users/<user_id>/unlock

# Or via database
mysql -e "UPDATE users SET failed_login_count=0, lockout_until=NULL, status='active' WHERE username='locked_user';" admissions
```

### Reset admin MFA
```bash
# Via API (requires another admin)
curl -X POST -H "Authorization: Bearer <admin-token>" \
  -d '{"user_id": <target_user_id>}' \
  http://localhost:8000/api/mfa/disable
```

### Database backup
```bash
mysqldump -u root -p admissions > /backup/admissions_$(date +%Y%m%d_%H%M%S).sql
```

### View operation logs
```bash
# Recent errors
mysql -e "SELECT * FROM operation_logs WHERE outcome='failure' ORDER BY created_at DESC LIMIT 20;" admissions
```
