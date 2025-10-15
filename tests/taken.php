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

  // ---------- COUNT tests user has taken ----------
  $cntSql = "
    SELECT COUNT(*) FROM (
      SELECT t.id
      FROM mock_tests t
      WHERE t.user_id = :uid_cnt
        " . ($q !== '' ? "AND (t.topic LIKE :qq_cnt OR t.purpose LIKE :qq_cnt)" : "") . "
        AND EXISTS (
          SELECT 1 FROM mock_submissions s
          WHERE s.test_id = t.id AND s.user_id = :uid_cnt2
        )
    ) AS x
  ";
  $cnt = $pdo->prepare($cntSql);
  $cnt->bindValue(':uid_cnt',  $userId, PDO::PARAM_INT);
  $cnt->bindValue(':uid_cnt2', $userId, PDO::PARAM_INT);
  if ($q !== '') $cnt->bindValue(':qq_cnt', '%'.$q.'%');
  $cnt->execute();
  $total = (int)$cnt->fetchColumn();

  // ---------- PAGE of tests with attempts + last submission ----------
  // NOTE: to avoid HY093 on some setups, we inline LIMIT/OFFSET after clamping (safe integers)
  $lim = (int)$limit;
  $off = (int)$offset;

  $sql = "
    SELECT
      t.id, t.topic, t.complexity, t.purpose, t.question_count, t.model, t.status, t.created_at,
      (SELECT COUNT(*) FROM mock_submissions ms WHERE ms.test_id = t.id AND ms.user_id = :uid_list1) AS attempts,
      (SELECT ms2.id
         FROM mock_submissions ms2
        WHERE ms2.test_id = t.id AND ms2.user_id = :uid_list2
        ORDER BY ms2.created_at DESC
        LIMIT 1
      ) AS last_submission_id
    FROM mock_tests t
    WHERE t.user_id = :uid_list0
      " . ($q !== '' ? "AND (t.topic LIKE :qq_list OR t.purpose LIKE :qq_list)" : "") . "
      AND EXISTS (
        SELECT 1 FROM mock_submissions s WHERE s.test_id = t.id AND s.user_id = :uid_list3
      )
    ORDER BY (
      SELECT MAX(ms3.created_at)
      FROM mock_submissions ms3
      WHERE ms3.test_id = t.id AND ms3.user_id = :uid_list4
    ) DESC
    LIMIT $lim OFFSET $off
  ";

  $st = $pdo->prepare($sql);
  $st->bindValue(':uid_list0', $userId, PDO::PARAM_INT);
  $st->bindValue(':uid_list1', $userId, PDO::PARAM_INT);
  $st->bindValue(':uid_list2', $userId, PDO::PARAM_INT);
  $st->bindValue(':uid_list3', $userId, PDO::PARAM_INT);
  $st->bindValue(':uid_list4', $userId, PDO::PARAM_INT);
  if ($q !== '') $st->bindValue(':qq_list', '%'.$q.'%');
  $st->execute();

  $rows = $st->fetchAll();
  if (!$rows) out(200, ['ok'=>true, 'total'=>$total, 'limit'=>$limit, 'offset'=>$offset, 'items'=>[]]);

  // Load last submissions in one go
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
        'scorePercent' => (int)round(((int)$r['correct_count']/max(1,(int)$r['total_questions']))*100),
        'durationSec' => $r['duration_sec'] !== null ? (int)$r['duration_sec'] : null,
        'created_at' => (string)$r['created_at']
      ];
    }
  }

  $items = [];
  foreach ($rows as $r) {
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
      'lastSubmission' => isset($r['last_submission_id']) && $r['last_submission_id']
        ? ($lastById[(int)$r['last_submission_id']] ?? null)
        : null
    ];
  }

  out(200, ['ok'=>true, 'total'=>$total, 'limit'=>$limit, 'offset'=>$offset, 'items'=>$items]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
