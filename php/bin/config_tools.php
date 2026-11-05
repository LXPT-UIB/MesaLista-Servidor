<?php
declare(strict_types=1);

// Funciones comunes de los scripts que cambian config/config.php
// (configure_paypal.php y configure_mail.php).

/** Pregunta por la terminal; con $hidden no se ve lo que se escribe. */
function ask(string $prompt, bool $hidden = false): string
{
    fwrite(STDOUT, $prompt);
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return trim((string) $line);
}

/** Reescribe config.php con los mismos permisos y dueño (root:www-data, como lo deja install.sh). */
function saveConfig(string $file, array $config, string $script): void
{
    $php = "<?php\n// Modificado por {$script} el " . date('Y-m-d H:i') . ". Contiene secretos: no lo copies fuera del servidor.\n"
        . 'return ' . var_export($config, true) . ";\n";
    $tmp = $file . '.tmp';
    $ok = file_put_contents($tmp, $php) !== false && chmod($tmp, fileperms($file) & 0777);
    if ($ok && DIRECTORY_SEPARATOR === '/') {
        $ok = chown($tmp, fileowner($file)) && chgrp($tmp, filegroup($file));
    }
    if (!$ok || !rename($tmp, $file)) {
        fwrite(STDERR, "No se ha podido escribir {$file}\n");
        exit(1);
    }
}
