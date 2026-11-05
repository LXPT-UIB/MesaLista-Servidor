<?php
declare(strict_types=1);

// Punto de entrada único de la API: Apache envía aquí todo /api/v1/*.
//
// La web (desde su PHP) y la app se identifican con «Authorization: Bearer
// <token>». Como el navegador nunca envía ese token por su cuenta, no hace falta
// protección CSRF ni CORS: la API no se llama desde el JavaScript de otro sitio.

require __DIR__ . '/../php/bootstrap.php';

try {
    // RS-01: la API no procesa nada que no llegue por HTTPS.
    if (config('require_https', true) && ($_SERVER['HTTPS'] ?? '') !== 'on') {
        fail(403, 'https_required', 'La API solo acepta conexiones HTTPS.');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !str_starts_with($type, 'application/json')) {
            fail(415, 'unsupported_type', 'El cuerpo debe enviarse como JSON.');
        }
    }

    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!preg_match('#^/api/v1(/.*)?$#', $path, $m)) {
        fail(404, 'not_found', 'Recurso no encontrado. La API está en /api/v1/.');
    }
    $path = rtrim($m[1] ?? '', '/') ?: '/';

    $router = new Router();
    require ML_ROOT . '/routes.php';
    $result = $router->dispatch($method, $path);

    if (isset($result['_redirect'])) {
        // Vuelta de PayPal: a una plantilla de config.php (front.*) o a la propia API.
        header('Location: ' . $result['_redirect'], true, 303);
        header('Cache-Control: no-store');
    } elseif (isset($result['_file'])) {
        // Descarga (p. ej. el CSV de la agenda): ['_file' => nombre, '_type' => tipo, '_body' => contenido].
        header('Content-Type: ' . $result['_type']);
        header('Content-Disposition: attachment; filename="' . $result['_file'] . '"');
        header('Cache-Control: no-store');
        echo $result['_body'];
    } else {
        $status = $result['_status'] ?? 200;
        unset($result['_status']);
        Http::json($status, $result);
    }
} catch (ApiError $e) {
    Http::json($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra]);
} catch (Throwable $e) {
    if (Db::isOverlap($e)) {
        // RF-09: la base de datos ha rechazado dos reservas solapadas en la misma
        // mesa (segunda protección). Para el cliente es un conflicto normal.
        error_log(sprintf('MesaLista: solapamiento rechazado por la base de datos en %s:%d', basename($e->getFile()), $e->getLine()));
        Http::json(409, ['error' => ['code' => 'conflict', 'message' => 'Esa hora ya no está disponible. Elige otra.']]);
    } else {
        // RS-11: al registro solo va el tipo de error y dónde ocurrió, nunca datos
        // de la petición (los mensajes de PostgreSQL pueden incluir valores).
        $code = $e instanceof PDOException ? ' SQLSTATE ' . $e->getCode() : '';
        error_log(sprintf('MesaLista: %s%s en %s:%d', get_class($e), $code, basename($e->getFile()), $e->getLine()));
        Http::json(500, ['error' => ['code' => 'server_error', 'message' => 'Error interno del servidor. Inténtalo de nuevo.']]);
    }
}

// Los correos y las devoluciones de PayPal se hacen después de responder, para
// no retrasar la respuesta. Lo que falle lo reintenta bin/worker.php cada minuto.
if (Mail::$queued || Payments::$refundsQueued) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        if (Payments::$refundsQueued) {
            Payments::processRefunds();
        }
    } catch (Throwable $e) {
        error_log('MesaLista: devolución aplazada (' . get_class($e) . ')');
    }
    try {
        Mail::flush();
    } catch (Throwable $e) {
        error_log('MesaLista: envío de correo aplazado (' . get_class($e) . ')');
    }
}
