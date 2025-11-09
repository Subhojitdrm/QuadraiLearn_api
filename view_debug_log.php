<?php
/**
 * View debug log
 */

header('Content-Type: text/plain');

$logFile = __DIR__ . '/debug.log';

if (!file_exists($logFile)) {
    echo "Debug log file not found: $logFile\n";
    exit;
}

// Show last 50 lines
$lines = file($logFile);
$lastLines = array_slice($lines, -50);

echo "=== Last 50 lines of debug.log ===\n\n";
echo implode('', $lastLines);
