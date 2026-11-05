<?php
declare(strict_types=1);

// Carga común de la API y de los scripts de línea de comandos.

const ML_ROOT = __DIR__;

date_default_timezone_set('Europe/Madrid');
mb_internal_encoding('UTF-8');

$mlConfigFile = ML_ROOT . '/config/config.php';
if (!is_file($mlConfigFile)) {
    http_response_code(500);
    exit("Falta php/config/config.php (copia config.example.php)\n");
}
$GLOBALS['ML_CONFIG'] = require $mlConfigFile;

/** Valor de configuración con notación de puntos: config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['ML_CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Validate.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Schedule.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Qr.php';
require_once __DIR__ . '/Mail.php';
require_once __DIR__ . '/Push.php';
require_once __DIR__ . '/Booking.php';
require_once __DIR__ . '/Present.php';
require_once __DIR__ . '/Admin.php';
require_once __DIR__ . '/Holds.php';
require_once __DIR__ . '/Export.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Payments.php';
require_once __DIR__ . '/GroupRequests.php';
