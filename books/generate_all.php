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
$bookId = (int)($in['bookId'] ?? 0);
if ($bookId <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'bookId is required']); exit; }

try {
  $pdo = get_pdo();

  // Own book?
  $b = $pdo->prepare('SELECT id, title, topic, style, level, language FROM books WHERE id=:id AND user_id=:uid LIMIT 1');
  $b->execute([':id'=>$bookId, ':uid'=>$uid]);
  $book = $b->fetch();
  if (!$book) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'book not found']); exit; }

  // Load chapters in order
  $c = $pdo->prepare('SELECT id, chapter_index, title, status FROM book_chapters WHERE book_id=:bid ORDER BY chapter_index ASC');
  $c->execute([':bid'=>$bookId]);
  $chapters = $c->fetchAll();

  $generated = 0; $skipped = 0;
  foreach ($chapters as $ch) {
    if ($ch['status'] === 'ready') { $skipped++; continue; }
    $content = generate_chapter_content(
      (string)$book['topic'], (string)$ch['title'],
      (string)($book['level'] ?? ''), (string)($book['style'] ?? ''),
      (string)($book['language'] ?? ''), (int)$ch['chapter_index']
    );
    $u = $pdo->prepare('UPDATE book_chapters SET content=:ct, status="ready", updated_at=NOW() WHERE id=:id');
    $u->execute([':ct'=>$content, ':id'=>$ch['id']]);
    $generated++;
  }

  echo json_encode([
    'ok'=>true,
    'bookId'=>$bookId,
    'generated'=>$generated,
    'skipped'=>$skipped,
    'total'=>count($chapters)
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>(defined('DEBUG')&&DEBUG)?$e->getMessage():'server error']);
}

// same helper as single-chapter
function generate_chapter_content(string $topic, string $chapterTitle, string $level, string $style, string $language, int $idx): string {
  $lvl = $level !== '' ? "Level: $level. " : "";
  $sty = $style !== '' ? "Style: $style. " : "";
  $lang= $language !== '' ? "Language: $language. " : "";
  $sections = [
    "Overview"        => "This section introduces $chapterTitle within $topic...",
    "Key Concepts"    => "Here we break down the essential ideas for $chapterTitle...",
    "Practical Guide" => "Step-by-step, how to apply $chapterTitle in real scenarios...",
    "Examples"        => "Concrete examples and mini-cases to cement understanding...",
    "Checklist"       => "A crisp checklist to review before moving on..."
  ];
  $buf = "# Chapter $idx: $chapterTitle\n\n{$lvl}{$sty}{$lang}Topic: $topic\n\n";
  foreach ($sections as $h => $p) {
    $buf .= "## $h\n$p\n\n";
    for ($i=0; $i<3; $i++) $buf .= para("$topic | $chapterTitle | $h")."\n\n";
  }
  return $buf;
}
function para(string $seed): string {
  return "In practice, ".$seed." demands clarity in definitions, incremental examples, and reflection-driven exercises. Keep notes, iterate, and test your understanding with varied prompts.";
}
