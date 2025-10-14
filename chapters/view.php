<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../lib/auth.php'; // require_auth()
require_once __DIR__ . '/../db.php';       // get_pdo()

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}

// ---- auth ----
$claims = require_auth();
$userId = (int)($claims['sub'] ?? 0);
if ($userId <= 0) out(401, ['ok'=>false, 'error'=>'unauthorized']);

/**
 * Fetch a single chapter with all its subsection contents.
 *
 * Accepts either:
 *   GET /api/chapters/view.php?chapterId=456
 * or
 *   GET /api/chapters/view.php?bookId=123&chapterIndex=2
 *
 * Optional:
 *   &includeEmpty=1   -> include outline sections even if content not generated
 */
$chapterId    = (int)($_GET['chapterId'] ?? 0);
$bookIdParam  = (int)($_GET['bookId'] ?? 0);
$chapterIndex = (int)($_GET['chapterIndex'] ?? 0);
$includeEmpty = isset($_GET['includeEmpty']) && (int)$_GET['includeEmpty'] === 1;

try {
  $pdo = get_pdo();

  // Resolve chapter + ensure ownership
  if ($chapterId > 0) {
    $c = $pdo->prepare('
      SELECT bc.id, bc.book_id, bc.chapter_index, bc.title, bc.sections_json
      FROM book_chapters bc
      JOIN books b ON b.id = bc.book_id
      WHERE bc.id = :cid AND b.user_id = :uid
      LIMIT 1
    ');
    $c->execute([':cid'=>$chapterId, ':uid'=>$userId]);
  } else {
    if ($bookIdParam <= 0 || $chapterIndex <= 0) {
      out(422, ['ok'=>false, 'error'=>'Provide chapterId OR (bookId and chapterIndex)']);
    }
    $c = $pdo->prepare('
      SELECT bc.id, bc.book_id, bc.chapter_index, bc.title, bc.sections_json
      FROM book_chapters bc
      JOIN books b ON b.id = bc.book_id
      WHERE bc.book_id = :bid AND bc.chapter_index = :cidx AND b.user_id = :uid
      LIMIT 1
    ');
    $c->execute([':bid'=>$bookIdParam, ':cidx'=>$chapterIndex, ':uid'=>$userId]);
  }

  $chapter = $c->fetch();
  if (!$chapter) out(404, ['ok'=>false, 'error'=>'chapter not found']);

  $bookId       = (int)$chapter['book_id'];
  $chapterIndex = (int)$chapter['chapter_index'];
  $chapterTitle = (string)$chapter['title'];

  // Outline sections from sections_json (array of titles)
  $outlineTitles = [];
  if (!empty($chapter['sections_json'])) {
    $tmp = json_decode((string)$chapter['sections_json'], true);
    if (is_array($tmp)) {
      // Normalize & index from 1
      $i = 1;
      foreach ($tmp as $t) {
        $t = is_string($t) ? trim($t) : '';
        if ($t !== '') $outlineTitles[$i++] = $t;
      }
    }
  }

  // Pull generated subsections content
  $s = $pdo->prepare('
    SELECT id, section_index, section_title, content_md, status, word_count, updated_at
    FROM chapter_sections
    WHERE book_id = :bid AND chapter_index = :cidx
    ORDER BY section_index ASC
  ');
  $s->execute([':bid'=>$bookId, ':cidx'=>$chapterIndex]);

  $generated = []; // section_index => row
  while ($row = $s->fetch()) {
    $generated[(int)$row['section_index']] = [
      'id'          => (int)$row['id'],
      'section_idx' => (int)$row['section_index'],
      'title'       => (string)$row['section_title'],
      'content_md'  => (string)($row['content_md'] ?? ''),
      'status'      => (string)$row['status'],
      'word_count'  => isset($row['word_count']) ? (int)$row['word_count'] : null,
      'updated_at'  => (string)$row['updated_at']
    ];
  }

  // Merge: decide final list of sections
  $sections = [];
  $maxIndex = 0;

  // Use outline order primarily (if present)
  if (!empty($outlineTitles)) {
    foreach ($outlineTitles as $idx => $title) {
      $maxIndex = max($maxIndex, $idx);
      if (isset($generated[$idx])) {
        // prefer generated title/content; keep outline title as fallback title if needed
        $row = $generated[$idx];
        if ($row['title'] === '' && $title !== '') $row['title'] = $title;
        $sections[] = $row;
      } else if ($includeEmpty) {
        $sections[] = [
          'id'          => null,
          'section_idx' => (int)$idx,
          'title'       => $title,
          'content_md'  => '',
          'status'      => 'empty',
          'word_count'  => null,
          'updated_at'  => null
        ];
      }
    }
  }

  // If there are generated sections beyond outline (or no outline at all), append them
  if (empty($outlineTitles)) {
    // just output what we have
    foreach ($generated as $idx => $row) {
      $sections[] = $row;
      $maxIndex = max($maxIndex, $idx);
    }
  } else {
    // add generated not represented in outline
    foreach ($generated as $idx => $row) {
      if (!isset($outlineTitles[$idx])) {
        $sections[] = $row;
        $maxIndex = max($maxIndex, $idx);
      }
    }
    // sort by section_idx
    usort($sections, fn($a,$b)=>$a['section_idx'] <=> $b['section_idx']);
  }

  // Build compiled markdown (by default only generated sections; include empty headings if includeEmpty=1)
  $compiled = "# Chapter {$chapterIndex}: {$chapterTitle}\n\n";
  foreach ($sections as $sec) {
    $title = $sec['title'] ?: "Section {$sec['section_idx']}";
    $compiled .= "## {$title}\n\n";
    if ($sec['content_md'] !== '') {
      $compiled .= rtrim($sec['content_md']) . "\n\n";
    } else if (!$includeEmpty) {
      // remove the empty heading we just appended when not including empties
      $compiled = preg_replace('/##\s.*\n\n$/', '', $compiled, 1);
    }
  }

  out(200, [
    'ok' => true,
    'chapter' => [
      'id'            => (int)$chapter['id'],
      'book_id'       => $bookId,
      'chapter_index' => $chapterIndex,
      'title'         => $chapterTitle
    ],
    'sections'    => $sections,
    'compiled_md' => $compiled
  ]);

} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>(defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server error']);
}
