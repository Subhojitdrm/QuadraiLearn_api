<?php
declare(strict_types=1);

/**
 * POST /api/v1/tokens/authorizations
 *
 * Create a token authorization (HOLD)
 *
 * Request Body:
 * {
 *   "feature": "chapter_generation",
 *   "units": 1,
 *   "cost_per_unit": 10,
 *   "resource_key": "<stable-chapter-hash>",
 *   "metadata": { "subject": "Maths", "grade": "VIII" }
 * }
 *
 * Response:
 * {
 *   "authorization_id": "01J...H",
 *   "status": "held",
 *   "held_amount": 10,
 *   "hold_expires_at": "2025-11-08T12:45:00Z",
 *   "balance_preview": { "regular": 240, "promo": 0, "total": 240 }
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

    // Parse request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        validation_error('Invalid JSON body');
    }

    // Validate required fields
    validate_required_fields($input, ['feature', 'units', 'resource_key']);

    // Extract fields
    $feature = trim($input['feature']);
    $units = (int)$input['units'];
    $costPerUnit = isset($input['cost_per_unit']) ? (int)$input['cost_per_unit'] : 0;
    $resourceKey = trim($input['resource_key']);
    $metadata = $input['metadata'] ?? [];

    // Validate types
    if (!is_int($units) && !ctype_digit((string)$units)) {
        validation_error('Invalid units', ['units' => 'Must be an integer']);
    }

    if (!is_array($metadata)) {
        validation_error('Invalid metadata', ['metadata' => 'Must be an object']);
    }

    // Get database connection
    $pdo = get_db();

    // Create authorization
    $result = create_authorization(
        $pdo,
        $userId,
        $feature,
        $units,
        $costPerUnit,
        $resourceKey,
        $metadata,
        $headers['idempotency_key']
    );

    // Send success response
    http_response_code(201);
    send_success($result);

} catch (Exception $e) {
    error_log("Error in POST /v1/tokens/authorizations: " . $e->getMessage());

    // Re-throw known errors
    if ($e instanceof PDOException) {
        server_error('Database error occurred');
    }

    throw $e;
}
