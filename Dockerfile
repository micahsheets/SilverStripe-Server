FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    libzip-dev \
    unzip \
    git \
    iproute2 \
    sudo \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        intl \
        gd \
        mysqli \
        pdo_mysql \
        bcmath \
        zip \
        sockets \
        opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=20M'; \
        echo 'post_max_size=20M'; \
    } > /usr/local/etc/php/conf.d/silverstripe-prod.ini

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN echo 'www-data ALL=(root) NOPASSWD: /usr/sbin/ip, /sbin/ip' > /etc/sudoers.d/av-panel-ip \
    && chmod 0440 /etc/sudoers.d/av-panel-ip
