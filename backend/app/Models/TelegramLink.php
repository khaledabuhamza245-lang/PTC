<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramLink extends Model
{
    protected $fillable = [
        'user_id',
        'link_token',
        'token_expires_at',
        'telegram_chat_id',
        'telegram_first_name',
        'linked_at',
        'mode',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'linked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return ! is_null($this->telegram_chat_id);
    }

    /*
     * "الوضع" الحالي المختار من القائمة الذكية (chat/debug/quiz/summarize)
     * — عمود جديد يحدد أي أداة يروح لها النص الحر يلي يبعته الطالب.
     * افتراضيًا "chat" (مساعد أسئلة عام) لأي حساب قديم قبل إضافة العمود.
     */
    public function currentMode(): string
    {
        $mode = (string) ($this->mode ?? '');

        return in_array($mode, ['chat', 'debug', 'quiz', 'summarize'], true) ? $mode : 'chat';
    }
}
