<?php
/**
 * Diagnostic script to test if routing is working
 */

header('Content-Type: application/json');

$info = [
    'status' => 'ok',
    'message' => 'Routing is working correctly',
    'server_info' => [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'not set',
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'not set',
        'php_self' => $_SERVER['PHP_SELF'] ?? 'not set',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'not set',
        'query_string' => $_SERVER['QUERY_STRING'] ?? 'not set',
    ],
    'headers' => [
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'not set',
        'x-request-id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? 'not set',
        'x-source' => $_SERVER['HTTP_X_SOURCE'] ?? 'not set',
    ],
    'php_version' => PHP_VERSION,
    'file_location' => __FILE__,
];

echo json_encode($info, JSON_PRETTY_PRINT);
exit;
