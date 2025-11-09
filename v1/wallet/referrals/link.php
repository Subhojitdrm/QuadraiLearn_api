<?php
declare(strict_types=1);

/**
 * POST /api/v1/wallet/me/referrals/link
 *
 * Create or get referral link for user
 *
 * Response:
 * {
 *   "code": "SUBH1234XYZW",
 *   "url": "https://app.quadralearn.com/r/SUBH1234XYZW"
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
require_once __DIR__ . '/../../../lib/promotions.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    validation_error('Method not allowed', ['method' => 'Only POST is supported']);
}

try {
    // Validate standard headers
    $headers = validate_standard_headers(false);

    // Require authentication
    $user = require_auth();
    $userId = (int)$user['sub'];

    // Validate scopes
    validate_scopes($user, ['wallet:read']);

    // Get database connection
    $pdo = get_db();

    // Get active referral campaign (hardcoded for now, can be made dynamic)
    $stmt = $pdo->prepare('
        SELECT id FROM promotion_campaigns
        WHERE type = ? AND status = ?
        LIMIT 1
    ');
    $stmt->execute([CAMPAIGN_TYPE_REFERRAL, CAMPAIGN_STATUS_ACTIVE]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        validation_error('No active referral campaign', ['campaign' => 'Referral program not currently active']);
    }

    // Base URL for referrals (should be in config)
    $baseUrl = 'https://app.quadralearn.com';

    // Create or get referral link
    $result = create_or_get_referral_link($pdo, $userId, $campaign['id'], $baseUrl);

    send_success($result);

} catch (Exception $e) {
    error_log("Error in POST /v1/wallet/me/referrals/link: " . $e->getMessage());
    server_error('Failed to create referral link');
}
