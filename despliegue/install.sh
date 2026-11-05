#!/usr/bin/env bash
# MesaLista · instalación del servidor (API + PostgreSQL) en una máquina virtual
# de Microsoft Azure con Ubuntu Server 24.04 LTS
#
# Desde el clon del repositorio en la máquina:
#   sudo bash despliegue/install.sh              instala o actualiza
#   sudo bash despliegue/install.sh --reset-db   además borra y recrea la base de datos
#
# Se puede ejecutar varias veces: no borra datos salvo con --reset-db.
# Los datos que pide la primera vez se guardan en /etc/mesalista/install.env.
#
# Queda así en el servidor:
#   /var/www/mesalista-servidor/php       lógica en PHP, scripts y config.php
#   /var/www/mesalista-servidor/publico   entrada de la API: https://<dominio>/api/v1/
#   /var/www/mesalista-web                raíz de la web (la instala su propio proyecto)
#
# Si en la máquina estaba MesaLista-Definitiva (MariaDB), este sitio de Apache
# y sus tareas de cron la sustituyen; sus archivos y su base de datos no se tocan.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR=/var/www/mesalista-servidor
PHP_DIR=$APP_DIR/php
API_DIR=$APP_DIR/publico
WEB_DIR=/var/www/mesalista-web
LIB_DIR=/usr/local/lib/mesalista
ETC_DIR=/etc/mesalista
ENV_FILE=$ETC_DIR/install.env
RESET_DB=0
[[ "${1:-}" == "--reset-db" ]] && RESET_DB=1

say()  { printf '\n\033[1;33m== %s\033[0m\n' "$*"; }
warn() { printf '\033[1;31m!! %s\033[0m\n' "$*" >&2; }
psql_admin() { sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 "$@"; }

[[ $EUID -eq 0 ]] || { warn "Ejecuta el script con sudo."; exit 1; }
# shellcheck source=/dev/null
source /etc/os-release
[[ "$ID" == "ubuntu" && "$VERSION_ID" == "24.04" ]] \
    || warn "Pensado para Ubuntu Server 24.04 LTS; detectado $PRETTY_NAME."

# ---------------------------------------------------------------------------
# Datos de la instalación
# ---------------------------------------------------------------------------
mkdir -p "$ETC_DIR"
chmod 700 "$ETC_DIR"
# shellcheck source=/dev/null
[[ -f "$ENV_FILE" ]] && source "$ENV_FILE"

ask() {  # ask VARIABLE "Pregunta" [secreto]
    local var=$1 prompt=$2 secret=${3:-} value
    [[ -n "${!var:-}" ]] && return
    if [[ -n "$secret" ]]; then read -rsp "$prompt: " value; echo; else read -rp "$prompt: " value; fi
    printf -v "$var" '%s' "$value"
}

say "Configuración"
# El dominio es la etiqueta DNS de la IP pública de Azure (Dirección IP
# pública > Configuración > Etiqueta de nombre DNS): con una IP estática,
# apunta siempre a esta máquina.
ask SERVER_DOMAIN "Dominio del servidor (p. ej. lapp.spaincentral.cloudapp.azure.com)"
SERVER_DOMAIN=$(printf '%s' "$SERVER_DOMAIN" | tr 'A-Z' 'a-z' | sed -e 's#^https\?://##' -e 's#/.*$##')
[[ "$SERVER_DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] \
    || { warn "«$SERVER_DOMAIN» no es un dominio válido."; exit 1; }
DOMAIN=$SERVER_DOMAIN
ask LE_EMAIL "Correo para avisos de Let's Encrypt"
ask ADMIN_EMAIL "Correo de la cuenta de administrador de MesaLista"
# Las credenciales de correo solo se piden al crear config.php y se guardan
# allí, no en install.env.
if [[ ! -f "$PHP_DIR/config/config.php" ]]; then
    echo "Correo con Azure Communication Services: cadena de conexión del recurso Communication Services"
    echo "(Configuración > Claves) y remitente de su dominio (DoNotReply@….azurecomm.net)."
    echo "Déjalos en blanco para configurarlos más tarde con $PHP_DIR/bin/configure_mail.php."
    ask MAIL_CONNECTION "Cadena de conexión de Azure" secret
    ask MAIL_SENDER "Remitente"
fi
umask 077
{
    printf 'SERVER_DOMAIN=%q\n' "$SERVER_DOMAIN"
    printf 'LE_EMAIL=%q\n' "$LE_EMAIL"
    printf 'ADMIN_EMAIL=%q\n' "$ADMIN_EMAIL"
} > "$ENV_FILE"
umask 022

# ---------------------------------------------------------------------------
# Paquetes y hora del servidor (regla 2: hora de Madrid)
# ---------------------------------------------------------------------------
say "Paquetes"
# Máquinas pequeñas (p. ej. Azure B1s, 1 GB): 1 GB de swap para que apt y PostgreSQL no se queden sin memoria.
if [[ $(awk '/MemTotal/ { print $2 }' /proc/meminfo) -lt 1600000 && -z "$(swapon --show --noheadings)" && ! -f /swapfile ]]; then
    fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap -q /swapfile && swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo "Swap de 1 GB activada."
fi
timedatectl set-timezone Europe/Madrid
timedatectl set-ntp true || true
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
# Paquetes sin versión: instalan los de la distribución (PHP 8.3 y PostgreSQL 16 en Ubuntu 24.04).
apt-get install -y -q \
    apache2 php-fpm php-cli php-pgsql php-mbstring php-curl \
    postgresql certbot ufw curl rsync cron openssl
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PG_VER=$(psql -V | grep -oE '[0-9]+' | head -1)
echo "PHP ${PHP_VER} · PostgreSQL ${PG_VER}"

# ---------------------------------------------------------------------------
# PostgreSQL: solo escucha en local (RS-10). Dos roles: mesalista_owner es el
# dueño del esquema y mesalista, el de la API, solo lee y escribe datos.
# ---------------------------------------------------------------------------
say "PostgreSQL"
PG_CONF=/etc/postgresql/${PG_VER}/main/conf.d/60-mesalista.conf
cat > "$PG_CONF" <<'EOF'
# MesaLista
listen_addresses = 'localhost'
timezone = 'Europe/Madrid'
log_timezone = 'Europe/Madrid'
EOF
systemctl restart postgresql

DB_PASS=""
if [[ -f "$PHP_DIR/config/config.php" ]]; then
    DB_PASS=$(php -r 'echo (require $argv[1])["db"]["pass"];' "$PHP_DIR/config/config.php")
fi
[[ -n "$DB_PASS" ]] || DB_PASS=$(openssl rand -hex 24)
psql_admin -v pass="$DB_PASS" <<'SQL'
SELECT 'CREATE ROLE mesalista_owner NOLOGIN' WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mesalista_owner') \gexec
SELECT 'CREATE ROLE mesalista LOGIN' WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mesalista') \gexec
ALTER ROLE mesalista LOGIN PASSWORD :'pass';
SELECT 'CREATE DATABASE mesalista OWNER mesalista_owner ENCODING ''UTF8'' TEMPLATE template0'
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'mesalista') \gexec
SQL

HAS_TABLES=$(sudo -u postgres psql -X -tA -d mesalista -c "SELECT to_regclass('public.reservations') IS NOT NULL")
if [[ "$HAS_TABLES" == "f" || $RESET_DB -eq 1 ]]; then
    if [[ $RESET_DB -eq 1 ]]; then
        read -rp "Se BORRARÁN todas las reservas y cuentas. Escribe BORRAR para seguir: " sure
        [[ "$sure" == "BORRAR" ]] || { warn "Cancelado."; exit 1; }
    fi
    # Como mesalista_owner: así es el dueño de todo (incluidas las extensiones).
    psql_admin -d mesalista -c 'SET ROLE mesalista_owner' -f "$REPO_DIR/bd/esquema.sql"
    psql_admin -d mesalista -c 'SET ROLE mesalista_owner' -f "$REPO_DIR/bd/datos.sql"
    echo "Base de datos creada con el restaurante de pruebas."
fi
psql_admin -d mesalista -f "$REPO_DIR/despliegue/permisos.sql"

# ---------------------------------------------------------------------------
# Código de la aplicación
# ---------------------------------------------------------------------------
say "API en $APP_DIR"
mkdir -p "$PHP_DIR" "$API_DIR" "$WEB_DIR" "$LIB_DIR"
# Las exclusiones empiezan por «/» para afectar solo a la raíz de php/.
rsync -a --delete --exclude '/config/config.php' "$REPO_DIR/php/" "$PHP_DIR/"
rsync -a --delete "$REPO_DIR/publico/" "$API_DIR/"
install -m 700 "$REPO_DIR/despliegue/backup.sh" "$REPO_DIR/despliegue/restore.sh" "$LIB_DIR/"
install -m 644 "$REPO_DIR/despliegue/permisos.sql" "$LIB_DIR/"
ML_DB_PASS="$DB_PASS" ML_DOMAIN="$DOMAIN" ML_MAIL_CONNECTION="${MAIL_CONNECTION:-}" ML_MAIL_SENDER="${MAIL_SENDER:-}" \
    php "$PHP_DIR/bin/write_config.php"
chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} +
find "$APP_DIR" -type f -exec chmod 640 {} +
mkdir -p /var/log/mesalista
chown www-data:www-data /var/log/mesalista
chmod 750 /var/log/mesalista

# Raíz de la web: la rellena el proyecto de la web. Mientras está vacía, una
# página que lo explica.
if [[ -z "$(ls -A "$WEB_DIR")" ]]; then
    cat > "$WEB_DIR/index.html" <<'EOF'
<!doctype html>
<html lang="es"><meta charset="utf-8"><title>MesaLista</title>
<p>La API de MesaLista funciona en <code>/api/v1/</code>. La web todavía no está instalada.</p>
</html>
EOF
    chown -R root:www-data "$WEB_DIR"
fi
mkdir -p /etc/apache2/mesalista-web.d

# ---------------------------------------------------------------------------
# PHP-FPM
# ---------------------------------------------------------------------------
say "PHP ${PHP_VER}"
cat > "/etc/php/${PHP_VER}/fpm/conf.d/90-mesalista.ini" <<'EOF'
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/mesalista/php.log
file_uploads = Off
allow_url_fopen = Off
post_max_size = 64K
max_execution_time = 30

; OPcache: el código PHP se compila una vez y queda en memoria. Revisa cada
; 60 s si un archivo ha cambiado (p. ej. config.php editado a mano); cada
; instalación reinicia PHP-FPM y lo vacía igualmente.
opcache.enable = 1
opcache.memory_consumption = 64
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
realpath_cache_ttl = 600
EOF

# Procesos de PHP-FPM según la memoria: ~40 MB por proceso y unos 450 MB
# reservados para PostgreSQL, Apache y el sistema (B1s de 1 GB → 8 procesos).
MEM_MB=$(( $(awk '/MemTotal/ { print $2 }' /proc/meminfo) / 1024 ))
FPM_CHILDREN=$(( (MEM_MB - 450) / 40 ))
(( FPM_CHILDREN < 4 )) && FPM_CHILDREN=4
(( FPM_CHILDREN > 32 )) && FPM_CHILDREN=32
cat > "/etc/php/${PHP_VER}/fpm/pool.d/zz-mesalista.conf" <<EOF
; MesaLista: ajuste del grupo www según la memoria (${MEM_MB} MB)
[www]
pm = dynamic
pm.max_children = ${FPM_CHILDREN}
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = $(( FPM_CHILDREN / 2 > 2 ? FPM_CHILDREN / 2 : 3 ))
pm.max_requests = 1000
EOF
echo "PHP-FPM: hasta ${FPM_CHILDREN} procesos (${MEM_MB} MB de memoria)."

# RD-02: los servicios vuelven a arrancar solos si se caen.
for svc in apache2 "php${PHP_VER}-fpm" "postgresql@${PG_VER}-main"; do
    mkdir -p "/etc/systemd/system/${svc}.service.d"
    printf '[Service]\nRestart=always\nRestartSec=2\n' > "/etc/systemd/system/${svc}.service.d/mesalista-restart.conf"
done
systemctl daemon-reload
systemctl enable apache2 "php${PHP_VER}-fpm" postgresql cron >/dev/null
systemctl restart "php${PHP_VER}-fpm"

# ---------------------------------------------------------------------------
# Apache (RS-01). Mientras no hay certificado de Let's Encrypt arranca con uno
# autofirmado, para poder servir el desafío HTTP por el puerto 80.
# ---------------------------------------------------------------------------
say "Apache"
LE_CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
LE_KEY="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
SELF_CERT="$ETC_DIR/selfsigned.crt"
SELF_KEY="$ETC_DIR/selfsigned.key"
ACME_DIR=/var/www/letsencrypt
CERT_OK=0
[[ -f "$LE_CERT" ]] && CERT_OK=1   # ya existe; certbot lo renueva solo

render_site() {  # render_site CERT KEY
    sed -e "s#__DOMAIN__#${DOMAIN}#g" -e "s#__API_DIR__#${API_DIR}#g" -e "s#__WEB_DIR__#${WEB_DIR}#g" \
        -e "s#__ACME_DIR__#${ACME_DIR}#g" -e "s#__CERT__#$1#g" -e "s#__KEY__#$2#g" \
        "$REPO_DIR/despliegue/apache-mesalista.conf" > /etc/apache2/sites-available/mesalista.conf
}
if [[ $CERT_OK -eq 0 && ! -f "$SELF_CERT" ]]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$DOMAIN" \
        -addext "subjectAltName=DNS:$DOMAIN" -keyout "$SELF_KEY" -out "$SELF_CERT" 2>/dev/null
fi
mkdir -p "$ACME_DIR/.well-known/acme-challenge"

a2dismod -q mpm_prefork php"${PHP_VER}" 2>/dev/null || true
a2enmod -q mpm_event ssl headers proxy_fcgi setenvif http2 deflate >/dev/null
a2enconf -q "php${PHP_VER}-fpm" >/dev/null
if [[ $CERT_OK -eq 1 ]]; then render_site "$LE_CERT" "$LE_KEY"; else render_site "$SELF_CERT" "$SELF_KEY"; fi
a2dissite -q 000-default default-ssl 2>/dev/null || true
a2ensite -q mesalista >/dev/null
apache2ctl configtest
systemctl restart apache2

# ---------------------------------------------------------------------------
# Cortafuegos: 80 y 443 desde fuera (RS-10) y SSH para administrar la máquina.
# El grupo de seguridad de red de Azure limita el 22 a la IP del administrador.
# ---------------------------------------------------------------------------
say "Cortafuegos"
ufw --force reset >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 22/tcp >/dev/null
# Solo se avisa si SSH acepta contraseñas: con claves no hay fuerza bruta posible.
SSH_PASSWORDS=$(sshd -T 2>/dev/null | awk '$1 == "passwordauthentication" || $1 == "kbdinteractiveauthentication" { print $2 }' | sort -u | tr '\n' ' ')
if [[ "$SSH_PASSWORDS" == "no " ]]; then
    echo "SSH abierto solo con clave (sin contraseñas)."
else
    warn "SSH acepta contraseñas: usa claves SSH (PasswordAuthentication no)."
fi
ufw --force enable >/dev/null
ufw status verbose

# ---------------------------------------------------------------------------
# Certificado HTTPS de Let's Encrypt (desafío HTTP por el puerto 80)
# ---------------------------------------------------------------------------
if [[ $CERT_OK -eq 0 ]]; then
    say "Certificado para $DOMAIN"
    if certbot certonly --webroot -w "$ACME_DIR" -d "$DOMAIN" -m "$LE_EMAIL" \
            --agree-tos --non-interactive --keep-until-expiring; then
        CERT_OK=1
        render_site "$LE_CERT" "$LE_KEY"
        apache2ctl configtest
        systemctl reload apache2
    fi
fi
if [[ $CERT_OK -eq 1 ]]; then
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    printf '#!/bin/sh\nsystemctl reload apache2\n' > /etc/letsencrypt/renewal-hooks/deploy/mesalista-reload.sh
    chmod 755 /etc/letsencrypt/renewal-hooks/deploy/mesalista-reload.sh
else
    warn "No se ha podido obtener el certificado de Let's Encrypt para $DOMAIN."
    warn "Se usa uno autofirmado temporal (el navegador mostrará un aviso y NO cumple RS-01)."
    warn "Comprueba que $DOMAIN apunta a la IP pública y que el puerto 80 está abierto"
    warn "(en Azure: Redes > reglas de entrada), y vuelve a ejecutar install.sh."
fi

# ---------------------------------------------------------------------------
# Tareas programadas. PostgreSQL no tiene eventos: los procesos automáticos de
# la base de datos los lanza worker.php.
# ---------------------------------------------------------------------------
say "Tareas programadas"
cat > /etc/cron.d/mesalista <<EOF
# MesaLista
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# No presentadas, señales vencidas, devoluciones, correos y avisos push
* * * * *     www-data  php ${PHP_DIR}/bin/worker.php >> /var/log/mesalista/worker.log 2>&1
# Borrado de datos personales caducados (RP-03, RP-08)
0 4 * * *     www-data  php ${PHP_DIR}/bin/worker.php --diario >> /var/log/mesalista/worker.log 2>&1
# RD-03: copia diaria de la base de datos
30 3 * * *    root      ${LIB_DIR}/backup.sh >> /var/log/mesalista/backup.log 2>&1
EOF
chmod 644 /etc/cron.d/mesalista
cat > /etc/logrotate.d/mesalista <<'EOF'
/var/log/mesalista/*.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    su www-data www-data
}
EOF

# ---------------------------------------------------------------------------
# Cuenta de administrador
# ---------------------------------------------------------------------------
ADMINS=$(sudo -u postgres psql -X -tA -d mesalista -c "SELECT COUNT(*) FROM users WHERE role = 'admin'")
if [[ "$ADMINS" == "0" ]]; then
    say "Cuenta de administrador ($ADMIN_EMAIL)"
    php "$PHP_DIR/bin/create_user.php" admin "$ADMIN_EMAIL" "Administrador"
fi

say "Listo"
cat <<EOF
  API:               https://${DOMAIN}/api/v1/config
  Raíz de la web:    ${WEB_DIR}   (y sus directivas de Apache en /etc/apache2/mesalista-web.d/)
  Configuración:     ${PHP_DIR}/config/config.php
  Copias:            /var/backups/mesalista (diarias a las 03:30)

  Correo con Azure Communication Services (si no lo configuraste al instalar):
    sudo php ${PHP_DIR}/bin/configure_mail.php
  Avisos push con Firebase (cuenta de servicio del proyecto):
    sudo php ${PHP_DIR}/bin/configure_fcm.php <cuenta-de-servicio.json>
  Señal de los grupos con PayPal sandbox (opcional):
    sudo php ${PHP_DIR}/bin/configure_paypal.php
  Cuentas de demostración (opcional):
    sudo php ${PHP_DIR}/bin/seed_demo.php "<contraseña de al menos 10 caracteres>"
EOF
