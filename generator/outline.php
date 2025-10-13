<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// If you want to require login for outline:
// require_once __DIR__ . '/../lib/auth.php';
// $claims = require_auth();

function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

$in = body_json();
$topic = trim((string)($in['topic'] ?? ''));
$style = trim((string)($in['style'] ?? ''));
$level = trim((string)($in['level'] ?? ''));
$lang  = trim((string)($in['language'] ?? ''));
$micro = !empty($in['microMode']);

if ($topic === '') {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'topic is required']);
  exit;
}

// simple deterministic pseudo-outline (replace with AI later)
function make_outline(string $topic, bool $micro): array {
  $base = [
    ['title'=>"Introduction to $topic", 'sections'=>['Why it matters','Core concepts','Real-world use cases']],
    ['title'=>"$topic: Fundamentals",  'sections'=>['Key terms','Essential principles','Common pitfalls']],
    ['title'=>"$topic: Intermediate",  'sections'=>['Patterns & techniques','Hands-on example','Best practices']],
    ['title'=>"$topic: Advanced",      'sections'=>['Edge cases','Performance & scaling','Trade-offs']],
    ['title'=>"$topic: Practical Lab", 'sections'=>['Step-by-step project','Checklist','Troubleshooting']],
    ['title'=>"$topic: Mock Exam",     'sections'=>['MCQs','Scenario questions','Answer keys']]
  ];
  if ($micro) {
    // compress to micro mode (shorter blueprint)
    $base = array_slice($base, 0, 3);
    foreach ($base as &$c) $c['sections'] = array_slice($c['sections'], 0, 2);
  }
  foreach ($base as $i => &$c) { $c = ['index'=>$i+1, 'title'=>$c['title'], 'sections'=>$c['sections']]; }
  return $base;
}

$outline = make_outline($topic, $micro);

echo json_encode([
  'ok'=>true,
  'meta'=>[
    'topic'=>$topic, 'style'=>$style, 'level'=>$level, 'language'=>$lang, 'microMode'=>$micro
  ],
  'outline'=>$outline
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
