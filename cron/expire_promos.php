<?php
declare(strict_types=1);

/**
 * Promo Token Expiry Runner (Background Job)
 *
 * Runs nightly at 02:00 IST to expire promotional tokens
 *
 * Cron setup:
 * 0 2 * * * php /path/to/cron/expire_promos.php >> /path/to/logs/expire_promos.log 2>&1
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/expiry.php';

// Log function
function log_message(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

log_message('=== Promo Expiry Runner Started ===');

try {
    // Get database connection
    $pdo = get_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Send expiry warnings (3 days ahead)
    log_message('Sending expiry warnings...');
    $warningCount = send_expiry_warnings($pdo, 3);
    log_message("Sent {$warningCount} expiry warning(s)");

    // Process expiries
    log_message('Processing promo expiries...');
    $results = process_promo_expiries($pdo);

    log_message("Processed: {$results['processed']} schedules");
    log_message("Total expired: {$results['total_expired']} tokens");
    log_message("Users affected: " . count($results['users_affected']));

    if (!empty($results['errors'])) {
        log_message("Errors encountered: " . count($results['errors']));
        foreach ($results['errors'] as $error) {
            log_message("  User {$error['user_id']}: {$error['error']}");
        }
    }

    log_message('=== Promo Expiry Runner Completed ===');
    exit(0);

} catch (PDOException $e) {
    log_message('Database error: ' . $e->getMessage());
    exit(1);

} catch (Exception $e) {
    log_message('Error: ' . $e->getMessage());
    exit(1);
}
