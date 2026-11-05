<?php
declare(strict_types=1);

/**
 * Avisos push en la app con Firebase Cloud Messaging (API HTTP v1).
 *
 * Cada cambio que envía un correo de reserva (Mail::queueReservation) o el de
 * grupo no aceptado envía también un aviso push a cada dispositivo que el
 * cliente ha registrado con POST /me/devices. Como los correos, los avisos se
 * guardan en push_outbox dentro de la transacción del cambio y se envían
 * después de responder (o desde bin/worker.php cada minuto). Solo llevan el
 * asunto, la fecha, la hora y los comensales: nunca el QR, las opciones de
 * menú ni la nota.
 *
 * El servidor se identifica ante Google con la cuenta de servicio del proyecto
 * de Firebase (config.php, «fcm»): firma un JWT con su clave privada (RS256),
 * lo cambia por un token de acceso OAuth 2.0 y llama con él a
 * POST /v1/projects/<proyecto>/messages:send. Sin cuenta de servicio, los
 * avisos esperan en la cola y a la hora se descartan.
 */
final class Push
{
    public static bool $queued = false;

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const API = 'https://fcm.googleapis.com';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const MAX_AGE_MIN = 60;
    private const MAX_ATTEMPTS = 5;

    /** Token de acceso de Google en memoria: ['token' => …, 'expires' => marca de tiempo]. */
    private static ?array $access = null;

    // ------------------------------------------------------------------
    // Dispositivos
    // ------------------------------------------------------------------

    /**
     * Registra el dispositivo del cliente (la app lo hace al abrirse y cuando
     * FCM le da un token nuevo). Si el token era de otra cuenta, pasa a esta y
     * se descartan los avisos que tenía pendientes. Devuelve true si es nuevo.
     */
    public static function register(array $user, array $in): bool
    {
        V::only($in, ['token', 'platform']);
        $token = self::deviceToken($in['token'] ?? null);
        $platform = $in['platform'] ?? null;
        if (!in_array($platform, ['android', 'ios'], true)) {
            V::invalid('platform', 'Debe ser «android» o «ios».');
        }
        return Db::tx(function () use ($user, $token, $platform) {
            $now = Clock::sql();
            $row = Db::one('SELECT id, user_id FROM push_devices WHERE token = ? FOR UPDATE', [$token]);
            if ($row === null) {
                Db::exec(
                    'INSERT INTO push_devices (user_id, token, platform, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?)',
                    [$user['id'], $token, $platform, $now, $now]
                );
                return true;
            }
            if ((int) $row['user_id'] !== (int) $user['id']) {
                Db::exec('DELETE FROM push_outbox WHERE device_id = ?', [$row['id']]);
            }
            Db::exec('UPDATE push_devices SET user_id = ?, platform = ?, last_seen_at = ? WHERE id = ?',
                [$user['id'], $platform, $now, $row['id']]);
            return false;
        });
    }

    /** El cliente deja de recibir avisos en ese dispositivo (p. ej. al cerrar sesión en la app). */
    public static function unregister(array $user, array $in): void
    {
        V::only($in, ['token']);
        Db::exec('DELETE FROM push_devices WHERE token = ? AND user_id = ?', [self::deviceToken($in['token'] ?? null), $user['id']]);
    }

    private static function deviceToken(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[A-Za-z0-9:_.\-]{20,512}$/', $v)) {
            V::invalid('token', 'El token del dispositivo no es válido.');
        }
        return $v;
    }

    // ------------------------------------------------------------------
    // Cola
    // ------------------------------------------------------------------

    /** Aviso de un cambio de reserva; lo llama Mail::queueReservation con el mismo asunto. */
    public static function queueReservation(string $event, string $email, array $reservation, string $title): void
    {
        $party = (int) $reservation['party_size'];
        $body = ucfirst(Mail::when(Clock::parse($reservation['start_at'])))
            . ' · ' . ($party === 1 ? '1 comensal' : "{$party} comensales");
        $data = ['type' => $event];
        if (isset($reservation['id'])) {
            $data['reservation_id'] = (string) $reservation['id'];
        }
        self::queueForEmail($email, $title, $body, $data);
    }

    /** Aviso para todos los dispositivos del cliente con ese correo (si tiene alguno). */
    public static function queueForEmail(string $email, string $title, string $body, array $data = []): void
    {
        $json = json_encode((object) array_map('strval', $data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $n = Db::exec(
            "INSERT INTO push_outbox (device_id, title, body, data, created_at)
             SELECT d.id, ?, ?, ?::jsonb, ? FROM push_devices d JOIN users u ON u.id = d.user_id
              WHERE u.email = ? AND u.role = 'client'
              ORDER BY d.id",
            [mb_substr($title, 0, 150), mb_substr($body, 0, 255), $json, Clock::sql(), $email]
        );
        if ($n > 0) {
            self::$queued = true;
        }
    }

    // ------------------------------------------------------------------
    // Envío
    // ------------------------------------------------------------------

    public static function configured(): bool
    {
        $c = config('fcm');
        return is_array($c) && ($c['enabled'] ?? false) === true && ($c['project_id'] ?? '') !== ''
            && ($c['client_email'] ?? '') !== '' && ($c['private_key'] ?? '') !== '';
    }

    /** Envía los avisos pendientes. Devuelve cuántos se han enviado. */
    public static function flush(int $limit = 50): int
    {
        if (!self::configured()) {
            return 0;
        }
        // Un aviso de hace más de una hora ya no sirve: se descarta sin enviarlo.
        Db::exec('DELETE FROM push_outbox WHERE created_at < ?', [Clock::sql(Clock::plus(Clock::now(), -self::MAX_AGE_MIN))]);
        $claim = bin2hex(random_bytes(16));
        Db::exec(
            "UPDATE push_outbox SET claim = ?, claimed_at = LOCALTIMESTAMP(0)
              WHERE id IN (SELECT id FROM push_outbox
                            WHERE attempts < ? AND (claim IS NULL OR claimed_at < LOCALTIMESTAMP - interval '5 minutes')
                            ORDER BY id LIMIT " . max(1, $limit) . '
                            FOR UPDATE SKIP LOCKED)',
            [$claim, self::MAX_ATTEMPTS]
        );
        $rows = Db::all(
            'SELECT o.id, o.device_id, o.title, o.body, o.data, d.token
               FROM push_outbox o JOIN push_devices d ON d.id = o.device_id
              WHERE o.claim = ? ORDER BY o.id',
            [$claim]
        );
        if (!$rows) {
            return 0;
        }
        try {
            self::accessToken();
        } catch (Throwable $e) {
            // Sin token de acceso no se puede enviar ninguno: todos gastan un intento.
            Db::exec('UPDATE push_outbox SET attempts = attempts + 1, claim = NULL, last_error = ? WHERE claim = ?',
                [mb_substr($e->getMessage(), 0, 255), $claim]);
            return 0;
        }
        $sent = 0;
        foreach ($rows as $r) {
            try {
                self::send($r['token'], $r['title'], $r['body'], json_decode((string) $r['data'], true) ?: []);
                Db::exec('DELETE FROM push_outbox WHERE id = ?', [$r['id']]);
                $sent++;
            } catch (PushGone) {
                // FCM ya no conoce el dispositivo (app desinstalada o token caducado): se olvida.
                Db::exec('DELETE FROM push_devices WHERE id = ?', [$r['device_id']]);
            } catch (PushThrottled $e) {
                // Límite de FCM o servicio caído: el resto vuelve a la cola sin gastar intentos.
                Db::exec('UPDATE push_outbox SET claim = NULL, last_error = ? WHERE claim = ?', [mb_substr($e->getMessage(), 0, 255), $claim]);
                break;
            } catch (Throwable $e) {
                Db::exec('UPDATE push_outbox SET attempts = attempts + 1, claim = NULL, last_error = ? WHERE id = ?',
                    [mb_substr($e->getMessage(), 0, 255), $r['id']]);
            }
        }
        return $sent;
    }

    /** Envía un aviso a un dispositivo. Lanza PushGone, PushThrottled u otra excepción. */
    public static function send(string $deviceToken, string $title, string $body, array $data = []): void
    {
        $c = config('fcm');
        $url = self::url((string) ($c['api'] ?? self::API)) . '/v1/projects/' . rawurlencode((string) $c['project_id']) . '/messages:send';
        $payload = ['message' => [
            'token'        => $deviceToken,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => (object) array_map('strval', $data),
            'android'      => ['priority' => 'high'],
        ]];
        [$status, $raw] = self::post($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type: application/json', 'Authorization: Bearer ' . self::accessToken()]);
        if ($status === 200) {
            return;
        }
        $error = json_decode($raw, true)['error'] ?? [];
        $code = '';
        foreach ((array) ($error['details'] ?? []) as $d) {
            $code = is_array($d) && is_string($d['errorCode'] ?? null) ? $d['errorCode'] : $code;
        }
        $message = "FCM HTTP {$status} " . ($code !== '' ? $code : (string) ($error['status'] ?? ''));
        if ($status === 401) {
            self::$access = null; // el token de acceso ya no vale: la próxima vez se pide otro
        }
        if ($status === 404 || $code === 'UNREGISTERED' || $code === 'SENDER_ID_MISMATCH') {
            throw new PushGone($message);
        }
        if ($status === 429 || $status >= 500) {
            throw new PushThrottled($message);
        }
        throw new RuntimeException($message);
    }

    /** Comprueba la cuenta de servicio pidiendo un token de acceso (lo usa configure_fcm.php). */
    public static function checkCredentials(): void
    {
        self::$access = null;
        self::accessToken();
    }

    /** Token de acceso OAuth 2.0 de la cuenta de servicio; dura una hora. */
    private static function accessToken(): string
    {
        if (self::$access !== null && self::$access['expires'] > time() + 60) {
            return self::$access['token'];
        }
        $c = config('fcm');
        $uri = self::url((string) ($c['token_uri'] ?? self::TOKEN_URI));
        $now = time();
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64('{"alg":"RS256","typ":"JWT"}') . '.' . $b64(json_encode([
            'iss' => (string) $c['client_email'], 'scope' => self::SCOPE, 'aud' => $uri,
            'iat' => $now, 'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private((string) $c['private_key']);
        if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM: la clave privada de la cuenta de servicio no es válida');
        }
        [$status, $raw] = self::post($uri, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . $b64($signature),
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        $data = json_decode($raw, true);
        if ($status !== 200 || !is_string($data['access_token'] ?? null)) {
            $why = is_string($data['error'] ?? null) ? $data['error'] : '';
            throw new RuntimeException("FCM: Google no ha dado el token de acceso (HTTP {$status} {$why})");
        }
        self::$access = ['token' => $data['access_token'], 'expires' => $now + (int) ($data['expires_in'] ?? 3600)];
        return self::$access['token'];
    }

    /** Solo HTTPS; http únicamente hacia 127.0.0.1 o localhost (servidor falso de las pruebas). */
    private static function url(string $url): string
    {
        $p = parse_url($url);
        $scheme = $p['scheme'] ?? '';
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true))) {
            throw new RuntimeException('FCM: la dirección de Google debe ser https');
        }
        return rtrim($url, '/');
    }

    /** POST con cURL: [código HTTP, cuerpo]. */
    private static function post(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('FCM: ' . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw];
    }
}

/** FCM ya no conoce el dispositivo (404 UNREGISTERED): se borra. */
final class PushGone extends RuntimeException {}

/** Límite de FCM o servicio no disponible (429, 5xx): se reintenta sin gastar intentos. */
final class PushThrottled extends RuntimeException {}
