<?php
declare(strict_types=1);

/**
 * POST /api/v1/wallet/me/purchases
 *
 * Create a token purchase intent
 *
 * Request Body:
 * {
 *   "tokens": 250,
 *   "provider": "razorpay"
 * }
 *
 * Response:
 * {
 *   "purchase_id": "01J...P",
 *   "status": "created",
 *   "tokens": 250,
 *   "inr_amount": 750,
 *   "provider": "razorpay",
 *   "provider_payload": {
 *     "order_id": "order_9A33XWu170gUtm",
 *     "amount": 75000,
 *     "currency": "INR"
 *   }
 * }
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    validation_error('Method not allowed', ['method' => 'Only POST is supported']);
}

try {
    // Validate standard headers (require idempotency key for mutations)
    $headers = validate_standard_headers(true);

    // Require authentication
    $user = require_auth();
    $userId = (int)$user['sub'];

    // Validate scopes
    validate_scopes($user, ['wallet:write']);

    // Parse request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        validation_error('Invalid JSON body');
    }

    // Validate required fields
    validate_required_fields($input, ['tokens', 'provider']);

    $tokens = (int)$input['tokens'];
    $provider = trim(strtolower($input['provider']));

    // Validate provider
    $allowedProviders = ['razorpay', 'stripe'];
    if (!in_array($provider, $allowedProviders, true)) {
        validation_error('Invalid provider', ['provider' => 'Must be one of: ' . implode(', ', $allowedProviders)]);
    }

    // Get database connection
    $pdo = get_db();

    // Create purchase
    $result = create_purchase(
        $pdo,
        $userId,
        $tokens,
        $provider,
        $headers['idempotency_key']
    );

    // Send success response
    http_response_code(201);
    send_success($result);

} catch (Exception $e) {
    error_log("Error in POST /v1/wallet/me/purchases: " . $e->getMessage());

    // Re-throw known errors
    if ($e instanceof PDOException) {
        server_error('Database error occurred');
    }

    throw $e;
}
