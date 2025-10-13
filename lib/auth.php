<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/jwt.php';

function get_bearer_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    // Fallback for servers that pass it differently
    $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    return null;
}

function require_auth(): array {
    header('Content-Type: application/json; charset=utf-8');
    $tok = get_bearer_token();
    if (!$tok) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'missing bearer token']);
        exit;
    }
    try {
        $claims = jwt_verify($tok, JWT_SECRET);
        return $claims; // includes 'sub' as user id
    } catch (Throwable $e) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'invalid token']);
        exit;
    }
}
