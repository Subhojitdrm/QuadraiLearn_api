<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/users/{userId}/transactions
 *
 * Get wallet transaction history for a specific user (admin only)
 *
 * Query params:
 * - userId: User ID (required)
 * - limit: Number of results (default 25, max 100)
 * - cursor: Opaque cursor for pagination
 *
 * Response: Same shape as /wallet/me/transactions
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id, X-Idempotency-Key, X-Source');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/errors.php';
require_once __DIR__ . '/../../../lib/headers.php';
require_once __DIR__ . '/../../../lib/wallet.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    validation_error('Method not allowed', ['method' => 'Only GET is supported']);
}

try {
    // Validate standard headers (no idempotency key needed for GET)
    $headers = validate_standard_headers(false);

    // Require authentication
    $user = require_auth();

    // Require admin role
    require_admin($user);

    // Validate scopes
    validate_scopes($user, ['wallet:admin']);

    // Get userId from query string
    $targetUserId = isset($_GET['userId']) ? (int)$_GET['userId'] : null;
    if (!$targetUserId) {
        validation_error('userId is required', ['userId' => 'Missing or invalid userId parameter']);
    }

    // Get pagination params
    $pagination = get_pagination_params(25, 100);

    // Get database connection
    $pdo = get_db();

    // Verify user exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);
    if (!$stmt->fetch()) {
        not_found_error('User not found');
    }

    // Get transactions
    $result = wallet_get_transactions($pdo, $targetUserId, $pagination['limit'], $pagination['cursor']);

    // Format response (convert occurred_at to ISO 8601)
    foreach ($result['items'] as &$item) {
        $item['occurred_at'] = date('c', strtotime($item['occurred_at']));
    }
    unset($item);

    // Send success response
    send_success($result);

} catch (Exception $e) {
    error_log("Error in GET /v1/admin/users/{userId}/transactions: " . $e->getMessage());
    server_error('Failed to retrieve wallet transactions');
}
