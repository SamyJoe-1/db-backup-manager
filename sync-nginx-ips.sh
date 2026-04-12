#!/bin/bash
IP_FILE="/etc/dbbackup/allowed_ips.txt"
HASH_FILE="/etc/dbbackup/allowed_ips.md5"
NGINX_CONF="/etc/nginx/sites-available/dbbackup"

CURRENT=$(md5sum "$IP_FILE" | awk '{print $1}')
LAST=$(cat "$HASH_FILE" 2>/dev/null)

[ "$CURRENT" = "$LAST" ] && exit 0

ALLOW_LINES=""
while IFS= read -r ip; do
    [[ -z "$ip" || "$ip" == \#* ]] && continue
    ALLOW_LINES="$ALLOW_LINES    allow $ip;\n"
done < "$IP_FILE"

ALLOW_LINES="${ALLOW_LINES}    deny all;"

sed -i '/allow /d;/deny all/d' "$NGINX_CONF"
sed -i "/index back-up.php;/a\\
$ALLOW_LINES" "$NGINX_CONF"

nginx -t 2>/dev/null && systemctl reload nginx && echo "$CURRENT" > "$HASH_FILE"
