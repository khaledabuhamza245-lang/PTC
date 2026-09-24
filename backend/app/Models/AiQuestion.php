<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiQuestion extends Model
{
    protected $fillable = [
        'user_id',
        'conversation_id',
        'message',
        'course_name',
        'status',
        'reply',
        'error_message',
        'attempts',
        'referenced_course_file_id',
        'cache_mode',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->belongsToMany(AiAttachment::class, 'ai_question_attachments');
    }

    /*
     * الملف الذي طابقه CourseFileMatcher من نص السؤال (قسم ٥: "اشرحلي
     * ملف كذا") — لا علاقة له بمرفقات الطالب نفسه (attachments أعلاه).
     */
    public function referencedCourseFile()
    {
        return $this->belongsTo(CourseFile::class, 'referenced_course_file_id');
    }
}