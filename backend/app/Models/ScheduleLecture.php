<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleLecture extends Model
{
    protected $fillable = [
        'user_id',
        'course_key',
        'name',
        'instructor',
        'type',
        'days',
        'start_time',
        'end_time',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
