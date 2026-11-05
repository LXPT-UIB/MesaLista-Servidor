# MesaLista · Servidor

Servidor del servicio de reserva de mesas MesaLista: API REST en PHP 8.3 con
Apache y PostgreSQL 16, en una máquina virtual de Azure con Ubuntu Server 24.04.
Es una de las tres partes del proyecto:

| Parte | Tecnologías | Cómo se conecta |
|---|---|---|
| **Servidor** (este repositorio) | Apache, PHP 8.3, PostgreSQL 16 | Ofrece la API en `https://<dominio>/api/v1/` |
| Web | HTML, CSS, PHP, JavaScript y Bootstrap | Su PHP llama a la API con un token Bearer |
| App | Con Firebase | Llama a la API con un token Bearer; recibe avisos push por FCM |

Todas las reglas del servicio (disponibilidad, plazos, mesas, QR, pagos…) están
en el servidor: la web y la app solo muestran datos y envían peticiones.

**Para los equipos de la web y de la app:** [`docs/integracion.md`](docs/integracion.md)
explica cómo conectarse y [`docs/openapi.yaml`](docs/openapi.yaml) describe todas
las rutas.

## Estructura

```
bd/
  esquema.sql      Tablas, vista, restricción contra solapamientos y procedimientos
  datos.sql        Restaurante de pruebas: 12 mesas con plano + 2 extra, franjas, parámetros
php/               Lógica de la API (fuera de la raíz web)
  bin/             worker.php (cron), configure_mail/fcm/paypal.php, create_user.php, seed_demo.php
  config/          config.example.php (config.php lo genera install.sh y no se sube a Git)
publico/           Punto de entrada de la API (index.php)
despliegue/
  install.sh       Instalación completa en Azure (Ubuntu 24.04)
  apache-mesalista.conf, permisos.sql, backup.sh, restore.sh
pruebas/           run.php, servidores falsos de Azure y de Google, servidor de desarrollo
docs/              openapi.yaml e integracion.md
```

## Instalación en Azure

1. Máquina virtual con **Ubuntu Server 24.04 LTS**, IP pública estática y una
   **etiqueta DNS** (p. ej. `lapp.spaincentral.cloudapp.azure.com`).
2. Grupo de seguridad de red: **80 y 443** abiertos a todos; **22** solo desde tu IP.
3. En la máquina:

   ```bash
   git clone <repositorio> ~/MesaLista-Servidor
   cd ~/MesaLista-Servidor && sudo bash despliegue/install.sh
   ```

   Pide el dominio, un correo para Let's Encrypt, el correo del administrador y
   (la primera vez) la cadena de conexión y el remitente de Azure Communication
   Services; se pueden dejar en blanco. Al final, la contraseña del administrador.
4. Opcional, cuando se tengan las claves:

   ```bash
   sudo php /var/www/mesalista-servidor/php/bin/configure_mail.php
   sudo php /var/www/mesalista-servidor/php/bin/configure_fcm.php <cuenta-de-servicio.json>
   sudo php /var/www/mesalista-servidor/php/bin/configure_paypal.php
   ```

Queda así:

| Qué | Dónde |
|---|---|
| API | `https://<dominio>/api/v1/` → `/var/www/mesalista-servidor/publico` |
| Lógica y `config.php` | `/var/www/mesalista-servidor/php` |
| Raíz de la web (la instala su proyecto) | `/var/www/mesalista-web`, con sus directivas de Apache en `/etc/apache2/mesalista-web.d/` |
| Base de datos | PostgreSQL 16 solo en local; dueño `mesalista_owner`, la API entra como `mesalista` (solo lee y escribe datos) |
| Tareas | `worker.php` cada minuto, `worker.php --diario` a las 04:00 y copia a las 03:30 (`/etc/cron.d/mesalista`) |
| Copias | `/var/backups/mesalista`, las de los últimos 14 días |
| Registros | `/var/log/mesalista` |

**Actualizar:** `git pull` y volver a ejecutar `sudo bash despliegue/install.sh`
(no borra datos y conserva `config.php`). Para empezar de cero:
`sudo bash despliegue/install.sh --reset-db` (pide escribir `BORRAR`).

**Si en la máquina estaba MesaLista-Definitiva** (con MariaDB), el sitio de Apache
y las tareas de cron de este servidor la sustituyen. Sus archivos
(`/var/www/mesalista`) y su base de datos no se tocan; se pueden quitar después.

**Restaurar una copia** en un servidor limpio: `install.sh` y después
`sudo bash /usr/local/lib/mesalista/restore.sh <copia.tar.gz>`.

Los cambios futuros del esquema necesitarán scripts de migración: `install.sh`
solo carga `esquema.sql` en una base de datos vacía o con `--reset-db`.

## Desarrollo en local

1. PostgreSQL 16. La base de datos debe pertenecer al usuario que carga el
   esquema (crea las extensiones `btree_gist` y `unaccent`):

   ```bash
   psql -U postgres -c "CREATE ROLE mesalista LOGIN PASSWORD '<contraseña>';"
   psql -U postgres -c "CREATE DATABASE mesalista OWNER mesalista ENCODING 'UTF8' TEMPLATE template0;"
   psql -U mesalista -d mesalista -f bd/esquema.sql
   psql -U mesalista -d mesalista -f bd/datos.sql
   ```

   `esquema.sql` borra y crea todas las tablas.
2. Copia `php/config/config.example.php` como `php/config/config.php` y, para
   desarrollo, pon `'require_https' => false`, `'test_clock' => true` y
   `'paypal' => ['mode' => 'fake']`.
3. Arranca el servidor integrado de PHP (con la extensión `pdo_pgsql`):

   ```bash
   php -d extension=pdo_pgsql -S 127.0.0.1:8091 -t publico pruebas/dev-router.php
   ```

4. Cuentas: `php php/bin/create_user.php admin admin@mesalista.test "Administración"`
   o las de demostración con `php php/bin/seed_demo.php "<contraseña>"`.

## Pruebas automáticas

`pruebas/run.php` ejecuta los casos CP-01 a CP-28 y CP-35 (avisos push; CP-29 a
CP-34 son pruebas manuales) contra la API con un reloj simulado. **Borra todos
los datos**, así que solo se usa en local o en un servidor de pruebas con
`'test_clock' => true` y la pasarela simulada (`'paypal' => ['mode' => 'fake']`):

```bash
php -d extension=pdo_pgsql pruebas/run.php http://127.0.0.1:8091 --yes
php -d extension=pdo_pgsql pruebas/run.php http://127.0.0.1:8091 --yes --only=CP-06,CP-23
```

En un servidor de pruebas instalado con `install.sh`, el usuario de la API no
puede vaciar tablas; antes hay que dárselo y ejecutar las pruebas con su config:

```bash
sudo -u postgres psql -d mesalista -c "GRANT TRUNCATE ON ALL TABLES IN SCHEMA public TO mesalista"
sudo ML_PHP_DIR=/var/www/mesalista-servidor/php php pruebas/run.php https://<dominio> --yes
```

El correo se prueba contra `pruebas/azure-stub.php` y los avisos push contra
`pruebas/fcm-stub.php`: servidores falsos que comprueban las firmas como Azure
y como Google.

## La API en resumen

- Todas las rutas empiezan por `/api/v1/` y usan JSON.
- `POST /api/v1/auth/login` (clientes) y `POST /api/v1/panel/login` (personal)
  devuelven `session.token`. En las peticiones siguientes va en la cabecera
  `Authorization: Bearer <token>`. Un token de cliente no sirve en el panel ni al revés.
- Los errores tienen siempre la forma `{"error": {"code": "…", "message": "…"}}`.
- La web llama a la API desde su PHP y envía la IP del navegador en
  `X-Forwarded-For` (solo se acepta desde `trusted_proxies`).
- Al pagar la señal se indica `{"from": "web"}` o `{"from": "app"}`; al terminar,
  PayPal devuelve al cliente a la plantilla `front.payment_web` o `front.payment_app`.
- Avisos push: la app registra su dispositivo en `POST /api/v1/me/devices`; cada
  cambio que envía un correo de reserva envía también un aviso con `data.type` y
  `data.reservation_id`. Necesita la cuenta de servicio de Firebase
  (`configure_fcm.php`).

Todo el detalle, en [`docs/integracion.md`](docs/integracion.md) y
[`docs/openapi.yaml`](docs/openapi.yaml).
