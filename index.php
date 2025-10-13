<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'app' => 'quadrailearn',
  'api' => 'ok',
  'routes' => [
    '/api/health.php' => 'health check',
  ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
