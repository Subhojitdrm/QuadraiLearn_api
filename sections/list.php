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

$bookId       = (int)($_GET['bookId'] ?? 0);
$chapterIndex = isset($_GET['chapterIndex']) ? (int)$_GET['chapterIndex'] : 0;
if ($bookId <= 0) out(422, ['ok'=>false, 'error'=>'bookId is required']);

try {
  $pdo = get_pdo();

  // ownership
  $b = $pdo->prepare('SELECT id FROM books WHERE id=:id AND user_id=:uid LIMIT 1');
  $b->execute([':id'=>$bookId, ':uid'=>$userId]);
  if (!$b->fetch()) out(404, ['ok'=>false, 'error'=>'book not found']);

  if ($chapterIndex > 0) {
    $stmt = $pdo->prepare('
      SELECT id, chapter_index, section_index, section_title, word_count, status, updated_at
      FROM chapter_sections
      WHERE book_id=:bid AND chapter_index=:cidx
      ORDER BY section_index ASC
    ');
    $stmt->execute([':bid'=>$bookId, ':cidx'=>$chapterIndex]);
  } else {
    $stmt = $pdo->prepare('
      SELECT id, chapter_index, section_index, section_title, word_count, status, updated_at
      FROM chapter_sections
      WHERE book_id=:bid
      ORDER BY chapter_index ASC, section_index ASC
    ');
    $stmt->execute([':bid'=>$bookId]);
  }

  $items = [];
  while ($row = $stmt->fetch()) {
    $items[] = [
      'id'            => (int)$row['id'],
      'chapter_index' => (int)$row['chapter_index'],
      'section_index' => (int)$row['section_index'],
      'title'         => (string)$row['section_title'],
      'word_count'    => isset($row['word_count']) ? (int)$row['word_count'] : null,
      'status'        => (string)$row['status'],
      'updated_at'    => (string)$row['updated_at'],
    ];
  }

  out(200, ['ok'=>true, 'bookId'=>$bookId, 'sections'=>$items]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
