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

try {
  $pdo = get_pdo();

  // Base filter (ownership)
  $where = ['t.user_id = :uid'];
  $params = [':uid' => $userId];

  if ($q !== '') {
    $where[] = '(t.topic LIKE :qq OR t.purpose LIKE :qq)';
    $params[':qq'] = '%'.$q.'%';
  }
  $whereSql = implode(' AND ', $where);

  // Count how many tests have submissions
  $cntSql = "
    SELECT COUNT(*) FROM (
      SELECT t.id
      FROM mock_tests t
      WHERE $whereSql
      AND EXISTS (SELECT 1 FROM mock_submissions s WHERE s.test_id = t.id AND s.user_id = :uid)
    ) x
  ";
  $cntSt = $pdo->prepare($cntSql);
  foreach ($params as $k => $v) $cntSt->bindValue($k, $v);
  $cntSt->execute();
  $total = (int)$cntSt->fetchColumn();

  // Fetch page of tests with attempts and latest submission id
  $sql = "
    SELECT
      t.id, t.topic, t.complexity, t.purpose, t.question_count, t.model, t.status, t.created_at,
      (SELECT COUNT(*) FROM mock_submissions ms WHERE ms.test_id = t.id AND ms.user_id = :uid) AS attempts,
      (SELECT ms2.id FROM mock_submissions ms2 WHERE ms2.test_id = t.id AND ms2.user_id = :uid ORDER BY ms2.created_at DESC LIMIT 1) AS last_submission_id
    FROM mock_tests t
    WHERE $whereSql
    AND EXISTS (SELECT 1 FROM mock_submissions s WHERE s.test_id = t.id AND s.user_id = :uid)
    ORDER BY (SELECT ms3.created_at FROM mock_submissions ms3 WHERE ms3.id =
              (SELECT ms2.id FROM mock_submissions ms2 WHERE ms2.test_id = t.id AND ms2.user_id = :uid ORDER BY ms2.created_at DESC LIMIT 1)
             ) DESC
    LIMIT :lim OFFSET :off
  ";
  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->bindValue(':lim', $limit, PDO::PARAM_INT);
  $st->bindValue(':off', $offset, PDO::PARAM_INT);
  $st->execute();

  $rows = $st->fetchAll();
  if (!$rows) out(200, ['ok'=>true, 'total'=>$total, 'limit'=>$limit, 'offset'=>$offset, 'items'=>[]]);

  // Load details of the last submissions in one shot
  $subIds = array_values(array_filter(array_map(fn($r)=> (int)$r['last_submission_id'], $rows)));
  $lastById = [];
  if (!empty($subIds)) {
    $ph = implode(',', array_fill(0, count($subIds), '?'));
    $s = $pdo->prepare("SELECT id, test_id, total_questions, correct_count, duration_sec, created_at FROM mock_submissions WHERE id IN ($ph)");
    foreach ($subIds as $i => $sid) $s->bindValue($i+1, $sid, PDO::PARAM_INT);
    $s->execute();
    while ($r = $s->fetch()) {
      $lastById[(int)$r['id']] = [
        'id' => (int)$r['id'],
        'test_id' => (int)$r['test_id'],
        'total' => (int)$r['total_questions'],
        'correct' => (int)$r['correct_count'],
        'scorePercent' => (int)round(((int)$r['correct_count'] / max(1,(int)$r['total_questions'])) * 100),
        'durationSec' => $r['duration_sec'] !== null ? (int)$r['duration_sec'] : null,
        'created_at' => (string)$r['created_at']
      ];
    }
  }

  $items = [];
  foreach ($rows as $r) {
    $last = $lastById[(int)$r['last_submission_id']] ?? null;
    $items[] = [
      'test' => [
        'id'            => (int)$r['id'],
        'topic'         => (string)$r['topic'],
        'complexity'    => (string)$r['complexity'],
        'purpose'       => $r['purpose'] ?: null,
        'questionCount' => (int)$r['question_count'],
        'model'         => $r['model'] ?: null,
        'status'        => (string)$r['status'],
        'created_at'    => (string)$r['created_at']
      ],
      'attempts' => (int)$r['attempts'],
      'lastSubmission' => $last
    ];
  }

  out(200, ['ok'=>true, 'total'=>$total, 'limit'=>$limit, 'offset'=>$offset, 'items'=>$items]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
