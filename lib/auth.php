<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/jwt.php';

function b64url_decode_strict(string $data): array {
    $parts = explode('.', $data);
    if (count($parts) !== 3) throw new Exception('Invalid token');
    [$h64, $p64, $s64] = $parts;
    $calc = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h64.$p64", JWT_SECRET, true)), '+/', '-_'), '=');
    if (!hash_equals($calc, $s64)) throw new Exception('Bad signature');
    $payload = json_decode((function($d){$pad=4-(strlen($d)%4); if($pad<4)$d.=str_repeat('=',$pad); return base64_decode(strtr($d,'-_','+/'));})($p64), true);
    if (!is_array($payload)) throw new Exception('Bad payload');
    $now = time();
    if (($payload['iss'] ?? '') !== JWT_ISSUER) throw new Exception('Bad iss');
    if (($payload['aud'] ?? '') !== JWT_AUDIENCE) throw new Exception('Bad aud');
    if (isset($payload['nbf']) && $now < (int)$payload['nbf']) throw new Exception('nbf');
    if (isset($payload['exp']) && $now >= (int)$payload['exp']) throw new Exception('exp');
    return $payload;
}
function require_auth(): array {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') !== 0) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'missing bearer token']);
        exit;
    }
    $jwt = trim(substr($hdr, 7));
    try { return b64url_decode_strict($jwt); }
    catch (Throwable $e) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'invalid token']);
        exit;
    }
}
