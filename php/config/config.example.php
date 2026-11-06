<?php
// MesaLista · Configuración del servidor.
// Copiar como config.php (install.sh lo hace y rellena las claves).
// Este archivo queda fuera de la raíz web y no debe subirse a Git.

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 5432,
        'name' => 'mesalista',
        'user' => 'mesalista',
        'pass' => 'CAMBIAR',
    ],

    // Dominio público del servidor (etiqueta DNS de la IP pública de Azure), sin https://
    'domain' => 'mesalista.spaincentral.cloudapp.azure.com',

    // Clave para firmar los QR (RS-07). 64 caracteres hexadecimales aleatorios:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Si cambia, todos los QR emitidos dejan de valer.
    'qr_secret' => 'CAMBIAR',

    // RS-01: la API rechaza las peticiones que no llegan por HTTPS.
    'require_https' => true,

    // Reloj simulado para las pruebas (cabecera X-Test-Now). NUNCA activarlo
    // en el servidor de la demostración.
    'test_clock' => false,

    // Direcciones desde las que llama el PHP de la web. De ellas se acepta
    // X-Forwarded-For con la IP real del navegador (para RS-06).
    'trusted_proxies' => ['127.0.0.1', '::1'],

    // Páginas de la web y de la app a las que el servidor envía al cliente.
    // {token}, {id} y {result} se sustituyen; <dominio> lo pone install.sh.
    //   reset            enlace del correo para elegir otra contraseña (RF-40)
    //   payment_web/app  vuelta de PayPal según desde dónde pagó (RF-44);
    //                    {result}: ok, error, caducado o cancelado
    //   payment_unknown  vuelta de PayPal sin un pedido conocido
    'front' => [
        'reset'           => 'https://<dominio>/restablecer?token={token}',
        'payment_web'     => 'https://<dominio>/reservas/{id}?pago={result}',
        'payment_app'     => 'mesalista://reserva/{id}?pago={result}',
        'payment_unknown' => 'https://<dominio>/reservas?pago=error',
    ],

    // RF-17: correos con Azure Communication Services Email. Cadena de conexión
    // del recurso Communication Services (Configuración > Claves) y remitente
    // de su dominio (DoNotReply@….azurecomm.net). Se rellenan con
    // php/bin/configure_mail.php. Sin cadena de conexión, los correos se
    // quedan en email_outbox.
    'mail' => [
        'enabled'           => false,
        'connection_string' => '',
        'sender'            => '',
    ],

    // Avisos push en la app con Firebase Cloud Messaging. Datos de la cuenta de
    // servicio del proyecto de Firebase (Configuración del proyecto > Cuentas de
    // servicio > Generar nueva clave privada). Se rellenan con
    // php/bin/configure_fcm.php a partir de ese archivo JSON. Sin ellos, los
    // avisos esperan en push_outbox y a la hora se descartan.
    'fcm' => [
        'enabled'      => false,
        'project_id'   => '',
        'client_email' => '',
        'private_key'  => '',
    ],

    // RF-44: señal de los grupos con PayPal, SIEMPRE en modo sandbox (pruebas).
    // Claves de developer.paypal.com > Apps & Credentials > Sandbox. Se rellenan
    // con php/bin/configure_paypal.php. Sin claves, los grupos aceptados se
    // confirman sin señal.
    'paypal' => [
        'mode'      => 'sandbox',
        'client_id' => '',
        'secret'    => '',
    ],
];
