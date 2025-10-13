<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/jwt.php';
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
function client_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
    foreach ($keys as $k) if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    return '0.0.0.0';
}

$input = body_json();
$identifier = trim((string)($input['identifier'] ?? ''));  // email or username
$password   = (string)($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    json_out(422, ['ok'=>false,'error'=>'identifier and password are required']);
}

try {
    $pdo = get_pdo();

    // (optional) RATE LIMIT / LOCKOUT — uses login_attempts table
    $ip = client_ip();

    // Try to find the user first
    $stmt = $pdo->prepare('SELECT id, email, username, password_hash, status, first_name, last_name
                           FROM users WHERE email = :id OR username = :id LIMIT 1');
    $stmt->execute([':id'=>$identifier]);
    $user = $stmt->fetch();
    $uid  = $user ? (int)$user['id'] : null;

    // Load attempts by IP+user
    $stmt = $pdo->prepare('SELECT * FROM login_attempts WHERE ip = :ip AND (user_id <=> :uid) ORDER BY id DESC LIMIT 1');
    $stmt->execute([':ip'=>$ip, ':uid'=>$uid]);
    $attempt = $stmt->fetch();

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($attempt && $attempt['locked_until']) {
        $lockedUntil = new DateTimeImmutable($attempt['locked_until'], new DateTimeZone('UTC'));
        if ($now <= $lockedUntil) json_out(429, ['ok'=>false,'error'=>'too many attempts, try later']);
    }

    // Validate
    if (!$user || $user['status'] === 'blocked' || !password_verify($password, $user['password_hash'])) {
        $windowStart = (new DateTimeImmutable('-'.LOGIN_WINDOW_MINUTES.' minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        if ($attempt) {
            $inWindow = ($attempt['last_attempt_at'] >= $windowStart);
            $attempts = $inWindow ? (int)$attempt['attempts'] + 1 : 1;
            $lockedUntil = null;
            if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                $lockedUntil = $now->modify('+'.LOGIN_LOCK_MINUTES.' minutes')->format('Y-m-d H:i:s');
            }
            $stmt = $pdo->prepare('UPDATE login_attempts
                                   SET attempts = :a, last_attempt_at = UTC_TIMESTAMP(), locked_until = :lu
                                   WHERE id = :id');
            $stmt->execute([':a'=>$attempts, ':lu'=>$lockedUntil, ':id'=>$attempt['id']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO login_attempts (user_id, ip, attempts, last_attempt_at, locked_until)
                                   VALUES (:uid, :ip, 1, UTC_TIMESTAMP(), NULL)');
            $stmt->execute([':uid'=>$uid, ':ip'=>$ip]);
        }

        json_out(401, ['ok'=>false,'error'=>'invalid credentials']);
    }

    // success → reset attempts
    if ($attempt) {
        $stmt = $pdo->prepare('UPDATE login_attempts
                               SET attempts = 0, locked_until = NULL, last_attempt_at = UTC_TIMESTAMP()
                               WHERE id = :id');
        $stmt->execute([':id'=>$attempt['id']]);
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
