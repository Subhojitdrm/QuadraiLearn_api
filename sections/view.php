<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../db.php';

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) out(401, ['ok'=>false,'error'=>'unauthorized']);

/**
 * You can fetch by:
 *   /sections/view.php?id=999
 * OR by composite key:
 *   /sections/view.php?bookId=123&chapterIndex=2&sectionIndex=3
 */
$id           = (int)($_GET['id'] ?? 0);
$bookId       = (int)($_GET['bookId'] ?? 0);
$chapterIndex = (int)($_GET['chapterIndex'] ?? 0);
$sectionIndex = (int)($_GET['sectionIndex'] ?? 0);

try {
  $pdo = get_pdo();

  // Resolve row and ensure ownership via the book
  if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM chapter_sections WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$id]);
    $sec = $stmt->fetch();
  } else {
    if ($bookId <= 0 || $chapterIndex <= 0 || $sectionIndex <= 0) {
      out(422, ['ok'=>false, 'error'=>'Provide id or (bookId, chapterIndex, sectionIndex)']);
    }
    $stmt = $pdo->prepare('
      SELECT * FROM chapter_sections
      WHERE book_id=:bid AND chapter_index=:cidx AND section_index=:sidx
      LIMIT 1
    ');
    $stmt->execute([':bid'=>$bookId, ':cidx'=>$chapterIndex, ':sidx'=>$sectionIndex]);
    $sec = $stmt->fetch();
  }

  if (!$sec) out(404, ['ok'=>false, 'error'=>'section not found']);

  // ownership check
  $b = $pdo->prepare('SELECT id FROM books WHERE id=:id AND user_id=:uid LIMIT 1');
  $b->execute([':id'=>$sec['book_id'], ':uid'=>$userId]);
  if (!$b->fetch()) out(404, ['ok'=>false, 'error'=>'not found']);

  out(200, [
    'ok'=>true,
    'section'=>[
      'id'            => (int)$sec['id'],
      'book_id'       => (int)$sec['book_id'],
      'chapter_id'    => $sec['chapter_id'] ? (int)$sec['chapter_id'] : null,
      'chapter_index' => (int)$sec['chapter_index'],
      'section_index' => (int)$sec['section_index'],
      'title'         => (string)$sec['section_title'],
      'content_md'    => (string)($sec['content_md'] ?? ''),
      'status'        => (string)$sec['status'],
      'word_count'    => isset($sec['word_count']) ? (int)$sec['word_count'] : null,
      'last_generated_at' => $sec['last_generated_at'] ?: null,
      'updated_at'    => (string)$sec['updated_at'],
      'created_at'    => (string)$sec['created_at']
    ]
  ]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
