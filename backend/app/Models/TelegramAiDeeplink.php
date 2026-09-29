<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * توكن مؤقّت (١٠ دقائق، استخدام واحد) يربط ضغطة زر "لخّصلي"/"بطاقات
 * مراجعة" بصفحة المادة بالموقع بإجراء محدد داخل بوت تيليجرام — راجع
 * تعليق الهجرة create_telegram_ai_deeplinks_table للسياق الكامل.
 */
class TelegramAiDeeplink extends Model
{
    protected $fillable = [
        'token',
        'user_id',
        'course_file_id',
        'mode',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function courseFile(): BelongsTo
    {
        return $this->belongsTo(CourseFile::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
