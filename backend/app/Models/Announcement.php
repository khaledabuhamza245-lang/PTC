<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class Announcement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'active',
        'reminder_count',
        'created_by',
        'audience',
        'audience_year',
        'audience_semester',
        'course_id',
    ];
 
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'reminder_count' => 'integer',
            'audience_year' => 'integer',
            'audience_semester' => 'integer',
        ];
    }
 
    /**
     * المساق المقصود حين يكون التوجيه إليه.
     *
     * تُحمَّل بأعمدة محدّدة في المسار العام لا كاملة: الحمولة تُطلب مع
     * كل تحميل صفحة، والطالب يحتاج الاسم والرمز لا صفَّ المساق كله.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
 
