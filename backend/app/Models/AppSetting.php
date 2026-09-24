<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إعداد عام مخزَّن في القاعدة لا في الكود.
 *
 * رقم التخرّج ١٤٢ وسقف الاختياري ٥ ومعرّف تليجرام — ثلاثتها قابلة
 * للتغيير بقرار إداري لا بنشر جديد. وضعها في القاعدة يجعل تعديلها
 * صفًّا واحدًا بدل مطاردة ستّة مواضع في الكود.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    /**
     * قراءة عدد صحيح موجب مع قيمة بديلة.
     *
     * البديل ليس ترفًا: قاعدة لم تُبذَر بعد تعيد null، وقسمة على null
     * ترمي في PHP 8. الحاسبة يجب ألّا تنهار لأن صفًّا مفقود.
     */
    public static function int(string $key, int $fallback): int
    {
        $value = static::where('key', $key)->value('value');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }

    public static function get(string $key, ?string $fallback = null): ?string
    {
        $value = static::where('key', $key)->value('value');

        return $value === null || $value === '' ? $fallback : $value;
    }
}
