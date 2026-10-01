#!/bin/bash
# =============================================================================
# EC2 User Data — Full Moodle 5.2 beta provisioning for dev503.sylr.org
# This runs ONCE on first boot as root
# =============================================================================

exec > /var/log/moodle-setup.log 2>&1
set -euxo pipefail

DOMAIN="dev503.sylr.org"
MOODLE_DIR="/var/www/moodle"
MOODLEDATA_DIR="/var/moodledata"
DB_NAME="moodle"
DB_USER="moodleuser"
CERTBOT_EMAIL="admin@sylr.org"
GITHUB_REPO="https://github.com/saylordotorg/moodle503.git"
WEB_USER="www-data"

# Generate DB password
DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 20)
# Save it for later retrieval
echo "$DB_PASS" > /root/.moodle_db_pass
chmod 600 /root/.moodle_db_pass

# -----------------------------------------------------------------------------
# 1. System packages
# -----------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get upgrade -y
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y nginx mariadb-server \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring \
    php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-soap \
    php8.3-opcache php8.3-redis \
    git unzip certbot python3-certbot-nginx redis-server

# AWS CLI v2 (not available via apt on Ubuntu 24.04)
curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o /tmp/awscliv2.zip
unzip -qo /tmp/awscliv2.zip -d /tmp/
/tmp/aws/install --update
rm -rf /tmp/awscliv2.zip /tmp/aws

# -----------------------------------------------------------------------------
# 2. PHP Configuration
# -----------------------------------------------------------------------------
PHP_FPM_INI="/etc/php/8.3/fpm/php.ini"
PHP_CLI_INI="/etc/php/8.3/cli/php.ini"

for INI_FILE in "$PHP_FPM_INI" "$PHP_CLI_INI"; do
    sed -i 's/^upload_max_filesize.*/upload_max_filesize = 256M/' "$INI_FILE"
    sed -i 's/^post_max_size.*/post_max_size = 256M/' "$INI_FILE"
    sed -i 's/^max_execution_time.*/max_execution_time = 300/' "$INI_FILE"
    sed -i 's/^memory_limit.*/memory_limit = 256M/' "$INI_FILE"
    sed -i 's/^;max_input_vars.*/max_input_vars = 5000/' "$INI_FILE"
    sed -i 's/^;opcache.enable=.*/opcache.enable=1/' "$INI_FILE"
done

systemctl restart php8.3-fpm

# -----------------------------------------------------------------------------
# 3. MariaDB Setup
# -----------------------------------------------------------------------------
systemctl enable --now mariadb

mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

cat > /etc/mysql/conf.d/moodle.cnf <<'MYCNF'
[mysqld]
innodb_file_per_table = 1
MYCNF

systemctl restart mariadb

# -----------------------------------------------------------------------------
# 4. Redis
# -----------------------------------------------------------------------------
systemctl enable --now redis-server

# -----------------------------------------------------------------------------
# 5. Directories
# -----------------------------------------------------------------------------
mkdir -p "$MOODLE_DIR" "$MOODLEDATA_DIR"
chown "$WEB_USER:$WEB_USER" "$MOODLEDATA_DIR"
chmod 750 "$MOODLEDATA_DIR"

# -----------------------------------------------------------------------------
# 6. Clone Moodle
# -----------------------------------------------------------------------------
git clone --recurse-submodules "$GITHUB_REPO" "$MOODLE_DIR"
chown -R "$WEB_USER:$WEB_USER" "$MOODLE_DIR"

# Install Composer and Moodle's PHP dependencies
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
cd "$MOODLE_DIR"
sudo -u "$WEB_USER" composer install --no-dev --classmap-authoritative --no-interaction

# -----------------------------------------------------------------------------
# 7. Moodle config.php
# -----------------------------------------------------------------------------
cat > "${MOODLE_DIR}/config.php" <<PHPCONFIG
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();

\$CFG->dbtype    = 'mariadb';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = 'localhost';
\$CFG->dbname    = '${DB_NAME}';
\$CFG->dbuser    = '${DB_USER}';
\$CFG->dbpass    = '${DB_PASS}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = array('dbcollation' => 'utf8mb4_unicode_ci');

\$CFG->wwwroot   = 'https://${DOMAIN}';
\$CFG->dataroot  = '${MOODLEDATA_DIR}';
\$CFG->directorypermissions = 02777;
\$CFG->admin     = 'admin';

\$CFG->session_handler_class = '\core\session\redis';
\$CFG->session_redis_host = '127.0.0.1';
\$CFG->session_redis_port = 6379;
\$CFG->session_redis_database = 0;
\$CFG->session_redis_prefix = 'moodle_sess_';
\$CFG->session_redis_acquire_lock_timeout = 120;
\$CFG->session_redis_lock_expire = 7200;

\$CFG->sslproxy = true;

// Moodle 5.x PHP router — nginx rewrites unmatched paths to /r.php.
\$CFG->routerconfigured = true;

require_once(__DIR__ . '/lib/setup.php');
PHPCONFIG

chown "$WEB_USER:$WEB_USER" "${MOODLE_DIR}/config.php"
chmod 640 "${MOODLE_DIR}/config.php"

# -----------------------------------------------------------------------------
# 8. Nginx
# -----------------------------------------------------------------------------
cat > /etc/nginx/sites-available/dev503.sylr.org <<'NGINX'
server {
    listen 80;
    server_name dev503.sylr.org;
    root /var/www/moodle/public;
    index index.php index.html;

    access_log /var/log/nginx/dev503.sylr.org_access.log;
    error_log  /var/log/nginx/dev503.sylr.org_error.log;

    client_max_body_size 256M;

    location ~ /\.git {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /r.php$uri?$query_string;
    }

    # Moodle 5.x PHP router
    location = /r.php {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_read_timeout 300;
    }

    location ~ ^(?<phpscript>.+?\.php)(/|$) {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        # Moodle 5.x router: non-existent .php paths go to r.php
        if (!-f $document_root$phpscript) {
            rewrite ^ /r.php$uri last;
        }
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_read_timeout 300;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/dev503.sylr.org /etc/nginx/sites-enabled/
nginx -t
systemctl enable --now nginx
systemctl restart nginx

# -----------------------------------------------------------------------------
# 9. Let's Encrypt SSL
# -----------------------------------------------------------------------------
certbot --nginx -d "$DOMAIN" --email "$CERTBOT_EMAIL" --agree-tos --non-interactive --redirect

# -----------------------------------------------------------------------------
# 10. Install Moodle database
# -----------------------------------------------------------------------------
sudo -u "$WEB_USER" php "${MOODLE_DIR}/admin/cli/install_database.php" \
    --adminuser=admin \
    --adminpass=Ch@ng3M3N0w! \
    --adminemail=admin@sylr.org \
    --fullname="Saylor Dev - Moodle 5.2 Beta" \
    --shortname="dev503" \
    --agree-license

# -----------------------------------------------------------------------------
# 11. Deploy user + SSH key for GitHub Actions
# -----------------------------------------------------------------------------
useradd -m -s /bin/bash deploy
usermod -aG "$WEB_USER" deploy

mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh

ssh-keygen -t ed25519 -f /home/deploy/.ssh/github_deploy -N ''
cat /home/deploy/.ssh/github_deploy.pub >> /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh

# Save the private key where we can retrieve it
cp /home/deploy/.ssh/github_deploy /root/.deploy_ssh_key
chmod 600 /root/.deploy_ssh_key

cat > /etc/sudoers.d/deploy <<'SUDOERS'
deploy ALL=(ALL) NOPASSWD: /usr/bin/git, /usr/bin/php, /usr/sbin/chown
SUDOERS

# Give deploy user access to moodle dir
chown -R "$WEB_USER:$WEB_USER" "$MOODLE_DIR"
chmod -R g+w "$MOODLE_DIR"
usermod -aG "$WEB_USER" deploy

# -----------------------------------------------------------------------------
# 12. Moodle cron
# -----------------------------------------------------------------------------
(crontab -u "$WEB_USER" -l 2>/dev/null; echo "* * * * * /usr/bin/php ${MOODLE_DIR}/admin/cli/cron.php > /dev/null 2>&1") | sort -u | crontab -u "$WEB_USER" -

# -----------------------------------------------------------------------------
# 13. Daily backup to S3
# -----------------------------------------------------------------------------
cat > /usr/local/bin/moodle-backup.sh <<'BACKUP'
#!/bin/bash
set -euo pipefail
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/tmp/moodle-backup-${TIMESTAMP}"
S3_BUCKET="s3://sylr-moodle-backups/dev503"

mkdir -p "$BACKUP_DIR"
mysqldump -u root moodle | gzip > "${BACKUP_DIR}/db.sql.gz"
tar czf "${BACKUP_DIR}/moodledata.tar.gz" \
    --exclude='cache' --exclude='temp' --exclude='trashdir' \
    -C /var moodledata

aws s3 cp "$BACKUP_DIR" "$S3_BUCKET/${TIMESTAMP}/" --recursive --quiet
rm -rf "$BACKUP_DIR"
echo "Backup completed: ${TIMESTAMP}"
BACKUP

chmod +x /usr/local/bin/moodle-backup.sh
(crontab -l 2>/dev/null; echo "0 3 * * * /usr/local/bin/moodle-backup.sh >> /var/log/moodle-backup.log 2>&1") | sort -u | crontab -

# -----------------------------------------------------------------------------
# Done
# -----------------------------------------------------------------------------
echo ""
echo "============================================="
echo "  MOODLE SETUP COMPLETE"
echo "============================================="
echo "  URL:        https://${DOMAIN}"
echo "  Admin:      admin / Ch@ng3M3N0w!"
echo "  DB Pass:    ${DB_PASS} (also in /root/.moodle_db_pass)"
echo "  Deploy Key: /root/.deploy_ssh_key"
echo "============================================="
