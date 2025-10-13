<?php
// Tiny front controller + router (Composer-free)

require __DIR__ . '/../app/Support/Env.php';
require __DIR__ . '/../app/Support/Router.php';
require __DIR__ . '/../app/Support/Response.php';
require __DIR__ . '/../app/Support/DB.php';
require __DIR__ . '/../app/Support/JWT.php';
require __DIR__ . '/../app/Support/Util.php';
require __DIR__ . '/../app/Support/Logger.php';
require __DIR__ . '/../app/Middleware/Cors.php';
require __DIR__ . '/../app/Middleware/Auth.php';
require __DIR__ . '/../app/Services/GeminiService.php';
require __DIR__ . '/../app/Services/OpenRouterService.php';
require __DIR__ . '/../app/Services/AIService.php';

// Controllers
require __DIR__ . '/../app/Controllers/AuthController.php';
require __DIR__ . '/../app/Controllers/BooksController.php';
require __DIR__ . '/../app/Controllers/GenerateController.php';
require __DIR__ . '/../app/Controllers/ChaptersController.php';
require __DIR__ . '/../app/Controllers/TestsController.php';
require __DIR__ . '/../app/Controllers/HealthController.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// 1) load config into getenv()
App\Support\Env::boot(__DIR__ . '/../config/config.php');

// 2) basic JSON headers & error reporting
header('Content-Type: application/json; charset=utf-8');
if (getenv('APP_ENV') === 'local') {
  error_reporting(E_ALL); ini_set('display_errors', 1);
}

try {
  // 3) CORS (handles OPTIONS)
  $cors = new App\Middleware\Cors(getenv('FRONTEND_ORIGINS'));
  $resp = $cors->handle();
  if ($resp !== null) { // preflight handled
    http_response_code($resp['status']); foreach ($resp['headers'] as $k=>$v) header("$k: $v");
    exit;
  }

  // 4) build routes
  $router = new App\Support\Router();

  // register routes
  require __DIR__ . '/../config/routes.php';

  // 5) dispatch
  $router->dispatch();

} catch (\Throwable $e) {
  App\Support\Logger::log("FATAL: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
  
  http_response_code(500);
  echo json_encode([
    'error' => 'internal_server_error',
    'message' => 'An unexpected error occurred. Please try again later.'
  ]);
}
