<?php
declare(strict_types=1);

/**
 * POST /api/auth/logout.php
 *
 * Acknowledges a logout request. In a stateless JWT system, the primary
 * responsibility for "logging out" (i.e., deleting the token) lies with the client.
 *
 * This endpoint authenticates the request to confirm who is logging out,
 * which can be used for logging or for advanced token invalidation strategies
 * (like a denylist), though none are implemented by default here.
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

/** ---------- Helpers (copied from other endpoints for consistency) ---------- */

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
    $remainder = strlen($s) % 4;
    if ($remainder) $s .= str_repeat('=', 4 - $remainder);
    $s = strtr($s, '-_', '+/');
    $out = base64_decode($s, true);
    return ($out === false) ? null : $out;
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

    $data = $h64 . '.' . $p64;
    $calc = hash_hmac('sha256', $data, $secret, true);
    if (!hash_equals($calc, $sig)) return null;

    // Note: We don't check 'exp' here because a user might be logging out
    // with an expired token. The goal is just to acknowledge the action.
    return $payload;
}

/** ---------- Endpoint Logic ---------- */

$auth = get_authorization_header();
if (!$auth || stripos($auth, 'Bearer ') !== 0) {
    json_out(401, ['ok' => false, 'error' => 'missing_authorization_header']);
}

// No action is needed on the server for a simple stateless logout.
// The client is responsible for destroying the token.
json_out(200, ['ok' => true, 'message' => 'logout_successful']);