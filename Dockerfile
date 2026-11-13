FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers expires \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf

COPY docker/apache.conf /etc/apache2/conf-available/alerter.conf
RUN a2enconf alerter

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-alerter.ini

COPY docker/entrypoint.sh /usr/local/bin/dev-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/dev-entrypoint && chmod +x /usr/local/bin/dev-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["dev-entrypoint"]
CMD ["apache2-foreground"]
