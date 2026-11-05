<?php
declare(strict_types=1);

// Carga las cuentas ficticias de la demostración y algunas reservas para
// mañana. Todas las cuentas usan la contraseña indicada.
//
//   php php/bin/seed_demo.php "<contraseña de demo>"
//
// Cuentas: recepcion@mesalista.test (personal), lucia@mesalista.test,
// joan@mesalista.test y marta@mesalista.test (clientes).

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../bootstrap.php';

$password = $argv[1] ?? '';
try {
    Auth::checkPasswordPolicy($password);
} catch (ApiError $e) {
    fwrite(STDERR, "Uso: php {$argv[0]} \"<contraseña>\"\n" . $e->getMessage() . "\n");
    exit(2);
}

$hash = Auth::hash($password);
$accounts = [
    ['staff',  'Recepción',     'recepcion@mesalista.test', null],
    ['client', 'Lucía Ferrer',  'lucia@mesalista.test',     '+34600111222'],
    ['client', 'Joan Pons',     'joan@mesalista.test',      '+34600333444'],
    ['client', 'Marta Vidal',   'marta@mesalista.test',     '+34600555666'],
];
$ids = [];
foreach ($accounts as [$role, $name, $email, $phone]) {
    $id = Db::value('SELECT id FROM users WHERE email = ?', [$email]);
    if ($id === null) {
        $id = Db::insert(
            'INSERT INTO users (role, name, email, phone, password_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$role, $name, $email, $phone, $hash, Clock::sql()]
        );
        fwrite(STDOUT, "Cuenta creada: {$email} ({$role})\n");
    }
    $ids[$email] = (int) $id;
}

// Reservas de ejemplo para mañana por la noche.
$tomorrow = Clock::now()->modify('+1 day')->format('Y-m-d');
$veggie = Db::value("SELECT id FROM menu_options WHERE name = 'Vegetariano'");
$examples = [
    ['marta@mesalista.test', '20:00', 2, [], 'Celebramos un cumpleaños.'],
    ['marta@mesalista.test', '21:30', 6, $veggie === null ? [] : [(int) $veggie], null],
];
foreach ($examples as [$email, $time, $party, $options, $note]) {
    $user = Db::one('SELECT * FROM users WHERE id = ?', [$ids[$email]]);
    try {
        Booking::createForClient($user, [
            'request_id'   => sprintf('%08x-0000-4000-8000-%012x', crc32($email . $time), $party),
            'date'         => $tomorrow,
            'time'         => $time,
            'party'        => $party,
            'menu_options' => $options,
            'note'         => $note,
            'consent'      => true,
        ]);
        fwrite(STDOUT, "Reserva de ejemplo: {$email} {$tomorrow} {$time} ({$party} pers.)\n");
    } catch (ApiError $e) {
        fwrite(STDOUT, "Reserva de ejemplo omitida ({$time}): {$e->getMessage()}\n");
    }
}
// Los correos de ejemplo no se envían.
Db::exec("DELETE FROM email_outbox WHERE to_email LIKE '%@mesalista.test'");
