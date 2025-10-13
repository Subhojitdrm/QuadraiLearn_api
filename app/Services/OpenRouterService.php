<?php
namespace App\Services;

class OpenRouterService
{
  public static function generate($prompt, $opts = []){
    $apiKey = getenv('OPENROUTER_API_KEY');
    $url    = getenv('OPENROUTER_URL');
    $model  = isset($opts['model']) ? $opts['model'] : (getenv('OPENROUTER_MODEL') ?: 'google/gemini-2.0-flash-lite-001');

    $messages = [];
    if (!empty($opts['system'])) $messages[] = ['role'=>'system','content'=>(string)$opts['system']];
    $messages[] = ['role'=>'user','content'=>(string)$prompt];

    $payload = ['model'=>$model, 'messages'=>$messages];
    if (isset($opts['temperature'])) $payload['temperature'] = (float)$opts['temperature'];
    if (isset($opts['max_tokens']))  $payload['max_tokens']  = (int)$opts['max_tokens'];

    $headers = [
      'Content-Type: application/json',
      'Authorization: Bearer '.$apiKey,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code >= 400) { error_log("OpenRouter: $code $err $response"); return ''; }
    $json = json_decode($response, true);
    return isset($json['choices'][0]['message']['content'])
      ? (string)$json['choices'][0]['message']['content'] : '';
  }

  public static function extractJson($text){
    if (!$text) return null;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) { $j=json_decode(trim($m[1]), true); if ($j) return $j; }
    $s = strpos($text,'{'); $e = strrpos($text,'}');
    if ($s!==false && $e!==false && $e>$s) { $j=json_decode(substr($text,$s,$e-$s+1), true); if ($j) return $j; }
    $s = strpos($text,'['); $e = strrpos($text,']');
    if ($s!==false && $e!==false && $e>$s) { $a=json_decode(substr($text,$s,$e-$s+1), true); if (is_array($a)) return ["chapters"=>$a]; }
    return null;
  }
}
