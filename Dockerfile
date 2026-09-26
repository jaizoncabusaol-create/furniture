FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli zip gd \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' \
    'expose_php=Off' \
    'session.cookie_httponly=1' \
    'session.cookie_secure=1' \
    'session.cookie_samesite=Lax' \
    'session.use_strict_mode=1' \
    'upload_max_filesize=100M' \
    'post_max_size=105M' \
    'memory_limit=256M' \
    > /usr/local/etc/php/conf.d/furniquest.ini

COPY index.php admin.php user.php db.php backup.php health.php /var/www/html/
COPY uploads/fur_clean_* /opt/catalog/
COPY docker-entrypoint.sh /usr/local/bin/furniquest-start

RUN chmod +x /usr/local/bin/furniquest-start

ENV APP_ENV=production AUTO_CREATE_DATABASE=false APP_BACKUP_DIR=/data/backups
EXPOSE 8080
CMD ["/usr/local/bin/furniquest-start"]
