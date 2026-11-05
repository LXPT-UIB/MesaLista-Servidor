<?php
declare(strict_types=1);

// Avisos push: guarda en config/config.php la cuenta de servicio del proyecto
// de Firebase y comprueba con Google que funciona antes de guardarla. La clave
// privada no se muestra en pantalla ni se guarda en ningún otro sitio.
//   sudo php /var/www/mesalista-servidor/php/bin/configure_fcm.php <archivo.json>
//   sudo php /var/www/mesalista-servidor/php/bin/configure_fcm.php no      (desactivar)
//
// El archivo JSON se descarga en la consola de Firebase: Configuración del
// proyecto > Cuentas de servicio > Generar nueva clave privada. Después de
// guardarlo, bórralo del servidor: config.php ya tiene lo necesario.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/config_tools.php';

$file = ML_ROOT . '/config/config.php';
$config = require $file;
$arg = $argv[1] ?? '';

if ($arg === '') {
    fwrite(STDERR, "Uso: php {$argv[0]} <cuenta-de-servicio.json>   o   php {$argv[0]} no\n");
    exit(2);
}

if (strtolower($arg) === 'no') {
    $config['fcm'] = ['enabled' => false] + ($config['fcm'] ?? []);
    saveConfig($file, $config, 'configure_fcm.php');
    fwrite(STDOUT, "Avisos push desactivados: los avisos se quedan en push_outbox y caducan a la hora.\n");
    exit(0);
}

$json = is_file($arg) ? json_decode((string) file_get_contents($arg), true) : null;
if (!is_array($json) || ($json['type'] ?? '') !== 'service_account'
    || !is_string($json['project_id'] ?? null) || !is_string($json['client_email'] ?? null)
    || !str_contains((string) ($json['private_key'] ?? ''), 'PRIVATE KEY')) {
    fwrite(STDERR, "{$arg} no es el archivo JSON de una cuenta de servicio de Firebase. No se ha cambiado nada.\n");
    exit(2);
}
$fcm = [
    'enabled'      => true,
    'project_id'   => $json['project_id'],
    'client_email' => $json['client_email'],
    'private_key'  => $json['private_key'],
];

// Prueba con la configuración nueva antes de guardarla: Google tiene que dar un token de acceso.
$GLOBALS['ML_CONFIG']['fcm'] = $fcm;
try {
    Push::checkCredentials();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\nNo se ha cambiado nada.\n");
    exit(3);
}

$config['fcm'] = $fcm;
saveConfig($file, $config, 'configure_fcm.php');
fwrite(STDOUT, "Guardado: avisos push con el proyecto {$fcm['project_id']}. Se aplica en un minuto.\n"
    . "Ya puedes borrar {$arg} del servidor.\n");
