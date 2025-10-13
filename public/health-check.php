<?php
declare(strict_types=1);

// Force clean output (in case anything got buffered by auto_prepend_file etc.)
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header('Content-Type: application/json');

// No includes—pure sanity check
echo json_encode(['ok' => true, 'ping' => 'pong', 'ts' => time()]);
exit;<?php
declare(strict_types=1);

// Force clean output (in case anything got buffered by auto_prepend_file etc.)
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header('Content-Type: application/json');

// No includes—pure sanity check
echo json_encode(['ok' => true, 'ping' => 'pong', 'ts' => time()]);
exit;