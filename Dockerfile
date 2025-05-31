FROM php:8.2-apache

# Enable Apache mod_rewrite (needed for Slim routing)
RUN a2enmod rewrite

# Set working directory early
WORKDIR /var/www/html

# Copy only composer files first (to leverage Docker cache)
COPY composer.json composer.lock ./

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP extensions required by your dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip \
    && docker-php-ext-install zip

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy rest of the project files (including .env)
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Apache config for Slim routing
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

EXPOSE 80
