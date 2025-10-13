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

$bookId  = (int)($_GET['id'] ?? 0);
$include = trim((string)($_GET['include'] ?? '')); // e.g. "chapters"

if ($bookId <= 0) out(422, ['ok'=>false, 'error'=>'id is required']);

try {
  $pdo = get_pdo();

  $stmt = $pdo->prepare('
    SELECT id, user_id, title, topic, style, level, language, micro_mode,
           outline_json, status, lifecycle, draft_expires_at, published_at,
           created_at, updated_at
    FROM books
    WHERE id=:id AND user_id=:uid
    LIMIT 1
  ');
  $stmt->execute([':id'=>$bookId, ':uid'=>$userId]);
  $book = $stmt->fetch();

  if (!$book) out(404, ['ok'=>false, 'error'=>'book not found']);

  // Base payload
  $payload = [
    'ok'   => true,
    'book' => [
      'id'              => (int)$book['id'],
      'title'           => (string)$book['title'],
      'topic'           => (string)$book['topic'],
      'style'           => $book['style'] ?: null,
      'level'           => $book['level'] ?: null,
      'language'        => $book['language'] ?: null,
      'microMode'       => ((int)$book['micro_mode']) === 1,
      'status'          => (string)$book['status'],
      'lifecycle'       => (string)$book['lifecycle'],
      'draft_expires_at'=> $book['draft_expires_at'] ?: null,
      'published_at'    => $book['published_at'] ?: null,
      'created_at'      => (string)$book['created_at'],
      'updated_at'      => (string)$book['updated_at'],
      // If you saved outline_json in books, expose it decoded:
      'outline'         => []
    ]
  ];

  if (!empty($book['outline_json'])) {
    $decoded = json_decode((string)$book['outline_json'], true);
    if (is_array($decoded)) $payload['book']['outline'] = $decoded;
  }

  // Optionally include chapters with sections
  if (strcasecmp($include, 'chapters') === 0) {
    $c = $pdo->prepare('
      SELECT id, chapter_index, title, sections_json, status, updated_at
      FROM book_chapters
      WHERE book_id = :bid
      ORDER BY chapter_index ASC
    ');
    $c->execute([':bid'=>$bookId]);

    $chapters = [];
    while ($row = $c->fetch()) {
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
        'updated_at'    => (string)$row['updated_at']
      ];
    }

    $payload['chapters'] = $chapters;
  }

  out(200, $payload);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=> (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
