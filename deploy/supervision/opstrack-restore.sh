#!/usr/bin/env bash
#
# Restauration d'une sauvegarde OpsTrack.
#   sudo ./opstrack-restore.sh 2026-09-10
#
# A valider en priorite sur la QUALIFICATION avant tout usage en production.
set -euo pipefail

JOUR="${1:?Usage: opstrack-restore.sh AAAA-MM-JJ}"
DEST=/var/backups/opstrack/$JOUR
APP_DIR=/var/www/opstrack

if [ ! -d "$DEST" ]; then
    echo "Sauvegarde introuvable : $DEST" >&2
    exit 1
fi

DB_NAME=$(grep '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2)
DB_USER=$(grep '^DB_USERNAME=' "$APP_DIR/.env" | cut -d= -f2)
DB_PASS=$(grep '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2)
MONGO_DB=$(grep '^MONGODB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2)

echo "== Mise en maintenance =="
sudo -u www-data php "$APP_DIR/artisan" down || true

echo "== Restauration MySQL =="
gunzip -c "$DEST/mysql-$DB_NAME.sql.gz" | mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"

echo "== Restauration MongoDB =="
mongorestore --quiet --drop --gzip --archive="$DEST/mongo-$MONGO_DB.archive"

echo "== Restauration des fichiers =="
tar xzf "$DEST/storage-app.tar.gz" -C "$APP_DIR/storage"
chown -R www-data:www-data "$APP_DIR/storage"

echo "== Sortie de maintenance =="
sudo -u www-data php "$APP_DIR/artisan" up

echo "Restauration du $JOUR terminee."
