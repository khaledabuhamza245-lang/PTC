<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentAnswer extends Model
{
    protected $fillable = [
        'question_id',
        'user_id',
        'answer',
        'helpful_count',
        'unhelpful_count',
    ];

    protected function casts(): array
    {
        return [
            'helpful_count' => 'integer',
            'unhelpful_count' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(StudentQuestion::class, 'question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(StudentAnswerVote::class, 'answer_id');
    }
}
