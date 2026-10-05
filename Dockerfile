# ── Stage 1: build ────────────────────────────────────────────────────────────
FROM php:8.3-cli AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip git libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip pcntl mbstring \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Layer manifests first so editing source doesn't bust the composer layer.
COPY composer.json composer.lock ./
COPY . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --optimize-autoloader \
        --prefer-dist

# No config:cache / route:cache at build time on purpose. Caching here would
# bake the BUILD environment (no DB_HOST, falls back to 127.0.0.1) into the
# image, and a cached config ignores the RUNTIME process environment entirely
# (the PHP counterpart of the godotenv ENV != prod guard in the Go services).
# Cache at deploy time if you need it, after the real env is present.

# ── Stage 2: runtime ──────────────────────────────────────────────────────────
FROM php:8.3-cli AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip pcntl mbstring \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Non-root user — required so storage/ permission bugs appear in dev, not prod.
RUN addgroup --gid 1000 wassimo \
    && adduser --uid 1000 --ingroup wassimo --shell /bin/bash --disabled-password wassimo

WORKDIR /app

COPY --from=builder --chown=wassimo:wassimo /app .

# Writable directories for the non-root user.
RUN chown -R wassimo:wassimo storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER wassimo

EXPOSE 8000

# Runs migrations then starts the server.
# --no-reload: without it, `serve` strips the container env from the php -S
# worker and the worker falls back to defaults (127.0.0.1). See Dockerfile.dev.
# In a multi-replica setup, replace this with a separate deploy step that
# runs migrations exactly once before scaling. See ADR §Migration strategy.
CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000 --no-reload"]
