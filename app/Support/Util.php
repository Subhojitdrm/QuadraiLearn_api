<?php
namespace App\Support;

class Util {
  public static function jsonBody(){
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
  }
  public static function int($v){ return (int)$v; }
  public static function str($v){ return trim((string)$v); }
  public static function now(){ return date('Y-m-d H:i:s'); }
}
