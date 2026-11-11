FROM brettt89/silverstripe-web:8.3-apache

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
