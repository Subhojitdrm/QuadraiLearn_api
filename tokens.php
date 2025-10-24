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

require_once __DIR__ . '/../../lib/auth.php';    // For require_auth()
require_once __DIR__ . '/../../db.php';          // For get_pdo()
require_once __DIR__ . '/../../lib/tokens.php';  // For get_user_token_balance()
require_once __DIR__ . '/../../config.php';      // For DEBUG constant

function out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Authenticate the user
$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);

if ($userId <= 0) {
    out(401, ['ok' => false, 'error' => 'unauthorized']);
}

try {
    $pdo = get_pdo();
    $balance = get_user_token_balance($pdo, $userId);

    out(200, ['ok' => true, 'balance' => $balance]);

} catch (Throwable $e) {
    $error_message = (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error';
    out(500, ['ok' => false, 'error' => $error_message]);
}
