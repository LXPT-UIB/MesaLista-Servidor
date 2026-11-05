<?php
declare(strict_types=1);

/**
 * Correos de reservas (RF-17, RP-05).
 *
 * Los correos se guardan en email_outbox dentro de la misma transacción que
 * el cambio de la reserva y se envían después de responder al cliente (o
 * desde bin/worker.php cada minuto), para que el proveedor no retrase la
 * respuesta de la API. El texto solo lleva nombre, fecha, hora y comensales:
 * nunca el QR, las opciones de menú ni la nota.
 *
 * Se envían con Azure Communication Services Email, por su API REST firmada
 * con la clave del recurso (no hace falta ninguna aplicación de Microsoft
 * Entra). Sin cadena de conexión en config.php, se quedan en la cola.
 */
final class Mail
{
    public static bool $queued = false;

    // Dominio gratuito de Azure: 5 correos por minuto y 10 por hora.
    private const PER_RUN = 5;
    private const SEND_API = '/emails:send?api-version=2023-03-31';

    private const DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** «sábado 7 de noviembre de 2026 a las 21:00» */
    public static function when(DateTimeImmutable $start): string
    {
        return sprintf(
            '%s %d de %s de %s a las %s',
            self::DAYS[(int) $start->format('N') - 1],
            (int) $start->format('j'),
            self::MONTHS[(int) $start->format('n') - 1],
            $start->format('Y'),
            $start->format('H:i')
        );
    }

    /**
     * $event: confirmed | modified | cancelled_holder | cancelled_restaurant |
     * group_accepted (pendiente de pago) | payment_expired. $extra se añade al
     * final del primer párrafo (p. ej. el aviso de la devolución de la señal).
     */
    public static function queueReservation(string $event, string $to, string $name, array $reservation, string $extra = ''): void
    {
        $party = (int) $reservation['party_size'];
        $people = $party === 1 ? '1 comensal' : "{$party} comensales";

        [$subject, $intro] = match ($event) {
            'confirmed'            => ['Reserva confirmada', 'Tu reserva está confirmada.'],
            'modified'             => ['Reserva modificada', 'Hemos aplicado el cambio en tu reserva. El QR anterior ya no es válido: usa el que aparece ahora en la app.'],
            'cancelled_holder'     => ['Reserva cancelada', 'Tu reserva se ha cancelado.'],
            'cancelled_restaurant' => ['Reserva cancelada por el restaurante', 'Lo sentimos: el restaurante ha tenido que cancelar tu reserva. Si quieres, puedes reservar otra hora desde la app.'],
            'group_accepted'       => ['Solicitud de grupo aceptada', 'El restaurante ha aceptado tu solicitud de grupo. Para confirmarla, paga la señal desde la app MesaLista.'],
            'payment_expired'      => ['Reserva sin confirmar', 'Se ha terminado el plazo para pagar la señal y la reserva se ha liberado. Si aún quieres venir, envía otra solicitud desde la app.'],
        };
        if ($extra !== '') {
            $intro .= ' ' . $extra;
        }
        $body = "Hola, {$name}:\n\n{$intro}\n\n"
            . 'Fecha y hora: ' . self::when(Clock::parse($reservation['start_at'])) . "\n"
            . "Comensales: {$people}\n\n"
            . "Puedes consultar tus reservas en la app MesaLista.\n";
        self::queue($to, $subject, $body);
        // El mismo cambio llega también como aviso push a la app del cliente.
        Push::queueReservation($event, $to, $reservation, $subject);
    }

    /** Correo cualquiera a la cola (el asunto lleva delante «MesaLista · »). */
    public static function queue(string $to, string $subject, string $body): void
    {
        Db::exec(
            'INSERT INTO email_outbox (to_email, subject, body, created_at) VALUES (?, ?, ?, ?)',
            [$to, "MesaLista · {$subject}", $body, Clock::sql()]
        );
        self::$queued = true;
    }

    /** Envía los correos pendientes. Devuelve cuántos se han enviado. */
    public static function flush(int $limit = 20): int
    {
        if (config('mail.enabled') !== true || (string) config('mail.connection_string') === '') {
            return 0;
        }
        $claim = bin2hex(random_bytes(16));
        // Reserva un lote; un lote abandonado se puede reclamar a los 5 minutos.
        Db::exec(
            'UPDATE email_outbox SET claim = ?, claimed_at = LOCALTIMESTAMP(0)
              WHERE id IN (SELECT id FROM email_outbox
                            WHERE attempts < 5 AND (claim IS NULL OR claimed_at < LOCALTIMESTAMP - interval \'5 minutes\')
                            ORDER BY id LIMIT ' . max(1, min($limit, self::PER_RUN)) . '
                            FOR UPDATE SKIP LOCKED)',
            [$claim]
        );
        $rows = Db::all('SELECT id, to_email, subject, body FROM email_outbox WHERE claim = ? ORDER BY id', [$claim]);
        if (!$rows) {
            return 0;
        }

        $sent = 0;
        foreach ($rows as $row) {
            try {
                self::send($row['to_email'], $row['subject'], $row['body']);
                Db::exec('DELETE FROM email_outbox WHERE id = ?', [$row['id']]);
                $sent++;
            } catch (MailThrottled $e) {
                // Azure pide esperar: el resto del lote vuelve a la cola sin
                // gastar intentos y sale en la siguiente vuelta del worker.
                Db::exec('UPDATE email_outbox SET claim = NULL, last_error = ? WHERE claim = ?',
                    [mb_substr($e->getMessage(), 0, 255), $claim]);
                break;
            } catch (Throwable $e) {
                // El error no incluye el contenido del mensaje (RS-11).
                Db::exec(
                    'UPDATE email_outbox SET attempts = attempts + 1, claim = NULL, last_error = ? WHERE id = ?',
                    [mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 255), $row['id']]
                );
            }
        }
        return $sent;
    }

    /**
     * Envía un correo: POST /emails:send de Azure Communication Services,
     * firmado con HMAC-SHA256 y la clave del recurso. 202 = aceptado para
     * enviar. El nombre visible del remitente se cambia en el portal de Azure
     * (dominio › MailFrom addresses), no aquí.
     */
    public static function send(string $to, string $subject, string $body): void
    {
        [$endpoint, $key] = self::connection((string) config('mail.connection_string'));
        $json = json_encode([
            'senderAddress' => (string) config('mail.sender'),
            'recipients' => ['to' => [['address' => $to]]],
            'content' => ['subject' => $subject, 'plainText' => $body],
            'userEngagementTrackingDisabled' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $url = parse_url($endpoint);
        $host = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $hash = base64_encode(hash('sha256', $json, true));
        $signature = base64_encode(hash_hmac('sha256', "POST\n" . self::SEND_API . "\n{$date};{$host};{$hash}", $key, true));

        $ch = curl_init($endpoint . self::SEND_API);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "x-ms-date: {$date}",
                "x-ms-content-sha256: {$hash}",
                "Authorization: HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature={$signature}",
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('Azure: ' . curl_error($ch));
        }
        if ($status === 202) {
            return;
        }
        $data = json_decode((string) $raw, true);
        $error = trim('Azure HTTP ' . $status . ' ' . ($data['error']['code'] ?? '') . ' ' . ($data['error']['message'] ?? ''));
        if ($status === 429) {
            throw new MailThrottled($error);
        }
        throw new RuntimeException($error);
    }

    /**
     * «endpoint=https://<recurso>.<zona>.communication.azure.com/;accesskey=<clave>»
     * → [endpoint sin la barra final, clave en binario]. El error nunca lleva la clave.
     */
    private static function connection(string $connection): array
    {
        $parts = [];
        foreach (explode(';', $connection) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[strtolower(trim($name))] = trim($value);
        }
        $endpoint = rtrim($parts['endpoint'] ?? '', '/');
        $key = base64_decode($parts['accesskey'] ?? '', true);
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        // Solo HTTPS (RS-01); HTTP únicamente hacia esta máquina, para las pruebas.
        $scheme = (string) parse_url($endpoint, PHP_URL_SCHEME);
        $secure = $scheme === 'https' || ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true));
        if ($host === '' || !$secure || $key === false || $key === '') {
            throw new RuntimeException('mail.connection_string no es una cadena de conexión de Azure válida');
        }
        return [$endpoint, $key];
    }
}

/** Azure ha rechazado el envío por el límite de correos (HTTP 429): se reintenta sin gastar intentos. */
final class MailThrottled extends RuntimeException
{
}
