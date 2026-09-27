FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli zip gd \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
        /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf \
    && a2enmod mpm_prefork

RUN printf '%s\n' \
    'expose_php=Off' \
    'upload_max_filesize=100M' \
    'post_max_size=105M' \
    'memory_limit=256M' \
    > /usr/local/etc/php/conf.d/furniquest.ini

COPY index.php admin.php user.php db.php backup.php health.php session.php /var/www/html/
COPY uploads/fur_clean_* /opt/catalog/
COPY docker-entrypoint.sh /usr/local/bin/furniquest-start

RUN chmod +x /usr/local/bin/furniquest-start

ENV APP_ENV=production AUTO_CREATE_DATABASE=false APP_BACKUP_DIR=/data/backups APP_SESSION_DIR=/data/sessions
EXPOSE 8080
CMD ["/usr/local/bin/furniquest-start"]
