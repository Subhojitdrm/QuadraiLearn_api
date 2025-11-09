<?php
declare(strict_types=1);

/**
 * POST /api/v1/payments/webhook/{provider}
 *
 * Handle payment provider webhooks (server-to-server)
 *
 * Security:
 * - Signature verification
 * - Provider IP allowlist (optional, add in production)
 * - Idempotent processing
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Razorpay-Signature, Stripe-Signature');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/errors.php';
require_once __DIR__ . '/../../lib/webhooks.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get provider from query string
    $provider = $_GET['provider'] ?? null;
    if (!$provider) {
        http_response_code(400);
        echo json_encode(['error' => 'Provider parameter required']);
        exit;
    }

    $provider = strtolower(trim($provider));

    // Get raw payload
    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);

    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // Get signature header based on provider
    $signature = null;
    if ($provider === 'razorpay') {
        $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? null;
    } elseif ($provider === 'stripe') {
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;
    }

    if (!$signature) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing signature header']);
        exit;
    }

    // Get database connection
    $pdo = get_db();

    // Handle webhook
    $result = handle_webhook($pdo, $provider, $payload, $signature);

    if ($result['success']) {
        http_response_code(200);
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }

} catch (Exception $e) {
    error_log("Error in POST /v1/payments/webhook: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Webhook processing failed',
        'message' => $e->getMessage()
    ]);
}
