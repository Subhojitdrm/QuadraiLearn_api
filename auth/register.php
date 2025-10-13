<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/../db.php';
require __DIR__ . '/../lib/audit.php';   // safe: internally try/catch
require __DIR__ . '/../config.php';

function json_out(int $code, array $data, ?PDO $pdo = null, array $audit = []): void {
    if ($pdo && !empty($audit)) {
        try {
            $audit['status_code'] = $code;
            audit_log($pdo, $audit);
        } catch (Throwable $e) {
            // swallow audit failures
        }
    }
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

$firstName = trim((string)($input['firstName'] ?? ''));
$lastName  = trim((string)($input['lastName'] ?? ''));
$username  = trim((string)($input['username'] ?? ''));
$email     = trim((string)($input['email'] ?? ''));
$areas     = $input['interestedAreas'] ?? null;
$primary   = trim((string)($input['primaryStudyNeed'] ?? ''));
$pass      = (string)($input['password'] ?? '');
$confirm   = (string)($input['confirmPassword'] ?? '');

$errors = [];
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

$interests = null;
if (is_array($areas)) {
    $clean = [];
    foreach ($areas as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '') $clean[] = $tag;
    }
    $interests = $clean;
} elseif ($areas !== null) {
    $errors['interestedAreas'] = 'interestedAreas must be an array';
}

if (!empty($errors)) {
    json_out(422, ['ok'=>false,'errors'=>$errors]);
}

try {
    $pdo = get_pdo();

    // audit attempt (won't crash if audit table bad)
    try {
        audit_log($pdo, [
            'action'=>'REGISTER_ATTEMPT',
            'entity_type'=>'user',
            'details'=>['email'=>$email,'username'=>$username]
        ]);
    } catch (Throwable $e) {}

    // unique check
    $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
    $stmt->execute([':u'=>$username, ':e'=>$email]);
    if ($row = $stmt->fetch()) {
        $errs = [];
        if (strcasecmp($row['email'], $email) === 0)    $errs['email'] = 'Email already in use';
        if (strcasecmp($row['username'], $username) === 0) $errs['username'] = 'Username already taken';

        json_out(409, ['ok'=>false,'errors'=>$errs], $pdo, [
            'action'=>'REGISTER_FAIL','entity_type'=>'user',
            'details'=>['reason'=>'conflict','email'=>$email,'username'=>$username]
        ]);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $interestsJson = $interests !== null ? json_encode($interests, JSON_UNESCAPED_UNICODE) : null;

    // if your users.interests is TEXT, this still works (stores JSON string)
    $sql = 'INSERT INTO users
            (first_name,last_name,username,email,interests,primary_study_need,password_hash)
            VALUES
            (:first,:last,:user,:email,:interests,:primary,:ph)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':first'=>$firstName,
        ':last'=>$lastName,
        ':user'=>$username,
        ':email'=>$email,
        ':interests'=>$interestsJson,
        ':primary'=>($primary !== '' ? $primary : null),
        ':ph'=>$hash,
    ]);

    $userId = (int)$pdo->lastInsertId();

    try {
        audit_log($pdo, [
            'action'=>'REGISTER_SUCCESS','entity_type'=>'user',
            'entity_id'=>$userId,'user_id'=>$userId,
            'details'=>['email'=>$email,'username'=>$username]
        ]);
    } catch (Throwable $e) {}

    json_out(201, [
        'ok'=>true,
        'message'=>'Registration successful',
        'user'=>[
            'id'=>$userId,
            'firstName'=>$firstName,
            'lastName'=>$lastName,
            'username'=>$username,
            'email'=>$email,
            'interestedAreas'=>$interests ?? [],
            'primaryStudyNeed'=>($primary !== '' ? $primary : null),
            'createdAt'=>date('c'),
        ]
    ], $pdo, [
        'action'=>'REGISTER_RESPONDED','entity_type'=>'user',
        'entity_id'=>$userId,'user_id'=>$userId
    ]);

} catch (Throwable $e) {
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage().' @ '.($e->getFile().':'.$e->getLine()))
        : 'server error';
    try {
        audit_log($pdo ?? get_pdo(), ['action'=>'REGISTER_ERROR','details'=>['type'=>get_class($e)]]);
    } catch (Throwable $ignore) {}
    json_out(500, ['ok'=>false,'error'=>$msg]);
}
