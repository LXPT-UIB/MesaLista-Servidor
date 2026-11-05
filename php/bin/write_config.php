<?php
declare(strict_types=1);

// Genera config/config.php a partir de config.example.php con los valores
// de las variables de entorno. Lo usa servidor/despliegue/install.sh; no sobrescribe un
// config.php que ya exista.
//
//   ML_DB_PASS, ML_DOMAIN, ML_MAIL_CONNECTION, ML_MAIL_SENDER (opcionales las de correo)

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$target = __DIR__ . '/../config/config.php';
if (is_file($target)) {
    fwrite(STDOUT, "config.php ya existe; no se modifica.\n");
    exit(0);
}

$c = require __DIR__ . '/../config/config.example.php';
$dbPass = getenv('ML_DB_PASS');
$domain = getenv('ML_DOMAIN');
if (!$dbPass || !$domain) {
    fwrite(STDERR, "Faltan ML_DB_PASS o ML_DOMAIN.\n");
    exit(2);
}
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

$php = "<?php\n// Generado por install.sh el " . date('Y-m-d H:i') . ". Contiene secretos: no lo copies fuera del servidor.\n"
    . 'return ' . var_export($c, true) . ";\n";
if (file_put_contents($target, $php) === false) {
    fwrite(STDERR, "No se ha podido escribir {$target}\n");
    exit(1);
}
fwrite(STDOUT, "config.php creado.\n");
