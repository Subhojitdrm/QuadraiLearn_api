<?php
namespace App\Middleware;
use App\Support\Response;
use App\Support\JWT;

class Auth {
  private $request;

  // called by Router before controller
  public function handle($req){
    $hdr = isset($req['headers']['Authorization']) ? $req['headers']['Authorization'] : '';
    if (!preg_match('/Bearer\s+(.+)/', $hdr, $m)) {
      return Response::json(['error'=>'unauthorized'], 401);
    }
    try {
      $payload = JWT::verify($m[1]);
      $req['uid'] = (int)$payload['sub'];
      $this->request = $req;
      return null; // continue
    } catch (\Throwable $e) {
      return Response::json(['error'=>'unauthorized'], 401);
    }
  }
  public function request(){ return $this->request; }
}
