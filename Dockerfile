# Use official PHP image with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite (needed for Slim routing)
RUN a2enmod rewrite

# Copy project files into the container
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Apache config for Slim routing
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

EXPOSE 80
