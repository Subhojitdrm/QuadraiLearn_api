<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php'; // require_auth()
require_once __DIR__ . '/../db.php';       // get_pdo()

// -------------------- CONFIG --------------------
const OPENROUTER_API_KEY = 'REPLACE_WITH_YOUR_OPENROUTER_KEY'; // <-- put your key
const OPENROUTER_URL     = 'https://openrouter.ai/api/v1/chat/completions';
const DEFAULT_MODEL      = 'alibaba/tongyi-deepresearch-30b-a3b:free'; // change if desired

// simple user-level rate limits (tune as needed)
const RL_MAX_PER_MIN = 3;
const RL_MAX_PER_DAY = 20;

// -------------------- HELPERS --------------------
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
function clamp_int(int $v, int $min, int $max): int {
  return max($min, min($max, $v));
}

// --- Robust JSON extraction & salvage ---
function strip_code_fences(string $s): string {
  // remove ```json ... ``` or ``` ... ```
  if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $s, $m)) {
    return $m[1];
  }
  return $s;
}
function try_decode_json(string $s): ?array {
  $d = json_decode($s, true);
  return is_array($d) ? $d : null;
}
/**
 * Extract the largest JSON object/array from a string.
 * If truncated, salvage by dropping the last incomplete object and closing the array.
 */
function extract_or_salvage_json(string $raw): ?array {
  $s = strip_code_fences($raw);

  // Fast path
  $d = try_decode_json($s);
  if ($d !== null) return $d;

  // Locate first JSON start
  $startObj = strpos($s, '{');
  $startArr = strpos($s, '[');
  if ($startObj === false && $startArr === false) return null;

  $start = ($startArr !== false && ($startObj === false || $startArr < $startObj)) ? $startArr : $startObj;
  $candidate = substr($s, $start);

  // Heuristic 1: cut at last closing brace/bracket
  $endBrace = strrpos($candidate, '}');
  $endBracket = strrpos($candidate, ']');
  if ($endBrace !== false || $endBracket !== false) {
    $end = max($endBrace ?: -1, $endBracket ?: -1);
    if ($end >= 0) {
      $slice = substr($candidate, 0, $end + 1);
      $d2 = try_decode_json($slice);
      if ($d2 !== null) return $d2;
    }
  }

  // Heuristic 2: salvage array-of-objects
  if ($startArr !== false && ($startObj === false || $startArr < $startObj)) {
    $arr = substr($s, $startArr); // starts with '['
    $depth = 0; $inStr = false; $esc = false;
    $lastGoodObjectEnd = -1;
    $len = strlen($arr);
    for ($i=0; $i<$len; $i++) {
      $ch = $arr[$i];
      if ($inStr) {
        if ($esc) { $esc = false; continue; }
        if ($ch === '\\') { $esc = true; continue; }
        if ($ch === '"') { $inStr = false; continue; }
        continue;
      } else {
        if ($ch === '"') { $inStr = true; continue; }
        if ($ch === '[' || $ch === '{') $depth++;
        if ($ch === ']' || $ch === '}') {
          $depth--;
          if ($ch === '}' && $depth === 1) $lastGoodObjectEnd = $i;
        }
      }
    }
    if ($lastGoodObjectEnd > 0) {
      $inner = substr($arr, 1, $lastGoodObjectEnd - 1);
      $inner = rtrim($inner, ", \r\n\t");
      $salvaged = '[' . $inner . ']';
      $d3 = try_decode_json($salvaged);
      if ($d3 !== null) return $d3;
      $wrapped = '{"questions":' . $salvaged . '}';
      $d4 = try_decode_json($wrapped);
      if ($d4 !== null) return $d4;
    }
  }

  return null;
}

function normalize_questions(array $raw, int $expectedCount): array {
  // Accept {questions:[...]} or just [...]
  $arr = [];
  if (isset($raw['questions']) && is_array($raw['questions'])) {
    $arr = $raw['questions'];
  } elseif (array_is_list($raw)) {
    $arr = $raw;
  } else {
    return [];
  }

  $out = [];
  $idx = 1;
  foreach ($arr as $q) {
    if (!is_array($q)) continue;

    $question = trim((string)($q['question'] ?? ''));
    $options  = $q['options'] ?? [];
    if (!is_array($options)) $options = [];
    $opts = [];
    foreach ($options as $o) {
      $o = trim((string)$o);
      if ($o !== '') $opts[] = $o;
    }
    $opts = array_slice($opts, 0, 4);
    if ($question === '' || count($opts) < 4) continue;

    // correct index / text
    $answerIndex = null;
    if (isset($q['answer_index'])) $answerIndex = (int)$q['answer_index'];
    elseif (isset($q['correct_index'])) $answerIndex = (int)$q['correct_index'];

    $answerText = '';
    if ($answerIndex !== null && $answerIndex >= 1 && $answerIndex <= 4) {
      $answerText = $opts[$answerIndex - 1] ?? '';
    } else {
      $answerText = trim((string)($q['answer'] ?? $q['correct_answer'] ?? ''));
      if ($answerText !== '') {
        foreach ($opts as $i => $opt) {
          if (strcasecmp($opt, $answerText) === 0) { $answerIndex = $i + 1; break; }
        }
      }
    }
    if ($answerIndex === null || $answerIndex < 1 || $answerIndex > 4) continue;

    $subTopic = trim((string)($q['sub_topic'] ?? $q['subTopic'] ?? ''));
    if ($subTopic === '') continue;

    $explanation = null;
    if (isset($q['explanation'])) {
      $explanation = trim((string)$q['explanation']);
      if ($explanation === '') $explanation = null;
    }

    $out[] = [
      'q_index'     => $idx++,
      'question'    => $question,
      'options'     => $opts,
      'correct_idx' => $answerIndex,
      'correct_txt' => $opts[$answerIndex - 1],
      'sub_topic'   => $subTopic,
      'explanation' => $explanation
    ];

    if (count($out) >= $expectedCount) break;
  }

  return $out;
}

// -------------------- AUTH --------------------
$claims = require_auth(); // 401 if missing/invalid
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) out(401, ['ok'=>false, 'error'=>'unauthorized']);

// -------------------- INPUT --------------------
$in = body_json();

$topic         = trim((string)($in['topic'] ?? ''));
$complexityRaw = strtolower(trim((string)($in['complexity'] ?? 'medium')));
$purpose       = trim((string)($in['purpose'] ?? ''));
$count         = (int)($in['questionCount'] ?? 10);
$model         = trim((string)($in['model'] ?? DEFAULT_MODEL));

if ($topic === '') out(422, ['ok'=>false, 'errors'=>['topic'=>'topic is required']]);

$complexity = in_array($complexityRaw, ['easy','medium','hard'], true) ? $complexityRaw : 'medium';
$count = clamp_int($count, 5, 50);

// -------------------- RATE LIMIT --------------------
try {
  $pdo = get_pdo();

  $perMin = $pdo->prepare('SELECT COUNT(*) FROM mock_tests WHERE user_id=:uid AND created_at >= (NOW() - INTERVAL 1 MINUTE)');
  $perMin->execute([':uid'=>$userId]);
  if ((int)$perMin->fetchColumn() >= RL_MAX_PER_MIN) {
    out(429, ['ok'=>false, 'error'=>'rate_limited_minute']);
  }

  $perDay = $pdo->prepare('SELECT COUNT(*) FROM mock_tests WHERE user_id=:uid AND created_at >= (NOW() - INTERVAL 1 DAY)');
  $perDay->execute([':uid'=>$userId]);
  if ((int)$perDay->fetchColumn() >= RL_MAX_PER_DAY) {
    out(429, ['ok'=>false, 'error'=>'rate_limited_day']);
  }
} catch (Throwable $e) {
  // If RL check fails, we let it pass rather than blocking the user.
}

// -------------------- PROMPT --------------------
$sys = "You output ONLY strict JSON. No markdown code fences, no prose, no commentary. If unsure, output []";

$userInstr =
  "Create a mock test with exactly {$count} multiple-choice questions on the topic: {$topic}.\n".
  "Difficulty: {$complexity}. Purpose: ".($purpose !== '' ? $purpose : 'general assessment').".\n\n".
  "Return JSON ONLY in one of the following shapes:\n".
  "{\"questions\": [ { ... }, ... ] }  OR  [ { ... }, ... ]\n\n".
  "Each question object MUST be exactly:\n".
  "{\n".
  "  \"question\": \"<clear question>\",\n".
  "  \"options\": [\"A\", \"B\", \"C\", \"D\"],\n".
  "  \"correct_index\": <1-4>,\n".
  "  \"sub_topic\": \"<precise sub-topic label>\",\n".
  "  \"explanation\": \"<brief explanation>\"\n".
  "}\n\n".
  "Rules:\n".
  "- options must be 4 non-empty distinct strings\n".
  "- correct_index must match the correct option (1..4)\n".
  "- sub_topic must be specific (e.g., \"useEffect dependencies\" not just \"React\")\n".
  "- STRICT: Do not use markdown code fences (```), do not add commentary. JSON ONLY.";

// -------------------- CALL OPENROUTER --------------------
$payload = [
  'model' => $model,
  'messages' => [
    ['role'=>'system', 'content'=>$sys],
    ['role'=>'user',   'content'=>$userInstr],
  ],
  'temperature' => 0.2,
  'max_tokens'  => 3800   // bumped to reduce truncation
];

$ch = curl_init(OPENROUTER_URL);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    'Authorization: Bearer '.OPENROUTER_API_KEY
  ],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES)
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false || $http >= 500) {
  out(502, ['ok'=>false, 'error'=>'openrouter_unavailable', 'detail'=>$curlErr ?: null]);
}

$data = json_decode($resp, true);
$content = (string)($data['choices'][0]['message']['content'] ?? '');

if ($content === '') {
  out(502, ['ok'=>false, 'error'=>'model_returned_empty']);
}

// -------------------- PARSE & VALIDATE JSON --------------------
$rawJson = extract_or_salvage_json($content);
if (!$rawJson) {
  out(502, ['ok'=>false, 'error'=>'json_extract_failed', 'raw'=>substr($content, 0, 400)]);
}

$questions = normalize_questions($rawJson, $count);
if (count($questions) === 0) {
  out(502, ['ok'=>false, 'error'=>'no_valid_questions', 'raw'=>substr($content, 0, 400)]);
}
if (count($questions) < $count) {
  out(502, ['ok'=>false, 'error'=>'insufficient_questions', 'got'=>count($questions), 'want'=>$count]);
}

// -------------------- SAVE TO DB --------------------
try {
  $pdo = get_pdo();
  $pdo->beginTransaction();

  $insTest = $pdo->prepare('INSERT INTO mock_tests
    (user_id, topic, complexity, purpose, question_count, model, status, created_at)
    VALUES (:uid,:topic,:comp,:purpose,:cnt,:model,"ready",NOW())');
  $insTest->execute([
    ':uid'=>$userId, ':topic'=>$topic, ':comp'=>$complexity,
    ':purpose'=>$purpose !== '' ? $purpose : null,
    ':cnt'=>count($questions), ':model'=>$model
  ]);
  $testId = (int)$pdo->lastInsertId();

  $insQ = $pdo->prepare('INSERT INTO mock_questions
    (test_id, q_index, question_text, options_json, correct_index, correct_text, sub_topic, explanation)
    VALUES (:tid, :idx, :q, :opts, :ci, :ct, :sub, :exp)');

  foreach ($questions as $q) {
    $insQ->execute([
      ':tid'=>$testId,
      ':idx'=>$q['q_index'],
      ':q'=>$q['question'],
      ':opts'=>json_encode($q['options'], JSON_UNESCAPED_UNICODE),
      ':ci'=>$q['correct_idx'],
      ':ct'=>$q['correct_txt'],
      ':sub'=>$q['sub_topic'],
      ':exp'=>$q['explanation'] ?? null
    ]);
  }

  $pdo->commit();

  out(200, [
    'ok'=>true,
    'test'=>[
      'id'=>$testId,
      'topic'=>$topic,
      'complexity'=>$complexity,
      'purpose'=>$purpose !== '' ? $purpose : null,
      'questionCount'=>count($questions),
      'createdAt'=>date('c')
    ],
    'questions'=>array_map(function($q){
      return [
        'index'       => $q['q_index'],
        'question'    => $q['question'],
        'options'     => $q['options'],
        'correct_idx' => $q['correct_idx'],
        'sub_topic'   => $q['sub_topic'],
        'explanation' => $q['explanation'] ?? null
      ];
    }, $questions)
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  out(500, ['ok'=>false, 'error'=>'db_error']);
}
