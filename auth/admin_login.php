<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/jwt.php';

function json_out_admin(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json_admin(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out_admin(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$input = body_json_admin();
$identifier = trim((string)($input['identifier'] ?? ''));
$password   = (string)($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    json_out_admin(422, ['ok' => false, 'error' => 'identifier_and_password_required']);
}

try {
    foreach (['JWT_SECRET','JWT_ISSUER','JWT_AUDIENCE','JWT_TTL'] as $const) {
        if (!defined($const) || constant($const) === '') {
            throw new RuntimeException("$const is not defined");
        }
    }

    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(128) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $stmt = $pdo->prepare(
        'SELECT id, username, email, password_hash, full_name, is_active
         FROM admin_users
         WHERE username = :u OR email = :e
         LIMIT 1'
    );
    $stmt->execute([':u' => $identifier, ':e' => $identifier]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !(int)$admin['is_active']) {
        json_out_admin(401, ['ok' => false, 'error' => 'invalid_credentials']);
    }

    if (!password_verify($password, (string)$admin['password_hash'])) {
        json_out_admin(401, ['ok' => false, 'error' => 'invalid_credentials']);
    }

    $adminId = (int)$admin['id'];
    $display = $admin['full_name'] ?: ($admin['username'] ?: $admin['email']);

    $token = jwt_issue($adminId, [
        'email' => $admin['email'],
        'username' => $admin['username'],
        'name' => $display,
        'role' => 'admin'
    ]);

    json_out_admin(200, [
        'ok' => true,
        'token' => $token,
        'expiresIn' => JWT_TTL,
        'admin' => [
            'id' => $adminId,
            'username' => $admin['username'],
            'email' => $admin['email'],
            'name' => $display
        ]
    ]);

} catch (Throwable $e) {
    $message = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server_error';
    json_out_admin(500, ['ok' => false, 'error' => $message]);
}
