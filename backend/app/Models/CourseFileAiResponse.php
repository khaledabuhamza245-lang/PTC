<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * الرد الجاهز (تلخيص أو بطاقات مراجعة) لملف مساق بعينه — راجع تعليق
 * الهجرة create_course_file_ai_responses_table للسياق الكامل.
 */
class CourseFileAiResponse extends Model
{
    protected $fillable = [
        'course_file_id',
        'mode',
        'response_text',
    ];

    public function courseFile(): BelongsTo
    {
        return $this->belongsTo(CourseFile::class);
    }
}
