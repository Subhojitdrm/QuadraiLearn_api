<?php
declare(strict_types=1);

/**
 * POST /api/auth/referral_link.php
 *
 * Professional referral code generator: requires Bearer JWT and always issues a fresh code.
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
require_once __DIR__ . '/../lib/promotions.php';
require_once __DIR__ . '/../lib/ulid.php';

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) {
    json_out(401, ['ok' => false, 'error' => 'invalid_user_claims']);
}

try {
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare('
        SELECT id FROM promotion_campaigns
        WHERE type = :type AND status = :status
        LIMIT 1
    ');
    $stmt->execute([
        ':type' => CAMPAIGN_TYPE_REFERRAL,
        ':status' => CAMPAIGN_STATUS_ACTIVE,
    ]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        $campaignId = ulid();
        $insert = $pdo->prepare('
            INSERT INTO promotion_campaigns (
                id, name, type, status, description, start_at, end_at, metadata
            ) VALUES (:id, :name, :type, :status, :description, NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), :metadata)
        ');
        $insert->execute([
            ':id' => $campaignId,
            ':name' => 'Global Referral Campaign',
            ':type' => CAMPAIGN_TYPE_REFERRAL,
            ':status' => CAMPAIGN_STATUS_ACTIVE,
            ':description' => 'Auto-created referral campaign',
            ':metadata' => json_encode(['autoCreated' => true]),
        ]);
        $campaign = ['id' => $campaignId];
    }

    $baseUrl = 'https://app.quadralearn.com';

    $attempts = 0;
    $code = generate_referral_code($userId);
    while ($attempts < 5) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referral_code = :code');
        $check->execute([':code' => $code]);
        if ((int)$check->fetchColumn() === 0) {
            break;
        }
        $code = generate_referral_code($userId);
        $attempts++;
    }

    if ($attempts >= 5) {
        json_out(500, ['ok' => false, 'error' => 'unable_to_generate_unique_code']);
    }

    $referralId = ulid();
    $insertReferral = $pdo->prepare('
        INSERT INTO referrals (id, campaign_id, referrer_user_id, referral_code, status)
        VALUES (:id, :campaign_id, :user_id, :code, :status)
    ');
    $insertReferral->execute([
        ':id' => $referralId,
        ':campaign_id' => $campaign['id'],
        ':user_id' => $userId,
        ':code' => $code,
        ':status' => REFERRAL_STATUS_GENERATED,
    ]);

    json_out(201, [
        'ok' => true,
        'code' => $code,
        'url' => $baseUrl . '/r/' . $code,
        'campaignId' => $campaign['id'],
    ]);
} catch (Throwable $e) {
    $message = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server_error';
    json_out(500, ['ok' => false, 'error' => $message]);
}
