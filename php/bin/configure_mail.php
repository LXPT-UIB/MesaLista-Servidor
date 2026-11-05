<?php
declare(strict_types=1);

// RF-17: guarda en config/config.php la cadena de conexión y el remitente de
// Azure Communication Services Email y, si se quiere, envía un correo de
// prueba. Las claves se escriben aquí, en el servidor; no se muestran en
// pantalla ni se guardan en ningún otro sitio.
//   sudo php /var/www/mesalista-servidor/php/bin/configure_mail.php
//   («no» como cadena de conexión = desactivar el envío: los correos se quedan
//   en email_outbox)

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/config_tools.php';

$file = ML_ROOT . '/config/config.php';
$config = require $file;
$old = $config['mail'] ?? [];
$mail = [
    'enabled'           => ($old['enabled'] ?? false) === true,
    'connection_string' => (string) ($old['connection_string'] ?? ''),
    'sender'            => (string) ($old['sender'] ?? ''),
];

fwrite(STDOUT, $mail['enabled'] && $mail['connection_string'] !== ''
    ? "Correo activado (remitente {$mail['sender']}).\n"
    : "Correo desactivado: los correos se quedan en la cola.\n");
fwrite(STDOUT, "Portal de Azure > tu recurso Communication Services > Configuración > Claves > Cadena de conexión.\n");
$connection = ask('Cadena de conexión (no se ve al escribir; vacío = mantener la actual; «no» = desactivar): ', true);

if (strtolower($connection) === 'no') {
    $mail['enabled'] = false;
} else {
    $connection = $connection !== '' ? $connection : $mail['connection_string'];
    if (!preg_match('#^endpoint=https://[^;\s]+;accesskey=[A-Za-z0-9+/]+=*;?$#i', $connection)) {
        fwrite(STDERR, "Eso no parece una cadena de conexión («endpoint=https://…;accesskey=…»). No se ha cambiado nada.\n");
        exit(2);
    }
    fwrite(STDOUT, "Remitente: recurso Email Communication Services > tu dominio > MailFrom addresses.\n");
    $sender = ask('Remitente (DoNotReply@….azurecomm.net)' . ($mail['sender'] !== '' ? " [{$mail['sender']}]" : '') . ': ');
    $sender = $sender !== '' ? $sender : $mail['sender'];
    if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "El remitente no es una dirección de correo. No se ha cambiado nada.\n");
        exit(2);
    }
    $mail = ['enabled' => true, 'connection_string' => $connection, 'sender' => $sender];

    // Prueba opcional con la configuración nueva, antes de guardarla.
    $to = ask('Correo al que enviar una prueba (vacío = no probar): ');
    if ($to !== '') {
        $GLOBALS['ML_CONFIG']['mail'] = $mail;
        try {
            Mail::send($to, 'MesaLista · Prueba de correo', "Este correo confirma que MesaLista puede enviar con Azure Communication Services.\n");
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\nNo se ha cambiado nada.\n");
            exit(3);
        }
        fwrite(STDOUT, "Azure ha aceptado el correo de prueba: míralo en {$to} (también en spam).\n");
    }
}

$config['mail'] = $mail;
saveConfig($file, $config, 'configure_mail.php');
fwrite(STDOUT, $mail['enabled']
    ? "Guardado. Se aplica en un minuto (lo usa bin/worker.php en su siguiente vuelta).\n"
    : "Correo desactivado: los correos se quedan en email_outbox.\n");
