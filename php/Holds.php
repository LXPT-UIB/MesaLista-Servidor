<?php
declare(strict_types=1);

/**
 * RF-45: bloqueo de mesas o franjas por un evento privado o una avería.
 *
 * Un bloqueo ocupa una mesa (o todas) durante un intervalo y aparece en la
 * vista table_blocks con estado 'hold': la disponibilidad, los clientes sin
 * reserva y el cambio o la unión de mesas lo tienen en cuenta sin más código.
 * No se crea si alguna reserva confirmada o en curso usa esas mesas en ese
 * intervalo: primero hay que moverla o cancelarla.
 */
final class Holds
{
    /** Bloqueos que no han terminado, del más próximo al más lejano. */
    public static function upcoming(): array
    {
        $rows = Db::all(
            'SELECT h.*, t.number, t.zone FROM table_holds h LEFT JOIN dining_tables t ON t.id = h.table_id
              WHERE h.ends_at > ? ORDER BY h.starts_at, t.zone, t.number',
            [Clock::sql()]
        );
        return array_map([self::class, 'present'], $rows);
    }

    /** Bloqueos que afectan a una fecha (para la agenda). */
    public static function forDate(DateTimeImmutable $date): array
    {
        $from = $date->setTime(0, 0);
        $rows = Db::all(
            'SELECT h.*, t.number, t.zone FROM table_holds h LEFT JOIN dining_tables t ON t.id = h.table_id
              WHERE h.starts_at < ? AND h.ends_at > ? ORDER BY h.starts_at, t.zone, t.number',
            [Clock::sql($from->modify('+1 day')), Clock::sql($from)]
        );
        return array_map([self::class, 'present'], $rows);
    }

    private static function present(array $h): array
    {
        $start = Clock::parse($h['starts_at']);
        $end = Clock::parse($h['ends_at']);
        return [
            'id'     => (int) $h['id'],
            'table'  => $h['table_id'] === null ? null : Booking::code($h),   // S-1…; null: todas
            'date'   => $start->format('Y-m-d'),
            'from'   => $start->format('H:i'),
            'to'     => $end->format('H:i'),
            'until'  => $end->format('Y-m-d H:i'),
            'reason' => $h['reason'],
            'active' => $start <= Clock::now() && Clock::now() < $end,
        ];
    }

    /**
     * Crea el bloqueo. Datos: date, from y to ("HH:MM"; to "00:00" = medianoche),
     * reason, y table_ids (lista) o all = true para todas las mesas.
     */
    public static function create(int $staffId, array $in): array
    {
        V::only($in, ['date', 'from', 'to', 'reason', 'table_ids', 'all']);
        $date = V::date($in['date'] ?? null);
        $from = V::time($in['from'] ?? null, 'from');
        $to = V::time($in['to'] ?? null, 'to');
        if ($to === 0) {
            $to = 1440;
        }
        if ($from >= $to) {
            V::invalid('to', 'El bloqueo debe terminar después de empezar.');
        }
        $reason = V::text($in['reason'] ?? null, 'reason', 2, 120);
        $all = array_key_exists('all', $in) && V::bool($in['all'], 'all');
        $ids = $all ? [] : V::idList($in['table_ids'] ?? null, 'table_ids', 100);
        if (!$all && $ids === []) {
            V::invalid('table_ids', 'Elige al menos una mesa o «todas las mesas».');
        }
        $start = Schedule::at($date, $from);
        $end = Schedule::at($date, $to);
        if ($end <= Clock::now()) {
            V::invalid('to', 'Ese intervalo ya ha pasado.');
        }

        return Db::tx(function () use ($staffId, $start, $end, $reason, $all, $ids) {
            // Mismo bloqueo de filas que las reservas: nadie reserva esas mesas mientras tanto.
            $tables = [];
            foreach (Db::all('SELECT id, number, zone FROM dining_tables WHERE deleted_at IS NULL ORDER BY id FOR UPDATE') as $t) {
                $tables[(int) $t['id']] = Booking::code($t);
            }
            foreach ($ids as $id) {
                if (!isset($tables[$id])) {
                    fail(404, 'table_not_found', 'Alguna de esas mesas no existe.');
                }
            }
            $affected = $all ? array_keys($tables) : $ids;
            $clash = Db::all(
                "SELECT DISTINCT reservation_id FROM table_blocks
                  WHERE table_id IN (" . Db::placeholders($affected) . ")
                    AND status IN ('confirmed','seated')
                    AND block_start < ? AND end_at > ?",
                [...$affected, Clock::sql($end), Clock::sql($start)]
            );
            if ($clash) {
                $list = array_map(function ($row) {
                    $r = Booking::load((int) $row['reservation_id']);
                    $who = $r['guest_name'] ?? $r['user_name'] ?? 'sin nombre';
                    return Clock::parse($r['start_at'])->format('H:i') . ' · mesa ' . implode('+', $r['table_codes']) . " · {$who}";
                }, $clash);
                fail(409, 'reservations_in_hold', 'Hay reservas en esas mesas durante el bloqueo. Muévelas o cancélalas antes: ' . implode('; ', $list) . '.',
                    ['reservations' => array_map(fn($row) => (int) $row['reservation_id'], $clash)]);
            }
            $now = Clock::sql();
            foreach ($all ? [null] : $ids as $id) {
                Db::exec(
                    'INSERT INTO table_holds (table_id, starts_at, ends_at, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, Clock::sql($start), Clock::sql($end), $reason, $staffId, $now]
                );
            }
            return self::upcoming();
        });
    }

    public static function delete(int $id): array
    {
        if (Db::exec('DELETE FROM table_holds WHERE id = ?', [$id]) === 0) {
            fail(404, 'not_found', 'Bloqueo no encontrado.');
        }
        return self::upcoming();
    }
}
