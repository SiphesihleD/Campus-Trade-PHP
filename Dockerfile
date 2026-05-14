FROM php:8.1-apache

# Enable mysqli extension for MySQL
RUN docker-php-ext-install mysqli

# Copy all PHP files to Apache web root
COPY . /var/www/html/

# Allow directory listing and fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Enable Apache mod_rewrite
RUN a2enmod rewrite

EXPOSE 80
