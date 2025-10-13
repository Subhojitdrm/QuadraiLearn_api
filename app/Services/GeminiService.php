<?php
namespace App\Services;

use App\Support\Logger;

class GeminiService
{
  public static function generate($prompt){
    $apiKey = getenv('GEMINI_API_KEY');
    $url = getenv('GEMINI_URL');

    $payload = [
      "contents" => [[ "parts" => [[ "text" => $prompt ]]]],
      "generationConfig" => [ "thinkingConfig" => [ "thinkingBudget" => 0 ] ]
    ];

    $ch = curl_init($url . '?key=' . urlencode($apiKey));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code >= 400) {
      Logger::log("Gemini Error: [HTTP $code] $err - Response: $response");
      return '';
    }
    $json = json_decode($response, true);
    return isset($json['candidates'][0]['content']['parts'][0]['text'])
      ? $json['candidates'][0]['content']['parts'][0]['text']
      : '';
  }

  public static function extractJson($text){
    if (!$text) return null;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
      $j = json_decode(trim($m[1]), true); if ($j) return $j;
    }
    $s = strpos($text,'{'); $e = strrpos($text,'}');
    if ($s!==false && $e!==false && $e>$s) { $j=json_decode(substr($text,$s,$e-$s+1), true); if ($j) return $j; }
    $s = strpos($text,'['); $e = strrpos($text,']');
    if ($s!==false && $e!==false && $e>$s) { $a=json_decode(substr($text,$s,$e-$s+1), true); if (is_array($a)) return ["chapters"=>$a]; }
    return null;
  }
}
