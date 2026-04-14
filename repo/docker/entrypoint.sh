#!/usr/bin/env bash
set -e

cd /var/www/html

echo "=== Admissions System — Container Startup ==="

# ── Ensure storage structure (volume may be empty on first run) ──
for d in \
  storage/app/attachments storage/app/artifacts storage/app/quarantine \
  storage/app/exports storage/app/public \
  storage/framework/cache/data storage/framework/sessions storage/framework/views \
  storage/logs bootstrap/cache; do
  mkdir -p "$d"
done
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# ── Generate APP_KEY if not provided ──
if [ -z "$APP_KEY" ]; then
  echo "APP_KEY not set — generating …"
  APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
  export APP_KEY
  echo "Generated APP_KEY"
fi

# ── Write .env from environment variables ──
cat > .env <<ENVEOF
APP_NAME="${APP_NAME:-Admissions System}"
APP_ENV=${APP_ENV:-local}
APP_DEBUG=${APP_DEBUG:-true}
APP_URL=${APP_URL:-http://localhost:8000}
APP_KEY=${APP_KEY}

DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST:-mysql}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-admissions}
DB_USERNAME=${DB_USERNAME:-admissions}
DB_PASSWORD=${DB_PASSWORD:-secret}

SESSION_DRIVER=${SESSION_DRIVER:-array}
SESSION_LIFETIME=${SESSION_LIFETIME:-120}
SESSION_TOKEN_SECRET=${SESSION_TOKEN_SECRET}

QUEUE_CONNECTION=${QUEUE_CONNECTION:-database}
CACHE_STORE=${CACHE_STORE:-file}
LOG_CHANNEL=${LOG_CHANNEL:-stderr}

ENCRYPTION_KEY=${ENCRYPTION_KEY}

FILESYSTEM_DISK=${FILESYSTEM_DISK:-local}
CAPTCHA_ENABLED=${CAPTCHA_ENABLED:-true}
MFA_ISSUER="${MFA_ISSUER:-Admissions System}"
MFA_REQUIRED_FOR_ADMIN=${MFA_REQUIRED_FOR_ADMIN:-true}

LOGIN_MAX_ATTEMPTS=${LOGIN_MAX_ATTEMPTS:-5}
LOGIN_LOCKOUT_WINDOW_MINUTES=${LOGIN_LOCKOUT_WINDOW_MINUTES:-15}
LOGIN_LOCKOUT_DURATION_MINUTES=${LOGIN_LOCKOUT_DURATION_MINUTES:-30}

SLA_HIGH_PRIORITY_HOURS=${SLA_HIGH_PRIORITY_HOURS:-2}
SLA_NORMAL_PRIORITY_HOURS=${SLA_NORMAL_PRIORITY_HOURS:-24}
BUSINESS_HOURS_START=${BUSINESS_HOURS_START:-08:00}
BUSINESS_HOURS_END=${BUSINESS_HOURS_END:-17:00}
BUSINESS_DAYS=${BUSINESS_DAYS:-1,2,3,4,5}
POLL_INTERVAL_MS=${POLL_INTERVAL_MS:-10000}
AUDIT_RETENTION_MONTHS=${AUDIT_RETENTION_MONTHS:-24}
ENVEOF

# ── Wait for MySQL ──
echo "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306} …"
retries=0
until php -r "
  \$h='${DB_HOST:-mysql}'; \$p='${DB_PORT:-3306}';
  \$u='${DB_USERNAME:-admissions}'; \$pw='${DB_PASSWORD:-secret}';
  new PDO(\"mysql:host=\$h;port=\$p\",\$u,\$pw);
" 2>/dev/null; do
  retries=$((retries+1))
  if [ "$retries" -ge 60 ]; then
    echo "ERROR: MySQL not reachable after 60 s — aborting."
    exit 1
  fi
  sleep 1
done
echo "MySQL is ready."

# ── Migrate ──
echo "Running migrations …"
php artisan migrate --force

# ── Seed once (only when users table is empty) ──
ROW_COUNT=$(php -r "
  \$pdo = new PDO(
    'mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306};dbname=${DB_DATABASE:-admissions}',
    '${DB_USERNAME:-admissions}',
    '${DB_PASSWORD:-secret}'
  );
  echo \$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
" 2>/dev/null || echo "0")

if [ "$ROW_COUNT" = "0" ]; then
  echo "Empty database — seeding demo data …"
  php artisan db:seed --force
fi

# ── Optimise ──
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "=== Application ready — starting services ==="
exec "$@"
