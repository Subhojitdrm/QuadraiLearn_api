<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/**
 * Requires:
 *  - api/config.php with: const OPENROUTER_API_KEY = '...'; const DEBUG = false;
 *  - api/lib/auth.php providing require_auth()
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php'; // enforces JWT

// ---------- auth: only logged-in users ----------
$claims = require_auth(); // 401s if missing/invalid
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']);
  exit;
}

// ---------- config ----------
$API_KEY = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
$MODEL   = 'alibaba/tongyi-deepresearch-30b-a3b:free'; // change model if you wish
if ($API_KEY === '') {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'OpenRouter API key missing in config.php']);
  exit;
}

// ---------- helpers ----------
function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}
function http_post_json(string $url, array $headers, array $payload, int $timeout = 90): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => $timeout,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$code, $resp, $err];
}
// PHP < 8.1 polyfill
if (!function_exists('array_is_list')) {
  function array_is_list(array $arr): bool { $i=0; foreach ($arr as $k=>$_){ if ($k!==$i++) return false; } return true; }
}

// Strip ```json ... ``` fences if the model wraps output
function strip_code_fences(string $s): string {
  if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $s, $m)) return trim($m[1]);
  return $s;
}

/** Try to salvage valid JSON from possibly fenced / truncated output */
function salvage_json_outline(string $content): ?array {
  $raw = trim(strip_code_fences($content));

  // 1) direct decode (object or array)
  $j = json_decode($raw, true);
  if (is_array($j)) {
    if (isset($j['outline']) && is_array($j['outline'])) return $j;
    if (array_is_list($j)) return ['outline' => $j];
  }

  // 2) largest {...} or [...] block
  if (preg_match('/(\{(?:[^{}]|(?R))*\})/s', $content, $m)) {
    $j = json_decode($m[1], true);
    if (is_array($j)) {
      if (isset($j['outline']) && is_array($j['outline'])) return $j;
      if (array_is_list($j)) return ['outline' => $j];
    }
  }
  if (preg_match('/(\[(?:[^\[\]]|(?R))*\])/s', $content, $m2)) {
    $a = json_decode($m2[1], true);
    if (is_array($a) && array_is_list($a)) return ['outline' => $a];
  }

  // 3) trim to last closing brace/bracket and try
  $lastObj = strrpos($raw, '}'); $lastArr = strrpos($raw, ']');
  $cutPos  = max($lastObj !== false ? $lastObj : -1, $lastArr !== false ? $lastArr : -1);
  if ($cutPos > 0) {
    $slice = substr($raw, 0, $cutPos + 1);
    $j = json_decode($slice, true);
    if (is_array($j)) {
      if (isset($j['outline']) && is_array($j['outline'])) return $j;
      if (array_is_list($j)) return ['outline' => $j];
    }
  }

  // 4) balance braces/brackets and retry
  $openCurly = substr_count($raw, '{');  $closeCurly = substr_count($raw, '}');
  $openBrack = substr_count($raw, '[');  $closeBrack = substr_count($raw, ']');
  $fix = $raw . str_repeat('}', max(0, $openCurly - $closeCurly)) . str_repeat(']', max(0, $openBrack - $closeBrack));
  $j = json_decode($fix, true);
  if (is_array($j)) {
    if (isset($j['outline']) && is_array($j['outline'])) return $j;
    if (array_is_list($j)) return ['outline' => $j];
  }

  return null;
}

// ---------- input ----------
$in       = body_json();
$topic    = trim((string)($in['topic'] ?? ''));
$style    = trim((string)($in['style'] ?? ''));       // Academic / Practical / Exam Purpose ...
$level    = trim((string)($in['level'] ?? ''));       // Beginner / Intermediate / Advanced
$language = trim((string)($in['language'] ?? ''));    // English / ...
$micro    = !empty($in['microMode']);                 // true -> compact outline

if ($topic === '') out(422, ['ok'=>false,'error'=>'topic is required']);

// ---------- prompt ----------
$sys = <<<SYS
You are an expert educational author and curriculum architect.
Design a complete, real-book-quality chapter outline for the given topic.

Rules:
- Output MUST be STRICT JSON only, no prose outside JSON.
- Cover the topic comprehensively like a real book (intro → foundations → intermediate → advanced → applications → exam prep/appendices if relevant).
- Order chapters logically (chronological or knowledge progression).
- Titles concise, professional, and unique.
- "sections" are short bullet subheadings (2–6 per chapter).
- Do NOT include paragraphs of content—outline only.
- No duplicate chapters. No "TBD".
SYS;

$microNote = $micro
  ? "Micro Mode is ON: produce a compact outline (about 3–6 chapters, 2–3 sections each)."
  : "Micro Mode is OFF: produce a full outline (about 8–16 chapters, 3–6 sections each).";

$messages = [
  ['role' => 'system', 'content' => $sys],
  ['role' => 'user', 'content' =>
    "Create a comprehensive chapter outline for the topic below.\n\n".
    "Topic: {$topic}\n".
    ($style    ? "Style: {$style}\n"     : "").
    ($level    ? "Level: {$level}\n"     : "").
    ($language ? "Language: {$language}\n": "").
    "{$microNote}\n\n".
    "Return STRICT JSON ONLY in the following shape:\n".
    "{ \"outline\": [ { \"index\": 1, \"title\": \"...\", \"sections\": [\"...\",\"...\"] }, ... ] }"
  ],
  // gentle schema hint; harmless if ignored
  ['role' => 'user', 'content' => 'Schema: {"outline":[{"index":1,"title":"...","sections":["..."]}]}']
];

// ---------- call OpenRouter ----------
list($code, $resp, $curlErr) = http_post_json(
  'https://openrouter.ai/api/v1/chat/completions',
  [
    'Content-Type: application/json',
    'Authorization: Bearer '.$API_KEY,
    'HTTP-Referer: https://quadrailearn.quadravise.com',
    'X-Title: QuadraiLearn Outline Architect'
  ],
  [
    'model'           => $MODEL,
    'messages'        => $messages,
    'temperature'     => 0.3,
    'max_tokens'      => 1600,
    'response_format' => ['type' => 'json_object'] // some models respect this
  ],
  90
);

if ($curlErr) {
  out(502, ['ok'=>false,'error'=>'openrouter_unreachable', 'details'=>(defined('DEBUG')&&DEBUG)?$curlErr:null]);
}
if ($code < 200 || $code >= 300) {
  out($code ?: 502, ['ok'=>false,'error'=>'openrouter_error', 'details'=>(defined('DEBUG')&&DEBUG)?$resp:null]);
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  out(502, ['ok'=>false,'error'=>'bad_openrouter_json', 'details'=>(defined('DEBUG')&&DEBUG)?$resp:null]);
}

$content = $data['choices'][0]['message']['content'] ?? '';
if (!is_string($content) || $content === '') {
  out(502, ['ok'=>false,'error'=>'no_content_from_model', 'details'=>(defined('DEBUG')&&DEBUG)?$data:null]);
}

// ---------- parse & salvage ----------
$parsed = salvage_json_outline($content);
if (!$parsed) {
  out(502, ['ok'=>false,'error'=>'model_did_not_return_outline_json', 'raw'=>(defined('DEBUG')&&DEBUG)?$content:null]);
}

// accept {outline:[...]} or top-level array
$outlineIn = isset($parsed['outline']) && is_array($parsed['outline']) ? $parsed['outline'] : $parsed;

// ---------- normalize / clean for UI ----------
$outline     = [];
$seen        = [];
$maxChapters = $micro ? 6 : 20; // hard cap
$idx         = 1;

foreach ($outlineIn as $row) {
  if (!is_array($row)) continue;

  $title = trim((string)($row['title'] ?? ''));
  if ($title === '') continue;

  // dedupe by case-insensitive title
  $key = mb_strtolower($title);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;

  // sections cleanup
  $sections = [];
  if (isset($row['sections']) && is_array($row['sections'])) {
    foreach ($row['sections'] as $s) {
      $s = trim((string)$s);
      if ($s !== '') $sections[] = $s;
    }
  }

  if ($micro) {
    $sections = array_slice($sections, 0, 3);
    if (count($sections) === 0) $sections = ['Overview','Key Ideas'];
  } else {
    $sections = array_slice($sections, 0, 6);
    if (count($sections) < 2) $sections = array_merge($sections, ['Overview','Key Ideas']);
    $sections = array_slice($sections, 0, 6);
  }

  $outline[] = [
    'index'    => $idx++,
    'title'    => $title,
    'sections' => $sections
  ];

  if (count($outline) >= $maxChapters) break;
}

if (empty($outline)) {
  out(502, ['ok'=>false,'error'=>'empty_outline_after_processing', 'raw'=>(defined('DEBUG')&&DEBUG)?$content:null]);
}

// ---------- success ----------
out(200, [
  'ok'   => true,
  'meta' => [
    'topic'     => $topic,
    'style'     => $style ?: null,
    'level'     => $level ?: null,
    'language'  => $language ?: null,
    'microMode' => $micro,
    'userId'    => $userId
  ],
  'outline' => $outline
]);
