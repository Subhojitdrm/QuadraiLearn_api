<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Tokens endpoint test successful!',
    'timestamp' => time()
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
