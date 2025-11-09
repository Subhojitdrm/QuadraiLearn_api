<?php
/**
 * Verify wallet tables exist
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

try {
    $pdo = get_pdo();

    // Check which tables exist
    $tables = [
        'users',
        'wallet_ledger',
        'wallet_balance_cache',
        'idempotency_keys',
        'token_authorizations',
        'purchases',
        'payment_webhook_events',
        'promotion_campaigns',
        'referrals',
        'promo_expiry_schedules',
        'analytics_token_daily',
        'analytics_exports'
    ];

    $results = [];

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;

        if ($exists) {
            // Get column count
            $stmt = $pdo->query("DESCRIBE $table");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results[$table] = [
                'exists' => true,
                'columns' => count($columns),
                'column_names' => array_column($columns, 'Field')
            ];
        } else {
            $results[$table] = [
                'exists' => false
            ];
        }
    }

    // Check triggers
    $stmt = $pdo->query("SHOW TRIGGERS WHERE `Table` = 'wallet_ledger'");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check views
    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'database' => DB_NAME,
        'tables' => $results,
        'triggers' => $triggers,
        'views' => $views
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
