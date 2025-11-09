<?php
declare(strict_types=1);

/**
 * Hold Expiry Runner (Background Job)
 *
 * Runs every minute via cron to expire old token authorizations
 *
 * Cron setup:
 * * * * * * php /path/to/cron/expire_holds.php >> /path/to/logs/expire_holds.log 2>&1
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorizations.php';

// Log function
function log_message(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

log_message('=== Hold Expiry Runner Started ===');

try {
    // Get database connection
    $pdo = get_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Expire old authorizations
    $count = expire_old_authorizations($pdo);

    if ($count > 0) {
        log_message("Expired {$count} authorization(s)");
    } else {
        log_message('No authorizations to expire');
    }

    log_message('=== Hold Expiry Runner Completed ===');
    exit(0);

} catch (PDOException $e) {
    log_message('Database error: ' . $e->getMessage());
    exit(1);

} catch (Exception $e) {
    log_message('Error: ' . $e->getMessage());
    exit(1);
}
