<?php
// Environment-style config (no Composer)
return [
  'APP_ENV' => 'local',
  'AI_PROVIDER' => 'gemini', // or 'openrouter'

  // Database
  'DB_HOST' => '127.0.0.1',
  'DB_NAME' => 'calmconq_quadravise_library',
  'DB_USER' => 'calmconq_quadraviseadmin',
  'DB_PASS' => 'calmconq_quadraviseadmin',

  // Auth
  'JWT_SECRET' => 'change-this-very-secret',

  // CORS (comma-separated)
  'FRONTEND_ORIGINS' => 'http://localhost:5173,http://localhost:3000,https://yourdomain.com',

  // Gemini
  'GEMINI_URL' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
  'GEMINI_API_KEY' => 'PASTE_YOUR_GEMINI_KEY',

  // OpenRouter
  'OPENROUTER_URL' => 'https://openrouter.ai/api/v1/chat/completions',
  'OPENROUTER_API_KEY' => 'PASTE_YOUR_OPENROUTER_KEY',
  'OPENROUTER_MODEL' => 'google/gemini-2.0-flash-lite-001',

  // Soft rate hints (you can enforce in middleware later)
  'RATE_RPM' => 9,
  'RATE_RPD' => 240,
];
