<?php
 
return [
 
    'telegram_contributions' => [
        'web_app_url' => env('TELEGRAM_CONTRIBUTIONS_WEB_APP_URL'),
        'shared_secret' => env('TELEGRAM_CONTRIBUTIONS_SHARED_SECRET'),
    ],

    /*
     * بوت "المساعد الأكاديمي" الجديد — منفصل تمامًا عن بوت المساهمة
     * بالمحتوى أعلاه (telegram_contributions، توكن مختلف بالكامل).
     */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],
 
    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
    ],
 
];
 

