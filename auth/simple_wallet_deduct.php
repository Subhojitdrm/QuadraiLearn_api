<?php
declare(strict_types=1);

/**
 * POST /api/auth/simple_wallet_deduct.php
 *
 * Minimal endpoint to deduct tokens from a user by ID.
 * Payload:
 * {
 *   "userId": 123,
 *   "amount": 25,
 *   "reason": "chapter_generation", // optional, defaults to admin_adjustment
 *   "referenceId": "ORDER-123",     // optional
 *   "metadata": { ... }             // optional object
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/wallet.php';
require_once __DIR__ . '/../lib/auth.php';

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$userClaims = require_auth();
$input = body_json();
$userId = isset($input['userId'])
    ? (int)$input['userId']
    : (int)($userClaims['sub'] ?? 0);
$amount = (int)($input['amount'] ?? 0);
$referenceId = isset($input['referenceId']) ? trim((string)$input['referenceId']) : null;
$metadata = $input['metadata'] ?? [];
$reason = isset($input['reason']) ? trim((string)$input['reason']) : REASON_ADMIN_ADJUSTMENT;

$errors = [];
if ($userId <= 0) {
    $errors['userId'] = 'Valid userId is required';
}
if ($amount <= 0) {
    $errors['amount'] = 'Amount must be greater than zero';
}
if ($metadata !== [] && !is_array($metadata)) {
    $errors['metadata'] = 'metadata must be an object';
}

$validReasons = [
    REASON_ADMIN_ADJUSTMENT,
    REASON_CHAPTER_GENERATION,
    REASON_REFUND_GENERATION_FAILURE,
    REASON_PROMO_EXPIRY,
    REASON_MIGRATION_CORRECTION,
    REASON_TOKEN_PURCHASE,
    REASON_REFERRAL_BONUS,
];
if (!in_array($reason, $validReasons, true)) {
    $errors['reason'] = 'Reason must be one of: ' . implode(', ', $validReasons);
}

if ($errors) {
    json_out(422, ['ok' => false, 'errors' => $errors]);
}

try {
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $entries = wallet_deduct_auto(
        $pdo,
        $userId,
        $amount,
        $reason,
        $referenceId,
        is_array($metadata) ? $metadata : [],
        null
    );

    $balance = wallet_get_balance($pdo, $userId);

    $pdo->commit();

    json_out(200, [
        'ok' => true,
        'message' => 'Tokens deducted successfully',
        'deducted' => $amount,
        'entries' => $entries,
        'balance' => $balance,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}
