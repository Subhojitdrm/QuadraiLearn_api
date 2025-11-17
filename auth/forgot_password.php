<?php
declare(strict_types=1);

/**
 * POST /api/auth/forgot_password.php
 *
 * Generates a one-time password reset token for a user identified by email or username.
 * This endpoint does not reveal whether a user exists to avoid account enumeration.
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
$identifier = trim((string)($input['identifier'] ?? ''));

if ($identifier === '' || strlen($identifier) > 255) {
    json_out(422, [
        'ok' => false,
        'error' => 'identifier_required',
        'message' => 'Email or username is required',
    ]);
}

try {
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure backing table exists (idempotent for repeated calls).
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

    $stmt = $pdo->prepare(
        'SELECT id, email FROM users WHERE email = :email OR username = :username LIMIT 1'
    );
    $stmt->execute([':email' => $identifier, ':username' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always respond with success payload to avoid leaking whether an account exists.
    $response = [
        'ok' => true,
        'message' => 'If the account exists, a reset link has been issued.',
        'expiresInMinutes' => 30,
    ];

    if ($user) {
        // Keep only one active token per user.
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :uid')->execute([
            ':uid' => (int)$user['id'],
        ]);

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');

        $insert = $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:uid, :token_hash, :expires_at)'
        );
        $insert->execute([
            ':uid' => (int)$user['id'],
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);

        // For now the API returns the token so clients can deliver it via custom channels.
        $response['resetToken'] = $plainToken;
        $response['expiresAt'] = $expiresAt;
    }

    json_out($user ? 201 : 200, $response);
} catch (Throwable $e) {
    $message = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine())
        : 'server_error';
    json_out(500, ['ok' => false, 'error' => $message]);
}
