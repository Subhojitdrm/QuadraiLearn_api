<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/**
 * Requires:
 *  - api/config.php with:
 *      const OPENROUTER_API_KEY = 'sk-or-...';
 *      const DEBUG = false; // optional
 *  - api/lib/auth.php with require_auth()
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';

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
if ($API_KEY === '') { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'OpenRouter API key missing in config.php']); exit; }

/**
 * We’ll try up to 2 models in this order.
 * Feel free to reorder or trim this list depending on your plan/access.
 */
$MODEL_CANDIDATES = [
  'alibaba/tongyi-deepresearch-30b-a3b:free', // your current pick
  'meta/llama-3.1-70b-instruct',              // good JSON behavior on OpenRouter
  'openai/gpt-4o-mini',                       // also decent JSON obedience
];

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
function fallback_outline_for_topic(string $topic, bool $micro): array {
  // Generic, but clean and useful for any subject
  $chapters = [
    ['t'=>"Introduction to {$topic}",                        's'=>['Scope & Outcomes','Why it Matters','Where to Start']],
    ['t'=>"Foundations of {$topic}",                         's'=>['Core Concepts','Terminology','Common Misconceptions']],
    ['t'=>"Essential Techniques in {$topic}",                's'=>['Methods & Tools','Worked Examples','Practice Tips']],
    ['t'=>"Intermediate Concepts in {$topic}",               's'=>['Deeper Patterns','Pitfalls','Best Practices']],
    ['t'=>"Applications of {$topic}",                        's'=>['Real-World Scenarios','Mini Projects','Case Studies']],
    ['t'=>"Assessment & Review for {$topic}",                's'=>['Checklists','Exercises','Mock Questions']],
    ['t'=>"Advanced Topics in {$topic}",                     's'=>['Edge Cases','Performance/Scaling','Trade-offs']],
    ['t'=>"Project / Capstone for {$topic}",                 's'=>['Project Brief','Milestones','Evaluation Criteria']],
    ['t'=>"Further Study & Resources for {$topic}",          's'=>['Books & Papers','Courses & Communities','Next Steps']]
  ];

  // If the topic looks like a school grade (e.g., "Math class 1"), bias to a simpler syllabus
  if (preg_match('/\b(class|grade|level)\s*\d+/i', $topic)) {
    $chapters = [
      ['t'=>"Getting Started with {$topic}",                  's'=>['What You Will Learn','Study Plan','How to Practice']],
      ['t'=>"Numbers & Operations in {$topic}",               's'=>['Basics','Examples','Exercises']],
      ['t'=>"Patterns & Problem Solving in {$topic}",         's'=>['Strategies','Worked Examples','Practice']],
      ['t'=>"Measurement & Shapes in {$topic}",               's'=>['Key Ideas','Hands-on Tasks','Review']],
      ['t'=>"Everyday Applications of {$topic}",              's'=>['Real-Life Examples','Mini Projects','Tips']],
      ['t'=>"Review & Assessment for {$topic}",               's'=>['Check Your Understanding','Quiz','What’s Next']]
    ];
  }

  // Trim for micro mode
  if ($micro) $chapters = array_slice($chapters, 0, 6);

  // Build the final array with indexes
  $out = []; $i = 1;
  foreach ($chapters as $c) {
    $secs = array_slice($c['s'], 0, $micro ? 4 : 6);
    if (count($secs) < 2) $secs = array_pad($secs, 2, 'Overview');
    $out[] = ['index'=>$i++, 'title'=>$c['t'], 'sections'=>$secs];
  }
  return $out;
}
// ---------- input ----------
$in       = body_json();
$topic    = trim((string)($in['topic'] ?? ''));
$style    = trim((string)($in['style'] ?? ''));       // Academic / Practical / Exam Purpose ...
$level    = trim((string)($in['level'] ?? ''));       // Beginner / Intermediate / Advanced
$language = trim((string)($in['language'] ?? ''));    // English / ...
$micro    = !empty($in['microMode']);                 // compact outline
$constraint = trim((string)($in['promptConstraint'] ?? ''));  // NEW (optional)
if ($topic === '') out(422, ['ok'=>false,'error'=>'topic is required']);

// ---------- prompts ----------
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

$noteFull  = "Produce a full outline (8–14 chapters, 3–6 sections each).";
$noteMicro = "Produce a compact outline (6–10 chapters, 2–5 sections each).";
$constraintLine = $constraint !== '' ? ("Constraint: {$constraint}\n") : '';
// compose base user message
$baseUser = "Create a comprehensive chapter outline for the topic below.\n\n".
            "Topic: {$topic}\n".
            ($style    ? "Style: {$style}\n"     : "").
            ($level    ? "Level: {$level}\n"     : "").
            ($language ? "Language: {$language}\n": "").
            $constraintLine .
            ($micro ? "Produce a compact outline (6–10 chapters, 2–5 sections each)." 
                    : "Produce a full outline (8–14 chapters, 3–6 sections each).") . "\n\n".
            "Return STRICT JSON ONLY in the shape:\n".
            "{ \"outline\": [ { \"index\": 1, \"title\": \"...\", \"sections\": [\"...\",\"...\"] }, ... ] }";

// a stricter compact message for retry
$retryUser = "Your previous output was truncated or empty. Return ONLY valid JSON. ".
             "Generate at most 10 chapters, each with 2–5 sections, no prose outside JSON.\n\n".
             $baseUser;

// ---------- function to call OpenRouter once ----------
function call_openrouter_outline(string $apiKey, string $model, array $messages, int $maxTokens, float $temperature = 0.3): array {
  return http_post_json(
    'https://openrouter.ai/api/v1/chat/completions',
    [
      'Content-Type: application/json',
      'Authorization: Bearer '.$apiKey,
      'HTTP-Referer: https://quadrailearn.quadravise.com',
      'X-Title: QuadraiLearn Outline Architect'
    ],
    [
      'model'           => $model,
      'messages'        => $messages,
      'temperature'     => $temperature,
      'max_tokens'      => $maxTokens,
      'response_format' => ['type' => 'json_object'] // some models obey
    ],
    90
  );
}

// ---------- attempt loop: try models + retry compact if needed ----------
$finalOutline = null;
$diags = [];

foreach ($MODEL_CANDIDATES as $model) {
  // First attempt: normal (micro decides requested size, but keep full tokens modest)
  $messages = [
    ['role' => 'system', 'content' => $sys],
    ['role' => 'user',   'content' => $baseUser],
    ['role' => 'user',   'content' => 'Schema: {"outline":[{"index":1,"title":"...","sections":["..."]}]}']
  ];
  list($code, $resp, $err) = call_openrouter_outline($API_KEY, $model, $messages, $micro ? 900 : 1100, 0.3);

  if ($err) { $diags[] = "model:$model network:$err"; }
  $data = is_string($resp) ? json_decode($resp, true) : null;

  $content = $data['choices'][0]['message']['content'] ?? '';
  $finish  = $data['choices'][0]['finish_reason'] ?? '';
  if (!is_string($content)) $content = '';
  if ($content === '' || $finish === 'length') {
    // Retry attempt: stricter + compact + lower max_tokens to avoid hard cutoffs
    $messagesRetry = [
      ['role' => 'system', 'content' => $sys],
      ['role' => 'user',   'content' => $retryUser],
      ['role' => 'user',   'content' => 'Schema: {"outline":[{"index":1,"title":"...","sections":["..."]}]}']
    ];
    list($code2, $resp2, $err2) = call_openrouter_outline($API_KEY, $model, $messagesRetry, 800, 0.2);
    if ($err2) { $diags[] = "retry model:$model network:$err2"; }
    $data2 = is_string($resp2) ? json_decode($resp2, true) : null;
    $content2 = $data2['choices'][0]['message']['content'] ?? '';
    if (!is_string($content2)) $content2 = '';
    if ($content2 !== '') {
      $parsed = salvage_json_outline($content2);
      if ($parsed) { $finalOutline = $parsed; break; }
    }
  } else {
    // We have some content → try parse
    $parsed = salvage_json_outline($content);
    if ($parsed) { $finalOutline = $parsed; break; }
  }
}

// bail if still nothing
if (!$finalOutline) {
  out(502, [
    'ok'=>false,
    'error'=>'no_content_from_model',
    'details'=> (defined('DEBUG')&&DEBUG) ? $diags : null
  ]);
}

// accept {outline:[...]} or top-level array
$outlineIn = isset($finalOutline['outline']) && is_array($finalOutline['outline'])
  ? $finalOutline['outline'] : $finalOutline;

// ---------- normalize / clean for UI ----------
$outline     = [];
$seen        = [];
$maxChapters = $micro ? 10 : 16; // conservative caps to avoid truncation
$idx         = 1;

foreach ($outlineIn as $row) {
  if (!is_array($row)) continue;

  $title = trim((string)($row['title'] ?? ''));
  if ($title === '') continue;

  // de-dup by case-insensitive title
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

  // bound section lengths
  $sections = $micro ? array_slice($sections, 0, 5) : array_slice($sections, 0, 6);
  if (count($sections) < 2) $sections = array_pad($sections, 2, 'Overview');

  $outline[] = [
    'index'    => $idx++,
    'title'    => $title,
    'sections' => $sections
  ];

  if (count($outline) >= $maxChapters) break;
}

if (empty($outline)) {
  // Final safety net: synthesize a clean outline so the UI never breaks
  $outline = fallback_outline_for_topic($topic, $micro);
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
