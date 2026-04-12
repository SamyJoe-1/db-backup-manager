#!/bin/bash
# -------------------------------------------------------
# /usr/local/bin/db-backup.sh
# Called by cron — do NOT run manually unless testing
# Usage: db-backup.sh <database_name>
# -------------------------------------------------------
DB_NAME="$1"
source /etc/dbbackup/credentials
DB_HOST="127.0.0.1"
BACKUP_DIR="/home/backups"
DATE=$(date +"%Y-%m-%d_%H-%M-%S")
FILENAME="${DB_NAME}_${DATE}.sql.gz"
DEST="${BACKUP_DIR}/${DB_NAME}"

# Create DB-specific folder if not exists
mkdir -p "$DEST"

# Dump and compress
mysqldump \
    -h "$DB_HOST" \
    -u "$DB_USER" \
    -p"$DB_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    "$DB_NAME" | gzip > "${DEST}/${FILENAME}"

if [ $? -eq 0 ]; then
    echo "[$(date)] SUCCESS: ${FILENAME}" >> /var/log/dbbackup.log
else
    echo "[$(date)] FAILED: ${DB_NAME}" >> /var/log/dbbackup.log
    exit 1
fi

# -------------------------------------------------------
# Retention: keep last 30 backups per DB, delete older
# -------------------------------------------------------
cd "$DEST" && ls -t *.sql.gz 2>/dev/null | tail -n +31 | xargs -r rm --