<?php
declare(strict_types=1);

/**
 * Purchase Service
 *
 * Handles token purchase operations:
 * - Create purchase intent
 * - Process payment webhooks
 * - Credit tokens on successful payment
 * - Generate receipts
 */

require_once __DIR__ . '/ulid.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/events.php';

// Purchase statuses
define('PURCHASE_STATUS_CREATED', 'created');
define('PURCHASE_STATUS_PENDING', 'pending');
define('PURCHASE_STATUS_PAID', 'paid');
define('PURCHASE_STATUS_FAILED', 'failed');
define('PURCHASE_STATUS_EXPIRED', 'expired');
define('PURCHASE_STATUS_REFUNDED', 'refunded');

// Token purchase configuration
define('TOKEN_PRICE_PER_UNIT', 3); // ₹3 per token
define('MIN_PURCHASE_TOKENS', 50);
define('MAX_PURCHASE_TOKENS', 10000);

/**
 * Calculate INR amount for tokens (in paise)
 *
 * @param int $tokens Number of tokens
 * @return int Amount in paise (₹1 = 100 paise)
 */
function calculate_purchase_amount(int $tokens): int
{
    return $tokens * TOKEN_PRICE_PER_UNIT * 100; // Convert to paise
}

/**
 * Generate receipt number
 *
 * Format: QL-YYYY-NNNNNN (e.g., QL-2025-000123)
 *
 * @param PDO $pdo Database connection
 * @return string Receipt number
 */
function generate_receipt_number(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "QL-{$year}-";

    // Get the last receipt for this year
    $stmt = $pdo->prepare('
        SELECT receipt_no FROM purchases
        WHERE receipt_no LIKE ?
        ORDER BY receipt_no DESC
        LIMIT 1
    ');
    $stmt->execute([$prefix . '%']);
    $lastReceipt = $stmt->fetchColumn();

    if ($lastReceipt) {
        // Extract number and increment
        $lastNumber = (int)substr($lastReceipt, -6);
        $nextNumber = $lastNumber + 1;
    } else {
        // First receipt of the year
        $nextNumber = 1;
    }

    return $prefix . str_pad((string)$nextNumber, 6, '0', STR_PAD_LEFT);
}

/**
 * Create a purchase intent
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $tokens Number of tokens to purchase
 * @param string $provider Payment provider (razorpay, stripe, etc.)
 * @param string $idempotencyKey Idempotency key
 * @return array Purchase data with provider payload
 */
function create_purchase(
    PDO $pdo,
    int $userId,
    int $tokens,
    string $provider,
    string $idempotencyKey
): array {
    // Validate tokens
    if ($tokens < MIN_PURCHASE_TOKENS || $tokens > MAX_PURCHASE_TOKENS) {
        validation_error('Invalid token amount', [
            'tokens' => "Must be between " . MIN_PURCHASE_TOKENS . " and " . MAX_PURCHASE_TOKENS
        ]);
    }

    // Calculate amount
    $inrAmount = calculate_purchase_amount($tokens);

    // Check for existing purchase with same idempotency key
    $stmt = $pdo->prepare('
        SELECT * FROM purchases WHERE idempotency_key = ?
    ');
    $stmt->execute([$idempotencyKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Return existing purchase (idempotent)
        return format_purchase_response($existing, $provider);
    }

    // Create purchase record
    $purchaseId = ulid();

    // Create provider order based on provider
    $providerPayload = create_provider_order($provider, $purchaseId, $inrAmount, $userId);

    // Insert purchase
    $stmt = $pdo->prepare('
        INSERT INTO purchases (
            id, user_id, status, tokens, inr_amount, provider,
            provider_order_id, metadata, idempotency_key
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $purchaseId,
        $userId,
        PURCHASE_STATUS_CREATED,
        $tokens,
        $inrAmount,
        $provider,
        $providerPayload['order_id'],
        json_encode(['user_id' => $userId]),
        $idempotencyKey
    ]);

    // Fetch created purchase
    $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ?');
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    return format_purchase_response($purchase, $provider, $providerPayload);
}

/**
 * Create provider order (Razorpay, Stripe, etc.)
 *
 * @param string $provider Provider name
 * @param string $purchaseId Purchase ID
 * @param int $amount Amount in paise
 * @param int $userId User ID
 * @return array Provider order data
 */
function create_provider_order(string $provider, string $purchaseId, int $amount, int $userId): array
{
    if ($provider === 'razorpay') {
        return create_razorpay_order($purchaseId, $amount, $userId);
    } elseif ($provider === 'stripe') {
        return create_stripe_order($purchaseId, $amount, $userId);
    } else {
        validation_error('Unknown payment provider', ['provider' => "Provider '{$provider}' not supported"]);
    }
}

/**
 * Create Razorpay order
 *
 * @param string $purchaseId Purchase ID
 * @param int $amount Amount in paise
 * @param int $userId User ID
 * @return array Razorpay order data
 */
function create_razorpay_order(string $purchaseId, int $amount, int $userId): array
{
    // In production, use Razorpay PHP SDK
    // For now, return mock data structure

    // IMPORTANT: In production, replace this with actual Razorpay API call:
    /*
    $api = new Razorpay\Api\Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
    $order = $api->order->create([
        'receipt' => $purchaseId,
        'amount' => $amount,
        'currency' => 'INR',
        'notes' => [
            'user_id' => $userId,
            'purchase_id' => $purchaseId
        ]
    ]);
    return [
        'order_id' => $order['id'],
        'amount' => $order['amount'],
        'currency' => $order['currency']
    ];
    */

    // Mock response for development
    return [
        'order_id' => 'order_' . strtoupper(substr(md5($purchaseId), 0, 14)),
        'amount' => $amount,
        'currency' => 'INR'
    ];
}

/**
 * Create Stripe order
 *
 * @param string $purchaseId Purchase ID
 * @param int $amount Amount in paise
 * @param int $userId User ID
 * @return array Stripe order data
 */
function create_stripe_order(string $purchaseId, int $amount, int $userId): array
{
    // In production, use Stripe PHP SDK
    // For now, return mock data structure

    return [
        'order_id' => 'pi_' . strtoupper(substr(md5($purchaseId), 0, 24)),
        'amount' => $amount,
        'currency' => 'INR'
    ];
}

/**
 * Format purchase response
 *
 * @param array $purchase Purchase record
 * @param string $provider Provider name
 * @param array|null $providerPayload Provider-specific payload
 * @return array Formatted response
 */
function format_purchase_response(array $purchase, string $provider, ?array $providerPayload = null): array
{
    $response = [
        'purchase_id' => $purchase['id'],
        'status' => $purchase['status'],
        'tokens' => (int)$purchase['tokens'],
        'inr_amount' => (int)$purchase['inr_amount'],
        'provider' => $provider
    ];

    if ($providerPayload) {
        $response['provider_payload'] = $providerPayload;
    }

    if ($purchase['receipt_no']) {
        $response['receipt_no'] = $purchase['receipt_no'];
    }

    return $response;
}

/**
 * Get purchase by ID
 *
 * @param PDO $pdo Database connection
 * @param string $purchaseId Purchase ID
 * @param int $userId User ID (for authorization)
 * @return array|null Purchase data or null if not found
 */
function get_purchase(PDO $pdo, string $purchaseId, int $userId): ?array
{
    $stmt = $pdo->prepare('
        SELECT * FROM purchases
        WHERE id = ? AND user_id = ?
    ');
    $stmt->execute([$purchaseId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Process successful payment (called from webhook)
 *
 * @param PDO $pdo Database connection
 * @param string $providerOrderId Provider order ID
 * @param string $providerPaymentId Provider payment ID
 * @return array Result with purchase and ledger info
 */
function process_successful_payment(
    PDO $pdo,
    string $providerOrderId,
    string $providerPaymentId
): array {
    // Find purchase by provider order ID
    $stmt = $pdo->prepare('
        SELECT * FROM purchases WHERE provider_order_id = ?
    ');
    $stmt->execute([$providerOrderId]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        throw new Exception("Purchase not found for order ID: {$providerOrderId}");
    }

    // Check if already paid
    if ($purchase['status'] === PURCHASE_STATUS_PAID) {
        // Already processed (idempotent)
        return [
            'already_processed' => true,
            'purchase_id' => $purchase['id'],
            'receipt_no' => $purchase['receipt_no']
        ];
    }

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Generate receipt number
        $receiptNo = generate_receipt_number($pdo);

        // Update purchase status
        $stmt = $pdo->prepare('
            UPDATE purchases
            SET status = ?, provider_payment_id = ?, receipt_no = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([
            PURCHASE_STATUS_PAID,
            $providerPaymentId,
            $receiptNo,
            $purchase['id']
        ]);

        // Credit tokens to wallet
        $ledgerEntry = wallet_credit(
            $pdo,
            (int)$purchase['user_id'],
            (int)$purchase['tokens'],
            TOKEN_TYPE_REGULAR,
            REASON_TOKEN_PURCHASE,
            $purchase['id'], // reference_id
            [
                'purchase_id' => $purchase['id'],
                'provider_order_id' => $providerOrderId,
                'provider_payment_id' => $providerPaymentId,
                'receipt_no' => $receiptNo
            ],
            "PURCHASE:{$purchase['id']}"
        );

        // Update purchase with ledger transaction ID
        $stmt = $pdo->prepare('
            UPDATE purchases SET ledger_transaction_id = ? WHERE id = ?
        ');
        $stmt->execute([$ledgerEntry['id'], $purchase['id']]);

        // Commit transaction
        $pdo->commit();

        // Get updated balance
        $balance = wallet_get_balance($pdo, (int)$purchase['user_id']);

        // Publish events
        publish_purchase_succeeded(
            (int)$purchase['user_id'],
            $purchase['id'],
            (int)$purchase['tokens'],
            (float)$purchase['inr_amount'] / 100, // Convert paise to rupees
            $providerPaymentId
        );

        return [
            'success' => true,
            'purchase_id' => $purchase['id'],
            'receipt_no' => $receiptNo,
            'tokens_credited' => (int)$purchase['tokens'],
            'new_balance' => $balance['total']
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Get purchases for user
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $limit Results per page
 * @param string|null $cursor Cursor for pagination
 * @return array Purchases list with pagination
 */
function get_user_purchases(PDO $pdo, int $userId, int $limit = 25, ?string $cursor = null): array
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
    $sql = 'SELECT * FROM purchases WHERE user_id = ?';
    $params = [$userId];

    if ($cursorData) {
        $sql .= ' AND created_at < ? OR (created_at = ? AND id < ?)';
        $params[] = $cursorData['t'];
        $params[] = $cursorData['t'];
        $params[] = $cursorData['id'];
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
    $params[] = $limit + 1;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if there's more
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    // Format items
    $items = array_map(function ($row) {
        return [
            'purchase_id' => $row['id'],
            'status' => $row['status'],
            'tokens' => (int)$row['tokens'],
            'inr_amount' => (int)$row['inr_amount'],
            'provider' => $row['provider'],
            'receipt_no' => $row['receipt_no'],
            'created_at' => date('c', strtotime($row['created_at'])),
            'updated_at' => date('c', strtotime($row['updated_at']))
        ];
    }, $rows);

    // Generate next cursor
    $nextCursor = null;
    if ($hasMore && !empty($rows)) {
        $lastRow = end($rows);
        $nextCursor = encode_cursor($lastRow['created_at'], $lastRow['id']);
    }

    return [
        'items' => $items,
        'next_cursor' => $nextCursor
    ];
}

/**
 * Get receipts for user
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $limit Results per page
 * @param string|null $cursor Cursor for pagination
 * @return array Receipts list with pagination
 */
function get_user_receipts(PDO $pdo, int $userId, int $limit = 25, ?string $cursor = null): array
{
    // Same as get_user_purchases but only paid status
    $cursorData = null;
    if ($cursor) {
        $cursorData = decode_cursor($cursor);
        if (!$cursorData) {
            validation_error('Invalid cursor format');
        }
    }

    $sql = 'SELECT * FROM purchases WHERE user_id = ? AND status = ?';
    $params = [$userId, PURCHASE_STATUS_PAID];

    if ($cursorData) {
        $sql .= ' AND created_at < ? OR (created_at = ? AND id < ?)';
        $params[] = $cursorData['t'];
        $params[] = $cursorData['t'];
        $params[] = $cursorData['id'];
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
    $params[] = $limit + 1;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $items = array_map(function ($row) {
        return [
            'receipt_no' => $row['receipt_no'],
            'purchase_id' => $row['id'],
            'tokens' => (int)$row['tokens'],
            'inr_amount' => (int)$row['inr_amount'],
            'paid_at' => date('c', strtotime($row['updated_at']))
        ];
    }, $rows);

    $nextCursor = null;
    if ($hasMore && !empty($rows)) {
        $lastRow = end($rows);
        $nextCursor = encode_cursor($lastRow['created_at'], $lastRow['id']);
    }

    return [
        'items' => $items,
        'next_cursor' => $nextCursor
    ];
}

/**
 * Get receipt by receipt number
 *
 * @param PDO $pdo Database connection
 * @param string $receiptNo Receipt number
 * @param int $userId User ID (for authorization)
 * @return array|null Receipt data or null if not found
 */
function get_receipt(PDO $pdo, string $receiptNo, int $userId): ?array
{
    $stmt = $pdo->prepare('
        SELECT * FROM purchases
        WHERE receipt_no = ? AND user_id = ? AND status = ?
    ');
    $stmt->execute([$receiptNo, $userId, PURCHASE_STATUS_PAID]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        return null;
    }

    return [
        'receipt_no' => $purchase['receipt_no'],
        'purchase_id' => $purchase['id'],
        'tokens' => (int)$purchase['tokens'],
        'inr_amount' => (int)$purchase['inr_amount'],
        'provider' => $purchase['provider'],
        'provider_payment_id' => $purchase['provider_payment_id'],
        'paid_at' => date('c', strtotime($purchase['updated_at'])),
        'created_at' => date('c', strtotime($purchase['created_at']))
    ];
}
