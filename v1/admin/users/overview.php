<?php
declare(strict_types=1);

/**
 * GET /api/v1/admin/users/overview
 *
 * Returns a paginated list of users enriched with their book counts and wallet balances.
 * Also returns aggregate totals so the admin UI can render a single dashboard card.
 *
 * Query params:
 * - limit  (optional, default 50, max 200)
 * - offset (optional, default 0)
 *
 * Scopes: analytics:read (admin role)
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id, X-Idempotency-Key, X-Source');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/errors.php';
require_once __DIR__ . '/../../../lib/headers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    validation_error('Method not allowed', ['method' => 'Only GET is supported']);
}

/**
 * Simple helper to format timestamps as ISO 8601 or null.
 */
function iso_time_or_null(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? gmdate('c', $ts) : null;
}

/**
 * Checks whether the given table exists in the currently selected database.
 */
function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $headers = validate_standard_headers(false);
    $claims  = require_auth();
    require_admin($claims);
    validate_scopes($claims, ['analytics:read']);

    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $limit  = max(1, min(200, $limit));
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $offset = max(0, $offset);

    $pdo = get_db();

    $hasBooksTable         = table_exists($pdo, 'books');
    $hasWalletCacheTable   = table_exists($pdo, 'wallet_balance_cache');

    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $totalBooks = 0;
    if ($hasBooksTable) {
        $totalBooks = (int)$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
    }

    $totalRegularTokens = 0;
    $totalPromoTokens   = 0;
    if ($hasWalletCacheTable) {
        $totalsStmt = $pdo->query(
            'SELECT
                COALESCE(SUM(regular_balance), 0) AS regular_sum,
                COALESCE(SUM(promo_balance), 0) AS promo_sum
             FROM wallet_balance_cache'
        );
        $tokenTotals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalRegularTokens = (int)($tokenTotals['regular_sum'] ?? 0);
        $totalPromoTokens   = (int)($tokenTotals['promo_sum'] ?? 0);
    }

    $selectParts = [
        'u.id',
        'u.username',
        'u.email',
        'u.first_name',
        'u.last_name',
        'u.created_at',
        'u.updated_at',
    ];

    $bookJoinSql = '';
    if ($hasBooksTable) {
        $selectParts[] = 'COALESCE(b.book_count, 0) AS book_count';
        $selectParts[] = 'b.last_book_created_at AS last_book_created_at';
        $bookJoinSql = '
            LEFT JOIN (
                SELECT
                    user_id,
                    COUNT(*) AS book_count,
                    MAX(created_at) AS last_book_created_at
                FROM books
                GROUP BY user_id
            ) b ON b.user_id = u.id
        ';
    } else {
        $selectParts[] = '0 AS book_count';
        $selectParts[] = 'NULL AS last_book_created_at';
    }

    $walletJoinSql = '';
    if ($hasWalletCacheTable) {
        $selectParts[] = 'COALESCE(w.regular_balance, 0) AS regular_balance';
        $selectParts[] = 'COALESCE(w.promo_balance, 0) AS promo_balance';
        $walletJoinSql = 'LEFT JOIN wallet_balance_cache w ON w.user_id = u.id';
    } else {
        $selectParts[] = '0 AS regular_balance';
        $selectParts[] = '0 AS promo_balance';
    }

    $sql = sprintf(
        'SELECT %s
         FROM users u
         %s
         %s
         ORDER BY u.created_at DESC
         LIMIT :limit OFFSET :offset',
        implode(",\n                ", $selectParts),
        $bookJoinSql,
        $walletJoinSql
    );

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = array_map(static function (array $row): array {
        $regular = (int)($row['regular_balance'] ?? 0);
        $promo   = (int)($row['promo_balance'] ?? 0);

        $fullName = trim(
            implode(
                ' ',
                array_filter([
                    $row['first_name'] ?? '',
                    $row['last_name'] ?? '',
                ])
            )
        );

        return [
            'id'        => (int)$row['id'],
            'username'  => $row['username'],
            'email'     => $row['email'],
            'name'      => $fullName !== '' ? $fullName : null,
            'createdAt' => iso_time_or_null($row['created_at'] ?? null),
            'updatedAt' => iso_time_or_null($row['updated_at'] ?? null),
            'books'     => [
                'count'         => (int)$row['book_count'],
                'lastCreatedAt' => iso_time_or_null($row['last_book_created_at'] ?? null),
            ],
            'tokens'    => [
                'regular' => $regular,
                'promo'   => $promo,
                'total'   => $regular + $promo,
            ],
        ];
    }, $rows);

    $response = [
        'totals' => [
            'users' => $totalUsers,
            'books' => $totalBooks,
            'tokens' => [
                'regular' => $totalRegularTokens,
                'promo'   => $totalPromoTokens,
                'total'   => $totalRegularTokens + $totalPromoTokens,
            ],
        ],
        'pagination' => [
            'limit'    => $limit,
            'offset'   => $offset,
            'returned' => count($users),
        ],
        'users' => $users,
    ];

    send_success($response);
} catch (Throwable $e) {
    error_log('Error in GET /v1/admin/users/overview: ' . $e->getMessage());
    server_error('Failed to fetch user overview');
}
