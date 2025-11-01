<?php
declare(strict_types=1);

/**
 * GET /api/chapters/get_structured.php?id=456
 *
 * Retrieves the full details of a single chapter, including its
 * generated content, for the authenticated user.
 *
 * Response 200 (OK):
 * {
 *   "ok": true,
 *   "chapter": {
 *     "id": 456,
 *     "book_id": 123,
 *     "chapter_index": 1,
 *     "title": "The Roman Republic",
 *     "generated_content": "The full markdown content...",
 *     "status": "ready",
 *     "created_at": "...",
 *     "updated_at": "..."
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
    $chapterId = (int)($_GET['id'] ?? 0);
    if ($chapterId <= 0) {
        json_out(422, ['ok' => false, 'error' => 'A valid chapter ID is required.']);
    }

    $pdo = get_pdo();

    // 3. Fetch the chapter record and verify ownership via a JOIN on the books table
    $stmt = $pdo->prepare(
        'SELECT c.* FROM chapters c JOIN books b ON c.book_id = b.id WHERE c.id = :cid AND b.user_id = :uid LIMIT 1'
    );
    $stmt->execute([':cid' => $chapterId, ':uid' => $userId]);
    $chapter = $stmt->fetch();

    if (!$chapter) {
        json_out(404, ['ok' => false, 'error' => 'Chapter not found or you do not have permission to view it.']);
    }

    json_out(200, ['ok' => true, 'chapter' => $chapter]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG) ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}