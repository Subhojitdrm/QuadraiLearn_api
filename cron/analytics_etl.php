<?php
declare(strict_types=1);

/**
 * Analytics ETL Job (Background Job)
 *
 * Runs nightly at 03:00 IST to populate analytics_token_daily materialized table
 *
 * Cron setup:
 * 0 3 * * * php /path/to/cron/analytics_etl.php >> /path/to/logs/analytics_etl.log 2>&1
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Log function
function log_message(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

log_message('=== Analytics ETL Job Started ===');

try {
    // Get database connection
    $pdo = get_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Determine date range to process
    // Process yesterday's data (or any missing days)
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // Get the last processed date
    $lastProcessedSql = 'SELECT MAX(date) as last_date FROM analytics_token_daily';
    $stmt = $pdo->query($lastProcessedSql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $lastProcessed = $result['last_date'] ?? null;

    // Determine start date
    if ($lastProcessed) {
        // Start from the day after last processed
        $startDate = date('Y-m-d', strtotime($lastProcessed . ' +1 day'));
    } else {
        // No data yet, start from the earliest ledger entry
        $firstEntrySql = 'SELECT MIN(DATE(occurred_at)) as first_date FROM wallet_ledger';
        $stmt = $pdo->query($firstEntrySql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $startDate = $result['first_date'] ?? $yesterday;
    }

    // Don't process today (incomplete data)
    $endDate = $yesterday;

    if ($startDate > $endDate) {
        log_message('No new data to process. Last processed: ' . $lastProcessed);
        log_message('=== Analytics ETL Job Completed ===');
        exit(0);
    }

    log_message("Processing date range: {$startDate} to {$endDate}");

    // Process each date
    $currentDate = $startDate;
    $processedCount = 0;

    while ($currentDate <= $endDate) {
        log_message("Processing date: {$currentDate}");

        // Aggregate data for the date
        $aggregateSql = '
            SELECT
                DATE(occurred_at) as date,
                SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as credited,
                SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as debited,
                SUM(CASE WHEN direction = "credit" THEN amount ELSE -amount END) as net,
                COUNT(DISTINCT user_id) as active_users
            FROM wallet_ledger
            WHERE DATE(occurred_at) = ?
            GROUP BY DATE(occurred_at)
        ';

        $stmt = $pdo->prepare($aggregateSql);
        $stmt->execute([$currentDate]);
        $aggregate = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get revenue for the date
        $revenueSql = '
            SELECT COALESCE(SUM(inr_amount), 0) / 100 as revenue_inr
            FROM purchases
            WHERE status = "paid" AND DATE(updated_at) = ?
        ';

        $stmt = $pdo->prepare($revenueSql);
        $stmt->execute([$currentDate]);
        $revenue = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get breakdown by feature
        $featureSql = '
            SELECT
                reason as feature,
                SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as credited,
                SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as debited
            FROM wallet_ledger
            WHERE DATE(occurred_at) = ?
            GROUP BY reason
        ';

        $stmt = $pdo->prepare($featureSql);
        $stmt->execute([$currentDate]);
        $featureBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byFeature = [];
        foreach ($featureBreakdown as $item) {
            $byFeature[$item['feature']] = [
                'credited' => (int)$item['credited'],
                'debited' => (int)$item['debited']
            ];
        }

        // Get regular vs promo split
        $compositionSql = '
            SELECT
                token_type,
                SUM(amount) as total
            FROM wallet_ledger
            WHERE DATE(occurred_at) = ? AND direction = "credit"
            GROUP BY token_type
        ';

        $stmt = $pdo->prepare($compositionSql);
        $stmt->execute([$currentDate]);
        $composition = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $regular = 0;
        $promo = 0;

        foreach ($composition as $item) {
            if ($item['token_type'] === 'regular') {
                $regular = (int)$item['total'];
            } else {
                $promo = (int)$item['total'];
            }
        }

        $total = $regular + $promo;
        $regularVsPromo = [
            'regular' => $total > 0 ? round(($regular / $total) * 100, 2) : 0,
            'promo' => $total > 0 ? round(($promo / $total) * 100, 2) : 0
        ];

        // Insert or update analytics_token_daily
        $insertSql = '
            INSERT INTO analytics_token_daily
            (date, credited, debited, net, revenue_in_inr, active_users, by_feature, regular_vs_promo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                credited = VALUES(credited),
                debited = VALUES(debited),
                net = VALUES(net),
                revenue_in_inr = VALUES(revenue_in_inr),
                active_users = VALUES(active_users),
                by_feature = VALUES(by_feature),
                regular_vs_promo = VALUES(regular_vs_promo),
                updated_at = CURRENT_TIMESTAMP
        ';

        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([
            $currentDate,
            $aggregate['credited'] ?? 0,
            $aggregate['debited'] ?? 0,
            $aggregate['net'] ?? 0,
            $revenue['revenue_inr'] ?? 0,
            $aggregate['active_users'] ?? 0,
            json_encode($byFeature),
            json_encode($regularVsPromo)
        ]);

        $processedCount++;
        log_message("  - Processed {$currentDate}: credited=" . ($aggregate['credited'] ?? 0) .
                    ", debited=" . ($aggregate['debited'] ?? 0) .
                    ", active_users=" . ($aggregate['active_users'] ?? 0));

        // Move to next date
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }

    log_message("Total dates processed: {$processedCount}");

    // Clean up expired exports
    log_message('Cleaning up expired exports...');

    require_once __DIR__ . '/../lib/exports.php';
    $cleanedCount = cleanup_expired_exports($pdo);
    log_message("Cleaned up {$cleanedCount} expired export(s)");

    log_message('=== Analytics ETL Job Completed Successfully ===');
    exit(0);

} catch (PDOException $e) {
    log_message('Database error: ' . $e->getMessage());
    exit(1);

} catch (Exception $e) {
    log_message('Error: ' . $e->getMessage());
    exit(1);
}
