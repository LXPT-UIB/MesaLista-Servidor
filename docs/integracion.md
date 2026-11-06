# MesaLista · Cómo conectar la web y la app con el servidor

Guía para los equipos de la **web** (HTML, CSS, PHP, JavaScript y Bootstrap) y de
la **app** (con Firebase). El contrato completo de la API, ruta a ruta, está en
[`openapi.yaml`](openapi.yaml): se puede abrir con [Swagger Editor](https://editor.swagger.io)
o cualquier visor de OpenAPI.

## 1. La regla principal

**Todas las reglas del servicio están en el servidor.** La web y la app no
deciden nada: ni qué horas están libres, ni plazos, ni ventanas de llegada, ni qué
mesa toca, ni si un QR vale. Muestran lo que responde la API y envían lo que hace
el usuario. Pueden validar formularios para ayudar (campo vacío, formato del
teléfono), pero la última palabra es siempre del servidor.

## 2. Dónde está la API

| Entorno | Dirección base |
|---|---|
| Servidor de Azure | `https://mesalista.spaincentral.cloudapp.azure.com/api/v1` |
| Desarrollo en local | `http://127.0.0.1:8091/api/v1` (ver el README) |

- Todo va en JSON (`Content-Type: application/json`), salvo la descarga del CSV.
- Solo HTTPS en el servidor: una petición por HTTP recibe una redirección.
- La versión va en la ruta (`/v1`): si algún día cambia el contrato de forma
  incompatible, será `/v2` y `/v1` seguirá funcionando un tiempo.

## 3. Sesiones con token Bearer

1. **Iniciar sesión.** Clientes: `POST /auth/login` (o `POST /auth/register` al
   crear la cuenta). Personal y administración: `POST /panel/login`. La respuesta
   trae `session.token` (64 caracteres), `session.kind` (`app` o `panel`) y
   `session.expires_at`.
2. **En cada petición**, la cabecera `Authorization: Bearer <token>`.
3. **Caducidad.** Cliente: 60 minutos. Panel: 8 horas como máximo y 30 minutos sin
   actividad. Cuando caduca, la API responde `401` con `code: unauthenticated`:
   hay que volver a pedir la contraseña.
4. **Cerrar sesión.** `POST /auth/logout` o `POST /panel/logout`: el token deja de
   valer en el servidor.
5. Un token de cliente no sirve en `/panel/*` ni al revés. Las rutas `/admin/*`
   piden además el rol de administrador (`403 forbidden`).
6. Las consultas automáticas del panel (el sondeo de cada segundo) llevan
   `X-Background: 1`, para que no cuenten como actividad.

El token es como una contraseña: no se escribe en registros, no va en la URL y,
en la web, **nunca llega al navegador** (apartado 5).

## 4. Respuestas y errores

- Éxito: `200`, o `201` al crear algo. Cada ruta devuelve un objeto (`reservation`,
  `reservations`, `tables`…), descrito en `openapi.yaml`.
- Error: siempre la misma forma.

  ```json
  {"error": {"code": "conflict", "message": "Esa hora ya no está disponible. Elige otra.", "field": "time"}}
  ```

  La web y la app reaccionan al **`code`** y pueden mostrar **`message`** tal cual
  (está en español). `field` aparece en los errores de validación.

| HTTP | Códigos frecuentes | Qué hacer |
|---|---|---|
| 400 | `bad_json`, `reset_invalid` | Error del programa o enlace caducado |
| 401 | `unauthenticated`, `bad_credentials` | Pedir que inicie sesión |
| 403 | `forbidden`, `bad_password`, `bad_pin` | Sin permiso o contraseña o PIN mal |
| 404 | `not_found` | No existe o no es suya |
| 409 | `conflict`, `limit`, `deadline`, `email_taken`, `too_early`, `qr_outdated`, `pending_payment`… | Choca con una regla: mostrar el mensaje |
| 422 | `invalid`, `closed`, `past`, `too_soon`, `too_far`, `qr_invalid` | Dato no válido: marcar `field` |
| 429 | `locked` | Demasiados intentos: esperar 15 minutos |
| 503 | `payments_off` | PayPal no disponible |

## 5. La web (PHP + Bootstrap)

### 5.1 El PHP de la web llama a la API

El navegador **solo habla con la web**. El PHP de la web llama a la API con cURL y
guarda el token en su propia sesión PHP. Así el token nunca está en JavaScript y
no hace falta CORS.

```php
<?php
// Llamada a la API desde el PHP de la web.
function api(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init('https://mesalista.spaincentral.cloudapp.azure.com/api/v1' . $path);
    $headers = ['Accept: application/json', 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR']];
    if (!empty($_SESSION['token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['token'];
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        // Misma máquina: se conecta a 127.0.0.1 con el certificado del dominio.
        CURLOPT_RESOLVE        => ['mesalista.spaincentral.cloudapp.azure.com:443:127.0.0.1'],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $data = json_decode((string) $raw, true) ?? [];
    if ($status === 401) {
        unset($_SESSION['token']);   // sesión caducada: a la página de entrar
    }
    return ['status' => $status, 'body' => $data];
}

// Iniciar sesión: el token se queda en la sesión PHP.
$r = api('POST', '/auth/login', ['email' => $email, 'password' => $password]);
if ($r['status'] === 200) {
    session_regenerate_id(true);
    $_SESSION['token'] = $r['body']['session']['token'];
}
```

- **IP del navegador:** la web envía `X-Forwarded-For` con `$_SERVER['REMOTE_ADDR']`.
  El servidor solo la acepta si la petición llega de una dirección de
  `trusted_proxies` (127.0.0.1 en la misma máquina; por eso `CURLOPT_RESOLVE`).
  Así el bloqueo tras 5 intentos fallidos es por persona y no bloquea a toda la web.
- **Cookie de sesión de la web:** `HttpOnly`, `Secure` y `SameSite=Lax`. Como la
  web sí usa cookies, necesita su propia protección CSRF en los formularios (un
  token oculto por sesión).
- **JavaScript de la web:** si una página necesita datos sin recargar (la sala del
  panel cada segundo, el escáner de QR), llama a un PHP de la web, que a su vez
  llama a la API. Nunca a la API directamente.

### 5.2 Lo que la web tiene que mostrar

- **Reservar:** `GET /config` (comensales, opciones de menú…) y
  `GET /availability?date=&party=`. Al confirmar, `POST /reservations` con un
  `request_id` nuevo (UUID) por cada intento del usuario; si hay un corte y se
  reintenta con el mismo, no se duplica.
- **QR de la reserva:** `reservation.qr` es un texto (`ML1.<id>.<versión>.<firma>`):
  se dibuja con una librería de QR en JavaScript. No se guarda ni se modifica.
- **Escáner del panel:** la cámara lee el QR y su texto se envía a
  `POST /panel/arrive-qr`; el servidor comprueba la firma y la hora.
- **Sala en tiempo real:** `GET /panel/version` cada segundo con `X-Background: 1`;
  solo si la marca cambia se vuelve a pedir `GET /panel/room` o la agenda.
- **Ticket:** `ticket` de `POST /panel/reservations`; se imprime con CSS
  `@media print`.
- **CSV:** `GET /panel/reservations.csv?date=` lo pide el PHP de la web y lo
  reenvía al navegador como descarga.

### 5.3 Páginas que el servidor enlaza

El servidor envía al cliente a estas direcciones (plantillas `front.*` de
`config.php`; si la web usa otras rutas, se cambian ahí):

| Plantilla | Valor por defecto | Para qué |
|---|---|---|
| `front.reset` | `https://<dominio>/restablecer?token={token}` | Enlace del correo de «He olvidado la contraseña»: la página pide la contraseña nueva y llama a `POST /auth/reset` con el token |
| `front.payment_web` | `https://<dominio>/reservas/{id}?pago={result}` | Vuelta de PayPal al pagar desde la web; `result`: `ok`, `error`, `caducado` o `cancelado` |
| `front.payment_unknown` | `https://<dominio>/reservas?pago=error` | Vuelta de PayPal sin un pedido conocido |

### 5.4 Apache y Bootstrap

- La web se instala en `/var/www/mesalista-web` (raíz del dominio). Sus directivas
  propias (caché, reescrituras, cabeceras) van en
  `/etc/apache2/mesalista-web.d/*.conf`, sin tocar el sitio del servidor.
- Se recomienda copiar Bootstrap dentro de la web (no cargarlo de un CDN) y poner
  una CSP estricta, por ejemplo
  `Header always set Content-Security-Policy "default-src 'self'; frame-ancestors 'none'"`.
- No registrar la cadena de consulta en los registros de acceso (el token del
  enlace de contraseña va en ella): el sitio de Apache ya usa un formato sin ella.

## 6. La app (con Firebase)

### 6.1 Llamadas a la API

- La app llama directamente a `https://<dominio>/api/v1` y **rechaza cualquier
  error de certificado**.
- Guarda el token en almacenamiento cifrado (Android Keystore o
  EncryptedSharedPreferences; en iOS, el llavero), nunca en texto plano.
- Firebase **no guarda reservas ni cuentas**: Firestore no puede impedir que dos
  personas reserven la misma mesa. Todo pasa por la API.

### 6.2 Avisos push (Firebase Cloud Messaging)

1. Tras iniciar sesión, la app pide su token de FCM y lo registra:
   `POST /me/devices` `{"token": "<token de FCM>", "platform": "android"}`.
2. Lo vuelve a registrar al abrirse y cuando FCM le da uno nuevo
   (`onNewToken`). Si no se registra en 60 días, el servidor lo olvida.
3. Al cerrar sesión: `DELETE /me/devices` `{"token": "…"}` y después
   `POST /auth/logout`.
4. Cada aviso trae `notification.title` y `notification.body` (fecha, hora y
   comensales) y en `data`:

   | `data.type` | Cuándo |
   |---|---|
   | `confirmed` | Reserva confirmada (también al pagar la señal o al aceptar un grupo sin señal) |
   | `modified` | Reserva modificada: el QR anterior ya no vale |
   | `cancelled_holder` | El cliente ha cancelado |
   | `cancelled_restaurant` | El restaurante ha cancelado |
   | `group_accepted` | Grupo aceptado: falta pagar la señal |
   | `group_rejected` | Grupo no aceptado |
   | `payment_expired` | No se pagó la señal a tiempo |

   `data.reservation_id`, cuando lo hay, permite abrir la reserva directamente con
   `GET /reservations/{id}`. Los avisos nunca llevan el QR, las opciones ni la nota.

El proyecto de Firebase de la app es el mismo cuya **cuenta de servicio** se
configura en el servidor (`php/bin/configure_fcm.php`).

### 6.3 Pago de la señal

1. `POST /reservations/{id}/pay` con `{"from": "app"}` devuelve `approve_url`.
2. La app la abre en una pestaña del sistema (Custom Tabs o
   `ASWebAuthenticationSession`), no en un WebView propio.
3. Al terminar, el servidor redirige a `mesalista://reserva/{id}?pago=<resultado>`
   (plantilla `front.payment_app`). La app registra el esquema `mesalista://`,
   cierra la pestaña y vuelve a pedir la reserva.

### 6.4 Contraseña olvidada

El enlace del correo abre la página de la web (`front.reset`): la app solo tiene
que llamar a `POST /auth/forgot`.

## 7. Probar contra el servidor

- En local: el README explica cómo arrancar PostgreSQL y la API en el puerto 8091.
- Cuentas de demostración: `php php/bin/seed_demo.php "<contraseña>"` crea
  `recepcion@mesalista.test` (personal) y tres clientes, con reservas para mañana.
- Un servidor de pruebas con `'test_clock' => true` acepta la cabecera
  `X-Test-Now: AAAA-MM-DD HH:MM:SS` para simular la hora (plazos, ventanas de
  llegada). En el servidor real está desactivada.
- La pasarela simulada (`'paypal' => ['mode' => 'fake']`) solo existe en un
  servidor de pruebas.

## 8. Resumen para cada equipo

**Web**
- Llama desde su PHP, guarda el token en `$_SESSION` y envía `X-Forwarded-For`.
- Tiene sus propias cookies con protección CSRF.
- Crea las páginas de `front.reset` y `front.payment_web`, y pone su CSP en
  `mesalista-web.d`.

**App**
- Llama directamente por HTTPS y guarda el token cifrado.
- Registra el dispositivo en `/me/devices` y abre la reserva de `data.reservation_id`.
- Paga con `{"from": "app"}` y vuelve por `mesalista://`.
