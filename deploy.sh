#!/bin/bash
# =============================================================================
# Manual deploy script — run on the EC2 server when you need to deploy
# outside of GitHub Actions (e.g., first-time setup, emergency fix)
#
# Usage: sudo bash deploy.sh [--skip-upgrade]
# =============================================================================

set -euo pipefail

MOODLE_DIR="/var/www/moodle"
SKIP_UPGRADE="${1:-}"

cd "$MOODLE_DIR"

# Detect web user
if id "nginx" &>/dev/null; then
    WEB_USER="nginx"
else
    WEB_USER="www-data"
fi

echo "=== Pulling latest code ==="
git fetch origin main
git reset --hard origin/main
git submodule sync --recursive
git submodule update --init --recursive

echo "=== Installing Composer dependencies ==="
if ! command -v composer &>/dev/null; then
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
sudo -u "$WEB_USER" composer install --no-dev --classmap-authoritative --no-interaction

echo "=== Fixing permissions ==="
chown -R "$WEB_USER:$WEB_USER" "$MOODLE_DIR"

if [[ "$SKIP_UPGRADE" != "--skip-upgrade" ]]; then
    echo "=== Enabling maintenance mode ==="
    php admin/cli/maintenance.php --enable

    echo "=== Running database upgrade ==="
    php admin/cli/upgrade.php --non-interactive --allow-unstable

    echo "=== Disabling maintenance mode ==="
    php admin/cli/maintenance.php --disable
fi

echo "=== Purging caches ==="
php admin/cli/purge_caches.php

echo "=== Deploy complete! ==="
