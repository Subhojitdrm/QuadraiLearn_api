<?php
declare(strict_types=1);

// ensure clean output
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

try {
    // 1) trivial query
    $pdo->query('SELECT 1')->fetch();

    // 2) get server version safely
    $ver = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    // 3) optional: verify current database
    $dbRow = $pdo->query('SELECT DATABASE() AS db')->fetch();

    echo json_encode([
        'ok'          => true,
        'db'          => 'connected',
        'database'    => $dbRow['db'] ?? null,
        'mysql_ver'   => $ver,
        'timestamp'   => time()
    ]);
} catch (Throwable $t) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Query failed or insufficient privileges'
    ]);
}
