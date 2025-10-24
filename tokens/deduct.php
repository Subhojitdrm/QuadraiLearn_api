<?php
declare(strict_types=1);

/**
 * POST /api/tokens/deduct.php
 * - Authenticated users can deduct tokens from themselves.
 * - Admins can deduct tokens from any user via userId.
 *
 * Body JSON:
 * {
 *   "userId": 123,              // optional; defaults to authenticated user's id
 *   "amount": 15,               // required, positive integer
 *   "reason": "chapter_usage",  // optional (<=64), default "user_spend"
 *   "kind": "spend"             // optional (<=32), default "spend"
 * }
 *
 * Responses:
 * 200 OK:
 * {
 *   "ok": true,
 *   "applied": { "userId": 7, "amount": 15, "reason": "chapter_usage", "kind": "spend",
 *                "actor": {"type":"user|admin","id":7} },
 *   "balance": 85
 * }
 *
 * 422 (insufficient funds):
 * { "ok": false, "error": "insufficient_funds", "balance": 10, "requested": 15 }
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
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') return $v;
        }
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
    if (!$auth || stripos($auth, 'Bearer ') !== 0) {
        json_out(401, ['ok' => false, 'error' => 'missing_or_invalid_authorization']);
    }
    if (!defined('JWT_SECRET') || JWT_SECRET === '') {
        json_out(500, ['ok' => false, 'error' => 'jwt_secret_not_configured']);
    }
    $jwt = trim(substr($auth, 7));
    $payload = jwt_decode_hs256($jwt, JWT_SECRET);
    if (!$payload) json_out(401, ['ok' => false, 'error' => 'invalid_token']);

    $sub  = $payload['sub'] ?? null;
    $role = $payload['role'] ?? null;
    if ($sub === null || !ctype_digit((string)$sub)) {
        json_out(401, ['ok' => false, 'error' => 'invalid_token_subject']);
    }
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

    $input  = body_json();
    // Allow target via query or body; default to self
    $userId = isset($_GET['userId']) ? (int)$_GET['userId'] : (int)($input['userId'] ?? $authUserId);
    $amount = isset($input['amount']) ? (int)$input['amount'] : 0;
    $reason = trim((string)($input['reason'] ?? 'user_spend'));
    $kind   = trim((string)($input['kind']   ?? 'spend'));

    // Permissions: users can deduct only from self; admins from anyone
    $isSelf  = ($userId === $authUserId);
    $isAdmin = ($authRole === 'admin');
    if (!$isSelf && !$isAdmin) {
        json_out(403, ['ok' => false, 'error' => 'forbidden_target']);
    }

    // Validate
    $errors = [];
    if ($userId <= 0) $errors['userId'] = 'userId must be a positive integer';
    if ($amount <= 0) $errors['amount'] = 'amount must be a positive integer';
    if ($reason === '' || strlen($reason) > 64) $errors['reason'] = 'reason is required (<= 64 chars)';
    if ($kind === ''   || strlen($kind)   > 32) $errors['kind']   = 'kind is required (<= 32 chars)';
    if ($errors) json_out(422, ['ok' => false, 'errors' => $errors]);

    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure user exists
    $chk = $pdo->prepare('SELECT id FROM users WHERE id = :u LIMIT 1');
    $chk->execute([':u' => $userId]);
    if (!$chk->fetchColumn()) {
        json_out(404, ['ok' => false, 'error' => 'user_not_found']);
    }

    // Atomic spend with row lock to prevent races
    $pdo->beginTransaction();
    try {
        // Ensure a balance row exists (upsert), then lock it
        $ins = $pdo->prepare(
            'INSERT INTO token_balances (user_id, balance)
             VALUES (:u, 0)
             ON DUPLICATE KEY UPDATE balance = balance'
        );
        $ins->execute([':u' => $userId]);

        // Lock the balance row
        $sel = $pdo->prepare('SELECT balance FROM token_balances WHERE user_id = :u FOR UPDATE');
        $sel->execute([':u' => $userId]);
        $current = (int)$sel->fetchColumn();

        if ($amount > $current) {
            // Not enough tokens → do not change anything
            $pdo->rollBack();
            json_out(422, [
                'ok' => false,
                'error' => 'insufficient_funds',
                'balance' => $current,
                'requested' => $amount
            ]);
        }

        // Perform deduction
        $upd = $pdo->prepare('UPDATE token_balances SET balance = balance - :a WHERE user_id = :u');
        $upd->execute([':a' => $amount, ':u' => $userId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            json_out(500, ['ok' => false, 'error' => 'deduct_failed']);
        }

        // Ledger entry
        $led = $pdo->prepare(
            'INSERT INTO token_ledger
               (user_id, delta, reason, actor_type, actor_id, kind, created_at)
             VALUES
               (:u, :d, :r, :at, :aid, :k, NOW())'
        );
        $led->execute([
            ':u'   => $userId,
            ':d'   => -$amount,                    // negative delta for spend
            ':r'   => $reason,
            ':at'  => $isAdmin ? 'admin' : 'user', // actor type
            ':aid' => $authUserId,                 // actor id
            ':k'   => $kind
        ]);

        $pdo->commit();

        // Return updated balance
        $balStmt = $pdo->prepare('SELECT balance FROM token_balances WHERE user_id = :u');
        $balStmt->execute([':u' => $userId]);
        $newBalance = (int)$balStmt->fetchColumn();

        json_out(200, [
            'ok' => true,
            'applied' => [
                'userId' => $userId,
                'amount' => $amount,
                'reason' => $reason,
                'kind'   => $kind,
                'actor'  => ['type' => $isAdmin ? 'admin' : 'user', 'id' => $authUserId],
            ],
            'balance' => $newBalance
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = (defined('DEBUG') && DEBUG)
            ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
            : 'server error';
        json_out(500, ['ok' => false, 'error' => $msg]);
    }

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}
