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
if ($userId <= 0) out(401, ['ok'=>false, 'error'=>'unauthorized']);

$q      = trim((string)($_GET['q'] ?? ''));
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$where = ['user_id = :uid'];
$params = [':uid'=>$userId];

if ($q !== '') {
  $where[] = '(topic LIKE :qq OR purpose LIKE :qq)';
  $params[':qq'] = '%'.$q.'%';
}
$whereSql = implode(' AND ', $where);

try {
  $pdo = get_pdo();

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM mock_tests WHERE $whereSql");
  $cnt->execute($params);
  $total = (int)$cnt->fetchColumn();

  $sql = "SELECT id, topic, complexity, purpose, question_count, model, status, created_at
          FROM mock_tests
          WHERE $whereSql
          ORDER BY created_at DESC
          LIMIT :lim OFFSET :off";
  $st = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $st->bindValue($k, $v);
  $st->bindValue(':lim', $limit,  PDO::PARAM_INT);
  $st->bindValue(':off', $offset, PDO::PARAM_INT);
  $st->execute();

  $items = [];
  while ($r = $st->fetch()) {
    $items[] = [
      'id'            => (int)$r['id'],
      'topic'         => (string)$r['topic'],
      'complexity'    => (string)$r['complexity'],
      'purpose'       => $r['purpose'] ?: null,
      'questionCount' => (int)$r['question_count'],
      'model'         => $r['model'] ?: null,
      'status'        => (string)$r['status'],
      'created_at'    => (string)$r['created_at']
    ];
  }

  out(200, ['ok'=>true, 'total'=>$total, 'limit'=>$limit, 'offset'=>$offset, 'tests'=>$items]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
