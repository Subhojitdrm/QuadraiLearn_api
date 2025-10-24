<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');                 // allow your UI
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../db.php'; // uses your existing db.php & config.php
require_once __DIR__ . '/../config.php'; // Ensure config is loaded first for the constant
require_once __DIR__ . '/../lib/tokens.php'; // Token function required here

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

/**
 * Expected payload keys:
 * firstName (required)
 * lastName (required)
 * username (required)
 * email (required)
 * interestedAreas (optional array of strings)
 * primaryStudyNeed (optional string)
 * password (required)
 * confirmPassword (required)
 */

$errors = [];

// Basic validation
$firstName = trim((string)($input['firstName'] ?? ''));
$lastName  = trim((string)($input['lastName'] ?? ''));
$username  = trim((string)($input['username'] ?? ''));
$email     = trim((string)($input['email'] ?? ''));
$areas     = $input['interestedAreas'] ?? null;       // expect array or null
$primary   = trim((string)($input['primaryStudyNeed'] ?? ''));
$pass      = (string)($input['password'] ?? '');
$confirm   = (string)($input['confirmPassword'] ?? '');

if ($firstName === '') $errors['firstName'] = 'First name is required';
if ($lastName === '')  $errors['lastName']  = 'Last name is required';
if ($username === '')  $errors['username']  = 'Username is required';

if ($email === '') {
    $errors['email'] = 'Email is required';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Email is invalid';
}

if ($pass === '' || $confirm === '') {
    $errors['password'] = 'Password and confirm password are required';
} elseif ($pass !== $confirm) {
    $errors['confirmPassword'] = 'Passwords do not match';
} elseif (strlen($pass) < 8) {
    $errors['password'] = 'Password must be at least 8 characters';
}

// Normalize interestedAreas → array of strings
$interests = null;
if (is_array($areas)) {
    $clean = [];
    foreach ($areas as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '') $clean[] = $tag;
    }
    $interests = $clean;
} elseif ($areas !== null) {
    $errors['interestedAreas'] = 'interestedAreas must be an array of strings';
}

if (!empty($errors)) {
    json_out(422, ['ok' => false, 'errors' => $errors]);
}

try {
    $pdo = get_pdo();

    // Check uniqueness for username & email
    $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
    $stmt->execute([':u' => $username, ':e' => $email]);
    if ($row = $stmt->fetch()) {
        if (strcasecmp($row['email'], $email) === 0) {
            $errors['email'] = 'Email already in use';
        }
        if (strcasecmp($row['username'], $username) === 0) {
            $errors['username'] = 'Username already taken';
        }
        json_out(409, ['ok' => false, 'errors' => $errors]);
    }

    // Hash password
    $hash = password_hash($pass, PASSWORD_DEFAULT);

    // Prepare JSON for interests (MySQL JSON or text)
    $interestsJson = $interests !== null ? json_encode($interests, JSON_UNESCAPED_UNICODE) : null;

    $sql = 'INSERT INTO users (
                first_name, last_name, username, email, interests, primary_study_need, password_hash
            ) VALUES (
                :first, :last, :user, :email, :interests, :primary, :ph
            )';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':first'     => $firstName,
        ':last'      => $lastName,
        ':user'      => $username,
        ':email'     => $email,
        ':interests' => $interestsJson,        // MySQL will cast valid JSON string into JSON column
        ':primary'   => $primary !== '' ? $primary : null,
        ':ph'        => $hash,
    ]);

    $userId = (int)$pdo->lastInsertId();

    // The line below now awards 20 tokens because INITIAL_SIGNUP_TOKENS is defined as 20.
    if (defined('INITIAL_SIGNUP_TOKENS') && INITIAL_SIGNUP_TOKENS > 0) {
        $token_amount = INITIAL_SIGNUP_TOKENS;
        // The add_tokens function is used to safely update the balance and log the transaction.
        $token_success = add_tokens(
            $pdo,
            $userId,
            $token_amount,
            'initial_signup_bonus',
            'user',
            $userId,
            'bonus'
        );
        
        // Handle token award failure as a server error, as it's a critical part of registration.
        if (!$token_success) {
            // This will trigger a 500 error if token awarding fails, making it explicit.
            json_out(500, ['ok' => false, 'error' => 'Failed to award initial tokens. Please try again.']);
        }
    }

    json_out(201, [
        'ok' => true,
        'message' => 'Registration successful, and ' . INITIAL_SIGNUP_TOKENS . ' tokens awarded.',
        'user' => [
            'id' => $userId,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'username' => $username,
            'email' => $email,
            'interestedAreas' => $interests ?? [],
            'primaryStudyNeed' => $primary !== '' ? $primary : null,
            'createdAt' => date('c'),
        ]
    ]);

} catch (PDOException $e) {
    // Handle duplicate keys (in case race conditions hit unique indexes)
    if ((int)$e->getCode() === 23000) {
        json_out(409, ['ok' => false, 'errors' => ['unique' => 'Email or username already exists']]);
    }
    // Only show detailed error in DEBUG mode (assuming DEBUG is defined in config)
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine())
        : 'database error';
    json_out(500, ['ok' => false, 'error' => $msg]);

} catch (Throwable $e) {
    // Only show detailed error in DEBUG mode
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine())
        : 'server error';
    json_out(500, ['ok' => false, 'error' => $msg]);
}