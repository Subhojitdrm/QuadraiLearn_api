<?php
// public/health-check.php
declare(strict_types=1);

header('Content-Type: application/json');

// Include the PDO connection
require_once __DIR__ . '/../config/db.php';

try {
    // Simple test query
    $stmt = $pdo->query('SELECT 1');
    $stmt->fetch();

    // Optional: check DB user and DB name quickly (do not expose sensitive info)
    echo json_encode([
        'ok' => true,
        'db' => 'connected'
    ]);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Query failed'
    ]);
}
