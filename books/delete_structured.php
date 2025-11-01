<?php
declare(strict_types=1);

/**
 * POST /api/books/delete_structured.php
 *
 * Deletes a book and all its associated chapters and subchapters for the
 * authenticated user. This relies on `ON DELETE CASCADE` in the database schema.
 *
 * JSON Body:
 * {
 *   "bookId": 123
 * }
 *
 * Response 200 (OK):
 * {
 *   "ok": true,
 *   "message": "Book deleted successfully."
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
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

    // 2. Get and validate input
    $input = body_json();
    $bookId = (int)($input['bookId'] ?? 0);
    if ($bookId <= 0) {
        json_out(422, ['ok' => false, 'error' => 'A valid bookId is required.']);
    }

    $pdo = get_pdo();

    // 3. Delete the book, but only if it belongs to the user
    $stmt = $pdo->prepare('DELETE FROM books WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $bookId, ':uid' => $userId]);

    if ($stmt->rowCount() > 0) {
        json_out(200, ['ok' => true, 'message' => 'Book deleted successfully.']);
    } else {
        json_out(404, ['ok' => false, 'error' => 'Book not found or you do not have permission to delete it.']);
    }

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG) ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}