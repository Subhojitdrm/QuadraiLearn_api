<?php
declare(strict_types=1);

/**
 * GET /api/books/view_structured.php?id=123
 *
 * Retrieves a single book and its full nested structure of chapters and
 * subchapters for the authenticated user.
 *
 * Response 200 (OK):
 * {
 *   "ok": true,
 *   "book": {
 *     "id": 123,
 *     "userId": "user_id_abc",
 *     "createdAt": "...",
 *     "updatedAt": "...",
 *     "config": { ... },
 *     "outline": [
 *       {
 *         "id": 1,
 *         "chapterIndex": 0,
 *         "title": "The Founding of Rome",
 *         "generatedContent": "...",
 *         "status": "ready",
 *         "subchapters": [
 *           { "id": 1, "subchapterIndex": 0, "title": "Romulus and Remus" },
 *           { "id": 2, "subchapterIndex": 1, "title": "The Seven Kings" }
 *         ]
 *       },
 *       ...
 *     ]
 *   }
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
    $bookId = (int)($_GET['id'] ?? 0);
    if ($bookId <= 0) {
        json_out(422, ['ok' => false, 'error' => 'A valid book ID is required.']);
    }

    $pdo = get_pdo();

    // 3. Fetch the main book record and verify ownership
    $stmtBook = $pdo->prepare('SELECT * FROM books WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmtBook->execute([':id' => $bookId, ':uid' => $userId]);
    $book = $stmtBook->fetch();

    if (!$book) {
        json_out(404, ['ok' => false, 'error' => 'Book not found or you do not have permission to view it.']);
    }

    // 4. Fetch all chapters for the book
    $stmtChapters = $pdo->prepare('SELECT * FROM chapters WHERE book_id = :bid ORDER BY chapter_index ASC');
    $stmtChapters->execute([':bid' => $bookId]);
    $chapters = $stmtChapters->fetchAll(PDO::FETCH_UNIQUE); // Key by chapter ID

    // 5. Fetch all subchapters for the book in one query
    $stmtSubchapters = $pdo->prepare(
        'SELECT s.* FROM subchapters s JOIN chapters c ON s.chapter_id = c.id
         WHERE c.book_id = :bid ORDER BY s.chapter_id, s.subchapter_index ASC'
    );
    $stmtSubchapters->execute([':bid' => $bookId]);

    // 6. Assemble the nested structure
    foreach ($chapters as &$chapter) { // Initialize subchapters array
        $chapter['subchapters'] = [];
    }
    unset($chapter); // Unset reference

    while ($subchapter = $stmtSubchapters->fetch()) {
        $chapterId = (int)$subchapter['chapter_id'];
        if (isset($chapters[$chapterId])) {
            $chapters[$chapterId]['subchapters'][] = $subchapter;
        }
    }

    // 7. Combine and return the final object
    $book['outline'] = array_values($chapters); // Convert from associative array to list
    json_out(200, ['ok' => true, 'book' => $book]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG) ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}