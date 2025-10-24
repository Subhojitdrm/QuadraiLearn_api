<?php
require __DIR__ . '/config.php';

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES'"
    ];
    
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Too many connections') !== false) {
            http_response_code(503); // Service Unavailable
            echo json_encode(['ok' => false, 'error' => 'db_busy_try_again']);
            exit;
        }
        throw $e;
    }

    return $pdo;
}