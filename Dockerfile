FROM brettt89/silverstripe-web:8.3-apache

# Enable PHP sockets extension for UDP communication
RUN docker-php-ext-install sockets

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
