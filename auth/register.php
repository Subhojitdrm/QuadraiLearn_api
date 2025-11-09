<?php
declare(strict_types=1);

/**
 * Register endpoint (atomic, with diagnostics)
 * - Requires: config.php, db.php (get_pdo), lib/tokens.php (add_tokens)
 * - Set DEBUG + LOG_FILE in config.php to get detailed diagnostic JSON/logs.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // adjust if you prefer a specific origin
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** Load order: config BEFORE db */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/wallet.php';

/** Tokens to award on successful signup */
const INITIAL_SIGNUP_TOKENS = 250;

/** Debug logger */
function log_debug(string $message): void {
    if (defined('DEBUG') && DEBUG && defined('LOG_FILE') && LOG_FILE) {
        error_log(date('[Y-m-d H:i:s] ') . $message . "\n", 3, LOG_FILE);
    }
}

/** JSON output helper */
function json_out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Diagnostic output:
 * - When DEBUG=true, returns { ok:false, stage, detail? } to caller.
 * - When DEBUG=false, returns generic server error.
 */
function diag_json_out(int $code, string $stage, $extra = null): void {
    if (defined('DEBUG') && DEBUG) {
        $payload = ['ok' => false, 'stage' => $stage];
        if ($extra !== null) $payload['detail'] = $extra;
        json_out($code, $payload);
    } else {
        json_out($code, ['ok' => false, 'error' => 'server error']);
    }
}

/** Read JSON body safely */
function body_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

log_debug('--- Register endpoint hit ---');

$input = body_json();

/**
 * Expected payload keys:
 * - firstName (required)
 * - lastName  (required)
 * - username  (required)
 * - email     (required, valid)
 * - interestedAreas (optional array of strings)
 * - primaryStudyNeed (optional string)
 * - password (required)
 * - confirmPassword (required, matches, min 8)
 */

log_debug('Request Body: ' . json_encode($input));

$errors = [];

$firstName = trim((string)($input['firstName'] ?? ''));
$lastName  = trim((string)($input['lastName'] ?? ''));
$username  = trim((string)($input['username'] ?? ''));
$email     = trim((string)($input['email'] ?? ''));
$areas     = $input['interestedAreas'] ?? null;        // expect array or null
$primary   = trim((string)($input['primaryStudyNeed'] ?? ''));
$pass      = (string)($input['password'] ?? '');
$confirm   = (string)($input['confirmPassword'] ?? '');

// Basic validation
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

// Normalize interestedAreas
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
    log_debug('Validation failed: ' . json_encode($errors));
    json_out(422, ['ok' => false, 'errors' => $errors]);
}

try {
    // Connect and force exception mode
    log_debug('Connecting to database...');
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    log_debug('Database connection OK');

    // Pre-check uniqueness
    try {
        $stmt = $pdo->prepare(
            'SELECT username, email
             FROM users
             WHERE username = :u OR email = :e
             LIMIT 1'
        );
        $stmt->execute([':u' => $username, ':e' => $email]);
    } catch (Throwable $e) {
        diag_json_out(500, 'precheck_query_failed', $e->getMessage());
    }

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $conflicts = [];
        if (isset($row['email']) && strcasecmp($row['email'], $email) === 0) {
            $conflicts['email'] = 'Email already in use';
        }
        if (isset($row['username']) && strcasecmp($row['username'], $username) === 0) {
            $conflicts['username'] = 'Username already taken';
        }
        log_debug('Conflict: ' . json_encode($conflicts));
        json_out(409, ['ok' => false, 'errors' => $conflicts]);
    }

    // Begin transaction (atomic insert + token award)
    try {
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        diag_json_out(500, 'begin_tx_failed', $e->getMessage());
    }

    // Insert user
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $interestsJson = $interests !== null ? json_encode($interests, JSON_UNESCAPED_UNICODE) : null;

    try {
        $sql = 'INSERT INTO users
                (first_name, last_name, username, email, interests, primary_study_need, password_hash)
                VALUES (:first, :last, :user, :email, :interests, :primary, :ph)';
        $stmt = $pdo->prepare($sql);

        $stmt->bindValue(':first', $firstName, PDO::PARAM_STR);
        $stmt->bindValue(':last',  $lastName,  PDO::PARAM_STR);
        $stmt->bindValue(':user',  $username,  PDO::PARAM_STR);
        $stmt->bindValue(':email', $email,     PDO::PARAM_STR);

        if ($interestsJson === null) {
            $stmt->bindValue(':interests', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':interests', $interestsJson, PDO::PARAM_STR);
        }

        if ($primary === '') {
            $stmt->bindValue(':primary', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':primary', $primary, PDO::PARAM_STR);
        }

        $stmt->bindValue(':ph', $hash, PDO::PARAM_STR);
        $stmt->execute();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = ($e instanceof PDOException) ? $e->getCode() : 'non_pdo';
        diag_json_out(500, 'user_insert_failed', ['code' => $code, 'msg' => $e->getMessage()]);
    }

    $userId = (int)$pdo->lastInsertId();
    log_debug("User inserted with ID {$userId}");

    // Award initial tokens using new wallet system
    if (INITIAL_SIGNUP_TOKENS > 0) {
        try {
            log_debug('About to credit wallet, transaction active: ' . ($pdo->inTransaction() ? 'yes' : 'no'));

            // Credit wallet with registration bonus
            wallet_credit(
                $pdo,
                $userId,
                INITIAL_SIGNUP_TOKENS,
                TOKEN_TYPE_REGULAR,
                REASON_REGISTRATION_BONUS,
                null,
                ['source' => 'registration'],
                "WALLET_SEED:user:{$userId}"
            );

            log_debug('Initial tokens awarded, transaction active: ' . ($pdo->inTransaction() ? 'yes' : 'no'));
        } catch (Throwable $e) {
            log_debug('wallet_credit threw exception: ' . $e->getMessage());
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = ($e instanceof PDOException) ? $e->getCode() : 'non_pdo';
            diag_json_out(500, 'wallet_credit_failed', ['code' => $code, 'msg' => $e->getMessage()]);
        }
    }

    // Commit transaction
    log_debug('About to commit, transaction active: ' . ($pdo->inTransaction() ? 'yes' : 'no'));

    try {
        if (!$pdo->inTransaction()) {
            log_debug('ERROR: No transaction active before commit!');
            // Skip commit if no transaction
        } else {
            $pdo->commit();
            log_debug('Transaction committed successfully');
        }
    } catch (Throwable $e) {
        log_debug('commit() threw exception: ' . $e->getMessage());
        diag_json_out(500, 'commit_failed', $e->getMessage());
    }

    // Success response
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
    // NOTE: PDO::getCode() returns SQLSTATE strings like '23000'
    if ($e->getCode() === '23000') {
        json_out(409, ['ok' => false, 'errors' => ['unique' => 'Email or username already exists']]);
    }
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'database error';
    log_debug('PDOException: ' . $msg);
    json_out(500, ['ok' => false, 'error' => $msg]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $msg = (defined('DEBUG') && DEBUG)
        ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'server error';
    log_debug('Throwable: ' . $msg);
    json_out(500, ['ok' => false, 'error' => $msg]);
}
