<?php
declare(strict_types=1);

// Clean any buffered output
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'ping' => 'pong', 'ts' => time()]);
exit;
