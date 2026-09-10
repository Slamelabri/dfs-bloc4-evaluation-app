#!/usr/bin/env bash
#
# Provisionnement d'une machine OpsTrack (Ubuntu 24.04 + Apache + PHP 8.4).
# Idempotent : peut etre relance sans effet de bord.
#
# A executer SUR la machine cible :
#   sudo -E DB_APP_PASSWORD='...' ./deploy/provision.sh
#
# Variables attendues :
#   DB_APP_PASSWORD   mot de passe du compte MySQL applicatif (obligatoire)
#   APP_DOMAIN        nom de domaine servi (defaut : eval-dfs-p-tpl-20265-02.it-students.fr)
#   APP_DIR           racine applicative (defaut : /var/www/opstrack)
#   REPO_URL          depot a cloner si APP_DIR est vide
set -euo pipefail

APP_DOMAIN="${APP_DOMAIN:-eval-dfs-p-tpl-20265-02.it-students.fr}"
APP_DIR="${APP_DIR:-/var/www/opstrack}"
REPO_URL="${REPO_URL:-https://github.com/Slamelabri/dfs-bloc4-evaluation-app.git}"
DB_NAME="${DB_NAME:-opstrack}"
DB_APP_USER="${DB_APP_USER:-opstrack_app}"
# Source des fichiers de configuration : par defaut le depot d'ou ce script est lance.
CONF_SRC="${CONF_SRC:-$(cd "$(dirname "$0")/.." && pwd)}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Ce script doit etre lance en root (sudo)." >&2
    exit 1
fi

if [ -z "${DB_APP_PASSWORD:-}" ]; then
    echo "DB_APP_PASSWORD est obligatoire." >&2
    exit 1
fi

echo "== 1/7 Paquets =="
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

# MongoDB et Redis sont deja fournis par l'image de base ; on n'installe que ce
# qui manque, groupe par groupe, pour qu'un paquet indisponible n'empeche pas
# les autres de s'installer.
installer_si_absent() {
    local paquets_manquants=""

    for paquet in "$@"; do
        if ! dpkg -s "$paquet" >/dev/null 2>&1; then
            paquets_manquants="$paquets_manquants $paquet"
        fi
    done

    if [ -n "$paquets_manquants" ]; then
        echo "  installation :$paquets_manquants"
        apt-get install -y -qq $paquets_manquants >/dev/null
    fi
}

installer_si_absent apache2 git unzip curl jq
installer_si_absent php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath
installer_si_absent certbot python3-certbot-apache
installer_si_absent ufw fail2ban

echo "== 2/7 Modules Apache =="
a2enmod rewrite proxy proxy_http headers ssl >/dev/null

echo "== 3/7 Base de donnees applicative =="
# Compte MySQL dedie a l'application : ni root, ni le compte de demonstration user1.
SQL_FILE=$(mktemp)
cat > "$SQL_FILE" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_APP_USER}'@'localhost' IDENTIFIED BY '${DB_APP_PASSWORD}';
ALTER USER '${DB_APP_USER}'@'localhost' IDENTIFIED BY '${DB_APP_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON \`${DB_NAME}\`.* TO '${DB_APP_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Connexion par socket unix (root systeme) ; a defaut, mot de passe root MySQL.
if mysql --protocol=socket -uroot < "$SQL_FILE" 2>/dev/null; then
    echo "  base et compte applicatif crees (auth socket)"
else
    mysql -uroot -p"${DB_ROOT_PASSWORD:-0000}" < "$SQL_FILE"
    echo "  base et compte applicatif crees (auth mot de passe)"
fi
rm -f "$SQL_FILE"

echo "== 4/7 Code applicatif =="
if [ ! -d "$APP_DIR/.git" ]; then
    mkdir -p "$(dirname "$APP_DIR")"
    git clone "$REPO_URL" "$APP_DIR"
fi
git config --global --add safe.directory "$APP_DIR"

echo "== 5/7 Vhost Apache =="
install -m 644 "$CONF_SRC/deploy/apache/opstrack.conf" /etc/apache2/sites-available/opstrack.conf
sed -i "s/ServerName .*/ServerName ${APP_DOMAIN}/" /etc/apache2/sites-available/opstrack.conf
a2ensite opstrack.conf >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true
apache2ctl configtest

echo "== 6/7 Service du microservice Next.js =="
install -m 644 "$CONF_SRC/deploy/systemd/opstrack-dispatch-dashboard.service" \
    /etc/systemd/system/opstrack-dispatch-dashboard.service
systemctl daemon-reload
systemctl enable opstrack-dispatch-dashboard.service >/dev/null

echo "== 7/7 Droits et durcissement reseau =="
# L'application appartient a www-data ; seuls storage/ et bootstrap/cache sont inscriptibles.
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod +x "$APP_DIR/artisan"
chmod +x "$APP_DIR"/deploy/*.sh 2>/dev/null || true
# .env : lisible uniquement par www-data
if [ -f "$APP_DIR/.env" ]; then
    chown www-data:www-data "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"
fi

# Pare-feu : uniquement SSH, HTTP et HTTPS. MySQL/Mongo/Redis restent sur 127.0.0.1.
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp   >/dev/null
ufw allow 80/tcp   >/dev/null
ufw allow 443/tcp  >/dev/null
ufw --force enable >/dev/null

systemctl enable --now fail2ban >/dev/null 2>&1 || true
systemctl reload apache2

echo
echo "Provisionnement termine pour ${APP_DOMAIN} (${APP_DIR})."
