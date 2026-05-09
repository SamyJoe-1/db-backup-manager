<?php
// -------------------------------------------------------
// DB BACKUP MANAGER — CONFIG
// Edit these values then run: install.sh or update.sh
// -------------------------------------------------------

// Database credentials
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Paths
define('BACKUP_DIR', '/home/backups/');
define('BACKUP_EXT', '.sql.gz');
define('SCHEDULE_FILE', '/etc/dbbackup/schedules.json');
define('DB_LIST_FILE',  '/etc/dbbackup/databases.json');
define('RETENTION_FILE', '/etc/dbbackup/retention.json');

// Web UI auth — generate hash at: https://bcrypt-generator.com (rounds: 12)
define('AUTH_USER', 'admin');
define('AUTH_HASH', 'PASTE_YOUR_BCRYPT_HASH_HERE');
define('SESSION_LIFETIME', 3600);

// Nginx — port to listen on
define('APP_PORT', '404');