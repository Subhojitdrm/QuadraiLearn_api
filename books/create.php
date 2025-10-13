<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../db.php';

$claims = require_auth();
$uid = (int)($claims['sub'] ?? 0);

function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

$in = body_json();
$title = trim((string)($in['title'] ?? ''));
$topic = trim((string)($in['topic'] ?? ''));
$style = trim((string)($in['style'] ?? ''));
$level = trim((string)($in['level'] ?? ''));
$lang  = trim((string)($in['language'] ?? ''));
$micro = !empty($in['microMode']);
$outline = $in['outline'] ?? [];

$errors = [];
if ($title === '') $errors['title'] = 'title is required';
if ($topic === '') $errors['topic'] = 'topic is required';
if (!is_array($outline) || empty($outline)) $errors['outline'] = 'outline is required';

if (!empty($errors)) { http_response_code(422); echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

try {
  $pdo = get_pdo();
  $pdo->beginTransaction();

  $outlineJson = json_encode($outline, JSON_UNESCAPED_UNICODE);

  $stmt = $pdo->prepare('INSERT INTO books (user_id, title, topic, style, level, language, micro_mode, outline_json)
                         VALUES (:uid, :title, :topic, :style, :level, :lang, :micro, :outline)');
  $stmt->execute([
    ':uid'=>$uid, ':title'=>$title, ':topic'=>$topic, ':style'=>$style ?: null, ':level'=>$level ?: null,
    ':lang'=>$lang ?: null, ':micro'=>$micro ? 1 : 0, ':outline'=>$outlineJson
  ]);
  $bookId = (int)$pdo->lastInsertId();

  // Insert chapters
  $chapStmt = $pdo->prepare('INSERT INTO book_chapters (book_id, chapter_index, title, status)
                             VALUES (:bid, :idx, :title, :status)');
  foreach ($outline as $c) {
    $idx = (int)($c['index'] ?? 0);
    $ctitle = trim((string)($c['title'] ?? 'Untitled Chapter'));
    if ($idx <= 0) continue;
    $chapStmt->execute([
      ':bid'=>$bookId, ':idx'=>$idx, ':title'=>$ctitle, ':status'=>'pending'
    ]);
  }

  $pdo->commit();

  echo json_encode([
    'ok'=>true,
    'book'=>[
      'id'=>$bookId, 'title'=>$title, 'topic'=>$topic, 'microMode'=>$micro,
      'chapters'=>count($outline)
    ]
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>(defined('DEBUG')&&DEBUG)?$e->getMessage():'server error']);
}
