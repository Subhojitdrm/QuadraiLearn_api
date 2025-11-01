<?php
declare(strict_types=1);

/**
 * POST /api/books/create_structured.php
 *
 * Creates a new book record with a normalized structure across three tables:
 * `books`, `chapters`, and `subchapters`. This is based on the more detailed
 * schema for storing book content.
 *
 * This endpoint is designed to be atomic, ensuring that either the entire
 * book structure is saved, or nothing is saved if an error occurs.
 *
 * JSON Body (example):
 * {
 *   "config": {
 *     "topic": "The History of Ancient Rome",
 *     "purpose": "Academic",
 *     "language": "English",
 *     "details": { "type": "Academic", "board": "CBSE", "class": "Class 9" },
 *     "preferences": { "includeExercises": true, "structure": "chapter" }
 *   },
 *   "outline": [
 *     {
 *       "chapterIndex": 0,
 *       "title": "The Founding of Rome",
 *       "subchapters": [ "Romulus and Remus", "The Seven Kings" ],
 *       "generatedContent": "...",
 *       "status": "ready"
 *     },
 *     {
 *       "chapterIndex": 1,
 *       "title": "The Roman Republic",
 *       "subchapters": [ "The Punic Wars", "Julius Caesar" ],
 *       "generatedContent": null,
 *       "status": "idle"
 *     }
 *   ]
 * }
 *
 * Response 201 (Created):
 * {
 *   "ok": true,
 *   "message": "Book created successfully.",
 *   "bookId": 123,
 *   "chaptersCreated": 2,
 *   "subchaptersCreated": 4
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// --- Endpoint Logic ---

try {
    // 1. Authenticate the user
    $claims = require_auth();
    $userId = (int)($claims['sub'] ?? 0);
    if ($userId <= 0) {
        json_out(401, ['ok' => false, 'error' => 'invalid_token_subject']);
    }

    // 2. Get and validate input from the JSON body
    $input = body_json();
    $config = $input['config'] ?? [];
    $outline = $input['outline'] ?? [];

    $topic = trim((string)($config['topic'] ?? ''));
    if ($topic === '' || !is_array($outline) || empty($outline)) {
        json_out(422, ['ok' => false, 'error' => 'config.topic and a non-empty outline are required']);
    }

    // 3. Save to database within a transaction
    $pdo = get_pdo();
    $pdo->beginTransaction();

    // Insert into `books` table
    $stmtBook = $pdo->prepare(
        'INSERT INTO books (user_id, topic, purpose, language, details, preferences, created_at, updated_at)
         VALUES (:uid, :topic, :purpose, :lang, :details, :prefs, NOW(), NOW())'
    );
    $stmtBook->execute([
        ':uid' => $userId,
        ':topic' => $topic,
        ':purpose' => trim((string)($config['purpose'] ?? '')) ?: null,
        ':lang' => trim((string)($config['language'] ?? '')) ?: null,
        ':details' => isset($config['details']) ? json_encode($config['details']) : null,
        ':prefs' => isset($config['preferences']) ? json_encode($config['preferences']) : null,
    ]);
    $bookId = (int)$pdo->lastInsertId();

    // Prepare statements for chapters and subchapters
    $stmtChapter = $pdo->prepare(
        'INSERT INTO chapters (book_id, chapter_index, title, generated_content, status, created_at, updated_at)
         VALUES (:bid, :cidx, :title, :content, :status, NOW(), NOW())'
    );
    $stmtSubchapter = $pdo->prepare(
        'INSERT INTO subchapters (chapter_id, subchapter_index, title, created_at)
         VALUES (:cid, :sidx, :title, NOW())'
    );

    $subchaptersCreated = 0;
    foreach ($outline as $chapterData) {
        if (!is_array($chapterData) || empty($chapterData['title'])) continue;

        // Insert into `chapters`
        $stmtChapter->execute([
            ':bid' => $bookId,
            ':cidx' => (int)($chapterData['chapterIndex'] ?? 0),
            ':title' => trim((string)$chapterData['title']),
            ':content' => ($chapterData['generatedContent'] ?? null) ? (string)$chapterData['generatedContent'] : null,
            ':status' => trim((string)($chapterData['status'] ?? 'idle')),
        ]);
        $chapterId = (int)$pdo->lastInsertId();

        // Insert into `subchapters`
        if (isset($chapterData['subchapters']) && is_array($chapterData['subchapters'])) {
            foreach ($chapterData['subchapters'] as $index => $subchapterTitle) {
                $stmtSubchapter->execute([
                    ':cid' => $chapterId,
                    ':sidx' => $index, // Use array index for order
                    ':title' => trim((string)$subchapterTitle),
                ]);
                $subchaptersCreated++;
            }
        }
    }

    $pdo->commit();

    json_out(201, [
        'ok' => true,
        'message' => 'Book created successfully.',
        'bookId' => $bookId,
        'chaptersCreated' => count($outline),
        'subchaptersCreated' => $subchaptersCreated,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $msg = (defined('DEBUG') && DEBUG) ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}