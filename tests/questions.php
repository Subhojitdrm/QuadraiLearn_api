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

$testId = (int)($_GET['testId'] ?? 0);
if ($testId <= 0) out(422, ['ok'=>false, 'error'=>'testId is required']);

$reveal = isset($_GET['revealAnswers']) && (int)$_GET['revealAnswers'] === 1;

try {
  $pdo = get_pdo();

  // verify ownership
  $t = $pdo->prepare('SELECT id, topic, complexity, purpose, question_count, model, status, created_at
                      FROM mock_tests WHERE id=:id AND user_id=:uid LIMIT 1');
  $t->execute([':id'=>$testId, ':uid'=>$userId]);
  $test = $t->fetch();
  if (!$test) out(404, ['ok'=>false, 'error'=>'test not found']);

  $q = $pdo->prepare('SELECT id, q_index, question_text, options_json, correct_index, correct_text, sub_topic, explanation
                      FROM mock_questions WHERE test_id=:tid ORDER BY q_index ASC');
  $q->execute([':tid'=>$testId]);

  $questions = [];
  while ($row = $q->fetch()) {
    $opts = json_decode((string)$row['options_json'], true);
    if (!is_array($opts)) $opts = [];
    $item = [
      'id'        => (int)$row['id'],
      'test_id'   => (int)$testId,
      'index'     => (int)$row['q_index'],
      'question'  => (string)$row['question_text'],
      'options'   => $opts,
      'sub_topic' => (string)$row['sub_topic'],
      'explanation' => $row['explanation'] ?: null,
    ];
    if ($reveal) {
      $item['correct_idx']  = (int)$row['correct_index'];
      $item['correct_text'] = (string)$row['correct_text'];
    }
    $questions[] = $item;
  }

  out(200, [
    'ok'=>true,
    'test'=>[
      'id'            => (int)$test['id'],
      'topic'         => (string)$test['topic'],
      'complexity'    => (string)$test['complexity'],
      'purpose'       => $test['purpose'] ?: null,
      'questionCount' => (int)$test['question_count'],
      'model'         => $test['model'] ?: null,
      'status'        => (string)$test['status'],
      'created_at'    => (string)$test['created_at']
    ],
    'questions'=>$questions
  ]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=> (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
