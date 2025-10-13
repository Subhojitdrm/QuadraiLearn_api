<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../db.php';

$claims = require_auth(); // 401s if invalid

try {
    $pdo = get_pdo();
    $uid = (int)$claims['sub'];

    $stmt = $pdo->prepare('SELECT id, email, username, first_name, last_name, status, created_at, updated_at
                           FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id'=>$uid]);
    $u = $stmt->fetch();

    if (!$u) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'not found']);
        exit;
    }

    echo json_encode([
        'ok'=>true,
        'user'=>[
            'id'=>(int)$u['id'],
            'email'=>$u['email'],
            'username'=>$u['username'],
            'name'=>trim($u['first_name'].' '.$u['last_name']),
            'status'=>$u['status'],
            'createdAt'=>$u['created_at'],
            'updatedAt'=>$u['updated_at'],
        ],
        'tokenClaims'=>[
            'sub'=>$claims['sub'] ?? null,
            'exp'=>$claims['exp'] ?? null,
            'iat'=>$claims['iat'] ?? null,
            'jti'=>$claims['jti'] ?? null
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>(defined('DEBUG')&&DEBUG)?$e->getMessage():'server error']);
}
