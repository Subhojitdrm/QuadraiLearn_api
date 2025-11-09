<?php
/**
 * Server Configuration Test
 * Access directly at: https://quadrailearn.quadravise.com/api/test-server.php
 */

header('Content-Type: application/json');

$info = [
    'status' => 'ok',
    'message' => 'Server is working correctly',
    'server_config' => [
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'not set',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'not set',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
        'php_self' => $_SERVER['PHP_SELF'] ?? 'not set',
        'pwd' => getcwd(),
        'this_file' => __FILE__,
    ],
    'apache_modules' => function_exists('apache_get_modules') ? apache_get_modules() : 'Not available (not Apache or CGI mode)',
    'htaccess_test' => [
        'rewrite_enabled' => in_array('mod_rewrite', function_exists('apache_get_modules') ? apache_get_modules() : []) ? 'yes' : 'unknown',
        'htaccess_exists' => file_exists(__DIR__ . '/.htaccess') ? 'yes' : 'no',
        'htaccess_readable' => is_readable(__DIR__ . '/.htaccess') ? 'yes' : 'no',
    ],
    'directory_structure' => [
        'api_folder' => is_dir(__DIR__ . '/api') ? 'exists' : 'not found',
        'v1_folder' => is_dir(__DIR__ . '/v1') ? 'exists' : 'not found',
        'v1_wallet_folder' => is_dir(__DIR__ . '/v1/wallet') ? 'exists' : 'not found',
        'me_php' => file_exists(__DIR__ . '/v1/wallet/me.php') ? 'exists' : 'not found',
    ],
];

echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
