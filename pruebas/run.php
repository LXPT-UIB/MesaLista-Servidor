<?php
declare(strict_types=1);

// MesaLista · pruebas automáticas de la API (casos CP-01 a CP-28 y CP-35)
//
// Se ejecutan contra un servidor de PRUEBAS, en la misma máquina que la base
// de datos, con 'test_clock' => true en config.php (reloj simulado con la
// cabecera X-Test-Now). BORRAN todos los datos.
//
//   php pruebas/run.php https://<dominio de pruebas> --yes
//   php -d extension=pdo_pgsql pruebas/run.php http://127.0.0.1:8091 --yes   (desarrollo)
//
// Opciones: --insecure (no valida el certificado), --only=CP-06,CP-12

// En el servidor: ML_PHP_DIR=/var/www/mesalista-servidor/php para usar la instalación y su config.php.
require (getenv('ML_PHP_DIR') ?: __DIR__ . '/../php') . '/bootstrap.php';

$base = rtrim($argv[1] ?? '', '/');
$opts = array_slice($argv, 2);
if (!preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "Uso: php pruebas/run.php <url base> --yes [--insecure] [--only=CP-01,CP-02]\n");
    exit(2);
}
if (config('test_clock') !== true) {
    fwrite(STDERR, "Activa 'test_clock' => true en php/config/config.php (solo en un servidor de pruebas).\n");
    exit(2);
}
if (!in_array('--yes', $opts, true)) {
    fwrite(STDERR, "Estas pruebas BORRAN todas las cuentas y reservas. Añade --yes para continuar.\n");
    exit(2);
}
$insecure = in_array('--insecure', $opts, true);
$only = null;
foreach ($opts as $o) {
    if (str_starts_with($o, '--only=')) {
        $only = explode(',', substr($o, 7));
    }
}

// ---------------------------------------------------------------------------
// Cliente HTTP con su propio token de sesión y su reloj simulado
// ---------------------------------------------------------------------------

final class Res
{
    public function __construct(public int $status, public array $body) {}

    public function code(): ?string
    {
        return $this->body['error']['code'] ?? null;
    }
}

final class Client
{
    public ?string $token = null;
    public string $now;
    public string $email = '';

    public function __construct(private string $base, private bool $insecure, string $now)
    {
        $this->now = $now;
    }

    public function at(string $now): self
    {
        $this->now = $now;
        return $this;
    }

    public function handle(string $method, string $path, ?array $body = null, array $extra = []): CurlHandle
    {
        $ch = curl_init($this->base . '/api/v1' . $path);
        $headers = ['Accept: application/json', 'X-Test-Now: ' . $this->now];
        foreach ($extra as $h) {
            $headers[] = $h;
        }
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => !$this->insecure,
            CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
        ]);
        return $ch;
    }

    public static function result(CurlHandle $ch, ?string $raw): Res
    {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = json_decode((string) $raw, true);
        return new Res($status, is_array($data) ? $data : ['_raw' => $raw]);
    }

    public function req(string $method, string $path, ?array $body = null, array $extra = []): Res
    {
        $ch = $this->handle($method, $path, $body, $extra);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('HTTP: ' . curl_error($ch));
        }
        $res = self::result($ch, $raw);
        // Login y registro devuelven el token: a partir de aquí va en cada petición.
        if (isset($res->body['session']['token'])) {
            $this->token = $res->body['session']['token'];
        }
        return $res;
    }

    public function get(string $p, array $extra = []): Res { return $this->req('GET', $p, null, $extra); }
    public function post(string $p, array $b = []): Res { return $this->req('POST', $p, $b); }
    public function patch(string $p, array $b = []): Res { return $this->req('PATCH', $p, $b); }
    public function put(string $p, array $b = []): Res { return $this->req('PUT', $p, $b); }
    public function delete(string $p, array $b = []): Res { return $this->req('DELETE', $p, $b); }
}

// ---------------------------------------------------------------------------
// Utilidades de las pruebas
// ---------------------------------------------------------------------------

$results = ['ok' => 0, 'fail' => 0];
$failures = [];
$current = '';

function check(bool $cond, string $what, mixed $detail = null): void
{
    global $results, $failures, $current;
    if ($cond) {
        $results['ok']++;
        echo "    \033[32m✔\033[0m {$what}\n";
    } else {
        $results['fail']++;
        $failures[] = "{$current}: {$what}";
        echo "    \033[31m✘ {$what}\033[0m" . ($detail !== null ? ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }
}

/** Día base: un sábado al menos 14 días en el futuro, para que el evento real de no presentadas no lo toque. */
$D = new DateTimeImmutable('today +14 days');
while ($D->format('N') !== '6') {
    $D = $D->modify('+1 day');
}

/** Fecha y hora relativa al día base: at(-1, '21:00') = viernes 21:00. */
function at(int $days, string $hhmm): string
{
    global $D;
    return $D->modify("{$days} day")->format('Y-m-d') . " {$hhmm}:00";
}

function day(int $days = 0): string
{
    global $D;
    return $D->modify("{$days} day")->format('Y-m-d');
}

function client(string $now): Client
{
    global $base, $insecure;
    return new Client($base, $insecure, $now);
}

$PASSWORD = 'Prueba-MesaLista-2026';
$seq = 0;

/** Registra un cliente nuevo y devuelve su cliente HTTP con la sesión iniciada. */
function newClient(string $now, ?string $phone = null): Client
{
    global $PASSWORD, $seq;
    $seq++;
    $c = client($now);
    $r = $c->post('/auth/register', [
        'name' => 'Cliente Prueba ' . chr(65 + $seq % 26),
        'email' => "cliente{$seq}@pruebas.test",
        'phone' => $phone ?? sprintf('+34600%06d', $seq),
        'password' => $PASSWORD,
    ]);
    if ($r->status !== 201) {
        throw new RuntimeException('No se pudo registrar: ' . json_encode($r->body, JSON_UNESCAPED_UNICODE));
    }
    $c->email = "cliente{$seq}@pruebas.test";
    return $c;
}

function staffClient(string $now, string $role = 'staff'): Client
{
    global $PASSWORD, $seq;
    $seq++;
    $email = "{$role}{$seq}@pruebas.test";
    Admin::createStaff(['name' => 'Personal Prueba', 'email' => $email, 'password' => $PASSWORD, 'role' => $role]);
    $c = client($now);
    $r = $c->post('/panel/login', ['email' => $email, 'password' => $PASSWORD]);
    if ($r->status !== 200) {
        throw new RuntimeException('No se pudo iniciar sesión del personal: ' . json_encode($r->body));
    }
    return $c;
}

/**
 * Sesión nueva del personal a esa hora. Las pruebas saltan horas en el reloj
 * simulado, y una sesión del panel se cierra tras 30 minutos sin uso (RS-05).
 */
function staffAt(string $now): Client
{
    return staffClient($now);
}

function reserve(Client $c, string $date, string $time, int $party, array $extra = []): Res
{
    return $c->post('/reservations', ['request_id' => uuid(), 'date' => $date, 'time' => $time, 'party' => $party] + $extra);
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
}

/**
 * Atajo de las pruebas: las 12 mesas con plano numeradas en orden (1-8 la
 * sala, 9-12 la terraza): code(3) = 'S-3', code(9) = 'T-1'. Un código se
 * devuelve tal cual.
 */
function code(int|string $t): string
{
    return is_string($t) ? $t : ($t <= 8 ? "S-{$t}" : 'T-' . ($t - 8));
}

/** Id de una mesa por su código ('S-3') o por su número en orden (3, 9…). */
function tableId(int|string $t): int
{
    return (int) Db::value('SELECT id FROM dining_tables WHERE code_live = ?', [code($t)]);
}

/** Id de una opción de menú (cambia cada vez que se recargan los datos de prueba). */
function optionId(string $name): int
{
    return (int) Db::value('SELECT id FROM menu_options WHERE name = ?', [$name]);
}

function status(int $id): ?string
{
    return Db::value('SELECT status FROM reservations WHERE id = ?', [$id]);
}

function countReservations(): int
{
    return (int) Db::value('SELECT COUNT(*) FROM reservations');
}

/** Ocupa una mesa con un cliente sin reserva que ya está sentado. */
function occupy(int|string $table, string $now): int
{
    return Db::insert(
        "INSERT INTO reservations (source, table_id, party_size, start_at, end_at, arrived_at, status,
                                   arrival_before_min, arrival_after_min, change_deadline_h, created_at)
         VALUES ('walkin', ?, 2, ?, ?, ?, 'seated', 15, 20, 24, ?)",
        [tableId($table), $now, date('Y-m-d H:i:s', strtotime($now) + 7200), $now, $now]
    );
}

/** Deja la base de datos con el restaurante de pruebas y sin cuentas ni reservas. */
function resetDatabase(): void
{
    $pdo = Db::pdo();
    $pdo->exec('TRUNCATE table_occupancy, reservation_menu_options, reservation_tables, table_holds, notifications,
                 email_outbox, payments, group_requests, password_resets, secrets, reservations, sessions,
                 login_attempts, link_attempts, users, dining_tables, service_slots, closed_days, settings,
                 menu_options RESTART IDENTITY CASCADE');
    $sql = (string) file_get_contents(__DIR__ . '/../bd/datos.sql');
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    Settings::forget();
}

function setSetting(string $name, int $value): void
{
    Db::exec('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
    Settings::forget();
}

function test(string $id, string $title, callable $fn): void
{
    global $only, $current;
    if ($only !== null && !in_array($id, $only, true)) {
        return;
    }
    $current = $id;
    echo "\n\033[1m{$id} · {$title}\033[0m\n";
    resetDatabase();
    try {
        $fn();
    } catch (Throwable $e) {
        check(false, 'excepción: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

echo "MesaLista · pruebas contra {$base}\nDía base: " . day() . " (sábado)\n";

// ---------------------------------------------------------------------------
// CP-01 · RF-01, RS-09
// ---------------------------------------------------------------------------
test('CP-01', 'Registro y contraseñas robustas', function () use ($PASSWORD) {
    $c = client(at(-3, '12:00'));
    $ok = $c->post('/auth/register', ['name' => 'Lucía Ferrer', 'email' => 'lucia@pruebas.test', 'phone' => '+34 600 111 222', 'password' => $PASSWORD]);
    check($ok->status === 201, 'una cuenta nueva se crea', $ok->body);
    $dup = client(at(-3, '12:00'))->post('/auth/register', ['name' => 'Otra', 'email' => 'LUCIA@pruebas.test', 'phone' => '+34600111333', 'password' => $PASSWORD]);
    check($dup->status === 409 && $dup->code() === 'email_taken', 'el mismo correo se rechaza', $dup->body);
    $short = client(at(-3, '12:00'))->post('/auth/register', ['name' => 'Pepe', 'email' => 'pepe@pruebas.test', 'phone' => '+34600111444', 'password' => '123456789']);
    check($short->status === 422, 'una contraseña de 9 caracteres se rechaza', $short->body);
    $common = client(at(-3, '12:00'))->post('/auth/register', ['name' => 'Pepe', 'email' => 'pepe@pruebas.test', 'phone' => '+34600111444', 'password' => '1234567890']);
    check($common->status === 422, '«1234567890» se rechaza por común', $common->body);
    check((int) Db::value('SELECT COUNT(*) FROM users') === 1, 'solo existe una cuenta');
});

// ---------------------------------------------------------------------------
// CP-02 · RF-02, RF-03, RS-05, RS-06
// ---------------------------------------------------------------------------
test('CP-02', 'Inicio y cierre de sesión, caducidad y bloqueo', function () use ($PASSWORD) {
    $t0 = at(-3, '12:00');
    $c = newClient($t0);
    $email = $c->email;

    $badPass = client($t0)->post('/auth/login', ['email' => $email, 'password' => 'incorrecta-123']);
    $badMail = client($t0)->post('/auth/login', ['email' => 'nadie@pruebas.test', 'password' => $PASSWORD]);
    check($badPass->status === 401 && $badMail->status === 401
        && $badPass->body['error']['message'] === $badMail->body['error']['message'], 'mismo mensaje genérico con correo o contraseña erróneos');

    $s = client($t0);
    check($s->post('/auth/login', ['email' => $email, 'password' => $PASSWORD])->status === 200, 'con credenciales correctas se inicia sesión');
    check($s->get('/reservations')->status === 200, 'con la sesión se piden las reservas');
    $s->post('/auth/logout');
    $copy = client($t0);
    $copy->token = $c->token;
    $c->post('/auth/logout');
    check($copy->get('/reservations')->status === 401, 'tras cerrar la sesión, el mismo token se rechaza');

    $t = client($t0);
    $t->post('/auth/login', ['email' => $email, 'password' => $PASSWORD]);
    check($t->at(at(-3, '12:59'))->get('/me')->status === 200, 'la sesión vale a los 59 minutos');
    check($t->at(at(-3, '13:01'))->get('/me')->status === 401, 'la sesión se rechaza a los 61 minutos');

    $victim = newClient($t0);
    $x = client(at(-3, '14:00'));
    for ($i = 1; $i <= 5; $i++) {
        $x->post('/auth/login', ['email' => $victim->email, 'password' => "mala-{$i}-xxxxx"]);
    }
    $sixth = $x->post('/auth/login', ['email' => $victim->email, 'password' => $PASSWORD]);
    check($sixth->status === 429, 'el sexto intento se rechaza aunque la contraseña sea correcta', $sixth->body);
    $later = $x->at(at(-3, '14:16'))->post('/auth/login', ['email' => $victim->email, 'password' => $PASSWORD]);
    check($later->status === 200, 'pasados 15 minutos vuelve a poder entrar', $later->body);

    $p = staffClient(at(-3, '10:00'));
    check($p->at(at(-3, '10:29'))->get('/panel/me')->status === 200, 'panel activo a los 29 minutos');
    check($p->at(at(-3, '11:00'))->get('/panel/me')->status === 401, 'panel cerrado tras 31 minutos sin actividad');
    $q = staffClient(at(-3, '10:00'));
    $q->at(at(-3, '10:10'))->get('/panel/version', ['X-Background: 1']);
    check($q->at(at(-3, '10:31'))->get('/panel/me')->status === 401, 'el sondeo automático no cuenta como actividad');
    $r = staffClient(at(-3, '10:00'));
    for ($h = 0; $h < 8 * 60; $h += 25) {
        $r->at(date('Y-m-d H:i:s', strtotime(at(-3, '10:00')) + $h * 60))->get('/panel/me');
    }
    check($r->at(at(-3, '18:01'))->get('/panel/me')->status === 401, 'panel cerrado a las 8 horas aunque haya actividad');

    // Token Bearer: el de un tipo de sesión no vale en las rutas del otro, y
    // usarlo mal no cierra la sesión de su lado.
    $panel = staffClient(at(-3, '10:00'));
    $wrong = client(at(-3, '10:01'));
    $wrong->token = $panel->token;
    check($wrong->get('/me')->status === 401, 'el token del panel no vale en las rutas de cliente');
    check($panel->at(at(-3, '10:02'))->get('/panel/me')->status === 200, 'y la sesión del panel sigue abierta');
    check(client(at(-3, '10:00'))->get('/me', ['Authorization: Basic eDp5'])->status === 401, 'una cabecera Authorization que no es Bearer se rechaza');

    // RS-06 detrás de la web: su PHP envía la IP del navegador en X-Forwarded-For
    // y la API solo la acepta desde trusted_proxies (127.0.0.1 en desarrollo).
    global $base;
    if (in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
        $who = newClient(at(-3, '15:00'));
        $web = client(at(-3, '15:00'));
        for ($i = 1; $i <= 5; $i++) {
            $web->req('POST', '/auth/login', ['email' => $who->email, 'password' => "mala-{$i}-xxxxx"], ['X-Forwarded-For: 203.0.113.10']);
        }
        $blocked = $web->req('POST', '/auth/login', ['email' => $who->email, 'password' => $PASSWORD], ['X-Forwarded-For: 203.0.113.10']);
        $other = $web->req('POST', '/auth/login', ['email' => $who->email, 'password' => $PASSWORD], ['X-Forwarded-For: 203.0.113.11']);
        check($blocked->status === 429 && $other->status === 200,
            'el bloqueo por intentos es por la IP del navegador que envía la web, no por la de la web', [$blocked->status, $other->status]);
    } else {
        check(true, 'omitida: X-Forwarded-For solo se comprueba contra la API local');
    }
});

// ---------------------------------------------------------------------------
// CP-03 · RF-04, RF-05, RP-02, RP-03, RP-08
// ---------------------------------------------------------------------------
test('CP-03', 'Perfil, eliminación de cuenta y borrado de datos', function () use ($PASSWORD) {
    $now = at(-3, '12:00');
    $c = newClient($now);
    $p = $c->patch('/me', ['phone' => '+34 699 000 111']);
    check($p->status === 200 && $c->get('/me')->body['user']['phone'] === '+34699000111', 'RF-04: el perfil devuelve el teléfono nuevo');

    $r1 = reserve($c, day(), '20:00', 2, ['note' => 'nota', 'consent' => true])->body['reservation']['id'];
    $r2 = reserve($c, day(), '13:00', 2)->body['reservation']['id'];
    $uid = (int) Db::value('SELECT id FROM users WHERE email = ?', [$c->email]);
    $del = $c->delete('/me', ['password' => $PASSWORD]);
    check($del->status === 200, 'la cuenta se elimina', $del->body);
    check(status($r1) === 'cancelled' && status($r2) === 'cancelled', 'sus dos reservas futuras quedan «cancelada»');
    check(Db::value('SELECT COUNT(*) FROM users WHERE id = ?', [$uid]) == 0, 'nombre, correo y teléfono borrados');
    check(Db::value('SELECT COUNT(*) FROM reservations WHERE user_id IS NOT NULL OR note IS NOT NULL') == 0, 'las reservas se conservan sin datos personales');
    $login = client($now)->post('/auth/login', ['email' => $c->email, 'password' => $PASSWORD]);
    check($login->status === 401, 'la cuenta ya no inicia sesión');

    // RP-03 y RP-08 con reservas antiguas insertadas directamente.
    $today = new DateTimeImmutable('today');
    $mk = function (int $daysAgo, string $status) use ($today) {
        $start = $today->modify("-{$daysAgo} days")->format('Y-m-d') . ' 21:00:00';
        $id = Db::insert(
            "INSERT INTO reservations (source, guest_name, guest_phone, table_id, party_size, start_at, end_at, status,
                                       arrival_before_min, arrival_after_min, change_deadline_h, note, created_at)
             VALUES ('local', 'Persona Antigua', '+34600999999', ?, 2, ?, ?, ?, 15, 20, 24, 'nota antigua', ?)",
            [tableId(1), $start, date('Y-m-d H:i:s', strtotime($start) + 7200), $status, $start]
        );
        Db::exec('INSERT INTO reservation_menu_options (reservation_id, option_id) VALUES (?, ?)', [$id, optionId('Sin gluten')]);
        return $id;
    };
    $d89 = $mk(89, 'completed');
    $d91 = $mk(91, 'no_show');
    $d6 = $mk(6, 'completed');
    $d8 = $mk(8, 'completed');
    Db::run('CALL sp_daily_purge(?)', [$today->format('Y-m-d')])->closeCursor();
    $row = fn($id) => Db::one('SELECT guest_name, guest_phone, note FROM reservations WHERE id = ?', [$id]);
    $opts = fn($id) => (int) Db::value('SELECT COUNT(*) FROM reservation_menu_options WHERE reservation_id = ?', [$id]);
    check($row($d91)['guest_name'] === null && $row($d91)['guest_phone'] === null, 'RP-03: la de hace 91 días pierde los datos personales');
    check($row($d89)['guest_name'] !== null, 'RP-03: la de hace 89 días los conserva');
    check($row($d8)['note'] === null && $opts($d8) === 0, 'RP-08: la de hace 8 días pierde opciones y nota');
    check($row($d6)['note'] !== null && $opts($d6) === 1, 'RP-08: la de hace 6 días las conserva');

    // PostgreSQL no tiene eventos: la limpieza diaria la lanza cron con worker.php --diario.
    $phpDir = getenv('ML_PHP_DIR') ?: __DIR__ . '/../php';
    exec(escapeshellarg(PHP_BINARY) . ' -r "exit(extension_loaded(\'pdo_pgsql\') ? 0 : 1);"', $_, $hasExt);
    $cmd = escapeshellarg(PHP_BINARY) . ($hasExt === 0 ? '' : ' -d extension=pdo_pgsql') . ' ' . escapeshellarg("{$phpDir}/bin/worker.php") . ' --diario 2>&1';
    exec($cmd, $out, $exit);
    check($exit === 0 && str_contains(implode("\n", $out), 'limpieza diaria hecha'), 'worker.php --diario ejecuta la limpieza diaria', $out);
});

// ---------------------------------------------------------------------------
// CP-04 · RF-06, RF-07, RF-08, RF-12, RF-13, RP-04
// ---------------------------------------------------------------------------
test('CP-04', 'Disponibilidad, reserva, asignación, opciones y nota', function () {
    $now = at(-3, '12:00');
    $c = newClient($now);
    $avail = $c->get('/availability?date=' . day() . '&party=4');
    check($avail->status === 200 && in_array('21:00', $avail->body['times'], true), 'las 21:00 aparecen para cuatro', $avail->body);
    check(!in_array('22:30', $avail->body['times'], true) && in_array('22:00', $avail->body['times'], true), 'la última cena empieza a las 22:00');

    $note = str_repeat('a', 200);
    $gf = optionId('Sin gluten');
    $r = reserve($c, day(), '21:00', 4, ['menu_options' => [$gf], 'note' => $note, 'consent' => true]);
    check($r->status === 201 && $r->body['reservation']['status'] === 'confirmed', 'la reserva queda confirmada', $r->body);
    $id = $r->body['reservation']['id'] ?? 0;
    $row = Db::one('SELECT r.*, t.max_capacity FROM reservations r JOIN dining_tables t ON t.id = r.table_id WHERE r.id = ?', [$id]);
    check((int) $row['max_capacity'] === 4, 'se asigna una mesa de 4 (la de menor capacidad)', $row['max_capacity'] ?? null);
    check($row['note'] === $note && $row['consent_at'] !== null, 'la nota de 200 caracteres y el consentimiento se guardan');
    check(Db::value('SELECT option_id FROM reservation_menu_options WHERE reservation_id = ?', [$id]) == $gf, 'la opción «sin gluten» se guarda');

    $staff = staffClient($now);
    $agenda = $staff->get('/panel/reservations?date=' . day());
    $seen = array_values(array_filter($agenda->body['reservations'], fn($x) => $x['id'] === $id));
    check($seen && $seen[0]['note'] === $note && $seen[0]['menu_options'] === [$gf], 'el personal ve la nota y las opciones en la agenda');

    $tooLong = reserve($c, day(), '13:00', 2, ['note' => str_repeat('b', 201), 'consent' => true]);
    check($tooLong->status === 422, 'una nota de 201 caracteres se rechaza', $tooLong->body);
    $noConsent = reserve($c, day(), '13:00', 2, ['menu_options' => [optionId('Vegetariano')], 'note' => 'vegetariana', 'consent' => false]);
    $nid = $noConsent->body['reservation']['id'] ?? 0;
    check($noConsent->status === 201 && Db::value('SELECT note FROM reservations WHERE id = ?', [$nid]) === null
        && Db::value('SELECT COUNT(*) FROM reservation_menu_options WHERE reservation_id = ?', [$nid]) == 0,
        'sin consentimiento se guarda sin opciones ni nota (RP-04)');

    // RF-06: con todas las mesas de cuatro ocupadas a las 21:00, no se ofrece para cuatro y sí para dos.
    $others = [];
    for ($i = 0; $i < 8; $i++) {
        $others[] = newClient($now);
    }
    foreach ($others as $o) {
        reserve($o, day(), '21:00', 4);
    }
    $four = $c->get('/availability?date=' . day() . '&party=4')->body['times'];
    $two = $c->get('/availability?date=' . day() . '&party=2')->body['times'];
    check(!in_array('21:00', $four, true), 'sin mesas para cuatro, las 21:00 no aparecen para cuatro', $four);
    check(in_array('21:00', $two, true), 'pero sí para dos', $two);
});

// ---------------------------------------------------------------------------
// CP-05 · RF-09 (CA-09-1 a CA-09-3)
// ---------------------------------------------------------------------------
test('CP-05', 'Reservas consecutivas, solapadas y sobre intervalos liberados', function () {
    $now = at(-3, '12:00');
    $a = newClient($now);
    $b = newClient($now);
    $c = newClient($now);
    // Solo la mesa 8 admite 10 comensales.
    $r1 = reserve($a, day(), '20:00', 10);
    $r2 = reserve($b, day(), '22:00', 10);
    check($r1->status === 201 && $r2->status === 201, 'CA-09-1: 20:00-22:00 y 22:00-00:00 en la misma mesa se aceptan');
    $before = countReservations();
    $r3 = reserve($c, day(), '21:00', 10);
    check($r3->status === 409 && $r3->code() === 'conflict' && countReservations() === $before, 'CA-09-2: una solicitud para las 21:00 se rechaza y no crea reserva', $r3->body);
    $a->post('/reservations/' . $r1->body['reservation']['id'] . '/cancel');
    $b->post('/reservations/' . $r2->body['reservation']['id'] . '/cancel');
    $r4 = reserve($c, day(), '21:00', 10);
    check($r4->status === 201, 'CA-09-3: al cancelarse, el intervalo vuelve a estar disponible', $r4->body);
});

// ---------------------------------------------------------------------------
// CP-06 · RF-09 (CA-09-4): 20 solicitudes simultáneas por la única mesa
// ---------------------------------------------------------------------------
test('CP-06', '20 clientes piden a la vez la única mesa disponible', function () {
    $now = at(-3, '12:00');
    $clients = [];
    for ($i = 0; $i < 20; $i++) {
        $clients[] = newClient($now);
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($clients as $c) {
        $ch = $c->handle('POST', '/reservations', ['request_id' => uuid(), 'date' => day(), 'time' => '13:00', 'party' => 10]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh);
        }
    } while ($running && $st === CURLM_OK);
    $codes = [];
    foreach ($handles as $ch) {
        $codes[] = Client::result($ch, curl_multi_getcontent($ch))->status;
        curl_multi_remove_handle($mh, $ch);
    }
    $created = count(array_filter($codes, fn($s) => $s === 201));
    $conflicts = count(array_filter($codes, fn($s) => $s === 409));
    check($created === 1, 'se confirma exactamente una reserva', array_count_values($codes));
    check($conflicts === 19, 'las otras 19 se rechazan por conflicto');
    $inDb = (int) Db::value("SELECT COUNT(*) FROM reservations WHERE status = 'confirmed' AND table_id = ?", [tableId(8)]);
    check($inDb === 1, 'en la base de datos hay una sola reserva en la mesa');

    // Segunda protección: aunque un fallo del código intentara guardar una
    // reserva solapada (aquí, escribiendo directamente), PostgreSQL la rechaza.
    $overlap = fn(string $from, string $to) => Db::tx(fn() => Db::insert(
        "INSERT INTO reservations (source, guest_name, table_id, party_size, start_at, end_at, status,
                                   arrival_before_min, arrival_after_min, change_deadline_h, created_at)
         VALUES ('local', 'Solapada', ?, 2, ?, ?, 'confirmed', 15, 20, 24, ?)",
        [tableId(8), $from, $to, at(-3, '12:00')]
    ));
    try {
        $overlap(at(0, '14:00'), at(0, '16:00'));
        check(false, 'la base de datos debería rechazar una reserva solapada');
    } catch (PDOException $e) {
        check(Db::isOverlap($e) && countReservations() === 1,
            'la base de datos rechaza por sí sola una reserva solapada en la misma mesa', $e->getCode());
    }
    check($overlap(at(0, '15:00'), at(0, '17:00')) > 0, 'una reserva justo a continuación (15:00) sí entra');
});

// ---------------------------------------------------------------------------
// CP-07 · RF-10
// ---------------------------------------------------------------------------
test('CP-07', 'La misma solicitud enviada tres veces', function () {
    $c = newClient(at(-3, '12:00'));
    $body = ['request_id' => uuid(), 'date' => day(), 'time' => '20:30', 'party' => 3];
    $ids = [];
    $statuses = [];
    for ($i = 0; $i < 3; $i++) {
        $r = $c->post('/reservations', $body);
        $statuses[] = $r->status;
        $ids[] = $r->body['reservation']['id'] ?? null;
    }
    check($statuses === [201, 200, 200], 'la primera crea y las repeticiones responden 200', $statuses);
    check(count(array_unique($ids)) === 1 && $ids[0] !== null, 'las tres respuestas identifican la misma reserva', $ids);
    check(countReservations() === 1, 'hay una sola reserva');
});

// ---------------------------------------------------------------------------
// CP-08 · RF-11, RF-36, RS-08
// ---------------------------------------------------------------------------
test('CP-08', 'Límite por cliente, día cerrado y datos incorrectos', function () {
    $c = newClient(at(-3, '12:00'));
    foreach (['13:00', '13:30', '14:00'] as $t) {
        reserve($c, day(), $t, 2);
    }
    $before = countReservations();
    $fourth = reserve($c, day(), '20:00', 2);
    check($fourth->status === 409 && $fourth->code() === 'limit', 'la cuarta reserva se rechaza', $fourth->body);

    Db::exec('INSERT INTO closed_days (day) VALUES (?)', [day(1)]);
    $d = newClient(at(-3, '12:00'));
    $closed = reserve($d, day(1), '13:00', 2);
    check($closed->status === 422 && $closed->code() === 'closed', 'una reserva en día cerrado se rechaza', $closed->body);
    check($d->get('/availability?date=' . day(1) . '&party=2')->body['times'] === [], 'un día cerrado no ofrece ninguna hora');

    $bad = [
        'comensales = 0'      => ['date' => day(), 'time' => '20:00', 'party' => 0],
        'fecha pasada'        => ['date' => day(-10), 'time' => '20:00', 'party' => 2],
        'hora no permitida'   => ['date' => day(), 'time' => '20:10', 'party' => 2],
        'nota de 5.000'       => ['date' => day(), 'time' => '20:00', 'party' => 2, 'note' => str_repeat('x', 5000), 'consent' => true],
        'opción inexistente'  => ['date' => day(), 'time' => '20:00', 'party' => 2, 'menu_options' => [999], 'consent' => true],
        'inyección en fecha'  => ['date' => "2026-01-01' OR '1'='1", 'time' => '20:00', 'party' => 2],
        'inyección en hora'   => ['date' => day(), 'time' => "20:00; DROP TABLE reservations;--", 'party' => 2],
        'comensales en texto' => ['date' => day(), 'time' => '20:00', 'party' => '2 OR 1=1'],
        'campo desconocido'   => ['date' => day(), 'time' => '20:00', 'party' => 2, 'user_id' => 1],
    ];
    foreach ($bad as $what => $payload) {
        $r = $d->post('/reservations', ['request_id' => uuid()] + $payload);
        check($r->status === 422, "rechaza {$what}", $r->body);
    }
    $inj = client(at(-3, '12:00'))->post('/auth/register', ['name' => "Robert'); DROP TABLE users;--", 'email' => 'x@pruebas.test', 'phone' => '+34600000001', 'password' => 'Prueba-MesaLista-2026']);
    check($inj->status === 422, 'rechaza un nombre con inyección SQL', $inj->body);
    check(countReservations() === $before, 'la base de datos no ha cambiado');
});

// ---------------------------------------------------------------------------
// CP-09 · RF-14, RF-15, RS-03
// ---------------------------------------------------------------------------
test('CP-09', 'Consultar y modificar las propias; no las ajenas', function () {
    $now = at(-3, '12:00');
    $a = newClient($now);
    $b = newClient($now);
    $ra = reserve($a, day(), '20:00', 2)->body['reservation'];
    $rb = reserve($b, day(), '20:00', 2)->body['reservation'];
    $list = $a->get('/reservations')->body['reservations'];
    check(count($list) === 1 && $list[0]['id'] === $ra['id'], 'A solo ve su reserva');

    $mod = $a->patch("/reservations/{$ra['id']}", ['time' => '21:00', 'party' => 3]);
    check($mod->status === 200 && $mod->body['reservation']['time'] === '21:00' && $mod->body['reservation']['party'] === 3, 'la modificación válida se aplica', $mod->body);
    check($mod->body['reservation']['qr'] !== $ra['qr'], 'la reserva modificada tiene un QR nuevo (RF-18)');

    // Sin mesa para 10 a las 21:00: se ocupa la única mesa de 10.
    reserve(newClient($now), day(), '21:00', 10);
    $snapshot = Db::one('SELECT table_id, party_size, start_at, qr_version FROM reservations WHERE id = ?', [$ra['id']]);
    $imp = $a->patch("/reservations/{$ra['id']}", ['party' => 9]);
    check($imp->status === 409 && $imp->code() === 'conflict', 'pedir comensales sin mesa libre devuelve conflicto', $imp->body);
    check(Db::one('SELECT table_id, party_size, start_at, qr_version FROM reservations WHERE id = ?', [$ra['id']]) == $snapshot, 'la reserva conserva hora, mesa y QR');

    $rowB = Db::one('SELECT * FROM reservations WHERE id = ?', [$rb['id']]);
    check($a->get("/reservations/{$rb['id']}")->status === 404, 'A no puede consultar la de B');
    check($a->patch("/reservations/{$rb['id']}", ['time' => '22:00'])->status === 404, 'A no puede modificar la de B');
    check($a->post("/reservations/{$rb['id']}/cancel")->status === 404, 'A no puede cancelar la de B');
    check(Db::one('SELECT * FROM reservations WHERE id = ?', [$rb['id']]) == $rowB, 'la reserva de B no ha cambiado');
});

// ---------------------------------------------------------------------------
// CP-10 · RF-16, RF-31 (CA-16-1 a CA-16-5)
// ---------------------------------------------------------------------------
test('CP-10', 'Cancelaciones del titular y del personal', function () {
    $book = at(-5, '12:00');
    $a = newClient($book);
    $b = newClient($book);
    $r1 = reserve($a, day(), '20:00', 2)->body['reservation']['id'];
    $r2 = reserve($a, day(), '21:00', 2)->body['reservation']['id'];
    $r3 = reserve($b, day(), '20:00', 2)->body['reservation']['id'];

    // Las sesiones de cliente duran 60 minutos: cada salto de hora usa una sesión nueva.
    $login = function (Client $c, string $now) {
        global $PASSWORD;
        $c->at($now)->post('/auth/login', ['email' => $c->email, 'password' => $PASSWORD]);
        return $c;
    };
    $ok = $login($a, at(-1, '14:00'))->post("/reservations/{$r1}/cancel");
    check($ok->status === 200 && status($r1) === 'cancelled', 'CA-16-1: 30 horas antes se cancela', $ok->body);
    check((int) Db::value("SELECT COUNT(*) FROM email_outbox WHERE subject LIKE '%cancelada%'") >= 1 || config('mail.enabled') === true, 'RF-17: se prepara el correo de cancelación');
    $late = $login($a, at(0, '01:00'))->post("/reservations/{$r2}/cancel");
    check($late->status === 409 && $late->code() === 'deadline' && status($r2) === 'confirmed', 'CA-16-2: 20 horas antes se rechaza', $late->body);
    $other = $login($b, at(-1, '14:00'))->post("/reservations/{$r2}/cancel");
    check($other->status === 404 && status($r2) === 'confirmed', 'CA-16-3: otra cuenta no puede cancelarla');
    $again = $login($a, at(-1, '14:05'))->post("/reservations/{$r1}/cancel");
    check($again->status === 200 && status($r1) === 'cancelled', 'CA-16-5: repetir la cancelación responde con éxito y no cambia nada');

    $byStaff = staffAt(at(0, '19:30'))->post("/panel/reservations/{$r3}/cancel");
    check($byStaff->status === 200 && status($r3) === 'cancelled', 'RF-31: el personal cancela 30 minutos antes', $byStaff->body);
    $notes = $login($b, at(0, '19:31'))->get('/me/notifications')->body['notifications'] ?? [];
    check(count($notes) === 1, 'RF-32: el titular ve el aviso al abrir la app', $notes);

    staffAt(at(0, '20:50'))->post("/panel/reservations/{$r2}/arrive");
    $seated = $login($a, at(0, '20:55'))->post("/reservations/{$r2}/cancel");
    check($seated->status === 409, 'CA-16-4: una reserva en curso no se cancela', $seated->body);
});

// ---------------------------------------------------------------------------
// CP-11 · RF-17 · correo por Azure Communication Services (servidor falso
// azure-stub.php, que comprueba la firma como Azure)
// ---------------------------------------------------------------------------
test('CP-11', 'Correo por Azure Communication Services', function () {
    $port = 18099;
    $key = base64_encode('clave-de-pruebas-de-mesalista');
    $log = (string) tempnam(sys_get_temp_dir(), 'ml-azure');
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/azure-stub.php'],
        [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes, null,
        array_merge(getenv(), ['ML_AZURE_KEY' => $key, 'ML_AZURE_LOG' => $log]));
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    $saved = $GLOBALS['ML_CONFIG']['mail'];
    $use = function (string $connection): void {
        $GLOBALS['ML_CONFIG']['mail'] = ['enabled' => true, 'connection_string' => $connection,
            'sender' => 'DoNotReply@pruebas.azurecomm.net'];
    };
    $outbox = fn() => Db::all('SELECT to_email, attempts, claim, last_error FROM email_outbox ORDER BY id');
    try {
        $use('');
        Mail::queue('ana@pruebas.test', 'Reserva confirmada', "Hola, Ana:\n\nTu reserva está confirmada.\n");
        $sent = Mail::flush(50);
        $rows = $outbox();
        check($sent === 0 && count($rows) === 1 && (int) $rows[0]['attempts'] === 0,
            'sin cadena de conexión no se envía nada y el correo espera en la cola', $rows);

        $use("endpoint=http://127.0.0.1:{$port}/;accesskey=" . base64_encode('otra-clave'));
        $sent = Mail::flush(50);
        $rows = $outbox();
        check($sent === 0 && (int) $rows[0]['attempts'] === 1 && str_contains((string) $rows[0]['last_error'], 'Azure HTTP 401'),
            'con una clave equivocada Azure responde 401 y se gasta un intento', $rows);

        $use("endpoint=http://127.0.0.1:{$port}/;accesskey={$key}");
        $sent = Mail::flush(50);
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        $msg = json_decode((string) end($lines), true);
        check($sent === 1 && $outbox() === [], 'con la clave buena Azure lo acepta (202) y sale de la cola');
        check(($msg['senderAddress'] ?? '') === 'DoNotReply@pruebas.azurecomm.net'
            && ($msg['recipients']['to'][0]['address'] ?? '') === 'ana@pruebas.test'
            && ($msg['content']['subject'] ?? '') === 'MesaLista · Reserva confirmada'
            && str_contains($msg['content']['plainText'] ?? '', 'Tu reserva está confirmada.')
            && ($msg['userEngagementTrackingDisabled'] ?? false) === true,
            'el mensaje lleva remitente, destinatario, asunto y texto en UTF-8, sin seguimiento', $msg);

        for ($i = 1; $i <= 7; $i++) {
            Mail::queue("cliente{$i}@pruebas.test", 'Prueba', 'Texto');
        }
        $sent = Mail::flush(50);
        check($sent === 5 && count($outbox()) === 2, 'cada vuelta envía como mucho 5 (límite del dominio de Azure)', $sent);
        Db::exec('DELETE FROM email_outbox');

        Mail::queue('limite@pruebas.test', 'Prueba', 'Texto');
        Mail::queue('luis@pruebas.test', 'Prueba', 'Texto');
        $sent = Mail::flush(50);
        $rows = $outbox();
        check($sent === 0 && count($rows) === 2 && (int) $rows[0]['attempts'] === 0 && (int) $rows[1]['attempts'] === 0
            && $rows[0]['claim'] === null && $rows[1]['claim'] === null && str_contains((string) $rows[0]['last_error'], '429'),
            'si Azure pide esperar (429), el lote vuelve a la cola sin gastar intentos', $rows);
        Db::exec('DELETE FROM email_outbox');

        Mail::queue('rechazo@pruebas.test', 'Prueba', 'Texto');
        Mail::flush(50);
        $rows = $outbox();
        check((int) $rows[0]['attempts'] === 1 && str_contains((string) $rows[0]['last_error'], 'Azure HTTP 400 InvalidRecipient'),
            'un destinatario rechazado gasta un intento y guarda el código de Azure', $rows);
        Db::exec('DELETE FROM email_outbox');

        $use("endpoint=http://ejemplo.com/;accesskey={$key}");
        Mail::queue('ana@pruebas.test', 'Prueba', 'Texto');
        Mail::flush(50);
        $rows = $outbox();
        check((int) $rows[0]['attempts'] === 1 && str_contains((string) $rows[0]['last_error'], 'no es una cadena de conexión')
            && !str_contains((string) $rows[0]['last_error'], $key),
            'sin HTTPS no se envía (RS-01) y el error no muestra la clave', $rows);
    } finally {
        $GLOBALS['ML_CONFIG']['mail'] = $saved;
        proc_terminate($stub);
        proc_close($stub);
        @unlink($log);
    }
});

// ---------------------------------------------------------------------------
// CP-12 · RF-18, RF-19, RF-20, RS-07 (CA-19-1 a CA-19-5)
// ---------------------------------------------------------------------------
test('CP-12', 'Validación del QR', function () {
    $book = at(-3, '12:00');
    $c = newClient($book);
    $r = reserve($c, day(), '21:00', 2)->body['reservation'];
    $late = reserve(newClient($book), day(), '21:00', 2)->body['reservation'];
    $d = newClient($book);
    $cancelled = reserve($d, day(), '20:00', 2)->body['reservation'];
    $d->post("/reservations/{$cancelled['id']}/cancel");
    $m = reserve($d, day(), '20:30', 2)->body['reservation'];
    $d->patch("/reservations/{$m['id']}", ['party' => 3]);

    $rc = staffAt(at(0, '20:00'))->post('/panel/arrive-qr', ['qr' => $cancelled['qr']]);
    check($rc->status === 409 && $rc->code() === 'cancelled', 'CA-19-4: el QR de una reserva cancelada se rechaza', $rc->body);
    $old = staffAt(at(0, '20:30'))->post('/panel/arrive-qr', ['qr' => $m['qr']]);
    check($old->status === 409 && $old->code() === 'qr_outdated', 'CA-19-4: el QR anterior a una modificación se rechaza', $old->body);

    $staff = staffAt(at(0, '20:40'));
    $early = $staff->post('/panel/arrive-qr', ['qr' => $r['qr']]);
    check($early->status === 409 && $early->code() === 'too_early' && status($r['id']) === 'confirmed', 'CA-19-2: a las 20:40 se rechaza por llegada anticipada', $early->body);
    $ok = $staff->at(at(0, '20:50'))->post('/panel/arrive-qr', ['qr' => $r['qr']]);
    check($ok->status === 200 && status($r['id']) === 'seated', 'CA-19-1: a las 20:50 se acepta y pasa a «en curso»', $ok->body);
    $twice = $staff->at(at(0, '20:51'))->post('/panel/arrive-qr', ['qr' => $r['qr']]);
    check($twice->status === 409 && $twice->code() === 'already_seated' && status($r['id']) === 'seated', 'RF-20: un segundo escaneo se rechaza', $twice->body);

    $qr = $m['qr'];
    $tampered = substr($qr, 0, -1) . (substr($qr, -1) === 'A' ? 'B' : 'A');
    $alt = $staff->post('/panel/arrive-qr', ['qr' => $tampered]);
    check($alt->status === 422 && $alt->code() === 'qr_invalid', 'CA-19-5: un QR con un carácter alterado se rechaza', $alt->body);
    $forged = preg_replace('/^ML1\.\d+\./', "ML1.{$late['id']}.", $qr);
    $fake = $staff->post('/panel/arrive-qr', ['qr' => $forged]);
    check($fake->status === 422, 'RS-07: un QR fabricado con el id de otra reserva se rechaza', $fake->body);

    $tooLate = staffAt(at(0, '21:25'))->post('/panel/arrive-qr', ['qr' => $late['qr']]);
    check($tooLate->status === 409 && status($late['id']) === 'no_show', 'CA-19-3: a las 21:25 se rechaza: ya está «no presentada»', $tooLate->body);
});

// ---------------------------------------------------------------------------
// CP-13 · RF-21, RF-23, RF-26, RF-32, RF-35
// ---------------------------------------------------------------------------
test('CP-13', 'Mesa ocupada, más comensales, mesas desactivadas y llegada anticipada', function () {
    $book = at(-3, '12:00');

    // RF-21 con otra mesa libre
    $c = newClient($book);
    $r = reserve($c, day(), '21:00', 2)->body['reservation'];
    $big = reserve(newClient($book), day(), '21:00', 10)->body['reservation'];
    $assigned = (int) Db::value('SELECT table_id FROM reservations WHERE id = ?', [$r['id']]);
    occupy((string) Db::value('SELECT code_live FROM dining_tables WHERE id = ?', [$assigned]), at(0, '19:00'));
    $staff = staffAt(at(0, '20:50'));
    $arr = $staff->post('/panel/arrive-qr', ['qr' => $r['qr']]);
    check($arr->status === 200 && (int) Db::value('SELECT table_id FROM reservations WHERE id = ?', [$r['id']]) !== $assigned,
        'RF-21: con la mesa asignada ocupada, la llegada se registra en otra libre', $arr->body);

    // RF-21 sin ninguna mesa libre: solo la mesa 8 admite 10.
    occupy(8, at(0, '19:00'));
    $none = $staff->at(at(0, '20:55'))->post('/panel/arrive-qr', ['qr' => $big['qr']]);
    check($none->status === 409 && $none->code() === 'no_table' && ($none->body['error']['alert'] ?? false) === true,
        'RF-21: sin ninguna libre, el panel recibe el aviso', $none->body);

    // RF-26
    $s13 = staffAt(at(0, '13:00'));
    $walk = $s13->post('/panel/walkin', ['party' => 4, 'table_id' => tableId(6)])->body['reservation'];
    check($s13->post("/panel/reservations/{$walk['id']}/party", ['party' => 6])->status === 200, 'RF-26: en una mesa de 6 con 4 se puede pasar a 6');
    $over = $s13->post("/panel/reservations/{$walk['id']}/party", ['party' => 7]);
    check($over->status === 409, 'pasar a 7 se rechaza', $over->body);

    // RF-32 y RF-35: desactivar una mesa con reservas futuras (antes se liberan las mesas en curso).
    Db::exec("UPDATE reservations SET status = 'completed' WHERE status = 'seated'");
    $admin = staffClient($book, 'admin');
    $holder = newClient($book);
    $f1 = reserve($holder, day(1), '20:00', 6)->body['reservation'];
    $t1 = (int) Db::value('SELECT table_id FROM reservations WHERE id = ?', [$f1['id']]);
    $res1 = $admin->patch('/admin/tables/' . $t1, ['active' => false]);
    check($res1->status === 200 && $res1->body['moved'] === 1 && status($f1['id']) === 'confirmed'
        && (int) Db::value('SELECT table_id FROM reservations WHERE id = ?', [$f1['id']]) !== $t1,
        'con otra mesa adecuada libre, la reserva pasa a ella', $res1->body);
    $f2 = reserve($holder, day(1), '20:00', 9)->body['reservation'];
    // También se cancela la reserva de 10 de hoy que no encontró mesa (RF-21): solo cabía en la 8.
    $res2 = $admin->patch('/admin/tables/' . tableId(8), ['active' => false]);
    check($res2->body['cancelled'] === 2 && status($f2['id']) === 'cancelled' && status($big['id']) === 'cancelled',
        'sin mesa adecuada, se cancelan', $res2->body);
    $notes = $holder->at($book)->get('/me/notifications')->body['notifications'];
    check(count($notes) === 1, 'RF-32: el titular ve el aviso al abrir la app', $notes);
    $avail = $holder->get('/availability?date=' . day(1) . '&party=9')->body['times'];
    check($avail === [], 'la mesa desactivada deja de ofrecerse', $avail);

    // RF-23: llegada anticipada 30 minutos antes
    $e = newClient($book);
    $er = reserve($e, day(2), '21:00', 2)->body['reservation'];
    $e2 = reserve(newClient($book), day(2), '21:00', 2)->body['reservation'];
    $s2030 = staffAt(at(2, '20:30'));
    $early = $s2030->post('/panel/arrive-qr', ['qr' => $er['qr'], 'early' => true]);
    check($early->status === 200 && $early->body['reservation']['end_time'] === '23:00', 'con la mesa libre se registra y sigue terminando a las 23:00', $early->body);
    occupy((string) Db::value('SELECT code_live FROM dining_tables WHERE id = (SELECT table_id FROM reservations WHERE id = ?)', [$e2['id']]), at(2, '19:00'));
    $busy = $s2030->post('/panel/arrive-qr', ['qr' => $e2['qr'], 'early' => true]);
    check($busy->status === 409 && status($e2['id']) === 'confirmed', 'con la mesa ocupada, se rechaza', $busy->body);
});

// ---------------------------------------------------------------------------
// CP-14 · RF-22, RF-24, RF-25
// ---------------------------------------------------------------------------
test('CP-14', 'Búsqueda, llegada sin QR, no presentadas y fin de la reserva', function () {
    $book = at(-3, '12:00');
    $c = newClient($book, '+34 611 222 333');
    $r = reserve($c, day(), '21:00', 2)->body['reservation'];
    $staff = staffClient(at(0, '20:55'));
    $found = $staff->get('/panel/reservations?date=' . day() . '&q=611222333')->body['reservations'];
    check(count($found) === 1 && $found[0]['id'] === $r['id'], 'la búsqueda por teléfono encuentra la reserva', $found);
    $nuria = Db::insert(
        "INSERT INTO reservations (source, guest_name, guest_phone, code, table_id, party_size, start_at, end_at, status,
                                   arrival_before_min, arrival_after_min, change_deadline_h, created_at)
         VALUES ('local', 'Núria Ollé', '+34622000111', 'ABCDEFGHJK', ?, 2, ?, ?, 'confirmed', 15, 20, 24, ?)",
        [tableId(2), at(0, '13:00'), at(0, '15:00'), $book]
    );
    $hit = $staff->get('/panel/reservations?date=' . day() . '&q=' . rawurlencode('NURIA olle'))->body['reservations'] ?? [];
    check(count($hit) === 1 && $hit[0]['id'] === $nuria, 'la búsqueda no distingue mayúsculas ni acentos («NURIA olle» encuentra «Núria Ollé»)', $hit);
    $arr = $staff->post("/panel/reservations/{$r['id']}/arrive", ['early' => false]);
    check($arr->status === 200 && status($r['id']) === 'seated', 'la llegada sin QR la pasa a «en curso»', $arr->body);

    $x = reserve(newClient($book), day(), '21:00', 4)->body['reservation'];
    $staff->at(at(0, '21:20'))->get('/panel/version');
    check(status($x['id']) === 'confirmed', 'a las 21:20 todavía no está «no presentada»');
    $staff->at(at(0, '21:21'))->get('/panel/version');
    check(status($x['id']) === 'no_show', 'a las 21:21 está «no presentada»');
    $walk = $staff->post('/panel/walkin', ['party' => 4, 'table_id' => (int) Db::value('SELECT table_id FROM reservations WHERE id = ?', [$x['id']])]);
    check($walk->status === 201, 'y su mesa vuelve a estar disponible', $walk->body);

    $later = staffAt(at(0, '22:40'));
    $done = $later->post("/panel/reservations/{$r['id']}/complete");
    check($done->status === 200 && status($r['id']) === 'completed', 'el personal la da por terminada: «completada»', $done->body);
    $room = $later->get('/panel/room')->body['tables'];
    $t = array_values(array_filter($room, fn($x) => $x['code'] === $r['table']))[0];
    check($t['state'] === 'free', 'la mesa vuelve a estar libre en sala');
});

// ---------------------------------------------------------------------------
// CP-15 · RF-27, RF-28, RF-33, RF-34
// ---------------------------------------------------------------------------
test('CP-15', 'Reserva presencial, cliente sin reserva y estado en sala', function () {
    $staff = staffClient(at(0, '12:00'));
    $local = $staff->post('/panel/reservations', ['name' => 'Joan Pons', 'phone' => '600 333 444', 'date' => day(), 'time' => '21:00', 'party' => 4]);
    check($local->status === 201 && $local->body['reservation']['status'] === 'confirmed', 'la reserva presencial queda confirmada', $local->body);
    check(Db::value('SELECT user_id FROM reservations WHERE id = ?', [$local->body['reservation']['id']]) === null, 'no pertenece a ninguna cuenta');
    check(strlen($local->body['ticket']['code']) === 10 && str_starts_with($local->body['ticket']['qr'], 'ML1.'), 'genera ticket con código y QR');

    $staff = staffAt(at(0, '13:05'));
    $walk = $staff->post('/panel/walkin', ['party' => 3]);
    check($walk->status === 201 && $walk->body['reservation']['status'] === 'seated', 'el cliente sin reserva nace «en curso»', $walk->body);
    $tableNum = $walk->body['reservation']['table'];
    $room = $staff->get('/panel/room')->body['tables'];
    $t = array_values(array_filter($room, fn($x) => $x['code'] === $tableNum))[0];
    check($t['state'] === 'occupied', 'su mesa aparece ocupada en sala');

    $other = staffClient(at(0, '13:05'));
    $v1 = $other->get('/panel/version', ['X-Background: 1'])->body['version'];
    $staff->post("/panel/reservations/{$walk->body['reservation']['id']}/complete");
    $v2 = $other->get('/panel/version', ['X-Background: 1'])->body['version'];
    check($v1 !== $v2, 'RF-34: el otro panel detecta el cambio sin recargar');
});

// ---------------------------------------------------------------------------
// CP-16 · RF-29, RF-30, RS-12, RS-13, RP-07
// ---------------------------------------------------------------------------
test('CP-16', 'Ticket, vinculación y códigos de reserva', function () {
    $now = at(-3, '12:00');
    $staff = staffClient($now);
    $l1 = $staff->post('/panel/reservations', ['name' => 'Joan Pons', 'phone' => '600 333 444', 'date' => day(), 'time' => '21:00', 'party' => 2])->body;
    $l2 = $staff->post('/panel/reservations', ['name' => 'Ana Ruiz', 'phone' => '600 555 666', 'date' => day(), 'time' => '20:00', 'party' => 2])->body;
    $ticket = $l1['ticket'];
    check(array_keys($ticket) === ['date', 'time', 'party', 'code', 'qr'], 'RP-07: el ticket solo lleva fecha, hora, comensales, código y QR', array_keys($ticket));

    $joan = newClient($now);
    $link = $joan->post('/reservations/link', ['code' => strtolower($ticket['code']), 'phone' => '+34600333444']);
    check($link->status === 200 && $link->body['reservation']['id'] === $l1['reservation']['id'], 'con código y teléfono correctos se vincula', $link->body);
    check(count($joan->get('/reservations')->body['reservations']) === 1, 'aparece entre sus reservas');
    $mod = $joan->patch('/reservations/' . $l1['reservation']['id'], ['party' => 3]);
    check($mod->status === 200, 'y puede modificarla', $mod->body);
    $again = newClient($now)->post('/reservations/link', ['code' => $ticket['code'], 'phone' => '600333444']);
    check($again->status === 404, 'otra cuenta no puede vincularla de nuevo', $again->body);

    $thief = newClient($now);
    for ($i = 0; $i < 5; $i++) {
        $thief->post('/reservations/link', ['code' => $l2['ticket']['code'], 'phone' => '699 000 00' . $i]);
    }
    $sixth = $thief->post('/reservations/link', ['code' => $l2['ticket']['code'], 'phone' => '600 555 666']);
    check($sixth->status === 429, 'RS-13: el sexto intento se rechaza aunque los datos sean correctos', $sixth->body);

    $okTicket = staffAt(at(0, '19:50'))->post('/panel/arrive-qr', ['qr' => $l2['ticket']['qr']]);
    check($okTicket->status === 200, 'el QR del ticket de una reserva sin vincular se acepta', $okTicket->body);
    $oldTicket = staffAt(at(0, '20:50'))->post('/panel/arrive-qr', ['qr' => $ticket['qr']]);
    check($oldTicket->status === 409 && $oldTicket->code() === 'qr_outdated', 'tras vincularla, el QR del ticket se rechaza', $oldTicket->body);

    $codes = [];
    for ($i = 0; $i < 10000; $i++) {
        $codes[] = Qr::newCode();
    }
    check(count(array_unique($codes)) === 10000, 'RS-12: 10.000 códigos sin repetir');
    check(count(preg_grep('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{10}$/', $codes)) === 10000, 'todos con 10 caracteres sin 0/O ni 1/I');
    $sorted = $codes;
    sort($sorted);
    check($sorted !== $codes, 'no siguen ningún orden');
});

// ---------------------------------------------------------------------------
// CP-17 · RF-35 a RF-38, RS-04
// ---------------------------------------------------------------------------
test('CP-17', 'Operaciones de administración y roles', function () {
    $now = at(-3, '12:00');
    $admin = staffClient($now, 'admin');
    $staff = staffClient($now);
    $client = newClient($now);
    $nobody = client($now);

    $ops = [
        ['GET', '/admin/tables', null],
        ['POST', '/admin/tables', ['number' => 99, 'zone' => 'Barra', 'min_capacity' => 1, 'max_capacity' => 2]],
        ['PUT', '/admin/settings', ['max_future_per_client' => 4]],
        ['POST', '/admin/staff', ['name' => 'Nuevo Empleado', 'email' => 'nuevo@pruebas.test', 'password' => 'Prueba-MesaLista-2026', 'role' => 'staff']],
    ];
    foreach ($ops as [$m, $p, $b]) {
        check($nobody->req($m, $p, $b)->status === 401, "sin sesión: {$m} {$p} se rechaza");
        check($client->req($m, $p, $b)->status === 401, "cliente: {$m} {$p} se rechaza");
        check($staff->req($m, $p, $b)->status === 403, "personal: {$m} {$p} se rechaza");
        $r = $admin->req($m, $p, $b);
        check(in_array($r->status, [200, 201], true), "administrador: {$m} {$p} se acepta", $r->body);
    }
    check($client->get('/panel/reservations')->status === 401, 'un cliente no accede a la agenda del personal');

    // RF-37: los cambios de duración solo afectan a las reservas nuevas.
    $c = newClient($now);
    $before = reserve($c, day(), '20:00', 2)->body['reservation'];
    $admin->put('/admin/settings', ['duration_min' => 90]);
    $after = reserve($c, day(), '13:00', 2)->body['reservation'];
    check($before['end_time'] === '22:00', 'la reserva existente conserva su fin');
    check($after['end_time'] === '14:30', 'la nueva dura 90 minutos', $after);

    // RF-36
    $admin->put('/admin/schedule/slots', ['slots' => [['weekday' => 6, 'open' => '20:00', 'close' => '00:00']]]);
    $times = $c->get('/availability?date=' . day() . '&party=2')->body['times'];
    check(!in_array('13:00', $times, true) && in_array('22:30', $times, true), 'las franjas nuevas se aplican', $times);

    // RF-38
    $emp = Db::value("SELECT id FROM users WHERE email = 'nuevo@pruebas.test'");
    $admin->patch("/admin/staff/{$emp}", ['active' => false]);
    $login = client($now)->post('/panel/login', ['email' => 'nuevo@pruebas.test', 'password' => 'Prueba-MesaLista-2026']);
    check($login->status === 401, 'una cuenta de personal desactivada no inicia sesión');
});

// ---------------------------------------------------------------------------
// CP-18 · Códigos de mesa, mesas extra, eliminar mesas y «Cerrar local» con PIN
// ---------------------------------------------------------------------------
test('CP-18', 'Códigos de mesa, mesas extra, eliminar y cerrar local', function () {
    $book = at(-3, '12:00');
    $admin = staffClient($book, 'admin');
    $staff = staffClient($book);

    $codes = array_column($admin->get('/admin/tables')->body['tables'], 'code');
    check($codes === ['S-1', 'S-2', 'S-3', 'S-4', 'S-5', 'S-6', 'S-7', 'S-8', 'T-1', 'T-2', 'T-3', 'T-4', 'E-1', 'E-2'],
        'las mesas se nombran por zona: S-1…S-8, T-1…T-4 y las extra E-1, E-2', $codes);
    check($admin->post('/admin/tables', ['number' => 1, 'zone' => 'Salón', 'min_capacity' => 1, 'max_capacity' => 2])->code() === 'duplicate',
        'no se crea otra S-1 (misma inicial de zona y número)');

    // Las extra nunca se ofrecen solas; sí para unir.
    $c = newClient($book);
    foreach ([1, 2, 3, 4] as $i) {
        reserve(newClient($book), day(), '21:00', 2);   // S-1, S-2, T-1 y S-3 ocupadas a las 21:00
    }
    $all = Db::all("SELECT code_live FROM dining_tables d JOIN reservations r ON r.table_id = d.id WHERE r.status = 'confirmed'");
    check(!in_array('E-1', array_column($all, 'code_live'), true) && !in_array('E-2', array_column($all, 'code_live'), true),
        'una reserva de la app nunca cae en una mesa extra', $all);
    $s21 = staffAt(at(0, '21:00'));
    $wt = array_column($s21->get('/panel/walkin-tables?party=2')->body['tables'], 'fits', 'code');
    check(($wt['E-1'] ?? null) === false, 'para un cliente sin reserva, una extra aparece pero no «basta sola»', $wt);
    check($s21->post('/panel/walkin', ['party' => 2, 'table_ids' => [tableId('E-1')]])->code() === 'join_only', 'no se sienta a nadie solo en una extra');
    $w = $s21->post('/panel/walkin', ['party' => 12, 'table_ids' => [tableId('E-1'), tableId('S-8')]]);
    check($w->status === 201 && $w->body['reservation']['tables'] === ['S-8', 'E-1'], 'S-8 con la extra E-1: la principal es S-8', $w->body);
    $room = array_column($s21->get('/panel/room')->body['tables'], null, 'code');
    check($room['E-1']['joined_to'] === 'S-8' && $room['S-8']['joined_to'] === null && $room['E-1']['join_only'] === true,
        'en sala, E-1 figura unida a S-8 (el panel muestra una sola ficha)', [$room['E-1'], $room['S-8']]);
    $r = reserve($c, day(1), '21:00', 2)->body['reservation'];
    check($staff->post("/panel/reservations/{$r['id']}/move", ['table_id' => tableId('E-2')])->code() === 'join_only', 'una extra no puede ser la mesa principal');

    // Eliminar: con reservas pendientes no; sin uso se borra; con historial se oculta y su código queda libre.
    check($admin->delete('/admin/tables/' . tableId($r['table']))->code() === 'table_in_use', 'no se elimina una mesa con reservas pendientes');
    $new = $admin->post('/admin/tables', ['number' => 1, 'zone' => 'Barra', 'min_capacity' => 1, 'max_capacity' => 2]);
    check($new->status === 201 && in_array('B-1', array_column($new->body['tables'], 'code'), true), 'se añade la mesa B-1 en una zona nueva');
    $del = $admin->delete('/admin/tables/' . tableId('B-1'));
    check($del->status === 200 && $del->body['deleted'] === 'B-1' && (int) Db::value("SELECT COUNT(*) FROM dining_tables WHERE zone = 'Barra'") === 0,
        'una mesa sin reservas se borra del todo');
    $old = occupy('T-4', at(-5, '13:00'));
    Db::exec("UPDATE reservations SET status = 'completed' WHERE id = ?", [$old]);
    $t4 = tableId('T-4');
    check($admin->delete("/admin/tables/{$t4}")->status === 200 && Db::value('SELECT deleted_at FROM dining_tables WHERE id = ?', [$t4]) !== null,
        'una mesa con reservas antiguas queda eliminada pero conserva el historial');
    check(!in_array('T-4', array_column($staff->get('/panel/room')->body['tables'], 'code'), true), 'y ya no aparece en sala');
    check($admin->post('/admin/tables', ['number' => 4, 'zone' => 'Terraza', 'min_capacity' => 2, 'max_capacity' => 4])->status === 201, 'su código T-4 se puede volver a usar');
    check($staff->delete('/admin/tables/' . tableId('T-3'))->status === 403, 'el personal no elimina mesas');

    // Cerrar local con PIN.
    $s22 = staffAt(at(0, '22:00'));
    check($s22->post('/panel/close', ['pin' => '2580'])->code() === 'no_pin', 'sin PIN fijado, «Cerrar local» no funciona');
    check($s22->put('/admin/close-pin', ['pin' => '2580'])->status === 403, 'el personal no fija el PIN');
    check($admin->put('/admin/close-pin', ['pin' => '12'])->status === 422, 'el PIN tiene de 4 a 8 cifras');
    $set = $admin->put('/admin/close-pin', ['pin' => '2580']);
    check($set->status === 200 && $set->body['close_pin_set'] === true && Db::value("SELECT value FROM secrets WHERE name = 'close_pin'") !== '2580',
        'el administrador fija el PIN (se guarda cifrado)');
    check($s22->post('/panel/close', ['pin' => '0000'])->code() === 'bad_pin', 'un PIN incorrecto no cierra');
    $seated = (int) Db::value("SELECT COUNT(*) FROM reservations WHERE status = 'seated'");
    $close = $s22->post('/panel/close', ['pin' => '2580']);
    check($close->status === 200 && $close->body['completed'] === $seated && $seated > 0, "con el PIN se terminan las {$seated} reservas en curso", $close->body);
    $busyNow = array_filter($s22->get('/panel/room')->body['tables'], fn($t) => $t['state'] === 'occupied');
    check($busyNow === [], 'y todas las mesas quedan libres');
    check(status($r['id']) === 'confirmed', 'las reservas que aún no han llegado no se tocan');
    for ($i = 0; $i < 4; $i++) {
        $s22->post('/panel/close', ['pin' => '9999']);
    }
    check($s22->post('/panel/close', ['pin' => '2580'])->code() === 'locked', 'tras 5 PIN incorrectos se bloquea 15 minutos');
});

// ---------------------------------------------------------------------------
// CP-19 · Cambiar, unir y separar mesas (panel del personal)
// ---------------------------------------------------------------------------
test('CP-19', 'Cambiar, unir y separar mesas', function () {
    $book = at(-3, '12:00');
    $ids = fn(array $numbers) => array_map('tableId', $numbers);

    // Grupo de 12 sin reserva en tres mesas de 4 (3, 4 y 5).
    $s13 = staffAt(at(0, '13:00'));
    $w = $s13->post('/panel/walkin', ['party' => 12, 'table_ids' => $ids([3, 4, 5])]);
    $r = $w->body['reservation'] ?? [];
    check($w->status === 201 && ($r['tables'] ?? []) === ['S-3', 'S-4', 'S-5'] && $r['max_capacity'] === 12,
        'un grupo de 12 se sienta en tres mesas de 4 unidas', $w->body);
    $room = $s13->get('/panel/room')->body['tables'];
    $occ = array_values(array_filter($room, fn($t) => in_array($t['code'], ['S-3', 'S-4', 'S-5'], true) && $t['state'] === 'occupied'
        && $t['current']['id'] === $r['id']));
    check(count($occ) === 3, 'las tres mesas aparecen ocupadas por la misma reserva');
    $short = $s13->post('/panel/walkin', ['party' => 12, 'table_ids' => $ids([10, 11])]);
    check($short->status === 409 && $short->code() === 'over_capacity', 'mesas que no suman plazas suficientes se rechazan', $short->body);

    // Las mesas unidas no se ofrecen a nadie mientras dura la reserva.
    $c = newClient(at(0, '13:00'));
    $avail = $c->get('/availability?date=' . day() . '&party=4')->body['times'];
    $taken = 0;
    for ($i = 0; $i < 6; $i++) {
        $res = reserve(newClient(at(0, '13:00')), day(), '14:00', 4);
        if ($res->status === 201) {
            $taken++;
            check(!in_array($res->body['reservation']['table'], ['S-3', 'S-4', 'S-5'], true), "la reserva {$i} no cae en una mesa unida", $res->body['reservation']['table']);
        }
    }
    check($taken === 4 && in_array('14:00', $avail, true), 'a las 14:00 solo quedan las 4 mesas para cuatro que no están unidas', $taken);

    // Más comensales: hasta la capacidad de todas sus mesas; uniendo otra, más.
    $over = $s13->post("/panel/reservations/{$r['id']}/party", ['party' => 13]);
    check($over->status === 409 && $over->code() === 'over_capacity', 'pasar a 13 con 12 plazas se rechaza', $over->body);
    $free = $s13->get("/panel/reservations/{$r['id']}/free-tables")->body['tables'] ?? [];
    check(in_array('T-1', array_column($free, 'code'), true) && !in_array('S-3', array_column($free, 'code'), true),
        'las mesas libres para unir no incluyen las suyas', array_column($free, 'code'));
    $join = $s13->post("/panel/reservations/{$r['id']}/join", ['table_ids' => [tableId(9)]]);
    check($join->status === 200 && $join->body['reservation']['tables'] === ['S-3', 'S-4', 'S-5', 'T-1'], 'se une la mesa T-1', $join->body);
    check($s13->post("/panel/reservations/{$r['id']}/party", ['party' => 13])->status === 200, 'con la mesa 9 ya caben 13');
    $sep = $s13->post("/panel/reservations/{$r['id']}/separate", ['table_id' => tableId(9)]);
    check($sep->status === 409 && $sep->code() === 'over_capacity', 'no se separa una mesa si sin ella no caben todos', $sep->body);
    $busyJoin = $s13->post("/panel/reservations/{$r['id']}/join", ['table_ids' => [tableId(9)]]);
    check($busyJoin->status === 409, 'una mesa que ya es suya no se vuelve a unir', $busyJoin->body);

    // Cambiar de mesa una reserva confirmada.
    $a = newClient($book);
    $ra = reserve($a, day(), '21:00', 2)->body['reservation'];
    $rb = reserve(newClient($book), day(), '21:00', 2)->body['reservation'];
    $s20 = staffAt(at(0, '20:00'));
    $mv = $s20->post("/panel/reservations/{$ra['id']}/move", ['table_id' => tableId(9)]);
    check($mv->status === 200 && $mv->body['reservation']['table'] === 'T-1', 'una reserva confirmada se mueve a otra mesa libre', $mv->body);
    $clash = $s20->post("/panel/reservations/{$ra['id']}/move", ['table_id' => tableId($rb['table'])]);
    check($clash->status === 409 && $clash->code() === 'table_busy', 'no se mueve a una mesa reservada a esa hora', $clash->body);
    $big = reserve(newClient($book), day(), '21:30', 6)->body['reservation'];
    $small = $s20->post("/panel/reservations/{$big['id']}/move", ['table_id' => tableId(1)]);
    check($small->status === 409 && $small->code() === 'over_capacity', 'no se mueve un grupo de 6 a una mesa de 2', $small->body);
    check(Db::value('SELECT qr_version FROM reservations WHERE id = ?', [$ra['id']]) == 1, 'mover de mesa no cambia el QR del cliente');

    // Unir mesas a una reserva confirmada de 10 (mesa 8) para un grupo que llegará con 12.
    $g = reserve(newClient($book), day(), '21:00', 10)->body['reservation'];
    $jg = $s20->post("/panel/reservations/{$g['id']}/join", ['table_ids' => [tableId(1)]]);
    check($jg->status === 200 && $jg->body['reservation']['max_capacity'] === 12, 'a la reserva de 10 se le une una mesa de 2', $jg->body);
    $deact = staffClient($book, 'admin')->patch('/admin/tables/' . tableId(1), ['active' => false]);
    check($deact->status === 409 && $deact->code() === 'table_joined', 'una mesa unida a una reserva no se puede desactivar', $deact->body);
    $mod = newClient($book);
    $gr = reserve($mod, day(1), '21:00', 2)->body['reservation'];
    staffAt($book)->post("/panel/reservations/{$gr['id']}/join", ['table_ids' => [tableId(2)]]);
    $chg = $mod->patch("/reservations/{$gr['id']}", ['time' => '20:00']);
    check($chg->status === 409 && $chg->code() === 'joined_tables', 'el cliente no cambia la hora de una reserva con mesas unidas', $chg->body);

    // Llegada con una mesa unida todavía ocupada: aviso al personal, sin recolocar.
    occupy(1, at(0, '19:00'));
    $s2050 = staffAt(at(0, '20:50'));
    $arr = $s2050->post('/panel/arrive-qr', ['qr' => $g['qr']]);
    check($arr->status === 409 && $arr->code() === 'no_table' && ($arr->body['error']['alert'] ?? false) === true,
        'si una mesa unida sigue ocupada, el panel recibe el aviso', $arr->body);
    Db::exec("UPDATE reservations SET status = 'completed' WHERE source = 'walkin' AND table_id = ?", [tableId(1)]);
    $ok = $s2050->post('/panel/arrive-qr', ['qr' => $g['qr']]);
    check($ok->status === 200 && $ok->body['reservation']['tables'] === ['S-8', 'S-1'], 'libre la mesa unida, la llegada se registra en las dos', $ok->body);
    $done = $s2050->post("/panel/reservations/{$g['id']}/complete");
    $after = $s2050->get('/panel/room')->body['tables'];
    $stillBusy = array_filter($after, fn($t) => in_array($t['code'], ['S-8', 'S-1'], true) && $t['state'] !== 'free');
    check($done->status === 200 && $stillBusy === [], 'al terminar, todas sus mesas quedan libres');
});

// ---------------------------------------------------------------------------
// CP-20 · Plano de la sala y lista de clientes (administración)
// ---------------------------------------------------------------------------
test('CP-20', 'Plano de la sala y lista de clientes', function () {
    $now = at(-3, '12:00');
    $admin = staffClient($now, 'admin');
    $staff = staffClient($now);

    $layout = ['tables' => [
        ['id' => tableId(1), 'pos_x' => 120, 'pos_y' => 80, 'shape' => 'round'],
        ['id' => tableId(2), 'pos_x' => 900, 'pos_y' => 520, 'shape' => 'rect'],
    ]];
    check($staff->put('/admin/tables/layout', $layout)->status === 403, 'el personal no puede cambiar el plano');
    $ok = $admin->put('/admin/tables/layout', $layout);
    check($ok->status === 200, 'el administrador guarda el plano', $ok->body);
    $room = array_column($staff->get('/panel/room')->body['tables'], null, 'code');
    check($room['S-1']['pos_x'] === 120 && $room['S-1']['pos_y'] === 80 && $room['S-1']['shape'] === 'round'
        && $room['S-2']['shape'] === 'rect', 'la sala devuelve las posiciones y formas guardadas', [$room['S-1'], $room['S-2']]);
    check($room['S-3']['pos_x'] === 330 && $room['S-3']['pos_y'] === 110, 'las mesas de los datos iniciales ya tienen su sitio en el plano', $room['S-3']);
    Db::exec('UPDATE dining_tables SET pos_x = NULL, pos_y = NULL WHERE id = ?', [tableId(3)]);
    $room = array_column($staff->get('/panel/room')->body['tables'], null, 'code');
    check($room['S-3']['pos_x'] === null, 'una mesa sin posición se devuelve sin ella (el panel la coloca)');
    foreach ([
        'fuera del plano' => ['id' => tableId(1), 'pos_x' => 1001, 'pos_y' => 10, 'shape' => 'rect'],
        'forma inexistente' => ['id' => tableId(1), 'pos_x' => 10, 'pos_y' => 10, 'shape' => 'star'],
        'mesa inexistente' => ['id' => 999999, 'pos_x' => 10, 'pos_y' => 10, 'shape' => 'rect'],
    ] as $what => $bad) {
        $r = $admin->put('/admin/tables/layout', ['tables' => [$bad]]);
        check(in_array($r->status, [404, 422], true), "rechaza una mesa {$what}", $r->body);
    }
    check(Db::value('SELECT pos_x FROM dining_tables WHERE id = ?', [tableId(1)]) == 120, 'un plano rechazado no cambia nada');

    $ana = newClient($now, '+34 622 000 111');
    reserve($ana, day(), '20:00', 2);
    reserve($ana, day(), '13:00', 2);
    newClient($now);
    check($staff->get('/admin/clients')->status === 403, 'el personal no ve la lista de clientes');
    check(client($now)->get('/admin/clients')->status === 401, 'sin sesión tampoco');
    $all = $admin->get('/admin/clients')->body['clients'] ?? [];
    check(count($all) === 2, 'la lista tiene a los dos clientes', count($all));
    $found = $admin->get('/admin/clients?q=622000111')->body['clients'] ?? [];
    check(count($found) === 1 && $found[0]['email'] === $ana->email && $found[0]['reservations'] === 2 && $found[0]['upcoming'] === 2,
        'la búsqueda por teléfono encuentra al cliente con sus 2 reservas pendientes', $found);
    check(!array_key_exists('password_hash', $found[0] ?? []), 'la lista no incluye el hash de la contraseña');
});

/** Último correo en cola para $to cuyo asunto contiene $subject (o null). */
function mailTo(string $to, string $subject): ?string
{
    return Db::value('SELECT body FROM email_outbox WHERE to_email = ? AND subject LIKE ? ORDER BY id DESC LIMIT 1', [$to, "%{$subject}%"]);
}

/** GET sin seguir redirecciones: [código, dirección de destino]. Admite la URL completa. */
function redirectOf(Client $c, string $url): array
{
    global $base;
    $path = str_starts_with($url, 'http') ? (string) preg_replace('#^https?://[^/]+/api/v1#', '', $url) : $url;
    $ch = $c->handle('GET', $path);
    curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
}

/** Solicitud de grupo aceptada por el personal con esas mesas: devuelve la reserva (vista del personal). */
function acceptedGroup(Client $c, Client $staff, string $date, string $time, int $party, array $tables): array
{
    $c->post('/group-requests', ['date' => $date, 'time' => $time, 'party' => $party]);
    $gid = (int) Db::value("SELECT MAX(id) FROM group_requests");
    $r = $staff->post("/panel/group-requests/{$gid}/accept", ['table_ids' => array_map('tableId', $tables)]);
    if ($r->status !== 200) {
        throw new RuntimeException('No se pudo aceptar el grupo: ' . json_encode($r->body, JSON_UNESCAPED_UNICODE));
    }
    return $r->body['reservation'];
}

/** El titular paga la señal con la pasarela simulada: devuelve la dirección de la web o de la app a la que vuelve. */
function payDeposit(Client $c, int $id, ?string $returnAt = null, string $from = 'web'): string
{
    $url = $c->post("/reservations/{$id}/pay", ['from' => $from])->body['approve_url'] ?? '';
    if ($returnAt !== null) {
        $c->at($returnAt);
    }
    [, $toReturn] = redirectOf($c, $url);
    [, $toApp] = redirectOf($c, $toReturn);
    return $toApp;
}

// ---------------------------------------------------------------------------
// CP-21 · RF-40 Recuperar la contraseña
// ---------------------------------------------------------------------------
test('CP-21', 'Recuperar la contraseña', function () {
    global $PASSWORD;
    $now = at(-3, '12:00');
    $c = newClient($now);
    $anon = client($now);
    $staff = staffClient($now);
    $staffEmail = (string) Db::value("SELECT email FROM users WHERE role = 'staff' LIMIT 1");

    $a = $anon->post('/auth/forgot', ['email' => 'nadie@pruebas.test']);
    $b = $anon->post('/auth/forgot', ['email' => $c->email]);
    check($a->status === 200 && $b->status === 200 && $a->body === $b->body, 'la respuesta es la misma exista o no la cuenta', [$a->body, $b->body]);
    check(mailTo('nadie@pruebas.test', 'Restablecer') === null, 'a un correo sin cuenta no se envía nada');
    $anon->post('/auth/forgot', ['email' => $staffEmail]);
    check(mailTo($staffEmail, 'Restablecer') === null, 'las cuentas del personal no se recuperan desde la app');
    $body = (string) mailTo($c->email, 'Restablecer');
    check((bool) preg_match('~/restablecer\?token=([0-9a-f]{64})~', $body, $m), 'el correo lleva el enlace con el token', $body);
    $token = $m[1] ?? '';
    check((int) Db::value('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$token]) === 0
        && (int) Db::value('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]) === 1,
        'la base de datos guarda el hash del token, no el token');

    check($anon->post('/auth/reset', ['token' => str_repeat('a', 64), 'password' => 'Otra-Clave-Segura-1'])->code() === 'reset_invalid', 'un token inventado no sirve');
    check($anon->post('/auth/reset', ['token' => $token, 'password' => 'corta'])->status === 422, 'la nueva contraseña cumple la política');
    $ok = $anon->post('/auth/reset', ['token' => $token, 'password' => 'Otra-Clave-Segura-1']);
    check($ok->status === 200, 'con el enlace se cambia la contraseña', $ok->body);
    check($c->get('/me')->status === 401, 'la sesión abierta se cierra al cambiarla');
    check(client($now)->post('/auth/login', ['email' => $c->email, 'password' => $PASSWORD])->status === 401, 'la contraseña anterior ya no vale');
    check(client($now)->post('/auth/login', ['email' => $c->email, 'password' => 'Otra-Clave-Segura-1'])->status === 200, 'la nueva sí');
    check($anon->post('/auth/reset', ['token' => $token, 'password' => 'Tercera-Clave-Segura-1'])->code() === 'reset_invalid', 'el enlace solo funciona una vez');

    // Caduca a los 30 minutos y un enlace nuevo anula el anterior.
    $anon->post('/auth/forgot', ['email' => $c->email]);
    preg_match('~token=([0-9a-f]{64})~', (string) mailTo($c->email, 'Restablecer'), $m1);
    $anon->post('/auth/forgot', ['email' => $c->email]);
    preg_match('~token=([0-9a-f]{64})~', (string) mailTo($c->email, 'Restablecer'), $m2);
    check($anon->post('/auth/reset', ['token' => $m1[1], 'password' => 'Cuarta-Clave-Segura-1'])->code() === 'reset_invalid', 'pedir otro enlace anula el anterior');
    check(client(at(-3, '12:31'))->post('/auth/reset', ['token' => $m2[1], 'password' => 'Cuarta-Clave-Segura-1'])->code() === 'reset_invalid', 'a los 30 minutos el enlace ha caducado');

    // Como mucho 3 enlaces por hora (ya van 3).
    $before = (int) Db::value('SELECT COUNT(*) FROM password_resets');
    check($anon->post('/auth/forgot', ['email' => $c->email])->status === 200 && (int) Db::value('SELECT COUNT(*) FROM password_resets') === $before,
        'el cuarto enlace en una hora no se envía (y la respuesta no lo delata)');
    unset($staff);
});

// ---------------------------------------------------------------------------
// CP-22 · RF-43 Solicitudes de grupos grandes
// ---------------------------------------------------------------------------
test('CP-22', 'Solicitudes de grupos grandes', function () {
    $book = at(-3, '12:00');
    $c = newClient($book);
    $staff = staffClient($book);

    $cfg = $c->get('/config')->body;
    check($cfg['max_group_party'] === 40 && $cfg['deposit_per_person'] === 5, 'la app sabe el máximo de un grupo (40) y la señal por persona (5 €)', $cfg);
    check(in_array('21:00', $c->get('/group-requests/times?date=' . day())->body['times'], true), 'se ofrecen las horas de inicio del día');

    check(client($book)->post('/group-requests', ['date' => day(), 'time' => '21:00', 'party' => 14])->status === 401, 'sin sesión no se puede pedir');
    check($c->post('/group-requests', ['date' => day(), 'time' => '21:00', 'party' => 10])->status === 422, '10 comensales no es un grupo: se reserva normal');
    check($c->post('/group-requests', ['date' => day(), 'time' => '21:00', 'party' => 41])->status === 422, 'más de 40 se rechaza');
    check($c->post('/group-requests', ['date' => day(), 'time' => '21:15', 'party' => 14])->code() === 'invalid_time', 'una hora no permitida se rechaza');
    $r = $c->post('/group-requests', ['date' => day(), 'time' => '21:00', 'party' => 14, 'note' => 'Cumpleaños', 'consent' => true]);
    check($r->status === 201 && $r->body['requests'][0]['status'] === 'pending' && $r->body['requests'][0]['note'] === 'Cumpleaños', 'el cliente envía una solicitud de 14 a las 21:00', $r->body);
    $gid = $r->body['requests'][0]['id'];

    check($c->get('/panel/group-requests')->status === 401, 'un cliente no ve las solicitudes del panel');
    $list = $staff->get('/panel/group-requests')->body['requests'];
    check(count($list) === 1 && $list[0]['party'] === 14 && $list[0]['email'] === $c->email, 'el personal ve la solicitud con los datos del cliente', $list);
    $tables = $staff->get("/panel/group-requests/{$gid}/tables")->body['tables'];
    check(count($tables) === 14 && !in_array(false, array_column($tables, 'free'), true), 'y las 14 mesas (12 y 2 extra), libres a esa hora');

    // Otra reserva a esa hora ocupa la mesa 1.
    $x = reserve(newClient($book), day(), '21:00', 2)->body['reservation'];
    $free = array_column($staff->get("/panel/group-requests/{$gid}/tables")->body['tables'], 'free', 'code');
    check($x['table'] === 'S-1' && $free['S-1'] === false, 'la mesa 1 aparece ocupada a esa hora');
    check($staff->post("/panel/group-requests/{$gid}/accept", ['table_ids' => [tableId(8)]])->code() === 'over_capacity', 'con la mesa 8 sola (10 plazas) no caben 14');
    check($staff->post("/panel/group-requests/{$gid}/accept", ['table_ids' => [tableId(8), tableId(1), tableId(2)]])->code() === 'table_busy', 'una mesa ocupada a esa hora no se puede usar');
    $acc = $staff->post("/panel/group-requests/{$gid}/accept", ['table_ids' => [tableId(8), tableId(6)], 'reply' => 'Os esperamos']);
    $res = $acc->body['reservation'] ?? [];
    check($acc->status === 200 && $res['status'] === 'pending_payment' && $res['tables'] === ['S-8', 'S-6'] && $res['deposit']['cents'] === 7000,
        'aceptada con las mesas 8 y 6: pendiente de pago, señal 14 × 5 € = 70 €', $acc->body);
    check($staff->post("/panel/group-requests/{$gid}/accept", ['table_ids' => [tableId(7)]])->code() === 'not_pending', 'no se acepta dos veces');
    check(reserve(newClient($book), day(), '21:00', 10)->code() === 'conflict', 'mientras espera el pago, las mesas del grupo no se pueden reservar');
    $mine = $c->get('/group-requests')->body['requests'][0];
    check($mine['status'] === 'accepted' && $mine['reservation_id'] === $res['id'] && $mine['reply'] === 'Os esperamos', 'el cliente ve la solicitud aceptada con el mensaje', $mine);
    check(str_contains((string) mailTo($c->email, 'Solicitud de grupo aceptada'), '70 €'), 'y recibe un correo con el importe de la señal');
    check(count($c->get('/me/notifications')->body['notifications']) === 1, 'y un aviso en la app');

    // Rechazo con motivo.
    $g2 = $c->post('/group-requests', ['date' => day(1), 'time' => '13:00', 'party' => 20])->body['requests'];
    $g2 = array_values(array_filter($g2, fn($g) => $g['status'] === 'pending'))[0]['id'];
    check($staff->post("/panel/group-requests/{$g2}/reject", [])->status === 422, 'para rechazar hay que dar un motivo');
    check($staff->post("/panel/group-requests/{$g2}/reject", ['reply' => 'Ese día no tenemos sitio'])->status === 200, 'el personal la rechaza con un motivo');
    check(str_contains((string) mailTo($c->email, 'no aceptada'), 'Ese día no tenemos sitio'), 'el cliente recibe el motivo por correo');

    // El cliente cancela la suya; la de otro «no existe».
    $other = newClient($book);
    $g3 = $c->post('/group-requests', ['date' => day(2), 'time' => '13:00', 'party' => 12])->body['requests'];
    $g3 = array_values(array_filter($g3, fn($g) => $g['status'] === 'pending'))[0]['id'];
    check($other->post("/group-requests/{$g3}/cancel")->status === 404, 'otro cliente no puede cancelarla');
    check($c->post("/group-requests/{$g3}/cancel")->status === 200 && Db::value('SELECT status FROM group_requests WHERE id = ?', [$g3]) === 'cancelled', 'el titular la cancela');

    // Como mucho 3 pendientes por cliente.
    foreach (['13:00', '13:30', '14:00'] as $t) {
        $c->post('/group-requests', ['date' => day(3), 'time' => $t, 'party' => 12]);
    }
    check($c->post('/group-requests', ['date' => day(3), 'time' => '20:00', 'party' => 12])->code() === 'limit', 'la cuarta solicitud pendiente se rechaza');

    // Sin señal (0 €), el grupo aceptado queda confirmado.
    setSetting('deposit_per_person', 0);
    $free4 = acceptedGroup($other, $staff, day(4), '21:00', 12, [8, 1]);
    check($free4['status'] === 'confirmed' && $free4['deposit'] === null, 'con señal 0 € la reserva del grupo nace confirmada', $free4);
    check($staff->put('/admin/settings', ['max_group_party' => 10])->status === 403, 'solo el administrador cambia los parámetros');
    check(staffClient($book, 'admin')->put('/admin/settings', ['max_group_party' => 10])->status === 422, 'el máximo de un grupo debe superar el de una reserva normal');
});

// ---------------------------------------------------------------------------
// CP-23 · RF-44 Señal con pago de prueba (pasarela simulada)
// ---------------------------------------------------------------------------
test('CP-23', 'Señal de los grupos con pago de prueba', function () {
    $book = at(-3, '12:00');
    $c = newClient($book);
    $staff = staffClient($book);
    $g = acceptedGroup($c, $staff, day(), '21:00', 12, [8, 1]);
    $id = $g['id'];

    $view = $c->get("/reservations/{$id}")->body['reservation'];
    check($view['status'] === 'pending_payment' && $view['payable'] && $view['qr'] === null && $view['deposit']['amount'] === '60 €'
        && $view['deposit']['due'] === substr(at(-3, '12:15'), 0, 16), 'el titular ve 60 € a pagar antes de las 12:15 y aún no tiene QR', $view);
    check($staff->post("/panel/reservations/{$id}/arrive", [])->code() === 'pending_payment', 'sin pagar no se puede registrar la llegada');
    check(newClient($book)->post("/reservations/{$id}/pay")->status === 404, 'otro cliente no puede pagarla');

    $to = payDeposit($c->at(at(-3, '12:05')), $id);
    check(str_ends_with($to, "/reservas/{$id}?pago=ok"), 'tras aprobar el pago vuelve a la reserva con «pago=ok»', $to);
    $view = $c->get("/reservations/{$id}")->body['reservation'];
    check($view['status'] === 'confirmed' && $view['qr'] !== null && $view['deposit']['payment'] === 'captured', 'la reserva queda confirmada, con QR y la señal cobrada', $view);
    check(str_contains((string) mailTo($c->email, 'Reserva confirmada'), 'señal de 60 €'), 'el correo de confirmación menciona la señal');
    $order = (string) Db::value('SELECT order_id FROM payments WHERE reservation_id = ?', [$id]);
    [, $again] = redirectOf($c, '/payments/return?token=' . $order);
    check(str_ends_with($again, 'pago=ok') && (int) Db::value("SELECT COUNT(*) FROM payments WHERE status = 'captured'") === 1, 'volver otra vez de PayPal no cobra dos veces');
    check($c->post("/reservations/{$id}/pay")->code() === 'not_payable', 'una reserva pagada no se vuelve a pagar');
    check($c->patch("/reservations/{$id}", ['time' => '21:30'])->code() === 'joined_tables', 'la hora de un grupo no se cambia desde la app');

    // Cancelación del titular dentro de plazo: se devuelve la señal.
    check($c->post("/reservations/{$id}/cancel")->status === 200, 'el titular cancela dentro de plazo');
    check(Db::value('SELECT status FROM payments WHERE reservation_id = ?', [$id]) === 'refunded', 'y la señal queda devuelta');
    check(str_contains((string) mailTo($c->email, 'Reserva cancelada'), 'devolvemos la señal de 60 €'), 'el correo lo indica');

    // Sin pagar a tiempo: se libera y no se cobra un pago aprobado tarde.
    $c2 = newClient($book);
    $late = acceptedGroup($c2, $staffB = staffAt($book), day(1), '21:00', 12, [8, 1]);
    $to = payDeposit($c2, $late['id'], at(-3, '12:20'));
    check(str_ends_with($to, 'pago=caducado'), 'un pago aprobado después del plazo vuelve con «pago=caducado»', $to);
    check(Db::value('SELECT status FROM payments WHERE reservation_id = ?', [$late['id']]) === 'failed'
        && Db::value('SELECT capture_id FROM payments WHERE reservation_id = ?', [$late['id']]) === null, 'y no se cobra');
    staffAt(at(-3, '12:20'))->get('/panel/version');
    check(status($late['id']) === 'cancelled' && Db::value('SELECT cancelled_by FROM reservations WHERE id = ?', [$late['id']]) === 'system',
        'el sistema cancela la reserva que no se pagó a tiempo');
    check(Db::value('SELECT status FROM group_requests WHERE reservation_id = ?', [$late['id']]) === 'expired', 'la solicitud queda «caducada»');
    check(mailTo($c2->email, 'Reserva sin confirmar') !== null && count($c2->at(at(-3, '12:21'))->get('/me/notifications')->body['notifications']) === 2,
        'el titular recibe correo y aviso (una sola vez)');
    staffAt(at(-3, '12:22'))->get('/panel/version');
    check((int) Db::value("SELECT COUNT(*) FROM email_outbox WHERE subject LIKE '%sin confirmar%'") === 1, 'el aviso no se repite');
    check(reserve(newClient(at(-3, '12:22')), day(1), '21:00', 10)->status === 201, 'y la mesa 8 vuelve a poder reservarse');

    // El restaurante cancela sin devolver (el cliente avisó fuera de plazo).
    $c3 = newClient($book);
    $s3 = staffAt($book);
    $paid = acceptedGroup($c3, $s3, day(2), '21:00', 12, [8, 1]);
    payDeposit($c3, $paid['id']);
    $x = $s3->post("/panel/reservations/{$paid['id']}/cancel", ['refund' => false]);
    check($x->status === 200 && $x->body['reservation']['deposit']['payment'] === 'captured', 'el personal cancela sin devolver la señal', $x->body);
    check(str_contains((string) mailTo($c3->email, 'cancelada por el restaurante'), 'no se devuelve'), 'el correo dice que la señal no se devuelve');
    $paid2 = acceptedGroup($c3, $s3, day(3), '21:00', 12, [8, 1]);
    payDeposit($c3, $paid2['id']);
    $s3->post("/panel/reservations/{$paid2['id']}/cancel");
    check(Db::value('SELECT status FROM payments WHERE reservation_id = ?', [$paid2['id']]) === 'refunded', 'si el restaurante cancela sin más, se devuelve');

    // Desde la app: PayPal le devuelve a la app (front.payment_app), no a la web.
    $c4 = newClient($book);
    $fromApp = acceptedGroup($c4, staffAt($book), day(4), '21:00', 12, [8, 1]);
    check($c4->post("/reservations/{$fromApp['id']}/pay", ['from' => 'movil'])->status === 422, 'el origen del pago solo puede ser «web» o «app»');
    $to = payDeposit($c4, $fromApp['id'], null, 'app');
    check($to === "mesalista://reserva/{$fromApp['id']}?pago=ok", 'pagando desde la app, se vuelve a la app con «pago=ok»', $to);
    unset($staffB);
});

// ---------------------------------------------------------------------------
// CP-24 · RF-45 Bloqueo de mesas y franjas
// ---------------------------------------------------------------------------
test('CP-24', 'Bloqueo de mesas y franjas', function () {
    $book = at(-3, '12:00');
    $staff = staffClient($book);
    $c = newClient($book);

    // La única mesa de 10 (la 8) bloqueada por la noche.
    $h = $staff->post('/panel/holds', ['date' => day(), 'from' => '20:00', 'to' => '00:00', 'reason' => 'Evento privado', 'table_ids' => [tableId(8)]]);
    check($h->status === 201 && count($h->body['holds']) === 1 && $h->body['holds'][0]['table'] === 'S-8', 'el personal bloquea la mesa S-8 por la noche', $h->body);
    $times = $c->get('/availability?date=' . day() . '&party=10')->body['times'];
    check(!array_filter($times, fn($t) => $t >= '20:00') && in_array('13:00', $times, true), 'para 10 no se ofrece ninguna cena, pero sí la comida', $times);
    $r = reserve($c, day(), '21:00', 10);
    check($r->status === 409 && $r->code() === 'conflict', 'una reserva de 10 a las 21:00 se rechaza', $r->body);
    check(reserve($c, day(), '13:00', 10)->status === 201, 'a mediodía la mesa 8 sí se puede reservar');

    // Franja completa: todas las mesas.
    $all = $staff->post('/panel/holds', ['date' => day(1), 'from' => '13:00', 'to' => '16:00', 'reason' => 'Cerrado a mediodía', 'all' => true]);
    check($all->status === 201, 'se bloquea la comida del día siguiente para todas las mesas', $all->body);
    $t2 = $c->get('/availability?date=' . day(1) . '&party=2')->body['times'];
    check(!array_filter($t2, fn($t) => $t < '17:00') && in_array('20:00', $t2, true), 'ese día no se ofrece ninguna hora de comida y sí las cenas', $t2);

    // Con una reserva dentro, no se bloquea.
    $x = reserve(newClient($book), day(2), '21:00', 2)->body['reservation'];
    $clash = $staff->post('/panel/holds', ['date' => day(2), 'from' => '20:00', 'to' => '23:00', 'reason' => 'Avería', 'table_ids' => [tableId($x['table'])]]);
    check($clash->status === 409 && $clash->code() === 'reservations_in_hold' && in_array($x['id'], $clash->body['error']['reservations'] ?? [], true),
        'no se bloquea una mesa con una reserva en ese horario, y se dice cuál', $clash->body);
    check((int) Db::value('SELECT COUNT(*) FROM table_holds WHERE starts_at >= ?', [day(2)]) === 0, 'el bloqueo rechazado no se guarda');

    // Validaciones y permisos.
    check($staff->post('/panel/holds', ['date' => day(), 'from' => '22:00', 'to' => '21:00', 'reason' => 'x', 'all' => true])->status === 422, 'el fin anterior al inicio se rechaza');
    check($staff->post('/panel/holds', ['date' => day(-10), 'from' => '13:00', 'to' => '16:00', 'reason' => 'Pasado', 'all' => true])->status === 422, 'un horario ya pasado se rechaza');
    check($c->post('/panel/holds', ['date' => day(), 'from' => '13:00', 'to' => '16:00', 'reason' => 'Cliente', 'all' => true])->status === 401, 'un cliente no puede bloquear mesas');

    // En sala: una mesa bloqueada ahora aparece «Bloqueada» y no sirve para un cliente sin reserva.
    $s = staffAt(at(0, '12:55'));
    $s->post('/panel/holds', ['date' => day(), 'from' => '13:00', 'to' => '15:00', 'reason' => 'Avería', 'table_ids' => [tableId(1)]]);
    $s13 = staffAt(at(0, '13:10'));
    $room = array_column($s13->get('/panel/room')->body['tables'], null, 'code');
    check($room['S-1']['state'] === 'blocked' && $room['S-1']['hold']['reason'] === 'Avería', 'la mesa S-1 aparece bloqueada en sala', $room['S-1']);
    $wt = array_column($s13->get('/panel/walkin-tables?party=2')->body['tables'], 'code');
    check(!in_array('S-1', $wt, true), 'y no se ofrece para sentar a un cliente sin reserva', $wt);

    // Al quitar el bloqueo, la mesa vuelve a ofrecerse.
    $id8 = (int) Db::value('SELECT id FROM table_holds WHERE table_id = ?', [tableId(8)]);
    check($staff->delete("/panel/holds/{$id8}")->status === 200, 'se quita el bloqueo de la mesa 8');
    check(reserve(newClient($book), day(), '21:00', 10)->status === 201, 'y la mesa 8 vuelve a poder reservarse por la noche');
});

// ---------------------------------------------------------------------------
// CP-25 · RF-46 Estadísticas (coinciden con un recuento manual)
// ---------------------------------------------------------------------------
test('CP-25', 'Estadísticas de un periodo', function () {
    global $D;
    $x = $D->modify('-63 days')->format('Y-m-d');   // un sábado pasado
    $mk = function (string $time, int $party, string $status, string $source) use ($x) {
        $start = "{$x} {$time}:00";
        Db::insert(
            "INSERT INTO reservations (source, guest_name, table_id, party_size, start_at, end_at, status,
                                       arrival_before_min, arrival_after_min, change_deadline_h, created_at)
             VALUES (?, 'Prueba', ?, ?, ?, ?, ?, 15, 20, 24, ?)",
            [$source, tableId(3), $party, $start, date('Y-m-d H:i:s', strtotime($start) + 7200), $status, $start]
        );
    };
    $mk('21:00', 4, 'completed', 'app');
    $mk('13:00', 2, 'completed', 'local');
    $mk('21:00', 3, 'no_show', 'app');
    $mk('20:00', 5, 'cancelled', 'app');
    $mk('22:00', 2, 'completed', 'walkin');

    $admin = staffClient(at(-3, '12:00'), 'admin');
    check(staffClient(at(-3, '12:00'))->get("/admin/stats?from={$x}&to={$x}")->status === 403, 'el personal no ve las estadísticas');
    $s = $admin->get("/admin/stats?from={$x}&to={$x}")->body;
    check($s['reservations'] === 4, 'reservas sin canceladas: 4', $s['reservations'] ?? null);
    check($s['people'] === 8, 'comensales sentados: 4 + 2 + 2 = 8', $s['people'] ?? null);
    check($s['no_show_rate'] == 33.3, 'no presentadas: 1 de 3 reservas cuya hora llegó = 33,3 %', $s['no_show_rate'] ?? null);
    check($s['cancel_rate'] == 25.0, 'canceladas: 1 de 4 reservas = 25 %', $s['cancel_rate'] ?? null);
    check($s['by_source'] === ['app' => 2, 'local' => 1, 'walkin' => 1], 'por origen: app 2, local 1, sin reserva 1', $s['by_source'] ?? null);
    $hours = array_column($s['by_hour'], 'reservations', 'time');
    check($hours === ['13:00' => 1, '21:00' => 2, '22:00' => 1], 'por hora: 13:00 → 1, 21:00 → 2, 22:00 → 1', $hours);
    $sat = array_values(array_filter($s['by_service'], fn($o) => $o['weekday'] === 6));
    // 56 plazas activas; cena 20–24 h: 2 rotaciones; comida 13–16 h: 1.
    check($sat[0]['slot'] === '13:00–16:00' && $sat[0]['people'] === 2 && $sat[0]['capacity'] === 56 && $sat[0]['occupancy'] == 3.6,
        'comida del sábado: 2 comensales de 56 plazas = 3,6 %', $sat[0] ?? null);
    check($sat[1]['slot'] === '20:00–00:00' && $sat[1]['people'] === 6 && $sat[1]['capacity'] === 112 && $sat[1]['occupancy'] == 5.4,
        'cena del sábado: 6 comensales de 56 × 2 rotaciones = 5,4 %', $sat[1] ?? null);
    check($admin->get("/admin/stats?from={$x}&to=" . $D->modify('-70 days')->format('Y-m-d'))->status === 422, 'un periodo al revés se rechaza');
});

// ---------------------------------------------------------------------------
// CP-26 · RF-47 Exportar las reservas de un día a CSV
// ---------------------------------------------------------------------------
test('CP-26', 'Exportar a CSV', function () {
    $book = at(-3, '12:00');
    $c = newClient($book, '+34 633 444 555');
    reserve($c, day(), '20:00', 2, ['note' => '=HYPERLINK("http://x.test","clic")', 'consent' => true, 'menu_options' => [optionId('Vegano')]]);
    reserve(newClient($book), day(), '13:00', 4);
    reserve(newClient($book), day(1), '13:00', 4);
    $staff = staffClient($book);

    $ch = $staff->handle('GET', '/panel/reservations.csv?date=' . day());
    $csv = (string) curl_exec($ch);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    check((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && str_starts_with($type, 'text/csv'), 'el personal descarga el CSV', $type);
    check(str_starts_with($csv, "\u{FEFF}\"Fecha\";\"Hora\""), 'empieza con BOM y la cabecera separada por «;»', substr($csv, 0, 40));
    $lines = array_values(array_filter(explode("\r\n", $csv)));
    check(count($lines) === 3, 'una línea de cabecera y una por cada reserva del día (2)', count($lines));
    check(str_contains($csv, '"\'=HYPERLINK(""http://x.test"",""clic"")"'), 'la fórmula de la nota se escapa con un apóstrofo');
    check(str_contains($csv, '"+34633444555"') && str_contains($csv, '"Vegano"'), 'el teléfono y las opciones salen tal cual');
    check($c->get('/panel/reservations.csv?date=' . day())->status === 401, 'un cliente no puede descargarlo');
});

// ---------------------------------------------------------------------------
// CP-27 · RS-01 · la API no responde por HTTP
// ---------------------------------------------------------------------------
test('CP-27', 'HTTPS obligatorio', function () use ($base) {
    if (!str_starts_with($base, 'https://')) {
        check(true, 'omitida: el servidor de desarrollo no usa HTTPS');
        return;
    }
    $http = 'http://' . substr($base, 8) . '/api/v1/config';
    $ch = curl_init($http);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    check($code === 301 || $code === 308, 'una petición por HTTP solo recibe una redirección a HTTPS', $code);
});

// ---------------------------------------------------------------------------
// CP-28 · RS-02
// ---------------------------------------------------------------------------
test('CP-28', 'Hash de contraseñas', function () {
    $a = newClient(at(-3, '12:00'));
    $b = newClient(at(-3, '12:00'));
    $hashes = array_column(Db::all('SELECT password_hash FROM users ORDER BY id'), 'password_hash');
    check($hashes[0] !== $hashes[1], 'dos cuentas con la misma contraseña tienen hashes distintos');
    check(str_starts_with($hashes[0], '$argon2id$v=19$m=19456,t=2,p=1$'), 'Argon2id con 19 MiB, 2 iteraciones y paralelismo 1', $hashes[0]);
    check(!str_contains(implode('', $hashes), 'Prueba-MesaLista'), 'no hay contraseñas en claro');
});

// ---------------------------------------------------------------------------
// CP-35 · avisos push (FCM) de los mismos cambios que RF-17 y RF-32, contra un
// servidor falso de Google (fcm-stub.php) que comprueba la firma de la cuenta
// de servicio. CP-29 a CP-34 son las pruebas manuales de la memoria.
// ---------------------------------------------------------------------------
test('CP-35', 'Avisos push con Firebase Cloud Messaging', function () use ($PASSWORD) {
    $port = 18098;
    $rsa = fn() => openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $key = $rsa();
    openssl_pkey_export($key, $privatePem);
    $pub = (string) tempnam(sys_get_temp_dir(), 'ml-fcm');
    file_put_contents($pub, openssl_pkey_get_details($key)['key']);
    $log = (string) tempnam(sys_get_temp_dir(), 'ml-fcm');
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fcm-stub.php'],
        [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes, null,
        array_merge(getenv(), ['ML_FCM_PUBKEY' => $pub, 'ML_FCM_LOG' => $log]));
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    $saved = $GLOBALS['ML_CONFIG']['fcm'] ?? null;
    $use = function (string $pem) use ($port): void {
        $GLOBALS['ML_CONFIG']['fcm'] = ['enabled' => true, 'project_id' => 'mesalista-pruebas',
            'client_email' => 'push@mesalista-pruebas.iam.gserviceaccount.com', 'private_key' => $pem,
            'token_uri' => "http://127.0.0.1:{$port}/token", 'api' => "http://127.0.0.1:{$port}"];
    };
    $outbox = fn() => Db::all('SELECT o.title, o.body, o.attempts, o.last_error, d.token FROM push_outbox o
                                 JOIN push_devices d ON d.id = o.device_id ORDER BY o.id');
    try {
        $book = at(-3, '12:00');
        $c = newClient($book);
        $dev = 'dispositivo-de-prueba-' . bin2hex(random_bytes(8));
        check($c->post('/me/devices', ['token' => $dev, 'platform' => 'android'])->status === 201, 'la app registra su dispositivo (201)');
        check($c->post('/me/devices', ['token' => $dev, 'platform' => 'android'])->status === 200, 'registrarlo otra vez no lo duplica (200)');
        check($c->post('/me/devices', ['token' => 'corto', 'platform' => 'android'])->status === 422
            && $c->post('/me/devices', ['token' => $dev, 'platform' => 'windows'])->status === 422, 'un token o una plataforma no válidos: 422');
        check(client($book)->post('/me/devices', ['token' => $dev, 'platform' => 'android'])->status === 401, 'sin sesión no se registra ningún dispositivo');

        $r = reserve($c, day(), '21:00', 2, ['note' => 'Nota privada', 'consent' => true])->body['reservation'];
        $rows = $outbox();
        check(count($rows) === 1 && $rows[0]['title'] === 'Reserva confirmada' && str_contains($rows[0]['body'], 'a las 21:00')
            && str_contains($rows[0]['body'], '2 comensales'), 'la reserva deja un aviso con el asunto, la hora y los comensales', $rows);
        check(!str_contains((string) json_encode(Db::all('SELECT * FROM push_outbox')), 'Nota privada'), 'el aviso no lleva la nota (como RP-05)');

        $GLOBALS['ML_CONFIG']['fcm'] = ['enabled' => false];
        check(Push::flush() === 0 && count($outbox()) === 1, 'sin cuenta de servicio no se envía y el aviso espera en la cola');

        $other = $rsa();
        openssl_pkey_export($other, $otherPem);
        $use($otherPem);
        $rows = $outbox();
        check(Push::flush() === 0 && (int) $outbox()[0]['attempts'] === 1 && str_contains((string) $outbox()[0]['last_error'], 'token de acceso'),
            'con una clave que no es la de la cuenta, Google no da el token y se gasta un intento', $outbox());

        $use($privatePem);
        $sent = Push::flush();
        $msg = json_decode((string) ((file($log, FILE_IGNORE_NEW_LINES) ?: [''])[0]), true)['message'] ?? [];
        check($sent === 1 && $outbox() === [], 'con la cuenta de servicio buena se envía y sale de la cola');
        check(($msg['token'] ?? '') === $dev && ($msg['notification']['title'] ?? '') === 'Reserva confirmada'
            && ($msg['data']['type'] ?? '') === 'confirmed' && ($msg['data']['reservation_id'] ?? '') === (string) $r['id']
            && ($msg['android']['priority'] ?? '') === 'high', 'FCM recibe el dispositivo, el título y el id de la reserva', $msg);

        $c->post('/me/devices', ['token' => 'muerto-' . str_repeat('x', 20), 'platform' => 'android']);
        $c->post("/reservations/{$r['id']}/cancel");
        $sent = Push::flush();
        check($sent === 1 && (int) Db::value("SELECT COUNT(*) FROM push_devices WHERE token LIKE 'muerto-%'") === 0,
            'un dispositivo que FCM ya no conoce (404) se borra', $sent);

        $c->post('/me/devices', ['token' => 'limite-' . str_repeat('x', 20), 'platform' => 'android']);
        reserve($c, day(1), '21:00', 2);
        $sent = Push::flush();
        $rows = $outbox();
        check($sent === 1 && count($rows) === 1 && (int) $rows[0]['attempts'] === 0 && str_contains((string) $rows[0]['last_error'], '429'),
            'con el límite de FCM (429) el aviso vuelve a la cola sin gastar intentos', $rows);

        Db::exec('UPDATE push_outbox SET created_at = ?', [date('Y-m-d H:i:s', time() - 7200)]);
        check(Push::flush() === 0 && $outbox() === [], 'un aviso de hace más de una hora se descarta sin enviarlo');

        $d = newClient($book);
        check($d->post('/me/devices', ['token' => $dev, 'platform' => 'ios'])->status === 200
            && (int) Db::value('SELECT user_id FROM push_devices WHERE token = ?', [$dev]) === (int) Db::value('SELECT id FROM users WHERE email = ?', [$d->email]),
            'si otra cuenta entra en el mismo dispositivo, el token pasa a ella');
        check($d->delete('/me/devices', ['token' => $dev])->status === 200 && Db::value('SELECT 1 FROM push_devices WHERE token = ?', [$dev]) === null,
            'al cerrar sesión, la app da de baja el dispositivo');
        $cid = (int) Db::value('SELECT id FROM users WHERE email = ?', [$c->email]);
        $c->delete('/me', ['password' => $PASSWORD]);
        check((int) Db::value('SELECT COUNT(*) FROM push_devices WHERE user_id = ?', [$cid]) === 0, 'al eliminar la cuenta se borran sus dispositivos');

        $GLOBALS['ML_CONFIG']['fcm']['api'] = 'http://fcm.ejemplo.test';
        try {
            Push::send($dev, 'Prueba', 'Prueba');
            check(false, 'una dirección de Google sin https debería rechazarse');
        } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'https'), 'la dirección de Google tiene que ser https', $e->getMessage());
        }
    } finally {
        $GLOBALS['ML_CONFIG']['fcm'] = $saved;
        proc_terminate($stub);
        proc_close($stub);
        @unlink($log);
        @unlink($pub);
    }
});

resetDatabase();
echo "\n" . str_repeat('─', 60) . "\n";
echo "Comprobaciones correctas: {$results['ok']} · fallidas: {$results['fail']}\n";
foreach ($failures as $f) {
    echo "  ✘ {$f}\n";
}
exit($results['fail'] === 0 ? 0 : 1);
