<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../db.php';

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}
function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) out(401, ['ok'=>false, 'error'=>'unauthorized']);

$in = body_json();
$testId = (int)($in['testId'] ?? 0);
$answers = $in['answers'] ?? [];
$duration = isset($in['durationSec']) ? max(0, (int)$in['durationSec']) : null;
if ($testId <= 0) out(422, ['ok'=>false, 'errors'=>['testId'=>'required']]);
if (!is_array($answers) || empty($answers)) out(422, ['ok'=>false, 'errors'=>['answers'=>'non-empty array required']]);

try {
  $pdo = get_pdo();

  // Ensure test belongs to user
  $t = $pdo->prepare('SELECT id, question_count FROM mock_tests WHERE id=:id AND user_id=:uid LIMIT 1');
  $t->execute([':id'=>$testId, ':uid'=>$userId]);
  $test = $t->fetch();
  if (!$test) out(404, ['ok'=>false, 'error'=>'test not found']);

  // Load questions (map by id and by index)
  $qstmt = $pdo->prepare('
    SELECT id, q_index, correct_index, correct_text, sub_topic
    FROM mock_questions WHERE test_id=:tid
  ');
  $qstmt->execute([':tid'=>$testId]);

  $byId = []; $byIndex = [];
  while ($q = $qstmt->fetch()) {
    $row = [
      'id' => (int)$q['id'],
      'index' => (int)$q['q_index'],
      'correct_idx' => (int)$q['correct_index'],
      'correct_text'=> (string)$q['correct_text'],
      'sub_topic'   => (string)$q['sub_topic']
    ];
    $byId[$row['id']] = $row;
    $byIndex[$row['index']] = $row;
  }

  // Score
  $detail = [];
  $correct = 0;
  $topicMiss = []; // sub_topic => incorrect count

  foreach ($answers as $a) {
    $qid = isset($a['questionId']) ? (int)$a['questionId'] : 0;
    $idx = isset($a['index']) ? (int)$a['index'] : 0;
    $choice = (int)($a['choiceIndex'] ?? 0);
    if ($choice < 1 || $choice > 4) continue;

    $qrow = null;
    if ($qid > 0 && isset($byId[$qid])) $qrow = $byId[$qid];
    elseif ($idx > 0 && isset($byIndex[$idx])) $qrow = $byIndex[$idx];
    if (!$qrow) continue;

    $isCorrect = ($choice === (int)$qrow['correct_idx']);
    if ($isCorrect) $correct++; else $topicMiss[$qrow['sub_topic']] = ($topicMiss[$qrow['sub_topic']] ?? 0) + 1;

    $detail[] = [
      'questionId'  => (int)$qrow['id'],
      'index'       => (int)$qrow['index'],
      'chosen_idx'  => $choice,
      'correct_idx' => (int)$qrow['correct_idx'],
      'is_correct'  => $isCorrect
    ];
  }

  // Persist submission + answers
  $pdo->beginTransaction();

  $insSub = $pdo->prepare('INSERT INTO mock_submissions
    (test_id, user_id, total_questions, correct_count, duration_sec, created_at)
    VALUES (:tid, :uid, :total, :corr, :dur, NOW())');
  $insSub->execute([
    ':tid'=>$testId, ':uid'=>$userId,
    ':total'=>(int)$test['question_count'],
    ':corr'=>$correct,
    ':dur'=>$duration
  ]);
  $subId = (int)$pdo->lastInsertId();

  $insAns = $pdo->prepare('INSERT INTO mock_answers
    (submission_id, question_id, chosen_index, is_correct)
    VALUES (:sid, :qid, :choice, :ok)');

  foreach ($detail as $d) {
    $insAns->execute([
      ':sid'=>$subId,
      ':qid'=>$d['questionId'],
      ':choice'=>$d['chosen_idx'],
      ':ok'=> $d['is_correct'] ? 1 : 0
    ]);
  }

  $pdo->commit();

  // Build weak-topics summary (sorted by misses desc)
  arsort($topicMiss);
  $weak = [];
  foreach ($topicMiss as $topic => $cnt) {
    $weak[] = ['sub_topic'=>$topic, 'misses'=>$cnt];
  }

  out(200, [
    'ok'=>true,
    'submission'=>[
      'id' => $subId,
      'testId' => (int)$testId,
      'total' => (int)$test['question_count'],
      'correct' => $correct,
      'scorePercent' => round(($correct / max(1,(int)$test['question_count'])) * 100),
      'durationSec' => $duration
    ],
    'weak_sub_topics' => $weak,
    'detail' => $detail
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
