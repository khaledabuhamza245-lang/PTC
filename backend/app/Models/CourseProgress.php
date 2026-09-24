<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseProgress extends Model
{
    protected $fillable = ['user_id', 'course_id', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
