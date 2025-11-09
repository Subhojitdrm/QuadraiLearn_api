<?php
declare(strict_types=1);

/**
 * POST /api/v1/tokens/deduct
 *
 * Single-shot token deduction (no hold, direct debit)
 *
 * Request Body:
 * {
 *   "reason": "chapter_generation",
 *   "amount": 10,
 *   "resource_key": "<stable-chapter-hash>",
 *   "metadata": {}
 * }
 *
 * Response:
 * {
 *   "debited": 10,
 *   "balances": { "regular": 230, "promo": 0, "total": 230 },
 *   "transaction_id": "01J...L"
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

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/errors.php';
require_once __DIR__ . '/../../lib/headers.php';
require_once __DIR__ . '/../../lib/authorizations.php';

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
    validate_required_fields($input, ['reason', 'amount', 'resource_key']);

    $reason = trim($input['reason']);
    $amount = (int)$input['amount'];
    $resourceKey = trim($input['resource_key']);
    $metadata = $input['metadata'] ?? [];

    // Validate amount
    if ($amount <= 0) {
        validation_error('Invalid amount', ['amount' => 'Must be greater than 0']);
    }

    // Validate metadata
    if (!is_array($metadata)) {
        validation_error('Invalid metadata', ['metadata' => 'Must be an object']);
    }

    // Get database connection
    $pdo = get_db();

    // Perform deduction
    $result = deduct_tokens(
        $pdo,
        $userId,
        $reason,
        $amount,
        $resourceKey,
        $metadata,
        $headers['idempotency_key']
    );

    // Send success response
    send_success($result);

} catch (Exception $e) {
    error_log("Error in POST /v1/tokens/deduct: " . $e->getMessage());

    // Re-throw known errors
    if ($e instanceof PDOException) {
        server_error('Database error occurred');
    }

    throw $e;
}
