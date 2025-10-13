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
  echo json_encode(['ok'=>false, 'error'=>'unauthorized']);
  exit;
}

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Optional query params:
 *   ?q=<search in title/topic>
 *   &lifecycle=draft|published|archived|trash
 *   &status=draft|generating|ready   (if you use a separate status)
 *   &limit=20  (default 50)
 *   &offset=0
 */
$q         = trim((string)($_GET['q'] ?? ''));
$lifecycle = trim((string)($_GET['lifecycle'] ?? ''));
$status    = trim((string)($_GET['status'] ?? ''));
$limit     = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$offset    = max(0, (int)($_GET['offset'] ?? 0));

$where  = ['user_id = :uid'];
$params = [':uid' => $userId];

if ($q !== '') {
  $where[] = '(title LIKE :q OR topic LIKE :q)';
  $params[':q'] = '%'.$q.'%';
}
if ($lifecycle !== '') {
  $where[] = 'lifecycle = :lc';
  $params[':lc'] = $lifecycle;
}
if ($status !== '') {
  $where[] = 'status = :st';
  $params[':st'] = $status;
}

$whereSql = implode(' AND ', $where);

try {
  $pdo = get_pdo();

  // total count for pagination
  $count = $pdo->prepare("SELECT COUNT(*) FROM books WHERE $whereSql");
  $count->execute($params);
  $total = (int)$count->fetchColumn();

  // main query
  $sql = "
    SELECT id, title, topic, style, level, language, micro_mode,
           status, lifecycle, draft_expires_at, published_at,
           created_at, updated_at,
           outline_json
    FROM books
    WHERE $whereSql
    ORDER BY updated_at DESC
    LIMIT :lim OFFSET :off
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
  $stmt->execute();

  $items = [];
  while ($row = $stmt->fetch()) {
    $outline = [];
    if (!empty($row['outline_json'])) {
      $tmp = json_decode((string)$row['outline_json'], true);
      if (is_array($tmp)) $outline = $tmp;
    }

    $items[] = [
      'id'              => (int)$row['id'],
      'title'           => (string)$row['title'],
      'topic'           => (string)$row['topic'],
      'style'           => $row['style'] ?: null,
      'level'           => $row['level'] ?: null,
      'language'        => $row['language'] ?: null,
      'microMode'       => ((int)$row['micro_mode']) === 1,
      'status'          => (string)$row['status'],
      'lifecycle'       => (string)$row['lifecycle'],
      'draft_expires_at'=> $row['draft_expires_at'] ?: null,
      'published_at'    => $row['published_at'] ?: null,
      'created_at'      => (string)$row['created_at'],
      'updated_at'      => (string)$row['updated_at'],
      // quick counts your UI might like
      'outlineChapterCount' => is_array($outline) ? count($outline) : 0
    ];
  }

  out(200, [
    'ok' => true,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
    'books' => $items
  ]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=> (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
