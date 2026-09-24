<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * متطلب سابق.
 *
 * الصفّ إمّا مربوط بمساق حقيقي، وإمّا يحمل رمزًا نصّيًا وحده مع
 * needs_review. الثاني ليس عطلًا بل الحالة الوسطى الصادقة: البيانات
 * الأكاديمية لا تُخمَّن، فالرمز الذي لا يقابل مساقًا يُعرض كما ورد
 * ويُدرَج في قائمة مراجعة الأدمن بدل أن يُطابَق بأقرب شبيه.
 */
class CoursePrerequisite extends Model
{
    protected $fillable = [
        'course_id', 'prerequisite_course_id', 'prerequisite_code_raw', 'needs_review',
    ];

    protected function casts(): array
    {
        return ['needs_review' => 'boolean'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function prerequisite()
    {
        return $this->belongsTo(Course::class, 'prerequisite_course_id');
    }
}
