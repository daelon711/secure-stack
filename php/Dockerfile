FROM php:8.5-fpm

# gd needs these system libraries present *before* it can be compiled in
RUN apt-get update && apt-get install -y \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*


WORKDIR /usr/share/nginx/epiphany
