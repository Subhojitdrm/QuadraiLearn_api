<?php
declare(strict_types=1);

/**
 * Analytics Service
 *
 * Provides token analytics and KPIs for admin dashboards
 */

require_once __DIR__ . '/errors.php';

/**
 * Get overview KPIs for token activity
 *
 * @param PDO $pdo Database connection
 * @param string $from Start date (YYYY-MM-DD)
 * @param string $to End date (YYYY-MM-DD)
 * @param string|null $feature Filter by feature
 * @param string|null $tokenType Filter by token type (regular/promo)
 * @return array KPI metrics
 */
function get_token_overview(PDO $pdo, string $from, string $to, ?string $feature = null, ?string $tokenType = null): array
{
    $sql = '
        SELECT
            SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as credited,
            SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as debited,
            SUM(CASE WHEN direction = "credit" THEN amount ELSE -amount END) as net,
            COUNT(DISTINCT user_id) as active_users
        FROM wallet_ledger
        WHERE DATE(occurred_at) >= ? AND DATE(occurred_at) <= ?
    ';

    $params = [$from, $to];

    if ($feature) {
        $sql .= ' AND reason = ?';
        $params[] = $feature;
    }

    if ($tokenType) {
        $sql .= ' AND token_type = ?';
        $params[] = $tokenType;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get revenue from purchases in the date range
    $revenueSql = '
        SELECT COALESCE(SUM(inr_amount), 0) / 100 as revenue_inr
        FROM purchases
        WHERE status = "paid"
          AND DATE(updated_at) >= ? AND DATE(updated_at) <= ?
    ';
    $stmt = $pdo->prepare($revenueSql);
    $stmt->execute([$from, $to]);
    $revenue = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'credited' => (int)($result['credited'] ?? 0),
        'debited' => (int)($result['debited'] ?? 0),
        'net' => (int)($result['net'] ?? 0),
        'revenue_in_inr' => (float)($revenue['revenue_inr'] ?? 0),
        'active_users' => (int)($result['active_users'] ?? 0)
    ];
}

/**
 * Get token trend data over time
 *
 * @param PDO $pdo Database connection
 * @param string $granularity daily, weekly, monthly
 * @param string $from Start date
 * @param string $to End date
 * @return array Time series data
 */
function get_token_trend(PDO $pdo, string $granularity, string $from, string $to): array
{
    // Determine date grouping based on granularity
    $dateGroup = match ($granularity) {
        'monthly' => 'DATE_FORMAT(occurred_at, "%Y-%m-01")',
        'weekly' => 'DATE(DATE_SUB(occurred_at, INTERVAL WEEKDAY(occurred_at) DAY))',
        default => 'DATE(occurred_at)'
    };

    $sql = "
        SELECT
            {$dateGroup} as date,
            SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) as credited,
            SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) as debited,
            SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as net,
            COUNT(DISTINCT user_id) as active_users
        FROM wallet_ledger
        WHERE DATE(occurred_at) >= ? AND DATE(occurred_at) <= ?
        GROUP BY {$dateGroup}
        ORDER BY date ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get revenue per period
    $revenueDateGroup = match ($granularity) {
        'monthly' => 'DATE_FORMAT(updated_at, "%Y-%m-01")',
        'weekly' => 'DATE(DATE_SUB(updated_at, INTERVAL WEEKDAY(updated_at) DAY))',
        default => 'DATE(updated_at)'
    };

    $revenueSql = "
        SELECT
            {$revenueDateGroup} as date,
            SUM(inr_amount) / 100 as revenue_inr
        FROM purchases
        WHERE status = 'paid'
          AND DATE(updated_at) >= ? AND DATE(updated_at) <= ?
        GROUP BY {$revenueDateGroup}
    ";

    $stmt = $pdo->prepare($revenueSql);
    $stmt->execute([$from, $to]);
    $revenueByDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $revenueByDate[$row['date']] = (float)$row['revenue_inr'];
    }

    // Merge revenue into main series
    $series = [];
    foreach ($rows as $row) {
        $series[] = [
            'date' => $row['date'],
            'credited' => (int)$row['credited'],
            'debited' => (int)$row['debited'],
            'net' => (int)$row['net'],
            'active_users' => (int)$row['active_users'],
            'revenue_in_inr' => $revenueByDate[$row['date']] ?? 0.0
        ];
    }

    return ['series' => $series];
}

/**
 * Get token usage breakdown by feature
 *
 * @param PDO $pdo Database connection
 * @param string $from Start date
 * @param string $to End date
 * @return array Feature breakdown
 */
function get_tokens_by_feature(PDO $pdo, string $from, string $to): array
{
    $sql = '
        SELECT
            reason as feature,
            SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as credited,
            SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as debited
        FROM wallet_ledger
        WHERE DATE(occurred_at) >= ? AND DATE(occurred_at) <= ?
        GROUP BY reason
        ORDER BY debited DESC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'items' => array_map(function ($row) {
            return [
                'feature' => $row['feature'],
                'credited' => (int)$row['credited'],
                'debited' => (int)$row['debited']
            ];
        }, $rows)
    ];
}

/**
 * Get token composition (regular vs promo)
 *
 * @param PDO $pdo Database connection
 * @param string $from Start date
 * @param string $to End date
 * @return array Composition percentages
 */
function get_token_composition(PDO $pdo, string $from, string $to): array
{
    $sql = '
        SELECT
            token_type,
            SUM(amount) as total
        FROM wallet_ledger
        WHERE DATE(occurred_at) >= ? AND DATE(occurred_at) <= ?
          AND direction = "credit"
        GROUP BY token_type
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $regular = 0;
    $promo = 0;

    foreach ($rows as $row) {
        if ($row['token_type'] === 'regular') {
            $regular = (int)$row['total'];
        } else {
            $promo = (int)$row['total'];
        }
    }

    $total = $regular + $promo;
    if ($total === 0) {
        return ['regular' => 0, 'promo' => 0];
    }

    return [
        'regular' => round(($regular / $total) * 100, 2),
        'promo' => round(($promo / $total) * 100, 2)
    ];
}

/**
 * Get top users leaderboard
 *
 * @param PDO $pdo Database connection
 * @param string $metric spend, earn, net
 * @param string $from Start date
 * @param string $to End date
 * @param int $limit Number of results
 * @return array Top users
 */
function get_top_users(PDO $pdo, string $metric, string $from, string $to, int $limit = 20): array
{
    $selectClause = match ($metric) {
        'earn' => 'SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as value',
        'net' => 'SUM(CASE WHEN direction = "credit" THEN amount ELSE -amount END) as value',
        default => 'SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as value' // spend
    };

    $sql = "
        SELECT
            wl.user_id,
            u.username,
            u.email,
            {$selectClause}
        FROM wallet_ledger wl
        JOIN users u ON wl.user_id = u.id
        WHERE DATE(wl.occurred_at) >= ? AND DATE(wl.occurred_at) <= ?
        GROUP BY wl.user_id
        ORDER BY value DESC
        LIMIT ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'items' => array_map(function ($row) use ($metric) {
            return [
                'user_id' => (int)$row['user_id'],
                'username' => $row['username'],
                'email' => $row['email'],
                $metric => (int)$row['value']
            ];
        }, $rows)
    ];
}

/**
 * Get purchase analytics
 *
 * @param PDO $pdo Database connection
 * @param string $from Start date
 * @param string $to End date
 * @return array Purchase metrics
 */
function get_purchase_analytics(PDO $pdo, string $from, string $to): array
{
    $sql = '
        SELECT
            COUNT(*) as total_purchases,
            SUM(tokens) as total_tokens,
            SUM(inr_amount) / 100 as total_revenue,
            AVG(tokens) as avg_tokens_per_purchase,
            AVG(inr_amount) / 100 as avg_revenue_per_purchase,
            COUNT(DISTINCT user_id) as unique_buyers
        FROM purchases
        WHERE status = "paid"
          AND DATE(updated_at) >= ? AND DATE(updated_at) <= ?
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_purchases' => (int)($result['total_purchases'] ?? 0),
        'total_tokens' => (int)($result['total_tokens'] ?? 0),
        'total_revenue' => (float)($result['total_revenue'] ?? 0),
        'avg_tokens_per_purchase' => (float)($result['avg_tokens_per_purchase'] ?? 0),
        'avg_revenue_per_purchase' => (float)($result['avg_revenue_per_purchase'] ?? 0),
        'unique_buyers' => (int)($result['unique_buyers'] ?? 0)
    ];
}

/**
 * Get promotion analytics
 *
 * @param PDO $pdo Database connection
 * @param string $from Start date
 * @param string $to End date
 * @param string|null $campaignId Filter by campaign
 * @return array Promotion metrics
 */
function get_promotion_analytics(PDO $pdo, string $from, string $to, ?string $campaignId = null): array
{
    $sql = '
        SELECT
            COUNT(DISTINCT r.referrer_user_id) as total_referrers,
            COUNT(*) as total_referrals,
            SUM(CASE WHEN r.status = "credited" THEN 1 ELSE 0 END) as successful_referrals,
            SUM(CASE WHEN wl.id IS NOT NULL THEN wl.amount ELSE 0 END) as total_bonus_awarded
        FROM referrals r
        LEFT JOIN wallet_ledger wl ON r.ledger_transaction_id = wl.id
        WHERE DATE(r.created_at) >= ? AND DATE(r.created_at) <= ?
    ';

    $params = [$from, $to];

    if ($campaignId) {
        $sql .= ' AND r.campaign_id = ?';
        $params[] = $campaignId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_referrers' => (int)($result['total_referrers'] ?? 0),
        'total_referrals' => (int)($result['total_referrals'] ?? 0),
        'successful_referrals' => (int)($result['successful_referrals'] ?? 0),
        'total_bonus_awarded' => (int)($result['total_bonus_awarded'] ?? 0),
        'conversion_rate' => $result['total_referrals'] > 0
            ? round(($result['successful_referrals'] / $result['total_referrals']) * 100, 2)
            : 0
    ];
}
