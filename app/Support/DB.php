<?php
namespace App\Support;
use PDO;

class DB {
  private static $pdo = null;
  public static function pdo(){
    if (!self::$pdo){
      $dsn = 'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4';
      self::$pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      ]);
    }
    return self::$pdo;
  }
}
