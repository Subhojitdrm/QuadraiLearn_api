<?php
declare(strict_types=1);

/**
 * POST /api/v1/admin/users/{userId}/wallet/seed
 *
 * Seed wallet with registration bonus (admin only, one-time operation)
 *
 * Body:
 * {
 *   "amount": 250
 * }
 *
 * Validations:
 * - Amount must equal configured seed amount (250)
 * - Reject if user already has registration_bonus
 * - Requires X-Idempotency-Key header
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
require_once __DIR__ . '/../../../lib/wallet.php';
require_once __DIR__ . '/../../../lib/idempotency.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    validation_error('Method not allowed', ['method' => 'Only POST is supported']);
}

// Seed amount configuration
define('WALLET_SEED_AMOUNT', 250);

try {
    // Validate standard headers (require idempotency key for mutations)
    $headers = validate_standard_headers(true);

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

    // Parse request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        validation_error('Invalid JSON body');
    }

    // Validate required fields
    validate_required_fields($input, ['amount']);

    // Validate amount
    $amount = (int)$input['amount'];
    if ($amount !== WALLET_SEED_AMOUNT) {
        validation_error('Amount must equal configured seed amount', [
            'amount' => "Amount must be exactly " . WALLET_SEED_AMOUNT
        ]);
    }

    // Get database connection
    $pdo = get_db();

    // Verify user exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);
    if (!$stmt->fetch()) {
        not_found_error('User not found');
    }

    // Check if user already has registration bonus
    if (wallet_has_registration_bonus($pdo, $targetUserId)) {
        conflict_error('User already has registration bonus', [
            'user_id' => $targetUserId,
            'reason' => 'registration_bonus already exists'
        ]);
    }

    // Execute with idempotency protection
    $response = idempotent_transaction(
        $pdo,
        $targetUserId,
        'WALLET_SEED',
        "user:{$targetUserId}",
        $headers['idempotency_key'],
        function () use ($pdo, $targetUserId, $amount, $headers) {
            // Credit the wallet
            $entry = wallet_credit(
                $pdo,
                $targetUserId,
                $amount,
                TOKEN_TYPE_REGULAR,
                REASON_REGISTRATION_BONUS,
                null,
                ['source' => 'admin_seed'],
                "WALLET_SEED:user:{$targetUserId}"
            );

            // Get updated balance
            $balance = wallet_get_balance($pdo, $targetUserId);

            return [
                'status_code' => 201,
                'message' => 'Wallet seeded successfully',
                'data' => [
                    'user_id' => $targetUserId,
                    'amount' => $amount,
                    'balances' => [
                        'regular' => $balance['regular'],
                        'promo' => $balance['promo'],
                        'total' => $balance['total']
                    ],
                    'entry_id' => $entry['id']
                ]
            ];
        }
    );

    // Send success response
    http_response_code($response['status_code']);
    send_success($response['data']);

} catch (Exception $e) {
    error_log("Error in POST /v1/admin/users/{userId}/wallet/seed: " . $e->getMessage());

    // Re-throw known errors
    if ($e instanceof PDOException) {
        server_error('Database error occurred');
    }

    throw $e;
}
