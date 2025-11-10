<?php
declare(strict_types=1);

/**
 * POST /api/auth/create_admin.php
 *
 * Bootstrap endpoint for creating an admin account.
 * Access is gated behind the ADMIN_SETUP_TOKEN environment variable,
 * supplied via X-Admin-Setup-Token header or JSON body field "setupToken".
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Setup-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function get_admin_setup_token(): ?string {
    $candidates = [
        getenv('ADMIN_SETUP_TOKEN') ?: null,
        $_ENV['ADMIN_SETUP_TOKEN'] ?? null,
        $_SERVER['ADMIN_SETUP_TOKEN'] ?? null,
    ];
    foreach ($candidates as $token) {
        if (is_string($token) && $token !== '') {
            return $token;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$expectedToken = get_admin_setup_token();
if ($expectedToken === null) {
    json_out(500, ['ok' => false, 'error' => 'admin_setup_token_not_configured']);
}

$input = body_json();
$providedToken = $_SERVER['HTTP_X_ADMIN_SETUP_TOKEN'] ?? ($input['setupToken'] ?? null);
if (!$providedToken || !hash_equals($expectedToken, (string)$providedToken)) {
    json_out(403, ['ok' => false, 'error' => 'invalid_setup_token']);
}

$username = trim((string)($input['username'] ?? ''));
$email    = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$fullName = trim((string)($input['fullName'] ?? ''));

$errors = [];
if ($username === '' || strlen($username) > 64) {
    $errors['username'] = 'username is required (<= 64 chars)';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'valid email is required';
}
if ($password === '' || strlen($password) < 8) {
    $errors['password'] = 'password must be at least 8 characters';
}
if ($fullName !== '' && strlen($fullName) > 128) {
    $errors['fullName'] = 'fullName must be <= 128 chars';
}
if ($errors) {
    json_out(422, ['ok' => false, 'errors' => $errors]);
}

try {
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

    $stmt = $pdo->prepare('
        INSERT INTO admin_users (username, email, password_hash, full_name)
        VALUES (:username, :email, :password_hash, :full_name)
    ');
    $stmt->execute([
        ':username'      => $username,
        ':email'         => $email,
        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ':full_name'     => $fullName !== '' ? $fullName : null,
    ]);

    json_out(201, [
        'ok' => true,
        'admin' => [
            'id'       => (int)$pdo->lastInsertId(),
            'username' => $username,
            'email'    => $email,
            'fullName' => $fullName !== '' ? $fullName : null,
        ],
    ]);
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) { // integrity constraint violation
        json_out(409, ['ok' => false, 'error' => 'username_or_email_exists']);
    }

    $message = (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'server_error';
    json_out(500, ['ok' => false, 'error' => $message]);
}
