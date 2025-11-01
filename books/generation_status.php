<?php
declare(strict_types=1);

/**
 * GET /api/books/generation_status.php?bookId=123
 *
 * Retrieves the content generation status for all chapters of a specific book.
 * Provides both a summary count and a detailed list of chapter statuses.
 *
 * Response 200 (OK):
 * {
 *   "ok": true,
 *   "bookId": 123,
 *   "summary": {
 *     "ready": 1,
 *     "idle": 1,
 *     "total": 2
 *   },
 *   "chapters": [
 *     { "id": 23, "title": "Introduction to Java Programming", "status": "ready" },
 *     { "id": 24, "title": "Variables and Data Types", "status": "idle" }
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

    // 2. Get and validate input
    $bookId = (int)($_GET['bookId'] ?? 0);
    if ($bookId <= 0) {
        json_out(422, ['ok' => false, 'error' => 'A valid bookId is required.']);
    }

    $pdo = get_pdo();

    // 3. Verify book ownership
    $stmtBook = $pdo->prepare('SELECT id FROM books WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmtBook->execute([':id' => $bookId, ':uid' => $userId]);
    if (!$stmtBook->fetch()) {
        json_out(404, ['ok' => false, 'error' => 'Book not found or you do not have permission to view it.']);
    }

    // 4. Fetch chapter statuses and summary in one efficient query
    $stmt = $pdo->prepare(
        'SELECT id, title, status FROM chapters WHERE book_id = :bid ORDER BY chapter_index ASC'
    );
    $stmt->execute([':bid' => $bookId]);
    $chapters = $stmt->fetchAll();

    // 5. Calculate the summary
    $summary = ['ready' => 0, 'idle' => 0, 'total' => 0];
    foreach ($chapters as $chapter) {
        if ($chapter['status'] === 'ready') {
            $summary['ready']++;
        } else {
            $summary['idle']++; // Treat any non-ready status as idle for summary purposes
        }
        $summary['total']++;
    }

    // 6. Return the combined response
    json_out(200, ['ok' => true, 'bookId' => $bookId, 'summary' => $summary, 'chapters' => $chapters]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG) ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}