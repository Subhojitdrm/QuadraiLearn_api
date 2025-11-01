<?php
declare(strict_types=1);

/**
 * GET /api/books/list_structured.php
 *
 * Retrieves a paginated list of books for the authenticated user from the
 * new structured tables. Supports filtering and searching.
 *
 * Query Params:
 *   ?q=<search_term>  - Searches in the book's topic.
 *   &purpose=<purpose> - Filters by the 'purpose' field.
 *   &limit=20         - Number of items per page (default 50, max 100).
 *   &offset=0         - Starting offset for pagination.
 *
 * Response 200 (OK):
 * {
 *   "ok": true,
 *   "total": 15,
 *   "limit": 20,
 *   "offset": 0,
 *   "books": [
 *     {
 *       "id": 123,
 *       "user_id": "user_id_abc",
 *       "topic": "The History of Ancient Rome",
 *       "purpose": "Academic",
 *       "language": "English",
 *       "details": { ... },
 *       "preferences": { ... },
 *       "chapterCount": 2,
 *       "createdAt": "...",
 *       "updatedAt": "..."
 *     },
 *     ...
 *   ]
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/auth.php'; // Provides require_auth()

function json_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Endpoint Logic ---

try {
    // 1. Authenticate the user
    $claims = require_auth();
    $userId = (int)($claims['sub'] ?? 0);
    if ($userId <= 0) {
        json_out(401, ['ok' => false, 'error' => 'invalid_token_subject']);
    }

    // 2. Parse query parameters for filtering and pagination
    $q = trim((string)($_GET['q'] ?? ''));
    $purpose = trim((string)($_GET['purpose'] ?? ''));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    // 3. Build the WHERE clause and parameters dynamically
    $where = ['b.user_id = :uid'];
    $params = [':uid' => $userId];

    if ($q !== '') {
        $where[] = 'b.topic LIKE :q';
        $params[':q'] = '%' . $q . '%';
    }
    if ($purpose !== '') {
        $where[] = 'b.purpose = :purpose';
        $params[':purpose'] = $purpose;
    }

    $whereSql = implode(' AND ', $where);
    $pdo = get_pdo();

    // 4. Get total count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM books b WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // 5. Fetch the paginated list of books with chapter counts
    $sql = "
        SELECT b.*, (SELECT COUNT(*) FROM chapters c WHERE c.book_id = b.id) AS chapterCount
        FROM books b
        WHERE $whereSql
        ORDER BY b.updated_at DESC
        LIMIT :lim OFFSET :off
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $books = $stmt->fetchAll();

    // 6. Return the response
    json_out(200, ['ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'books' => $books]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG) ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}