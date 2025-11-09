<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/users/{userId}/wallet
 *
 * Get wallet balance for a specific user (admin only)
 *
 * Query params:
 * - userId: User ID (from query string)
 *
 * Response: Same shape as /wallet/me
 * {
 *   "balances": { "regular": 250, "promo": 0, "total": 250 },
 *   "updated_at": "2025-11-08T12:30:00Z",
 *   "split": { "regular": 250, "promo": 0 }
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

    // Get database connection
    $pdo = get_db();

    // Verify user exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);
    if (!$stmt->fetch()) {
        not_found_error('User not found');
    }

    // Get wallet balance
    $balance = wallet_get_balance($pdo, $targetUserId);

    // Format response
    $response = [
        'balances' => [
            'regular' => $balance['regular'],
            'promo' => $balance['promo'],
            'total' => $balance['total']
        ],
        'updated_at' => $balance['updated_at'] ? date('c', strtotime($balance['updated_at'])) : date('c'),
        'split' => [
            'regular' => $balance['regular'],
            'promo' => $balance['promo']
        ]
    ];

    // Send success response
    send_success($response);

} catch (Exception $e) {
    error_log("Error in GET /v1/admin/users/{userId}/wallet: " . $e->getMessage());
    server_error('Failed to retrieve wallet balance');
}
