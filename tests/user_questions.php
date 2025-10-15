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

$testId   = (int)($_GET['testId'] ?? 0);
$q        = trim((string)($_GET['q'] ?? ''));
$limit    = max(1, min(500, (int)($_GET['limit'] ?? 200)));
$offset   = max(0, (int)($_GET['offset'] ?? 0));
$reveal   = isset($_GET['revealAnswers']) && (int)$_GET['revealAnswers'] === 1;

try {
  $pdo = get_pdo();

  // Base filter: user ownership
  $where = ['user_id = :uid'];
  $params = [':uid'=>$userId];

  if ($testId > 0) {
    $where[] = 'id = :tid';
    $params[':tid'] = $testId;
  }
  if ($q !== '') {
    $where[] = '(topic LIKE :qq OR purpose LIKE :qq)';
    $params[':qq'] = '%'.$q.'%';
  }
  $whereSql = implode(' AND ', $where);

  // Count tests
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM mock_tests WHERE $whereSql");
  $cnt->execute($params);
  $totalTests = (int)$cnt->fetchColumn();

  // Fetch tests (page)
  $t = $pdo->prepare("
    SELECT id, topic, complexity, purpose, question_count, model, status, created_at
    FROM mock_tests
    WHERE $whereSql
    ORDER BY created_at DESC
    LIMIT :lim OFFSET :off
  ");
  foreach ($params as $k=>$v) $t->bindValue($k, $v);
  $t->bindValue(':lim', $limit, PDO::PARAM_INT);
  $t->bindValue(':off', $offset, PDO::PARAM_INT);
  $t->execute();

  $tests = $t->fetchAll();
  if (!$tests) {
    out(200, ['ok'=>true, 'totalTests'=>$totalTests, 'limit'=>$limit, 'offset'=>$offset, 'items'=>[]]);
  }

  // Gather test IDs and load all questions in one query
  $ids = array_map(fn($r)=>(int)$r['id'], $tests);
  $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));

  $qq = $pdo->prepare("
    SELECT q.id, q.test_id, q.q_index, q.question_text, q.options_json,
           q.correct_index, q.correct_text, q.sub_topic, q.explanation
    FROM mock_questions q
    WHERE q.test_id IN ($inPlaceholders)
    ORDER BY q.test_id ASC, q.q_index ASC
  ");
  foreach ($ids as $i => $id) $qq->bindValue($i+1, $id, PDO::PARAM_INT);
  $qq->execute();

  // Group questions under their test
  $byTest = [];
  while ($row = $qq->fetch()) {
    $opts = json_decode((string)$row['options_json'], true);
    if (!is_array($opts)) $opts = [];
    $item = [
      'id'        => (int)$row['id'],
      'test_id'   => (int)$row['test_id'],
      'index'     => (int)$row['q_index'],
      'question'  => (string)$row['question_text'],
      'options'   => $opts,
      'sub_topic' => (string)$row['sub_topic'],
      'explanation' => $row['explanation'] ?: null
    ];
    if ($reveal) {
      $item['correct_idx']  = (int)$row['correct_index'];
      $item['correct_text'] = (string)$row['correct_text'];
    }
    $byTest[(int)$row['test_id']][] = $item;
  }

  // Build final items list
  $items = [];
  foreach ($tests as $trow) {
    $tid = (int)$trow['id'];
    $items[] = [
      'test'=>[
        'id'            => $tid,
        'topic'         => (string)$trow['topic'],
        'complexity'    => (string)$trow['complexity'],
        'purpose'       => $trow['purpose'] ?: null,
        'questionCount' => (int)$trow['question_count'],
        'model'         => $trow['model'] ?: null,
        'status'        => (string)$trow['status'],
        'created_at'    => (string)$trow['created_at']
      ],
      'questions' => $byTest[$tid] ?? []
    ];
  }

  out(200, [
    'ok'=>true,
    'totalTests'=>$totalTests,
    'limit'=>$limit,
    'offset'=>$offset,
    'items'=>$items
  ]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=> (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
