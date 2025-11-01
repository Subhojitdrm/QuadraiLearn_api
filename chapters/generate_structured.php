<?php
declare(strict_types=1);

/**
 * POST /api/chapters/generate_structured.php
 *
 * Generates content for a specific chapter using an AI model and saves it
 * to the database. This endpoint is the content-generation counterpart for
 * the new structured book format.
 *
 * JSON Body:
 * {
 *   "chapterId": 456
 * }
 *
 * Response 200 (OK):
 * {
 *   "ok": true,
 *   "message": "Chapter content generated and saved successfully.",
 *   "chapter": {
 *     "id": 456,
 *     "status": "ready",
 *     "wordCount": 850
 *   },
 *   "content": "The full generated markdown content..."
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/tokens.php';

// --- Helpers ---

function json_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function http_post_json(string $url, array $headers, array $payload, int $timeout = 120): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $resp, $err];
}

// --- Endpoint Logic ---

try {
    // 1. Authenticate user
    $claims = require_auth();
    $userId = (int)($claims['sub'] ?? 0);
    if ($userId <= 0) {
        json_out(401, ['ok' => false, 'error' => 'invalid_token_subject']);
    }

    // 2. Get and validate input
    $input = body_json();
    $chapterId = (int)($input['chapterId'] ?? 0);
    if ($chapterId <= 0) {
        json_out(422, ['ok' => false, 'error' => 'A valid chapterId is required.']);
    }

    $pdo = get_pdo();

    // 3. Fetch chapter, book, and subchapters, and verify ownership
    $stmt = $pdo->prepare(
        'SELECT b.topic, b.purpose, b.language, c.title AS chapter_title
         FROM chapters c
         JOIN books b ON c.book_id = b.id
         WHERE c.id = :cid AND b.user_id = :uid
         LIMIT 1'
    );
    $stmt->execute([':cid' => $chapterId, ':uid' => $userId]);
    $data = $stmt->fetch();

    if (!$data) {
        json_out(404, ['ok' => false, 'error' => 'Chapter not found or you do not have permission.']);
    }

    $subchapterStmt = $pdo->prepare('SELECT title FROM subchapters WHERE chapter_id = :cid ORDER BY subchapter_index ASC');
    $subchapterStmt->execute([':cid' => $chapterId]);
    $subchapters = $subchapterStmt->fetchAll(PDO::FETCH_COLUMN);

    // 4. Deduct tokens for generation
    $tokenCost = defined('TOKEN_COST_GENERATE_CHAPTER') ? TOKEN_COST_GENERATE_CHAPTER : 5;
    if (!deduct_tokens($pdo, $userId, $tokenCost, 'generate_chapter_content', $chapterId)) {
        json_out(402, ['ok' => false, 'error' => 'insufficient_tokens', 'required' => $tokenCost]);
    }

    // 5. Construct the AI prompt
    $subchapterList = '';
    if (!empty($subchapters)) {
        $subchapterList = "The chapter must be broken down into these sub-headings:\n- " . implode("\n- ", $subchapters);
    }

    $prompt = "You are an expert author. Write the full content for the following book chapter in clear, engaging, and well-structured markdown.\n\n"
        . "Book Topic: {$data['topic']}\n"
        . "Chapter Title: {$data['chapter_title']}\n"
        . ($data['purpose'] ? "Writing Purpose: {$data['purpose']}\n" : "")
        . ($data['language'] ? "Language: {$data['language']}\n" : "")
        . "\n"
        . $subchapterList . "\n\n"
        . "Rules:\n"
        . "- The output must be markdown text only.\n"
        . "- Start directly with the content. Do not add any commentary before or after.\n"
        . "- Use headings, paragraphs, lists, and bold text to create a rich, readable document.\n"
        . "- Aim for a comprehensive and educational tone, suitable for the book's topic and purpose.\n"
        . "- Ensure the content is at least 800 words long.";

    // 6. Call the AI model
    $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
    if ($apiKey === '') {
        json_out(500, ['ok' => false, 'error' => 'api_key_not_configured']);
    }

    list($code, $resp, $err) = http_post_json(
        'https://openrouter.ai/api/v1/chat/completions',
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        [
            'model' => 'meta/llama-3.1-70b-instruct', // A powerful model for content generation
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.5,
            'max_tokens' => 4000,
        ]
    );

    if ($err || $code >= 400) {
        json_out(502, ['ok' => false, 'error' => 'ai_model_failed', 'details' => $err ?: $resp]);
    }

    $aiResponse = json_decode($resp, true);
    $content = $aiResponse['choices'][0]['message']['content'] ?? '';

    if (trim($content) === '') {
        json_out(502, ['ok' => false, 'error' => 'ai_model_returned_empty_content']);
    }

    // 7. Save content to the database
    $updateStmt = $pdo->prepare(
        'UPDATE chapters SET generated_content = :content, status = "ready", updated_at = NOW() WHERE id = :cid'
    );
    $updateStmt->execute([':content' => $content, ':cid' => $chapterId]);

    if ($updateStmt->rowCount() === 0) {
        json_out(500, ['ok' => false, 'error' => 'database_update_failed']);
    }

    // 8. Return success response
    json_out(200, [
        'ok' => true,
        'message' => 'Chapter content generated and saved successfully.',
        'chapter' => [
            'id' => $chapterId,
            'status' => 'ready',
            'wordCount' => str_word_count($content),
        ],
        'content' => $content,
    ]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG) ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : 'server_error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}