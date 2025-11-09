<?php
declare(strict_types=1);

/**
 * Export Service
 *
 * Handles data export to CSV and JSON formats
 */

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/ulid.php';

// Export directory (ensure this exists and is writable)
const EXPORT_DIR = __DIR__ . '/../exports/';
const EXPORT_URL_BASE = '/exports/'; // Base URL for downloads
const EXPORT_EXPIRY_HOURS = 24; // Download link expires after 24 hours

/**
 * Dataset definitions with their SQL queries
 */
const DATASET_QUERIES = [
    'transactions' => [
        'sql' => '
            SELECT
                wl.id,
                wl.user_id,
                u.username,
                u.email,
                wl.token_type,
                wl.direction,
                wl.reason,
                wl.amount,
                wl.balance_after_regular,
                wl.balance_after_promo,
                wl.reference_id,
                wl.metadata,
                wl.occurred_at
            FROM wallet_ledger wl
            JOIN users u ON wl.user_id = u.id
            WHERE 1=1
        ',
        'filters' => [
            'from' => 'AND DATE(wl.occurred_at) >= ?',
            'to' => 'AND DATE(wl.occurred_at) <= ?',
            'user_id' => 'AND wl.user_id = ?',
            'token_type' => 'AND wl.token_type = ?',
            'direction' => 'AND wl.direction = ?',
            'reason' => 'AND wl.reason = ?'
        ],
        'order' => 'ORDER BY wl.occurred_at DESC',
        'columns' => ['id', 'user_id', 'username', 'email', 'token_type', 'direction', 'reason', 'amount', 'balance_after_regular', 'balance_after_promo', 'reference_id', 'metadata', 'occurred_at']
    ],

    'purchases' => [
        'sql' => '
            SELECT
                p.id,
                p.user_id,
                u.username,
                u.email,
                p.tokens,
                p.inr_amount / 100 as inr_amount,
                p.provider,
                p.provider_order_id,
                p.status,
                p.receipt_no,
                p.created_at,
                p.updated_at
            FROM purchases p
            JOIN users u ON p.user_id = u.id
            WHERE 1=1
        ',
        'filters' => [
            'from' => 'AND DATE(p.created_at) >= ?',
            'to' => 'AND DATE(p.created_at) <= ?',
            'user_id' => 'AND p.user_id = ?',
            'status' => 'AND p.status = ?',
            'provider' => 'AND p.provider = ?'
        ],
        'order' => 'ORDER BY p.created_at DESC',
        'columns' => ['id', 'user_id', 'username', 'email', 'tokens', 'inr_amount', 'provider', 'provider_order_id', 'status', 'receipt_no', 'created_at', 'updated_at']
    ],

    'referrals' => [
        'sql' => '
            SELECT
                r.id,
                r.campaign_id,
                c.name as campaign_name,
                r.referrer_user_id,
                u1.username as referrer_username,
                u1.email as referrer_email,
                r.referee_user_id,
                u2.username as referee_username,
                u2.email as referee_email,
                r.referral_code,
                r.status,
                r.bonus_awarded,
                r.created_at,
                r.credited_at
            FROM referrals r
            JOIN promotion_campaigns c ON r.campaign_id = c.id
            JOIN users u1 ON r.referrer_user_id = u1.id
            LEFT JOIN users u2 ON r.referee_user_id = u2.id
            WHERE 1=1
        ',
        'filters' => [
            'from' => 'AND DATE(r.created_at) >= ?',
            'to' => 'AND DATE(r.created_at) <= ?',
            'campaign_id' => 'AND r.campaign_id = ?',
            'status' => 'AND r.status = ?',
            'referrer_user_id' => 'AND r.referrer_user_id = ?'
        ],
        'order' => 'ORDER BY r.created_at DESC',
        'columns' => ['id', 'campaign_id', 'campaign_name', 'referrer_user_id', 'referrer_username', 'referrer_email', 'referee_user_id', 'referee_username', 'referee_email', 'referral_code', 'status', 'bonus_awarded', 'created_at', 'credited_at']
    ],

    'authorizations' => [
        'sql' => '
            SELECT
                ta.id,
                ta.user_id,
                u.username,
                u.email,
                ta.feature,
                ta.units,
                ta.cost_per_unit,
                ta.total_cost,
                ta.resource_key,
                ta.status,
                ta.hold_expires_at,
                ta.created_at,
                ta.captured_at,
                ta.voided_at
            FROM token_authorizations ta
            JOIN users u ON ta.user_id = u.id
            WHERE 1=1
        ',
        'filters' => [
            'from' => 'AND DATE(ta.created_at) >= ?',
            'to' => 'AND DATE(ta.created_at) <= ?',
            'user_id' => 'AND ta.user_id = ?',
            'feature' => 'AND ta.feature = ?',
            'status' => 'AND ta.status = ?'
        ],
        'order' => 'ORDER BY ta.created_at DESC',
        'columns' => ['id', 'user_id', 'username', 'email', 'feature', 'units', 'cost_per_unit', 'total_cost', 'resource_key', 'status', 'hold_expires_at', 'created_at', 'captured_at', 'voided_at']
    ],

    'users' => [
        'sql' => '
            SELECT
                u.id,
                u.username,
                u.email,
                u.role,
                wb.regular as balance_regular,
                wb.promo as balance_promo,
                wb.total as balance_total,
                u.created_at,
                wb.last_transaction_at
            FROM users u
            LEFT JOIN wallet_balance_cache wb ON u.id = wb.user_id
            WHERE 1=1
        ',
        'filters' => [
            'role' => 'AND u.role = ?',
            'from' => 'AND DATE(u.created_at) >= ?',
            'to' => 'AND DATE(u.created_at) <= ?'
        ],
        'order' => 'ORDER BY u.created_at DESC',
        'columns' => ['id', 'username', 'email', 'role', 'balance_regular', 'balance_promo', 'balance_total', 'created_at', 'last_transaction_at']
    ]
];

/**
 * Create a new export request
 *
 * @param PDO $pdo Database connection
 * @param int $userId Admin user requesting export
 * @param string $type Export type (csv, json)
 * @param string $dataset Dataset to export
 * @param array $filters Export filters
 * @return array Export record
 */
function create_export(PDO $pdo, int $userId, string $type, string $dataset, array $filters = []): array
{
    // Validate type
    if (!in_array($type, ['csv', 'json'], true)) {
        throw new InvalidArgumentException('Invalid export type. Must be csv or json.');
    }

    // Validate dataset
    if (!isset(DATASET_QUERIES[$dataset])) {
        throw new InvalidArgumentException('Invalid dataset. Available: ' . implode(', ', array_keys(DATASET_QUERIES)));
    }

    // Estimate row count
    $estimatedRows = estimate_export_rows($pdo, $dataset, $filters);

    // Create export record
    $exportId = ULID::generate();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . EXPORT_EXPIRY_HOURS . ' hours'));

    $sql = '
        INSERT INTO analytics_exports
        (id, user_id, type, dataset, filters, status, estimated_rows, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $exportId,
        $userId,
        $type,
        $dataset,
        json_encode($filters),
        'preparing',
        $estimatedRows,
        $expiresAt
    ]);

    return [
        'export_id' => $exportId,
        'status' => 'preparing',
        'type' => $type,
        'dataset' => $dataset,
        'estimated_rows' => $estimatedRows,
        'expires_at' => $expiresAt
    ];
}

/**
 * Estimate number of rows for export
 *
 * @param PDO $pdo Database connection
 * @param string $dataset Dataset name
 * @param array $filters Filters to apply
 * @return int Estimated row count
 */
function estimate_export_rows(PDO $pdo, string $dataset, array $filters): int
{
    $config = DATASET_QUERIES[$dataset];

    // Build count query
    $countSql = preg_replace('/SELECT.*?FROM/s', 'SELECT COUNT(*) as count FROM', $config['sql']);

    // Apply filters
    $params = [];
    foreach ($config['filters'] as $filterKey => $filterClause) {
        if (isset($filters[$filterKey])) {
            $countSql .= ' ' . $filterClause;
            $params[] = $filters[$filterKey];
        }
    }

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int)($result['count'] ?? 0);
}

/**
 * Generate export file (synchronous - call from background job for large exports)
 *
 * @param PDO $pdo Database connection
 * @param string $exportId Export ID
 * @return bool Success status
 */
function generate_export_file(PDO $pdo, string $exportId): bool
{
    try {
        // Get export record
        $export = get_export_record($pdo, $exportId);

        if (!$export) {
            return false;
        }

        if ($export['status'] !== 'preparing') {
            return false;
        }

        // Fetch data
        $data = fetch_export_data($pdo, $export['dataset'], json_decode($export['filters'], true));

        // Ensure export directory exists
        if (!file_exists(EXPORT_DIR)) {
            mkdir(EXPORT_DIR, 0755, true);
        }

        // Generate filename
        $timestamp = date('YmdHis');
        $filename = "{$export['dataset']}_{$timestamp}.{$export['type']}";
        $filePath = EXPORT_DIR . $filename;

        // Generate file based on type
        if ($export['type'] === 'csv') {
            $success = generate_csv($filePath, $data, DATASET_QUERIES[$export['dataset']]['columns']);
        } else {
            $success = generate_json($filePath, $data);
        }

        if (!$success) {
            update_export_status($pdo, $exportId, 'failed', 'Failed to generate export file');
            return false;
        }

        // Update export record
        $downloadUrl = EXPORT_URL_BASE . $filename;

        $sql = '
            UPDATE analytics_exports
            SET status = ?,
                actual_rows = ?,
                file_path = ?,
                download_url = ?,
                completed_at = NOW()
            WHERE id = ?
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'ready',
            count($data),
            $filePath,
            $downloadUrl,
            $exportId
        ]);

        return true;

    } catch (Exception $e) {
        update_export_status($pdo, $exportId, 'failed', $e->getMessage());
        return false;
    }
}

/**
 * Fetch data for export
 *
 * @param PDO $pdo Database connection
 * @param string $dataset Dataset name
 * @param array $filters Filters to apply
 * @return array Data rows
 */
function fetch_export_data(PDO $pdo, string $dataset, array $filters): array
{
    $config = DATASET_QUERIES[$dataset];

    // Build query
    $sql = $config['sql'];
    $params = [];

    // Apply filters
    foreach ($config['filters'] as $filterKey => $filterClause) {
        if (isset($filters[$filterKey])) {
            $sql .= ' ' . $filterClause;
            $params[] = $filters[$filterKey];
        }
    }

    // Add ordering
    $sql .= ' ' . $config['order'];

    // Add limit for safety (max 100k rows)
    $sql .= ' LIMIT 100000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Generate CSV file
 *
 * @param string $filePath Output file path
 * @param array $data Data rows
 * @param array $columns Column order
 * @return bool Success status
 */
function generate_csv(string $filePath, array $data, array $columns): bool
{
    $fp = fopen($filePath, 'w');

    if ($fp === false) {
        return false;
    }

    // Write header
    fputcsv($fp, $columns);

    // Write data rows
    foreach ($data as $row) {
        $csvRow = [];
        foreach ($columns as $col) {
            $value = $row[$col] ?? '';

            // Handle JSON columns
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }

            $csvRow[] = $value;
        }
        fputcsv($fp, $csvRow);
    }

    fclose($fp);
    return true;
}

/**
 * Generate JSON file
 *
 * @param string $filePath Output file path
 * @param array $data Data rows
 * @return bool Success status
 */
function generate_json(string $filePath, array $data): bool
{
    $json = json_encode([
        'total' => count($data),
        'data' => $data,
        'exported_at' => date('c')
    ], JSON_PRETTY_PRINT);

    return file_put_contents($filePath, $json) !== false;
}

/**
 * Get export record by ID
 *
 * @param PDO $pdo Database connection
 * @param string $exportId Export ID
 * @return array|null Export record
 */
function get_export_record(PDO $pdo, string $exportId): ?array
{
    $sql = 'SELECT * FROM analytics_exports WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$exportId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ?: null;
}

/**
 * Update export status
 *
 * @param PDO $pdo Database connection
 * @param string $exportId Export ID
 * @param string $status New status
 * @param string|null $errorMessage Error message if failed
 * @return void
 */
function update_export_status(PDO $pdo, string $exportId, string $status, ?string $errorMessage = null): void
{
    $sql = '
        UPDATE analytics_exports
        SET status = ?, error_message = ?
        WHERE id = ?
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $errorMessage, $exportId]);
}

/**
 * Get user's export history
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $limit Number of results
 * @return array Export list
 */
function get_user_exports(PDO $pdo, int $userId, int $limit = 20): array
{
    $sql = '
        SELECT
            id,
            type,
            dataset,
            status,
            estimated_rows,
            actual_rows,
            download_url,
            expires_at,
            created_at,
            completed_at
        FROM analytics_exports
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $limit]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Clean up expired exports (call from cron)
 *
 * @param PDO $pdo Database connection
 * @return int Number of exports cleaned
 */
function cleanup_expired_exports(PDO $pdo): int
{
    // Get expired exports
    $sql = '
        SELECT id, file_path
        FROM analytics_exports
        WHERE status = ? AND expires_at < NOW()
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['ready']);
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cleaned = 0;

    foreach ($expired as $export) {
        // Delete file
        if ($export['file_path'] && file_exists($export['file_path'])) {
            unlink($export['file_path']);
        }

        // Update status
        $updateSql = 'UPDATE analytics_exports SET status = ? WHERE id = ?';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute(['expired', $export['id']]);

        $cleaned++;
    }

    return $cleaned;
}
