<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
// (Optional) allow browser calls from your React app later
header('Access-Control-Allow-Origin: *');

require __DIR__ . '/db.php';

$response = [
    'ok'          => false,
    'app'         => 'quadrailearn',
    'endpoint'    => 'health',
    'time'        => gmdate('c'),
    'php_version' => PHP_VERSION,
];

try {
    $pdo = get_pdo();

    // Basic ping
    $ping = $pdo->query('SELECT 1 AS ping')->fetch();
    // Get MySQL server version (handy for support)
    $ver  = $pdo->query('SELECT VERSION() AS version')->fetch();

    $response['ok']           = ($ping && (int)$ping['ping'] === 1);
    $response['mysql_status'] = $response['ok'] ? 'connected' : 'failed';
    $response['db_name']      = DB_NAME;
    $response['mysql_version']= $ver['version'] ?? null;

    http_response_code($response['ok'] ? 200 : 500);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    $response['mysql_status'] = 'failed';
    $response['error'] = DEBUG ? $e->getMessage() : 'database connection error';
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
