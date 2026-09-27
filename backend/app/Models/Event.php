<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * ميزة "📅 رادار الفرص والفعاليات" (جلسة سابعة، جزء 2) — جدول ومسار
 * مستقلّان تمامًا عن Announcement (نظام "📢 نشر إعلان" الموجود مسبقًا):
 * قرار متعمَّد بعدم لمس ذاك المسار المعقّد الشغّال أصلًا وقت العمل بلا
 * إشراف مباشر من المستخدم. Announcement يبقى للإعلانات الإدارية العامة
 * (تغييرات جدول، إشعارات رسمية...)، وEvent مخصص فقط لفرص/فعاليات
 * (مسابقات، ورشات، تدريب، منح...) يقدر أي عضو طاقم (isStaff()) ينشرها،
 * وتظهر بقائمة قابلة للتصفح لكل الطلاب مرتبة بالأحدث أولًا، مع تمييز
 * تلقائي للمنتهية (expires_at) بدل حذفها.
 */
class Event extends Model
{
    public const CATEGORY_EVENT = 'event';
    public const CATEGORY_OPPORTUNITY = 'opportunity';

    public const CATEGORIES = [self::CATEGORY_EVENT, self::CATEGORY_OPPORTUNITY];

    protected $fillable = [
        'category',
        'title',
        'description',
        'url',
        'expires_at',
        'posted_by',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
