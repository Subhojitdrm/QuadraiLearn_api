<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php'; // require_auth()
require_once __DIR__ . '/../db.php';       // get_pdo()

$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']);
  exit;
}

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

$bookId = (int)($_GET['bookId'] ?? 0);
if ($bookId <= 0) out(422, ['ok'=>false, 'error'=>'bookId is required']);

try {
  $pdo = get_pdo();

  // Ensure ownership
  $b = $pdo->prepare('SELECT id FROM books WHERE id=:id AND user_id=:uid LIMIT 1');
  $b->execute([':id'=>$bookId, ':uid'=>$userId]);
  if (!$b->fetch()) out(404, ['ok'=>false, 'error'=>'book not found']);

  // Fetch chapters
  $stmt = $pdo->prepare('
    SELECT id, chapter_index, title, sections_json, status, updated_at
    FROM book_chapters
    WHERE book_id = :bid
    ORDER BY chapter_index ASC
  ');
  $stmt->execute([':bid'=>$bookId]);

  $chapters = [];
  while ($row = $stmt->fetch()) {
    $sections = [];
    if (!empty($row['sections_json'])) {
      $tmp = json_decode((string)$row['sections_json'], true);
      if (is_array($tmp)) $sections = array_values(array_filter(array_map('trim', $tmp), fn($s)=>$s!==''));
    }
    $chapters[] = [
      'id'            => (int)$row['id'],
      'chapter_index' => (int)$row['chapter_index'],
      'title'         => (string)$row['title'],
      'sections'      => $sections,
      'status'        => (string)$row['status'],
      'updated_at'    => (string)$row['updated_at'],
    ];
  }

  out(200, ['ok'=>true, 'bookId'=>$bookId, 'chapters'=>$chapters]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=> (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
