# syntax=docker/dockerfile:1

# ── Node build stage ──────────────────────────────────────────────────────────
FROM node:22-alpine AS node-build

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/

RUN npm run build

# ── PHP / FrankenPHP stage ────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.3-alpine

# install-php-extensions is bundled in the FrankenPHP image
RUN install-php-extensions \
    ctype \
    curl \
    dom \
    fileinfo \
    filter \
    hash \
    mbstring \
    openssl \
    pcre \
    pdo \
    pdo_mysql \
    session \
    tokenizer \
    xml \
    redis \
    opcache

WORKDIR /app

# PHP dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

# Application source
COPY . .

# Pre-built frontend assets
COPY --from=node-build /app/public/build ./public/build

# Laravel bootstrap
RUN php artisan storage:link --no-interaction || true

EXPOSE 80 443

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
