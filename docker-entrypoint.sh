#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*) echo 'PORT must be numeric' >&2; exit 1 ;;
esac

printf 'Listen %s\n' "$port" > /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$port>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /data/uploads /data/backups /data/sessions
cp -Rn /opt/catalog/. /data/uploads/
chown -R www-data:www-data /data
for image in /opt/catalog/prd_img_*; do
    [ -f "$image" ] || continue
    target="/data/uploads/$(basename "$image")"
    cp --remove-destination "$image" "$target"
    chown www-data:www-data "$target"
    chmod 0644 "$target"
done
chmod 700 /data/backups
chmod 700 /data/sessions
ln -sfn /data/uploads /var/www/html/uploads

# Ensure only mpm_prefork is enabled
a2dismod mpm_event 2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

exec apache2-foreground
