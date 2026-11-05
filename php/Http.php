<?php
declare(strict_types=1);

/** Error que la API devuelve como {"error": {"code", "message", ...}}. */
final class ApiError extends Exception
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}

function fail(int $status, string $code, string $message, array $extra = []): never
{
    throw new ApiError($status, $code, $message, $extra);
}

final class Http
{
    private const MAX_BODY = 16384;

    /** Cuerpo JSON de la petición como array asociativo. */
    public static function body(): array
    {
        static $body = null;
        if ($body !== null) {
            return $body;
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if ($raw === false || $raw === '') {
            return $body = [];
        }
        if (strlen($raw) > self::MAX_BODY) {
            fail(413, 'too_large', 'La petición es demasiado grande.');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            fail(400, 'bad_json', 'El cuerpo de la petición no es JSON válido.');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            fail(400, 'bad_json', 'El cuerpo de la petición debe ser un objeto JSON.');
        }
        return $body = $data;
    }

    public static function query(string $name): ?string
    {
        $v = $_GET[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Dirección pública de la API (https://<dominio>/api/v1), para PayPal. */
    public static function apiUrl(): string
    {
        if (config('require_https', true)) {
            return 'https://' . config('domain') . '/api/v1';
        }
        // Solo en desarrollo (sin HTTPS): el host con el que se ha llamado.
        return 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/v1';
    }

    /**
     * Dirección de la web o de la app a partir de una plantilla de config.php
     * (front.reset, front.payment_web…), con {token}, {id} o {result}
     * sustituidos. Solo se redirige a estas plantillas: nunca a una dirección
     * que llegue en la petición.
     */
    public static function frontUrl(string $key, array $vars = []): string
    {
        $template = config("front.$key");
        if (!is_string($template) || $template === '') {
            throw new RuntimeException("falta front.$key en config.php");
        }
        $pairs = [];
        foreach ($vars as $name => $value) {
            $pairs['{' . $name . '}'] = rawurlencode((string) $value);
        }
        return strtr($template, $pairs);
    }

    /** Token de la cabecera «Authorization: Bearer <token>», o null. */
    public static function bearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', (string) $header, $m) ? $m[1] : null;
    }

    /**
     * IP del cliente. La web llama a la API desde su propio PHP: si la petición
     * llega de una dirección de confianza (trusted_proxies), la IP real del
     * navegador es la última de X-Forwarded-For que no sea de confianza. Así el
     * bloqueo por intentos fallidos (RS-06) sigue siendo por persona.
     */
    public static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) config('trusted_proxies', []);
        if (!in_array($remote, $trusted, true) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!in_array($ip, $trusted, true)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** Petición automática del panel (sondeo), que no cuenta como actividad (RS-05). */
    public static function isBackground(): bool
    {
        return ($_SERVER['HTTP_X_BACKGROUND'] ?? '') === '1';
    }
}

/** Enrutador mínimo: "/reservations/{id}" captura un entero positivo. */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[1-9][0-9]{0,9})', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(string $method, string $path): array
    {
        $allowed = false;
        foreach ($this->routes as [$m, $regex, $handler]) {
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            if ($m !== $method) {
                $allowed = true;
                continue;
            }
            $params = array_map('intval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY));
            return $handler($params);
        }
        if ($allowed) {
            fail(405, 'method_not_allowed', 'Método no permitido.');
        }
        fail(404, 'not_found', 'Recurso no encontrado.');
    }
}
