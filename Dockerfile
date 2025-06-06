FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html \
    && chmod 644 /var/www/html/users.json \
    && chmod 644 /var/www/html/error.log
EXPOSE 80
CMD ["apache2-foreground"]
