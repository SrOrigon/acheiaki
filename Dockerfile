FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        postgresql-dev \
        oniguruma-dev \
        curl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        gd \
        mbstring \
        curl

WORKDIR /app
COPY . /app

RUN mkdir -p /app/data /app/data/tenants /app/data/logs /app/data/cache /app/data/cache/kv /app/data/cache/media /app/data/queue /app/assets/lojas \
    && chmod -R 775 /app/data /app/assets/lojas \
    && php /app/scripts/compress_assets.php

ENV PHP_CLI_SERVER_WORKERS=8

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} router.php"]
