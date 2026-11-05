<?php
declare(strict_types=1);

/**
 * Contenido de los QR y códigos de reserva (RF-18, RF-29, RS-07, RS-12).
 *
 * El QR lleva "ML1.<id>.<versión>.<firma>", donde la firma es un HMAC-SHA256
 * con la clave del servidor. Un QR alterado o fabricado fuera del sistema no
 * tiene una firma válida, y uno anterior a una modificación o a una
 * vinculación tiene una versión que ya no es la vigente.
 */
final class Qr
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O ni 1/I
    public const CODE_LENGTH = 10;

    public static function make(int $reservationId, int $version): string
    {
        return sprintf('ML1.%d.%d.%s', $reservationId, $version, self::sign($reservationId, $version));
    }

    /** [id, versión] si la firma es válida; null en caso contrario. */
    public static function parse(string $content): ?array
    {
        if (!preg_match('/^ML1\.([1-9][0-9]{0,9})\.([1-9][0-9]{0,9})\.([A-Za-z0-9_-]{22})$/', $content, $m)) {
            return null;
        }
        $id = (int) $m[1];
        $version = (int) $m[2];
        if (!hash_equals(self::sign($id, $version), $m[3])) {
            return null;
        }
        return [$id, $version];
    }

    private static function sign(int $id, int $version): string
    {
        $secret = (string) config('qr_secret');
        if (strlen($secret) < 32 || $secret === 'CAMBIAR') {
            throw new RuntimeException('qr_secret no está configurado');
        }
        $mac = hash_hmac('sha256', "reservation:{$id}:{$version}", $secret, true);
        return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
    }

    /** Código de reserva aleatorio de 10 caracteres (unos 50 bits). */
    public static function newCode(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    public static function normalizeCode(mixed $v): string
    {
        if (!is_string($v)) {
            V::invalid('code', 'El código de reserva no es válido.');
        }
        $code = strtoupper(preg_replace('/[\s-]/', '', $v));
        if (!preg_match('/^[' . self::ALPHABET . ']{' . self::CODE_LENGTH . '}$/', $code)) {
            V::invalid('code', 'El código de reserva debe tener 10 caracteres.');
        }
        return $code;
    }
}
