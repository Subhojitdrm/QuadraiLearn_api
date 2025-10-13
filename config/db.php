<?php
// PHP 7+ compatible PDO connection

declare(strict_types=1);

$DB_HOST = 'localhost'; // change only if your host shows a different MySQL hostname
$DB_NAME = 'calmconq_quadravise_library';
$DB_USER = 'calmconq_quadraviseadmin';
$DB_PASS = 'calmconq_quadraviseadmin';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}
