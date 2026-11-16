<?php

// php -S için router. Testler her yol için yanıt kuyruğunu POST /__reset ile yükler,
// gelen istekleri GET /__log ile okur. Durum, istekler arasında OTP_STUB_STATE dosyasında tutulur.

$stateFile = getenv('OTP_STUB_STATE');
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['queue' => [], 'log' => []];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');

header('Content-Type: application/json');

if ($path === '/__reset') {
    file_put_contents($stateFile, json_encode(['queue' => json_decode($body, true) ?: [], 'log' => []]));
    return;
}
if ($path === '/__log') {
    echo json_encode($state['log']);
    return;
}

$state['log'][] = [
    'path' => $path,
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => json_decode($body, true),
];
$response = array_shift($state['queue'][$path]) ?? ['status' => 599, 'body' => ['code' => 'STUB_QUEUE_EMPTY']];
file_put_contents($stateFile, json_encode($state));

http_response_code($response['status']);
echo json_encode($response['body']);
