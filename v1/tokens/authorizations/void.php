<?php
declare(strict_types=1);

/**
 * POST /api/v1/tokens/authorizations/{authorization_id}/void
 *
 * Void an authorization (cancel hold or refund captured tokens)
 *
 * Request Body:
 * {
 *   "status_from_upstream": "failed",
 *   "failure_code": "MODEL_TIMEOUT",
 *   "failure_msg": "LLM timed out"
 * }
 *
 * Response:
 * {
 *   "status": "voided",
 *   "refunded": 10,
 *   "balances": { "regular": 240, "promo": 0, "total": 240 }
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
require_once __DIR__ . '/../../../lib/authorizations.php';

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

    // Get authorization_id from query string
    $authorizationId = $_GET['authorization_id'] ?? null;
    if (!$authorizationId) {
        validation_error('authorization_id is required', ['authorization_id' => 'Missing in URL']);
    }

    // Parse request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        validation_error('Invalid JSON body');
    }

    // Extract fields (all optional)
    $statusFromUpstream = $input['status_from_upstream'] ?? 'failed';
    $failureCode = $input['failure_code'] ?? null;
    $failureMsg = $input['failure_msg'] ?? null;

    // Get database connection
    $pdo = get_db();

    // Void authorization
    $result = void_authorization(
        $pdo,
        $authorizationId,
        $userId,
        $statusFromUpstream,
        $failureCode,
        $failureMsg
    );

    // Send success response
    send_success($result);

} catch (Exception $e) {
    error_log("Error in POST /v1/tokens/authorizations/{id}/void: " . $e->getMessage());

    // Re-throw known errors
    if ($e instanceof PDOException) {
        server_error('Database error occurred');
    }

    throw $e;
}
