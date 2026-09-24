<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentReport extends Model
{
    use HasFactory;

    public const REASONS = [
        'broken_link' => 'الرابط لا يعمل',
        'wrong_file' => 'الملف خاطئ',
        'duplicate' => 'الملف مكرر',
        'wrong_title' => 'الاسم غير صحيح',
        'wrong_course' => 'المساق غير صحيح',
        'inappropriate' => 'محتوى غير مناسب',
        'other' => 'سبب آخر',
    ];

    protected $fillable = [
        'course_id',
        'course_file_id',
        'user_id',
        'reason',
        'note',
        'resolved_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function courseFile()
    {
        return $this->belongsTo(CourseFile::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
