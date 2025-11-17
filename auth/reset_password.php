<?php
declare(strict_types=1);

/**
 * POST /api/auth/reset_password.php
 *
 * Consumes a password reset token produced by forgot_password.php and updates the user's password.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$input = body_json();
$token     = trim((string)($input['token'] ?? ''));
$password  = (string)($input['newPassword'] ?? '');
$confirm   = (string)($input['confirmPassword'] ?? '');

$errors = [];
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $errors['token'] = 'Valid reset token is required';
}
if ($password === '') {
    $errors['newPassword'] = 'Password is required';
} elseif (strlen($password) < 8) {
    $errors['newPassword'] = 'Password must be at least 8 characters';
}
if ($confirm === '' || $confirm !== $password) {
    $errors['confirmPassword'] = 'Passwords must match';
}

if ($errors) {
    json_out(422, ['ok' => false, 'errors' => $errors]);
}

try {
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure password_reset_tokens table exists (for fresh deployments).
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $tokenHash = hash('sha256', strtolower($token));

    $stmt = $pdo->prepare(
        'SELECT id, user_id FROM password_reset_tokens
         WHERE token_hash = :token_hash
           AND consumed_at IS NULL
           AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        json_out(400, ['ok' => false, 'error' => 'invalid_or_expired_token']);
    }

    $userId = (int)$tokenRow['user_id'];
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    $updateUser = $pdo->prepare('UPDATE users SET password_hash = :ph WHERE id = :uid LIMIT 1');
    $updateUser->execute([
        ':ph'  => $hashedPassword,
        ':uid' => $userId,
    ]);

    if ($updateUser->rowCount() !== 1) {
        $pdo->rollBack();
        json_out(500, ['ok' => false, 'error' => 'user_update_failed']);
    }

    $consumeToken = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET consumed_at = UTC_TIMESTAMP()
         WHERE id = :token_id'
    );
    $consumeToken->execute([':token_id' => (int)$tokenRow['id']]);

    // Remove any other outstanding tokens for this user to prevent reuse.
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :uid AND consumed_at IS NULL')->execute([
        ':uid' => $userId,
    ]);

    $pdo->commit();

    json_out(200, ['ok' => true, 'message' => 'Password updated successfully.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine())
        : 'server_error';
    json_out(500, ['ok' => false, 'error' => $message]);
}
