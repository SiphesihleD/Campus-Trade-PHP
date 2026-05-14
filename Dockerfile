FROM php:8.1-apache

# Enable mysqli extension for MySQL
RUN docker-php-ext-install mysqli

# Enable Apache mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Configure Apache to allow .htaccess overrides
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/campus-trade.conf \
    && a2enconf campus-trade

# Copy all files to Apache web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
