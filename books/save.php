<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php';  // require_auth()
require_once __DIR__ . '/../db.php';        // get_pdo()

$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false, 'error'=>'unauthorized']);
  exit;
}

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

/**
 * Expected body:
 * {
 *   "bookId": 123,                     // optional (if present => update)
 *   "title": "Mastering X",            // required
 *   "topic": "X",                      // required
 *   "style": "Practical",              // optional
 *   "level": "Beginner",               // optional
 *   "language": "English",             // optional
 *   "microMode": false,                // optional
 *   "outline": [                       // required, array of {index,title,sections?}
 *      {"index":1,"title":"Intro", "sections":["...","..."]},
 *      ...
 *   ],
 *   "publish": false                   // optional, if true -> lifecycle='published'
 * }
 */

$in        = body_json();
$bookId    = isset($in['bookId']) ? (int)$in['bookId'] : 0;
$title     = trim((string)($in['title'] ?? ''));
$topic     = trim((string)($in['topic'] ?? ''));
$style     = trim((string)($in['style'] ?? ''));
$level     = trim((string)($in['level'] ?? ''));
$language  = trim((string)($in['language'] ?? ''));
$micro     = !empty($in['microMode']);
$outlineIn = $in['outline'] ?? null;
$publish   = !empty($in['publish']);

$errors = [];
if ($title === '') $errors['title'] = 'title is required';
if ($topic === '') $errors['topic'] = 'topic is required';
if (!is_array($outlineIn) || empty($outlineIn)) $errors['outline'] = 'outline array is required';
if (!empty($errors)) out(422, ['ok'=>false, 'errors'=>$errors]);

// normalize outline (keep only index, title, sections[])
$outline = [];
$seenIdx = [];
foreach ($outlineIn as $row) {
  if (!is_array($row)) continue;
  $idx = (int)($row['index'] ?? 0);
  $ttl = trim((string)($row['title'] ?? ''));
  if ($idx <= 0 || $ttl === '' || isset($seenIdx[$idx])) continue;
  $seenIdx[$idx] = true;

  $sections = [];
  if (isset($row['sections']) && is_array($row['sections'])) {
    foreach ($row['sections'] as $s) {
      $s = trim((string)$s);
      if ($s !== '') $sections[] = $s;
    }
  }
  $outline[] = ['index'=>$idx, 'title'=>$ttl, 'sections'=>$sections];
}

// sort by index
usort($outline, fn($a,$b)=>$a['index']<=>$b['index']);

try {
  $pdo = get_pdo();
  $pdo->beginTransaction();

  // If updating, ensure ownership
  if ($bookId > 0) {
    $chk = $pdo->prepare('SELECT id, user_id, lifecycle FROM books WHERE id = :id LIMIT 1');
    $chk->execute([':id'=>$bookId]);
    $book = $chk->fetch();
    if (!$book || (int)$book['user_id'] !== $userId) {
      $pdo->rollBack();
      out(404, ['ok'=>false, 'error'=>'book not found']);
    }
  }

  $outlineJson = json_encode($outline, JSON_UNESCAPED_UNICODE);

  // Insert or update books row
  if ($bookId === 0) {
    $stmt = $pdo->prepare('INSERT INTO books
      (user_id, title, topic, style, level, language, micro_mode, outline_json, status, lifecycle, draft_expires_at, created_at, updated_at)
      VALUES
      (:uid, :title, :topic, :style, :level, :lang, :micro, :outline, "draft", "draft", (NOW() + INTERVAL 21 DAY), NOW(), NOW())');
    $stmt->execute([
      ':uid'=>$userId, ':title'=>$title, ':topic'=>$topic,
      ':style'=>$style ?: null, ':level'=>$level ?: null, ':lang'=>$language ?: null,
      ':micro'=>$micro ? 1 : 0, ':outline'=>$outlineJson
    ]);
    $bookId = (int)$pdo->lastInsertId();
  } else {
    // Keep lifecycle unless publish flag changes it
    $stmt = $pdo->prepare('UPDATE books
      SET title=:title, topic=:topic, style=:style, level=:level, language=:lang,
          micro_mode=:micro, outline_json=:outline, updated_at=NOW()
      WHERE id=:id AND user_id=:uid');
    $stmt->execute([
      ':title'=>$title, ':topic'=>$topic,
      ':style'=>$style ?: null, ':level'=>$level ?: null, ':lang'=>$language ?: null,
      ':micro'=>$micro ? 1 : 0, ':outline'=>$outlineJson,
      ':id'=>$bookId, ':uid'=>$userId
    ]);
  }

  // Upsert chapters
  // - For each incoming index: insert if missing, else update title (preserve content)
  // - Any existing pending chapters that are no longer in the outline are removed
  //   (ready chapters not in outline are kept to avoid accidental data loss)
  $existing = $pdo->prepare('SELECT id, chapter_index, title, status FROM book_chapters WHERE book_id=:bid');
  $existing->execute([':bid'=>$bookId]);
  $current = [];
  while ($row = $existing->fetch()) {
    $current[(int)$row['chapter_index']] = $row;
  }

  // NOTE: ensure your DB has `sections_json` JSON/LONGTEXT column on book_chapters
  $ins = $pdo->prepare('INSERT INTO book_chapters
    (book_id, chapter_index, title, sections_json, status, created_at, updated_at)
    VALUES (:bid, :idx, :title, :sections, "pending", NOW(), NOW())');

  $upd = $pdo->prepare('UPDATE book_chapters
    SET title=:title, sections_json=:sections, updated_at=NOW()
    WHERE book_id=:bid AND chapter_index=:idx');

  $seenIncomingIdx = [];
  $inserted=0; $updated=0; $kept=0; $removed=0;

  foreach ($outline as $c) {
    $idx = (int)$c['index'];
    $ttl = (string)$c['title'];
    $secs = isset($c['sections']) && is_array($c['sections']) ? $c['sections'] : [];
    $sectionsJson = json_encode($secs, JSON_UNESCAPED_UNICODE);

    // IMPORTANT: mark seen indices so we don't mistakenly delete them later
    $seenIncomingIdx[$idx] = true;

    if (!isset($current[$idx])) {
      $ins->execute([':bid'=>$bookId, ':idx'=>$idx, ':title'=>$ttl, ':sections'=>$sectionsJson]);
      $inserted++;
    } else {
      // Update title/sections; content/status preserved
      $upd->execute([':title'=>$ttl, ':sections'=>$sectionsJson, ':bid'=>$bookId, ':idx'=>$idx]);
      // Only count as updated if something actually changed is hard to detect without diffing;
      // we increment updated here for simplicity.
      $updated++;
    }
  }

  // remove orphaned pending chapters (indices no longer present)
  foreach ($current as $idx => $row) {
    if (!isset($seenIncomingIdx[$idx])) {
      if ((string)$row['status'] === 'pending') {
        $del = $pdo->prepare('DELETE FROM book_chapters WHERE id=:id LIMIT 1');
        $del->execute([':id'=>$row['id']]);
        $removed++;
      } else {
        // keep 'ready' chapters not in outline to avoid losing authored/generated content
        $kept++;
      }
    } else {
      // those that existed and remain are "kept" if not counted above
      // (we already incremented $updated for all matched chapters; if you prefer strict counts,
      // you can compute diffs and set $kept accordingly)
    }
  }

  // handle publish flag
  if ($publish) {
    $pub = $pdo->prepare('UPDATE books SET lifecycle="published", published_at=NOW(), updated_at=NOW() WHERE id=:id AND user_id=:uid');
    $pub->execute([':id'=>$bookId, ':uid'=>$userId]);
    $lifecycle = 'published';
  } else {
    // ensure lifecycle not accidentally unset
    $lif = $pdo->prepare('SELECT lifecycle FROM books WHERE id=:id');
    $lif->execute([':id'=>$bookId]);
    $lifecycle = (string)($lif->fetchColumn() ?: 'draft');
  }

  $pdo->commit();

  out(200, [
    'ok' => true,
    'book' => [
      'id'        => $bookId,
      'title'     => $title,
      'topic'     => $topic,
      'style'     => $style ?: null,
      'level'     => $level ?: null,
      'language'  => $language ?: null,
      'microMode' => $micro,
      'lifecycle' => $lifecycle
    ],
    'chapters' => [
      'inserted' => $inserted,
      'updated'  => $updated,
      'kept'     => $kept,
      'removed'  => $removed
    ]
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
