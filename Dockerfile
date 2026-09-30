FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

WORKDIR /var/www/html

EXPOSE 80