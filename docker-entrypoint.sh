#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*) echo 'PORT must be numeric' >&2; exit 1 ;;
esac

printf 'Listen %s\n' "$port" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$port>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /data/uploads /data/backups
cp -Rn /opt/catalog/. /data/uploads/
chown -R www-data:www-data /data
chmod 700 /data/backups
ln -sfn /data/uploads /var/www/html/uploads

rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf
rm -f /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf

exec apache2-foreground
