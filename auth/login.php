<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/jwt.php';   // requires config.php for JWT_*
require_once __DIR__ . '/../config.php';

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
/**
 * Payload:
 *   identifier: email OR username (required)
 *   password:   string (required)
 */
$identifier = trim((string)($in['identifier'] ?? ''));
$password   = (string)($in['password'] ?? '');

if ($identifier === '' || $password === '') {
    json_out(422, ['ok'=>false,'error'=>'identifier and password are required']);
}

try {
    $pdo = get_pdo();

    // Find by email or username
    $stmt = $pdo->prepare(
        'SELECT id, email, username, password_hash, status, first_name, last_name
         FROM users WHERE email = :id OR username = :id LIMIT 1'
    );
    $stmt->execute([':id'=>$identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        json_out(401, ['ok'=>false,'error'=>'invalid credentials']);
    }
    if ($user['status'] === 'blocked') {
        json_out(403, ['ok'=>false,'error'=>'account is blocked']);
    }
    if (!password_verify($password, $user['password_hash'])) {
        json_out(401, ['ok'=>false,'error'=>'invalid credentials']);
    }

    // Issue JWT
    $token = jwt_issue((int)$user['id'], [
        'email'    => $user['email'],
        'username' => $user['username'],
        'name'     => trim($user['first_name'].' '.$user['last_name']),
        'role'     => 'user'
    ]);

    json_out(200, [
        'ok' => true,
        'token' => $token,
        'expiresIn' => JWT_TTL,
        'user' => [
            'id'       => (int)$user['id'],
            'email'    => $user['email'],
            'username' => $user['username'],
            'name'     => trim($user['first_name'].' '.$user['last_name']),
            'status'   => $user['status'],
        ]
    ]);
} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine())
        : 'server error';
    json_out(500, ['ok'=>false,'error'=>$msg]);
}
