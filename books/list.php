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

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

try {
  $pdo = get_pdo();

  if ($q !== '') {
    $stmt = $pdo->prepare(
      'SELECT b.id, b.title, b.topic, b.status, b.created_at,
              (SELECT COUNT(*) FROM book_chapters c WHERE c.book_id = b.id) AS chapters
       FROM books b
       WHERE b.user_id = :uid AND (b.title LIKE :q OR b.topic LIKE :q)
       ORDER BY b.created_at DESC LIMIT 100'
    );
    $stmt->execute([':uid'=>$uid, ':q'=>'%'.$q.'%']);
  } else {
    $stmt = $pdo->prepare(
      'SELECT b.id, b.title, b.topic, b.status, b.created_at,
              (SELECT COUNT(*) FROM book_chapters c WHERE c.book_id = b.id) AS chapters
       FROM books b
       WHERE b.user_id = :uid
       ORDER BY b.created_at DESC LIMIT 100'
    );
    $stmt->execute([':uid'=>$uid]);
  }

  $rows = $stmt->fetchAll();
  echo json_encode(['ok'=>true, 'books'=>$rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>(defined('DEBUG')&&DEBUG)?$e->getMessage():'server error']);
}
