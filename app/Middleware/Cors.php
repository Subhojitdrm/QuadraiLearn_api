<?php
namespace App\Middleware;

class Cors {
  private $allowed;

  public function __construct($originsCsv){
    $this->allowed = $originsCsv ? array_map('trim', explode(',', $originsCsv)) : ['*'];
  }

  // Return response array for OPTIONS or null to continue
  public function handle(){
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    $method = $_SERVER['REQUEST_METHOD'];

    $headers = [
      'Access-Control-Allow-Methods' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
      'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
      'Access-Control-Max-Age'      => '86400',
    ];

    if (in_array('*', $this->allowed)) {
      $headers['Access-Control-Allow-Origin'] = '*';
    } elseif ($origin && in_array($origin, $this->allowed)) {
      $headers['Access-Control-Allow-Origin'] = $origin;
      $headers['Access-Control-Allow-Credentials'] = 'true';
      $headers['Vary'] = 'Origin';
    }

    foreach ($headers as $k=>$v) header("$k: $v");

    if ($method === 'OPTIONS') {
      return ['status'=>204, 'headers'=>$headers, 'body'=>[]];
    }
    return null;
  }
}
