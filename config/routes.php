<?php
use App\Support\Router;
use App\Middleware\Auth;
use App\Controllers\AuthController;
use App\Controllers\BooksController;
use App\Controllers\GenerateController;
use App\Controllers\ChaptersController;
use App\Controllers\TestsController;

/** @var Router $router */
$router = $router ?? null;
if (!$router) return;

/* Public */
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login',    [AuthController::class, 'login']);

/* Protected (JWT) */
$auth = new Auth();

$router->group('/api', function(Router $r) {
  // Books
  $r->get('/books',        [BooksController::class, 'index']);
  $r->post('/books',       [BooksController::class, 'create']);
  $r->get('/books/{id}',   [BooksController::class, 'show']);
  $r->delete('/books/{id}',[BooksController::class, 'destroy']);

  // Generation
  $r->post('/books/{id}/outline',     [GenerateController::class, 'architect']);
  $r->post('/chapters/{id}/generate', [GenerateController::class, 'chapter']);
  $r->post('/books/{id}/synthesize',  [GenerateController::class, 'synthesizeAll']);

  // Reading
  $r->get('/chapters/{id}',        [ChaptersController::class, 'show']);
  $r->get('/chapters/{id}/pages',  [ChaptersController::class, 'pages']);

  // Mock tests
  $r->post('/books/{id}/tests', [TestsController::class, 'create']);
  $r->get('/tests/{id}',        [TestsController::class, 'show']);
}, $auth);
