FROM php:8.5-fpm

RUN apt-get update && apt-get install -y \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/log/securestack && chown www-data:www-data /var/log/securestack

USER www-data

WORKDIR /usr/share/nginx/epiphany

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 CMD php -r "exit(0);" || exit 1
