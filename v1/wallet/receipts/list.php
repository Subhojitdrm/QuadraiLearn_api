<?php
declare(strict_types=1);

/**
 * GET /api/v1/wallet/me/receipts
 *
 * List user's receipts (paid purchases only)
 *
 * Query params:
 * - limit: Results per page (default 25)
 * - cursor: Pagination cursor
 *
 * Response:
 * {
 *   "items": [
 *     {
 *       "receipt_no": "QL-2025-000123",
 *       "purchase_id": "01J...P",
 *       "tokens": 250,
 *       "inr_amount": 750,
 *       "paid_at": "2025-11-08T12:30:00Z"
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

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/errors.php';
require_once __DIR__ . '/../../../lib/headers.php';
require_once __DIR__ . '/../../../lib/purchases.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    validation_error('Method not allowed', ['method' => 'Only GET is supported']);
}

try {
    // Validate standard headers
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

    // Get receipts
    $result = get_user_receipts($pdo, $userId, $pagination['limit'], $pagination['cursor']);

    // Send success response
    send_success($result);

} catch (Exception $e) {
    error_log("Error in GET /v1/wallet/me/receipts: " . $e->getMessage());
    server_error('Failed to retrieve receipts');
}
