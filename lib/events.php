<?php
declare(strict_types=1);

/**
 * Events Publishing Utility
 *
 * Publishes events for UI notifications and integrations
 * Events are logged to database and can be consumed by external systems
 *
 * Supported events:
 * - wallet.updated
 * - purchase.succeeded
 * - promo.expiry_upcoming
 * - promo.expired
 */

require_once __DIR__ . '/ulid.php';

/**
 * Publish an event
 *
 * @param string $eventType Event type (e.g., 'wallet.updated')
 * @param array $payload Event payload
 * @return void
 */
function publish_event(string $eventType, array $payload): void
{
    global $pdo;

    // For now, just log events to a simple events table
    // In production, this could integrate with a message queue (RabbitMQ, Redis, etc.)

    try {
        // Check if events table exists, create if not
        ensure_events_table($pdo);

        // Generate event ID
        $eventId = ulid();
        $occurredAt = date('Y-m-d H:i:s');

        // Insert event
        $stmt = $pdo->prepare('
            INSERT INTO events (id, event_type, payload, occurred_at, processed)
            VALUES (?, ?, ?, ?, 0)
        ');

        $stmt->execute([
            $eventId,
            $eventType,
            json_encode($payload),
            $occurredAt
        ]);

        // In development, optionally log to file
        if (defined('DEBUG') && DEBUG) {
            error_log("Event published: {$eventType} - " . json_encode($payload));
        }

    } catch (Exception $e) {
        // Don't fail the main operation if event publishing fails
        // Just log the error
        error_log("Failed to publish event {$eventType}: " . $e->getMessage());
    }
}

/**
 * Ensure events table exists
 *
 * @param PDO $pdo Database connection
 * @return void
 */
function ensure_events_table(PDO $pdo): void
{
    static $tableChecked = false;

    if ($tableChecked) {
        return;
    }

    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS events (
                id VARCHAR(26) PRIMARY KEY,
                event_type VARCHAR(64) NOT NULL,
                payload JSON NOT NULL,
                occurred_at TIMESTAMP NOT NULL,
                processed TINYINT(1) NOT NULL DEFAULT 0,
                processed_at TIMESTAMP NULL,
                INDEX idx_event_type (event_type),
                INDEX idx_processed (processed, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $tableChecked = true;
    } catch (Exception $e) {
        error_log("Failed to create events table: " . $e->getMessage());
    }
}

/**
 * Get unprocessed events
 *
 * @param PDO $pdo Database connection
 * @param string|null $eventType Filter by event type (null = all)
 * @param int $limit Maximum events to fetch
 * @return array Array of events
 */
function get_unprocessed_events(PDO $pdo, ?string $eventType = null, int $limit = 100): array
{
    $sql = 'SELECT * FROM events WHERE processed = 0';
    $params = [];

    if ($eventType) {
        $sql .= ' AND event_type = ?';
        $params[] = $eventType;
    }

    $sql .= ' ORDER BY occurred_at ASC LIMIT ?';
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark event as processed
 *
 * @param PDO $pdo Database connection
 * @param string $eventId Event ID
 * @return void
 */
function mark_event_processed(PDO $pdo, string $eventId): void
{
    $stmt = $pdo->prepare('
        UPDATE events
        SET processed = 1, processed_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute([$eventId]);
}

/**
 * Helper: Publish wallet.updated event
 *
 * @param int $userId User ID
 * @param array $balances ['regular' => int, 'promo' => int, 'total' => int]
 * @param string $reason Reason for update
 * @param int $delta Change in balance (positive for credit, negative for debit)
 * @return void
 */
function publish_wallet_updated(int $userId, array $balances, string $reason, int $delta): void
{
    publish_event('wallet.updated', [
        'user_id' => $userId,
        'balances' => $balances,
        'reason' => $reason,
        'delta' => $delta,
        'occurred_at' => date('c') // ISO 8601 format
    ]);
}

/**
 * Helper: Publish purchase.succeeded event
 *
 * @param int $userId User ID
 * @param string $purchaseId Purchase ID
 * @param int $tokens Tokens purchased
 * @param float $inr Amount in INR
 * @param string $providerRef Provider reference
 * @return void
 */
function publish_purchase_succeeded(int $userId, string $purchaseId, int $tokens, float $inr, string $providerRef): void
{
    publish_event('purchase.succeeded', [
        'user_id' => $userId,
        'purchase_id' => $purchaseId,
        'tokens' => $tokens,
        'inr' => $inr,
        'provider_ref' => $providerRef
    ]);
}

/**
 * Helper: Publish promo.expiry_upcoming event
 *
 * @param int $userId User ID
 * @param int $expiringTokens Number of tokens expiring
 * @param string $expiryDate Expiry date (ISO 8601)
 * @return void
 */
function publish_promo_expiry_upcoming(int $userId, int $expiringTokens, string $expiryDate): void
{
    publish_event('promo.expiry_upcoming', [
        'user_id' => $userId,
        'expiring_tokens' => $expiringTokens,
        'expiry_date' => $expiryDate
    ]);
}

/**
 * Helper: Publish promo.expired event
 *
 * @param int $userId User ID
 * @param int $expiredTokens Number of tokens expired
 * @param string $runId Job run ID
 * @return void
 */
function publish_promo_expired(int $userId, int $expiredTokens, string $runId): void
{
    publish_event('promo.expired', [
        'user_id' => $userId,
        'expired_tokens' => $expiredTokens,
        'run_id' => $runId
    ]);
}
