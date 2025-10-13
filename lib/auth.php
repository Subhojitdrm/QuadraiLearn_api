<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/jwt.php';

function get_bearer_token(): ?string {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if (stripos($hdr, 'Bearer ') === 0) return trim(substr($hdr, 7));
  return null;
}

function b64url_decode_strict(string $data): array {
  $parts = explode('.', $data);
  if (count($parts) !== 3) throw new Exception('Invalid token');
  [$h64, $p64, $s64] = $parts;
  $calc = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h64.$p64", JWT_SECRET, true)), '+/', '-_'), '=');
  if (!hash_equals($calc, $s64)) throw new Exception('Bad signature');
  $pad = 4 - (strlen($p64) % 4); if ($pad < 4) $p64 .= str_repeat('=', $pad);
  $payload = json_decode(strtr(base64_decode(strtr($p64, '-_', '+/')) ?: '', "\0", ''), true);
  if (!is_array($payload)) throw new Exception('Bad payload');
  $now = time();
  if (($payload['iss'] ?? '') !== JWT_ISSUER) throw new Exception('Bad iss');
  if (($payload['aud'] ?? '') !== JWT_AUDIENCE) throw new Exception('Bad aud');
  if (isset($payload['nbf']) && $now < (int)$payload['nbf']) throw new Exception('nbf');
  if (isset($payload['exp']) && $now >= (int)$payload['exp']) throw new Exception('exp');
  return $payload;
}

function require_auth(): array {
  header('Content-Type: application/json; charset=utf-8');
  $tok = get_bearer_token();
  if (!$tok) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'missing bearer token']); exit; }
  try { return b64url_decode_strict($tok); }
  catch (Throwable $e) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'invalid token']); exit; }
}
