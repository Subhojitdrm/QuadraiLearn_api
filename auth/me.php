<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/auth.php';

$claims = require_auth();
echo json_encode(['ok'=>true,'claims'=>$claims], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
