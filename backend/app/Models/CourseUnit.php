<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseUnit extends Model
{
    protected $fillable = [
        'course_id',
        'course_section_id',
        'title',
        'description',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * القسم الرئيسي الذي توجد داخله الوحدة.
     */
    public function section()
    {
        return $this->belongsTo(
            CourseSection::class,
            'course_section_id'
        );
    }

    /**
     * إبقاء الاسم القديم مؤقتًا حتى لا ينكسر أي كود سابق.
     */
    public function legacySection()
    {
        return $this->section();
    }

    /**
     * التصنيفات الموجودة داخل الوحدة.
     */
    public function categories()
    {
        return $this->hasMany(
            CourseSection::class,
            'course_unit_id'
        )
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function contents()
    {
        return $this->hasMany(
            CourseFile::class,
            'course_unit_id'
        )
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}