#!/usr/bin/env bash
#
# Sauvegarde quotidienne OpsTrack : MySQL, MongoDB, fichier .env et storage.
# Retention 7 jours. Executee par cron a 02h30.
set -euo pipefail

DEST=/var/backups/opstrack
JOUR=$(date +%Y-%m-%d)
RETENTION=7
APP_DIR=/var/www/opstrack

mkdir -p "$DEST/$JOUR"
chmod 700 "$DEST"

# --- MySQL ---
DB_NAME=$(grep '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2)
DB_USER=$(grep '^DB_USERNAME=' "$APP_DIR/.env" | cut -d= -f2)
DB_PASS=$(grep '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2)

mysqldump --single-transaction --routines --triggers --no-tablespaces \
    -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$DEST/$JOUR/mysql-$DB_NAME.sql.gz"

# --- MongoDB ---
MONGO_DB=$(grep '^MONGODB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2)
mongodump --quiet --db "$MONGO_DB" --archive="$DEST/$JOUR/mongo-$MONGO_DB.archive" --gzip

# --- Configuration et fichiers deposes ---
cp "$APP_DIR/.env" "$DEST/$JOUR/env.backup"
tar czf "$DEST/$JOUR/storage-app.tar.gz" -C "$APP_DIR/storage" app

chmod -R 600 "$DEST/$JOUR"
chmod 700 "$DEST/$JOUR"

# --- Retention ---
find "$DEST" -maxdepth 1 -type d -name "20*" -mtime +$RETENTION -exec rm -rf {} \; 2>/dev/null || true

echo "$(date -Iseconds) sauvegarde terminee : $DEST/$JOUR ($(du -sh "$DEST/$JOUR" | cut -f1))" \
    >> /var/log/opstrack/backup.log
