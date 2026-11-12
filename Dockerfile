FROM php:8.3-apache

# Install system libraries required by Silverstripe extensions
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        intl \
        gd \
        pdo_mysql \
        bcmath \
        zip \
        sockets \
        opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Set Apache DocumentRoot to Silverstripe's /public folder
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copy Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
