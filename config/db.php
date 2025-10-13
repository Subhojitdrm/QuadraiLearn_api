<?php
// config/db.php
// PHP 7+ compatible PDO connection helper

declare(strict_types=1);

// On most cPanel/Bluehost setups the host is 'localhost'. 
// If your host gives a different MySQL hostname, replace it here.
$DB_HOST = 'localhost';
$DB_NAME = 'calmconq_quadravise_library';
$DB_USER = 'calmconq_quadraviseadmin';
$DB_PASS = 'calmconq_quadraviseadmin';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // native prepares
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // Never echo raw DB errors in production
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'    => false,
        'error' => 'Database connection failed'
    ]);
    exit;
}
