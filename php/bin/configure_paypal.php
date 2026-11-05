<?php
declare(strict_types=1);

// RF-44: guarda en config/config.php las claves de PayPal sandbox y comprueba
// que PayPal las acepta. Las claves se escriben aquí, en el servidor; no se
// muestran en pantalla ni se guardan en ningún otro sitio.
//   sudo php /var/www/mesalista-servidor/php/bin/configure_paypal.php
//   (vacío como Client ID = quitar las claves y desactivar la señal)

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/config_tools.php';

$file = ML_ROOT . '/config/config.php';
$config = require $file;

fwrite(STDOUT, "PayPal sandbox: developer.paypal.com > Apps & Credentials > Sandbox > tu app.\n");
$clientId = ask('Client ID: ');
$secret = $clientId === '' ? '' : ask('Secret (no se ve al escribir): ', true);

if ($clientId !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $clientId) || !preg_match('/^[A-Za-z0-9_-]{20,128}$/', $secret)) {
        fwrite(STDERR, "El Client ID o el Secret no tienen el formato esperado. No se ha cambiado nada.\n");
        exit(2);
    }
    // Comprobación: pedir un token a PayPal sandbox con esas claves.
    $ch = curl_init('https://api-m.sandbox.paypal.com/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $clientId . ':' . $secret,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200) {
        fwrite(STDERR, "PayPal sandbox no acepta esas claves (HTTP {$status}). No se ha cambiado nada.\n");
        exit(3);
    }
    fwrite(STDOUT, "PayPal sandbox acepta las claves.\n");
}

$config['paypal'] = ['mode' => 'sandbox', 'client_id' => $clientId, 'secret' => $secret];
saveConfig($file, $config, 'configure_paypal.php');
fwrite(STDOUT, $clientId === ''
    ? "Claves quitadas: los grupos aceptados se confirman sin señal.\n"
    : "Guardado. En un minuto (OPcache) los grupos aceptados pagarán la señal (Parámetros > señal por persona).\n");
