<?php
declare(strict_types=1);

/** Parámetros del servicio (RF-37), leídos una vez por petición. */
final class Settings
{
    /** Nombre => [mínimo, máximo] admitidos al modificarlos desde el panel. */
    public const RANGES = [
        'duration_min'          => [30, 360],
        'slot_step_min'         => [5, 120],
        'min_advance_min'       => [0, 2880],
        'max_advance_days'      => [1, 365],
        'min_party'             => [1, 50],
        'max_party'             => [1, 50],
        'max_future_per_client' => [1, 50],
        'change_deadline_h'     => [0, 168],
        'arrival_before_min'    => [0, 120],
        'arrival_after_min'     => [1, 120],
        // RF-43 y RF-44: grupos grandes y señal (0 € = sin señal).
        'max_group_party'       => [2, 200],
        'deposit_per_person'    => [0, 100],
        'deposit_hold_min'      => [5, 1440],
    ];

    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values === null) {
            self::$values = [];
            foreach (Db::all('SELECT name, value FROM settings') as $row) {
                self::$values[$row['name']] = (int) $row['value'];
            }
        }
        return self::$values;
    }

    public static function get(string $name): int
    {
        $all = self::all();
        if (!isset($all[$name])) {
            throw new RuntimeException("Falta el parámetro {$name}");
        }
        return $all[$name];
    }

    public static function forget(): void
    {
        self::$values = null;
    }

    /** Opciones de menú activas: [id => nombre]. */
    public static function menuOptions(): array
    {
        $out = [];
        foreach (Db::all('SELECT id, name FROM menu_options WHERE active ORDER BY id') as $row) {
            $out[(int) $row['id']] = $row['name'];
        }
        return $out;
    }
}
