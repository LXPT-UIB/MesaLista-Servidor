<?php
declare(strict_types=1);

// Tareas de cron. PostgreSQL no tiene eventos programados, así que los
// procesos automáticos de la base de datos se lanzan desde aquí:
//
//   * * * * *  worker.php           cada minuto: marca las no presentadas y las
//                                   señales vencidas (RF-24, RF-44), reintenta
//                                   las devoluciones y envía los correos y
//                                   los avisos push pendientes
//   0 4 * * *  worker.php --diario  cada día a las 04:00: borra los datos
//                                   personales caducados (RP-03, RP-08)
//
// Las dos líneas las escribe install.sh en /etc/cron.d/mesalista.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../bootstrap.php';

try {
    if (in_array('--diario', $argv, true)) {
        Db::run('CALL sp_daily_purge(?)', [Clock::now()->format('Y-m-d')])->closeCursor();
        fwrite(STDOUT, date('c') . " limpieza diaria hecha\n");
        exit(0);
    }
    Booking::markNoShows();
    $refunds = Payments::processRefunds();
    if ($refunds > 0) {
        fwrite(STDOUT, date('c') . " devoluciones de señal: {$refunds}\n");
    }
    $sent = Mail::flush(50);
    if ($sent > 0) {
        fwrite(STDOUT, date('c') . " correos enviados: {$sent}\n");
    }
    $pushed = Push::flush(100);
    if ($pushed > 0) {
        fwrite(STDOUT, date('c') . " avisos push enviados: {$pushed}\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' worker: ' . get_class($e) . ' en ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
}
