<?php
declare(strict_types=1);

/** Acceso a PostgreSQL con PDO y consultas preparadas (RS-08). */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = config('db');
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['name']);
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Las fechas se guardan en hora de Madrid (timestamp sin zona), como PHP.
            self::$pdo->exec("SET TIME ZONE 'Europe/Madrid'");
            // Con READ COMMITTED, las comprobaciones de solapamiento que siguen a
            // un bloqueo de mesas ven siempre las reservas ya confirmadas (RF-09).
            self::$pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Ejecuta y devuelve el número de filas afectadas. */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** INSERT de una fila; devuelve su id (la última identidad generada en esta sesión). */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** "?, ?, ?" para una lista IN (...). */
    public static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Ejecuta $fn dentro de una transacción. Si PostgreSQL la aborta por un
     * interbloqueo (40P01), por no poder bloquear (55P03) o por un conflicto de
     * serialización (40001), la repite.
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $state = self::state($e);
                if (in_array($state, ['40P01', '55P03', '40001'], true) && $attempt < 4) {
                    usleep(random_int(5000, 30000));
                    continue;
                }
                throw $e;
            }
        }
    }

    public static function isDuplicateKey(Throwable $e): bool
    {
        return self::state($e) === '23505';
    }

    /** Dos reservas solapadas en la misma mesa (restricción ex_occupancy_overlap, RF-09). */
    public static function isOverlap(Throwable $e): bool
    {
        return self::state($e) === '23P01';
    }

    /** Código SQLSTATE de un error de PostgreSQL, o '' si no lo es. */
    private static function state(Throwable $e): string
    {
        return $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : '';
    }
}
