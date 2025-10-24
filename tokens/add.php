<?php
declare(strict_types=1);

/**
 * POST /api/tokens/add.php
 * - Authenticated users can add tokens to themselves.
 * - Admins can add tokens to any user via ?userId or JSON body userId.
 *
 * Body JSON:
 * {
 *   "userId": 123,            // optional; defaults to authenticated user's id
 *   "amount": 20,             // required, positive integer
 *   "reason": "purchase",     // optional (<=64), default "user_add"
 *   "kind": "bonus"           // optional (<=32), default "bonus"
 * }
 *
 * Response 200:
 * {
 *   "ok": true,
 *   "applied": { "userId": 123, "amount": 20, "reason": "...", "kind": "...", "actor": { "type": "user|admin", "id": 7 } },
 *   "balance": 140
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
require_once __DIR__ . '/../lib/tokens.php';

/* ---------- helpers ---------- */

function json_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_authorization_header(): ?string {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) if (strtolower($k) === 'authorization') return $v;
    }
    return null;
}

function base64_urlsafe_decode(string $s): ?string {
    $r = strlen($s) % 4;
    if ($r) $s .= str_repeat('=', 4 - $r);
    $s = strtr($s, '-_', '+/');
    $out = base64_decode($s, true);
    return $out === false ? null : $out;
}

function jwt_decode_hs256(string $jwt, string $secret): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h64, $p64, $s64] = $parts;

    $header  = json_decode(base64_urlsafe_decode($h64) ?? '', true);
    $payload = json_decode(base64_urlsafe_decode($p64) ?? '', true);
    $sig     = base64_urlsafe_decode($s64);

    if (!is_array($header) || !is_array($payload) || $sig === null) return null;
    if (($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT') return null;

    $calc = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
    if (!hash_equals($calc, $sig)) return null;

    $now = time();
    if (isset($payload['nbf']) && $now < (int)$payload['nbf']) return null;
    if (isset($payload['exp']) && $now >= (int)$payload['exp']) return null;

    return $payload;
}

/** Require auth via Bearer; returns [userId:int, role:?string, payload:array] */
function require_auth(): array {
    $auth = get_authorization_header();
    if (!$auth || stripos($auth, 'Bearer ') !== 0) json_out(401, ['ok' => false, 'error' => 'missing_or_invalid_authorization']);
    if (!defined('JWT_SECRET') || JWT_SECRET === '') json_out(500, ['ok' => false, 'error' => 'jwt_secret_not_configured']);

    $jwt = trim(substr($auth, 7));
    $payload = jwt_decode_hs256($jwt, JWT_SECRET);
    if (!$payload) json_out(401, ['ok' => false, 'error' => 'invalid_token']);

    $sub  = $payload['sub'] ?? null;
    $role = $payload['role'] ?? null;
    if ($sub === null || !ctype_digit((string)$sub)) json_out(401, ['ok' => false, 'error' => 'invalid_token_subject']);
    return [(int)$sub, is_string($role) ? $role : null, $payload];
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* ---------- endpoint ---------- */

try {
    [$authUserId, $authRole] = require_auth();

    $input       = body_json();
    // allow userId via querystring or body; default to self
    $userId      = isset($_GET['userId']) ? (int)$_GET['userId'] : (int)($input['userId'] ?? $authUserId);
    $amount      = isset($input['amount']) ? (int)$input['amount'] : 0;
    $reason      = trim((string)($input['reason'] ?? 'user_add'));
    $kind        = trim((string)($input['kind']   ?? 'bonus'));

    // Permission: users can only add to themselves; admins can add to anyone
    $isSelf      = ($userId === $authUserId);
    $isAdmin     = ($authRole === 'admin');
    if (!$isSelf && !$isAdmin) {
        json_out(403, ['ok' => false, 'error' => 'forbidden_target']);
    }

    // Basic validation
    $errors = [];
    if ($userId <= 0) $errors['userId'] = 'userId must be a positive integer';
    if ($amount <= 0) $errors['amount'] = 'amount must be a positive integer';
    if ($reason === '' || strlen($reason) > 64) $errors['reason'] = 'reason is required (<= 64 chars)';
    if ($kind === ''   || strlen($kind)   > 32) $errors['kind']   = 'kind is required (<= 32 chars)';

    // Optional guardrails to prevent abuse (tweak/remove as needed)
    // e.g., limit self-credits to <= 1000 per request
    if ($isSelf && $amount > 1000) $errors['amount'] = 'amount too large for self-credits';

    if ($errors) json_out(422, ['ok' => false, 'errors' => $errors]);

    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure target user exists
    $chk = $pdo->prepare('SELECT id FROM users WHERE id = :u LIMIT 1');
    $chk->execute([':u' => $userId]);
    if (!$chk->fetchColumn()) json_out(404, ['ok' => false, 'error' => 'user_not_found']);

    // Apply credit atomically
    $pdo->beginTransaction();
    try {
        $actorType = $isAdmin ? 'admin' : 'user';
        $actorId   = $authUserId;

        $ok = add_tokens(
            $pdo,
            $userId,
            $amount,
            $reason,
            $actorType,
            $actorId,
            $kind
        );

        if (!$ok) {
            $pdo->rollBack();
            json_out(500, ['ok' => false, 'error' => 'add_tokens_failed']);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = (defined('DEBUG') && DEBUG)
            ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
            : 'server error';
        json_out(500, ['ok' => false, 'error' => $msg]);
    }

    // Fetch updated balance
    $balance = get_token_balance($pdo, $userId);
    if ($balance === null) $balance = 0;

    json_out(200, [
        'ok' => true,
        'applied' => [
            'userId' => $userId,
            'amount' => $amount,
            'reason' => $reason,
            'kind'   => $kind,
            'actor'  => ['type' => $isAdmin ? 'admin' : 'user', 'id' => $authUserId],
        ],
        'balance' => (int)$balance,
    ]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}
