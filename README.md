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

## Estructura

```
bd/
  esquema.sql      Tablas, vista, restricción contra solapamientos y procedimientos
  datos.sql        Restaurante de pruebas: 12 mesas con plano + 2 extra, franjas, parámetros
php/               Lógica de la API (fuera de la raíz web)
publico/           Punto de entrada de la API
despliegue/        Instalación en Azure, Apache, copias de seguridad
pruebas/           Pruebas automáticas de la API
docs/              Contrato de la API (OpenAPI) y guía para la web y la app
```

## Estado

Se construye por fases:

1. **Esquema de PostgreSQL y conexión** (hecha).
2. **API `/api/v1` con tokens Bearer** (hecha).
3. **Pruebas automáticas** (hecha).
4. **Avisos push con Firebase Cloud Messaging** (hecha: 29 casos, 338 comprobaciones correctas).
5. Instalación, `openapi.yaml` y guía de integración.

## Base de datos en local

Con PostgreSQL 16 (la base de datos debe pertenecer al usuario de la aplicación,
que crea la extensión `btree_gist`):

```bash
psql -U postgres -c "CREATE ROLE mesalista LOGIN PASSWORD '<contraseña>';"
psql -U postgres -c "CREATE DATABASE mesalista OWNER mesalista ENCODING 'UTF8' TEMPLATE template0;"
psql -U mesalista -d mesalista -f bd/esquema.sql
psql -U mesalista -d mesalista -f bd/datos.sql
```

`esquema.sql` borra y crea todas las tablas.

## API en local

1. Copia `php/config/config.example.php` como `php/config/config.php` y, para
   desarrollo, pon `'require_https' => false` y `'test_clock' => true`.
2. Arranca el servidor integrado de PHP (con la extensión `pdo_pgsql`):

   ```bash
   php -d extension=pdo_pgsql -S 127.0.0.1:8091 -t publico pruebas/dev-router.php
   ```

3. Crea una cuenta del personal: `php php/bin/create_user.php admin admin@mesalista.test "Administración"`.

## Pruebas automáticas

`pruebas/run.php` ejecuta los casos CP-01 a CP-28 y CP-35 (avisos push; CP-29 a
CP-34 son pruebas manuales) contra la API con un reloj
simulado. **Borra todos los datos**, así que solo se usa en local o en un
servidor de pruebas con `'test_clock' => true` y la pasarela simulada
(`'paypal' => ['mode' => 'fake']`):

```bash
php -d extension=pdo_pgsql pruebas/run.php http://127.0.0.1:8091 --yes
php -d extension=pdo_pgsql pruebas/run.php http://127.0.0.1:8091 --yes --only=CP-06,CP-23
```

El correo se prueba contra `pruebas/azure-stub.php` y los avisos push contra
`pruebas/fcm-stub.php`: servidores falsos que comprueban las firmas como Azure
y como Google.

## Cómo se usa la API

- Todas las rutas empiezan por `/api/v1/` y usan JSON.
- `POST /api/v1/auth/login` (clientes) y `POST /api/v1/panel/login` (personal)
  devuelven `session.token`. En las peticiones siguientes va en la cabecera
  `Authorization: Bearer <token>`. Un token de cliente no sirve en el panel ni al revés.
- Los errores tienen siempre la forma `{"error": {"code": "…", "message": "…"}}`.
- La web llama a la API desde su PHP y envía la IP del navegador en
  `X-Forwarded-For` (solo se acepta desde `trusted_proxies`).
- Al pagar la señal se indica `{"from": "web"}` o `{"from": "app"}`; al terminar,
  PayPal devuelve al cliente a la plantilla `front.payment_web` o `front.payment_app`.

## Avisos push (Firebase Cloud Messaging)

- La app registra el token de FCM del dispositivo con
  `POST /api/v1/me/devices` `{"token": "…", "platform": "android"}` al abrirse y
  cuando FCM le da uno nuevo, y lo da de baja con `DELETE /api/v1/me/devices`
  `{"token": "…"}` al cerrar sesión.
- Cada cambio que envía un correo de reserva (confirmada, modificada, cancelada,
  grupo aceptado o rechazado, señal cobrada o vencida) envía también un aviso a
  sus dispositivos: título, fecha, hora y comensales, y en `data` el tipo
  (`type`) y el id de la reserva (`reservation_id`). Nunca el QR, las opciones
  ni la nota.
- El servidor usa la cuenta de servicio del proyecto de Firebase. En la consola
  de Firebase: *Configuración del proyecto › Cuentas de servicio › Generar nueva
  clave privada*. Después, en el servidor:

  ```bash
  sudo php /var/www/mesalista-servidor/php/bin/configure_fcm.php <archivo.json>
  ```

  Comprueba con Google que la cuenta funciona y la guarda en `config.php`. Luego
  se puede borrar el `.json`.
- Un aviso que no se ha podido enviar en una hora se descarta; los dispositivos
  que FCM ya no conoce se borran solos.
