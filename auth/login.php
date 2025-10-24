<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/jwt_util.php'; // Assumes you have a JWT helper

function json_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$input = body_json();
$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($email === '' || $password === '') {
    json_out(400, ['ok' => false, 'error' => 'Email and password are required']);
}

try {
    $pdo = get_pdo();

    // --- Rate Limiting / Brute-force prevention ---
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $pdo->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = :ip AND last_attempt > (NOW() - INTERVAL :window MINUTE)');
    $stmt->execute([':ip' => $ip, ':window' => LOGIN_WINDOW_MINUTES]);
    $attemptInfo = $stmt->fetch();

    if ($attemptInfo && (int)$attemptInfo['attempts'] >= LOGIN_MAX_ATTEMPTS) {
        json_out(429, ['ok' => false, 'error' => 'Too many login attempts. Please try again later.']);
    }

    // --- User lookup ---
    $stmt = $pdo->prepare('SELECT id, email, password_hash, auth_provider FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        // Record failed attempt
        $upsert = $pdo->prepare('
            INSERT INTO login_attempts (ip_address, attempts, last_attempt) VALUES (:ip, 1, NOW())
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()
        ');
        $upsert->execute([':ip' => $ip]);
        json_out(401, ['ok' => false, 'error' => 'Invalid credentials']);
    }

    // If user registered via Google, guide them to the correct login method
    if ($user['auth_provider'] === 'google') {
        json_out(401, ['ok' => false, 'error' => 'This account was created using Google. Please log in with Google.']);
    }

    // --- Success ---
    // Clear login attempts for this IP on success
    $del = $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip');
    $del->execute([':ip' => $ip]);

    // Generate JWT
    $userId = (int)$user['id'];
    $token = create_jwt($userId, $user['email']); // Assumes a create_jwt function in jwt_util.php

    // Fetch full user profile to return
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, username, email FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    json_out(200, [
        'ok' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            'id' => (int)$userProfile['id'],
            'firstName' => $userProfile['first_name'],
            'lastName' => $userProfile['last_name'],
            'username' => $userProfile['username'],
            'email' => $userProfile['email'],
        ]
    ]);

} catch (Throwable $e) {
    $message = (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'A server error occurred.';
    json_out(500, ['ok' => false, 'error' => $message]);
}

/**
 * NOTE: This implementation requires a `login_attempts` table and a `jwt_util.php` library.
 *
 * CREATE TABLE `login_attempts` (
 *   `ip_address` varchar(45) NOT NULL,
 *   `attempts` int(11) NOT NULL DEFAULT 1,
 *   `last_attempt` timestamp NOT NULL DEFAULT current_timestamp(),
 *   PRIMARY KEY (`ip_address`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * Your `jwt_util.php` should have a function like:
 * function create_jwt(int $userId, string $email): string { ... }
 */