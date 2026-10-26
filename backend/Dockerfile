# syntax=docker/dockerfile:1

# ---------- Etapa 1: dependencias de Composer ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-interaction --no-scripts --no-progress \
    --prefer-dist --optimize-autoloader

# ---------- Etapa 2: imagen final (FrankenPHP = PHP 8.3 + servidor Caddy) ----------
FROM dunglas/frankenphp:1-php8.3-alpine

RUN install-php-extensions pdo_mysql bcmath intl opcache pcntl zip \
    && apk add --no-cache netcat-openbsd

ENV SERVER_NAME=":8000" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=json

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-antibugs.ini"
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
# sed: tolera finales de línea CRLF si el repo se clonó en Windows.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD wget -qO- http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
