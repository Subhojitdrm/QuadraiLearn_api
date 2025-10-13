<?php
namespace App\Controllers;

use App\Support\DB;
use App\Support\Response;
use App\Support\Logger;

class HealthController {
  public function check($req) {
    try {
      // Check DB connection
      DB::pdo()->query("SELECT 1");
      $dbStatus = 'ok';
    } catch (\PDOException $e) {
      $dbStatus = 'error';
      Logger::log('Health Check DB Error: ' . $e->getMessage());
    }

    return Response::json(['status' => 'ok', 'checks' => ['database' => $dbStatus]]);
  }
}