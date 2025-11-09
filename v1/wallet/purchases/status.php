<?php
declare(strict_types=1);

/**
 * GET /api/v1/wallet/me/purchases/{purchase_id}
 *
 * Get purchase status
 *
 * Response:
 * {
 *   "status": "paid",
 *   "tokens": 250,
 *   "inr_amount": 750,
 *   "receipt_no": "QL-2025-000123"
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

    // Get purchase_id from query string
    $purchaseId = $_GET['purchase_id'] ?? null;
    if (!$purchaseId) {
        validation_error('purchase_id is required', ['purchase_id' => 'Missing in URL']);
    }

    // Get database connection
    $pdo = get_db();

    // Get purchase
    $purchase = get_purchase($pdo, $purchaseId, $userId);

    if (!$purchase) {
        not_found_error('Purchase not found');
    }

    // Format response
    $response = [
        'purchase_id' => $purchase['id'],
        'status' => $purchase['status'],
        'tokens' => (int)$purchase['tokens'],
        'inr_amount' => (int)$purchase['inr_amount'],
        'provider' => $purchase['provider'],
        'created_at' => date('c', strtotime($purchase['created_at'])),
        'updated_at' => date('c', strtotime($purchase['updated_at']))
    ];

    if ($purchase['receipt_no']) {
        $response['receipt_no'] = $purchase['receipt_no'];
    }

    send_success($response);

} catch (Exception $e) {
    error_log("Error in GET /v1/wallet/me/purchases/{id}: " . $e->getMessage());
    server_error('Failed to retrieve purchase');
}
