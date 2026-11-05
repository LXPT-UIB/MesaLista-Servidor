<?php
declare(strict_types=1);

/** Configuración del restaurante y cuentas del personal (RF-36 a RF-38). */
final class Admin
{
    public static function tables(): array
    {
        return array_map(fn($t) => [
            'id'           => (int) $t['id'],
            'number'       => (int) $t['number'],
            'code'         => Booking::code($t),
            'zone'         => $t['zone'],
            'min_capacity' => (int) $t['min_capacity'],
            'max_capacity' => (int) $t['max_capacity'],
            'active'       => (int) $t['active'] === 1,
            'join_only'    => (int) $t['join_only'] === 1,
            'pos_x'        => $t['pos_x'] === null ? null : (int) $t['pos_x'],
            'pos_y'        => $t['pos_y'] === null ? null : (int) $t['pos_y'],
            'shape'        => $t['shape'],
        ], Db::all('SELECT * FROM dining_tables WHERE deleted_at IS NULL ORDER BY join_only, zone, number'));
    }

    /**
     * Guarda el plano de la sala: posición (centro en un lienzo de 1000 × 600
     * de su zona) y forma de cada mesa. No toca capacidades ni reservas.
     */
    public static function saveLayout(array $in): array
    {
        V::only($in, ['tables']);
        $list = $in['tables'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) > 200) {
            V::invalid('tables', 'El plano debe ser una lista de hasta 200 mesas.');
        }
        $rows = [];
        foreach ($list as $t) {
            if (!is_array($t)) {
                V::invalid('tables', 'Mesa no válida en el plano.');
            }
            V::only($t, ['id', 'pos_x', 'pos_y', 'shape']);
            $shape = $t['shape'] ?? 'rect';
            if (!in_array($shape, ['rect', 'round'], true)) {
                V::invalid('shape', 'La forma debe ser «rect» o «round».');
            }
            $rows[] = [
                V::int($t['id'] ?? null, 'id', 1, PHP_INT_MAX >> 32),
                V::int($t['pos_x'] ?? null, 'pos_x', 0, 1000),
                V::int($t['pos_y'] ?? null, 'pos_y', 0, 600),
                $shape,
            ];
        }
        Db::tx(function () use ($rows) {
            foreach ($rows as [$id, $x, $y, $shape]) {
                // Las mesas extra no tienen plano.
                if (Db::exec('UPDATE dining_tables SET pos_x = ?, pos_y = ?, shape = ? WHERE id = ? AND NOT join_only AND deleted_at IS NULL', [$x, $y, $shape, $id]) === 0
                    && Db::value('SELECT 1 FROM dining_tables WHERE id = ? AND deleted_at IS NULL', [$id]) === null) {
                    fail(404, 'not_found', "La mesa {$id} no existe.");
                }
            }
        });
        return self::tables();
    }

    // ------------------------------------------------------------------
    // Clientes registrados
    // ------------------------------------------------------------------

    /**
     * Clientes con sus datos de contacto y un resumen de reservas. Solo para
     * administradores; los datos personales no salen de este listado (RP-01).
     */
    public static function clients(?string $q): array
    {
        $sql = "SELECT u.id, u.name, u.email, u.phone, u.created_at,
                       COUNT(r.id) AS total,
                       COUNT(*) FILTER (WHERE r.status = 'confirmed' AND r.start_at > ?) AS upcoming,
                       COUNT(*) FILTER (WHERE r.status = 'completed') AS completed,
                       COUNT(*) FILTER (WHERE r.status = 'no_show') AS no_show,
                       MAX(r.start_at) AS last_reservation
                  FROM users u
                  LEFT JOIN reservations r ON r.user_id = u.id
                 WHERE u.role = 'client'";
        $params = [Clock::sql()];
        if ($q !== null && $q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' AND (search_key(u.name) LIKE search_key(?) OR search_key(u.email) LIKE search_key(?) OR u.phone LIKE ?)';
            $digits = preg_replace('/\D/', '', $q);
            array_push($params, $like, $like, $digits === '' ? $like : "%{$digits}%");
        }
        $sql .= ' GROUP BY u.id ORDER BY u.created_at DESC LIMIT 500';
        return array_map(fn($u) => [
            'id'               => (int) $u['id'],
            'name'             => $u['name'],
            'email'            => $u['email'],
            'phone'            => $u['phone'],
            'created_at'       => substr($u['created_at'], 0, 10),
            'reservations'     => (int) $u['total'],
            'upcoming'         => (int) $u['upcoming'],
            'completed'        => (int) $u['completed'],
            'no_show'          => (int) $u['no_show'],
            'last_reservation' => $u['last_reservation'] === null ? null : substr($u['last_reservation'], 0, 10),
        ], Db::all($sql, $params));
    }

    // ------------------------------------------------------------------
    // Franjas de servicio y días cerrados (RF-36)
    // ------------------------------------------------------------------

    public static function schedule(): array
    {
        $slots = array_map(fn($s) => [
            'weekday' => (int) $s['weekday'],
            'open'    => Schedule::hhmm((int) $s['open_min']),
            'close'   => Schedule::hhmm((int) $s['close_min']),
        ], Db::all('SELECT weekday, open_min, close_min FROM service_slots ORDER BY weekday, open_min'));
        $closed = array_column(
            Db::all('SELECT day FROM closed_days WHERE day >= ? ORDER BY day', [Clock::now()->format('Y-m-d')]),
            'day'
        );
        return ['slots' => $slots, 'closed_days' => $closed];
    }

    /** Sustituye todas las franjas. "00:00" como cierre significa medianoche. */
    public static function saveSlots(array $in): array
    {
        V::only($in, ['slots']);
        $slots = $in['slots'] ?? null;
        if (!is_array($slots) || !array_is_list($slots) || count($slots) > 50) {
            V::invalid('slots', 'Las franjas deben ser una lista de hasta 50 elementos.');
        }
        $clean = [];
        foreach ($slots as $s) {
            if (!is_array($s)) {
                V::invalid('slots', 'Franja no válida.');
            }
            V::only($s, ['weekday', 'open', 'close']);
            $weekday = V::int($s['weekday'] ?? null, 'weekday', 1, 7);
            $open = V::time($s['open'] ?? null, 'open');
            $close = V::time($s['close'] ?? null, 'close');
            if ($close === 0) {
                $close = 1440;
            }
            if ($open >= $close) {
                V::invalid('close', 'Cada franja debe cerrar después de abrir.');
            }
            $clean[$weekday][] = [$open, $close];
        }
        foreach ($clean as $list) {
            usort($list, fn($a, $b) => $a[0] <=> $b[0]);
            for ($i = 1; $i < count($list); $i++) {
                if ($list[$i][0] < $list[$i - 1][1]) {
                    V::invalid('slots', 'Dos franjas del mismo día no pueden solaparse.');
                }
            }
        }
        Db::tx(function () use ($clean) {
            Db::exec('DELETE FROM service_slots');
            foreach ($clean as $weekday => $list) {
                foreach ($list as [$open, $close]) {
                    Db::exec('INSERT INTO service_slots (weekday, open_min, close_min) VALUES (?, ?, ?)', [$weekday, $open, $close]);
                }
            }
        });
        return self::schedule();
    }

    public static function setClosedDay(array $in, bool $closed): array
    {
        V::only($in, ['date']);
        $date = V::date($in['date'] ?? null)->format('Y-m-d');
        if ($closed) {
            Db::exec('INSERT INTO closed_days (day) VALUES (?) ON CONFLICT DO NOTHING', [$date]);
        } else {
            Db::exec('DELETE FROM closed_days WHERE day = ?', [$date]);
        }
        return self::schedule();
    }

    // ------------------------------------------------------------------
    // Parámetros y opciones de menú (RF-37)
    // ------------------------------------------------------------------

    public static function settings(): array
    {
        $options = array_map(fn($o) => [
            'id' => (int) $o['id'], 'name' => $o['name'], 'active' => (int) $o['active'] === 1,
        ], Db::all('SELECT id, name, active FROM menu_options ORDER BY id'));
        return [
            'settings' => Settings::all(), 'ranges' => Settings::RANGES, 'menu_options' => $options,
            'close_pin_set' => Db::value("SELECT 1 FROM secrets WHERE name = 'close_pin'") !== null,
        ];
    }

    // ------------------------------------------------------------------
    // PIN de «Cerrar local»: lo fija el administrador; lo usa el personal.
    // ------------------------------------------------------------------

    private const PIN_MAX_FAILED = 5;
    private const PIN_LOCK_MIN = 15;

    public static function setClosePin(array $in): array
    {
        V::only($in, ['pin']);
        $pin = $in['pin'] ?? null;
        if (!is_string($pin) || !preg_match('/^\d{4,8}$/', $pin)) {
            V::invalid('pin', 'El PIN debe tener de 4 a 8 cifras.');
        }
        Db::exec(
            "INSERT INTO secrets (name, value, updated_at) VALUES ('close_pin', ?, ?)
             ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at",
            [Auth::hash($pin), Clock::sql()]
        );
        return self::settings();
    }

    /**
     * Comprueba el PIN. Tras 5 fallos en 15 minutos (de cualquier persona)
     * se bloquea: un PIN de pocas cifras no se puede probar a ciegas.
     */
    public static function checkClosePin(mixed $pin): void
    {
        $key = hash('sha256', 'close-pin');
        $failed = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND NOT success AND created_at > ?',
            [$key, Clock::sql(Clock::plus(Clock::now(), -self::PIN_LOCK_MIN))]
        );
        if ($failed >= self::PIN_MAX_FAILED) {
            fail(429, 'locked', 'Demasiados PIN incorrectos. Espera 15 minutos o pide ayuda al administrador.');
        }
        $hash = Db::value("SELECT value FROM secrets WHERE name = 'close_pin'");
        if ($hash === null) {
            fail(409, 'no_pin', 'El administrador aún no ha fijado el PIN de «Cerrar local» (Administración → Parámetros).');
        }
        $ok = is_string($pin) && preg_match('/^\d{4,8}$/', $pin) === 1 && password_verify($pin, $hash);
        Db::exec('INSERT INTO login_attempts (email_hash, ip, success, created_at) VALUES (?, ?, ?, ?)',
            [$key, Http::ip(), $ok ? 1 : 0, Clock::sql()]);
        if (!$ok) {
            fail(403, 'bad_pin', 'PIN incorrecto.');
        }
    }

    /** Los cambios solo afectan a las reservas nuevas (regla 9). */
    public static function saveSettings(array $in): array
    {
        V::only($in, array_keys(Settings::RANGES));
        $new = Settings::all();
        foreach ($in as $name => $value) {
            [$min, $max] = Settings::RANGES[$name];
            $new[$name] = V::int($value, $name, $min, $max);
        }
        if ($new['min_party'] > $new['max_party']) {
            V::invalid('min_party', 'El mínimo de comensales no puede superar el máximo.');
        }
        if ($new['max_group_party'] <= $new['max_party']) {
            V::invalid('max_group_party', 'El máximo de un grupo debe ser mayor que el máximo de comensales de una reserva normal.');
        }
        Db::tx(function () use ($in, $new) {
            foreach (array_keys($in) as $name) {
                Db::exec('UPDATE settings SET value = ? WHERE name = ?', [$new[$name], $name]);
            }
        });
        Settings::forget();
        return self::settings();
    }

    public static function saveMenuOption(?int $id, array $in): array
    {
        V::only($in, ['name', 'active']);
        try {
            if ($id === null) {
                Db::exec('INSERT INTO menu_options (name) VALUES (?)', [V::text($in['name'] ?? null, 'name', 2, 40)]);
            } else {
                if (Db::value('SELECT 1 FROM menu_options WHERE id = ?', [$id]) === null) {
                    fail(404, 'not_found', 'Opción no encontrada.');
                }
                if (array_key_exists('name', $in)) {
                    Db::exec('UPDATE menu_options SET name = ? WHERE id = ?', [V::text($in['name'], 'name', 2, 40), $id]);
                }
                if (array_key_exists('active', $in)) {
                    Db::exec('UPDATE menu_options SET active = ? WHERE id = ?', [V::bool($in['active'], 'active') ? 1 : 0, $id]);
                }
            }
        } catch (PDOException $e) {
            if (Db::isDuplicateKey($e)) {
                fail(409, 'duplicate', 'Ya existe una opción con ese nombre.');
            }
            throw $e;
        }
        return self::settings();
    }

    // ------------------------------------------------------------------
    // Cuentas del personal (RF-38)
    // ------------------------------------------------------------------

    public static function staff(): array
    {
        return array_map(fn($u) => [
            'id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'],
            'role' => $u['role'], 'active' => (int) $u['active'] === 1,
        ], Db::all("SELECT id, name, email, role, active FROM users WHERE role IN ('staff','admin') ORDER BY name"));
    }

    public static function createStaff(array $in): array
    {
        V::only($in, ['name', 'email', 'password', 'role']);
        $name = V::name($in['name'] ?? null);
        $email = V::email($in['email'] ?? null);
        $role = self::role($in['role'] ?? null);
        $password = Auth::checkPasswordPolicy($in['password'] ?? null);
        try {
            Db::exec(
                'INSERT INTO users (role, name, email, password_hash, created_at) VALUES (?, ?, ?, ?, ?)',
                [$role, $name, $email, Auth::hash($password), Clock::sql()]
            );
        } catch (PDOException $e) {
            if (Db::isDuplicateKey($e)) {
                fail(409, 'email_taken', 'Ya existe una cuenta con ese correo.', ['field' => 'email']);
            }
            throw $e;
        }
        return self::staff();
    }

    /** Una cuenta desactivada no puede iniciar sesión y pierde las sesiones abiertas. */
    public static function updateStaff(array $admin, int $id, array $in): array
    {
        V::only($in, ['name', 'role', 'active', 'password']);
        $u = Db::one("SELECT id FROM users WHERE id = ? AND role IN ('staff','admin')", [$id]);
        if ($u === null) {
            fail(404, 'not_found', 'Cuenta no encontrada.');
        }
        $self = $id === (int) $admin['id'];
        if (array_key_exists('name', $in)) {
            Db::exec('UPDATE users SET name = ? WHERE id = ?', [V::name($in['name']), $id]);
        }
        if (array_key_exists('role', $in)) {
            $role = self::role($in['role']);
            if ($self && $role !== 'admin') {
                fail(409, 'self', 'No puedes quitarte el rol de administrador a ti mismo.');
            }
            Db::exec('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        }
        if (array_key_exists('password', $in)) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hash(Auth::checkPasswordPolicy($in['password'])), $id]);
        }
        if (array_key_exists('active', $in)) {
            $active = V::bool($in['active'], 'active');
            if ($self && !$active) {
                fail(409, 'self', 'No puedes desactivar tu propia cuenta.');
            }
            Db::exec('UPDATE users SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
            if (!$active) {
                Db::exec('DELETE FROM sessions WHERE user_id = ?', [$id]);
            }
        }
        return self::staff();
    }

    private static function role(mixed $v): string
    {
        if (!in_array($v, ['staff', 'admin'], true)) {
            V::invalid('role', 'El rol debe ser «staff» o «admin».');
        }
        return $v;
    }
}
