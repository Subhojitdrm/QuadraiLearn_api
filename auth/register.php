<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/../db.php';
require __DIR__ . '/../lib/audit.php';   // <-- add this

function json_out(int $code, array $data, ?PDO $pdo = null, array $audit = []): void {
    // write an audit row if $pdo and action provided
    if ($pdo && !empty($audit)) {
        $audit['status_code'] = $code;
        try { audit_log($pdo, $audit); } catch (Throwable $e) { /* never break response */ }
    }
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ... (body_json + validation as before)

try {
    $pdo = get_pdo();

    // log attempt (no user yet)
    audit_log($pdo, [
        'action'  => 'REGISTER_ATTEMPT',
        'entity_type' => 'user',
        'details' => [
            'email' => $email,
            'username' => $username
        ],
    ]);

    // uniqueness check
    $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = :u OR email = :e LIMIT 1');
    $stmt->execute([':u' => $username, ':e' => $email]);
    if ($row = $stmt->fetch()) {
        $errs = [];
        if (strcasecmp($row['email'], $email) === 0) $errs['email'] = 'Email already in use';
        if (strcasecmp($row['username'], $username) === 0) $errs['username'] = 'Username already taken';

        json_out(409, ['ok' => false, 'errors' => $errs], $pdo, [
            'action'  => 'REGISTER_FAIL',
            'entity_type' => 'user',
            'details' => ['reason' => 'conflict', 'email' => $email, 'username' => $username]
        ]);
    }

    // insert user (same as earlier)
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $interestsJson = $interests !== null ? json_encode($interests, JSON_UNESCAPED_UNICODE) : null;

    $sql = 'INSERT INTO users (first_name,last_name,username,email,interests,primary_study_need,password_hash)
            VALUES (:first,:last,:user,:email,:interests,:primary,:ph)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':first'=>$firstName, ':last'=>$lastName, ':user'=>$username, ':email'=>$email,
        ':interests'=>$interestsJson, ':primary'=>($primary !== '' ? $primary : null), ':ph'=>$hash
    ]);

    $userId = (int)$pdo->lastInsertId();

    // success log
    audit_log($pdo, [
        'action'      => 'REGISTER_SUCCESS',
        'entity_type' => 'user',
        'entity_id'   => $userId,
        'user_id'     => $userId,
        'details'     => ['email' => $email, 'username' => $username]
    ]);

    json_out(201, [
        'ok' => true,
        'message' => 'Registration successful',
        'user' => [
            'id' => $userId,
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'username'  => $username,
            'email'     => $email,
            'interestedAreas' => $interests ?? [],
            'primaryStudyNeed' => $primary !== '' ? $primary : null,
            'createdAt' => date('c'),
        ]
    ], $pdo, [
        'action'      => 'REGISTER_RESPONDED',
        'entity_type' => 'user',
        'entity_id'   => $userId,
        'user_id'     => $userId
    ]);

} catch (PDOException $e) {
    json_out(500, ['ok' => false, 'error' => 'database error'], isset($pdo)?$pdo:null, [
        'action'  => 'REGISTER_FAIL',
        'entity_type' => 'user',
        'details' => ['reason' => 'db_error']
    ]);
} catch (Throwable $e) {
    json_out(500, ['ok' => false, 'error' => 'server error'], isset($pdo)?$pdo:null, [
        'action'  => 'REGISTER_FAIL',
        'entity_type' => 'user',
        'details' => ['reason' => 'server_error']
    ]);
}
