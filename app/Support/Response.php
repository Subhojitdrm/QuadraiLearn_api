<?php
namespace App\Support;

class Response {
  public static function json($body=[], $status=200, $headers=[]){
    return ['status'=>$status, 'headers'=>$headers, 'body'=>$body];
  }
  public static function error($message, $status=400){
    return self::json(['error'=>$message], $status);
  }
}
