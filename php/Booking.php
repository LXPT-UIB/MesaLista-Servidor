<?php
declare(strict_types=1);

/**
 * Reglas de las reservas: disponibilidad, asignación de mesa, creación,
 * modificación, cancelación, vinculación, llegadas y estado en sala.
 *
 * Concurrencia (RF-09): toda operación que asigna una mesa bloquea antes con
 * SELECT ... FOR UPDATE las filas de las mesas candidatas y después comprueba
 * los solapamientos. Dos solicitudes que pueden acabar en la misma mesa
 * bloquean esa fila, así que la segunda espera a que la primera confirme y ya
 * ve su reserva. Las mesas se bloquean al final, justo antes de insertar,
 * para que la transacción dure lo mínimo.
 *
 * Orden de bloqueo: usuario → reserva → mesas (por id).
 */
final class Booking
{
    // Ocupan la mesa: reservas confirmadas, en curso y pendientes de pago (RF-44), y los bloqueos (RF-45).
    private const BLOCKING = "('confirmed','seated','hold','pending_payment')";

    // ------------------------------------------------------------------
    // Consultas auxiliares
    // ------------------------------------------------------------------

    /**
     * Código visible de una mesa: inicial de la zona y número dentro de ella
     * (S-1 sala, T-1 terraza, E-1 extra). Igual que la columna code_live.
     */
    public static function code(array $t): string
    {
        return mb_strtoupper(mb_substr((string) $t['zone'], 0, 1)) . '-' . (int) $t['number'];
    }

    /** Mesas activas que admiten $party, la de menor capacidad máxima primero (RF-08). */
    private static function suitableTables(int $party, bool $lock): array
    {
        $rows = Db::all(
            'SELECT id, number, zone, min_capacity, max_capacity FROM dining_tables
              WHERE active AND NOT join_only AND min_capacity <= ? AND max_capacity >= ?
              ORDER BY id' . ($lock ? ' FOR UPDATE' : ''),
            [$party, $party]
        );
        usort($rows, fn($a, $b) => [$a['max_capacity'], $a['zone'], $a['number']] <=> [$b['max_capacity'], $b['zone'], $b['number']]);
        return $rows;
    }

    /**
     * Ids de las mesas con alguna reserva o bloqueo que las ocupa en [start, end).
     * Cuenta la mesa principal, las unidas y los bloqueos (vista table_blocks).
     */
    private static function blockedTables(array $tableIds, DateTimeImmutable $start, DateTimeImmutable $end, ?int $excludeId = null): array
    {
        if (!$tableIds) {
            return [];
        }
        $sql = 'SELECT DISTINCT table_id FROM table_blocks
                 WHERE table_id IN (' . Db::placeholders($tableIds) . ')
                   AND status IN ' . self::BLOCKING . '
                   AND block_start < ? AND end_at > ?';
        $params = [...$tableIds, Clock::sql($end), Clock::sql($start)];
        if ($excludeId !== null) {
            // Los bloqueos no tienen reserva (NULL): nunca se excluyen.
            $sql .= ' AND (reservation_id IS NULL OR reservation_id <> ?)';
            $params[] = $excludeId;
        }
        return array_map('intval', array_column(Db::all($sql, $params), 'table_id'));
    }

    /** Ids de las mesas ocupadas ahora en sala (con una reserva «en curso»), unidas incluidas. */
    private static function occupiedTables(?int $excludeId = null): array
    {
        $sql = "SELECT DISTINCT table_id FROM table_blocks WHERE status = 'seated'";
        $params = [];
        if ($excludeId !== null) {
            $sql .= ' AND reservation_id <> ?';
            $params[] = $excludeId;
        }
        return array_map('intval', array_column(Db::all($sql, $params), 'table_id'));
    }

    /** Mesas unidas (adicionales) de una reserva: [id => fila]. */
    private static function extraTables(int $reservationId, bool $lock = false): array
    {
        $out = [];
        $rows = Db::all(
            'SELECT t.id, t.number, t.zone, t.min_capacity, t.max_capacity, t.active
               FROM reservation_tables rt JOIN dining_tables t ON t.id = rt.table_id
              WHERE rt.reservation_id = ? ORDER BY t.id' . ($lock ? ' FOR UPDATE' : ''),
            [$reservationId]
        );
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * Mesa adecuada que nadie bloquea en [start, end), bloqueando las candidatas.
     * Con $freeNow, además debe estar libre en sala en este momento.
     */
    private static function pickTable(int $party, DateTimeImmutable $start, DateTimeImmutable $end, ?int $excludeId, bool $freeNow = false): ?array
    {
        $tables = self::suitableTables($party, true);
        $ids = array_map('intval', array_column($tables, 'id'));
        $busy = self::blockedTables($ids, $start, $end, $excludeId);
        if ($freeNow) {
            $busy = array_merge($busy, self::occupiedTables($excludeId));
        }
        foreach ($tables as $t) {
            if (!in_array((int) $t['id'], $busy, true)) {
                return $t;
            }
        }
        return null;
    }

    /** RF-24: marca las no presentadas con la hora del servidor (también lo hace worker.php cada minuto). */
    public static function markNoShows(): void
    {
        Db::run('CALL sp_mark_no_shows(?)', [Clock::sql()])->closeCursor();
        self::notifyExpiredPayments();
    }

    /**
     * RF-44: aviso y correo de las reservas de grupo que el sistema ha liberado
     * por no pagar la señal. Las cancela el procedimiento (también desde
     * worker.php); el aviso en la app sirve de marca de «ya avisada».
     */
    private static function notifyExpiredPayments(): void
    {
        $ids = Db::all(
            "SELECT r.id FROM reservations r
              WHERE r.status = 'cancelled' AND r.cancelled_by = 'system' AND r.user_id IS NOT NULL
                AND r.cancelled_at > ?
                AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.reservation_id = r.id AND n.created_at >= r.cancelled_at)",
            [Clock::sql(Clock::now()->modify('-1 day'))]
        );
        foreach ($ids as $row) {
            Db::tx(function () use ($row) {
                $r = Db::one('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$row['id']]);
                if (Db::value('SELECT 1 FROM notifications WHERE reservation_id = ? AND created_at >= ?', [$r['id'], $r['cancelled_at']]) !== null) {
                    return;
                }
                $start = Clock::parse($r['start_at']);
                Db::exec(
                    'INSERT INTO notifications (user_id, reservation_id, message, created_at) VALUES (?, ?, ?, ?)',
                    [$r['user_id'], $r['id'],
                     sprintf('No se pagó la señal a tiempo y se ha liberado la reserva de grupo del %s a las %s.', $start->format('d/m/Y'), $start->format('H:i')),
                     // worker.php usa la hora real: la marca nunca queda antes de la cancelación.
                     max(Clock::sql(), $r['cancelled_at'])]
                );
                $u = Db::one('SELECT name, email FROM users WHERE id = ?', [$r['user_id']]);
                Mail::queueReservation('payment_expired', $u['email'], $u['name'], $r);
            });
        }
    }

    private static function futureConfirmedCount(int $userId): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'confirmed' AND start_at > ?",
            [$userId, Clock::sql()]
        );
    }

    private static function idForRequest(int $userId, string $requestId): ?int
    {
        $id = Db::value('SELECT id FROM reservations WHERE user_id = ? AND request_id = ?', [$userId, $requestId]);
        return $id === null ? null : (int) $id;
    }

    /**
     * Reserva con su mesa, sus mesas unidas y sus opciones de menú, o null.
     * table_codes lista todas sus mesas (S-1, …) y capacity es la suma de sus plazas.
     */
    public static function load(int $id): ?array
    {
        $r = Db::one(
            "SELECT r.*, t.number AS table_number, t.zone, t.max_capacity,
                    u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                    (SELECT p.status FROM payments p
                      WHERE p.reservation_id = r.id AND p.status IN ('captured','refund_pending','refunded')
                      ORDER BY p.id DESC LIMIT 1) AS payment_status
               FROM reservations r
               JOIN dining_tables t ON t.id = r.table_id
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.id = ?",
            [$id]
        );
        if ($r !== null) {
            $r['menu_options'] = array_map('intval', array_column(
                Db::all('SELECT option_id FROM reservation_menu_options WHERE reservation_id = ? ORDER BY option_id', [$id]),
                'option_id'
            ));
            $extra = self::extraTables($id);
            $r['table_code'] = self::code(['zone' => $r['zone'], 'number' => $r['table_number']]);
            $r['extra_tables'] = array_map(fn($t) => self::code($t), array_values($extra));
            $r['table_codes'] = [$r['table_code'], ...$r['extra_tables']];
            $r['capacity'] = (int) $r['max_capacity'] + array_sum(array_map(fn($t) => (int) $t['max_capacity'], $extra));
        }
        return $r;
    }

    private static function mustLoad(int $id): array
    {
        return self::load($id) ?? fail(404, 'not_found', 'Reserva no encontrada.');
    }

    // ------------------------------------------------------------------
    // Validación de solicitudes
    // ------------------------------------------------------------------

    /**
     * Regla 3: hora de inicio permitida, día abierto, comensales en el rango y
     * antelación. El personal no tiene antelación mínima (regla 8).
     */
    private static function checkRequest(DateTimeImmutable $date, int $minutes, int $party, int $durationMin, bool $staff): DateTimeImmutable
    {
        $minParty = Settings::get('min_party');
        $maxParty = Settings::get('max_party');
        if ($party < $minParty || $party > $maxParty) {
            fail(422, 'invalid_party', "El número de comensales debe estar entre {$minParty} y {$maxParty}.", ['field' => 'party']);
        }
        if (Schedule::isClosed($date)) {
            fail(422, 'closed', 'El restaurante está cerrado ese día.', ['field' => 'date']);
        }
        if (!in_array($minutes, Schedule::startTimes($date, $durationMin), true)) {
            fail(422, 'invalid_time', 'Esa hora de inicio no está permitida.', ['field' => 'time']);
        }
        $start = Schedule::at($date, $minutes);
        $now = Clock::now();
        if ($staff) {
            if ($start <= $now) {
                fail(422, 'past', 'Esa hora ya ha pasado.', ['field' => 'time']);
            }
        } else {
            $minAdvance = Settings::get('min_advance_min');
            if ($start < Clock::plus($now, $minAdvance)) {
                fail(422, 'too_soon', "Hay que reservar con al menos {$minAdvance} minutos de antelación.", ['field' => 'time']);
            }
        }
        $maxDays = Settings::get('max_advance_days');
        if ($start > $now->modify("+{$maxDays} days")) {
            fail(422, 'too_far', "Solo se puede reservar con {$maxDays} días de antelación como máximo.", ['field' => 'date']);
        }
        return $start;
    }

    /**
     * Opciones de menú y nota (RF-12, RF-13). Sin consentimiento explícito se
     * guardan sin ellas (RP-04), pero antes se validan igualmente (RS-08).
     */
    private static function extras(array $in): array
    {
        $consent = array_key_exists('consent', $in) ? V::bool($in['consent'], 'consent') : false;
        $options = array_key_exists('menu_options', $in) && $in['menu_options'] !== null
            ? V::idList($in['menu_options'], 'menu_options', 10) : [];
        $note = null;
        if (array_key_exists('note', $in) && $in['note'] !== null) {
            $note = V::text($in['note'], 'note', 0, 200, true);
            $note = $note === '' ? null : $note;
        }
        $valid = Settings::menuOptions();
        foreach ($options as $o) {
            if (!isset($valid[$o])) {
                V::invalid('menu_options', 'Alguna opción de menú no existe.');
            }
        }
        if (!$consent) {
            return [[], null, false];
        }
        return [$options, $note, $options !== [] || $note !== null];
    }

    private static function saveExtras(int $id, array $options, ?string $note, bool $consent): void
    {
        Db::exec('DELETE FROM reservation_menu_options WHERE reservation_id = ?', [$id]);
        foreach ($options as $o) {
            Db::exec('INSERT INTO reservation_menu_options (reservation_id, option_id) VALUES (?, ?)', [$id, $o]);
        }
        Db::exec(
            'UPDATE reservations SET note = ?, consent_at = ? WHERE id = ?',
            [$note, $consent ? Clock::sql() : null, $id]
        );
    }

    private static function insert(array $f): int
    {
        return Db::insert(
            'INSERT INTO reservations
               (source, user_id, request_id, code, guest_name, guest_phone, table_id, party_size,
                start_at, end_at, arrived_at, status, arrival_before_min, arrival_after_min,
                change_deadline_h, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $f['source'], $f['user_id'] ?? null, $f['request_id'] ?? null, $f['code'] ?? null,
                $f['guest_name'] ?? null, $f['guest_phone'] ?? null, $f['table_id'], $f['party'],
                Clock::sql($f['start']), Clock::sql($f['end']), isset($f['arrived']) ? Clock::sql($f['arrived']) : null,
                $f['status'], Settings::get('arrival_before_min'), Settings::get('arrival_after_min'),
                Settings::get('change_deadline_h'), $f['created_by'] ?? null, Clock::sql(),
            ]
        );
    }

    // ------------------------------------------------------------------
    // Disponibilidad (RF-06)
    // ------------------------------------------------------------------

    /**
     * Horas de inicio ("HH:MM") con alguna mesa adecuada libre durante toda la
     * reserva. Para el personal no cuenta la antelación mínima (regla 8).
     */
    public static function availability(DateTimeImmutable $date, int $party, bool $staff = false): array
    {
        $minParty = Settings::get('min_party');
        $maxParty = Settings::get('max_party');
        if ($party < $minParty || $party > $maxParty) {
            fail(422, 'invalid_party', "El número de comensales debe estar entre {$minParty} y {$maxParty}.", ['field' => 'party']);
        }
        $duration = Settings::get('duration_min');
        $now = Clock::now();
        $earliest = Clock::sql($staff ? $now->modify('+1 second') : Clock::plus($now, Settings::get('min_advance_min')));
        $latest = Clock::sql($now->modify('+' . Settings::get('max_advance_days') . ' days'));

        $times = [];
        foreach (Schedule::startTimes($date, $duration) as $t) {
            $s = Clock::sql(Schedule::at($date, $t));
            if ($s >= $earliest && $s <= $latest) {
                $times[] = $t;
            }
        }
        $tableIds = array_map('intval', array_column(self::suitableTables($party, false), 'id'));
        if (!$times || !$tableIds) {
            return [];
        }

        $from = Clock::sql(Schedule::at($date, $times[0]));
        $to = Clock::sql(Schedule::at($date, end($times) + $duration));
        $busy = [];
        $rows = Db::all(
            'SELECT table_id, block_start, end_at FROM table_blocks
              WHERE table_id IN (' . Db::placeholders($tableIds) . ')
                AND status IN ' . self::BLOCKING . '
                AND block_start < ? AND end_at > ?',
            [...$tableIds, $to, $from]
        );
        foreach ($rows as $row) {
            $busy[(int) $row['table_id']][] = [$row['block_start'], $row['end_at']];
        }

        $free = [];
        foreach ($times as $t) {
            $s = Clock::sql(Schedule::at($date, $t));
            $e = Clock::sql(Schedule::at($date, $t + $duration));
            foreach ($tableIds as $id) {
                $clash = false;
                foreach ($busy[$id] ?? [] as [$bs, $be]) {
                    if ($bs < $e && $be > $s) {
                        $clash = true;
                        break;
                    }
                }
                if (!$clash) {
                    $free[] = Schedule::hhmm($t);
                    continue 2;
                }
            }
        }
        return $free;
    }

    // ------------------------------------------------------------------
    // Cliente: reservar, modificar, cancelar, vincular
    // ------------------------------------------------------------------

    /**
     * RF-07 a RF-13. Devuelve [reserva, creada]. Si la app repite una
     * solicitud ya procesada (mismo request_id), devuelve la reserva existente
     * sin crear otra (RF-10).
     */
    public static function createForClient(array $user, array $in): array
    {
        V::only($in, ['request_id', 'date', 'time', 'party', 'menu_options', 'note', 'consent']);
        $userId = (int) $user['id'];
        $requestId = V::uuid($in['request_id'] ?? null);
        if (($existing = self::idForRequest($userId, $requestId)) !== null) {
            return [self::mustLoad($existing), false];
        }

        $date = V::date($in['date'] ?? null);
        $minutes = V::time($in['time'] ?? null);
        $party = V::int($in['party'] ?? null, 'party', 1, 255);
        [$options, $note, $consent] = self::extras($in);
        $duration = Settings::get('duration_min');
        $start = self::checkRequest($date, $minutes, $party, $duration, false);
        $end = Clock::plus($start, $duration);

        try {
            [$id, $created] = Db::tx(function () use ($user, $userId, $requestId, $party, $start, $end, $options, $note, $consent) {
                Db::value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
                if (($existing = self::idForRequest($userId, $requestId)) !== null) {
                    return [$existing, false];
                }
                $max = Settings::get('max_future_per_client');
                if (self::futureConfirmedCount($userId) >= $max) {
                    fail(409, 'limit', "Ya tienes {$max} reservas confirmadas pendientes, que es el máximo.");
                }
                $table = self::pickTable($party, $start, $end, null);
                if ($table === null) {
                    fail(409, 'conflict', 'Esa hora ya no está disponible. Elige otra.');
                }
                $id = self::insert([
                    'source' => 'app', 'user_id' => $userId, 'request_id' => $requestId,
                    'table_id' => (int) $table['id'], 'party' => $party,
                    'start' => $start, 'end' => $end, 'status' => 'confirmed',
                ]);
                self::saveExtras($id, $options, $note, $consent);
                Mail::queueReservation('confirmed', $user['email'], $user['name'],
                    ['start_at' => Clock::sql($start), 'party_size' => $party]);
                return [$id, true];
            });
        } catch (PDOException $e) {
            // Dos envíos simultáneos de la misma solicitud: gana uno y el otro la devuelve.
            if (Db::isDuplicateKey($e) && ($existing = self::idForRequest($userId, $requestId)) !== null) {
                return [self::mustLoad($existing), false];
            }
            throw $e;
        }
        return [self::mustLoad($id), $created];
    }

    /** RS-03: un cliente solo ve y toca sus reservas; las demás «no existen». */
    private static function lockOwned(int $userId, int $id): array
    {
        $r = Db::one('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$id]);
        if ($r === null || (int) $r['user_id'] !== $userId) {
            fail(404, 'not_found', 'Reserva no encontrada.');
        }
        return $r;
    }

    public static function getOwned(int $userId, int $id): array
    {
        $r = self::load($id);
        if ($r === null || (int) $r['user_id'] !== $userId) {
            fail(404, 'not_found', 'Reserva no encontrada.');
        }
        return $r;
    }

    public static function listOwned(int $userId): array
    {
        $ids = Db::all(
            "SELECT id FROM reservations WHERE user_id = ?
              ORDER BY status IN ('confirmed','seated') DESC,
                       CASE WHEN status IN ('confirmed','seated') THEN start_at END ASC,
                       start_at DESC",
            [$userId]
        );
        return array_map(fn($row) => self::load((int) $row['id']), $ids);
    }

    /** Plazo de modificación y de cancelación (regla 12). */
    private static function checkDeadline(array $r): void
    {
        $hours = (int) $r['change_deadline_h'];
        $limit = Clock::plus(Clock::parse($r['start_at']), -60 * $hours);
        if (Clock::now() > $limit) {
            fail(409, 'deadline', "Faltan menos de {$hours} horas para la reserva: ya no se puede cambiar ni cancelar desde la app. Llama al restaurante.");
        }
    }

    /**
     * RF-13 y RF-15: cambia fecha, hora, comensales u opciones. El cambio se
     * aplica entero o no se aplica (regla 7) y siempre renueva el QR (RF-18).
     */
    public static function modifyByHolder(array $user, int $id, array $in): array
    {
        V::only($in, ['date', 'time', 'party', 'menu_options', 'note', 'consent']);
        $userId = (int) $user['id'];
        $date = array_key_exists('date', $in) ? V::date($in['date']) : null;
        $minutes = array_key_exists('time', $in) ? V::time($in['time']) : null;
        $party = array_key_exists('party', $in) ? V::int($in['party'], 'party', 1, 255) : null;
        $touchExtras = array_key_exists('menu_options', $in) || array_key_exists('note', $in) || array_key_exists('consent', $in);
        [$options, $note, $consent] = $touchExtras ? self::extras($in) : [[], null, false];

        Db::tx(function () use ($user, $userId, $id, $date, $minutes, $party, $touchExtras, $options, $note, $consent) {
            Db::value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $r = self::lockOwned($userId, $id);
            if ($r['status'] !== 'confirmed') {
                fail(409, 'not_modifiable', 'Solo se pueden modificar las reservas confirmadas.');
            }
            self::checkDeadline($r);

            $oldStart = Clock::parse($r['start_at']);
            $oldEnd = Clock::parse($r['end_at']);
            $oldMinutes = (int) $oldStart->format('H') * 60 + (int) $oldStart->format('i');
            $newDate = $date ?? $oldStart->setTime(0, 0);
            $newMinutes = $minutes ?? $oldMinutes;
            $newParty = $party ?? (int) $r['party_size'];
            $changesSlot = $newDate->format('Y-m-d') !== $oldStart->format('Y-m-d')
                || $newMinutes !== $oldMinutes
                || $newParty !== (int) $r['party_size'];
            if (!$changesSlot && !$touchExtras) {
                return;
            }

            $start = $oldStart;
            $end = $oldEnd;
            $tableId = (int) $r['table_id'];
            if ($changesSlot && (self::extraTables($id) !== [] || $r['deposit_cents'] !== null)) {
                fail(409, 'joined_tables', 'El restaurante ha unido mesas para tu reserva: para cambiar la hora o los comensales, llama al restaurante.');
            }
            if ($changesSlot) {
                // La reserva conserva su duración aunque el parámetro haya cambiado (regla 9).
                $duration = intdiv($oldEnd->getTimestamp() - $oldStart->getTimestamp(), 60);
                $start = self::checkRequest($newDate, $newMinutes, $newParty, $duration, false);
                $end = Clock::plus($start, $duration);
                $table = self::pickTable($newParty, $start, $end, $id);
                if ($table === null) {
                    fail(409, 'conflict', 'No hay mesa libre para ese cambio. Tu reserva sigue como estaba.');
                }
                $tableId = (int) $table['id'];
            }

            Db::exec(
                'UPDATE reservations
                    SET table_id = ?, party_size = ?, start_at = ?, end_at = ?, qr_version = qr_version + 1
                  WHERE id = ?',
                [$tableId, $newParty, Clock::sql($start), Clock::sql($end), $id]
            );
            if ($touchExtras) {
                self::saveExtras($id, $options, $note, $consent);
            }
            Mail::queueReservation('modified', $user['email'], $user['name'],
                ['start_at' => Clock::sql($start), 'party_size' => $newParty]);
        });
        return self::mustLoad($id);
    }

    /**
     * RF-16. Una segunda cancelación responde con éxito y no cambia nada (CA-16-5).
     * RF-44: dentro del plazo se devuelve la señal pagada; una reserva de grupo
     * aún sin pagar se puede cancelar siempre (no hay nada que devolver).
     */
    public static function cancelByHolder(array $user, int $id): array
    {
        Db::tx(function () use ($user, $id) {
            $r = self::lockOwned((int) $user['id'], $id);
            if ($r['status'] === 'cancelled') {
                return;
            }
            if (!in_array($r['status'], ['confirmed', 'pending_payment'], true)) {
                fail(409, 'not_cancellable', 'Esta reserva ya no se puede cancelar.');
            }
            if ($r['status'] === 'confirmed') {
                self::checkDeadline($r);
            }
            Db::exec(
                "UPDATE reservations SET status = 'cancelled', cancelled_by = 'holder', cancelled_at = ?, payment_due_at = NULL WHERE id = ?",
                [Clock::sql(), $id]
            );
            $refund = Payments::markRefund($id);
            Mail::queueReservation('cancelled_holder', $user['email'], $user['name'], $r,
                $refund > 0 ? 'Te devolvemos la señal de ' . Payments::euros($refund) . ' en tu cuenta de PayPal.' : '');
        });
        return self::mustLoad($id);
    }

    private static function samePhone(?string $a, string $b): bool
    {
        if ($a === null) {
            return false;
        }
        $norm = static function (string $p): string {
            $d = preg_replace('/\D/', '', $p);
            if (str_starts_with($d, '0034')) {
                $d = substr($d, 4);
            } elseif (strlen($d) === 11 && str_starts_with($d, '34')) {
                $d = substr($d, 2);
            }
            return $d;
        };
        return hash_equals($norm($a), $norm($b));
    }

    /**
     * RF-30: vincula una reserva presencial con su código y el teléfono con el
     * que se registró. Renueva el QR, así que el del ticket deja de valer.
     * RS-13: tras 5 intentos fallidos en 15 minutos, la cuenta queda bloqueada.
     */
    public static function link(array $user, array $in): array
    {
        V::only($in, ['code', 'phone']);
        $userId = (int) $user['id'];
        $failed = (int) Db::value(
            'SELECT COUNT(*) FROM link_attempts WHERE user_id = ? AND NOT success AND created_at > ?',
            [$userId, Clock::sql(Clock::plus(Clock::now(), -15))]
        );
        if ($failed >= 5) {
            fail(429, 'locked', 'Demasiados intentos fallidos. Vuelve a intentarlo dentro de 15 minutos.');
        }
        $code = Qr::normalizeCode($in['code'] ?? null);
        $phone = V::phone($in['phone'] ?? null);

        $linkedId = Db::tx(function () use ($userId, $code, $phone) {
            Db::value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $r = Db::one('SELECT * FROM reservations WHERE code = ? FOR UPDATE', [$code]);
            $ok = $r !== null
                && $r['source'] === 'local'
                && $r['user_id'] === null
                && $r['status'] === 'confirmed'
                && Clock::parse($r['start_at']) > Clock::now()
                && self::samePhone($r['guest_phone'], $phone);
            if (!$ok) {
                return null;
            }
            // Regla 11: la reserva vinculada cuenta para el límite del cliente.
            $max = Settings::get('max_future_per_client');
            if (self::futureConfirmedCount($userId) >= $max) {
                fail(409, 'limit', "Ya tienes {$max} reservas confirmadas pendientes, que es el máximo.");
            }
            Db::exec('UPDATE reservations SET user_id = ?, qr_version = qr_version + 1 WHERE id = ?', [$userId, $r['id']]);
            return (int) $r['id'];
        });

        Db::exec(
            'INSERT INTO link_attempts (user_id, success, created_at) VALUES (?, ?, ?)',
            [$userId, $linkedId === null ? 0 : 1, Clock::sql()]
        );
        if ($linkedId === null) {
            fail(404, 'link_failed', 'No hay ninguna reserva pendiente con ese código y ese teléfono.');
        }
        return self::mustLoad($linkedId);
    }

    // ------------------------------------------------------------------
    // Personal: reservas presenciales, cancelaciones, llegadas, sala
    // ------------------------------------------------------------------

    /** RF-28 y RF-29: reserva a nombre de un cliente, sin cuenta, con código y ticket. */
    public static function createLocal(int $staffId, array $in): array
    {
        V::only($in, ['name', 'phone', 'date', 'time', 'party', 'menu_options', 'note', 'consent']);
        $name = V::name($in['name'] ?? null);
        $phone = V::phone($in['phone'] ?? null);
        $date = V::date($in['date'] ?? null);
        $minutes = V::time($in['time'] ?? null);
        $party = V::int($in['party'] ?? null, 'party', 1, 255);
        [$options, $note, $consent] = self::extras($in);
        $duration = Settings::get('duration_min');
        $start = self::checkRequest($date, $minutes, $party, $duration, true);
        $end = Clock::plus($start, $duration);

        $id = Db::tx(function () use ($staffId, $name, $phone, $party, $start, $end, $options, $note, $consent) {
            $table = self::pickTable($party, $start, $end, null);
            if ($table === null) {
                fail(409, 'conflict', 'Esa hora ya no está disponible. Elige otra.');
            }
            do {
                $code = Qr::newCode();
            } while (Db::value('SELECT 1 FROM reservations WHERE code = ?', [$code]) !== null);
            $id = self::insert([
                'source' => 'local', 'code' => $code, 'guest_name' => $name, 'guest_phone' => $phone,
                'table_id' => (int) $table['id'], 'party' => $party, 'start' => $start, 'end' => $end,
                'status' => 'confirmed', 'created_by' => $staffId,
            ]);
            self::saveExtras($id, $options, $note, $consent);
            return $id;
        });
        return self::mustLoad($id);
    }

    /** RF-43: mesas activas para la hora de una solicitud de grupo, con las libres marcadas. */
    public static function groupTables(int $requestId): array
    {
        self::markNoShows();
        $g = Db::one('SELECT day, time_min FROM group_requests WHERE id = ?', [$requestId])
            ?? fail(404, 'not_found', 'Solicitud no encontrada.');
        $start = Schedule::at(new DateTimeImmutable($g['day']), (int) $g['time_min']);
        $end = Clock::plus($start, Settings::get('duration_min'));
        $tables = self::activeTables(false);
        $busy = self::blockedTables(array_keys($tables), $start, $end);
        return array_values(array_map(fn($t) => [
            'id'           => (int) $t['id'],
            'number'       => (int) $t['number'],
            'code'         => self::code($t),
            'zone'         => $t['zone'],
            'max_capacity' => (int) $t['max_capacity'],
            'join_only'    => (int) $t['join_only'] === 1,
            'free'         => !in_array((int) $t['id'], $busy, true),
        ], $tables));
    }

    /**
     * RF-43: el personal acepta una solicitud de grupo eligiendo las mesas (la
     * primera es la principal y las demás quedan unidas). Con señal (RF-44) la
     * reserva nace «pendiente de pago» y retiene las mesas deposit_hold_min
     * minutos; sin pasarela o sin importe por persona, nace confirmada.
     */
    public static function acceptGroup(int $staffId, int $requestId, array $in): array
    {
        V::only($in, ['table_ids', 'reply']);
        $wanted = V::idList($in['table_ids'] ?? null, 'table_ids', 10);
        if ($wanted === []) {
            V::invalid('table_ids', 'Elige al menos una mesa.');
        }
        $reply = isset($in['reply']) ? V::text($in['reply'], 'reply', 0, 200, true) : '';
        self::markNoShows();
        $userId = Db::value('SELECT user_id FROM group_requests WHERE id = ?', [$requestId]);

        $id = Db::tx(function () use ($staffId, $requestId, $userId, $wanted, $reply) {
            if ($userId !== null) {
                Db::value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            }
            $g = Db::one('SELECT * FROM group_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($g === null) {
                fail(404, 'not_found', 'Solicitud no encontrada.');
            }
            if ($g['status'] !== 'pending') {
                fail(409, 'not_pending', 'Esta solicitud ya está respondida o cancelada.');
            }
            if ($g['user_id'] === null) {
                fail(409, 'no_user', 'El cliente ha eliminado su cuenta.');
            }
            $party = (int) $g['party_size'];
            $start = Schedule::at(new DateTimeImmutable($g['day']), (int) $g['time_min']);
            $end = Clock::plus($start, Settings::get('duration_min'));
            $now = Clock::now();
            if ($start <= $now) {
                fail(409, 'past', 'La hora de esta solicitud ya ha pasado. Recházala.');
            }

            $tables = self::activeTables(true);
            foreach ($wanted as $tid) {
                if (!isset($tables[$tid])) {
                    fail(404, 'table_not_found', 'Alguna de esas mesas no existe o está fuera de servicio.');
                }
            }
            $capacity = array_sum(array_map(fn($tid) => (int) $tables[$tid]['max_capacity'], $wanted));
            if ($capacity < $party) {
                fail(409, 'over_capacity', "Esas mesas suman {$capacity} plazas para {$party} personas. Añade más mesas.");
            }
            $busy = self::blockedTables($wanted, $start, $end);
            if ($busy) {
                $numbers = implode(', ', array_map(fn($tid) => self::code($tables[$tid]), $busy));
                fail(409, 'table_busy', "No están libres a esa hora: mesa {$numbers}.");
            }
            // La principal es la primera elegida que no sea extra.
            $mains = array_values(array_filter($wanted, fn($tid) => (int) $tables[$tid]['join_only'] === 0));
            if ($mains === []) {
                fail(409, 'join_only', 'Las mesas extra solo se unen a otra mesa: elige también una mesa de sala o de terraza.');
            }
            $wanted = [$mains[0], ...array_values(array_diff($wanted, [$mains[0]]))];

            $deposit = Payments::enabled() ? Settings::get('deposit_per_person') * 100 * $party : 0;
            $id = self::insert([
                'source' => 'app', 'user_id' => (int) $g['user_id'], 'table_id' => $wanted[0], 'party' => $party,
                'start' => $start, 'end' => $end, 'status' => $deposit > 0 ? 'pending_payment' : 'confirmed',
                'created_by' => $staffId,
            ]);
            $due = null;
            if ($deposit > 0) {
                $due = min(Clock::plus($now, Settings::get('deposit_hold_min')), $start);
                Db::exec('UPDATE reservations SET deposit_cents = ?, payment_due_at = ? WHERE id = ?', [$deposit, Clock::sql($due), $id]);
            }
            foreach (array_slice($wanted, 1) as $tid) {
                Db::exec('INSERT INTO reservation_tables (reservation_id, table_id) VALUES (?, ?)', [$id, $tid]);
            }
            if ($g['note'] !== null) {
                Db::exec('UPDATE reservations SET note = ?, consent_at = ? WHERE id = ?', [$g['note'], $g['consent_at'], $id]);
            }
            Db::exec(
                "UPDATE group_requests SET status = 'accepted', reply = ?, reservation_id = ?, decided_by = ?, decided_at = ? WHERE id = ?",
                [$reply === '' ? null : $reply, $id, $staffId, Clock::sql(), $requestId]
            );

            $u = Db::one('SELECT name, email FROM users WHERE id = ?', [$g['user_id']]);
            $when = sprintf('del %s a las %s', $start->format('d/m/Y'), $start->format('H:i'));
            $replyText = $reply === '' ? '' : " Mensaje del restaurante: «{$reply}».";
            if ($deposit > 0) {
                $text = 'Señal: ' . Payments::euros($deposit) . '. Tienes hasta las ' . $due->format('H:i') . ' del '
                    . $due->format('d/m/Y') . ' para pagarla; si no, la reserva se libera.' . $replyText;
                $message = "Han aceptado tu grupo {$when}. Paga la señal de " . Payments::euros($deposit) . ' antes de las ' . $due->format('H:i') . '.';
                Mail::queueReservation('group_accepted', $u['email'], $u['name'], ['start_at' => Clock::sql($start), 'party_size' => $party], $text);
            } else {
                $message = "Han aceptado tu grupo {$when}: la reserva está confirmada.";
                Mail::queueReservation('confirmed', $u['email'], $u['name'], ['start_at' => Clock::sql($start), 'party_size' => $party], trim($replyText));
            }
            Db::exec('INSERT INTO notifications (user_id, reservation_id, message, created_at) VALUES (?, ?, ?, ?)',
                [$g['user_id'], $id, mb_substr($message, 0, 255), Clock::sql()]);
            return $id;
        });
        return self::mustLoad($id);
    }

    /**
     * RF-31 y RF-32: el personal cancela sin plazo; el titular con cuenta recibe
     * aviso y correo. RF-44: la señal pagada se devuelve salvo que el personal
     * indique lo contrario (p. ej. el cliente avisa fuera de plazo).
     */
    public static function cancelByRestaurant(int $id, array $in = []): array
    {
        V::only($in, ['refund']);
        $refund = array_key_exists('refund', $in) ? V::bool($in['refund'], 'refund') : true;
        Db::tx(function () use ($id, $refund) {
            $r = Db::one('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$id]);
            if ($r === null) {
                fail(404, 'not_found', 'Reserva no encontrada.');
            }
            if ($r['status'] === 'cancelled') {
                return;
            }
            if (!in_array($r['status'], ['confirmed', 'pending_payment'], true)) {
                fail(409, 'not_cancellable', 'Solo se pueden cancelar las reservas confirmadas o pendientes de pago.');
            }
            self::restaurantCancel($r, $refund);
        });
        return self::mustLoad($id);
    }

    private static function restaurantCancel(array $r, bool $refund = true): void
    {
        Db::exec(
            "UPDATE reservations SET status = 'cancelled', cancelled_by = 'restaurant', cancelled_at = ?, payment_due_at = NULL WHERE id = ?",
            [Clock::sql(), $r['id']]
        );
        $refunded = $refund ? Payments::markRefund((int) $r['id']) : 0;
        if ($r['user_id'] === null) {
            return; // Reserva presencial sin vincular: el personal avisa por teléfono (regla 14).
        }
        $u = Db::one('SELECT name, email FROM users WHERE id = ?', [$r['user_id']]);
        $start = Clock::parse($r['start_at']);
        Db::exec(
            'INSERT INTO notifications (user_id, reservation_id, message, created_at) VALUES (?, ?, ?, ?)',
            [$r['user_id'], $r['id'],
             sprintf('El restaurante ha cancelado tu reserva del %s a las %s.', $start->format('d/m/Y'), $start->format('H:i')),
             Clock::sql()]
        );
        $paid = Payments::capturedFor((int) $r['id']);
        $extra = match (true) {
            $refunded > 0  => 'Te devolvemos la señal de ' . Payments::euros($refunded) . ' en tu cuenta de PayPal.',
            $paid !== null => 'La señal pagada no se devuelve: la cancelación se ha hecho fuera de plazo.',
            default        => '',
        };
        Mail::queueReservation('cancelled_restaurant', $u['email'], $u['name'], $r, $extra);
    }

    /** RF-19 y RF-20: llegada con el QR (de la app o del ticket). */
    public static function arriveByQr(mixed $qr, bool $early): array
    {
        if (!is_string($qr) || strlen($qr) > 120) {
            fail(422, 'qr_invalid', 'QR no válido.');
        }
        $parsed = Qr::parse(trim($qr));
        if ($parsed === null) {
            fail(422, 'qr_invalid', 'QR no válido: no lo ha generado MesaLista o está alterado.');
        }
        [$id, $version] = $parsed;
        return self::arrive($id, $version, $early);
    }

    /**
     * Registra la llegada de una reserva confirmada (RF-19 a RF-23).
     * Dentro de la ventana de llegada, si la mesa asignada sigue ocupada se
     * reasigna a otra libre; si no hay ninguna, se avisa al personal. Antes de
     * la ventana solo se acepta con $early y con la mesa libre hasta el inicio.
     */
    public static function arrive(int $id, ?int $qrVersion, bool $early): array
    {
        self::markNoShows();
        $id = Db::tx(function () use ($id, $qrVersion, $early) {
            $r = Db::one('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$id]);
            if ($r === null) {
                fail(404, 'not_found', 'Reserva no encontrada.');
            }
            if ($qrVersion !== null && (int) $r['qr_version'] !== $qrVersion) {
                fail(409, 'qr_outdated', 'Este QR ya no es válido: la reserva se modificó o se vinculó a una cuenta. Pide el QR vigente.');
            }
            match ($r['status']) {
                'seated'    => fail(409, 'already_seated', 'Esta llegada ya se registró: la reserva está en curso.'),
                'completed' => fail(409, 'completed', 'Esta reserva ya está completada.'),
                'cancelled' => fail(409, 'cancelled', 'Esta reserva está cancelada.'),
                'no_show'   => fail(409, 'no_show', 'La ventana de llegada se cerró y la reserva está «no presentada». Si hay mesa, siéntalo como cliente sin reserva.'),
                'pending_payment' => fail(409, 'pending_payment', 'Esta reserva de grupo aún no ha pagado la señal: no está confirmada.'),
                'confirmed' => null,
            };

            $now = Clock::now();
            $start = Clock::parse($r['start_at']);
            $end = Clock::parse($r['end_at']);
            $open = Clock::plus($start, -(int) $r['arrival_before_min']);
            $tableId = (int) $r['table_id'];
            $table = Db::one('SELECT id, number, zone, active FROM dining_tables WHERE id = ? FOR UPDATE', [$tableId]);
            $extra = self::extraTables($id, true);
            // Mesas de la reserva que no están libres ahora (la principal y las unidas).
            $busy = [];
            $occupied = self::occupiedTables($id);
            foreach ([(int) $table['id'] => $table] + $extra as $tid => $t) {
                if ((int) $t['active'] !== 1 || in_array($tid, $occupied, true)
                    || ($now < $start && self::blockedTables([$tid], $now, $start, $id) !== [])) {
                    $busy[] = self::code($t);
                }
            }
            $busyNow = $busy !== [];
            $busyText = (count($busy) === 1 ? 'La mesa ' : 'Las mesas ') . implode(', ', $busy);

            if ($now < $open) {
                if (!$early) {
                    fail(409, 'too_early', 'Llegada anticipada: la ventana de llegada empieza a las ' . $open->format('H:i') . '.',
                        ['early_possible' => !$busyNow, 'reservation_id' => (int) $r['id']]);
                }
                if ($busyNow) {
                    fail(409, 'table_busy', "{$busyText} no está libre hasta la hora de la reserva. Esperad a la ventana de llegada.");
                }
            } elseif ($busyNow && $extra !== []) {
                // Mesas unidas: no se recolocan solas; el personal decide (cambiar mesa).
                fail(409, 'no_table', "{$busyText} de este grupo sigue ocupada. Usa «Cambiar mesa» o «Unir mesas» para sentarlos en otras.",
                    ['alert' => true]);
            } elseif ($busyNow) {
                $other = self::pickTable((int) $r['party_size'], min($now, $start), $end, (int) $r['id'], true);
                if ($other === null) {
                    fail(409, 'no_table', "La mesa " . self::code($table) . " sigue ocupada y no hay otra mesa libre para {$r['party_size']} personas. Avisa al responsable de sala.",
                        ['alert' => true]);
                }
                $tableId = (int) $other['id'];
            }

            Db::exec(
                "UPDATE reservations SET status = 'seated', arrived_at = ?, table_id = ? WHERE id = ?",
                [Clock::sql($now), $tableId, $r['id']]
            );
            return (int) $r['id'];
        });
        return self::mustLoad($id);
    }

    /** RF-25: la reserva en curso pasa a «completada» y la mesa queda libre. */
    public static function complete(int $id): array
    {
        Db::tx(function () use ($id) {
            $r = Db::one('SELECT status FROM reservations WHERE id = ? FOR UPDATE', [$id]);
            if ($r === null) {
                fail(404, 'not_found', 'Reserva no encontrada.');
            }
            if ($r['status'] === 'completed') {
                return;
            }
            if ($r['status'] !== 'seated') {
                fail(409, 'not_seated', 'Solo se pueden terminar las reservas en curso.');
            }
            Db::exec("UPDATE reservations SET status = 'completed', completed_at = ? WHERE id = ?", [Clock::sql(), $id]);
        });
        return self::mustLoad($id);
    }

    /** RF-26: más comensales en una reserva en curso, hasta la capacidad de todas sus mesas. */
    public static function increaseParty(int $id, array $in): array
    {
        V::only($in, ['party']);
        $party = V::int($in['party'] ?? null, 'party', 1, 255);
        Db::tx(function () use ($id, $party) {
            $r = Db::one('SELECT status, party_size FROM reservations WHERE id = ? FOR UPDATE', [$id]);
            if ($r === null) {
                fail(404, 'not_found', 'Reserva no encontrada.');
            }
            if ($r['status'] !== 'seated') {
                fail(409, 'not_seated', 'Solo se puede cambiar el número de comensales de una reserva en curso.');
            }
            if ($party <= (int) $r['party_size']) {
                fail(422, 'invalid_party', 'Indica un número de comensales mayor que el actual.', ['field' => 'party']);
            }
            $capacity = self::mustLoad($id)['capacity'];
            if ($party > $capacity) {
                fail(409, 'over_capacity', "Sus mesas admiten como máximo {$capacity} comensales. Une otra mesa para sentar a más.");
            }
            Db::exec('UPDATE reservations SET party_size = ? WHERE id = ?', [$party, $id]);
        });
        return self::mustLoad($id);
    }

    // ------------------------------------------------------------------
    // Cambiar y unir mesas (panel del personal)
    // ------------------------------------------------------------------

    /** Mesas activas ordenadas por id, opcionalmente bloqueadas FOR UPDATE. */
    private static function activeTables(bool $lock): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM dining_tables WHERE active ORDER BY id' . ($lock ? ' FOR UPDATE' : '')) as $t) {
            $out[(int) $t['id']] = $t;
        }
        return $out;
    }

    /**
     * Intervalo que tiene que estar libre para sentar o mover una reserva: el
     * suyo si aún no ha llegado; desde ahora hasta su fin si ya está en curso.
     */
    private static function window(array $r): array
    {
        $end = Clock::parse($r['end_at']);
        if ($r['status'] === 'seated') {
            $now = Clock::now();
            return [$now, max($end, Clock::plus($now, 1))];
        }
        return [Clock::parse($r['block_start']), $end];
    }

    /** Ids de las mesas libres para la reserva $r durante su intervalo (y ahora, si está en curso). */
    private static function freeFor(array $r, array $tableIds): array
    {
        [$from, $to] = self::window($r);
        $busy = self::blockedTables($tableIds, $from, $to, (int) $r['id']);
        if ($r['status'] === 'seated') {
            $busy = array_merge($busy, self::occupiedTables((int) $r['id']));
        }
        return array_values(array_diff($tableIds, $busy));
    }

    private static function lockMovable(int $id): array
    {
        $r = Db::one('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$id]);
        if ($r === null) {
            fail(404, 'not_found', 'Reserva no encontrada.');
        }
        if (!in_array($r['status'], ['confirmed', 'seated', 'pending_payment'], true)) {
            fail(409, 'not_movable', 'Solo se pueden cambiar las mesas de una reserva confirmada o en curso.');
        }
        return $r;
    }

    /** Señal para que los paneles abiertos recarguen (RF-34): cambia updated_at. */
    private static function touch(int $id): void
    {
        Db::exec('UPDATE reservations SET updated_at = LOCALTIMESTAMP(6) WHERE id = ?', [$id]);
    }

    /** Mesas a las que se puede mover o que se pueden unir a una reserva. */
    public static function freeTablesFor(int $id): array
    {
        self::markNoShows();
        $r = self::mustLoad($id);
        if (!in_array($r['status'], ['confirmed', 'seated', 'pending_payment'], true)) {
            fail(409, 'not_movable', 'Solo se pueden cambiar las mesas de una reserva confirmada o en curso.');
        }
        $own = [(int) $r['table_id'], ...array_keys(self::extraTables($id))];
        $candidates = array_diff(array_keys(self::activeTables(false)), $own);
        $free = self::freeFor($r, array_values($candidates));
        $tables = self::activeTables(false);
        $party = (int) $r['party_size'];
        $extraCapacity = $r['capacity'] - (int) $r['max_capacity'];
        return array_map(fn($tid) => [
            'id'           => $tid,
            'number'       => (int) $tables[$tid]['number'],
            'code'         => self::code($tables[$tid]),
            'zone'         => $tables[$tid]['zone'],
            'max_capacity' => (int) $tables[$tid]['max_capacity'],
            'join_only'    => (int) $tables[$tid]['join_only'] === 1,
            // Si se cambia la mesa principal por esta, ¿caben todos? (una extra nunca es la principal)
            'fits_as_main' => (int) $tables[$tid]['join_only'] === 0
                && (int) $tables[$tid]['max_capacity'] + $extraCapacity >= $party,
        ], $free);
    }

    /** Mueve la reserva de su mesa principal a otra libre. Las mesas unidas se mantienen. */
    public static function changeTable(int $id, array $in): array
    {
        V::only($in, ['table_id']);
        $target = V::int($in['table_id'] ?? null, 'table_id', 1, PHP_INT_MAX >> 32);
        Db::tx(function () use ($id, $target) {
            $r = self::lockMovable($id);
            $tables = self::activeTables(true);
            $extra = self::extraTables($id, true);
            if (!isset($tables[$target])) {
                fail(404, 'table_not_found', 'Esa mesa no existe o está fuera de servicio.');
            }
            if ($target === (int) $r['table_id'] || isset($extra[$target])) {
                fail(409, 'same_table', 'La reserva ya está en esa mesa.');
            }
            if ((int) $tables[$target]['join_only'] === 1) {
                fail(409, 'join_only', 'La mesa ' . self::code($tables[$target]) . ' es extra: solo se puede unir a otra mesa, no ser la principal.');
            }
            $capacity = (int) $tables[$target]['max_capacity'] + array_sum(array_map(fn($t) => (int) $t['max_capacity'], $extra));
            if ($capacity < (int) $r['party_size']) {
                fail(409, 'over_capacity', "En la mesa " . self::code($tables[$target]) . " no caben {$r['party_size']} personas. Elige otra o une mesas.");
            }
            if (self::freeFor($r, [$target]) === []) {
                fail(409, 'table_busy', "La mesa " . self::code($tables[$target]) . " no está libre durante la reserva.");
            }
            Db::exec('UPDATE reservations SET table_id = ? WHERE id = ?', [$target, $id]);
        });
        return self::mustLoad($id);
    }

    /** Une una o varias mesas libres a la reserva (grupos que no caben en una sola). */
    public static function joinTables(int $id, array $in): array
    {
        V::only($in, ['table_ids']);
        $wanted = V::idList($in['table_ids'] ?? null, 'table_ids', 10);
        if ($wanted === []) {
            V::invalid('table_ids', 'Elige al menos una mesa para unir.');
        }
        Db::tx(function () use ($id, $wanted) {
            $r = self::lockMovable($id);
            $tables = self::activeTables(true);
            $extra = self::extraTables($id, true);
            foreach ($wanted as $tid) {
                if (!isset($tables[$tid])) {
                    fail(404, 'table_not_found', 'Alguna de esas mesas no existe o está fuera de servicio.');
                }
                if ($tid === (int) $r['table_id'] || isset($extra[$tid])) {
                    fail(409, 'same_table', "La mesa " . self::code($tables[$tid]) . " ya es de esta reserva.");
                }
            }
            $busy = array_diff($wanted, self::freeFor($r, $wanted));
            if ($busy) {
                $numbers = implode(', ', array_map(fn($tid) => self::code($tables[$tid]), $busy));
                fail(409, 'table_busy', "No están libres durante la reserva: mesa {$numbers}.");
            }
            foreach ($wanted as $tid) {
                Db::exec('INSERT INTO reservation_tables (reservation_id, table_id) VALUES (?, ?)', [$id, $tid]);
            }
            self::touch($id);
        });
        return self::mustLoad($id);
    }

    /** Separa una mesa unida, si las que quedan siguen teniendo sitio para todos. */
    public static function separateTable(int $id, array $in): array
    {
        V::only($in, ['table_id']);
        $tid = V::int($in['table_id'] ?? null, 'table_id', 1, PHP_INT_MAX >> 32);
        Db::tx(function () use ($id, $tid) {
            $r = self::lockMovable($id);
            $extra = self::extraTables($id, true);
            if (!isset($extra[$tid])) {
                fail(409, 'not_joined', 'Esa mesa no está unida a esta reserva.');
            }
            $main = (int) Db::value('SELECT max_capacity FROM dining_tables WHERE id = ?', [$r['table_id']]);
            unset($extra[$tid]);
            $left = $main + array_sum(array_map(fn($t) => (int) $t['max_capacity'], $extra));
            if ($left < (int) $r['party_size']) {
                fail(409, 'over_capacity', "Sin esa mesa solo quedan {$left} plazas para {$r['party_size']} personas.");
            }
            Db::exec('DELETE FROM reservation_tables WHERE reservation_id = ? AND table_id = ?', [$id, $tid]);
            self::touch($id);
        });
        return self::mustLoad($id);
    }

    /**
     * Mesas libres ahora para un cliente sin reserva durante toda la duración
     * de reserva. Todas, no solo las de su tamaño: fits indica las que bastan
     * solas; para grupos grandes se eligen varias y se unen.
     */
    public static function walkInTables(int $party): array
    {
        self::markNoShows();
        $now = Clock::now();
        $end = Clock::plus($now, Settings::get('duration_min'));
        $tables = self::activeTables(false);
        $ids = array_keys($tables);
        $busy = array_merge(self::blockedTables($ids, $now, $end), self::occupiedTables());
        $free = [];
        foreach ($tables as $tid => $t) {
            if (!in_array($tid, $busy, true)) {
                // Una mesa extra nunca basta sola: solo se une a otras.
                $t['fits'] = (int) $t['join_only'] === 0 && (int) $t['min_capacity'] <= $party && $party <= (int) $t['max_capacity'];
                $t['code'] = self::code($t);
                $free[] = $t;
            }
        }
        usort($free, fn($a, $b) => [$b['fits'], $a['join_only'], $a['max_capacity'], $a['code']] <=> [$a['fits'], $b['join_only'], $b['max_capacity'], $b['code']]);
        return $free;
    }

    /**
     * RF-33: sienta a clientes sin reserva; la reserva nace «en curso». Con
     * table_ids se sientan en varias mesas unidas (la mayor es la principal).
     */
    public static function walkIn(int $staffId, array $in): array
    {
        V::only($in, ['party', 'table_id', 'table_ids']);
        $party = V::int($in['party'] ?? null, 'party', 1, 50);
        $wanted = isset($in['table_id']) ? V::int($in['table_id'], 'table_id', 1, PHP_INT_MAX >> 32) : null;
        $several = isset($in['table_ids']) ? V::idList($in['table_ids'], 'table_ids', 10) : null;
        self::markNoShows();
        $now = Clock::now();
        $end = Clock::plus($now, Settings::get('duration_min'));

        $id = Db::tx(function () use ($staffId, $party, $wanted, $several, $now, $end) {
            if ($several !== null && count($several) > 1) {
                $tables = self::activeTables(true);
                foreach ($several as $tid) {
                    if (!isset($tables[$tid])) {
                        fail(404, 'table_not_found', 'Alguna de esas mesas no existe o está fuera de servicio.');
                    }
                }
                $busy = array_intersect($several, array_merge(self::blockedTables($several, $now, $end), self::occupiedTables()));
                if ($busy) {
                    $numbers = implode(', ', array_map(fn($tid) => self::code($tables[$tid]), $busy));
                    fail(409, 'table_busy', "No están libres durante toda la reserva: mesa {$numbers}.");
                }
                $capacity = array_sum(array_map(fn($tid) => (int) $tables[$tid]['max_capacity'], $several));
                if ($capacity < $party) {
                    fail(409, 'over_capacity', "Esas mesas suman {$capacity} plazas para {$party} personas. Une alguna más.");
                }
                // La principal es la mayor de las que no son extra.
                usort($several, fn($a, $b) => [(int) $tables[$a]['join_only'], -(int) $tables[$a]['max_capacity']]
                    <=> [(int) $tables[$b]['join_only'], -(int) $tables[$b]['max_capacity']]);
                if ((int) $tables[$several[0]]['join_only'] === 1) {
                    fail(409, 'join_only', 'Las mesas extra solo se unen a otra mesa: elige también una mesa de sala o de terraza.');
                }
                $main = array_shift($several);
                $id = self::insert([
                    'source' => 'walkin', 'table_id' => $main, 'party' => $party,
                    'start' => $now, 'end' => $end, 'arrived' => $now, 'status' => 'seated', 'created_by' => $staffId,
                ]);
                foreach ($several as $tid) {
                    Db::exec('INSERT INTO reservation_tables (reservation_id, table_id) VALUES (?, ?)', [$id, $tid]);
                }
                return $id;
            }
            if ($several !== null && count($several) === 1) {
                $wanted = $several[0];
            }
            if ($wanted !== null && (int) Db::value('SELECT join_only FROM dining_tables WHERE id = ?', [$wanted]) === 1) {
                fail(409, 'join_only', 'Las mesas extra solo se unen a otra mesa: elige también una mesa de sala o de terraza.');
            }
            $tables = self::suitableTables($party, true);
            $ids = array_map('intval', array_column($tables, 'id'));
            $busy = array_merge(self::blockedTables($ids, $now, $end), self::occupiedTables());
            $free = array_values(array_filter($tables, fn($t) => !in_array((int) $t['id'], $busy, true)));
            if ($wanted !== null) {
                $free = array_values(array_filter($free, fn($t) => (int) $t['id'] === $wanted));
            }
            if (!$free) {
                fail(409, 'no_table', $wanted !== null
                    ? 'Esa mesa no está libre para ese grupo durante toda la duración de la reserva.'
                    : "No hay ninguna mesa libre para {$party} personas durante toda la duración de la reserva. Puedes unir varias mesas.");
            }
            return self::insert([
                'source' => 'walkin', 'table_id' => (int) $free[0]['id'], 'party' => $party,
                'start' => $now, 'end' => $end, 'arrived' => $now, 'status' => 'seated', 'created_by' => $staffId,
            ]);
        });
        return self::mustLoad($id);
    }

    /** RF-22 y RF-27: reservas de una fecha, con búsqueda opcional por nombre o teléfono. */
    public static function agenda(DateTimeImmutable $date, ?string $q): array
    {
        self::markNoShows();
        $sql = 'SELECT r.id FROM reservations r LEFT JOIN users u ON u.id = r.user_id
                 WHERE r.start_at >= ? AND r.start_at < ?';
        $params = [Clock::sql($date->setTime(0, 0)), Clock::sql($date->setTime(0, 0)->modify('+1 day'))];
        if ($q !== null && $q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $conds = ['search_key(r.guest_name) LIKE search_key(?)', 'search_key(u.name) LIKE search_key(?)'];
            array_push($params, $like, $like);
            $digits = preg_replace('/\D/', '', $q);
            if (strlen($digits) >= 3) {
                $conds[] = 'r.guest_phone LIKE ?';
                $conds[] = "REPLACE(u.phone, '+', '') LIKE ?";
                array_push($params, "%{$digits}%", "%{$digits}%");
            }
            $sql .= ' AND (' . implode(' OR ', $conds) . ')';
        }
        $sql .= ' ORDER BY r.start_at, r.id';
        return array_map(fn($row) => self::load((int) $row['id']), Db::all($sql, $params));
    }

    /** RF-34: estado en sala de cada mesa (libre, ocupada o fuera de servicio). */
    public static function room(): array
    {
        self::markNoShows();
        $now = Clock::now();
        $tables = Db::all('SELECT id, number, zone, min_capacity, max_capacity, active, join_only, pos_x, pos_y, shape
                             FROM dining_tables WHERE deleted_at IS NULL ORDER BY join_only, zone, number');
        // Una reserva con mesas unidas aparece en cada una de sus mesas.
        $loaded = [];
        $get = function (int $rid) use (&$loaded): array {
            return $loaded[$rid] ??= self::load($rid);
        };
        $seated = [];
        foreach (Db::all("SELECT reservation_id, table_id FROM table_blocks WHERE status = 'seated'") as $row) {
            $seated[(int) $row['table_id']] = $get((int) $row['reservation_id']);
        }
        $next = [];
        $rows = Db::all(
            "SELECT reservation_id, table_id FROM table_blocks
              WHERE status IN ('confirmed','pending_payment') AND start_at >= ? AND start_at < ?
              ORDER BY start_at",
            [Clock::sql(Clock::plus($now, -60)), Clock::sql($now->setTime(0, 0)->modify('+1 day'))]
        );
        foreach ($rows as $row) {
            $next[(int) $row['table_id']] ??= $get((int) $row['reservation_id']);
        }
        // RF-45: bloqueos en vigor ahora (NULL = todas las mesas).
        $holds = [];
        $rows = Db::all('SELECT table_id, reason, ends_at FROM table_holds WHERE starts_at <= ? AND ends_at > ? ORDER BY ends_at DESC',
            [Clock::sql($now), Clock::sql($now)]);
        foreach ($rows as $h) {
            $hold = ['reason' => $h['reason'], 'until' => Clock::parse($h['ends_at'])->format('H:i')];
            foreach ($h['table_id'] === null ? array_column($tables, 'id') : [$h['table_id']] as $tid) {
                $holds[(int) $tid] = $hold;
            }
        }
        $out = [];
        foreach ($tables as $t) {
            $id = (int) $t['id'];
            $current = $seated[$id] ?? null;
            $state = match (true) {
                (int) $t['active'] !== 1 => 'out_of_service',
                isset($seated[$id])      => 'occupied',
                isset($holds[$id])       => 'blocked',
                default                  => 'free',
            };
            $out[] = [
                'id'           => $id,
                'number'       => (int) $t['number'],
                'code'         => self::code($t),
                'zone'         => $t['zone'],
                'min_capacity' => (int) $t['min_capacity'],
                'max_capacity' => (int) $t['max_capacity'],
                'join_only'    => (int) $t['join_only'] === 1,   // extra: sin plano, solo para unir
                // Mesa unida a otra en una reserva en curso: el panel la muestra dentro de la principal.
                'joined_to'    => $current !== null && (int) $current['table_id'] !== $id ? $current['table_code'] : null,
                // Mapa: sin posición guardada, el panel coloca la mesa en una cuadrícula.
                'pos_x'        => $t['pos_x'] === null ? null : (int) $t['pos_x'],
                'pos_y'        => $t['pos_y'] === null ? null : (int) $t['pos_y'],
                'shape'        => $t['shape'],
                'state'        => $state,
                'hold'         => $holds[$id] ?? null,
                'current'      => $current !== null ? Present::staff($current) : null,
                'next'         => isset($next[$id]) ? Present::staff($next[$id]) : null,
            ];
        }
        return $out;
    }

    /**
     * Marca de cambios para que el panel sepa cuándo recargar sin recargar la
     * página (RF-34). Incluye los bloqueos y el minuto actual: un bloqueo que
     * empieza o una reserva que se acerca cambian la sala sin tocar la base de datos.
     */
    public static function version(): string
    {
        $data = (string) Db::value(
            "SELECT CONCAT(
                GREATEST(COALESCE((SELECT MAX(updated_at) FROM reservations), '2000-01-01'),
                         COALESCE((SELECT MAX(updated_at) FROM dining_tables), '2000-01-01')),
                '|', (SELECT COUNT(*) FROM table_holds), '|', COALESCE((SELECT MAX(id) FROM table_holds), 0),
                '|', (SELECT COUNT(*) FROM group_requests WHERE status = 'pending'), '|', COALESCE((SELECT MAX(id) FROM group_requests), 0))"
        );
        return $data . '|' . Clock::now()->format('H:i');
    }

    // ------------------------------------------------------------------
    // Administración de mesas (RF-35)
    // ------------------------------------------------------------------

    /**
     * Crea ($id null) o modifica una mesa. Si una mesa se desactiva o deja de
     * admitir a alguna de sus reservas futuras, estas pasan a otra mesa
     * adecuada y, si no hay ninguna, se cancelan con aviso al titular (DP-04).
     */
    public static function saveTable(?int $id, array $in): array
    {
        V::only($in, ['number', 'zone', 'min_capacity', 'max_capacity', 'active', 'join_only']);
        self::markNoShows();
        return Db::tx(function () use ($id, $in) {
            $all = Db::all('SELECT * FROM dining_tables WHERE deleted_at IS NULL ORDER BY id FOR UPDATE');
            $current = null;
            if ($id !== null) {
                foreach ($all as $t) {
                    if ((int) $t['id'] === $id) {
                        $current = $t;
                    }
                }
                if ($current === null) {
                    fail(404, 'not_found', 'Mesa no encontrada.');
                }
            }
            $number = array_key_exists('number', $in) ? V::int($in['number'], 'number', 1, 999) : ($current ? (int) $current['number'] : V::int(null, 'number', 1, 999));
            $zone = array_key_exists('zone', $in) ? V::text($in['zone'], 'zone', 1, 40) : ($current['zone'] ?? V::text(null, 'zone', 1, 40));
            $min = array_key_exists('min_capacity', $in) ? V::int($in['min_capacity'], 'min_capacity', 1, 50) : ($current ? (int) $current['min_capacity'] : V::int(null, 'min_capacity', 1, 50));
            $max = array_key_exists('max_capacity', $in) ? V::int($in['max_capacity'], 'max_capacity', 1, 50) : ($current ? (int) $current['max_capacity'] : V::int(null, 'max_capacity', 1, 50));
            $active = array_key_exists('active', $in) ? V::bool($in['active'], 'active') : ($current ? (int) $current['active'] === 1 : true);
            $joinOnly = array_key_exists('join_only', $in) ? V::bool($in['join_only'], 'join_only') : ($current ? (int) $current['join_only'] === 1 : false);
            if ($min > $max) {
                V::invalid('min_capacity', 'La capacidad mínima no puede ser mayor que la máxima.');
            }
            $code = self::code(['zone' => $zone, 'number' => $number]);
            foreach ($all as $t) {
                if (self::code($t) === $code && (int) $t['id'] !== $id) {
                    fail(409, 'duplicate', "Ya existe la mesa {$code}. Las mesas se nombran con la inicial de la zona y el número.");
                }
            }

            if ($current === null) {
                $id = Db::insert(
                    'INSERT INTO dining_tables (number, zone, min_capacity, max_capacity, active, join_only) VALUES (?, ?, ?, ?, ?, ?)',
                    [$number, $zone, $min, $max, $active ? 1 : 0, $joinOnly ? 1 : 0]
                );
                return ['table_id' => $id, 'moved' => 0, 'cancelled' => 0];
            }

            $changesUse = !$active || $min !== (int) $current['min_capacity'] || $max !== (int) $current['max_capacity']
                || $joinOnly !== ((int) $current['join_only'] === 1);
            $seated = Db::value("SELECT COUNT(*) FROM table_blocks WHERE table_id = ? AND status = 'seated'", [$id]);
            if ((int) $seated > 0 && $changesUse) {
                fail(409, 'table_in_use', 'La mesa tiene una reserva en curso. Termínala antes de desactivarla o cambiar su capacidad.');
            }
            // Mesas de un grupo (principal o unida): no se recolocan solas.
            $joined = Db::value(
                "SELECT COUNT(*) FROM table_blocks tb
                  WHERE tb.table_id = ? AND tb.status = 'confirmed'
                    AND EXISTS (SELECT 1 FROM reservation_tables rt WHERE rt.reservation_id = tb.reservation_id)",
                [$id]
            );
            if ((int) $joined > 0 && $changesUse) {
                fail(409, 'table_joined', 'La mesa forma parte de una reserva con mesas unidas. Sepárala o cambia la reserva de mesa antes.');
            }
            Db::exec(
                'UPDATE dining_tables SET number = ?, zone = ?, min_capacity = ?, max_capacity = ?, active = ?, join_only = ?,
                        pos_x = CASE WHEN ? THEN NULL ELSE pos_x END, pos_y = CASE WHEN ? THEN NULL ELSE pos_y END WHERE id = ?',
                [$number, $zone, $min, $max, $active ? 1 : 0, $joinOnly ? 1 : 0, $joinOnly ? 1 : 0, $joinOnly ? 1 : 0, $id]
            );
            [$moved, $cancelled] = self::reaccommodate($id);
            return ['table_id' => $id, 'moved' => $moved, 'cancelled' => $cancelled];
        });
    }

    /** Recoloca las reservas confirmadas que su mesa ya no puede atender. */
    private static function reaccommodate(int $tableId): array
    {
        $t = Db::one('SELECT active, join_only, min_capacity, max_capacity FROM dining_tables WHERE id = ?', [$tableId]);
        $rows = Db::all(
            "SELECT * FROM reservations WHERE table_id = ? AND status = 'confirmed' ORDER BY start_at FOR UPDATE",
            [$tableId]
        );
        $moved = 0;
        $cancelled = 0;
        foreach ($rows as $r) {
            $party = (int) $r['party_size'];
            if ((int) $t['active'] === 1 && (int) $t['join_only'] === 0 && $party >= (int) $t['min_capacity'] && $party <= (int) $t['max_capacity']) {
                continue;
            }
            $other = self::pickTable($party, Clock::parse($r['start_at']), Clock::parse($r['end_at']), (int) $r['id']);
            if ($other !== null && (int) $other['id'] !== $tableId) {
                Db::exec('UPDATE reservations SET table_id = ? WHERE id = ?', [$other['id'], $r['id']]);
                $moved++;
            } else {
                self::restaurantCancel($r);
                $cancelled++;
            }
        }
        return [$moved, $cancelled];
    }

    /**
     * Elimina una mesa. No se puede si tiene reservas pendientes o en curso
     * (hay que moverlas antes). Si nunca se ha usado se borra; si tiene
     * reservas antiguas, queda marcada como eliminada para conservar el
     * historial, y su código (p. ej. S-9) vuelve a quedar libre.
     */
    public static function deleteTable(int $id): array
    {
        self::markNoShows();
        return Db::tx(function () use ($id) {
            $t = Db::one('SELECT * FROM dining_tables WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id])
                ?? fail(404, 'not_found', 'Mesa no encontrada.');
            $pending = Db::all(
                "SELECT DISTINCT reservation_id FROM table_blocks
                  WHERE table_id = ? AND reservation_id IS NOT NULL
                    AND (status = 'seated' OR (status IN ('confirmed','pending_payment') AND end_at > ?))",
                [$id, Clock::sql()]
            );
            if ($pending) {
                fail(409, 'table_in_use', 'La mesa ' . self::code($t) . ' tiene ' . count($pending)
                    . ' reserva(s) pendiente(s) o en curso. Muévelas a otra mesa («Mesas…» en la agenda) o cancélalas antes de eliminarla.');
            }
            $used = Db::value('SELECT 1 FROM reservations WHERE table_id = ? UNION SELECT 1 FROM reservation_tables WHERE table_id = ? LIMIT 1', [$id, $id]);
            if ($used === null) {
                Db::exec('DELETE FROM dining_tables WHERE id = ?', [$id]);   // sus bloqueos se borran en cascada
            } else {
                Db::exec('DELETE FROM table_holds WHERE table_id = ?', [$id]);
                Db::exec('UPDATE dining_tables SET deleted_at = ?, active = false, pos_x = NULL, pos_y = NULL WHERE id = ?', [Clock::sql(), $id]);
            }
            return ['deleted' => self::code($t)];
        });
    }

    /**
     * «Cerrar local»: termina todas las reservas en curso y deja libres todas
     * las mesas. Protegido con el PIN del restaurante (Admin::checkClosePin).
     */
    public static function closeLocal(array $staff, array $in): array
    {
        V::only($in, ['pin']);
        Admin::checkClosePin($in['pin'] ?? null);
        $n = Db::tx(fn() => Db::exec(
            "UPDATE reservations SET status = 'completed', completed_at = ? WHERE status = 'seated'",
            [Clock::sql()]
        ));
        error_log(sprintf('MesaLista: «Cerrar local» por el usuario %d (%d reservas terminadas)', $staff['id'], $n));
        return ['completed' => $n];
    }

    // ------------------------------------------------------------------
    // Cuentas de cliente (RF-05, RP-02)
    // ------------------------------------------------------------------

    /**
     * Elimina la cuenta: las reservas confirmadas pasan a «cancelada» y todas
     * se conservan sin datos personales.
     */
    public static function deleteClient(array $user): void
    {
        $userId = (int) $user['id'];
        Db::tx(function () use ($userId, $user) {
            Db::value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            // RF-44: se devuelve la señal de las reservas que aún estaban en plazo de cancelación.
            $now = Clock::now();
            foreach (Db::all("SELECT id, start_at, change_deadline_h FROM reservations WHERE user_id = ? AND status = 'confirmed' FOR UPDATE", [$userId]) as $r) {
                if ($now <= Clock::plus(Clock::parse($r['start_at']), -60 * (int) $r['change_deadline_h'])) {
                    Payments::markRefund((int) $r['id']);
                }
            }
            Db::exec(
                "UPDATE reservations SET status = 'cancelled', cancelled_by = 'holder', cancelled_at = ?, payment_due_at = NULL
                  WHERE user_id = ? AND status IN ('confirmed','pending_payment')",
                [Clock::sql(), $userId]
            );
            Db::exec(
                'DELETE FROM reservation_menu_options rmo
                  USING reservations r WHERE r.id = rmo.reservation_id AND r.user_id = ?',
                [$userId]
            );
            Db::exec(
                'UPDATE reservations SET note = NULL, guest_name = NULL, guest_phone = NULL, request_id = NULL
                  WHERE user_id = ?',
                [$userId]
            );
            Db::exec('DELETE FROM email_outbox WHERE to_email = ?', [$user['email']]);
            Db::exec('DELETE FROM users WHERE id = ?', [$userId]); // sesiones, avisos e intentos en cascada
        });
    }
}
