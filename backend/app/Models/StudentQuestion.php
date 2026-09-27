<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentQuestion extends Model
{
    protected $fillable = [
        'course_id',
        'user_id',
        'question',
        'answers_count',
    ];

    protected function casts(): array
    {
        return [
            'answers_count' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class, 'question_id');
    }
}
