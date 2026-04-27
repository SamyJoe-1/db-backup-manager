#!/bin/bash
# -------------------------------------------------------
# DB Backup Manager — Updater
# Only updates code files, never touches your config
# -------------------------------------------------------

set -e

TOKEN="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"
OWNER="SamyJoe-1"
REPO="db-backup-manager"
BRANCH="main"
API_URL="https://api.github.com/repos/$OWNER/$REPO/contents"

echo "=== DB Backup Manager Updater ==="

fetch_file() {
    local remote_path="$1"
    local target_path="$2"
    curl -fsSL \
        -H "Accept: application/vnd.github.raw" \
        -H "Authorization: Bearer $TOKEN" \
        -H "X-GitHub-Api-Version: 2022-11-28" \
        -H "User-Agent: db-backup-manager-updater" \
        "$API_URL/$remote_path?ref=$BRANCH" \
        -o "$target_path"
}

fetch_file "back-up.php" "/var/www/dbbackup/back-up.php"
fetch_file "db-backup.sh" "/usr/local/bin/db-backup.sh"
fetch_file "backup-to-drive.sh" "/usr/local/bin/backup-to-drive.sh"
fetch_file "sync-nginx-ips.sh" "/usr/local/bin/sync-nginx-ips.sh"

chmod +x /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh
sed -i 's/\r//' /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh

chown -R www-data:www-data /var/www/dbbackup

echo "=== Updated successfully ==="
