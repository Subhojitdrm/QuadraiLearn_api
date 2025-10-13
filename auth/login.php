<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/jwt.php';

function json_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
function body_json(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$in = body_json();
$identifier = trim((string)($in['identifier'] ?? ''));
$password   = (string)($in['password'] ?? '');

if ($identifier === '' || $password === '') {
    json_out(422, ['ok'=>false,'error'=>'identifier and password are required']);
}

try {
    foreach (['JWT_SECRET','JWT_ISSUER','JWT_AUDIENCE','JWT_TTL'] as $c) {
        if (!defined($c) || constant($c) === '') throw new RuntimeException("$c is not defined");
    }

    $pdo = get_pdo();

    // ✅ FIX: distinct named params
    $stmt = $pdo->prepare(
        'SELECT id, email, username, password_hash
         FROM users
         WHERE email = :e OR username = :u
         LIMIT 1'
    );
    $stmt->execute([':e' => $identifier, ':u' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        json_out(401, ['ok'=>false,'error'=>'invalid credentials']);
    }

    // Fallback for schemas that used `password` column name
    if (!isset($user['password_hash'])) {
        $stmt = $pdo->prepare('SELECT id, email, username, password AS password_hash FROM users WHERE id = :uid LIMIT 1');
        $stmt->execute([':uid' => (int)$user['id']]);
        $user = $stmt->fetch() ?: $user;
    }

    if (!password_verify($password, (string)$user['password_hash'])) {
        json_out(401, ['ok'=>false,'error'=>'invalid credentials']);
    }

    $displayName = $user['username'] ?? ($user['email'] ?? ('user#'.(int)$user['id']));
    $token = jwt_issue((int)$user['id'], [
        'email'    => $user['email']    ?? null,
        'username' => $user['username'] ?? null,
        'name'     => $displayName,
        'role'     => 'user'
    ]);

    json_out(200, [
        'ok' => true,
        'token' => $token,
        'expiresIn' => JWT_TTL,
        'user' => [
            'id'       => (int)$user['id'],
            'email'    => $user['email']    ?? null,
            'username' => $user['username'] ?? null,
            'name'     => $displayName
        ]
    ]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine())
        : 'server error';
    json_out(500, ['ok'=>false,'error'=>$msg]);
}
