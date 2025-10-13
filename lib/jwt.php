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

function jwt_sign(array $header, array $payload, string $secret): string {
    $h = b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', "$h.$p", $secret, true);
    $s = b64url_encode($signature);
    return "$h.$p.$s";
}

function jwt_parse(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new Exception('Invalid token');
    return $parts;
}

function jwt_verify(string $jwt, string $secret): array {
    [$h64, $p64, $s64] = jwt_parse($jwt);
    $header  = json_decode(b64url_decode($h64), true);
    $payload = json_decode(b64url_decode($p64), true);
    if (!is_array($header) || !is_array($payload)) throw new Exception('Invalid token json');

    $calc = b64url_encode(hash_hmac('sha256', "$h64.$p64", $secret, true));
    if (!hash_equals($calc, $s64)) throw new Exception('Bad signature');

    $now = time();
    if (isset($payload['nbf']) && $now < (int)$payload['nbf']) throw new Exception('Token not yet valid');
    if (isset($payload['iat']) && $now + 300 < (int)$payload['iat']) throw new Exception('Token iat in future');
    if (isset($payload['exp']) && $now >= (int)$payload['exp']) throw new Exception('Token expired');
    if (($payload['iss'] ?? '') !== JWT_ISSUER) throw new Exception('Bad iss');
    if (($payload['aud'] ?? '') !== JWT_AUDIENCE) throw new Exception('Bad aud');

    return $payload;
}

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
    return jwt_sign($header, $payload, JWT_SECRET);
}
