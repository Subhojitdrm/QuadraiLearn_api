<?php
declare(strict_types=1);

/**
 * GET /api/v1/wallet/me/transactions
 *
 * Get current user's wallet transaction history
 *
 * Query params:
 * - limit: Number of results (default 25, max 100)
 * - cursor: Opaque cursor for pagination
 *
 * Response:
 * {
 *   "items": [
 *     {
 *       "id": "01J...A",
 *       "occurred_at": "2025-11-08T12:30:00Z",
 *       "type": "credit",
 *       "token_type": "regular",
 *       "reason": "registration_bonus",
 *       "amount": 250,
 *       "balance_after": { "regular": 250, "promo": 0, "total": 250 },
 *       "metadata": {}
 *     }
 *   ],
 *   "next_cursor": "opaque"
 * }
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id, X-Idempotency-Key, X-Source');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/errors.php';
require_once __DIR__ . '/../../lib/headers.php';
require_once __DIR__ . '/../../lib/wallet.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    validation_error('Method not allowed', ['method' => 'Only GET is supported']);
}

try {
    // Validate standard headers (no idempotency key needed for GET)
    $headers = validate_standard_headers(false);

    // Require authentication
    $user = require_auth();
    $userId = (int)$user['sub'];

    // Validate scopes
    validate_scopes($user, ['wallet:read']);

    // Get pagination params
    $pagination = get_pagination_params(25, 100);

    // Get database connection
    $pdo = get_db();

    // Get transactions
    $result = wallet_get_transactions($pdo, $userId, $pagination['limit'], $pagination['cursor']);

    // Format response (convert occurred_at to ISO 8601)
    foreach ($result['items'] as &$item) {
        $item['occurred_at'] = date('c', strtotime($item['occurred_at']));
    }
    unset($item);

    // Send success response
    send_success($result);

} catch (Exception $e) {
    error_log("Error in GET /v1/wallet/me/transactions: " . $e->getMessage());
    server_error('Failed to retrieve wallet transactions');
}
