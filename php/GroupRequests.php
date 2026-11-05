<?php
declare(strict_types=1);

/**
 * RF-43: los grupos de más comensales que el máximo (10) envían una solicitud
 * desde la app; el personal la acepta (eligiendo mesas, Booking::acceptGroup)
 * o la rechaza. Aceptada con señal (RF-44), la reserva queda «pendiente de pago».
 */
final class GroupRequests
{
    private const MAX_PENDING = 3;

    /** Horas que se pueden pedir para una fecha (las de inicio permitidas, sin mirar mesas). */
    public static function times(DateTimeImmutable $date): array
    {
        $now = Clock::now();
        $earliest = Clock::plus($now, Settings::get('min_advance_min'));
        $latest = $now->modify('+' . Settings::get('max_advance_days') . ' days');
        $out = [];
        foreach (Schedule::startTimes($date, Settings::get('duration_min')) as $t) {
            $s = Schedule::at($date, $t);
            if ($s >= $earliest && $s <= $latest) {
                $out[] = Schedule::hhmm($t);
            }
        }
        return $out;
    }

    public static function create(array $user, array $in): array
    {
        V::only($in, ['date', 'time', 'party', 'note', 'consent']);
        $date = V::date($in['date'] ?? null);
        $minutes = V::time($in['time'] ?? null);
        $min = Settings::get('max_party') + 1;
        $max = Settings::get('max_group_party');
        $party = V::int($in['party'] ?? null, 'party', $min, max($min, $max));
        $consent = array_key_exists('consent', $in) && V::bool($in['consent'], 'consent');
        $note = null;
        if (array_key_exists('note', $in) && $in['note'] !== null) {
            $note = V::text($in['note'], 'note', 0, 200, true);
            $note = $note === '' ? null : $note;
        }
        if (!$consent) {
            $note = null;   // RP-04: sin consentimiento no se guarda la nota
        }
        if (!in_array(Schedule::hhmm($minutes), self::times($date), true)) {
            fail(422, 'invalid_time', 'Esa hora no se puede pedir: el restaurante está cerrado, ya ha pasado o falta demasiado.', ['field' => 'time']);
        }
        $pending = (int) Db::value("SELECT COUNT(*) FROM group_requests WHERE user_id = ? AND status = 'pending'", [$user['id']]);
        if ($pending >= self::MAX_PENDING) {
            fail(409, 'limit', 'Ya tienes ' . self::MAX_PENDING . ' solicitudes de grupo pendientes. Espera a que el restaurante responda.');
        }
        Db::exec(
            "INSERT INTO group_requests (user_id, day, time_min, party_size, note, consent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$user['id'], $date->format('Y-m-d'), $minutes, $party, $note, $note !== null ? Clock::sql() : null, Clock::sql()]
        );
        return self::forClient((int) $user['id']);
    }

    /** Solicitudes del cliente aún vigentes (pendientes, y las decididas de los últimos 30 días). */
    public static function forClient(int $userId): array
    {
        $rows = Db::all(
            "SELECT * FROM group_requests WHERE user_id = ?
                AND (status = 'pending' OR created_at > ?)
              ORDER BY status = 'pending' DESC, day, time_min",
            [$userId, Clock::sql(Clock::now()->modify('-30 days'))]
        );
        return array_map([self::class, 'present'], $rows);
    }

    public static function cancelOwn(array $user, int $id): array
    {
        $n = Db::exec("UPDATE group_requests SET status = 'cancelled', decided_at = ? WHERE id = ? AND user_id = ? AND status = 'pending'",
            [Clock::sql(), $id, $user['id']]);
        if ($n === 0 && Db::value('SELECT 1 FROM group_requests WHERE id = ? AND user_id = ?', [$id, $user['id']]) === null) {
            fail(404, 'not_found', 'Solicitud no encontrada.');
        }
        return self::forClient((int) $user['id']);
    }

    /** El personal rechaza una solicitud indicando el motivo (se envía al cliente). */
    public static function reject(int $staffId, int $id, array $in): void
    {
        V::only($in, ['reply']);
        $reply = V::text($in['reply'] ?? null, 'reply', 1, 200, true);
        Db::tx(function () use ($staffId, $id, $reply) {
            $g = Db::one('SELECT * FROM group_requests WHERE id = ? FOR UPDATE', [$id]);
            if ($g === null) {
                fail(404, 'not_found', 'Solicitud no encontrada.');
            }
            if ($g['status'] !== 'pending') {
                fail(409, 'not_pending', 'Esta solicitud ya está respondida o cancelada.');
            }
            Db::exec("UPDATE group_requests SET status = 'rejected', reply = ?, decided_by = ?, decided_at = ? WHERE id = ?",
                [$reply, $staffId, Clock::sql(), $id]);
            if ($g['user_id'] === null) {
                return;
            }
            $start = Schedule::at(new DateTimeImmutable($g['day']), (int) $g['time_min']);
            Db::exec('INSERT INTO notifications (user_id, reservation_id, message, created_at) VALUES (?, NULL, ?, ?)', [
                $g['user_id'],
                mb_substr(sprintf('El restaurante no puede atender tu grupo del %s a las %s: %s', $start->format('d/m/Y'), $start->format('H:i'), $reply), 0, 255),
                Clock::sql(),
            ]);
            $u = Db::one('SELECT name, email FROM users WHERE id = ?', [$g['user_id']]);
            Mail::queue($u['email'], 'Solicitud de grupo no aceptada',
                "Hola, {$u['name']}:\n\nLo sentimos: el restaurante no puede atender tu solicitud de grupo de "
                . "{$g['party_size']} comensales para el " . Mail::when($start) . ".\n\n"
                . "Motivo: {$reply}\n\nPuedes enviar otra solicitud para otra fecha desde la app MesaLista.\n");
            Push::queueForEmail($u['email'], 'Solicitud de grupo no aceptada',
                "{$g['party_size']} comensales · " . ucfirst(Mail::when($start)), ['type' => 'group_rejected']);
        });
    }

    /** Para el panel: pendientes primero y las decididas de los últimos 30 días. */
    public static function forStaff(): array
    {
        $rows = Db::all(
            "SELECT g.*, u.name AS user_name, u.phone AS user_phone, u.email AS user_email
               FROM group_requests g LEFT JOIN users u ON u.id = g.user_id
              WHERE g.status = 'pending' OR g.created_at > ?
              ORDER BY g.status = 'pending' DESC, g.day, g.time_min",
            [Clock::sql(Clock::now()->modify('-30 days'))]
        );
        return array_map(fn($g) => self::present($g) + [
            'name'  => $g['user_name'],
            'phone' => $g['user_phone'],
            'email' => $g['user_email'],
        ], $rows);
    }

    private static function present(array $g): array
    {
        return [
            'id'             => (int) $g['id'],
            'date'           => $g['day'],
            'time'           => Schedule::hhmm((int) $g['time_min']),
            'party'          => (int) $g['party_size'],
            'note'           => $g['note'],
            'status'         => $g['status'],
            'reply'          => $g['reply'],
            'reservation_id' => $g['reservation_id'] === null ? null : (int) $g['reservation_id'],
            'created_at'     => substr($g['created_at'], 0, 16),
        ];
    }
}
