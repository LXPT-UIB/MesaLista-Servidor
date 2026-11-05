<?php
declare(strict_types=1);

/**
 * RF-44: señal de los grupos grandes con PayPal en modo de pruebas.
 *
 * Reglas (decididas antes de implementarlo, como pide el documento):
 *  - Solo pagan señal las solicitudes de grupo aceptadas: deposit_per_person ×
 *    comensales. La reserva nace «pendiente de pago» y retiene sus mesas
 *    deposit_hold_min minutos; si no se paga, el sistema la cancela.
 *  - Al volver de PayPal, el servidor confirma el cobro con PayPal (capture)
 *    antes de dar la reserva por confirmada. Si el plazo ya terminó o la
 *    reserva ya está pagada, no se cobra.
 *  - Sin cobros dobles: cada llamada lleva su PayPal-Request-Id (PayPal no
 *    repite la operación) y order_id es único en la base de datos.
 *  - Se devuelve si cancela el titular dentro del plazo o cancela el
 *    restaurante; fuera de plazo, no. Las devoluciones se apuntan en la misma
 *    transacción que la cancelación y se envían después (y por cron si fallan).
 */
final class Payments
{
    public const CURRENCY = 'EUR';
    public static bool $refundsQueued = false;

    public static function mode(): string
    {
        return config('paypal.mode', 'sandbox') === 'fake' ? 'fake' : 'paypal';
    }

    /** ¿Hay pasarela configurada? La simulada solo existe en el servidor de pruebas. */
    public static function configured(): bool
    {
        if (self::mode() === 'fake') {
            return config('test_clock') === true;
        }
        return (string) config('paypal.client_id', '') !== '' && (string) config('paypal.secret', '') !== '';
    }

    /** Se pide señal si hay importe por persona y pasarela. */
    public static function enabled(): bool
    {
        return Settings::get('deposit_per_person') > 0 && self::configured();
    }

    public static function gateway(): PaymentGateway
    {
        return self::mode() === 'fake' ? new FakeGateway() : new PayPalGateway();
    }

    /** Pago capturado de una reserva, o null. */
    public static function capturedFor(int $reservationId): ?array
    {
        return Db::one("SELECT * FROM payments WHERE reservation_id = ? AND status IN ('captured','refund_pending','refunded') ORDER BY id DESC LIMIT 1", [$reservationId]);
    }

    /**
     * El titular empieza el pago: crea un pedido en PayPal y devuelve la
     * dirección a la que hay que llevarle para que lo apruebe. $from dice si
     * paga desde la web o desde la app, para devolverle allí al terminar.
     */
    public static function start(array $user, int $reservationId, string $from = 'web'): string
    {
        if (!in_array($from, ['web', 'app'], true)) {
            V::invalid('from', 'Debe ser «web» o «app».');
        }
        $r = Booking::getOwned((int) $user['id'], $reservationId);
        if ($r['status'] !== 'pending_payment') {
            fail(409, 'not_payable', 'Esta reserva no tiene ningún pago pendiente.');
        }
        if (Clock::parse($r['payment_due_at']) <= Clock::now()) {
            fail(409, 'payment_expired', 'Se ha terminado el plazo para pagar la señal.');
        }
        if (!self::configured()) {
            fail(503, 'payments_off', 'El pago con PayPal no está disponible ahora. Llama al restaurante.');
        }
        $attempt = (int) Db::value('SELECT COUNT(*) FROM payments WHERE reservation_id = ?', [$reservationId]) + 1;
        $order = self::gateway()->createOrder(
            (int) $r['deposit_cents'],
            self::CURRENCY,
            (string) $reservationId,
            Http::apiUrl() . '/payments/return',
            Http::frontUrl("payment_$from", ['id' => $reservationId, 'result' => 'cancelado']),
            "mesalista-r{$reservationId}-o{$attempt}"
        );
        Db::exec(
            "INSERT INTO payments (reservation_id, provider, order_id, amount_cents, currency, status, return_to, created_at)
             VALUES (?, ?, ?, ?, ?, 'created', ?, ?)",
            [$reservationId, self::mode(), $order['id'], (int) $r['deposit_cents'], self::CURRENCY, $from, Clock::sql()]
        );
        return $order['approve_url'];
    }

    /**
     * Vuelta desde PayPal (?token=<pedido>). Cobra solo si la reserva sigue
     * pendiente y dentro de plazo. Devuelve la dirección de la web o de la app
     * (según desde dónde se pagó) a la que redirigir. No necesita sesión: solo
     * puede cobrar un pedido que creó este servidor y que el comprador ha aprobado.
     */
    public static function handleReturn(?string $orderId): string
    {
        if ($orderId === null || !preg_match('/^[A-Za-z0-9-]{6,64}$/', $orderId)) {
            return Http::frontUrl('payment_unknown');
        }
        $p = Db::one('SELECT * FROM payments WHERE order_id = ?', [$orderId]);
        if ($p === null) {
            return Http::frontUrl('payment_unknown');
        }
        $rid = (int) $p['reservation_id'];
        $result = Db::tx(function () use ($p, $rid) {
            $r = Db::one('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$rid]);
            $p = Db::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$p['id']]);
            if ($p['status'] === 'captured') {
                return 'ok';
            }
            if ($p['status'] !== 'created') {
                return 'error';
            }
            // Plazo terminado o reserva ya pagada con otro pedido: no se cobra.
            if ($r['status'] !== 'pending_payment' || Clock::parse($r['payment_due_at']) <= Clock::now()) {
                Db::exec("UPDATE payments SET status = 'failed', last_error = 'reserva no pendiente o fuera de plazo' WHERE id = ?", [$p['id']]);
                return $r['status'] === 'confirmed' ? 'ok' : 'caducado';
            }
            try {
                $capture = self::gateway()->captureOrder($p['order_id'], "mesalista-capture-p{$p['id']}");
            } catch (Throwable $e) {
                Db::exec("UPDATE payments SET status = 'failed', last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $p['id']]);
                return 'error';
            }
            if ($capture['status'] !== 'COMPLETED' || $capture['amount_cents'] !== (int) $p['amount_cents']) {
                Db::exec("UPDATE payments SET status = 'failed', last_error = ? WHERE id = ?", ['captura ' . $capture['status'], $p['id']]);
                return 'error';
            }
            Db::exec("UPDATE payments SET status = 'captured', capture_id = ? WHERE id = ?", [$capture['capture_id'], $p['id']]);
            Db::exec("UPDATE reservations SET status = 'confirmed', payment_due_at = NULL WHERE id = ?", [$rid]);
            $u = Db::one('SELECT name, email FROM users WHERE id = ?', [$r['user_id']]);
            if ($u !== null) {
                Mail::queueReservation('confirmed', $u['email'], $u['name'], $r,
                    'Hemos recibido la señal de ' . self::euros((int) $p['amount_cents']) . '.');
            }
            return 'ok';
        });
        return Http::frontUrl('payment_' . $p['return_to'], ['id' => $rid, 'result' => $result]);
    }

    /**
     * Apunta la devolución de la señal pagada de una reserva (dentro de la
     * transacción de la cancelación). Devuelve el importe, o 0 si no había.
     */
    public static function markRefund(int $reservationId): int
    {
        $p = Db::one("SELECT id, amount_cents FROM payments WHERE reservation_id = ? AND status = 'captured' FOR UPDATE", [$reservationId]);
        if ($p === null) {
            return 0;
        }
        Db::exec("UPDATE payments SET status = 'refund_pending' WHERE id = ?", [$p['id']]);
        self::$refundsQueued = true;
        return (int) $p['amount_cents'];
    }

    /** Envía a PayPal las devoluciones pendientes. Devuelve cuántas se han hecho. */
    public static function processRefunds(int $limit = 20): int
    {
        if (!self::configured()) {
            return 0;
        }
        $done = 0;
        foreach (Db::all("SELECT * FROM payments WHERE status = 'refund_pending' ORDER BY id LIMIT " . max(1, $limit)) as $p) {
            try {
                self::gateway()->refundCapture((string) $p['capture_id'], (int) $p['amount_cents'], $p['currency'], "mesalista-refund-p{$p['id']}");
                Db::exec("UPDATE payments SET status = 'refunded', last_error = NULL WHERE id = ? AND status = 'refund_pending'", [$p['id']]);
                $done++;
            } catch (Throwable $e) {
                Db::exec('UPDATE payments SET last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 255), $p['id']]);
            }
        }
        return $done;
    }

    public static function euros(int $cents): string
    {
        return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.') . ' €';
    }
}

/** Proveedor de pagos: crear pedido, cobrarlo y devolverlo. */
interface PaymentGateway
{
    /** @return array{id: string, approve_url: string} */
    public function createOrder(int $cents, string $currency, string $reference, string $returnUrl, string $cancelUrl, string $requestId): array;

    /** @return array{status: string, capture_id: string, amount_cents: int} */
    public function captureOrder(string $orderId, string $requestId): array;

    public function refundCapture(string $captureId, int $cents, string $currency, string $requestId): void;
}

/**
 * PayPal REST (Orders v2), siempre en el entorno sandbox: con estas claves no
 * se puede mover dinero real. Las claves van en config.php (paypal.client_id
 * y paypal.secret) y nunca salen en los registros (RS-11).
 */
final class PayPalGateway implements PaymentGateway
{
    private const BASE = 'https://api-m.sandbox.paypal.com';

    private function token(): string
    {
        static $token = null;
        if ($token !== null) {
            return $token;
        }
        $ch = curl_init(self::BASE . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => config('paypal.client_id') . ':' . config('paypal.secret'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200 || !isset($data['access_token'])) {
            throw new RuntimeException('PayPal: no se ha podido autenticar (revisa client_id y secret)');
        }
        return $token = $data['access_token'];
    }

    private function call(string $path, array $body, string $requestId): array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body === [] ? new stdClass() : $body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token(),
                'PayPal-Request-Id: ' . $requestId,   // idempotencia: PayPal no repite la operación
                'Prefer: return=representation',
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status >= 400 || !is_array($data)) {
            $name = is_array($data) ? ($data['name'] ?? $data['error'] ?? '') : curl_error($ch);
            throw new RuntimeException("PayPal {$path}: HTTP {$status} {$name}");
        }
        return $data;
    }

    public function createOrder(int $cents, string $currency, string $reference, string $returnUrl, string $cancelUrl, string $requestId): array
    {
        $order = $this->call('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'reserva-' . $reference,
                'custom_id'    => $reference,
                'description'  => 'Señal de la reserva de grupo en MesaLista',
                'amount'       => ['currency_code' => $currency, 'value' => number_format($cents / 100, 2, '.', '')],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name'          => 'MesaLista',
                'locale'              => 'es-ES',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'PAY_NOW',
                'return_url'          => $returnUrl,
                'cancel_url'          => $cancelUrl,
            ]]],
        ], $requestId);
        foreach ($order['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                return ['id' => (string) $order['id'], 'approve_url' => (string) $link['href']];
            }
        }
        throw new RuntimeException('PayPal: el pedido no trae enlace de aprobación');
    }

    public function captureOrder(string $orderId, string $requestId): array
    {
        $data = $this->call('/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [], $requestId);
        $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
        return [
            'status'       => $capture['status'] ?? ($data['status'] ?? 'UNKNOWN'),
            'capture_id'   => (string) ($capture['id'] ?? ''),
            'amount_cents' => (int) round(((float) ($capture['amount']['value'] ?? 0)) * 100),
        ];
    }

    public function refundCapture(string $captureId, int $cents, string $currency, string $requestId): void
    {
        $data = $this->call('/v2/payments/captures/' . rawurlencode($captureId) . '/refund', [
            'amount' => ['currency_code' => $currency, 'value' => number_format($cents / 100, 2, '.', '')],
        ], $requestId);
        if (!in_array($data['status'] ?? '', ['COMPLETED', 'PENDING'], true)) {
            throw new RuntimeException('PayPal: devolución ' . ($data['status'] ?? 'desconocida'));
        }
    }
}

/**
 * Pasarela simulada para las pruebas automáticas (solo con test_clock):
 * «aprobar» redirige directamente a la vuelta, y cobrar y devolver siempre
 * salen bien.
 */
final class FakeGateway implements PaymentGateway
{
    public function createOrder(int $cents, string $currency, string $reference, string $returnUrl, string $cancelUrl, string $requestId): array
    {
        $id = 'FAKE-' . bin2hex(random_bytes(8));
        return ['id' => $id, 'approve_url' => Http::apiUrl() . '/payments/fake-approve?token=' . $id];
    }

    public function captureOrder(string $orderId, string $requestId): array
    {
        $amount = (int) Db::value('SELECT amount_cents FROM payments WHERE order_id = ?', [$orderId]);
        return ['status' => 'COMPLETED', 'capture_id' => 'FAKECAP-' . substr($orderId, 5), 'amount_cents' => $amount];
    }

    public function refundCapture(string $captureId, int $cents, string $currency, string $requestId): void
    {
    }
}
