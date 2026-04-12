#!/bin/bash
BACKUP_DIR="/home/backups"
DRIVE_DIR="drive:DBBackups"
LOG="/var/log/dbbackup.log"
TODAY=$(date +"%Y-%m-%d")

for DB_DIR in "$BACKUP_DIR"/*/; do
    DB_NAME=$(basename "$DB_DIR")
    for FILE in "$DB_DIR"*_${TODAY}_*.sql.gz; do
        [ -f "$FILE" ] || continue
        rclone copy "$FILE" "$DRIVE_DIR/$DB_NAME/" --log-file="$LOG" --log-level INFO
        if [ $? -eq 0 ]; then
            echo "[$(date)] SUCCESS: Uploaded $(basename $FILE) to Drive" >> "$LOG"
        else
            echo "[$(date)] FAILED: $(basename $FILE)" >> "$LOG"
        fi
    done
done