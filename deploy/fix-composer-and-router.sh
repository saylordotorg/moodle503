#!/bin/bash
# =============================================================================
# One-shot fix for dev503 — install Composer deps and configure Moodle 5.x
# PHP router. Run once on the live server:
#   sudo bash deploy/fix-composer-and-router.sh
# =============================================================================

set -euo pipefail

MOODLE_DIR="/var/www/moodle"
NGINX_SITE="/etc/nginx/sites-available/dev503.sylr.org"

if id "nginx" &>/dev/null; then
    WEB_USER="nginx"
else
    WEB_USER="www-data"
fi

cd "$MOODLE_DIR"

echo "=== 1/4  Installing Composer (if missing) ==="
if ! command -v composer &>/dev/null; then
    curl -fsSL https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer
fi

echo "=== 2/4  Running composer install ==="
sudo -u "$WEB_USER" composer install --no-dev --classmap-authoritative --no-interaction
chown -R "$WEB_USER:$WEB_USER" "$MOODLE_DIR/vendor"

echo "=== 3/4  Enabling router in config.php ==="
if ! grep -q "routerconfigured" "$MOODLE_DIR/config.php"; then
    # Insert the flag just before the require_once of setup.php
    sed -i "/require_once.*lib\/setup\.php/i \$CFG->routerconfigured = true;\n" "$MOODLE_DIR/config.php"
fi

echo "=== 4/4  Updating nginx for r.php rewrites ==="
# Replace the "try_files $uri $uri/ /index.php..." line with the router variant,
# and add a dedicated location = /r.php block if not already present.
sed -i 's|try_files \$uri \$uri/ /index\.php?\$query_string;|try_files $uri $uri/ /r.php$uri?$query_string;|' "$NGINX_SITE"

if ! grep -q "location = /r.php" "$NGINX_SITE"; then
    # Insert the /r.php block right after the "location /" block.
    python3 - "$NGINX_SITE" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()
block = """
    location = /r.php {
        fastcgi_split_path_info ^(.+\\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_read_timeout 300;
    }
"""
# Place new block before the generic ~ [^/]\.php(/|$) block
src = re.sub(r'(\n)(    location ~ \[\^/\]\\\.php\(/\|\$\) \{)',
             r'\1' + block + r'\n\2', src, count=1)
open(path, 'w').write(src)
PY
fi

nginx -t
systemctl reload nginx

echo "=== Purging Moodle caches ==="
sudo -u "$WEB_USER" php admin/cli/purge_caches.php

echo "=== Done. Reload Site administration → Server → Environment to re-check. ==="
