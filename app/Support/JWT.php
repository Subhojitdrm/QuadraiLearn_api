<?php
namespace App\Support;

/**
 * Minimal HS256 JWT (PHP 7)
 * NOTE: for production consider a vetted lib; this is kept tiny per your no-composer constraint.
 */
class JWT {
  public static function issue($uid, $ttl=3600){
    $header = self::b64(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = self::b64(json_encode(['sub'=>$uid,'iat'=>time(),'exp'=>time()+$ttl]));
    $sig = self::sign("$header.$payload", getenv('JWT_SECRET'));
    return "$header.$payload.$sig";
  }
  public static function verify($jwt){
    $parts = explode('.', $jwt);
    if (count($parts)!==3) throw new \Exception('bad_token');
    list($h,$p,$s) = $parts;
    $calc = self::sign("$h.$p", getenv('JWT_SECRET'));
    if (!hash_equals($calc, $s)) throw new \Exception('bad_sig');
    $payload = json_decode(self::b64d($p), true);
    if (!$payload || (isset($payload['exp']) && time() > $payload['exp'])) throw new \Exception('expired');
    return $payload;
  }
  private static function sign($data, $secret){
    return self::b64(hash_hmac('sha256', $data, $secret, true));
  }
  private static function b64($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
  private static function b64d($s){ return base64_decode(strtr($s, '-_', '+/')); }
}
