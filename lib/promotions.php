<?php
declare(strict_types=1);

/**
 * Promotions Service
 *
 * Manages promotional campaigns including:
 * - Campaign creation and management
 * - Referral system
 * - Promo token expiry
 */

require_once __DIR__ . '/ulid.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/wallet.php';

// Campaign types
define('CAMPAIGN_TYPE_REFERRAL', 'referral');
define('CAMPAIGN_TYPE_SEASONAL', 'seasonal');
define('CAMPAIGN_TYPE_BULK', 'bulk');

// Campaign statuses
define('CAMPAIGN_STATUS_DRAFT', 'draft');
define('CAMPAIGN_STATUS_ACTIVE', 'active');
define('CAMPAIGN_STATUS_PAUSED', 'paused');
define('CAMPAIGN_STATUS_ARCHIVED', 'archived');

// Referral statuses
define('REFERRAL_STATUS_GENERATED', 'generated');
define('REFERRAL_STATUS_CLICKED', 'clicked');
define('REFERRAL_STATUS_JOINED', 'joined');
define('REFERRAL_STATUS_CREDITED', 'credited');
define('REFERRAL_STATUS_REJECTED', 'rejected');

// Expiry schedule statuses
define('EXPIRY_STATUS_SCHEDULED', 'scheduled');
define('EXPIRY_STATUS_PARTIALLY_EXPIRED', 'partially_expired');
define('EXPIRY_STATUS_EXPIRED', 'expired');

// Default expiry period (30 days)
define('PROMO_EXPIRY_DAYS', 30);

/**
 * Generate unique referral code
 *
 * @param int $userId User ID
 * @return string 12-character referral code (e.g., SUBH1234XYZW)
 */
function generate_referral_code(int $userId): string
{
    // Use first 4 chars of username or user ID, plus random suffix
    $prefix = strtoupper(substr(md5((string)$userId), 0, 4));
    $suffix = strtoupper(substr(md5(uniqid()), 0, 8));
    return $prefix . $suffix;
}

/**
 * Create or get referral link for user
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $campaignId Campaign ID
 * @param string $baseUrl Base URL for referral links
 * @return array Referral data with code and URL
 */
function create_or_get_referral_link(PDO $pdo, int $userId, string $campaignId, string $baseUrl): array
{
    // Check for existing referral
    $stmt = $pdo->prepare('
        SELECT referral_code FROM referrals
        WHERE referrer_user_id = ? AND campaign_id = ?
        LIMIT 1
    ');
    $stmt->execute([$userId, $campaignId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        return [
            'code' => $existing,
            'url' => $baseUrl . '/r/' . $existing
        ];
    }

    // Generate new code
    $code = generate_referral_code($userId);

    // Ensure uniqueness
    $attempts = 0;
    while ($attempts < 5) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referral_code = ?');
        $stmt->execute([$code]);
        if ((int)$stmt->fetchColumn() === 0) {
            break;
        }
        $code = generate_referral_code($userId);
        $attempts++;
    }

    // Create referral record
    $referralId = ulid();
    $stmt = $pdo->prepare('
        INSERT INTO referrals (id, campaign_id, referrer_user_id, referral_code, status)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$referralId, $campaignId, $userId, $code, REFERRAL_STATUS_GENERATED]);

    return [
        'code' => $code,
        'url' => $baseUrl . '/r/' . $code
    ];
}

/**
 * Get user's referrals
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $limit Results per page
 * @param string|null $cursor Pagination cursor
 * @return array Referrals list
 */
function get_user_referrals(PDO $pdo, int $userId, int $limit = 25, ?string $cursor = null): array
{
    $cursorData = null;
    if ($cursor) {
        $cursorData = decode_cursor($cursor);
    }

    $sql = 'SELECT r.*, u.username as referee_username, u.email as referee_email
            FROM referrals r
            LEFT JOIN users u ON r.referee_user_id = u.id
            WHERE r.referrer_user_id = ?';
    $params = [$userId];

    if ($cursorData) {
        $sql .= ' AND r.created_at < ? OR (r.created_at = ? AND r.id < ?)';
        $params[] = $cursorData['t'];
        $params[] = $cursorData['t'];
        $params[] = $cursorData['id'];
    }

    $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT ?';
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
            'code' => $row['referral_code'],
            'status' => $row['status'],
            'referee_username' => $row['referee_username'],
            'referee_email' => $row['referee_email'],
            'clicks' => (int)$row['click_count'],
            'created_at' => date('c', strtotime($row['created_at']))
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
 * Apply referral bonus on user signup
 *
 * @param PDO $pdo Database connection
 * @param string $referralCode Referral code
 * @param int $newUserId New user ID (referee)
 * @param string $campaignId Campaign ID
 * @return array Result with bonus details
 */
function apply_referral_bonus(PDO $pdo, string $referralCode, int $newUserId, string $campaignId): array
{
    // Get campaign
    $stmt = $pdo->prepare('SELECT * FROM promotion_campaigns WHERE id = ? AND status = ?');
    $stmt->execute([$campaignId, CAMPAIGN_STATUS_ACTIVE]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        throw new Exception('Campaign not found or inactive');
    }

    // Validate campaign dates
    $now = time();
    if ($campaign['start_at'] && strtotime($campaign['start_at']) > $now) {
        throw new Exception('Campaign not yet started');
    }
    if ($campaign['end_at'] && strtotime($campaign['end_at']) < $now) {
        throw new Exception('Campaign has ended');
    }

    // Find referral
    $stmt = $pdo->prepare('SELECT * FROM referrals WHERE referral_code = ? AND campaign_id = ?');
    $stmt->execute([$referralCode, $campaignId]);
    $referral = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$referral) {
        throw new Exception('Invalid referral code');
    }

    $referrerId = (int)$referral['referrer_user_id'];

    // Check per-user cap for referrer
    if ($campaign['per_user_cap']) {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM referrals
            WHERE referrer_user_id = ? AND campaign_id = ? AND status = ?
        ');
        $stmt->execute([$referrerId, $campaignId, REFERRAL_STATUS_CREDITED]);
        $creditedCount = (int)$stmt->fetchColumn();

        if ($creditedCount >= $campaign['per_user_cap']) {
            throw new Exception('Referrer has reached maximum referrals for this campaign');
        }
    }

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Update referral status
        $stmt = $pdo->prepare('
            UPDATE referrals
            SET referee_user_id = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$newUserId, REFERRAL_STATUS_JOINED, $referral['id']]);

        // Credit bonus to referrer
        $ledgerEntry = wallet_credit(
            $pdo,
            $referrerId,
            (int)$campaign['bonus_amount'],
            $campaign['token_type'],
            REASON_REFERRAL_BONUS,
            $newUserId, // reference to new user
            [
                'campaign_id' => $campaignId,
                'referral_code' => $referralCode,
                'referee_user_id' => $newUserId
            ],
            "REFERRAL:{$referralCode}:{$newUserId}"
        );

        // Update referral with ledger transaction
        $stmt = $pdo->prepare('
            UPDATE referrals
            SET ledger_transaction_id = ?, status = ?
            WHERE id = ?
        ');
        $stmt->execute([$ledgerEntry['id'], REFERRAL_STATUS_CREDITED, $referral['id']]);

        // If promo tokens, create expiry schedule
        if ($campaign['token_type'] === TOKEN_TYPE_PROMO) {
            create_promo_expiry_schedule(
                $pdo,
                $referrerId,
                $ledgerEntry['id'],
                (int)$campaign['bonus_amount']
            );
        }

        $pdo->commit();

        return [
            'success' => true,
            'referrer_user_id' => $referrerId,
            'bonus_amount' => (int)$campaign['bonus_amount'],
            'token_type' => $campaign['token_type'],
            'transaction_id' => $ledgerEntry['id']
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Create promo expiry schedule
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $ledgerId Source ledger entry ID
 * @param int $amount Promo amount
 * @return string Schedule ID
 */
function create_promo_expiry_schedule(PDO $pdo, int $userId, string $ledgerId, int $amount): string
{
    $scheduleId = ulid();
    $expiryAt = date('Y-m-d H:i:s', strtotime('+' . PROMO_EXPIRY_DAYS . ' days'));

    $stmt = $pdo->prepare('
        INSERT INTO promo_expiry_schedules (
            id, user_id, source_ledger_id, expiry_at,
            amount_initial, amount_remaining, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    $stmt->execute([
        $scheduleId,
        $userId,
        $ledgerId,
        $expiryAt,
        $amount,
        $amount,
        EXPIRY_STATUS_SCHEDULED
    ]);

    return $scheduleId;
}

/**
 * Get upcoming promo expiries for user
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $range Range (e.g., 'next_30d', 'next_7d')
 * @return array Expiry items
 */
function get_upcoming_expiries(PDO $pdo, int $userId, string $range = 'next_30d'): array
{
    $days = 30;
    if ($range === 'next_7d') {
        $days = 7;
    } elseif ($range === 'next_3d') {
        $days = 3;
    }

    $stmt = $pdo->prepare('
        SELECT es.*, wl.reason
        FROM promo_expiry_schedules es
        JOIN wallet_ledger wl ON es.source_ledger_id = wl.id
        WHERE es.user_id = ?
          AND es.status IN (?, ?)
          AND es.expiry_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
          AND es.amount_remaining > 0
        ORDER BY es.expiry_at ASC
    ');

    $stmt->execute([
        $userId,
        EXPIRY_STATUS_SCHEDULED,
        EXPIRY_STATUS_PARTIALLY_EXPIRED,
        $days
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'items' => array_map(function ($row) {
            return [
                'date' => date('Y-m-d', strtotime($row['expiry_at'])),
                'amount' => (int)$row['amount_remaining'],
                'source' => $row['reason']
            ];
        }, $rows)
    ];
}

/**
 * Get campaign statistics
 *
 * @param PDO $pdo Database connection
 * @param string $campaignId Campaign ID
 * @return array Campaign stats
 */
function get_campaign_stats(PDO $pdo, string $campaignId): array
{
    // Total granted (sum of all credited bonuses)
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(wl.amount), 0) as granted
        FROM referrals r
        JOIN wallet_ledger wl ON r.ledger_transaction_id = wl.id
        WHERE r.campaign_id = ? AND r.status = ?
    ');
    $stmt->execute([$campaignId, REFERRAL_STATUS_CREDITED]);
    $granted = (int)$stmt->fetchColumn();

    // Total expired
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(wl.amount), 0) as expired
        FROM promo_expiry_schedules es
        JOIN wallet_ledger wl ON wl.reference_id = es.id
        WHERE wl.reason = ? AND es.user_id IN (
            SELECT DISTINCT referrer_user_id FROM referrals WHERE campaign_id = ?
        )
    ');
    $stmt->execute([REASON_PROMO_EXPIRY, $campaignId]);
    $expired = (int)$stmt->fetchColumn();

    // Active users (unique referrers who got credited)
    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT referrer_user_id) FROM referrals
        WHERE campaign_id = ? AND status = ?
    ');
    $stmt->execute([$campaignId, REFERRAL_STATUS_CREDITED]);
    $activeUsers = (int)$stmt->fetchColumn();

    // Joined (total referee signups)
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM referrals
        WHERE campaign_id = ? AND status IN (?, ?)
    ');
    $stmt->execute([$campaignId, REFERRAL_STATUS_JOINED, REFERRAL_STATUS_CREDITED]);
    $joined = (int)$stmt->fetchColumn();

    return [
        'granted' => $granted,
        'expired' => $expired,
        'active_users' => $activeUsers,
        'joined' => $joined
    ];
}
