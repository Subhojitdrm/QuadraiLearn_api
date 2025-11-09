<?php
declare(strict_types=1);

/**
 * Idempotency Service
 *
 * Prevents duplicate operations by storing idempotency keys
 * and returning cached responses for duplicate requests
 *
 * Per specification:
 * - Store idempotency_key per (user_id, operation, resource_key)
 * - On duplicate key: return original response payload and status
 * - Never insert a second ledger entry or trigger a second provider call
 */

require_once __DIR__ . '/errors.php';

/**
 * Check if idempotency key exists and return cached response if found
 *
 * @param PDO $pdo Database connection
 * @param int|null $userId User ID (null for webhooks)
 * @param string $operation Operation identifier (e.g., 'WALLET_SEED')
 * @param string $resourceKey Resource identifier
 * @param string $idempotencyKey Idempotency key from header
 * @return array|null Cached response data or null if not found
 */
function check_idempotency(
    PDO $pdo,
    ?int $userId,
    string $operation,
    string $resourceKey,
    string $idempotencyKey
): ?array {
    $stmt = $pdo->prepare('
        SELECT response_hash, status_code, created_at
        FROM idempotency_keys
        WHERE user_id <=> ? AND operation = ? AND resource_key = ?
    ');

    $stmt->execute([$userId, $operation, $resourceKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    // Found existing idempotency record
    // For now, we'll return a signal that this is a duplicate
    // The caller should handle returning the cached response
    return [
        'status_code' => (int)$row['status_code'],
        'created_at' => $row['created_at'],
        'is_duplicate' => true
    ];
}

/**
 * Store idempotency key with response
 *
 * @param PDO $pdo Database connection
 * @param int|null $userId User ID (null for webhooks)
 * @param string $operation Operation identifier
 * @param string $resourceKey Resource identifier
 * @param string $idempotencyKey Idempotency key from header
 * @param mixed $response Response data to cache
 * @param int $statusCode HTTP status code
 * @return void
 */
function store_idempotency(
    PDO $pdo,
    ?int $userId,
    string $operation,
    string $resourceKey,
    string $idempotencyKey,
    $response,
    int $statusCode
): void {
    // Hash the response
    $responseJson = json_encode($response);
    $responseHash = hash('sha256', $responseJson);

    try {
        $stmt = $pdo->prepare('
            INSERT INTO idempotency_keys (
                user_id, operation, resource_key, idempotency_key,
                response_hash, status_code, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');

        $stmt->execute([
            $userId,
            $operation,
            $resourceKey,
            $idempotencyKey,
            $responseHash,
            $statusCode
        ]);
    } catch (PDOException $e) {
        // If duplicate key error, that's fine - it means concurrent request
        // already stored it. Otherwise, log the error
        if ($e->getCode() !== '23000') { // Not a duplicate key error
            error_log("Failed to store idempotency key: " . $e->getMessage());
        }
    }
}

/**
 * Generate resource key for chapter generation
 *
 * @param int $userId User ID
 * @param array $params Chapter parameters (prompt, subject, grade, page_goal)
 * @return string SHA256 hash of normalized parameters
 */
function generate_chapter_resource_key(int $userId, array $params): string
{
    // Normalize parameters
    $normalized = [
        'user_id' => $userId,
        'prompt' => trim(strtolower($params['prompt'] ?? '')),
        'subject' => trim(strtolower($params['subject'] ?? '')),
        'grade' => trim(strtolower($params['grade'] ?? '')),
        'page_goal' => (int)($params['page_goal'] ?? 0)
    ];

    // Sort by key for consistency
    ksort($normalized);

    // Generate hash
    return hash('sha256', json_encode($normalized));
}

/**
 * Execute operation with idempotency protection
 *
 * @param PDO $pdo Database connection
 * @param int|null $userId User ID (null for webhooks)
 * @param string $operation Operation identifier
 * @param string $resourceKey Resource identifier
 * @param string $idempotencyKey Idempotency key from header
 * @param callable $callback Function to execute if not duplicate
 * @return array Response data
 */
function with_idempotency(
    PDO $pdo,
    ?int $userId,
    string $operation,
    string $resourceKey,
    string $idempotencyKey,
    callable $callback
): array {
    // Check if this operation was already performed
    $existing = check_idempotency($pdo, $userId, $operation, $resourceKey, $idempotencyKey);

    if ($existing && $existing['is_duplicate']) {
        // Return cached response
        // Note: In a full implementation, we'd store and return the actual response body
        // For now, we'll indicate it's a duplicate and let the caller handle it
        return [
            'is_duplicate' => true,
            'status_code' => $existing['status_code'],
            'message' => 'Operation already performed',
            'performed_at' => $existing['created_at']
        ];
    }

    // Execute the operation
    $response = $callback();
    $statusCode = $response['status_code'] ?? 200;

    // Store idempotency record
    store_idempotency($pdo, $userId, $operation, $resourceKey, $idempotencyKey, $response, $statusCode);

    return $response;
}

/**
 * Wrapper for database transactions with idempotency
 *
 * @param PDO $pdo Database connection
 * @param int|null $userId User ID
 * @param string $operation Operation identifier
 * @param string $resourceKey Resource key
 * @param string $idempotencyKey Idempotency key
 * @param callable $callback Transaction callback
 * @return array Response data
 */
function idempotent_transaction(
    PDO $pdo,
    ?int $userId,
    string $operation,
    string $resourceKey,
    string $idempotencyKey,
    callable $callback
): array {
    // Check idempotency first (outside transaction)
    $existing = check_idempotency($pdo, $userId, $operation, $resourceKey, $idempotencyKey);

    if ($existing && $existing['is_duplicate']) {
        conflict_error('Operation already performed', [
            'performed_at' => $existing['created_at']
        ]);
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Execute callback
        $response = $callback();

        // Store idempotency record
        $statusCode = $response['status_code'] ?? 200;
        store_idempotency($pdo, $userId, $operation, $resourceKey, $idempotencyKey, $response, $statusCode);

        // Commit transaction
        $pdo->commit();

        return $response;

    } catch (Exception $e) {
        // Rollback on error
        $pdo->rollBack();
        throw $e;
    }
}
