<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GpaEntry extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'grade',
    ];

    protected function casts(): array
    {
        return [
            'grade' => 'float',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
