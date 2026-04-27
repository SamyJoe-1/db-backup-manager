<h1 align="center">𝕄𝕣.𝕊𝕒𝕞𝕪𝕁𝕠𝕖<br>𝔹𝕒𝕔𝕜𝕦𝕡 𝕄𝕒𝕟𝕒𝕘𝕖𝕣</h1>

# DB Backup Manager

Automated database backup manager with a web UI, scheduling, and Google Drive upload.

---

## Features

- PHP web UI to manage databases, schedules, restores, and backup files
- Database search suggestions when adding new databases
- Full database restore from a selected backup file
- Selected-table restore from a selected backup file with multi-select support
- Paginated backup browser with 10 records per page
- Scrollable backup list for large backup histories
- Smart file size formatting (`KB`, `MB`, `GB`, `TB`) with 2 decimal places
- Auto backup via cron on schedules set in the UI
- Auto upload backups to Google Drive daily at 23:50
- Retains last 30 backups per database, auto-deletes older ones
- Login page with bcrypt password hashing and 1-hour session timeout
- IP whitelist managed via a simple text file — no SSH needed to update

---

## Requirements

- Ubuntu / Debian VPS
- Nginx
- PHP 8.x + PHP-FPM
- MariaDB / MySQL
- rclone (for Google Drive upload)

---

## Install

```bash
bash <(curl -fsSL https://backup-manager.netlify.app/install.sh)
```

The installer will ask you for:

| Field | Description |
|-------|-------------|
| DB Username | MySQL/MariaDB user with access to all DBs |
| DB Password | Password for that user |
| UI Username | Username for the web UI login |
| UI Password | Password for the web UI login |
| Nginx Port | Port to run the web UI on (default: 404) |

---

## Update

To pull the latest version without touching your config:

```bash
bash <(curl -fsSL https://backup-manager.netlify.app/update.sh)
```

---

## Restore Modes

- `Full Database`: drops the target database and restores the entire selected backup file
- `Selected Tables`: reads the selected backup file, lists its tables, and restores only the tables you choose

Selected-table restore supports multi-select so you can restore several tables in one shot from the same backup.

---

## Backup Browser

- Shows backups in a scrollable container
- Paginates backup history at 10 records per page
- Opens restore actions in a modal instead of burying them under long lists
- Displays file sizes in the most suitable unit automatically

---

## Google Drive Setup (Optional)

After install, you need to configure rclone manually to enable Google Drive uploads.

1. Install rclone:
   ```bash
   curl https://rclone.org/install.sh | bash
   ```
2. Create a Google Cloud project and enable the Drive API
3. Create OAuth credentials (Desktop app)
4. Run on your local PC:
   ```
   .\rclone.exe authorize "drive" "YOUR_CLIENT_ID" "YOUR_CLIENT_SECRET"
   ```
5. Copy the token and configure on the VPS:
   ```bash
   nano ~/.config/rclone/rclone.conf
   ```
   Paste:
   ```ini
   [drive]
   type = drive
   client_id = YOUR_CLIENT_ID
   client_secret = YOUR_CLIENT_SECRET
   scope = drive
   token = YOUR_TOKEN_JSON
   root_folder_id = YOUR_GOOGLE_DRIVE_FOLDER_ID
   ```
6. Test:
   ```bash
   rclone ls drive:
   ```

---

## File Structure

```
/var/www/dbbackup/
    back-up.php              # Web UI

/etc/dbbackup/
    config.php               # App configuration
    credentials              # DB credentials
    allowed_ips.txt          # IP whitelist
    databases.json           # Tracked databases
    schedules.json           # Backup schedules

/usr/local/bin/
    db-backup.sh             # Backup script (called by cron)
    backup-to-drive.sh       # Google Drive upload script
    sync-nginx-ips.sh        # IP whitelist sync script

/home/backups/
    <db_name>/
        <db_name>_YYYY-MM-DD_HH-MM-SS.sql.gz
```

---

## Backup Schedule Options

| Label | Cron |
|-------|------|
| Every 1 hour | `0 * * * *` |
| Every 3 hours | `0 */3 * * *` |
| Every 6 hours | `0 */6 * * *` |
| Every 12 hours | `0 */12 * * *` |
| Every 24 hours | `0 2 * * *` |
| Weekly | `0 2 * * 0` |
| Drive upload | `50 23 * * *` |

---

## Access

```
http://YOUR_SERVER_IP:PORT/back-up.php
```

---

## Security

- Runs on a non-standard port — not scanned by most bots
- Login page with bcrypt hashed password — code exposure does not reveal the password
- Session expires after 1 hour
- Credentials stored outside webroot at `/etc/dbbackup/`
- Optional IP whitelist via `allowed_ips.txt`

---

## Logs

```bash
tail -f /var/log/dbbackup.log
```
