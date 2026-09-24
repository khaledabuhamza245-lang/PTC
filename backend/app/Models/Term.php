<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * فصل دراسي.
 *
 * الحالي يُضبط يدويًا من لوحة الأدمن لا من التاريخ: التقويم الرسمي
 * غير متاح، والاستضافة بلا cron مضمون — فحسابه آليًا يبني قرارًا
 * على تواريخ قد تكون فارغة أصلًا.
 */
class Term extends Model
{
    protected $fillable = [
        'code', 'label', 'academic_year', 'semester',
        'starts_on', 'ends_on', 'is_current', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public static function current(): ?self
    {
        return static::where('is_current', true)->first();
    }
}
