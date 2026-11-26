# Full library (site + API + admin) on PHP 8.3 / Apache. Used by docker-compose.yml
# and by GitHub Codespaces (.devcontainer).
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
        libzip-dev libicu-dev mariadb-client \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip intl exif \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && printf '<Directory /var/www/app/public>\n  AllowOverride All\n  FallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-enabled/upsl.conf \
 && printf 'upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/upsl.ini

WORKDIR /var/www/app
COPY . /var/www/app
RUN rm -f config/config.php && chown -R www-data:www-data storage config public/media

COPY docker/entrypoint.sh /usr/local/bin/upsl-entrypoint
RUN chmod +x /usr/local/bin/upsl-entrypoint
EXPOSE 80
ENTRYPOINT ["upsl-entrypoint"]
CMD ["apache2-foreground"]
