# ============================================================
# Multi-stage Dockerfile for Admissions System
# backend/ = Laravel 11,  frontend/ = Vue.js 3
# ============================================================

# ---- Stage 1: Build frontend assets ----
FROM node:20-alpine AS frontend

WORKDIR /build
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install

COPY frontend/vite.config.js ./
COPY frontend/src ./src
# Vite outputs to  /build/backend/public/build  (relative outDir in config)
# but since we have no ../backend here we override outDir at build time
RUN mkdir -p dist && \
    npx vite build --outDir dist --manifest

# ---- Stage 2: Install PHP dependencies ----
FROM composer:2 AS composer

WORKDIR /build
COPY backend/composer.json ./
RUN composer install \
    --no-dev --no-interaction --no-scripts \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---- Stage 3: Production image ----
FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        nginx supervisor bash dcron curl \
        libpng libjpeg-turbo freetype libzip oniguruma \
        libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql mbstring gd opcache zip pcntl bcmath \
    && apk del libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev oniguruma-dev \
    && rm -rf /var/cache/apk/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

# ---- Copy backend application code ----
COPY backend/artisan backend/composer.json ./
COPY backend/bootstrap ./bootstrap
COPY backend/config ./config
COPY backend/database ./database
COPY backend/public ./public
COPY backend/routes ./routes
COPY backend/app ./app
COPY backend/resources ./resources

# ---- Vendor from composer stage ----
COPY --from=composer /build/vendor ./vendor

# ---- Built frontend assets ----
COPY --from=frontend /build/dist ./public/build

# ---- Storage tree + permissions ----
RUN mkdir -p \
        storage/app/attachments storage/app/artifacts \
        storage/app/quarantine storage/app/exports storage/app/public \
        storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# ---- Entrypoint + cron ----
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
RUN echo '* * * * * cd /var/www/html && php artisan schedule:run >> /var/www/html/storage/logs/scheduler.log 2>&1' \
        > /var/spool/cron/crontabs/www-data \
    && chown www-data:www-data /var/spool/cron/crontabs/www-data \
    && chmod 0600 /var/spool/cron/crontabs/www-data

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
