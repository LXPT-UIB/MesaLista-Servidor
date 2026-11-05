<?php
declare(strict_types=1);

/**
 * Validación de tipo, rango y longitud de los datos recibidos (RS-08).
 * Cualquier dato incorrecto termina la petición con 422 antes de tocar la
 * base de datos.
 */
final class V
{
    public static function invalid(string $field, string $message): never
    {
        fail(422, 'invalid', $message, ['field' => $field]);
    }

    public static function int(mixed $v, string $field, int $min, int $max): int
    {
        if (is_string($v) && preg_match('/^-?[0-9]{1,9}$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v)) {
            self::invalid($field, "El campo «{$field}» debe ser un número entero.");
        }
        if ($v < $min || $v > $max) {
            self::invalid($field, "El campo «{$field}» debe estar entre {$min} y {$max}.");
        }
        return $v;
    }

    public static function bool(mixed $v, string $field): bool
    {
        if (!is_bool($v)) {
            self::invalid($field, "El campo «{$field}» debe ser verdadero o falso.");
        }
        return $v;
    }

    /** Texto libre: sin caracteres de control salvo saltos de línea en $multiline. */
    public static function text(mixed $v, string $field, int $min, int $max, bool $multiline = false): string
    {
        if (!is_string($v)) {
            self::invalid($field, "El campo «{$field}» debe ser un texto.");
        }
        $v = trim(str_replace("\r\n", "\n", $v));
        if (!mb_check_encoding($v, 'UTF-8')) {
            self::invalid($field, "El campo «{$field}» no es UTF-8 válido.");
        }
        $control = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($control, $v)) {
            self::invalid($field, "El campo «{$field}» contiene caracteres no permitidos.");
        }
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            self::invalid($field, $min > 0
                ? "El campo «{$field}» debe tener entre {$min} y {$max} caracteres."
                : "El campo «{$field}» admite como máximo {$max} caracteres.");
        }
        return $v;
    }

    /** Nombre de persona: letras, espacios, apóstrofo, punto y guion. */
    public static function name(mixed $v, string $field = 'name'): string
    {
        $v = self::text($v, $field, 2, 80);
        if (!preg_match("/^[\\p{L}\\p{M}' .\\-]+$/u", $v)) {
            self::invalid($field, 'El nombre solo puede contener letras, espacios, apóstrofos, puntos y guiones.');
        }
        return preg_replace('/\s+/u', ' ', $v);
    }

    public static function email(mixed $v): string
    {
        $v = mb_strtolower(self::text($v, 'email', 3, 254));
        if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            self::invalid('email', 'El correo electrónico no es válido.');
        }
        return $v;
    }

    /** Teléfono: se guarda solo con dígitos y un «+» inicial opcional. */
    public static function phone(mixed $v, string $field = 'phone'): string
    {
        $v = self::text($v, $field, 9, 25);
        if (!preg_match('/^\+?[0-9 ().\-]+$/', $v)) {
            self::invalid($field, 'El teléfono solo puede contener dígitos, espacios, guiones y un «+» inicial.');
        }
        $normalized = (str_starts_with($v, '+') ? '+' : '') . preg_replace('/\D/', '', $v);
        $digits = strlen(ltrim($normalized, '+'));
        if ($digits < 9 || $digits > 15) {
            self::invalid($field, 'El teléfono debe tener entre 9 y 15 dígitos.');
        }
        return $normalized;
    }

    /** Fecha "AAAA-MM-DD" válida en el calendario. */
    public static function date(mixed $v, string $field = 'date'): DateTimeImmutable
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            self::invalid($field, 'La fecha debe tener el formato AAAA-MM-DD.');
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            self::invalid($field, 'La fecha no existe.');
        }
        return $d;
    }

    /** Hora "HH:MM" → minutos desde medianoche. */
    public static function time(mixed $v, string $field = 'time'): int
    {
        if (!is_string($v) || !preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $v, $m)) {
            self::invalid($field, 'La hora debe tener el formato HH:MM.');
        }
        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** Lista de identificadores enteros positivos sin repetir. */
    public static function idList(mixed $v, string $field, int $maxCount): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > $maxCount) {
            self::invalid($field, "El campo «{$field}» debe ser una lista de hasta {$maxCount} elementos.");
        }
        $ids = [];
        foreach ($v as $item) {
            $ids[] = self::int($item, $field, 1, PHP_INT_MAX >> 32);
        }
        if (count(array_unique($ids)) !== count($ids)) {
            self::invalid($field, "El campo «{$field}» tiene elementos repetidos.");
        }
        return $ids;
    }

    public static function uuid(mixed $v, string $field = 'request_id'): string
    {
        if (!is_string($v) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) {
            self::invalid($field, 'Identificador de solicitud no válido.');
        }
        return $v;
    }

    /** Comprueba que el cuerpo no trae campos desconocidos. */
    public static function only(array $body, array $allowed): void
    {
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            self::invalid((string) reset($unknown), 'Campo no permitido: «' . reset($unknown) . '».');
        }
    }
}
