<?php
declare(strict_types=1);

// Crea una cuenta del personal desde la consola (la primera cuenta de
// administrador no puede crearse desde el panel).
//
//   php php/bin/create_user.php admin admin@mesalista.test "Nombre Apellido"
//   php php/bin/create_user.php staff recepcion@mesalista.test "Recepción"
//
// La contraseña se pide por teclado sin mostrarla, o se lee de la variable
// de entorno ML_PASSWORD.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../bootstrap.php';

[$script, $role, $email, $name] = array_pad($argv, 4, null);
if (!in_array($role, ['admin', 'staff'], true) || $email === null || $name === null) {
    fwrite(STDERR, "Uso: php {$script} admin|staff <correo> \"<nombre>\"\n");
    exit(2);
}

function ask_hidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $tty = DIRECTORY_SEPARATOR === '/' && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $line;
}

$password = getenv('ML_PASSWORD');
if ($password === false || $password === '') {
    $password = ask_hidden('Contraseña (mínimo 10 caracteres): ');
    if ($password !== ask_hidden('Repite la contraseña: ')) {
        fwrite(STDERR, "Las contraseñas no coinciden.\n");
        exit(1);
    }
}

try {
    Admin::createStaff(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role]);
    fwrite(STDOUT, "Cuenta {$role} creada: {$email}\n");
} catch (ApiError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
