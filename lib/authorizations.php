<?php
declare(strict_types=1);

/**
 * Token Authorizations Service
 *
 * Implements hold-then-capture pattern for token deductions:
 * 1. Create authorization (HOLD) - reserves tokens without deducting
 * 2. Capture - actually debits tokens from wallet
 * 3. Void - cancels hold or refunds captured tokens
 */

require_once __DIR__ . '/ulid.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/pricebook.php';

// Authorization statuses
define('AUTH_STATUS_CREATED', 'created');
define('AUTH_STATUS_HELD', 'held');
define('AUTH_STATUS_CAPTURED', 'captured');
define('AUTH_STATUS_VOIDED', 'voided');
define('AUTH_STATUS_EXPIRED', 'expired');

// Hold expiry time (10 minutes)
define('HOLD_EXPIRY_MINUTES', 10);

/**
 * Create a token authorization (HOLD)
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $feature Feature name (e.g., 'chapter_generation')
 * @param int $units Number of units
 * @param int $costPerUnit Cost per unit (server validates this)
 * @param string $resourceKey Stable hash of resource parameters
 * @param array $metadata Additional metadata
 * @param string $idempotencyKey Idempotency key
 * @return array Authorization data
 */
function create_authorization(
    PDO $pdo,
    int $userId,
    string $feature,
    int $units,
    int $costPerUnit,
    string $resourceKey,
    array $metadata = [],
    string $idempotencyKey = ''
): array {
    // Validate units
    if ($units < 1) {
        validation_error('Units must be at least 1', ['units' => 'Must be >= 1']);
    }

    // Get server-side pricing
    $serverCost = get_feature_cost($feature);
    if ($serverCost === null) {
        validation_error('Unknown feature', ['feature' => 'Feature not found in pricebook']);
    }

    // Calculate total amount (ignore client cost, use server cost)
    $amount = $units * $serverCost;

    // Check balance
    $balance = wallet_get_balance($pdo, $userId);
    if ($balance['regular'] < $amount) {
        business_rule_error('Insufficient tokens', [
            'required' => $amount,
            'available' => $balance['regular'],
            'error_code' => 'LOW_BALANCE'
        ]);
    }

    // Check for existing active authorization for this resource
    $stmt = $pdo->prepare('
        SELECT id, status, amount, hold_expires_at
        FROM token_authorizations
        WHERE user_id = ? AND feature = ? AND resource_key = ?
          AND status IN (?, ?)
        LIMIT 1
    ');
    $stmt->execute([$userId, $feature, $resourceKey, AUTH_STATUS_CREATED, AUTH_STATUS_HELD]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Return existing authorization (idempotent)
        return [
            'authorization_id' => $existing['id'],
            'status' => $existing['status'],
            'held_amount' => (int)$existing['amount'],
            'hold_expires_at' => date('c', strtotime($existing['hold_expires_at'])),
            'balance_preview' => [
                'regular' => $balance['regular'],
                'promo' => $balance['promo'],
                'total' => $balance['total']
            ]
        ];
    }

    // Create new authorization
    $authId = ulid();
    $holdExpiresAt = date('Y-m-d H:i:s', strtotime('+' . HOLD_EXPIRY_MINUTES . ' minutes'));

    $stmt = $pdo->prepare('
        INSERT INTO token_authorizations (
            id, user_id, feature, resource_key, amount, status,
            hold_expires_at, metadata, idempotency_key
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $authId,
        $userId,
        $feature,
        $resourceKey,
        $amount,
        AUTH_STATUS_HELD,
        $holdExpiresAt,
        json_encode($metadata),
        $idempotencyKey ?: null
    ]);

    return [
        'authorization_id' => $authId,
        'status' => AUTH_STATUS_HELD,
        'held_amount' => $amount,
        'hold_expires_at' => date('c', strtotime($holdExpiresAt)),
        'balance_preview' => [
            'regular' => $balance['regular'],
            'promo' => $balance['promo'],
            'total' => $balance['total']
        ]
    ];
}

/**
 * Capture an authorization (debit tokens)
 *
 * @param PDO $pdo Database connection
 * @param string $authorizationId Authorization ID
 * @param string $resultId Result ID (e.g., chapter_id)
 * @param string $statusFromUpstream Upstream status (success/failed)
 * @param int $userId User ID (for authorization check)
 * @return array Capture result
 */
function capture_authorization(
    PDO $pdo,
    string $authorizationId,
    string $resultId,
    string $statusFromUpstream,
    int $userId
): array {
    // Get authorization
    $stmt = $pdo->prepare('
        SELECT * FROM token_authorizations
        WHERE id = ? AND user_id = ?
    ');
    $stmt->execute([$authorizationId, $userId]);
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth) {
        not_found_error('Authorization not found');
    }

    // Check status
    if ($auth['status'] === AUTH_STATUS_CAPTURED) {
        // Already captured, return original result (idempotent)
        $balance = wallet_get_balance($pdo, $userId);
        return [
            'status' => AUTH_STATUS_CAPTURED,
            'debited' => (int)$auth['amount'],
            'balances' => [
                'regular' => $balance['regular'],
                'promo' => $balance['promo'],
                'total' => $balance['total']
            ],
            'transaction_id' => $auth['captured_transaction_id']
        ];
    }

    if ($auth['status'] === AUTH_STATUS_VOIDED || $auth['status'] === AUTH_STATUS_EXPIRED) {
        conflict_error('Authorization cannot be captured', [
            'status' => $auth['status'],
            'message' => 'Authorization is ' . $auth['status']
        ]);
    }

    // Check if hold expired
    if ($auth['hold_expires_at'] && strtotime($auth['hold_expires_at']) < time()) {
        // Mark as expired
        $stmt = $pdo->prepare('UPDATE token_authorizations SET status = ? WHERE id = ?');
        $stmt->execute([AUTH_STATUS_EXPIRED, $authorizationId]);

        conflict_error('Authorization expired', [
            'expired_at' => $auth['hold_expires_at']
        ]);
    }

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Debit tokens
        $entry = wallet_debit(
            $pdo,
            $userId,
            (int)$auth['amount'],
            TOKEN_TYPE_REGULAR,
            REASON_CHAPTER_GENERATION,
            $resultId,
            array_merge(
                json_decode($auth['metadata'], true) ?? [],
                ['authorization_id' => $authorizationId]
            ),
            "AUTH_CAPTURE:{$authorizationId}"
        );

        // Update authorization status
        $stmt = $pdo->prepare('
            UPDATE token_authorizations
            SET status = ?, captured_transaction_id = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([AUTH_STATUS_CAPTURED, $entry['id'], $authorizationId]);

        // Commit transaction
        $pdo->commit();

        // Get updated balance
        $balance = wallet_get_balance($pdo, $userId);

        return [
            'status' => AUTH_STATUS_CAPTURED,
            'debited' => (int)$auth['amount'],
            'balances' => [
                'regular' => $balance['regular'],
                'promo' => $balance['promo'],
                'total' => $balance['total']
            ],
            'transaction_id' => $entry['id']
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Void an authorization (cancel or refund)
 *
 * @param PDO $pdo Database connection
 * @param string $authorizationId Authorization ID
 * @param int $userId User ID
 * @param string $statusFromUpstream Upstream status
 * @param string|null $failureCode Failure code (if failed)
 * @param string|null $failureMsg Failure message (if failed)
 * @return array Void result
 */
function void_authorization(
    PDO $pdo,
    string $authorizationId,
    int $userId,
    string $statusFromUpstream = 'failed',
    ?string $failureCode = null,
    ?string $failureMsg = null
): array {
    // Get authorization
    $stmt = $pdo->prepare('
        SELECT * FROM token_authorizations
        WHERE id = ? AND user_id = ?
    ');
    $stmt->execute([$authorizationId, $userId]);
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth) {
        not_found_error('Authorization not found');
    }

    // Check if already voided
    if ($auth['status'] === AUTH_STATUS_VOIDED) {
        $balance = wallet_get_balance($pdo, $userId);
        return [
            'status' => AUTH_STATUS_VOIDED,
            'refunded' => 0,
            'balances' => [
                'regular' => $balance['regular'],
                'promo' => $balance['promo'],
                'total' => $balance['total']
            ]
        ];
    }

    // Begin transaction
    $pdo->beginTransaction();

    try {
        $refunded = 0;
        $transactionId = null;

        // If captured, issue refund
        if ($auth['status'] === AUTH_STATUS_CAPTURED) {
            $entry = wallet_credit(
                $pdo,
                $userId,
                (int)$auth['amount'],
                TOKEN_TYPE_REGULAR,
                REASON_REFUND_GENERATION_FAILURE,
                $auth['captured_transaction_id'],
                [
                    'authorization_id' => $authorizationId,
                    'failure_code' => $failureCode,
                    'failure_msg' => $failureMsg
                ],
                "AUTH_VOID:{$authorizationId}"
            );
            $refunded = (int)$auth['amount'];
            $transactionId = $entry['id'];
        }

        // Update authorization status
        $stmt = $pdo->prepare('
            UPDATE token_authorizations
            SET status = ?, voided_transaction_id = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([AUTH_STATUS_VOIDED, $transactionId, $authorizationId]);

        // Commit transaction
        $pdo->commit();

        // Get updated balance
        $balance = wallet_get_balance($pdo, $userId);

        return [
            'status' => AUTH_STATUS_VOIDED,
            'refunded' => $refunded,
            'balances' => [
                'regular' => $balance['regular'],
                'promo' => $balance['promo'],
                'total' => $balance['total']
            ]
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Single-shot deduction (no hold, direct debit)
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $reason Deduction reason
 * @param int $amount Amount to deduct
 * @param string $resourceKey Resource key for idempotency
 * @param array $metadata Additional metadata
 * @param string $idempotencyKey Idempotency key
 * @return array Deduction result
 */
function deduct_tokens(
    PDO $pdo,
    int $userId,
    string $reason,
    int $amount,
    string $resourceKey,
    array $metadata = [],
    string $idempotencyKey = ''
): array {
    // Check for existing deduction (idempotency by resource_key)
    $stmt = $pdo->prepare('
        SELECT id, amount, balance_after_regular, balance_after_promo
        FROM wallet_ledger
        WHERE user_id = ? AND reason = ? AND reference_id = ? AND direction = ?
        LIMIT 1
    ');
    $stmt->execute([$userId, $reason, $resourceKey, DIRECTION_DEBIT]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Already deducted, return existing result
        return [
            'debited' => (int)$existing['amount'],
            'balances' => [
                'regular' => (int)$existing['balance_after_regular'],
                'promo' => (int)$existing['balance_after_promo'],
                'total' => (int)$existing['balance_after_regular'] + (int)$existing['balance_after_promo']
            ],
            'transaction_id' => $existing['id']
        ];
    }

    // Perform deduction
    $entry = wallet_debit(
        $pdo,
        $userId,
        $amount,
        TOKEN_TYPE_REGULAR,
        $reason,
        $resourceKey,
        $metadata,
        $idempotencyKey
    );

    return [
        'debited' => $amount,
        'balances' => [
            'regular' => $entry['balance_after_regular'],
            'promo' => $entry['balance_after_promo'],
            'total' => $entry['balance_after_regular'] + $entry['balance_after_promo']
        ],
        'transaction_id' => $entry['id']
    ];
}

/**
 * Expire old authorizations
 *
 * @param PDO $pdo Database connection
 * @return int Number of authorizations expired
 */
function expire_old_authorizations(PDO $pdo): int
{
    $stmt = $pdo->prepare('
        UPDATE token_authorizations
        SET status = ?, updated_at = NOW()
        WHERE status = ? AND hold_expires_at < NOW()
    ');
    $stmt->execute([AUTH_STATUS_EXPIRED, AUTH_STATUS_HELD]);

    return $stmt->rowCount();
}
