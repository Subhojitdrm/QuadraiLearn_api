<?php
declare(strict_types=1);

/**
 * POST /api/auth/redeem_referral_code.php
 *
 * Redeem a referral code and credit custom tokens to the authenticated user.
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
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/wallet.php';

function get_referral_master_key(): ?string {
    return 'QUADRA_MASTER_REFERRAL_KEY_2025';
}

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

$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) {
    json_out(401, ['ok' => false, 'error' => 'invalid_user_claims']);
}

$body = body_json();
$code = strtoupper(trim((string)($body['referralCode'] ?? '')));
$tokenAmount = isset($body['tokenAmount']) ? (int)$body['tokenAmount'] : 0;
$masterKeyInput = trim((string)($body['masterKey'] ?? ''));
$expectedMasterKey = get_referral_master_key();
$usesMasterKey = $masterKeyInput !== '';

$errors = [];
if ($tokenAmount <= 0) {
    $errors['tokenAmount'] = 'tokenAmount must be greater than zero';
}
if (!$usesMasterKey) {
    if ($code === '' || strlen($code) > 32) {
        $errors['referralCode'] = 'Valid referralCode is required';
    }
} else {
    if (!$expectedMasterKey || !hash_equals($expectedMasterKey, $masterKeyInput)) {
        $errors['masterKey'] = 'Invalid masterKey provided';
    }
}
if (!$usesMasterKey && $code === '') {
    $errors['referralCode'] = 'referralCode is required';
}
if ($usesMasterKey && $code !== '') {
    // optional but prevent mixing
    $errors['referralCode'] = 'Do not pass referralCode when using masterKey';
}
if ($errors) {
    json_out(422, ['ok' => false, 'errors' => $errors]);
}

try {
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($usesMasterKey) {
        $pdo->beginTransaction();
        $idempotencyKey = sprintf('MASTER_REFERRAL:%d:%d:%s', $userId, $tokenAmount, date('Ymd'));
        $ledgerEntry = wallet_credit(
            $pdo,
            $userId,
            $tokenAmount,
            TOKEN_TYPE_REGULAR,
            REASON_REFERRAL_BONUS,
            null,
            [
                'masterKey' => true,
            ],
            $idempotencyKey
        );
        $pdo->commit();
        $balance = wallet_get_balance($pdo, $userId);
        json_out(200, [
            'ok' => true,
            'message' => 'Master referral bonus credited',
            'awardedTokens' => $tokenAmount,
            'ledgerEntry' => $ledgerEntry,
            'balance' => $balance,
        ]);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT * FROM referrals WHERE referral_code = :code LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':code' => $code]);
    $referral = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$referral) {
        $pdo->rollBack();
        json_out(404, ['ok' => false, 'error' => 'invalid_referral_code']);
    }

    $referrerId = (int)$referral['referrer_user_id'];
    if ($referrerId === $userId) {
        $pdo->rollBack();
        json_out(422, ['ok' => false, 'error' => 'cannot_redeem_own_code']);
    }

    $existingReferee = $referral['referee_user_id'];
    if ($existingReferee !== null && (int)$existingReferee !== $userId) {
        $pdo->rollBack();
        json_out(409, ['ok' => false, 'error' => 'referral_code_already_used']);
    }

    if ($referral['status'] === 'credited' && (int)$existingReferee === $userId) {
        $pdo->rollBack();
        json_out(409, ['ok' => false, 'error' => 'referral_already_redeemed']);
    }

    $idempotencyKey = sprintf('REFERRAL:%s:%d', $code, $userId);
    $ledgerEntry = wallet_credit(
        $pdo,
        $userId,
        $tokenAmount,
        TOKEN_TYPE_REGULAR,
        REASON_REFERRAL_BONUS,
        $referral['id'],
        [
            'referralCode' => $code,
            'campaignId' => $referral['campaign_id'],
            'referrerUserId' => $referrerId,
        ],
        $idempotencyKey
    );

    $update = $pdo->prepare('
        UPDATE referrals
        SET referee_user_id = :referee,
            status = :status,
            ledger_transaction_id = :ledger,
            updated_at = NOW()
        WHERE id = :id
    ');
    $update->execute([
        ':referee' => $userId,
        ':status'  => 'credited',
        ':ledger'  => $ledgerEntry['id'],
        ':id'      => $referral['id'],
    ]);

    $pdo->commit();

    $balance = wallet_get_balance($pdo, $userId);

    json_out(200, [
        'ok' => true,
        'message' => 'Referral bonus credited',
        'awardedTokens' => $tokenAmount,
        'ledgerEntry' => $ledgerEntry,
        'balance' => $balance,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server_error';
    json_out(500, ['ok' => false, 'error' => $message]);
}
