<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseSection extends Model
{
    protected $fillable = [
        'course_id',
        'course_unit_id',
        'title',
        'slug',
        'icon',
        'description',
        'sort_order',
        'counts_toward_progress',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'counts_toward_progress' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * الوحدة التي يتبع لها السجل عندما يكون تصنيفًا.
     */
    public function unit()
    {
        return $this->belongsTo(
            CourseUnit::class,
            'course_unit_id'
        );
    }

    /**
     * الوحدات الموجودة داخل هذا القسم الرئيسي.
     */
    public function units()
    {
        return $this->hasMany(
            CourseUnit::class,
            'course_section_id'
        )
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function contents()
    {
        return $this->hasMany(
            CourseFile::class,
            'course_section_id'
        )
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}