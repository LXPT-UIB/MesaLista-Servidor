<?php
declare(strict_types=1);

/** Representación JSON de las reservas para la app y para el panel. */
final class Present
{
    private static function base(array $r): array
    {
        $start = Clock::parse($r['start_at']);
        $end = Clock::parse($r['end_at']);
        return [
            'id'           => (int) $r['id'],
            'status'       => $r['status'],
            'source'       => $r['source'],
            'date'         => $start->format('Y-m-d'),
            'time'         => $start->format('H:i'),
            'end_time'     => $end->format('H:i'),
            'party'        => (int) $r['party_size'],
            'table'        => $r['table_code'],      // S-1, T-2, E-1…
            'tables'       => $r['table_codes'],     // principal y unidas
            'zone'         => $r['zone'],
            'menu_options' => $r['menu_options'],
            'note'         => $r['note'],
            'deposit'      => self::deposit($r),
        ];
    }

    /**
     * RF-44: señal de una reserva de grupo, o null si no lleva. payment: null
     * (sin pagar), captured, refund_pending o refunded.
     */
    private static function deposit(array $r): ?array
    {
        if ($r['deposit_cents'] === null) {
            return null;
        }
        $due = $r['payment_due_at'] === null ? null : Clock::parse($r['payment_due_at']);
        return [
            'amount'       => Payments::euros((int) $r['deposit_cents']),
            'cents'        => (int) $r['deposit_cents'],
            'due'          => $due?->format('Y-m-d H:i'),
            'seconds_left' => $due === null ? null : max(0, $due->getTimestamp() - Clock::now()->getTimestamp()),
            'payment'      => $r['payment_status'],
        ];
    }

    /** Para el titular en la app: incluye el QR vigente si la reserva está confirmada. */
    public static function client(array $r): array
    {
        $start = Clock::parse($r['start_at']);
        $deadline = Clock::plus($start, -60 * (int) $r['change_deadline_h']);
        return self::base($r) + [
            'modifiable'     => $r['status'] === 'confirmed' && Clock::now() <= $deadline,
            'deadline'       => $deadline->format('Y-m-d H:i'),
            'arrival_window' => [
                Clock::plus($start, -(int) $r['arrival_before_min'])->format('H:i'),
                Clock::plus($start, (int) $r['arrival_after_min'])->format('H:i'),
            ],
            'qr'             => $r['status'] === 'confirmed' ? Qr::make((int) $r['id'], (int) $r['qr_version']) : null,
            // Reservas de grupo: cambiar hora o comensales se pide por teléfono.
            'group'          => $r['deposit_cents'] !== null || $r['extra_tables'] !== [],
            'payable'        => $r['status'] === 'pending_payment' && Payments::configured()
                && $r['payment_due_at'] !== null && Clock::parse($r['payment_due_at']) > Clock::now(),
        ];
    }

    /** Para el personal: nombre y teléfono del titular, llegada y si tiene ticket vigente. */
    public static function staff(array $r): array
    {
        $linked = $r['user_id'] !== null;
        $start = Clock::parse($r['start_at']);
        return self::base($r) + [
            'name'           => $r['guest_name'] ?? $r['user_name'],
            'phone'          => $r['guest_phone'] ?? $r['user_phone'],
            'linked'         => $linked,
            'arrived_at'     => $r['arrived_at'] !== null ? Clock::parse($r['arrived_at'])->format('H:i') : null,
            'max_capacity'   => $r['capacity'],         // plazas sumando las mesas unidas
            'arrival_window' => [
                Clock::plus($start, -(int) $r['arrival_before_min'])->format('H:i'),
                Clock::plus($start, (int) $r['arrival_after_min'])->format('H:i'),
            ],
            'has_ticket'     => $r['source'] === 'local' && !$linked && $r['status'] === 'confirmed',
            'cancelled_by'   => $r['cancelled_by'],
        ];
    }

    /**
     * RF-29 y RP-07: el ticket solo muestra fecha, hora, comensales, QR y código.
     * Deja de existir cuando la reserva se vincula a una cuenta.
     */
    public static function ticket(array $r): array
    {
        if ($r['source'] !== 'local' || $r['user_id'] !== null || $r['status'] !== 'confirmed') {
            fail(409, 'no_ticket', 'Esta reserva no tiene un ticket vigente.');
        }
        $start = Clock::parse($r['start_at']);
        return [
            'date'  => $start->format('Y-m-d'),
            'time'  => $start->format('H:i'),
            'party' => (int) $r['party_size'],
            'code'  => $r['code'],
            'qr'    => Qr::make((int) $r['id'], (int) $r['qr_version']),
        ];
    }
}
