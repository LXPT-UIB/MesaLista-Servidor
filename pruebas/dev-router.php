<?php
// Servidor de desarrollo con el servidor integrado de PHP (hace de Apache):
//   php -d extension=pdo_pgsql -S 127.0.0.1:8091 -t publico pruebas/dev-router.php
// Solo para programar y probar en local, nunca en el servidor real.

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('#^/api/v1(/|$)#', $path)) {
    require __DIR__ . '/../publico/index.php';
    return true;
}
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo '{"error":{"code":"not_found","message":"Recurso no encontrado. La API está en /api/v1/."}}';
return true;
