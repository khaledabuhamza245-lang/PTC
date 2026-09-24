<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'messages',
        'pinned_course_file_id',
    ];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /*
     * الملف "المثبَّت" على هذه المحادثة — أول ما يُلخَّص ملف عبر زر
     * "لخّصلي" أو يُذكَر اسمه بنص سؤال، يبقى مرفَقًا تلقائيًا مع كل
     * سؤال لاحق بنفس المحادثة (بلا حاجة لتكرار اسمه) لحد ما تبدأ
     * محادثة جديدة أو يُلخَّص ملف تاني بنفس المحادثة.
     */
    public function pinnedCourseFile()
    {
        return $this->belongsTo(CourseFile::class, 'pinned_course_file_id');
    }
}