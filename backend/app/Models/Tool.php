<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tool extends Model
{
    /** الأنواع الأربعة المعتمدة — مصدرها الوحيد هنا. */
    public const TYPES = ['software', 'online', 'concept', 'library'];

    protected $fillable = [
        'name',
        'type',
        'description',
        'official_url',
        'video_url',
        'explanation',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class)
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
