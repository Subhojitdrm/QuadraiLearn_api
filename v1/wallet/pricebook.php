<?php
declare(strict_types=1);

/**
 * GET /api/v1/wallet/me/pricebook
 *
 * Get pricing information for features
 *
 * Query params:
 * - feature: Feature name (optional, returns all if not specified)
 *
 * Response (single feature):
 * {
 *   "unit_cost": 10,
 *   "token_type": "regular",
 *   "currency_hint": "₹"
 * }
 *
 * Response (all features):
 * {
 *   "chapter_generation": { "unit_cost": 10, "token_type": "regular", "currency_hint": "₹" },
 *   "test_generation": { "unit_cost": 5, "token_type": "regular", "currency_hint": "₹" }
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
require_once __DIR__ . '/../../lib/pricebook.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    validation_error('Method not allowed', ['method' => 'Only GET is supported']);
}

try {
    // Validate standard headers (no idempotency key needed for GET)
    $headers = validate_standard_headers(false);

    // Require authentication
    $user = require_auth();

    // Validate scopes
    validate_scopes($user, ['wallet:read']);

    // Get feature from query string
    $feature = $_GET['feature'] ?? null;

    if ($feature) {
        // Return pricing for specific feature
        $pricing = get_pricebook_entry($feature);
        if (!$pricing) {
            not_found_error("Feature '{$feature}' not found in pricebook");
        }

        // Remove description from API response (internal only)
        unset($pricing['description']);

        send_success($pricing);
    } else {
        // Return all pricebook entries
        $pricebook = get_all_pricebook_entries();

        // Remove descriptions
        $response = [];
        foreach ($pricebook as $key => $entry) {
            $response[$key] = [
                'unit_cost' => $entry['unit_cost'],
                'token_type' => $entry['token_type'],
                'currency_hint' => $entry['currency_hint']
            ];
        }

        send_success($response);
    }

} catch (Exception $e) {
    error_log("Error in GET /v1/wallet/me/pricebook: " . $e->getMessage());
    server_error('Failed to retrieve pricebook');
}
