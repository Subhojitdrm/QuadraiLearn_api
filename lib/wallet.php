<?php
declare(strict_types=1);

/**
 * Wallet Service Library
 *
 * Core wallet operations following ledger-based accounting:
 * - Append-only ledger principle
 * - Balance caching for performance
 * - Credit/debit operations
 * - Support for regular and promo tokens
 */

require_once __DIR__ . '/ulid.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/events.php';

// Valid token types
define('TOKEN_TYPE_REGULAR', 'regular');
define('TOKEN_TYPE_PROMO', 'promo');

// Valid directions
define('DIRECTION_CREDIT', 'credit');
define('DIRECTION_DEBIT', 'debit');

// Valid reasons (matching enum in schema)
define('REASON_REGISTRATION_BONUS', 'registration_bonus');
define('REASON_CHAPTER_GENERATION', 'chapter_generation');
define('REASON_REFUND_GENERATION_FAILURE', 'refund_generation_failure');
define('REASON_TOKEN_PURCHASE', 'token_purchase');
define('REASON_REFERRAL_BONUS', 'referral_bonus');
define('REASON_PROMO_EXPIRY', 'promo_expiry');
define('REASON_ADMIN_ADJUSTMENT', 'admin_adjustment');
define('REASON_MIGRATION_CORRECTION', 'migration_correction');

/**
 * Get current wallet balance from cache
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return array ['regular' => int, 'promo' => int, 'total' => int, 'updated_at' => string|null]
 */
function wallet_get_balance(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT regular_balance, promo_balance, updated_at
        FROM wallet_balance_cache
        WHERE user_id = ?
    ');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // No cache entry, return zero balances
        return [
            'regular' => 0,
            'promo' => 0,
            'total' => 0,
            'updated_at' => null
        ];
    }

    return [
        'regular' => (int)$row['regular_balance'],
        'promo' => (int)$row['promo_balance'],
        'total' => (int)$row['regular_balance'] + (int)$row['promo_balance'],
        'updated_at' => $row['updated_at']
    ];
}

/**
 * Add entry to wallet ledger
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $tokenType 'regular' or 'promo'
 * @param string $direction 'credit' or 'debit'
 * @param string $reason Reason enum value
 * @param int $amount Positive amount
 * @param string|null $referenceId Optional reference (e.g., chapter_id)
 * @param array $metadata Additional metadata
 * @param string|null $idempotencyKey Idempotency key
 * @return array Ledger entry data
 * @throws Exception If validation fails or insufficient balance
 */
function wallet_add_ledger_entry(
    PDO $pdo,
    int $userId,
    string $tokenType,
    string $direction,
    string $reason,
    int $amount,
    ?string $referenceId = null,
    array $metadata = [],
    ?string $idempotencyKey = null
): array {
    // Validate inputs
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be positive');
    }

    if (!in_array($tokenType, [TOKEN_TYPE_REGULAR, TOKEN_TYPE_PROMO], true)) {
        throw new InvalidArgumentException('Invalid token type');
    }

    if (!in_array($direction, [DIRECTION_CREDIT, DIRECTION_DEBIT], true)) {
        throw new InvalidArgumentException('Invalid direction');
    }

    // Get current balance
    $currentBalance = wallet_get_balance($pdo, $userId);
    $regularBalance = $currentBalance['regular'];
    $promoBalance = $currentBalance['promo'];

    // Calculate new balance
    if ($direction === DIRECTION_CREDIT) {
        if ($tokenType === TOKEN_TYPE_REGULAR) {
            $regularBalance += $amount;
        } else {
            $promoBalance += $amount;
        }
    } else { // DEBIT
        if ($tokenType === TOKEN_TYPE_REGULAR) {
            if ($regularBalance < $amount) {
                business_rule_error('Insufficient regular tokens', [
                    'required' => $amount,
                    'available' => $regularBalance
                ]);
            }
            $regularBalance -= $amount;
        } else {
            if ($promoBalance < $amount) {
                business_rule_error('Insufficient promo tokens', [
                    'required' => $amount,
                    'available' => $promoBalance
                ]);
            }
            $promoBalance -= $amount;
        }
    }

    // Generate ULID for this entry
    $id = ulid();
    $occurredAt = date('Y-m-d H:i:s');

    // Insert ledger entry
    $stmt = $pdo->prepare('
        INSERT INTO wallet_ledger (
            id, user_id, token_type, direction, reason, amount,
            balance_after_regular, balance_after_promo,
            occurred_at, reference_id, metadata, idempotency_key
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $id,
        $userId,
        $tokenType,
        $direction,
        $reason,
        $amount,
        $regularBalance,
        $promoBalance,
        $occurredAt,
        $referenceId,
        json_encode($metadata),
        $idempotencyKey
    ]);

    // Prepare entry data for return
    $entry = [
        'id' => $id,
        'user_id' => $userId,
        'token_type' => $tokenType,
        'direction' => $direction,
        'reason' => $reason,
        'amount' => $amount,
        'balance_after_regular' => $regularBalance,
        'balance_after_promo' => $promoBalance,
        'occurred_at' => $occurredAt,
        'reference_id' => $referenceId,
        'metadata' => $metadata
    ];

    // Publish wallet.updated event
    publish_event('wallet.updated', [
        'user_id' => $userId,
        'balances' => [
            'regular' => $regularBalance,
            'promo' => $promoBalance,
            'total' => $regularBalance + $promoBalance
        ],
        'reason' => $reason,
        'delta' => $direction === DIRECTION_CREDIT ? $amount : -$amount,
        'occurred_at' => $occurredAt
    ]);

    return $entry;
}

/**
 * Credit tokens to wallet
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $amount Amount to credit
 * @param string $tokenType 'regular' or 'promo'
 * @param string $reason Reason enum value
 * @param string|null $referenceId Optional reference
 * @param array $metadata Additional metadata
 * @param string|null $idempotencyKey Idempotency key
 * @return array Ledger entry
 */
function wallet_credit(
    PDO $pdo,
    int $userId,
    int $amount,
    string $tokenType,
    string $reason,
    ?string $referenceId = null,
    array $metadata = [],
    ?string $idempotencyKey = null
): array {
    return wallet_add_ledger_entry(
        $pdo,
        $userId,
        $tokenType,
        DIRECTION_CREDIT,
        $reason,
        $amount,
        $referenceId,
        $metadata,
        $idempotencyKey
    );
}

/**
 * Debit tokens from wallet
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $amount Amount to debit
 * @param string $tokenType 'regular' or 'promo'
 * @param string $reason Reason enum value
 * @param string|null $referenceId Optional reference
 * @param array $metadata Additional metadata
 * @param string|null $idempotencyKey Idempotency key
 * @return array Ledger entry
 */
function wallet_debit(
    PDO $pdo,
    int $userId,
    int $amount,
    string $tokenType,
    string $reason,
    ?string $referenceId = null,
    array $metadata = [],
    ?string $idempotencyKey = null
): array {
    return wallet_add_ledger_entry(
        $pdo,
        $userId,
        $tokenType,
        DIRECTION_DEBIT,
        $reason,
        $amount,
        $referenceId,
        $metadata,
        $idempotencyKey
    );
}

/**
 * Get wallet transaction history with cursor pagination
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $limit Results per page
 * @param string|null $cursor Opaque cursor for pagination
 * @return array ['items' => array, 'next_cursor' => string|null]
 */
function wallet_get_transactions(PDO $pdo, int $userId, int $limit = 25, ?string $cursor = null): array
{
    // Decode cursor if provided
    $cursorData = null;
    if ($cursor) {
        $cursorData = decode_cursor($cursor);
        if (!$cursorData) {
            validation_error('Invalid cursor format');
        }
    }

    // Build query
    $sql = 'SELECT * FROM wallet_ledger WHERE user_id = ?';
    $params = [$userId];

    if ($cursorData) {
        // Continue from cursor
        $sql .= ' AND occurred_at < ? OR (occurred_at = ? AND id < ?)';
        $params[] = $cursorData['t'];
        $params[] = $cursorData['t'];
        $params[] = $cursorData['id'];
    }

    $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT ?';
    $params[] = $limit + 1; // Fetch one extra to determine if there's a next page

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if there's a next page
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows); // Remove extra row
    }

    // Format items
    $items = array_map(function ($row) {
        return [
            'id' => $row['id'],
            'occurred_at' => $row['occurred_at'],
            'type' => $row['direction'],
            'token_type' => $row['token_type'],
            'reason' => $row['reason'],
            'amount' => (int)$row['amount'],
            'balance_after' => [
                'regular' => (int)$row['balance_after_regular'],
                'promo' => (int)$row['balance_after_promo'],
                'total' => (int)$row['balance_after_regular'] + (int)$row['balance_after_promo']
            ],
            'metadata' => json_decode($row['metadata'], true)
        ];
    }, $rows);

    // Generate next cursor if there's more data
    $nextCursor = null;
    if ($hasMore && !empty($rows)) {
        $lastRow = end($rows);
        $nextCursor = encode_cursor($lastRow['occurred_at'], $lastRow['id']);
    }

    return [
        'items' => $items,
        'next_cursor' => $nextCursor
    ];
}

/**
 * Check if user has already received registration bonus
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return bool True if registration bonus already exists
 */
function wallet_has_registration_bonus(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM wallet_ledger
        WHERE user_id = ? AND reason = ?
    ');
    $stmt->execute([$userId, REASON_REGISTRATION_BONUS]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Deduct tokens with automatic promo-first strategy
 * Deducts promo tokens first, then regular tokens if needed
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $amount Total amount to deduct
 * @param string $reason Reason enum value
 * @param string|null $referenceId Optional reference
 * @param array $metadata Additional metadata
 * @param string|null $idempotencyKey Idempotency key
 * @return array Array of ledger entries created
 */
function wallet_deduct_auto(
    PDO $pdo,
    int $userId,
    int $amount,
    string $reason,
    ?string $referenceId = null,
    array $metadata = [],
    ?string $idempotencyKey = null
): array {
    $balance = wallet_get_balance($pdo, $userId);
    $totalAvailable = $balance['total'];

    if ($totalAvailable < $amount) {
        business_rule_error('Insufficient tokens', [
            'required' => $amount,
            'available' => $totalAvailable
        ]);
    }

    $entries = [];
    $remaining = $amount;

    // Deduct from promo first
    if ($balance['promo'] > 0 && $remaining > 0) {
        $deductPromo = min($balance['promo'], $remaining);
        $entries[] = wallet_debit(
            $pdo,
            $userId,
            $deductPromo,
            TOKEN_TYPE_PROMO,
            $reason,
            $referenceId,
            $metadata,
            $idempotencyKey ? $idempotencyKey . ':promo' : null
        );
        $remaining -= $deductPromo;
    }

    // Deduct from regular if needed
    if ($remaining > 0) {
        $entries[] = wallet_debit(
            $pdo,
            $userId,
            $remaining,
            TOKEN_TYPE_REGULAR,
            $reason,
            $referenceId,
            $metadata,
            $idempotencyKey ? $idempotencyKey . ':regular' : null
        );
    }

    return $entries;
}
