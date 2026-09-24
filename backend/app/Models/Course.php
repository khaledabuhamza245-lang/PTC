<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory;

    /*
     * حذف المساق يمحو بالـ cascade تسجيلات كل الطلاب فيه وتقدّمهم.
     * الحذف الناعم يجعله وسمًا قابلًا للتراجع بدل محو نهائي.
     */
    use SoftDeletes;

    /*
     * فخّ صامت لولا هذه القائمة: updateOrCreate يتجاهل أي مفتاح خارج
     * $fillable بلا خطأ ولا تحذير. لو بقيت الأعمدة الأكاديمية الأربعة
     * خارجها لمرّت البذور خضراء و credit_hours فارغة في ٧٥ صفًّا —
     * فالتوسعة جزء من الهجرة لا خطوة تالية لها.
     */
    protected $fillable = [
        'key', 'code', 'name_ar', 'name_en', 'year', 'semester', 'sort_order',
        'page', 'keywords', 'is_active',
        'credit_hours', 'course_type', 'description', 'objectives',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'semester' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'credit_hours' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function files()
    {
        return $this->hasMany(CourseFile::class);
    }

    public function units()
    {
        return $this->hasMany(CourseUnit::class)->orderBy('sort_order')->orderBy('id');
    }

    /** التصنيفات العامة للمادة أو التصنيفات التابعة للوحدات. */
    public function sections()
    {
        return $this->hasMany(CourseSection::class)->orderBy('sort_order')->orderBy('id');
    }

    /** صفوف المتطلبات كما هي — بمفتاح مربوط أو برمز نصّي وحده. */
    public function prerequisites()
    {
        return $this->hasMany(CoursePrerequisite::class)->orderBy('id');
    }

    /** المساقات التي يفتحها هذا المساق — الاتجاه المعاكس للمتطلب. */
    public function requiredFor()
    {
        return $this->hasMany(CoursePrerequisite::class, 'prerequisite_course_id')->orderBy('id');
    }

    public function prepTopics()
    {
        return $this->hasMany(CoursePrepTopic::class)->orderBy('sort_order')->orderBy('id');
    }

    /** دليل أدوات المادة — أداة واحدة قد تخدم عدة مساقات. */
    public function tools()
    {
        return $this->belongsToMany(Tool::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('course_tool.sort_order')
            ->orderBy('tools.id');
    }

    /** صندوق الخبرة: نصائح الطلاب المتراكمة لهذا المساق. */
    public function tips()
    {
        return $this->hasMany(CourseTip::class)->latest();
    }
}
