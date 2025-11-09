<?php
declare(strict_types=1);

/**
 * GET /api/v1/wallet/me
 *
 * Get current user's wallet balance
 *
 * Response:
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

    // Get database connection
    $pdo = get_db();

    // Get wallet balance
    $balance = wallet_get_balance($pdo, $userId);

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

    // Add ETag for caching
    $etag = md5(json_encode($response));
    header("ETag: \"{$etag}\"");

    // Check If-None-Match header
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
    if ($ifNoneMatch && trim($ifNoneMatch, '"') === $etag) {
        http_response_code(304); // Not Modified
        exit;
    }

    // Set cache headers (soft TTL 3 seconds)
    header('Cache-Control: private, max-age=3');

    // Send success response
    send_success($response);

} catch (Exception $e) {
    error_log("Error in GET /v1/wallet/me: " . $e->getMessage());
    server_error('Failed to retrieve wallet balance');
}
