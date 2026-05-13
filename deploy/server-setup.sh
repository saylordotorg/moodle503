#!/bin/bash
# =============================================================================
# Moodle 5.03 Server Setup — dev503.sylr.org
# Run this ONCE on a fresh Amazon Linux 2023 or Ubuntu 24.04 EC2 instance
# Usage: sudo bash server-setup.sh
# =============================================================================

set -euo pipefail

DOMAIN="dev503.sylr.org"
MOODLE_DIR="/var/www/moodle"
MOODLEDATA_DIR="/var/moodledata"
DB_NAME="moodle"
DB_USER="moodleuser"
CERTBOT_EMAIL="admin@sylr.org"

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "Cannot detect OS"; exit 1
fi

echo "=== Detected OS: $OS ==="

# -----------------------------------------------------------------------------
# 1. System packages
# -----------------------------------------------------------------------------
echo "=== Installing system packages ==="

if [[ "$OS" == "amzn" ]]; then
    dnf update -y
    dnf install -y nginx mariadb105-server php8.2-fpm php8.2-cli \
        php8.2-mysqlnd php8.2-xml php8.2-mbstring php8.2-curl php8.2-zip \
        php8.2-gd php8.2-intl php8.2-soap php8.2-opcache php8.2-sodium \
        git unzip certbot python3-certbot-nginx redis6
elif [[ "$OS" == "ubuntu" ]]; then
    apt update && apt upgrade -y
    add-apt-repository -y ppa:ondrej/php
    apt update
    apt install -y nginx mariadb-server \
        php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring \
        php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-soap \
        php8.3-opcache php8.3-sodium php8.3-redis \
        git unzip certbot python3-certbot-nginx redis-server
else
    echo "Unsupported OS: $OS"; exit 1
fi

# -----------------------------------------------------------------------------
# 2. PHP Configuration
# -----------------------------------------------------------------------------
echo "=== Configuring PHP ==="

# Find the active php.ini for FPM
PHP_INI=$(php --ini | grep "Loaded Configuration" | awk '{print $NF}')
PHP_FPM_INI=$(echo "$PHP_INI" | sed 's/cli/fpm/')

# Apply Moodle-recommended PHP settings
for INI_FILE in "$PHP_INI" "$PHP_FPM_INI"; do
    if [ -f "$INI_FILE" ]; then
        sed -i 's/^upload_max_filesize.*/upload_max_filesize = 256M/' "$INI_FILE"
        sed -i 's/^post_max_size.*/post_max_size = 256M/' "$INI_FILE"
        sed -i 's/^max_execution_time.*/max_execution_time = 300/' "$INI_FILE"
        sed -i 's/^max_input_vars.*/max_input_vars = 5000/' "$INI_FILE"
        sed -i 's/^memory_limit.*/memory_limit = 256M/' "$INI_FILE"
        # Enable opcache
        sed -i 's/^;opcache.enable=.*/opcache.enable=1/' "$INI_FILE"
        sed -i 's/^opcache.enable=.*/opcache.enable=1/' "$INI_FILE"
    fi
done

# If max_input_vars is commented out, add it
grep -q "^max_input_vars" "$PHP_FPM_INI" 2>/dev/null || echo "max_input_vars = 5000" >> "$PHP_FPM_INI"

# -----------------------------------------------------------------------------
# 3. MariaDB Setup
# -----------------------------------------------------------------------------
echo "=== Configuring MariaDB ==="

systemctl enable --now mariadb

# Generate a random DB password
DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 20)

# Create database and user
mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

# Moodle requires innodb_file_per_table
cat > /etc/my.cnf.d/moodle.cnf 2>/dev/null || cat > /etc/mysql/conf.d/moodle.cnf <<'MYCNF'
[mysqld]
innodb_file_per_table = 1
innodb_large_prefix = ON
innodb_file_format = Barracuda
MYCNF

systemctl restart mariadb

# -----------------------------------------------------------------------------
# 4. Redis (Session & Cache Store)
# -----------------------------------------------------------------------------
echo "=== Starting Redis ==="
systemctl enable --now redis 2>/dev/null || systemctl enable --now redis-server

# -----------------------------------------------------------------------------
# 5. Create directories
# -----------------------------------------------------------------------------
echo "=== Setting up directories ==="

mkdir -p "$MOODLE_DIR"
mkdir -p "$MOODLEDATA_DIR"

# Set web user
if [[ "$OS" == "amzn" ]]; then
    WEB_USER="nginx"
elif [[ "$OS" == "ubuntu" ]]; then
    WEB_USER="www-data"
fi

chown -R "$WEB_USER:$WEB_USER" "$MOODLEDATA_DIR"
chmod 750 "$MOODLEDATA_DIR"

# -----------------------------------------------------------------------------
# 6. Clone Moodle from your org repo
# -----------------------------------------------------------------------------
echo "=== Cloning Moodle repository ==="
echo ""
echo ">> You need to clone your org's repo manually:"
echo "   cd $MOODLE_DIR"
echo "   git clone --recurse-submodules https://github.com/YOUR_ORG/moodle503.git ."
echo "   chown -R $WEB_USER:$WEB_USER $MOODLE_DIR"
echo ""

# -----------------------------------------------------------------------------
# 7. Nginx Configuration
# -----------------------------------------------------------------------------
echo "=== Configuring Nginx ==="

# Determine PHP-FPM socket path
if [[ "$OS" == "amzn" ]]; then
    PHP_SOCK="/run/php-fpm/www.sock"
elif [[ "$OS" == "ubuntu" ]]; then
    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
fi

cat > /etc/nginx/conf.d/dev503.sylr.org.conf <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    root ${MOODLE_DIR}/public;
    index index.php index.html;

    # Logging
    access_log /var/log/nginx/${DOMAIN}_access.log;
    error_log  /var/log/nginx/${DOMAIN}_error.log;

    # Max upload size (match PHP)
    client_max_body_size 256M;

    # Block access to sensitive directories
    location ~ /\.git {
        deny all;
        return 404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # PHP-FPM
    location ~ [^/]\.php(/|\$) {
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_read_timeout 300;
    }

    # Static files caching
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)\$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }
}
NGINX

# Remove default site if it exists
rm -f /etc/nginx/sites-enabled/default 2>/dev/null
rm -f /etc/nginx/conf.d/default.conf 2>/dev/null

nginx -t
systemctl enable --now nginx
systemctl restart nginx

# Ensure PHP-FPM is running
systemctl enable --now php*-fpm 2>/dev/null || systemctl enable --now php-fpm

# -----------------------------------------------------------------------------
# 8. SSL with Let's Encrypt
# -----------------------------------------------------------------------------
echo "=== Setting up HTTPS with Let's Encrypt ==="
echo ""
echo ">> IMPORTANT: Before running certbot, make sure:"
echo "   1. Your DNS A record for ${DOMAIN} points to this server's public IP"
echo "   2. Port 80 and 443 are open in the security group"
echo ""
echo "   Then run:"
echo "   sudo certbot --nginx -d ${DOMAIN} --email ${CERTBOT_EMAIL} --agree-tos --non-interactive"
echo ""
echo "   Certbot will auto-renew via systemd timer."

# -----------------------------------------------------------------------------
# 9. Moodle config.php
# -----------------------------------------------------------------------------
echo "=== Generating Moodle config.php ==="

cat > /tmp/moodle-config.php <<PHPCONFIG
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();

// Database
\$CFG->dbtype    = 'mariadb';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = 'localhost';
\$CFG->dbname    = '${DB_NAME}';
\$CFG->dbuser    = '${DB_USER}';
\$CFG->dbpass    = '${DB_PASS}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = array(
    'dbcollation' => 'utf8mb4_unicode_ci',
);

// Paths
\$CFG->wwwroot   = 'https://${DOMAIN}';
\$CFG->dataroot  = '${MOODLEDATA_DIR}';
\$CFG->directorypermissions = 02777;
\$CFG->admin     = 'admin';

// Redis session handler (fast, handles concurrency)
\$CFG->session_handler_class = '\core\session\redis';
\$CFG->session_redis_host = '127.0.0.1';
\$CFG->session_redis_port = 6379;
\$CFG->session_redis_database = 0;
\$CFG->session_redis_prefix = 'moodle_sess_';
\$CFG->session_redis_acquire_lock_timeout = 120;
\$CFG->session_redis_lock_expire = 7200;

// Performance
\$CFG->sslproxy = true;

require_once(__DIR__ . '/lib/setup.php');
PHPCONFIG

echo ""
echo ">> config.php has been generated at /tmp/moodle-config.php"
echo "   After cloning your repo, move it into place:"
echo "   cp /tmp/moodle-config.php ${MOODLE_DIR}/config.php"
echo "   chown ${WEB_USER}:${WEB_USER} ${MOODLE_DIR}/config.php"
echo "   chmod 640 ${MOODLE_DIR}/config.php"

# -----------------------------------------------------------------------------
# 10. Deploy user & SSH key for GitHub Actions
# -----------------------------------------------------------------------------
echo "=== Setting up deploy user ==="

if ! id "deploy" &>/dev/null; then
    useradd -m -s /bin/bash deploy
    usermod -aG "$WEB_USER" deploy
fi

mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh

echo ""
echo ">> Generate an SSH keypair for GitHub Actions deployment:"
echo "   sudo ssh-keygen -t ed25519 -f /home/deploy/.ssh/github_deploy -N ''"
echo "   cat /home/deploy/.ssh/github_deploy.pub >> /home/deploy/.ssh/authorized_keys"
echo "   chmod 600 /home/deploy/.ssh/authorized_keys"
echo "   chown -R deploy:deploy /home/deploy/.ssh"
echo ""
echo "   Then add the PRIVATE key as a GitHub secret named EC2_SSH_KEY"
echo "   And add the server's public IP as a GitHub secret named EC2_HOST"

# Give deploy user permission to run moodle commands
cat > /etc/sudoers.d/deploy <<'SUDOERS'
deploy ALL=(ALL) NOPASSWD: /usr/bin/git, /usr/bin/php
SUDOERS

# -----------------------------------------------------------------------------
# 11. Cron job for Moodle scheduled tasks
# -----------------------------------------------------------------------------
echo "=== Setting up Moodle cron ==="

CRON_LINE="* * * * * /usr/bin/php ${MOODLE_DIR}/admin/cli/cron.php > /dev/null 2>&1"
(crontab -u "$WEB_USER" -l 2>/dev/null; echo "$CRON_LINE") | sort -u | crontab -u "$WEB_USER" -

# -----------------------------------------------------------------------------
# 12. Daily backup to S3
# -----------------------------------------------------------------------------
echo "=== Setting up daily backups ==="

cat > /usr/local/bin/moodle-backup.sh <<'BACKUP'
#!/bin/bash
set -euo pipefail
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/tmp/moodle-backup-${TIMESTAMP}"
S3_BUCKET="s3://sylr-moodle-backups/dev503"

mkdir -p "$BACKUP_DIR"

# Database dump
mysqldump -u root moodle | gzip > "${BACKUP_DIR}/db.sql.gz"

# Moodledata (exclude cache and temp)
tar czf "${BACKUP_DIR}/moodledata.tar.gz" \
    --exclude='cache' --exclude='temp' --exclude='trashdir' \
    -C /var moodledata

# Upload to S3
aws s3 cp "$BACKUP_DIR" "$S3_BUCKET/${TIMESTAMP}/" --recursive --quiet

# Cleanup local
rm -rf "$BACKUP_DIR"

# Remove backups older than 30 days from S3
aws s3 ls "$S3_BUCKET/" | awk '{print $2}' | while read -r prefix; do
    backup_date=$(echo "$prefix" | cut -d'_' -f1 | tr -d '/')
    if [[ $(date -d "$backup_date" +%s 2>/dev/null) -lt $(date -d '-30 days' +%s) ]]; then
        aws s3 rm "$S3_BUCKET/$prefix" --recursive --quiet
    fi
done

echo "Backup completed: ${TIMESTAMP}"
BACKUP

chmod +x /usr/local/bin/moodle-backup.sh

# Run backup daily at 3 AM
(crontab -l 2>/dev/null; echo "0 3 * * * /usr/local/bin/moodle-backup.sh >> /var/log/moodle-backup.log 2>&1") | sort -u | crontab -

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
echo ""
echo "============================================="
echo "  Moodle Server Setup Complete!"
echo "============================================="
echo ""
echo "  Domain:     ${DOMAIN}"
echo "  Moodle Dir: ${MOODLE_DIR}"
echo "  Data Dir:   ${MOODLEDATA_DIR}"
echo "  DB Name:    ${DB_NAME}"
echo "  DB User:    ${DB_USER}"
echo "  DB Pass:    ${DB_PASS}"
echo ""
echo "  SAVE THE DB PASSWORD ABOVE — it won't be shown again!"
echo ""
echo "  Remaining manual steps:"
echo "  1. Point DNS A record for ${DOMAIN} → this server's IP"
echo "  2. Clone your repo into ${MOODLE_DIR}"
echo "  3. Move config.php: cp /tmp/moodle-config.php ${MOODLE_DIR}/config.php"
echo "  4. Run certbot for HTTPS (see instructions above)"
echo "  5. Set up deploy SSH key for GitHub Actions"
echo "  6. Run Moodle install: php ${MOODLE_DIR}/admin/cli/install_database.php"
echo "  7. Create S3 bucket: aws s3 mb s3://sylr-moodle-backups"
echo "============================================="
