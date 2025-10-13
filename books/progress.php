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
$bookId = (int)($_GET['id'] ?? 0);
if ($bookId<=0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'invalid id']); exit; }

try {
  $pdo = get_pdo();
  $b = $pdo->prepare('SELECT id FROM books WHERE id=:id AND user_id=:uid LIMIT 1');
  $b->execute([':id'=>$bookId, ':uid'=>$uid]);
  if (!$b->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

  $row = $pdo->query("
    SELECT
      SUM(status='pending') AS pending,
      SUM(status='ready')   AS ready,
      COUNT(*)              AS total
    FROM book_chapters
    WHERE book_id = ".(int)$bookId
  )->fetch();

  echo json_encode(['ok'=>true, 'progress'=>$row], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>(defined('DEBUG')&&DEBUG)?$e->getMessage():'server error']);
}
