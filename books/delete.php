<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php'; // require_auth()
require_once __DIR__ . '/../db.php';       // get_pdo()

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

// ---- auth ----
$claims = require_auth(); // 401 if missing/invalid
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) out(401, ['ok'=>false, 'error'=>'unauthorized']);

// ---- input ----
// Accept either: DELETE /books/delete.php?id=123
// or POST   /books/delete.php  { "bookId": 123 }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$bookId = 0;

if ($method === 'DELETE') {
  $bookId = (int)($_GET['id'] ?? 0);
} else {
  $in = body_json();
  $bookId = (int)($in['bookId'] ?? ($_GET['id'] ?? 0));
}

if ($bookId <= 0) out(422, ['ok'=>false, 'error'=>'bookId (or id) is required']);

try {
  $pdo = get_pdo();
  $pdo->beginTransaction();

  // Ensure ownership
  $chk = $pdo->prepare('SELECT id FROM books WHERE id=:id AND user_id=:uid LIMIT 1');
  $chk->execute([':id'=>$bookId, ':uid'=>$userId]);
  if (!$chk->fetch()) {
    $pdo->rollBack();
    out(404, ['ok'=>false, 'error'=>'book not found']);
  }

  // Gather chapter ids for cascading deletes in related tables
  $chapIds = [];
  $cstmt = $pdo->prepare('SELECT id FROM book_chapters WHERE book_id = :bid');
  $cstmt->execute([':bid'=>$bookId]);
  while ($r = $cstmt->fetch()) $chapIds[] = (int)$r['id'];

  $deletedVersions = 0;
  // OPTIONAL: if you created chapter_versions table, delete those rows first
  if (!empty($chapIds)) {
    // Chunk IN() to avoid huge parameter lists (safe for most cases here)
    $chunks = array_chunk($chapIds, 500);
    foreach ($chunks as $chunk) {
      $ph = implode(',', array_fill(0, count($chunk), '?'));
      $sql = "DELETE FROM chapter_versions WHERE chapter_id IN ($ph)";
      $delv = $pdo->prepare($sql);
      $delv->execute($chunk);
      $deletedVersions += $delv->rowCount();
    }
  }

  // Delete chapters
  $delCh = $pdo->prepare('DELETE FROM book_chapters WHERE book_id = :bid');
  $delCh->execute([':bid'=>$bookId]);
  $deletedChapters = $delCh->rowCount();

  // OPTIONAL: if you used a gen_cache table tied to book/user, clear it
  // $delCache = $pdo->prepare('DELETE FROM gen_cache WHERE user_id=:uid AND book_temp_id = :btid');
  // $delCache->execute([':uid'=>$userId, ':btid'=>$someTempId]);

  // Delete the book
  $delBk = $pdo->prepare('DELETE FROM books WHERE id = :id AND user_id = :uid LIMIT 1');
  $delBk->execute([':id'=>$bookId, ':uid'=>$userId]);
  $deletedBooks = $delBk->rowCount();

  $pdo->commit();

  out(200, [
    'ok' => true,
    'deleted' => [
      'books'     => (int)$deletedBooks,
      'chapters'  => (int)$deletedChapters,
      'versions'  => (int)$deletedVersions  // 0 if you don’t have that table
    ]
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  out(500, ['ok'=>false, 'error'=> (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
