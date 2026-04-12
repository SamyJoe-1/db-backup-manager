#!/bin/bash
# -------------------------------------------------------
# DB Backup Manager — Updater
# Only updates code files, never touches your config
# -------------------------------------------------------

set -e

TOKEN="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"
REPO="https://raw.githubusercontent.com/SamyJoe-1/db-backup-manager/main"

echo "=== DB Backup Manager Updater ==="

curl -sSL -H "Authorization: token $TOKEN" "$REPO/back-up.php"        -o /var/www/dbbackup/back-up.php
curl -sSL -H "Authorization: token $TOKEN" "$REPO/db-backup.sh"       -o /usr/local/bin/db-backup.sh
curl -sSL -H "Authorization: token $TOKEN" "$REPO/backup-to-drive.sh" -o /usr/local/bin/backup-to-drive.sh
curl -sSL -H "Authorization: token $TOKEN" "$REPO/sync-nginx-ips.sh"  -o /usr/local/bin/sync-nginx-ips.sh

chmod +x /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh
sed -i 's/\r//' /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh

chown -R www-data:www-data /var/www/dbbackup

echo "=== Updated successfully ==="