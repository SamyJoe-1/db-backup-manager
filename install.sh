#!/bin/bash
# -------------------------------------------------------
# DB Backup Manager â€” Installer
# Usage: curl -sSL -H "Authorization: token TOKEN" \
#   https://raw.githubusercontent.com/SamyJoe-1/db-backup-manager/main/install.sh | bash
# -------------------------------------------------------

set -e

PHP_FPM=$(systemctl list-units --type=service | grep -o 'php[0-9.]*-fpm' | head -1)
PHP_SOCK=$(find /run/php/ -name "php*-fpm.sock" | grep -v "^/run/php/php-fpm.sock" | head -1)

OWNER="SamyJoe-1"
REPO="db-backup-manager"
BRANCH="main"
RAW_BASE="https://raw.githubusercontent.com/$OWNER/$REPO/$BRANCH"

echo "=== DB Backup Manager Installer ==="

# ---- Collect config from user ----
read -p "DB Username: " DB_USER
read -sp "DB Password: " DB_PASS; echo
read -p "Auth Username for web UI: " AUTH_USER
read -sp "Auth Password for web UI: " AUTH_PASS; echo
read -p "Nginx port (default 404): " APP_PORT
APP_PORT=${APP_PORT:-404}

# Generate bcrypt hash
AUTH_HASH=$(php -r "echo password_hash('$AUTH_PASS', PASSWORD_BCRYPT, ['cost'=>12]);")

# ---- Create directories ----
mkdir -p /var/www/dbbackup /home/backups /etc/dbbackup
chmod 750 /home/backups /etc/dbbackup

fetch_file() {
    local remote_path="$1"
    local target_path="$2"
    curl -fsSL "$RAW_BASE/$remote_path" -o "$target_path"
}

# ---- Download files ----
fetch_file "back-up.php" "/var/www/dbbackup/back-up.php"
fetch_file "db-backup.sh" "/usr/local/bin/db-backup.sh"
fetch_file "backup-to-drive.sh" "/usr/local/bin/backup-to-drive.sh"
fetch_file "sync-nginx-ips.sh" "/usr/local/bin/sync-nginx-ips.sh"

chmod +x /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh
sed -i 's/\r//' /usr/local/bin/db-backup.sh /usr/local/bin/backup-to-drive.sh /usr/local/bin/sync-nginx-ips.sh

# ---- Write credentials ----
cat > /etc/dbbackup/credentials <<EOF
DB_USER="$DB_USER"
DB_PASS="$DB_PASS"
EOF
chmod 640 /etc/dbbackup/credentials
chown root:www-data /etc/dbbackup/credentials

# ---- Write config.php ----
cat > /etc/dbbackup/config.php <<EOF
<?php
\$creds = parse_ini_file('/etc/dbbackup/credentials');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_USER', \$creds['DB_USER']);
define('DB_PASS', \$creds['DB_PASS']);

define('BACKUP_DIR', '/home/backups/');
define('BACKUP_EXT', '.sql.gz');

define('SCHEDULE_FILE', '/etc/dbbackup/schedules.json');
define('DB_LIST_FILE',  '/etc/dbbackup/databases.json');

define('AUTH_USER', '$AUTH_USER');
define('AUTH_HASH', '$AUTH_HASH');
define('SESSION_LIFETIME', 3600);
EOF

# ---- Write nginx conf ----
cat > /etc/nginx/sites-available/dbbackup <<EOF
server {
    listen $APP_PORT;

    root /var/www/dbbackup;
    index back-up.php;

    location / {
        try_files \$uri \$uri/ /back-up.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_SOCK;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ \.php-config$ { deny all; }
    location ~ /\.ht { deny all; }

    error_log /var/log/nginx/dbbackup_error.log;
    access_log /var/log/nginx/dbbackup_access.log;
}
EOF

ln -sf /etc/nginx/sites-available/dbbackup /etc/nginx/sites-enabled/dbbackup

# ---- Ownership ----
chown -R www-data:www-data /var/www/dbbackup /home/backups /etc/dbbackup

# ---- Log file ----
touch /var/log/dbbackup.log
chown www-data:www-data /var/log/dbbackup.log

# ---- allowed_ips.txt ----
touch /etc/dbbackup/allowed_ips.txt
chmod 644 /etc/dbbackup/allowed_ips.txt

# ---- Firewall ----
ufw allow "${APP_PORT}/tcp"

# ---- Cron ----
echo "www-data" >> /etc/cron.allow
echo "www-data ALL=(ALL) NOPASSWD: /usr/local/bin/db-backup.sh" >> /etc/sudoers
(crontab -l 2>/dev/null; echo "50 23 * * * /usr/local/bin/backup-to-drive.sh") | crontab -
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/sync-nginx-ips.sh") | crontab -

systemctl enable "$PHP_FPM" && systemctl start "$PHP_FPM"

# ---- Reload nginx ----
nginx -t && systemctl reload nginx

echo ""
echo "=== DONE ==="
echo "Access at: http://YOUR_SERVER_IP:$APP_PORT/back-up.php"
