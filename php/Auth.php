<?php
declare(strict_types=1);

/**
 * Contraseñas, sesiones y roles (RF-01 a RF-03, RF-38, RS-02, RS-04 a RS-06, RS-09).
 *
 * La web y la app se identifican con un token Bearer (cabecera
 * «Authorization: Bearer <token>») que la API entrega al iniciar sesión. La
 * base de datos solo guarda su hash SHA-256. Hay dos tipos de sesión:
 *   app   · clientes, caduca 60 minutos después de iniciarse.
 *   panel · personal y administradores, 8 horas como máximo y 30 minutos sin actividad.
 */
final class Auth
{
    private const APP_TTL_MIN = 60;
    private const PANEL_TTL_MIN = 480;
    private const PANEL_IDLE_MIN = 30;
    private const MAX_FAILED = 5;
    private const LOCK_MIN = 15;

    // RS-02: Argon2id con 19 MiB, 2 iteraciones y paralelismo 1 (mínimo OWASP).
    private const HASH_OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

    private static ?array $user = null;

    /** Token de la sesión que acaba de abrir esta petición (login o registro). */
    private static ?array $issued = null;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::HASH_OPTIONS);
    }

    /** RS-09: al menos 10 caracteres y fuera de la lista de contraseñas comunes. */
    public static function checkPasswordPolicy(mixed $password): string
    {
        if (!is_string($password) || !mb_check_encoding($password, 'UTF-8')) {
            V::invalid('password', 'La contraseña no es válida.');
        }
        $len = mb_strlen($password);
        if ($len < 10 || $len > 128) {
            V::invalid('password', 'La contraseña debe tener entre 10 y 128 caracteres.');
        }
        if (self::isCommon($password)) {
            V::invalid('password', 'Esa contraseña es demasiado común. Elige otra.');
        }
        return $password;
    }

    private static function isCommon(string $password): bool
    {
        $file = ML_ROOT . '/data/common-passwords.txt';
        if (!is_file($file)) {
            return false;
        }
        $needle = mb_strtolower($password);
        $fh = fopen($file, 'r');
        while (($line = fgets($fh)) !== false) {
            if (mb_strtolower(rtrim($line, "\r\n")) === $needle) {
                fclose($fh);
                return true;
            }
        }
        fclose($fh);
        return false;
    }

    /**
     * Inicia sesión. Con credenciales erróneas, cuenta desactivada o de otro
     * tipo, siempre el mismo mensaje (RF-02). Tras 5 fallos desde la misma IP en
     * 15 minutos, rechaza incluso la contraseña correcta (RS-06).
     */
    public static function login(string $kind, mixed $emailIn, mixed $passwordIn): array
    {
        $email = is_string($emailIn) ? mb_strtolower(trim($emailIn)) : '';
        $password = is_string($passwordIn) ? $passwordIn : '';
        if ($email === '' || $password === '' || strlen($email) > 254 || strlen($password) > 512) {
            fail(401, 'bad_credentials', 'Correo o contraseña incorrectos.');
        }

        $now = Clock::now();
        $emailHash = hash('sha256', $email);
        $ip = Http::ip();
        $failed = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email_hash = ? AND ip = ? AND NOT success AND created_at > ?',
            [$emailHash, $ip, Clock::sql(Clock::plus($now, -self::LOCK_MIN))]
        );
        if ($failed >= self::MAX_FAILED) {
            fail(429, 'locked', 'Demasiados intentos fallidos. Vuelve a intentarlo dentro de 15 minutos.');
        }

        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $roles = $kind === 'app' ? ['client'] : ['staff', 'admin'];
        if ($user === null) {
            // Mismo coste que una comprobación real, para no delatar si el correo existe.
            self::hash($password);
        }
        $ok = $user !== null
            && password_verify($password, $user['password_hash'])
            && (int) $user['active'] === 1
            && in_array($user['role'], $roles, true);

        Db::exec(
            'INSERT INTO login_attempts (email_hash, ip, success, created_at) VALUES (?, ?, ?, ?)',
            [$emailHash, $ip, $ok ? 1 : 0, Clock::sql()]
        );
        if (!$ok) {
            fail(401, 'bad_credentials', 'Correo o contraseña incorrectos.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID, self::HASH_OPTIONS)) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($password), $user['id']]);
        }

        self::startSession($kind, (int) $user['id']);
        return $user;
    }

    /** RF-01: crea una cuenta de cliente e inicia su sesión. */
    public static function register(array $in): array
    {
        V::only($in, ['name', 'email', 'phone', 'password']);
        $name = V::name($in['name'] ?? null);
        $email = V::email($in['email'] ?? null);
        $phone = V::phone($in['phone'] ?? null);
        $password = self::checkPasswordPolicy($in['password'] ?? null);
        try {
            $id = Db::insert(
                "INSERT INTO users (role, name, email, phone, password_hash, created_at) VALUES ('client', ?, ?, ?, ?, ?)",
                [$name, $email, $phone, self::hash($password), Clock::sql()]
            );
        } catch (PDOException $e) {
            if (Db::isDuplicateKey($e)) {
                fail(409, 'email_taken', 'Ya existe una cuenta con ese correo.', ['field' => 'email']);
            }
            throw $e;
        }
        self::startSession('app', $id);
        return Db::one('SELECT id, name, email, phone FROM users WHERE id = ?', [$id]);
    }

    private static function startSession(string $kind, int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $now = Clock::now();
        $expires = Clock::plus($now, $kind === 'app' ? self::APP_TTL_MIN : self::PANEL_TTL_MIN);
        Db::exec(
            'INSERT INTO sessions (token_hash, user_id, kind, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [hash('sha256', $token), $userId, $kind, Clock::sql($now), Clock::sql($now), Clock::sql($expires)]
        );
        self::$issued = ['token' => $token, 'kind' => $kind, 'expires_at' => $expires->format(DATE_ATOM)];
    }

    /**
     * Token de la sesión recién abierta, para devolverlo en la respuesta. Es la
     * única vez que viaja: después el cliente lo envía en cada petición.
     */
    public static function issued(): array
    {
        return self::$issued ?? throw new LogicException('no se ha abierto ninguna sesión');
    }

    /** Usuario de la sesión actual del tipo indicado, o null. */
    public static function current(string $kind): ?array
    {
        if (self::$user !== null && self::$user['_kind'] === $kind) {
            return self::$user;
        }
        $token = Http::bearer();
        if ($token === null || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = Db::one(
            'SELECT s.last_seen_at, s.expires_at, s.kind, u.*
               FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ?',
            [$hash]
        );
        // Un token del panel en una ruta de cliente (o al revés) no vale aquí,
        // pero la sesión sigue abierta para su lado.
        if ($row === null || $row['kind'] !== $kind) {
            return null;
        }
        $now = Clock::now();
        $valid = (int) $row['active'] === 1
            && Clock::parse($row['expires_at']) > $now
            && ($kind === 'app' || Clock::parse($row['last_seen_at']) > Clock::plus($now, -self::PANEL_IDLE_MIN));
        if (!$valid) {
            Db::exec('DELETE FROM sessions WHERE token_hash = ?', [$hash]);
            return null;
        }
        if ($kind === 'panel' && !Http::isBackground()) {
            Db::exec('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?', [Clock::sql($now), $hash]);
        }
        unset($row['password_hash']);
        $row['_kind'] = $kind;
        $row['_token_hash'] = $hash;
        return self::$user = $row;
    }

    /** Exige sesión del tipo indicado y uno de los roles (RS-04). */
    public static function require(string $kind, array $roles): array
    {
        $user = self::current($kind);
        if ($user === null) {
            fail(401, 'unauthenticated', 'La sesión ha caducado o no existe. Inicia sesión de nuevo.');
        }
        if (!in_array($user['role'], $roles, true)) {
            fail(403, 'forbidden', 'No tienes permiso para esta operación.');
        }
        return $user;
    }

    public static function client(): array
    {
        return self::require('app', ['client']);
    }

    public static function staff(): array
    {
        return self::require('panel', ['staff', 'admin']);
    }

    public static function admin(): array
    {
        return self::require('panel', ['admin']);
    }

    /** RF-03: el token deja de valer en el servidor, no solo en la web o la app. */
    public static function logout(string $kind): void
    {
        $token = Http::bearer();
        if ($token !== null && preg_match('/^[0-9a-f]{64}$/', $token)) {
            Db::exec('DELETE FROM sessions WHERE token_hash = ? AND kind = ?', [hash('sha256', $token), $kind]);
        }
        self::$user = null;
    }

    // ------------------------------------------------------------------
    // RF-40: restablecer la contraseña con un enlace de un solo uso
    // ------------------------------------------------------------------

    private const RESET_TTL_MIN = 30;
    private const RESET_PER_HOUR = 3;

    /**
     * Envía el enlace si el correo es de un cliente. La respuesta es la misma
     * exista o no la cuenta, para no revelar quién está registrado. Cada nuevo
     * enlace anula los anteriores; como mucho 3 por hora y cuenta.
     */
    public static function requestReset(mixed $emailIn): void
    {
        $email = is_string($emailIn) ? mb_strtolower(trim($emailIn)) : '';
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            V::invalid('email', 'El correo electrónico no es válido.');
        }
        $user = Db::one("SELECT id, name, email FROM users WHERE email = ? AND role = 'client' AND active", [$email]);
        if ($user === null) {
            return;
        }
        $now = Clock::now();
        $recent = (int) Db::value(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?',
            [$user['id'], Clock::sql(Clock::plus($now, -60))]
        );
        if ($recent >= self::RESET_PER_HOUR) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        Db::tx(function () use ($user, $token, $now) {
            Db::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [Clock::sql($now), $user['id']]);
            Db::exec(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
                [$user['id'], hash('sha256', $token), Clock::sql(Clock::plus($now, self::RESET_TTL_MIN)), Clock::sql($now)]
            );
            // Página de la web que pide la contraseña nueva (plantilla front.reset).
            $link = Http::frontUrl('reset', ['token' => $token]);
            Mail::queue($user['email'], 'Restablecer la contraseña',
                "Hola, {$user['name']}:\n\n"
                . "Has pedido restablecer la contraseña de MesaLista. Abre este enlace en los próximos 30 minutos; solo funciona una vez:\n\n"
                . "{$link}\n\n"
                . "Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.\n");
        });
    }

    /** Cambia la contraseña con el enlace y cierra todas las sesiones de la cuenta. */
    public static function resetPassword(array $in): void
    {
        V::only($in, ['token', 'password']);
        $token = $in['token'] ?? null;
        $invalid = fn() => fail(400, 'reset_invalid', 'El enlace no es válido o ha caducado. Pide otro desde «¿Has olvidado tu contraseña?».');
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            $invalid();
        }
        $password = self::checkPasswordPolicy($in['password'] ?? null);
        Db::tx(function () use ($token, $password, $invalid) {
            $row = Db::one('SELECT * FROM password_resets WHERE token_hash = ? FOR UPDATE', [hash('sha256', $token)]);
            if ($row === null || $row['used_at'] !== null || Clock::parse($row['expires_at']) <= Clock::now()) {
                $invalid();
            }
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($password), $row['user_id']]);
            Db::exec('UPDATE password_resets SET used_at = ? WHERE id = ?', [Clock::sql(), $row['id']]);
            Db::exec('DELETE FROM sessions WHERE user_id = ?', [$row['user_id']]);
        });
    }

    public static function verifyPassword(int $userId, mixed $password): bool
    {
        $hash = Db::value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        return is_string($password) && is_string($hash) && password_verify($password, $hash);
    }
}
