# Use the official PHP image with Apache
FROM php:8.2-apache

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Install Python and pip
RUN apt-get update && apt-get install -y python3 python3-pip

# Install Python dependencies
RUN pip3 install python-telegram-bot requests

# Copy your bot files
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html \
    && chmod 644 /var/www/html/users.json \
    && chmod 644 /var/www/html/error.log

# Expose port 80
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]