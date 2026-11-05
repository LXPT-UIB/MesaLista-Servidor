<?php
declare(strict_types=1);

/**
 * Hora del servidor (zona de Madrid), fija durante toda la petición.
 * Todas las comprobaciones de tiempo pasan por aquí (regla 2).
 *
 * En las pruebas, con 'test_clock' activado, la cabecera X-Test-Now
 * ("2026-11-07 20:50:00") simula otra hora.
 */
final class Clock
{
    private static ?DateTimeImmutable $now = null;

    public static function now(): DateTimeImmutable
    {
        if (self::$now === null) {
            $now = new DateTimeImmutable('now');
            $simulated = $_SERVER['HTTP_X_TEST_NOW'] ?? null;
            if ($simulated !== null && config('test_clock') === true) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $simulated);
                if ($parsed !== false) {
                    $now = $parsed;
                }
            }
            self::$now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
        }
        return self::$now;
    }

    /** Para scripts de línea de comandos. */
    public static function set(DateTimeImmutable $now): void
    {
        self::$now = $now;
    }

    public static function sql(?DateTimeImmutable $t = null): string
    {
        return ($t ?? self::now())->format('Y-m-d H:i:s');
    }

    public static function parse(string $sqlDateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($sqlDateTime);
    }

    /** $t + $minutes (pueden ser negativos). */
    public static function plus(DateTimeImmutable $t, int $minutes): DateTimeImmutable
    {
        return $t->modify(sprintf('%+d minutes', $minutes));
    }
}
