<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

/**
 * Expected body:
 * {
 *   "bookId": 123,                     // required
 *   "chapterIndex": 2,                 // required (1-based)
 *   "sectionIndex": 3,                 // required (1-based)
 *   "sectionTitle": "Concept B",       // required
 *   "content": "# Markdown..."         // required (markdown)
 * }
 */
$in = body_json();

$bookId       = (int)($in['bookId'] ?? 0);
$chapterIndex = (int)($in['chapterIndex'] ?? 0);
$sectionIndex = (int)($in['sectionIndex'] ?? 0);
$sectionTitle = trim((string)($in['sectionTitle'] ?? ''));
$content      = (string)($in['content'] ?? '');

$errors = [];
if ($bookId <= 0)        $errors['bookId'] = 'bookId is required';
if ($chapterIndex <= 0)  $errors['chapterIndex'] = 'chapterIndex must be >= 1';
if ($sectionIndex <= 0)  $errors['sectionIndex'] = 'sectionIndex must be >= 1';
if ($sectionTitle === '')$errors['sectionTitle'] = 'sectionTitle is required';
if ($content === '')     $errors['content'] = 'content is required';
if (!empty($errors)) out(422, ['ok'=>false, 'errors'=>$errors]);

try {
  $pdo = get_pdo();
  $pdo->beginTransaction();

  // Ensure the book belongs to this user
  $b = $pdo->prepare('SELECT id FROM books WHERE id=:id AND user_id=:uid LIMIT 1');
  $b->execute([':id'=>$bookId, ':uid'=>$userId]);
  if (!$b->fetch()) {
    $pdo->rollBack();
    out(404, ['ok'=>false, 'error'=>'book not found']);
  }

  // Try to find chapter_id for convenience (optional)
  $chId = null;
  $c = $pdo->prepare('SELECT id FROM book_chapters WHERE book_id=:bid AND chapter_index=:cidx LIMIT 1');
  $c->execute([':bid'=>$bookId, ':cidx'=>$chapterIndex]);
  if ($row = $c->fetch()) $chId = (int)$row['id'];

  $wc = str_word_count(strip_tags($content));

  // Upsert the section
  // Try update first
  $upd = $pdo->prepare('
    UPDATE chapter_sections
    SET section_title = :title,
        content_md = :content,
        status = "ready",
        word_count = :wc,
        last_generated_at = NOW(),
        updated_at = NOW()
    WHERE book_id = :bid AND chapter_index = :cidx AND section_index = :sidx
  ');
  $upd->execute([
    ':title'=>$sectionTitle, ':content'=>$content, ':wc'=>$wc,
    ':bid'=>$bookId, ':cidx'=>$chapterIndex, ':sidx'=>$sectionIndex
  ]);

  if ($upd->rowCount() === 0) {
    // Insert
    $ins = $pdo->prepare('
      INSERT INTO chapter_sections
        (book_id, chapter_id, chapter_index, section_index, section_title, content_md, status, word_count, last_generated_at, created_at, updated_at)
      VALUES
        (:bid, :chid, :cidx, :sidx, :title, :content, "ready", :wc, NOW(), NOW(), NOW())
    ');
    $ins->execute([
      ':bid'=>$bookId, ':chid'=>$chId, ':cidx'=>$chapterIndex, ':sidx'=>$sectionIndex,
      ':title'=>$sectionTitle, ':content'=>$content, ':wc'=>$wc
    ]);
    $sectionId = (int)$pdo->lastInsertId();
  } else {
    // Fetch id for response
    $get = $pdo->prepare('SELECT id FROM chapter_sections WHERE book_id=:bid AND chapter_index=:cidx AND section_index=:sidx LIMIT 1');
    $get->execute([':bid'=>$bookId, ':cidx'=>$chapterIndex, ':sidx'=>$sectionIndex]);
    $sectionId = (int)($get->fetchColumn() ?: 0);
  }

  $pdo->commit();

  out(200, [
    'ok'=>true,
    'section'=>[
      'id'            => $sectionId,
      'book_id'       => $bookId,
      'chapter_index' => $chapterIndex,
      'section_index' => $sectionIndex,
      'title'         => $sectionTitle,
      'word_count'    => $wc
    ]
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
