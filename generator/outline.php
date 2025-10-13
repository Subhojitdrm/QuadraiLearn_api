<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/**
 * Requires:
 *   - public_html/QuadraiLearn/api/config.php
 *     with:  const OPENROUTER_API_KEY = 'sk-or-...';
 *             const DEBUG = false;  // (optional)
 */
require_once __DIR__ . '/../config.php';

/** === Config === */
$API_KEY = (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '');
$MODEL   = 'alibaba/tongyi-deepresearch-30b-a3b:free'; // change if you prefer another OpenRouter model
if ($API_KEY === '') {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'OpenRouter API key missing in config.php']);
  exit;
}

/** === Helpers === */
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
function strip_code_fences(string $s): string {
  // Remove ```json ... ``` or ``` ... ```
  if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $s, $m)) {
    return trim($m[1]);
  }
  return $s;
}

/** === Input === */
$in       = body_json();
$topic    = trim((string)($in['topic'] ?? ''));
$style    = trim((string)($in['style'] ?? ''));      // Academic / Practical / Exam Purpose ...
$level    = trim((string)($in['level'] ?? ''));      // Beginner / Intermediate / Advanced
$language = trim((string)($in['language'] ?? ''));   // English / ...
$micro    = !empty($in['microMode']);                // true -> compact outline

if ($topic === '') {
  out(422, ['ok'=>false,'error'=>'topic is required']);
}

/** === Prompt === */
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
  // Schema hint (harmless if ignored by model)
  ['role' => 'user', 'content' => 'Schema: {"outline":[{"index":1,"title":"...","sections":["..."]}]}']
];

/** === Call OpenRouter === */
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
    'temperature'     => 0.3,   // prefer stable structure
    'max_tokens'      => 1600,
    'response_format' => ['type' => 'json_object'] // hint some models respect
  ],
  90
);

if ($curlErr) {
  out(502, ['ok'=>false,'error'=>'openrouter_unreachable','details'=> (defined('DEBUG')&&DEBUG) ? $curlErr : null]);
}
if ($code < 200 || $code >= 300) {
  out($code ?: 502, ['ok'=>false,'error'=>'openrouter_error','details'=> (defined('DEBUG')&&DEBUG) ? $resp : null]);
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  out(502, ['ok'=>false,'error'=>'bad_openrouter_json','details'=> (defined('DEBUG')&&DEBUG) ? $resp : null]);
}

$content = $data['choices'][0]['message']['content'] ?? '';
if (!is_string($content) || $content === '') {
  out(502, ['ok'=>false,'error'=>'no_content_from_model','details'=> (defined('DEBUG')&&DEBUG) ? $data : null]);
}

/** === Parse model output robustly === */
$raw    = trim(strip_code_fences($content));
$parsed = json_decode($raw, true);

// If not parsed, try largest object or array inside
if (!is_array($parsed)) {
  if (preg_match('/(\{(?:[^{}]|(?R))*\})/s', $content, $m)) {
    $parsed = json_decode($m[1], true);
  }
  if (!is_array($parsed) && preg_match('/(\[(?:[^\[\]]|(?R))*\])/s', $content, $m2)) {
    $maybeArray = json_decode($m2[1], true);
    if (is_array($maybeArray)) $parsed = ['outline' => $maybeArray];
  }
}

if (!is_array($parsed)) {
  out(502, ['ok'=>false,'error'=>'model_did_not_return_outline_json','raw'=> (defined('DEBUG')&&DEBUG) ? $content : null]);
}

/** Accept {outline:[...]} or top-level array */
if (isset($parsed['outline']) && is_array($parsed['outline'])) {
  $outlineIn = $parsed['outline'];
} elseif (array_is_list($parsed)) {
  $outlineIn = $parsed;
} else {
  out(502, ['ok'=>false,'error'=>'model_did_not_return_outline_json','raw'=> (defined('DEBUG')&&DEBUG) ? $content : null]);
}

/** === Post-process: normalize/clean === */
$outline     = [];
$seen        = [];
$maxChapters = $micro ? 6 : 20; // hard cap
$idx         = 1;

foreach ($outlineIn as $row) {
  if (!is_array($row)) continue;

  $title = trim((string)($row['title'] ?? ''));
  if ($title === '') continue;

  // dedupe by lowercase title
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
  out(502, ['ok'=>false,'error'=>'empty_outline_after_processing','raw'=> (defined('DEBUG')&&DEBUG) ? $content : null]);
}

/** === Success === */
out(200, [
  'ok'   => true,
  'meta' => [
    'topic'     => $topic,
    'style'     => $style ?: null,
    'level'     => $level ?: null,
    'language'  => $language ?: null,
    'microMode' => $micro
  ],
  'outline' => $outline
]);
