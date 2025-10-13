<?php
namespace App\Services;

class AIService {
  public static function generate($prompt, $opts = []){
    $provider = isset($opts['provider']) ? strtolower($opts['provider']) : strtolower(getenv('AI_PROVIDER') ?: 'gemini');
    if ($provider === 'openrouter') {
      return OpenRouterService::generate($prompt, $opts);
    }
    return GeminiService::generate($prompt);
  }
  public static function extractJson($text){
    $o = GeminiService::extractJson($text);
    if ($o) return $o;
    return OpenRouterService::extractJson($text);
  }
}
