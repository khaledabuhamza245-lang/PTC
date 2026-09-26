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
        'reminders_enabled',
        'pending_action',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'linked_at' => 'datetime',
            'reminders_enabled' => 'boolean',
            'pending_action' => 'array',
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
     * "الوضع" الحالي المختار من القائمة الذكية — عمود يحدد أي أداة
     * يروح لها النص الحر يلي يبعته الطالب. افتراضيًا "chat" (مساعد
     * أسئلة عام) لأي حساب قديم قبل إضافة العمود.
     *
     * "debug_explain" و"debug_optimize" تفريعان عن "debug" نفسه (نفس
     * أداة "مصحّح أكواد" بالقائمة، لكن اختيار فرعي: شرح الكود أو
     * تحسين أدائه بدل تصحيح أخطائه) — راجع TelegramAiAssistant::
     * explainCode()/optimizeCode() وsendDebugHubCard() بالـwebhook.
     */
    public function currentMode(): string
    {
        $mode = (string) ($this->mode ?? '');

        return in_array($mode, ['chat', 'debug', 'debug_explain', 'debug_optimize', 'quiz', 'summarize'], true)
            ? $mode
            : 'chat';
    }

    /*
     * "pending_action" — حالة محادثة متعددة الخطوات لإضافة/تعديل/حذف
     * محاضرة من داخل البوت نفسه (راجع TelegramWebhookController).
     * JSON بصيغة: {action, step, lecture_id, data:{...}}. null يعني
     * الطالب مش بمنتصف أي عملية جدول حاليًا — بهاي الحالة أي نص بيروح
     * لأداة الذكاء الاصطناعي المختارة (currentMode) كالمعتاد.
     */
    public function isInScheduleFlow(): bool
    {
        return is_array($this->pending_action) && ! empty($this->pending_action['action'] ?? null);
    }
}
