# Production image for Railway (web and worker services). Replaces Railpack, whose PHP install
# step depends on a plugin repository that no longer exists (github.com/jdx/vfox-php).
# Same runtime as before: FrankenPHP (Caddy) with PHP 8.3, app in /app.
FROM dunglas/frankenphp:1-php8.3

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql opcache zip pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'memory_limit=512M\nupload_max_filesize=25M\npost_max_size=30M\nexpose_php=Off\n' > "$PHP_INI_DIR/conf.d/zz-app.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/Caddyfile /Caddyfile
COPY docker/start-container.sh /start-container.sh
RUN chmod +x /start-container.sh

ENV SERVER_NAME=:80
CMD ["/start-container.sh"]
