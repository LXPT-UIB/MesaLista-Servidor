<?php
declare(strict_types=1);

// Rutas de la API. Prefijo /api/v1; las de /panel exigen sesión del personal
// y las de /admin, rol de administrador (RS-04). La autorización se
// comprueba siempre aquí, en el servidor. Las sesiones se abren con
// /auth/login o /panel/login, que devuelven el token Bearer en «session».

/** @var Router $router */

$body = fn(): array => Http::body();
$dateParam = fn(): DateTimeImmutable => V::date(Http::query('date') ?? Clock::now()->format('Y-m-d'));

// ---------------------------------------------------------------------
// Público
// ---------------------------------------------------------------------

$router->add('GET', '/config', fn() => [
    'min_party'        => Settings::get('min_party'),
    'max_party'        => Settings::get('max_party'),
    'max_advance_days' => Settings::get('max_advance_days'),
    'duration_min'     => Settings::get('duration_min'),
    'menu_options'     => array_map(
        fn($id, $name) => ['id' => $id, 'name' => $name],
        array_keys(Settings::menuOptions()), Settings::menuOptions()
    ),
    'today'            => Clock::now()->format('Y-m-d'),
    // RF-43 y RF-44: grupos más grandes que max_party, con señal si hay pasarela.
    'max_group_party'  => Settings::get('max_group_party'),
    'deposit_per_person' => Payments::enabled() ? Settings::get('deposit_per_person') : 0,
    'deposit_hold_min' => Settings::get('deposit_hold_min'),
]);

// RF-06: cualquier persona puede consultar la disponibilidad.
$router->add('GET', '/availability', fn() => [
    'times' => Booking::availability(
        V::date(Http::query('date')),
        V::int(Http::query('party'), 'party', 1, 255)
    ),
]);

// ---------------------------------------------------------------------
// Cliente (app móvil)
// ---------------------------------------------------------------------

$router->add('POST', '/auth/register', fn() => [
    'user' => Auth::register($body()), 'session' => Auth::issued(), '_status' => 201,
]);

$router->add('POST', '/auth/login', function () use ($body) {
    $in = $body();
    $u = Auth::login('app', $in['email'] ?? null, $in['password'] ?? null);
    return [
        'user'    => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone']],
        'session' => Auth::issued(),
    ];
});

$router->add('POST', '/auth/logout', function () {
    Auth::logout('app');
    return ['ok' => true];
});

// RF-40: recuperar la contraseña. La respuesta es la misma exista o no la cuenta.
$router->add('POST', '/auth/forgot', function () use ($body) {
    $in = $body();
    V::only($in, ['email']);
    Auth::requestReset($in['email'] ?? null);
    return ['ok' => true];
});

$router->add('POST', '/auth/reset', function () use ($body) {
    Auth::resetPassword($body());
    return ['ok' => true];
});

$profile = fn(array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone']];

$router->add('GET', '/me', fn() => ['user' => $profile(Auth::client())]);

// RF-04: el cliente modifica su nombre y su teléfono.
$router->add('PATCH', '/me', function () use ($body, $profile) {
    $u = Auth::client();
    $in = $body();
    V::only($in, ['name', 'phone']);
    if (array_key_exists('name', $in)) {
        Db::exec('UPDATE users SET name = ? WHERE id = ?', [V::name($in['name']), $u['id']]);
    }
    if (array_key_exists('phone', $in)) {
        Db::exec('UPDATE users SET phone = ? WHERE id = ?', [V::phone($in['phone']), $u['id']]);
    }
    return ['user' => $profile(Db::one('SELECT id, name, email, phone FROM users WHERE id = ?', [$u['id']]))];
});

// RF-05: eliminar la cuenta, confirmando con la contraseña.
$router->add('DELETE', '/me', function () use ($body) {
    $u = Auth::client();
    $in = $body();
    if (!Auth::verifyPassword((int) $u['id'], $in['password'] ?? null)) {
        fail(403, 'bad_password', 'La contraseña no es correcta.');
    }
    Booking::deleteClient($u);
    Auth::logout('app');
    return ['ok' => true];
});

// RF-32: avisos de cancelaciones hechas por el restaurante.
$router->add('GET', '/me/notifications', function () {
    $u = Auth::client();
    return ['notifications' => array_map(fn($n) => [
        'id' => (int) $n['id'], 'message' => $n['message'], 'reservation_id' => $n['reservation_id'] === null ? null : (int) $n['reservation_id'],
        'created_at' => $n['created_at'],
    ], Db::all('SELECT * FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY id', [$u['id']]))];
});

$router->add('POST', '/me/notifications/read', function () {
    $u = Auth::client();
    Db::exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::sql(), $u['id']]);
    return ['ok' => true];
});

// RF-14
$router->add('GET', '/reservations', function () {
    $u = Auth::client();
    return ['reservations' => array_map([Present::class, 'client'], Booking::listOwned((int) $u['id']))];
});

// RF-07 a RF-13. 201 si se ha creado; 200 si era una repetición (RF-10).
$router->add('POST', '/reservations', function () use ($body) {
    [$r, $created] = Booking::createForClient(Auth::client(), $body());
    return ['reservation' => Present::client($r), 'replayed' => !$created, '_status' => $created ? 201 : 200];
});

// RF-30
$router->add('POST', '/reservations/link', fn() => [
    'reservation' => Present::client(Booking::link(Auth::client(), $body())),
]);

$router->add('GET', '/reservations/{id}', function ($p) {
    $u = Auth::client();
    return ['reservation' => Present::client(Booking::getOwned((int) $u['id'], $p['id']))];
});

// RF-15
$router->add('PATCH', '/reservations/{id}', fn($p) => [
    'reservation' => Present::client(Booking::modifyByHolder(Auth::client(), $p['id'], $body())),
]);

// RF-16
$router->add('POST', '/reservations/{id}/cancel', fn($p) => [
    'reservation' => Present::client(Booking::cancelByHolder(Auth::client(), $p['id'])),
]);

// RF-44: pagar la señal. Devuelve la página de PayPal a la que llevar al cliente.
// RF-44: {"from": "web"} o {"from": "app"}: adónde vuelve el cliente al terminar.
$router->add('POST', '/reservations/{id}/pay', function ($p) use ($body) {
    $in = $body();
    V::only($in, ['from']);
    return ['approve_url' => Payments::start(Auth::client(), $p['id'], $in['from'] ?? 'web')];
});

// Vuelta desde PayPal (?token=<pedido>): cobra y redirige a la reserva en la app.
$router->add('GET', '/payments/return', fn() => ['_redirect' => Payments::handleReturn(Http::query('token'))]);

// Pasarela simulada (solo en el servidor de pruebas): «aprueba» y vuelve.
$router->add('GET', '/payments/fake-approve', function () {
    if (Payments::mode() !== 'fake' || !Payments::configured()) {
        fail(404, 'not_found', 'Recurso no encontrado.');
    }
    return ['_redirect' => Http::apiUrl() . '/payments/return?token=' . rawurlencode(Http::query('token') ?? '')];
});

// RF-43: solicitudes de grupo.
$router->add('GET', '/group-requests/times', fn() => ['times' => GroupRequests::times(V::date(Http::query('date')))]);

$router->add('GET', '/group-requests', fn() => ['requests' => GroupRequests::forClient((int) Auth::client()['id'])]);

$router->add('POST', '/group-requests', fn() => [
    'requests' => GroupRequests::create(Auth::client(), $body()), '_status' => 201,
]);

$router->add('POST', '/group-requests/{id}/cancel', fn($p) => [
    'requests' => GroupRequests::cancelOwn(Auth::client(), $p['id']),
]);

// ---------------------------------------------------------------------
// Panel del personal
// ---------------------------------------------------------------------

$staffProfile = fn(array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];

$router->add('POST', '/panel/login', function () use ($body, $staffProfile) {
    $in = $body();
    return [
        'user'    => $staffProfile(Auth::login('panel', $in['email'] ?? null, $in['password'] ?? null)),
        'session' => Auth::issued(),
    ];
});

$router->add('POST', '/panel/logout', function () {
    Auth::logout('panel');
    return ['ok' => true];
});

$router->add('GET', '/panel/me', fn() => ['user' => $staffProfile(Auth::staff())]);

// RF-34: el panel consulta esta marca cada segundo y solo recarga si cambia.
$router->add('GET', '/panel/version', function () {
    Auth::staff();
    Booking::markNoShows();
    return ['version' => Booking::version()];
});

$router->add('GET', '/panel/room', function () {
    Auth::staff();
    return ['tables' => Booking::room(), 'now' => Clock::now()->format('H:i')];
});

$router->add('GET', '/panel/availability', function () {
    Auth::staff();
    return ['times' => Booking::availability(
        V::date(Http::query('date')),
        V::int(Http::query('party'), 'party', 1, 255),
        true
    )];
});

// RF-22 y RF-27
$router->add('GET', '/panel/reservations', function () use ($dateParam) {
    Auth::staff();
    $q = Http::query('q');
    $q = $q === null ? null : V::text($q, 'q', 0, 80);
    return ['reservations' => array_map([Present::class, 'staff'], Booking::agenda($dateParam(), $q))];
});

// RF-28 y RF-29
$router->add('POST', '/panel/reservations', function () use ($body) {
    $staff = Auth::staff();
    $r = Booking::createLocal((int) $staff['id'], $body());
    return ['reservation' => Present::staff($r), 'ticket' => Present::ticket($r), '_status' => 201];
});

$router->add('GET', '/panel/reservations/{id}/ticket', function ($p) {
    Auth::staff();
    $r = Booking::load($p['id']) ?? fail(404, 'not_found', 'Reserva no encontrada.');
    return ['ticket' => Present::ticket($r)];
});

// RF-31. RF-44: {"refund": false} para no devolver la señal (cancelación fuera de plazo).
$router->add('POST', '/panel/reservations/{id}/cancel', function ($p) use ($body) {
    Auth::staff();
    return ['reservation' => Present::staff(Booking::cancelByRestaurant($p['id'], $body()))];
});

// RF-43: solicitudes de grupo.
$router->add('GET', '/panel/group-requests', function () {
    Auth::staff();
    Booking::markNoShows();
    return ['requests' => GroupRequests::forStaff()];
});

$router->add('GET', '/panel/group-requests/{id}/tables', function ($p) {
    Auth::staff();
    return ['tables' => Booking::groupTables($p['id'])];
});

$router->add('POST', '/panel/group-requests/{id}/accept', function ($p) use ($body) {
    $staff = Auth::staff();
    return ['reservation' => Present::staff(Booking::acceptGroup((int) $staff['id'], $p['id'], $body())),
            'requests' => GroupRequests::forStaff()];
});

$router->add('POST', '/panel/group-requests/{id}/reject', function ($p) use ($body) {
    $staff = Auth::staff();
    GroupRequests::reject((int) $staff['id'], $p['id'], $body());
    return ['requests' => GroupRequests::forStaff()];
});

// RF-21, RF-22 y RF-23: llegada sin QR (búsqueda por nombre o teléfono).
$router->add('POST', '/panel/reservations/{id}/arrive', function ($p) use ($body) {
    Auth::staff();
    $in = $body();
    V::only($in, ['early']);
    $early = array_key_exists('early', $in) ? V::bool($in['early'], 'early') : false;
    return ['reservation' => Present::staff(Booking::arrive($p['id'], null, $early))];
});

// RF-19, RF-20, RS-07: llegada con QR.
$router->add('POST', '/panel/arrive-qr', function () use ($body) {
    Auth::staff();
    $in = $body();
    V::only($in, ['qr', 'early']);
    $early = array_key_exists('early', $in) ? V::bool($in['early'], 'early') : false;
    return ['reservation' => Present::staff(Booking::arriveByQr($in['qr'] ?? null, $early))];
});

// RF-25
$router->add('POST', '/panel/reservations/{id}/complete', function ($p) {
    Auth::staff();
    return ['reservation' => Present::staff(Booking::complete($p['id']))];
});

// RF-26
$router->add('POST', '/panel/reservations/{id}/party', function ($p) use ($body) {
    Auth::staff();
    return ['reservation' => Present::staff(Booking::increaseParty($p['id'], $body()))];
});

// RF-33
$router->add('GET', '/panel/walkin-tables', function () {
    Auth::staff();
    return ['tables' => array_map(fn($t) => [
        'id' => (int) $t['id'], 'number' => (int) $t['number'], 'code' => $t['code'], 'zone' => $t['zone'],
        'min_capacity' => (int) $t['min_capacity'], 'max_capacity' => (int) $t['max_capacity'],
        'join_only' => (int) $t['join_only'] === 1, 'fits' => $t['fits'],
    ], Booking::walkInTables(V::int(Http::query('party'), 'party', 1, 50)))];
});

$router->add('POST', '/panel/walkin', function () use ($body) {
    $staff = Auth::staff();
    return ['reservation' => Present::staff(Booking::walkIn((int) $staff['id'], $body())), '_status' => 201];
});

// «Cerrar local»: termina todas las reservas en curso (todas las mesas libres). Pide el PIN.
$router->add('POST', '/panel/close', function () use ($body) {
    return Booking::closeLocal(Auth::staff(), $body());
});

// RF-45: bloqueos de mesas y franjas (personal y administración).
$router->add('GET', '/panel/holds', function () {
    Auth::staff();
    $date = Http::query('date');
    return ['holds' => $date === null ? Holds::upcoming() : Holds::forDate(V::date($date))];
});

$router->add('POST', '/panel/holds', function () use ($body) {
    $staff = Auth::staff();
    return ['holds' => Holds::create((int) $staff['id'], $body()), '_status' => 201];
});

$router->add('DELETE', '/panel/holds/{id}', function ($p) {
    Auth::staff();
    return ['holds' => Holds::delete($p['id'])];
});

// RF-47: reservas de un día en CSV.
$router->add('GET', '/panel/reservations.csv', function () use ($dateParam) {
    Auth::staff();
    [$file, $csv] = Export::dayCsv($dateParam());
    return ['_file' => $file, '_type' => 'text/csv; charset=utf-8', '_body' => $csv];
});

// Cambiar y unir mesas de una reserva confirmada o en curso.
$router->add('GET', '/panel/reservations/{id}/free-tables', function ($p) {
    Auth::staff();
    return ['tables' => Booking::freeTablesFor($p['id'])];
});

$router->add('POST', '/panel/reservations/{id}/move', function ($p) use ($body) {
    Auth::staff();
    return ['reservation' => Present::staff(Booking::changeTable($p['id'], $body()))];
});

$router->add('POST', '/panel/reservations/{id}/join', function ($p) use ($body) {
    Auth::staff();
    return ['reservation' => Present::staff(Booking::joinTables($p['id'], $body()))];
});

$router->add('POST', '/panel/reservations/{id}/separate', function ($p) use ($body) {
    Auth::staff();
    return ['reservation' => Present::staff(Booking::separateTable($p['id'], $body()))];
});

// ---------------------------------------------------------------------
// Administración
// ---------------------------------------------------------------------

// RF-35
$router->add('GET', '/admin/tables', function () {
    Auth::admin();
    return ['tables' => Admin::tables()];
});

$router->add('POST', '/admin/tables', function () use ($body) {
    Auth::admin();
    return Booking::saveTable(null, $body()) + ['tables' => Admin::tables(), '_status' => 201];
});

$router->add('PATCH', '/admin/tables/{id}', function ($p) use ($body) {
    Auth::admin();
    return Booking::saveTable($p['id'], $body()) + ['tables' => Admin::tables()];
});

// Eliminar una mesa (si no tiene reservas pendientes ni en curso).
$router->add('DELETE', '/admin/tables/{id}', function ($p) {
    Auth::admin();
    return Booking::deleteTable($p['id']) + ['tables' => Admin::tables()];
});

// PIN de «Cerrar local» (4 a 8 cifras).
$router->add('PUT', '/admin/close-pin', function () use ($body) {
    Auth::admin();
    return Admin::setClosePin($body());
});

// Plano de la sala (posición y forma de las mesas).
$router->add('PUT', '/admin/tables/layout', function () use ($body) {
    Auth::admin();
    return ['tables' => Admin::saveLayout($body())];
});

// RF-46: estadísticas de un periodo (?from=AAAA-MM-DD&to=AAAA-MM-DD; por defecto, 30 días).
$router->add('GET', '/admin/stats', function () {
    Auth::admin();
    return Stats::period(array_filter(['from' => Http::query('from'), 'to' => Http::query('to')], fn($v) => $v !== null));
});

// Clientes registrados, con búsqueda por nombre, correo o teléfono.
$router->add('GET', '/admin/clients', function () {
    Auth::admin();
    $q = Http::query('q');
    return ['clients' => Admin::clients($q === null ? null : V::text($q, 'q', 0, 80))];
});

// RF-36
$router->add('GET', '/admin/schedule', function () {
    Auth::admin();
    return Admin::schedule();
});

$router->add('PUT', '/admin/schedule/slots', function () use ($body) {
    Auth::admin();
    return Admin::saveSlots($body());
});

$router->add('POST', '/admin/schedule/closed', function () use ($body) {
    Auth::admin();
    return Admin::setClosedDay($body(), true);
});

$router->add('POST', '/admin/schedule/open', function () use ($body) {
    Auth::admin();
    return Admin::setClosedDay($body(), false);
});

// RF-37
$router->add('GET', '/admin/settings', function () {
    Auth::admin();
    return Admin::settings();
});

$router->add('PUT', '/admin/settings', function () use ($body) {
    Auth::admin();
    return Admin::saveSettings($body());
});

$router->add('POST', '/admin/menu-options', function () use ($body) {
    Auth::admin();
    return Admin::saveMenuOption(null, $body()) + ['_status' => 201];
});

$router->add('PATCH', '/admin/menu-options/{id}', function ($p) use ($body) {
    Auth::admin();
    return Admin::saveMenuOption($p['id'], $body());
});

// RF-38
$router->add('GET', '/admin/staff', function () {
    Auth::admin();
    return ['staff' => Admin::staff()];
});

$router->add('POST', '/admin/staff', function () use ($body) {
    Auth::admin();
    return ['staff' => Admin::createStaff($body()), '_status' => 201];
});

$router->add('PATCH', '/admin/staff/{id}', function ($p) use ($body) {
    $admin = Auth::admin();
    return ['staff' => Admin::updateStaff($admin, $p['id'], $body())];
});
