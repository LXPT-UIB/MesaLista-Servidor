<?php
declare(strict_types=1);

// Genera config/config.php a partir de config.example.php con los valores de
// las variables de entorno. Lo usa despliegue/install.sh.
//
//   ML_DB_PASS, ML_DOMAIN, ML_MAIL_CONNECTION, ML_MAIL_SENDER (opcionales las de correo)
//
// Si config.php ya existe no se regenera (conserva claves y contraseñas); solo
// se actualiza el dominio, y con él las direcciones de la web (front.*), si
// ML_DOMAIN es otro.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$target = __DIR__ . '/../config/config.php';
$dbPass = getenv('ML_DB_PASS');
$domain = getenv('ML_DOMAIN');
if (!$dbPass || !$domain) {
    fwrite(STDERR, "Faltan ML_DB_PASS o ML_DOMAIN.\n");
    exit(2);
}

// Contiene secretos: solo root lo lee mientras se escribe (install.sh pone
// después el grupo www-data y los permisos 640).
umask(0077);
$write = function (array $c, string $note) use ($target): void {
    $php = "<?php\n// {$note} el " . date('Y-m-d H:i') . ". Contiene secretos: no lo copies fuera del servidor.\n"
        . 'return ' . var_export($c, true) . ";\n";
    $tmp = $target . '.tmp';
    if (file_put_contents($tmp, $php) === false || !rename($tmp, $target)) {
        fwrite(STDERR, "No se ha podido escribir {$target}\n");
        exit(1);
    }
};

if (is_file($target)) {
    $c = require $target;
    $old = (string) ($c['domain'] ?? '');
    if ($old === $domain) {
        fwrite(STDOUT, "config.php ya existe; no se modifica.\n");
        exit(0);
    }
    $c['domain'] = $domain;
    foreach ($c['front'] ?? [] as $key => $url) {
        if ($old !== '') {
            $c['front'][$key] = str_replace('://' . $old . '/', '://' . $domain . '/', $url);
        }
    }
    $write($c, 'Dominio actualizado por install.sh');
    fwrite(STDOUT, "config.php: dominio cambiado de «{$old}» a «{$domain}».\n");
    exit(0);
}

$c = require __DIR__ . '/../config/config.example.php';
$c['db']['pass'] = $dbPass;
$c['domain'] = $domain;
$c['qr_secret'] = bin2hex(random_bytes(32));
foreach ($c['front'] as $key => $url) {
    $c['front'][$key] = str_replace('<dominio>', $domain, $url);
}
$mailConnection = (string) getenv('ML_MAIL_CONNECTION');
$mailSender = (string) getenv('ML_MAIL_SENDER');
if ($mailConnection !== '' && $mailSender !== '') {
    $c['mail']['connection_string'] = $mailConnection;
    $c['mail']['sender'] = $mailSender;
    $c['mail']['enabled'] = true;
}

$write($c, 'Generado por install.sh');
fwrite(STDOUT, "config.php creado.\n");
