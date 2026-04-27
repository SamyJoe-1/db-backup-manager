#!/bin/bash
# -------------------------------------------------------
# DB Backup Manager — Updater
# Only updates code files, never touches your config
# -------------------------------------------------------

set -e

OWNER="SamyJoe-1"
REPO="db-backup-manager"
BRANCH="main"
RAW_BASE="https://raw.githubusercontent.com/$OWNER/$REPO/$BRANCH"

echo "=== DB Backup Manager Updater ==="

fetch_file() {
    local remote_path="$1"
    local target_path="$2"
    curl -fsSL "$RAW_BASE/$remote_path" -o "$target_path"
}

fetch_file "back-up.php" "/var/www/dbbackup/back-up.php"
fetch_file "db-backup.sh" "/usr/local/bin/db-backup.sh"
fetch_file "backup-to-drive.sh" "/usr/local/bin/backup-to-drive.sh"
fetch_file "sync-nginx-ips.sh" "/usr/local/bin/sync-nginx-ips.sh"

chmod +x /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh
sed -i 's/\r//' /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh

chown -R www-data:www-data /var/www/dbbackup

echo "=== Updated successfully ==="
