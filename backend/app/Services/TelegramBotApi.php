<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/*
 * أصغر غلاف ممكن حول Telegram Bot API — استدعاء HTTP عادي، بنفس
 * أسلوب استخدام Http::  الموجود أصلًا بـ TelegramUploadController.
 * لا مكتبة خارجية (SDK) — API تيليجرام نفسه بسيط بما يكفي.
 */
class TelegramBotApi
{
    private string $token;

    public function __construct()
    {
        $this->token = (string) config('services.telegram.bot_token', '');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function sendMessage(int|string $chatId, string $text): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        Http::timeout(10)->post(
            "https://api.telegram.org/bot{$this->token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]
        );
    }
}
