#!/usr/bin/env bash
# MesaLista · copia diaria (RD-03)
#
# Guarda en /var/backups/mesalista un .tar.gz con el volcado de la base de
# datos de PostgreSQL (tablas, vista, procedimientos y disparadores, en formato
# de pg_dump) y config.php, que contiene la clave de los QR: sin ella, los QR
# emitidos no se podrían validar tras restaurar. Conserva las copias de los
# últimos 14 días.
#
#   sudo bash backup.sh        (cron: 30 3 * * *)

set -euo pipefail

CONFIG=/var/www/mesalista-servidor/php/config/config.php
DEST=/var/backups/mesalista
KEEP_DAYS=14
STAMP=$(date +%F-%H%M)

umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
chmod 755 "$WORK"   # postgres escribe aquí el volcado

# Copia coherente sin parar el servicio (una sola transacción).
sudo -u postgres pg_dump --format=custom --file="$WORK/mesalista.dump" mesalista
cp "$CONFIG" "$WORK/config.php"
tar -czf "$DEST/mesalista-${STAMP}.tar.gz.tmp" -C "$WORK" mesalista.dump config.php
mv "$DEST/mesalista-${STAMP}.tar.gz.tmp" "$DEST/mesalista-${STAMP}.tar.gz"

find "$DEST" -name 'mesalista-*.tar.gz' -mtime +"$KEEP_DAYS" -delete
echo "Copia creada: $DEST/mesalista-${STAMP}.tar.gz"
