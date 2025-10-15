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

$submissionId = (int)($_GET['submissionId'] ?? 0);
$testId       = (int)($_GET['testId'] ?? 0);

try {
  $pdo = get_pdo();

  // Resolve submission (by id OR latest for a test)
  if ($submissionId > 0) {
    $s = $pdo->prepare("
      SELECT ms.id, ms.test_id, ms.user_id, ms.total_questions, ms.correct_count, ms.duration_sec, ms.created_at,
             t.topic, t.complexity, t.purpose, t.question_count, t.model
      FROM mock_submissions ms
      JOIN mock_tests t ON t.id = ms.test_id
      WHERE ms.id = :sid AND ms.user_id = :uid AND t.user_id = :uid
      LIMIT 1
    ");
    $s->execute([':sid'=>$submissionId, ':uid'=>$userId]);
  } elseif ($testId > 0) {
    $s = $pdo->prepare("
      SELECT ms.id, ms.test_id, ms.user_id, ms.total_questions, ms.correct_count, ms.duration_sec, ms.created_at,
             t.topic, t.complexity, t.purpose, t.question_count, t.model
      FROM mock_submissions ms
      JOIN mock_tests t ON t.id = ms.test_id
      WHERE ms.test_id = :tid AND ms.user_id = :uid AND t.user_id = :uid
      ORDER BY ms.created_at DESC
      LIMIT 1
    ");
    $s->execute([':tid'=>$testId, ':uid'=>$userId]);
  } else {
    out(422, ['ok'=>false, 'error'=>'Provide submissionId OR testId']);
  }

  $sub = $s->fetch();
  if (!$sub) out(404, ['ok'=>false, 'error'=>'submission not found']);

  $sid  = (int)$sub['id'];
  $tid  = (int)$sub['test_id'];

  // Load all questions for this test
  $q = $pdo->prepare("
    SELECT id, q_index, question_text, options_json, correct_index, correct_text, sub_topic, explanation
    FROM mock_questions
    WHERE test_id = :tid
    ORDER BY q_index ASC
  ");
  $q->execute([':tid'=>$tid]);
  $questions = $q->fetchAll();

  // Load user answers for this submission
  $a = $pdo->prepare("SELECT question_id, chosen_index, is_correct FROM mock_answers WHERE submission_id = :sid");
  $a->execute([':sid'=>$sid]);
  $answers = [];
  $attempted = 0;
  while ($r = $a->fetch()) {
    $qid = (int)$r['question_id'];
    $answers[$qid] = [
      'chosen_idx' => (int)$r['chosen_index'],
      'is_correct' => ((int)$r['is_correct'] === 1)
    ];
    $attempted++;
  }

  // If no attempts, we MUST NOT reveal correct answers
  $revealCorrect = $attempted > 0;

  $items = [];
  foreach ($questions as $row) {
    $opts = json_decode((string)$row['options_json'], true);
    if (!is_array($opts)) $opts = [];
    $qid = (int)$row['id'];

    $chosen = $answers[$qid]['chosen_idx'] ?? null;
    $isCorr = $answers[$qid]['is_correct'] ?? null;

    $item = [
      'id'        => $qid,
      'index'     => (int)$row['q_index'],
      'question'  => (string)$row['question_text'],
      'options'   => $opts,
      'sub_topic' => (string)$row['sub_topic'],
      'explanation' => $row['explanation'] ?: null,
      'chosen_idx'  => $chosen,
      'is_correct'  => $isCorr
    ];

    if ($revealCorrect) {
      $item['correct_idx']  = (int)$row['correct_index'];
      $item['correct_text'] = (string)$row['correct_text'];
    }
    $items[] = $item;
  }

  out(200, [
    'ok'=>true,
    'submission'=>[
      'id'          => $sid,
      'testId'      => $tid,
      'topic'       => (string)$sub['topic'],
      'complexity'  => (string)$sub['complexity'],
      'purpose'     => $sub['purpose'] ?: null,
      'questionCount'=> (int)$sub['question_count'],
      'total'       => (int)$sub['total_questions'],
      'correct'     => (int)$sub['correct_count'],
      'scorePercent'=> (int)round(((int)$sub['correct_count']/max(1,(int)$sub['total_questions']))*100),
      'durationSec' => $sub['duration_sec'] !== null ? (int)$sub['duration_sec'] : null,
      'created_at'  => (string)$sub['created_at'],
      'answersAttempted' => $attempted,
      'revealingAnswers' => $revealCorrect ? 1 : 0
    ],
    'questions' => $items
  ]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
