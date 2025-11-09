<?php
declare(strict_types=1);

/**
 * Webhook Handler Service
 *
 * Handles payment webhooks from providers (Razorpay, Stripe, etc.)
 * - Signature verification
 * - Event deduplication
 * - Payment processing
 */

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/purchases.php';

// Webhook event statuses
define('WEBHOOK_STATUS_RECEIVED', 'received');
define('WEBHOOK_STATUS_PROCESSED', 'processed');
define('WEBHOOK_STATUS_SKIPPED', 'skipped');
define('WEBHOOK_STATUS_ERROR', 'error');

// Provider configuration (should be in config.php in production)
// IMPORTANT: Set these in config.php
if (!defined('RAZORPAY_KEY_ID')) {
    define('RAZORPAY_KEY_ID', 'rzp_test_YOUR_KEY_ID');
}
if (!defined('RAZORPAY_KEY_SECRET')) {
    define('RAZORPAY_KEY_SECRET', 'YOUR_WEBHOOK_SECRET');
}

/**
 * Log webhook event to database
 *
 * @param PDO $pdo Database connection
 * @param string $provider Provider name
 * @param string $eventId Event ID from provider
 * @param array $payload Full webhook payload
 * @return int Webhook event record ID
 */
function log_webhook_event(PDO $pdo, string $provider, string $eventId, array $payload): int
{
    $stmt = $pdo->prepare('
        INSERT INTO payment_webhook_events (provider, event_id, payload, status)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$provider, $eventId, json_encode($payload), WEBHOOK_STATUS_RECEIVED]);
    return (int)$pdo->lastInsertId();
}

/**
 * Check if webhook event already processed
 *
 * @param PDO $pdo Database connection
 * @param string $provider Provider name
 * @param string $eventId Event ID
 * @return bool True if already processed
 */
function is_webhook_processed(PDO $pdo, string $provider, string $eventId): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM payment_webhook_events
        WHERE provider = ? AND event_id = ? AND status = ?
    ');
    $stmt->execute([$provider, $eventId, WEBHOOK_STATUS_PROCESSED]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Update webhook event status
 *
 * @param PDO $pdo Database connection
 * @param int $webhookId Webhook event ID
 * @param string $status New status
 * @param string|null $errorMsg Error message (if status is error)
 * @return void
 */
function update_webhook_status(PDO $pdo, int $webhookId, string $status, ?string $errorMsg = null): void
{
    $stmt = $pdo->prepare('
        UPDATE payment_webhook_events
        SET status = ?, processed_at = NOW(), error_msg = ?
        WHERE id = ?
    ');
    $stmt->execute([$status, $errorMsg, $webhookId]);
}

/**
 * Verify Razorpay webhook signature
 *
 * @param string $payload Raw webhook payload
 * @param string $signature Signature from X-Razorpay-Signature header
 * @param string $secret Webhook secret
 * @return bool True if signature is valid
 */
function verify_razorpay_signature(string $payload, string $signature, string $secret): bool
{
    $expectedSignature = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expectedSignature, $signature);
}

/**
 * Verify Stripe webhook signature
 *
 * @param string $payload Raw webhook payload
 * @param string $signature Signature from Stripe-Signature header
 * @param string $secret Webhook secret
 * @return bool True if signature is valid
 */
function verify_stripe_signature(string $payload, string $signature, string $secret): bool
{
    // Parse signature header
    $signatureParts = [];
    foreach (explode(',', $signature) as $element) {
        list($key, $value) = explode('=', $element, 2);
        $signatureParts[$key] = $value;
    }

    if (!isset($signatureParts['t'], $signatureParts['v1'])) {
        return false;
    }

    $timestamp = $signatureParts['t'];
    $signedPayload = "{$timestamp}.{$payload}";
    $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

    return hash_equals($expectedSignature, $signatureParts['v1']);
}

/**
 * Handle Razorpay webhook
 *
 * @param PDO $pdo Database connection
 * @param array $payload Webhook payload
 * @param string $signature Signature header
 * @return array Processing result
 */
function handle_razorpay_webhook(PDO $pdo, array $payload, string $signature): array
{
    // Verify signature
    $rawPayload = json_encode($payload);
    if (!verify_razorpay_signature($rawPayload, $signature, RAZORPAY_KEY_SECRET)) {
        return [
            'success' => false,
            'error' => 'Invalid signature'
        ];
    }

    // Extract event details
    $event = $payload['event'] ?? null;
    $eventId = $payload['event_id'] ?? $payload['id'] ?? null;

    if (!$event || !$eventId) {
        return [
            'success' => false,
            'error' => 'Missing event or event_id'
        ];
    }

    // Log webhook event
    $webhookId = log_webhook_event($pdo, 'razorpay', $eventId, $payload);

    // Check if already processed
    if (is_webhook_processed($pdo, 'razorpay', $eventId)) {
        update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_SKIPPED);
        return [
            'success' => true,
            'message' => 'Event already processed (idempotent)'
        ];
    }

    // Handle different event types
    try {
        switch ($event) {
            case 'payment.captured':
            case 'order.paid':
                $paymentEntity = $payload['payload']['payment']['entity'] ?? $payload['payload']['order']['entity'] ?? null;

                if (!$paymentEntity) {
                    throw new Exception('Missing payment entity in payload');
                }

                $orderId = $paymentEntity['order_id'] ?? null;
                $paymentId = $paymentEntity['id'] ?? null;

                if (!$orderId || !$paymentId) {
                    throw new Exception('Missing order_id or payment_id');
                }

                // Process payment
                $result = process_successful_payment($pdo, $orderId, $paymentId);

                update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_PROCESSED);

                return [
                    'success' => true,
                    'result' => $result
                ];

            case 'payment.failed':
                // Mark purchase as failed (no credit)
                $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
                if ($paymentEntity && isset($paymentEntity['order_id'])) {
                    $stmt = $pdo->prepare('
                        UPDATE purchases SET status = ? WHERE provider_order_id = ?
                    ');
                    $stmt->execute([PURCHASE_STATUS_FAILED, $paymentEntity['order_id']]);
                }

                update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_PROCESSED);

                return [
                    'success' => true,
                    'message' => 'Payment failed, purchase marked as failed'
                ];

            default:
                // Unknown event type, skip
                update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_SKIPPED, "Unknown event: {$event}");
                return [
                    'success' => true,
                    'message' => 'Event type not handled'
                ];
        }
    } catch (Exception $e) {
        update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_ERROR, $e->getMessage());
        throw $e;
    }
}

/**
 * Handle Stripe webhook
 *
 * @param PDO $pdo Database connection
 * @param array $payload Webhook payload
 * @param string $signature Signature header
 * @return array Processing result
 */
function handle_stripe_webhook(PDO $pdo, array $payload, string $signature): array
{
    // Verify signature
    $rawPayload = json_encode($payload);
    if (!verify_stripe_signature($rawPayload, $signature, RAZORPAY_KEY_SECRET)) {
        return [
            'success' => false,
            'error' => 'Invalid signature'
        ];
    }

    // Extract event details
    $eventType = $payload['type'] ?? null;
    $eventId = $payload['id'] ?? null;

    if (!$eventType || !$eventId) {
        return [
            'success' => false,
            'error' => 'Missing type or id'
        ];
    }

    // Log webhook event
    $webhookId = log_webhook_event($pdo, 'stripe', $eventId, $payload);

    // Check if already processed
    if (is_webhook_processed($pdo, 'stripe', $eventId)) {
        update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_SKIPPED);
        return [
            'success' => true,
            'message' => 'Event already processed (idempotent)'
        ];
    }

    // Handle different event types
    try {
        switch ($eventType) {
            case 'payment_intent.succeeded':
            case 'charge.succeeded':
                $paymentIntent = $payload['data']['object'] ?? null;

                if (!$paymentIntent) {
                    throw new Exception('Missing payment intent in payload');
                }

                $orderId = $paymentIntent['metadata']['order_id'] ?? null;
                $paymentId = $paymentIntent['id'] ?? null;

                if (!$orderId || !$paymentId) {
                    throw new Exception('Missing order_id or payment_id');
                }

                // Process payment
                $result = process_successful_payment($pdo, $orderId, $paymentId);

                update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_PROCESSED);

                return [
                    'success' => true,
                    'result' => $result
                ];

            case 'payment_intent.payment_failed':
            case 'charge.failed':
                // Mark purchase as failed
                $paymentIntent = $payload['data']['object'] ?? null;
                if ($paymentIntent && isset($paymentIntent['metadata']['order_id'])) {
                    $stmt = $pdo->prepare('
                        UPDATE purchases SET status = ? WHERE provider_order_id = ?
                    ');
                    $stmt->execute([PURCHASE_STATUS_FAILED, $paymentIntent['metadata']['order_id']]);
                }

                update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_PROCESSED);

                return [
                    'success' => true,
                    'message' => 'Payment failed, purchase marked as failed'
                ];

            default:
                // Unknown event type, skip
                update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_SKIPPED, "Unknown event: {$eventType}");
                return [
                    'success' => true,
                    'message' => 'Event type not handled'
                ];
        }
    } catch (Exception $e) {
        update_webhook_status($pdo, $webhookId, WEBHOOK_STATUS_ERROR, $e->getMessage());
        throw $e;
    }
}

/**
 * Handle webhook based on provider
 *
 * @param PDO $pdo Database connection
 * @param string $provider Provider name (razorpay, stripe)
 * @param array $payload Webhook payload
 * @param string $signature Signature header
 * @return array Processing result
 */
function handle_webhook(PDO $pdo, string $provider, array $payload, string $signature): array
{
    switch ($provider) {
        case 'razorpay':
            return handle_razorpay_webhook($pdo, $payload, $signature);

        case 'stripe':
            return handle_stripe_webhook($pdo, $payload, $signature);

        default:
            return [
                'success' => false,
                'error' => "Unknown provider: {$provider}"
            ];
    }
}
