FROM php:8.4-apache

# Extensions PHP + modules Apache requis
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

# Configuration Apache (DocumentRoot par défaut + protection du dossier includes/)
COPY docker/apache-logflow.conf /etc/apache2/conf-enabled/logflow.conf

# Code applicatif (exclusions dans .dockerignore)
COPY . /var/www/html/
RUN rm -f /var/www/html/config.php \
    && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
