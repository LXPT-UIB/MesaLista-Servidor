<?php
declare(strict_types=1);

/** RF-47: reservas de un día en CSV, para abrirlas en Excel o LibreOffice. */
final class Export
{
    private const STATUS = [
        'confirmed' => 'Confirmada', 'seated' => 'En curso', 'completed' => 'Completada',
        'cancelled' => 'Cancelada', 'no_show' => 'No presentada', 'pending_payment' => 'Pendiente de pago',
    ];
    private const SOURCE = ['app' => 'App', 'local' => 'Local', 'walkin' => 'Sin reserva'];

    /** Devuelve [nombre de archivo, contenido]. */
    public static function dayCsv(DateTimeImmutable $date): array
    {
        $options = Settings::menuOptions();
        $rows = [['Fecha', 'Hora', 'Fin', 'Mesas', 'Zona', 'Comensales', 'Estado', 'Origen', 'Cliente', 'Teléfono', 'Opciones de menú', 'Nota', 'Llegada']];
        foreach (Booking::agenda($date, null) as $r) {
            $start = Clock::parse($r['start_at']);
            $rows[] = [
                $start->format('d/m/Y'),
                $start->format('H:i'),
                Clock::parse($r['end_at'])->format('H:i'),
                implode('+', $r['table_codes']),
                $r['zone'],
                (int) $r['party_size'],
                self::STATUS[$r['status']] ?? $r['status'],
                self::SOURCE[$r['source']] ?? $r['source'],
                $r['guest_name'] ?? $r['user_name'] ?? '',
                $r['guest_phone'] ?? $r['user_phone'] ?? '',
                implode(', ', array_map(fn($id) => $options[$id] ?? '', $r['menu_options'])),
                $r['note'] ?? '',
                $r['arrived_at'] !== null ? Clock::parse($r['arrived_at'])->format('H:i') : '',
            ];
        }
        // BOM y «;»: Excel en español abre el archivo con los acentos y las columnas bien.
        $out = "\u{FEFF}";
        foreach ($rows as $row) {
            $out .= implode(';', array_map([self::class, 'cell'], $row)) . "\r\n";
        }
        return ['reservas-' . $date->format('Y-m-d') . '.csv', $out];
    }

    /**
     * Una celda CSV. Las que empiezan por = + - @ (o tabulador) se marcan con un
     * apóstrofo para que la hoja de cálculo no las ejecute como fórmula, salvo
     * los números y teléfonos («+34600…»), que no pueden serlo.
     */
    private static function cell(mixed $value): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", (string) $value);
        if (!is_int($value) && $s !== '' && strpbrk($s[0], "=+-@\t") !== false && !preg_match('/^[+-]?[0-9][0-9 .]*$/', $s)) {
            $s = "'" . $s;
        }
        return '"' . str_replace('"', '""', $s) . '"';
    }
}
