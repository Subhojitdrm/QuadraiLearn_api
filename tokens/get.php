<?php
declare(strict_types=1);

/**
 * GET /api/tokens/get.php
 * Returns token balance (and optional ledger) for the authenticated user.
 * Admins may query other users via ?userId=.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/tokens.php'; // expects get_token_balance(...) as provided

/** ---------- Helpers ---------- */

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

/**
 * Minimal HS256 JWT decode/verify (no lib required)
 * Requires: define('JWT_SECRET', '...') in config.php
 * Returns payload array on success, null on failure.
 */
function jwt_decode_hs256(string $jwt, string $secret): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h64, $p64, $s64] = $parts;

    $header  = json_decode(base64_urlsafe_decode($h64) ?? '', true);
    $payload = json_decode(base64_urlsafe_decode($p64) ?? '', true);
    $sig     = base64_urlsafe_decode($s64);

    if (!is_array($header) || !is_array($payload) || $sig === null) return null;
    if (($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT') return null;

    $data = $h64 . '.' . $p64;
    $calc = hash_hmac('sha256', $data, $secret, true);
    if (!hash_equals($calc, $sig)) return null;

    // exp/nbf checks (optional but recommended)
    $now = time();
    if (isset($payload['nbf']) && $now < (int)$payload['nbf']) return null;
    if (isset($payload['exp']) && $now >= (int)$payload['exp']) return null;

    return $payload;
}

function base64_urlsafe_decode(string $s): ?string {
    $remainder = strlen($s) % 4;
    if ($remainder) $s .= str_repeat('=', 4 - $remainder);
    $s = strtr($s, '-_', '+/');
    $out = base64_decode($s, true);
    return ($out === false) ? null : $out;
}

/** Require auth via Bearer token; returns [userId:int, role:string|null, payload:array] */
function require_auth(): array {
    $auth = get_authorization_header();
    if (!$auth || stripos($auth, 'Bearer ') !== 0) {
        json_out(401, ['ok' => false, 'error' => 'missing_or_invalid_authorization']);
    }
    $jwt = trim(substr($auth, 7));
    if (!defined('JWT_SECRET') || JWT_SECRET === '') {
        json_out(500, ['ok' => false, 'error' => 'jwt_secret_not_configured']);
    }
    $payload = jwt_decode_hs256($jwt, JWT_SECRET);
    if (!$payload) {
        json_out(401, ['ok' => false, 'error' => 'invalid_token']);
    }
    $sub = $payload['sub'] ?? null;
    if ($sub === null || !ctype_digit((string)$sub)) {
        json_out(401, ['ok' => false, 'error' => 'invalid_token_subject']);
    }
    $role = $payload['role'] ?? null; // e.g., 'admin'
    return [(int)$sub, is_string($role) ? $role : null, $payload];
}

/** ---------- Endpoint logic ---------- */

try {
    [$authUserId, $authRole] = require_auth();

    // Parse query params
    $queryUserId = isset($_GET['userId']) ? (int)$_GET['userId'] : $authUserId;
    $includeLedger = isset($_GET['includeLedger']) && ($_GET['includeLedger'] === '1' || strtolower($_GET['includeLedger']) === 'true');

    // Only admins may view others' balances
    if ($queryUserId !== $authUserId && $authRole !== 'admin') {
        json_out(403, ['ok' => false, 'error' => 'forbidden']);
    }

    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verify user exists (prevents FK surprises)
    $chk = $pdo->prepare('SELECT id, username, email, first_name, last_name FROM users WHERE id = :u LIMIT 1');
    $chk->execute([':u' => $queryUserId]);
    $userRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        json_out(404, ['ok' => false, 'error' => 'user_not_found']);
    }

    // Current balance (helper from lib/tokens.php)
    $balance = get_token_balance($pdo, $queryUserId);
    if ($balance === null) {
        // If no row yet, treat as 0 balance
        $balance = 0;
    }

    $resp = [
        'ok' => true,
        'user' => [
            'id'        => (int)$userRow['id'],
            'username'  => $userRow['username'],
            'email'     => $userRow['email'],
            'firstName' => $userRow['first_name'],
            'lastName'  => $userRow['last_name'],
        ],
        'balance' => (int)$balance,
    ];

    if ($includeLedger) {
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 25)));
        $offset   = ($page - 1) * $pageSize;

        // Count total
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM token_ledger WHERE user_id = :u');
        $cnt->execute([':u' => $queryUserId]);
        $totalRows = (int)$cnt->fetchColumn();

        // Fetch page
        $led = $pdo->prepare(
            'SELECT id, delta, reason, actor_type, actor_id, kind, created_at
             FROM token_ledger
             WHERE user_id = :u
             ORDER BY created_at DESC, id DESC
             LIMIT :lim OFFSET :off'
        );
        $led->bindValue(':u',   $queryUserId, PDO::PARAM_INT);
        $led->bindValue(':lim', $pageSize,    PDO::PARAM_INT);
        $led->bindValue(':off', $offset,      PDO::PARAM_INT);
        $led->execute();
        $rows = $led->fetchAll(PDO::FETCH_ASSOC);

        $resp['ledger'] = [
            'page'      => $page,
            'pageSize'  => $pageSize,
            'totalRows' => $totalRows,
            'rows'      => array_map(static function(array $r): array {
                return [
                    'id'         => (int)$r['id'],
                    'delta'      => (int)$r['delta'],
                    'reason'     => $r['reason'],
                    'actorType'  => $r['actor_type'],
                    'actorId'    => (int)$r['actor_id'],
                    'kind'       => $r['kind'],
                    'createdAt'  => $r['created_at'],
                ];
            }, $rows),
        ];
    }

    json_out(200, $resp);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}
