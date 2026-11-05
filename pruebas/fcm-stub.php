<?php
declare(strict_types=1);

// Servidor falso de Google para la prueba de los avisos push (lo arranca
// run.php con el servidor integrado de PHP). Hace lo mismo que Google:
//
//   POST /token                            comprueba la firma RS256 del JWT de la
//                                          cuenta de servicio con su clave pública
//                                          (ML_FCM_PUBKEY) y da un token de acceso
//   POST /v1/projects/<p>/messages:send    exige ese token y guarda el mensaje en
//                                          ML_FCM_LOG. Tokens de dispositivo de
//                                          prueba: «muerto…» → 404 UNREGISTERED,
//                                          «limite…» → 429.

const ACCESS = 'ya29.token-de-pruebas';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$reply = function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
};
$b64 = fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/'));

if ($path === '/token') {
    $parts = explode('.', (string) ($_POST['assertion'] ?? ''));
    $claims = count($parts) === 3 ? json_decode($b64($parts[1]), true) : null;
    $valid = is_array($claims)
        && ($_POST['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
        && ($claims['scope'] ?? '') === 'https://www.googleapis.com/auth/firebase.messaging'
        && ($claims['aud'] ?? '') === 'http://' . $_SERVER['HTTP_HOST'] . '/token'
        && ($claims['exp'] ?? 0) > time()
        && openssl_verify($parts[0] . '.' . $parts[1], $b64($parts[2]),
            (string) file_get_contents((string) getenv('ML_FCM_PUBKEY')), OPENSSL_ALGO_SHA256) === 1;
    $valid ? $reply(200, ['access_token' => ACCESS, 'expires_in' => 3599, 'token_type' => 'Bearer'])
           : $reply(400, ['error' => 'invalid_grant']);
    return;
}

if (preg_match('#^/v1/projects/[^/]+/messages:send$#', $path)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . ACCESS) {
        $reply(401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']]);
        return;
    }
    $raw = (string) file_get_contents('php://input');
    $token = (string) (json_decode($raw, true)['message']['token'] ?? '');
    if (str_starts_with($token, 'muerto')) {
        $reply(404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]]);
        return;
    }
    if (str_starts_with($token, 'limite')) {
        $reply(429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']]);
        return;
    }
    file_put_contents((string) getenv('ML_FCM_LOG'), $raw . "\n", FILE_APPEND);
    $reply(200, ['name' => 'projects/pruebas/messages/' . bin2hex(random_bytes(4))]);
    return;
}

$reply(404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND']]);
