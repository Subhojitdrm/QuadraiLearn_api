<?php
declare(strict_types=1);

/**
 * Promo Token Expiry Service
 *
 * Handles expiration of promotional tokens:
 * - Process expired promo tokens
 * - Preview upcoming expiries
 * - Send expiry notifications
 */

require_once __DIR__ . '/ulid.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/promotions.php';

/**
 * Process promo token expiries
 *
 * @param PDO $pdo Database connection
 * @param string|null $targetDate Target date (YYYY-MM-DD), null = now
 * @return array Processing results
 */
function process_promo_expiries(PDO $pdo, ?string $targetDate = null): array
{
    $targetTimestamp = $targetDate ? strtotime($targetDate . ' 23:59:59') : time();
    $targetDatetime = date('Y-m-d H:i:s', $targetTimestamp);

    // Get all schedules that should expire by target date
    $stmt = $pdo->prepare('
        SELECT * FROM promo_expiry_schedules
        WHERE expiry_at <= ?
          AND status IN (?, ?)
          AND amount_remaining > 0
        ORDER BY user_id, expiry_at ASC
    ');

    $stmt->execute([
        $targetDatetime,
        EXPIRY_STATUS_SCHEDULED,
        EXPIRY_STATUS_PARTIALLY_EXPIRED
    ]);

    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [
        'processed' => 0,
        'total_expired' => 0,
        'users_affected' => [],
        'errors' => []
    ];

    // Group by user for efficient processing
    $schedulesByUser = [];
    foreach ($schedules as $schedule) {
        $userId = (int)$schedule['user_id'];
        if (!isset($schedulesByUser[$userId])) {
            $schedulesByUser[$userId] = [];
        }
        $schedulesByUser[$userId][] = $schedule;
    }

    // Process each user's expiries
    foreach ($schedulesByUser as $userId => $userSchedules) {
        try {
            $result = process_user_expiries($pdo, $userId, $userSchedules);
            $results['processed'] += $result['count'];
            $results['total_expired'] += $result['amount'];
            $results['users_affected'][] = $userId;
        } catch (Exception $e) {
            $results['errors'][] = [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ];
        }
    }

    return $results;
}

/**
 * Process expiries for a single user
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param array $schedules Array of expiry schedules
 * @return array Processing result
 */
function process_user_expiries(PDO $pdo, int $userId, array $schedules): array
{
    // Get current promo balance
    $balance = wallet_get_balance($pdo, $userId);
    $availablePromo = $balance['promo'];

    $totalExpired = 0;
    $processedCount = 0;

    $pdo->beginTransaction();

    try {
        foreach ($schedules as $schedule) {
            if ($availablePromo <= 0) {
                break; // No more promo tokens to deduct
            }

            $toExpire = min((int)$schedule['amount_remaining'], $availablePromo);

            if ($toExpire > 0) {
                // Debit promo tokens
                wallet_debit(
                    $pdo,
                    $userId,
                    $toExpire,
                    TOKEN_TYPE_PROMO,
                    REASON_PROMO_EXPIRY,
                    $schedule['id'], // reference to expiry schedule
                    [
                        'schedule_id' => $schedule['id'],
                        'source_ledger_id' => $schedule['source_ledger_id'],
                        'original_amount' => (int)$schedule['amount_initial']
                    ],
                    "EXPIRY:{$schedule['id']}"
                );

                // Update schedule
                $newRemaining = (int)$schedule['amount_remaining'] - $toExpire;
                $newStatus = $newRemaining > 0 ? EXPIRY_STATUS_PARTIALLY_EXPIRED : EXPIRY_STATUS_EXPIRED;

                $stmt = $pdo->prepare('
                    UPDATE promo_expiry_schedules
                    SET amount_remaining = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$newRemaining, $newStatus, $schedule['id']]);

                $availablePromo -= $toExpire;
                $totalExpired += $toExpire;
                $processedCount++;

                // Publish event
                publish_promo_expired($userId, $toExpire, 'expiry_run_' . date('Ymd'));
            }
        }

        $pdo->commit();

        return [
            'count' => $processedCount,
            'amount' => $totalExpired
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Preview upcoming expiries
 *
 * @param PDO $pdo Database connection
 * @param string $date Target date (YYYY-MM-DD)
 * @return array Preview data per user
 */
function preview_expiries(PDO $pdo, string $date): array
{
    $targetDatetime = date('Y-m-d 23:59:59', strtotime($date));

    $stmt = $pdo->prepare('
        SELECT user_id, SUM(amount_remaining) as total_expiring
        FROM promo_expiry_schedules
        WHERE expiry_at <= ?
          AND status IN (?, ?)
          AND amount_remaining > 0
        GROUP BY user_id
    ');

    $stmt->execute([
        $targetDatetime,
        EXPIRY_STATUS_SCHEDULED,
        EXPIRY_STATUS_PARTIALLY_EXPIRED
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $preview = [];
    foreach ($rows as $row) {
        $userId = (int)$row['user_id'];
        $balance = wallet_get_balance($pdo, $userId);

        $preview[] = [
            'user_id' => $userId,
            'scheduled_expiry' => (int)$row['total_expiring'],
            'current_promo_balance' => $balance['promo'],
            'actual_expiry' => min((int)$row['total_expiring'], $balance['promo'])
        ];
    }

    return $preview;
}

/**
 * Send expiry warning notifications
 *
 * @param PDO $pdo Database connection
 * @param int $daysAhead Number of days to look ahead (default 3)
 * @return int Number of notifications sent
 */
function send_expiry_warnings(PDO $pdo, int $daysAhead = 3): int
{
    $targetDate = date('Y-m-d H:i:s', strtotime("+{$daysAhead} days"));

    // Get schedules expiring within the window
    $stmt = $pdo->prepare('
        SELECT DISTINCT user_id, SUM(amount_remaining) as total_expiring, MIN(expiry_at) as earliest_expiry
        FROM promo_expiry_schedules
        WHERE expiry_at <= ?
          AND expiry_at >= NOW()
          AND status IN (?, ?)
          AND amount_remaining > 0
        GROUP BY user_id
        HAVING total_expiring > 0
    ');

    $stmt->execute([
        $targetDate,
        EXPIRY_STATUS_SCHEDULED,
        EXPIRY_STATUS_PARTIALLY_EXPIRED
    ]);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notificationCount = 0;
    foreach ($users as $user) {
        try {
            publish_promo_expiry_upcoming(
                (int)$user['user_id'],
                (int)$user['total_expiring'],
                date('c', strtotime($user['earliest_expiry']))
            );
            $notificationCount++;
        } catch (Exception $e) {
            error_log("Failed to send expiry warning to user {$user['user_id']}: " . $e->getMessage());
        }
    }

    return $notificationCount;
}
