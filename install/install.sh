#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/opt/chilemon"
APACHE_CONF="/etc/apache2/conf-available/chilemon.conf"

echo "[*] ChileMon install (SQLite, sub-path /chilemon)"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root: sudo ./install/install.sh"
  exit 1
fi

echo "[*] Installing dependencies..."
apt update -y
apt install -y apache2 php php-sqlite3 libapache2-mod-php

echo "[*] Creating directories..."
mkdir -p "${APP_DIR}/data" "${APP_DIR}/logs"

# Si el repo fue copiado a /opt/chilemon, esta ruta existe:
if [[ ! -d "${APP_DIR}/public" ]]; then
  echo "[!] Expected code in ${APP_DIR}. Please copy your repo to ${APP_DIR} first."
  echo "    Example: sudo rsync -a ./ ${APP_DIR}/"
  exit 2
fi

echo "[*] Initializing SQLite DB if missing..."
DB="${APP_DIR}/data/chilemon.sqlite"
if [[ ! -f "${DB}" ]]; then
  touch "${DB}"
  chown www-data:www-data "${DB}"
  chmod 640 "${DB}"

  if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "${DB}" < "${APP_DIR}/install/sql/schema_sqlite.sql"
  else
    apt install -y sqlite3
    sqlite3 "${DB}" < "${APP_DIR}/install/sql/schema_sqlite.sql"
  fi
fi

echo "[*] Installing Apache conf (Alias /chilemon)..."
cp -a "${APP_DIR}/install/apache-chilemon.conf" "${APACHE_CONF}"
a2enconf chilemon
a2enmod rewrite
systemctl reload apache2

echo "[*] Done."
echo "    Open: http(s)://<your-node>/chilemon/"
