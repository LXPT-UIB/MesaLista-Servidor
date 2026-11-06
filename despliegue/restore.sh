#!/usr/bin/env bash
# MesaLista · restauración de una copia (RD-03)
#
# En un servidor limpio: primero install.sh y después este script.
#
#   sudo bash restore.sh /var/backups/mesalista/mesalista-2026-12-01-0330.tar.gz
#
# Sustituye la base de datos y config.php por los de la copia, y pone al
# usuario mesalista de PostgreSQL la contraseña que figura en esa config.php.

set -euo pipefail

ARCHIVE=$(realpath "${1:?Uso: sudo bash restore.sh <copia.tar.gz>}")
CONFIG=/var/www/mesalista-servidor/php/config/config.php
PERMISOS=/usr/local/lib/mesalista/permisos.sql
[[ $EUID -eq 0 ]] || { echo "Ejecuta con sudo." >&2; exit 1; }
[[ -f "$ARCHIVE" ]] || { echo "No existe $ARCHIVE" >&2; exit 1; }
cd /   # postgres no puede entrar en las carpetas personales
psql_admin() { sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 "$@"; }

START=$(date +%s)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
tar -xzf "$ARCHIVE" -C "$WORK"
[[ -f "$WORK/mesalista.dump" && -f "$WORK/config.php" ]] || { echo "La copia no es de MesaLista." >&2; exit 1; }
DB_PASS=$(php -r 'echo (require $argv[1])["db"]["pass"] ?? "";' "$WORK/config.php")
[[ "$DB_PASS" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "La contraseña de la copia tiene caracteres no admitidos." >&2; exit 1; }

echo "Restaurando la base de datos…"
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
systemctl stop "php${PHP_VER}-fpm" cron   # nadie usa la base de datos mientras se cambia
psql_admin -c 'DROP DATABASE IF EXISTS mesalista WITH (FORCE)'
psql_admin -c "CREATE DATABASE mesalista OWNER mesalista_owner ENCODING 'UTF8' LOCALE 'C.UTF-8' TEMPLATE template0"
# El volcado llega por la entrada estándar: postgres no necesita leer la carpeta de trabajo.
sudo -u postgres pg_restore --dbname=mesalista --no-owner --role=mesalista_owner --exit-on-error < "$WORK/mesalista.dump"

install -m 640 -o root -g www-data "$WORK/config.php" "$CONFIG"
# La contraseña va por la entrada estándar (\set), no en la línea de órdenes.
{ printf '\\set pass %s\n' "'$DB_PASS'"; printf '%s\n' "ALTER ROLE mesalista LOGIN PASSWORD :'pass';"; } | psql_admin
psql_admin -d mesalista -f - < "$PERMISOS"

systemctl start "php${PHP_VER}-fpm" cron
systemctl restart apache2

COUNTS=$(psql_admin -tA -d mesalista -c \
    "SELECT COUNT(*) || ' reservas, ' || COUNT(*) FILTER (WHERE status IN ('confirmed', 'seated')) || ' confirmadas o en curso' FROM reservations")
echo "Restaurado en $(( $(date +%s) - START )) s: ${COUNTS}."
