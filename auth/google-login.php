<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/tokens.php';
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

/**
 * Verifies a Google ID token.
 * In a production environment, use the `google/apiclient` library.
 * @param string $idToken The ID token from the client.
 * @return array|null The payload if valid, otherwise null.
 */
function verify_google_id_token(string $idToken): ?array {
    // For production, use Google's library:
    // require_once __DIR__ . '/../vendor/autoload.php';
    // $client = new Google_Client(['client_id' => GOOGLE_CLIENT_ID]);
    // $payload = $client->verifyIdToken($idToken);
    // if ($payload) {
    //     return $payload;
    // }
    // return null;

    // --- Mock verification for demonstration ---
    // This is NOT secure and should be replaced with the library call above.
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) return null;
    $payload = json_decode(base64_decode($parts[1]), true);
    if (!is_array($payload) || !isset($payload['email'], $payload['sub'], $payload['name'])) {
        return null;
    }
    // In a real scenario, you'd also check $payload['aud'] against your GOOGLE_CLIENT_ID
    // and $payload['iss'] to be 'accounts.google.com' or 'https://accounts.google.com'.
    return $payload;
}

$input = body_json();
$idToken = trim((string)($input['id_token'] ?? ''));

if ($idToken === '') {
    json_out(400, ['ok' => false, 'error' => 'id_token is required']);
}

$payload = verify_google_id_token($idToken);

if (!$payload) {
    json_out(401, ['ok' => false, 'error' => 'Invalid Google ID token']);
}

$googleId = $payload['sub'];
$email = $payload['email'];
$name = $payload['name'];
$firstName = $payload['given_name'] ?? strtok($name, ' ');
$lastName = $payload['family_name'] ?? substr(strstr($name, ' '), 1) ?: '';

try {
    $pdo = get_pdo();
    $pdo->beginTransaction();

    // Check if user exists by Google ID
    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE google_id = :gid LIMIT 1');
    $stmt->execute([':gid' => $googleId]);
    $user = $stmt->fetch();

    if ($user) {
        // User exists, log them in
        $userId = (int)$user['id'];
    } else {
        // User does not exist by Google ID, check by email
        $stmt = $pdo->prepare('SELECT id, auth_provider FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $userByEmail = $stmt->fetch();

        if ($userByEmail) {
            // Email exists, link Google ID to this account
            $userId = (int)$userByEmail['id'];
            $updateStmt = $pdo->prepare('UPDATE users SET google_id = :gid, auth_provider = "google" WHERE id = :id');
            $updateStmt->execute([':gid' => $googleId, ':id' => $userId]);
        } else {
            // New user: create account
            $insertStmt = $pdo->prepare(
                'INSERT INTO users (first_name, last_name, email, auth_provider, google_id, username)
                 VALUES (:fname, :lname, :email, "google", :gid, :uname)'
            );
            // Create a unique username fallback
            $username = strtolower(strtok($email, '@') . '_' . substr(bin2hex(random_bytes(3)), 0, 4));
            $insertStmt->execute([
                ':fname' => $firstName,
                ':lname' => $lastName,
                ':email' => $email,
                ':gid' => $googleId,
                ':uname' => $username
            ]);
            $userId = (int)$pdo->lastInsertId();

            // Grant initial tokens
            if (defined('INITIAL_SIGNUP_TOKENS') && INITIAL_SIGNUP_TOKENS > 0) {
                add_tokens($pdo, $userId, INITIAL_SIGNUP_TOKENS, 'initial_signup_bonus', 'user', $userId, 'bonus');
            }
        }
    }

    $pdo->commit();

    // Issue JWT and return user data
    $token = create_jwt($userId, $email);
    json_out(200, [
        'ok' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => ['id' => $userId, 'email' => $email, 'firstName' => $firstName, 'lastName' => $lastName]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $message = (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'A server error occurred.';
    json_out(500, ['ok' => false, 'error' => $message]);
}