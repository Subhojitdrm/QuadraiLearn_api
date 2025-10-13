<?php
namespace App\Support;

class Logger {
  private static $logFile;

  public static function log($message) {
    if (!self::$logFile) {
      $logDir = __DIR__ . '/../../storage/logs';
      if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
      }
      self::$logFile = $logDir . '/app.log';
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(self::$logFile, "[$timestamp] " . $message . PHP_EOL, FILE_APPEND);
  }
}