<?php
declare(strict_types=1);

// Servidor falso de Azure Communication Services Email para CP-11 (lo arranca
// run.php con php -S). Comprueba la firma HMAC-SHA256 como Azure, apunta el
// mensaje en ML_AZURE_LOG y responde según el destinatario:
//   limite@…  → 429 (límite de envío)   rechazo@… → 400   el resto → 202

$key = base64_decode((string) getenv('ML_AZURE_KEY'));
$path = (string) $_SERVER['REQUEST_URI'];
$body = (string) file_get_contents('php://input');
$headers = array_change_key_case(getallheaders());

function reply(int $status, array $data, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/emails:send?api-version=2023-03-31') {
    reply(404, ['error' => ['code' => 'NotFound', 'message' => 'Resource not found.']]);
}
$date = $headers['x-ms-date'] ?? '';
$hash = base64_encode(hash('sha256', $body, true));
$stringToSign = "POST\n{$path}\n{$date};" . ($headers['host'] ?? '') . ";{$hash}";
$expected = 'HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature='
    . base64_encode(hash_hmac('sha256', $stringToSign, $key, true));
$fresh = ($t = strtotime($date)) !== false && abs(time() - $t) < 300;
if (($headers['x-ms-content-sha256'] ?? '') !== $hash || ($headers['authorization'] ?? '') !== $expected || !$fresh) {
    reply(401, ['error' => ['code' => 'Denied', 'message' => 'Denied by the resource provider.']]);
}

file_put_contents((string) getenv('ML_AZURE_LOG'), $body . "\n", FILE_APPEND);
$to = (string) (json_decode($body, true)['recipients']['to'][0]['address'] ?? '');
if (str_starts_with($to, 'limite@')) {
    reply(429, ['error' => ['code' => 'TooManyRequests', 'message' => 'Too many requests.']], ['Retry-After: 60']);
}
if (str_starts_with($to, 'rechazo@')) {
    reply(400, ['error' => ['code' => 'InvalidRecipient', 'message' => 'Recipient address is not valid.']]);
}
reply(202, ['id' => bin2hex(random_bytes(16)), 'status' => 'Running']);
