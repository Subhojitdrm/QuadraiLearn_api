<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/analytics/tokens/by-feature
 *
 * Get token usage breakdown by feature
 *
 * Query Parameters:
 * - from: Start date (YYYY-MM-DD) - required
 * - to: End date (YYYY-MM-DD) - required
 *
 * Scope: admin
 */

require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../../../lib/errors.php';
require_once __DIR__ . '/../../../../lib/headers.php';
require_once __DIR__ . '/../../../../lib/analytics.php';

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('method_not_allowed', 'Only GET requests are allowed', [], 405);
}

try {
    // Validate standard headers
    validate_standard_headers();

    // Validate authentication and admin scope
    validate_scopes(['admin']);

    // Get required query parameters
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    if (!$from || !$to) {
        validation_error('from and to date parameters are required');
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        validation_error('Invalid date format. Use YYYY-MM-DD');
    }

    // Validate date range (max 1 year)
    $fromDate = new DateTime($from);
    $toDate = new DateTime($to);
    $diff = $fromDate->diff($toDate);

    if ($diff->days > 365) {
        business_rule_error('Date range cannot exceed 365 days');
    }

    if ($fromDate > $toDate) {
        validation_error('from date must be before or equal to to date');
    }

    // Get database connection
    $pdo = get_db();

    // Get feature breakdown
    $breakdown = get_tokens_by_feature($pdo, $from, $to);

    // Send response
    http_response_code(200);
    echo json_encode($breakdown);

} catch (PDOException $e) {
    error_log('Database error in analytics by-feature: ' . $e->getMessage());
    send_error('database_error', 'A database error occurred', [], 500);
} catch (Exception $e) {
    error_log('Error in analytics by-feature: ' . $e->getMessage());
    send_error('internal_error', 'An internal error occurred', [], 500);
}
