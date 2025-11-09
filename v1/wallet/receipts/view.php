<?php
declare(strict_types=1);

/**
 * GET /api/v1/wallet/me/receipts/{receipt_no}
 *
 * Get receipt details
 *
 * Response:
 * {
 *   "receipt_no": "QL-2025-000123",
 *   "purchase_id": "01J...P",
 *   "tokens": 250,
 *   "inr_amount": 750,
 *   "provider": "razorpay",
 *   "provider_payment_id": "pay_abc123",
 *   "paid_at": "2025-11-08T12:30:00Z",
 *   "created_at": "2025-11-08T12:25:00Z"
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

    // Get receipt_no from query string
    $receiptNo = $_GET['receipt_no'] ?? null;
    if (!$receiptNo) {
        validation_error('receipt_no is required', ['receipt_no' => 'Missing in URL']);
    }

    // Get database connection
    $pdo = get_db();

    // Get receipt
    $receipt = get_receipt($pdo, $receiptNo, $userId);

    if (!$receipt) {
        not_found_error('Receipt not found');
    }

    // Send success response
    send_success($receipt);

} catch (Exception $e) {
    error_log("Error in GET /v1/wallet/me/receipts/{receipt_no}: " . $e->getMessage());
    server_error('Failed to retrieve receipt');
}
