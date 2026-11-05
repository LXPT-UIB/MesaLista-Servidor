<?php
declare(strict_types=1);

/** Franjas de servicio, días cerrados y horas de inicio permitidas (RF-36, regla 3). */
final class Schedule
{
    public static function isClosed(DateTimeImmutable $date): bool
    {
        return Db::value('SELECT 1 FROM closed_days WHERE day = ?', [$date->format('Y-m-d')]) !== null;
    }

    /**
     * Horas de inicio permitidas para una fecha, en minutos desde medianoche:
     * cada slot_step_min desde la apertura de cada franja hasta una duración
     * de reserva antes de su cierre. Un día cerrado no tiene ninguna.
     */
    public static function startTimes(DateTimeImmutable $date, int $durationMin): array
    {
        if (self::isClosed($date)) {
            return [];
        }
        $step = Settings::get('slot_step_min');
        $slots = Db::all(
            'SELECT open_min, close_min FROM service_slots WHERE weekday = ? ORDER BY open_min',
            [(int) $date->format('N')]
        );
        $times = [];
        foreach ($slots as $slot) {
            for ($t = (int) $slot['open_min']; $t + $durationMin <= (int) $slot['close_min']; $t += $step) {
                $times[$t] = true;
            }
        }
        $times = array_keys($times);
        sort($times);
        return $times;
    }

    /** Fecha y hora a partir de una fecha y minutos desde medianoche. */
    public static function at(DateTimeImmutable $date, int $minutes): DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify("+{$minutes} minutes");
    }

    public static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
