<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function b64url_decode(string $data): string {
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) $data .= str_repeat('=', $pad);
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}
/** Issue HS256 JWT */
function jwt_issue(int $userId, array $extra = []): string {
    $now = time();
    $payload = array_merge([
        'iss' => JWT_ISSUER,
        'aud' => JWT_AUDIENCE,
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + JWT_TTL,
        'jti' => bin2hex(random_bytes(12)),
        'sub' => (string)$userId,
    ], $extra);
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $h = b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $s = b64url_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    return "$h.$p.$s";
}
