<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../db.php';

$claims = require_auth();
$uid = (int)($claims['sub'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'invalid id']); exit; }

try {
  $pdo = get_pdo();

  $stmt = $pdo->prepare('SELECT * FROM books WHERE id = :id AND user_id = :uid LIMIT 1');
  $stmt->execute([':id'=>$id, ':uid'=>$uid]);
  $book = $stmt->fetch();
  if (!$book) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

  $chap = $pdo->prepare('SELECT id, chapter_index, title, status FROM book_chapters WHERE book_id = :bid ORDER BY chapter_index ASC');
  $chap->execute([':bid'=>$id]);
  $chapters = $chap->fetchAll();
$row['sections'] = [];
if (!empty($row['sections_json'])) {
  $arr = json_decode($row['sections_json'], true);
  if (is_array($arr)) $row['sections'] = $arr;
}
unset($row['sections_json']);
  echo json_encode(['ok'=>true, 'book'=>$book, 'chapters'=>$chapters], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>(defined('DEBUG')&&DEBUG)?$e->getMessage():'server error']);
}
