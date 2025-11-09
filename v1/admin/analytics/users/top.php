<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/analytics/users/top
 *
 * Get top users leaderboard
 *
 * Query Parameters:
 * - metric: spend, earn, net - required
 * - from: Start date (YYYY-MM-DD) - required
 * - to: End date (YYYY-MM-DD) - required
 * - limit: Number of results (1-100, default 20)
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
    $metric = $_GET['metric'] ?? null;
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    if (!$metric || !$from || !$to) {
        validation_error('metric, from, and to parameters are required');
    }

    // Validate metric
    if (!in_array($metric, ['spend', 'earn', 'net'], true)) {
        validation_error('metric must be one of: spend, earn, net');
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

    // Get limit (default 20, max 100)
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

    if ($limit < 1 || $limit > 100) {
        validation_error('limit must be between 1 and 100');
    }

    // Get database connection
    $pdo = get_db();

    // Get top users
    $topUsers = get_top_users($pdo, $metric, $from, $to, $limit);

    // Send response
    http_response_code(200);
    echo json_encode($topUsers);

} catch (PDOException $e) {
    error_log('Database error in analytics top users: ' . $e->getMessage());
    send_error('database_error', 'A database error occurred', [], 500);
} catch (Exception $e) {
    error_log('Error in analytics top users: ' . $e->getMessage());
    send_error('internal_error', 'An internal error occurred', [], 500);
}
