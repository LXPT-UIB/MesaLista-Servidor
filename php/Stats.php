<?php
declare(strict_types=1);

/**
 * RF-46: ocupación por franja y porcentaje de reservas no presentadas de un
 * periodo, para el administrador.
 *
 * Definiciones (las mismas que explica el panel):
 *  - % no presentadas = no presentadas / reservas cuya hora ya llegó
 *    (completadas, en curso o no presentadas). Sin clientes sin reserva.
 *  - % canceladas = canceladas / todas las reservas (sin clientes sin reserva).
 *  - Ocupación de un servicio = comensales sentados o previstos /
 *    (plazas de las mesas activas × rotaciones que caben en el servicio ×
 *    días de ese servicio en el periodo).
 */
final class Stats
{
    private const DAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    private const OCCUPYING = "('confirmed','seated','completed')";

    public static function period(array $query): array
    {
        $today = Clock::now()->setTime(0, 0);
        $to = isset($query['to']) ? V::date($query['to'], 'to') : $today;
        $from = isset($query['from']) ? V::date($query['from'], 'from') : $to->modify('-29 days');
        if ($from > $to) {
            V::invalid('from', 'La fecha inicial no puede ser posterior a la final.');
        }
        if ($from->diff($to)->days > 366) {
            V::invalid('from', 'El periodo puede tener como máximo un año.');
        }
        $start = Clock::sql($from);
        $end = Clock::sql($to->modify('+1 day'));

        // Totales por estado y por origen.
        $byStatus = array_fill_keys(['confirmed', 'pending_payment', 'seated', 'completed', 'cancelled', 'no_show'], ['reservations' => 0, 'people' => 0]);
        $bySource = array_fill_keys(['app', 'local', 'walkin'], 0);
        $rows = Db::all(
            'SELECT status, source, COUNT(*) AS n, SUM(party_size) AS people
               FROM reservations WHERE start_at >= ? AND start_at < ? GROUP BY status, source',
            [$start, $end]
        );
        $booked = ['total' => 0, 'cancelled' => 0, 'arrived' => 0, 'no_show' => 0];
        foreach ($rows as $r) {
            $byStatus[$r['status']]['reservations'] += (int) $r['n'];
            $byStatus[$r['status']]['people'] += (int) $r['people'];
            if ($r['status'] !== 'cancelled') {
                $bySource[$r['source']] += (int) $r['n'];
            }
            if ($r['source'] === 'walkin') {
                continue;
            }
            $booked['total'] += (int) $r['n'];
            if ($r['status'] === 'cancelled') {
                $booked['cancelled'] += (int) $r['n'];
            }
            if (in_array($r['status'], ['completed', 'seated', 'no_show'], true)) {
                $booked['arrived'] += (int) $r['n'];
            }
            if ($r['status'] === 'no_show') {
                $booked['no_show'] += (int) $r['n'];
            }
        }

        // Por día y por hora de inicio (sin canceladas).
        $byDay = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $byDay[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'reservations' => 0, 'people' => 0];
        }
        foreach (Db::all(
            "SELECT start_at::date AS d, COUNT(*) AS n, SUM(party_size) AS people FROM reservations
              WHERE start_at >= ? AND start_at < ? AND status <> 'cancelled' GROUP BY start_at::date",
            [$start, $end]
        ) as $r) {
            $byDay[$r['d']]['reservations'] = (int) $r['n'];
            $byDay[$r['d']]['people'] = (int) $r['people'];
        }
        $byHour = array_map(fn($r) => [
            'time' => substr($r['t'], 0, 5), 'reservations' => (int) $r['n'], 'people' => (int) $r['people'],
        ], Db::all(
            "SELECT to_char(start_at, 'HH24:MI') AS t, COUNT(*) AS n, SUM(party_size) AS people FROM reservations
              WHERE start_at >= ? AND start_at < ? AND status <> 'cancelled' GROUP BY t ORDER BY t",
            [$start, $end]
        ));

        return [
            'from'          => $from->format('Y-m-d'),
            'to'            => $to->format('Y-m-d'),
            'by_status'     => $byStatus,
            'by_source'     => $bySource,
            'reservations'  => $booked['total'] - $booked['cancelled'] + $bySource['walkin'],
            // Comensales sentados o previstos: sin canceladas ni no presentadas.
            'people'        => array_sum(array_map(fn($s) => $s['people'], array_diff_key($byStatus, ['cancelled' => 1, 'no_show' => 1]))),
            'no_show_rate'  => $booked['arrived'] > 0 ? round(100 * $booked['no_show'] / $booked['arrived'], 1) : null,
            'cancel_rate'   => $booked['total'] > 0 ? round(100 * $booked['cancelled'] / $booked['total'], 1) : null,
            'by_day'        => array_values($byDay),
            'by_hour'       => $byHour,
            'by_service'    => self::services($from, $to, $start, $end),
        ];
    }

    /** Ocupación de cada franja de servicio (día de la semana y horario). */
    private static function services(DateTimeImmutable $from, DateTimeImmutable $to, string $start, string $end): array
    {
        $seats = (int) Db::value('SELECT COALESCE(SUM(max_capacity), 0) FROM dining_tables WHERE active AND NOT join_only');
        $duration = Settings::get('duration_min');
        $closed = array_flip(array_column(
            Db::all('SELECT day FROM closed_days WHERE day >= ? AND day <= ?', [$from->format('Y-m-d'), $to->format('Y-m-d')]),
            'day'
        ));
        $daysPerWeekday = array_fill(1, 7, 0);
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            if (!isset($closed[$d->format('Y-m-d')])) {
                $daysPerWeekday[(int) $d->format('N')]++;
            }
        }
        $rows = Db::all(
            'SELECT s.id, s.weekday, s.open_min, s.close_min, COUNT(r.id) AS n, COALESCE(SUM(r.party_size), 0) AS people
               FROM service_slots s
               LEFT JOIN reservations r
                 ON EXTRACT(ISODOW FROM r.start_at) = s.weekday
                AND EXTRACT(HOUR FROM r.start_at) * 60 + EXTRACT(MINUTE FROM r.start_at) >= s.open_min
                AND EXTRACT(HOUR FROM r.start_at) * 60 + EXTRACT(MINUTE FROM r.start_at) <  s.close_min
                AND r.start_at >= ? AND r.start_at < ?
                AND r.status IN ' . self::OCCUPYING . '
              GROUP BY s.id, s.weekday, s.open_min, s.close_min
              ORDER BY s.weekday, s.open_min',
            [$start, $end]
        );
        return array_map(function ($s) use ($seats, $duration, $daysPerWeekday) {
            $rotations = max(1, intdiv((int) $s['close_min'] - (int) $s['open_min'], $duration));
            $services = $daysPerWeekday[(int) $s['weekday']];
            $capacity = $seats * $rotations * $services;
            return [
                'weekday'      => (int) $s['weekday'],
                'day'          => self::DAYS[(int) $s['weekday'] - 1],
                'slot'         => Schedule::hhmm((int) $s['open_min']) . '–' . Schedule::hhmm((int) $s['close_min']),
                'services'     => $services,
                'rotations'    => $rotations,
                'reservations' => (int) $s['n'],
                'people'       => (int) $s['people'],
                'capacity'     => $capacity,
                'occupancy'    => $capacity > 0 ? round(100 * (int) $s['people'] / $capacity, 1) : null,
            ];
        }, $rows);
    }
}
