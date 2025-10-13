<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/**
 * CONFIG
 * ----------------------------------------------------------------
 * 1) Put your OpenRouter API key in an env var (recommended)
 *    or define OPENROUTER_API_KEY in config.php.
 *
 *    In cPanel → Cron/Advanced/Environment or .htaccess:
 *      SetEnv OPENROUTER_API_KEY "sk-or-...yourkey..."
 *
 * 2) You can change $MODEL to any OpenRouter model you prefer.
 */
require_once __DIR__ . '/../config.php';

$API_KEY = getenv('OPENROUTER_API_KEY') ?: (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '');
$MODEL   = 'alibaba/tongyi-deepresearch-30b-a3b:free'; // or another OpenRouter model

if ($API_KEY === '') {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'OpenRouter API key missing (set OPENROUTER_API_KEY or define OPENROUTER_API_KEY in config.php)']);
  exit;
}

/** Helpers */
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
function http_post_json(string $url, array $headers, array $payload, int $timeout = 60): array {
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

/** Input */
$in      = body_json();
$topic   = trim((string)($in['topic'] ?? ''));
$style   = trim((string)($in['style'] ?? ''));      // e.g. Academic / Practical Guide / Exam Purpose
$level   = trim((string)($in['level'] ?? ''));      // e.g. Beginner / Intermediate / Advanced
$language= trim((string)($in['language'] ?? ''));   // e.g. English
$micro   = !empty($in['microMode']);                // if true → compact outline

if ($topic === '') {
  out(422, ['ok'=>false,'error'=>'topic is required']);
}

/**
 * Prompting strategy:
 * - We ask the model to return STRICT JSON with chapters that reflect a real book.
 * - We enforce consistency: broad coverage, chronological/knowledge progression,
 *   and no missing major areas.
 * - We request sections as brief bullet headings to help your UI later.
 * - We gate Micro Mode (fewer chapters/sections) as a parameter.
 */
$sys = <<<SYS
You are an expert educational author and curriculum architect.
Your job: design a complete, real-book-quality chapter outline for the given topic.

Rules:
- Output MUST be STRICT JSON only, matching the schema exactly (no prose outside JSON).
- Cover the topic comprehensively like a real book (intro → foundations → intermediate → advanced → applications → exam prep/appendices if relevant).
- Order chapters logically (chronological or knowledge progression).
- Titles should be concise, professional, and unique.
- "sections" are short bullet subheadings (2–6 per chapter) to guide writing later.
- Do NOT include chapter content paragraphs here—only the outline.
- No duplicate chapters. No "TBD".
SYS;

$microNote = $micro
  ? "Micro Mode is ON: produce a compact outline (about 3–6 chapters, 2–3 sections each)."
  : "Micro Mode is OFF: produce a full outline (about 8–16 chapters, 3–6 sections each).";

$user = [
  'topic'    => $topic,
  'style'    => $style ?: null,
  'level'    => $level ?: null,
  'language' => $language ?: null,
  'microMode'=> $micro,
  'instruction' => $microNote,
  'schema' => [
    'type' => 'object',
    'required' => ['outline'],
    'properties' => [
      'outline' => [
        'type' => 'array',
        'items' => [
          'type' => 'object',
          'required' => ['index','title','sections'],
          'properties' => [
            'index'    => ['type'=>'integer', 'description'=>'1-based chapter index'],
            'title'    => ['type'=>'string',  'description'=>'chapter title'],
            'sections' => ['type'=>'array',   'items'=>['type'=>'string']]
          ]
        ]
      ]
    ]
  ]
];

$messages = [
  ['role' => 'system', 'content' => $sys],
  ['role' => 'user',   'content' =>
    "Create a comprehensive chapter outline for the topic below.\n\n".
    "Topic: {$topic}\n".
    ($style    ? "Style: {$style}\n"     : "").
    ($level    ? "Level: {$level}\n"     : "").
    ($language ? "Language: {$language}\n": "").
    "{$microNote}\n\n".
    "Return STRICT JSON ONLY in the following shape:\n".
    "{ \"outline\": [ { \"index\": 1, \"title\": \"...\", \"sections\": [\"...\",\"...\"] }, ... ] }"
  ],
  // Optionally pass the schema hint to some models via a second user tool hint:
  ['role' => 'user', 'content' => "Schema hint (for your internal validation): ".json_encode($user['schema'])]
];

/** Call OpenRouter */
list($code, $resp, $curlErr) = http_post_json(
  'https://openrouter.ai/api/v1/chat/completions',
  [
    'Content-Type: application/json',
    'Authorization: Bearer '.$API_KEY,
    // Optional but recommended for OpenRouter routing/telemetry:
    'HTTP-Referer: https://quadrailearn.quadravise.com',
    'X-Title: QuadraiLearn Outline Architect'
  ],
  [
    'model'    => $MODEL,
    'messages' => $messages,
    'temperature' => 0.3,   // keep structure stable
    'max_tokens'  => 1200,  // enough to return a full outline
  ],
  90
);

if ($curlErr) {
  out(502, ['ok'=>false,'error'=>'openrouter_unreachable','details'=>$curlErr]);
}
if ($code < 200 || $code >= 300) {
  out($code ?: 502, ['ok'=>false,'error'=>'openrouter_error','details'=>$resp]);
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  out(502, ['ok'=>false,'error'=>'bad_openrouter_json','details'=>$resp]);
}

$content = '';
if (isset($data['choices'][0]['message']['content'])) {
  $content = (string)$data['choices'][0]['message']['content'];
} else {
  out(502, ['ok'=>false,'error'=>'no_content_from_model','details'=>$data]);
}

/**
 * The model should return strict JSON. If it accidentally includes prose,
 * try to extract the largest JSON object from the string.
 */
$parsed = null;
$try = trim($content);
if ($try !== '') {
  $parsed = json_decode($try, true);
}
if (!is_array($parsed)) {
  if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $m)) {
    $parsed = json_decode($m[0], true);
  }
}
if (!is_array($parsed) || !isset($parsed['outline']) || !is_array($parsed['outline'])) {
  out(502, ['ok'=>false,'error'=>'model_did_not_return_outline_json','raw'=>$content]);
}

/** Post-process: normalize, index, dedupe, micro mode guard */
$outline = [];
$seen = [];
$maxChapters = $micro ? 6 : 20; // hard cap
$idx = 1;

foreach ($parsed['outline'] as $row) {
  if (!is_array($row)) continue;
  $title = trim((string)($row['title'] ?? ''));
  if ($title === '') continue;

  // de-dup by lowercase title
  $key = mb_strtolower($title);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;

  $sections = [];
  if (isset($row['sections']) && is_array($row['sections'])) {
    foreach ($row['sections'] as $s) {
      $s = trim((string)$s);
      if ($s !== '') $sections[] = $s;
    }
  }
  // sensible bounds on sections
  if ($micro) {
    $sections = array_slice($sections, 0, 3);
    if (count($sections) === 0) $sections = ['Overview','Key Ideas'];
  } else {
    $sections = array_slice($sections, 0, 6);
    if (count($sections) < 2) $sections = array_merge($sections, ['Overview','Key Ideas']);
    $sections = array_slice($sections, 0, 6);
  }

  $outline[] = [
    'index' => $idx++,
    'title' => $title,
    'sections' => $sections
  ];
  if (count($outline) >= $maxChapters) break;
}

if (empty($outline)) {
  out(502, ['ok'=>false,'error'=>'empty_outline_after_processing','raw'=>$content]);
}

/** Success */
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
