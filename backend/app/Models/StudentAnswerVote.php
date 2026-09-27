<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAnswerVote extends Model
{
    protected $fillable = [
        'answer_id',
        'user_id',
        'vote',
    ];

    public function answer(): BelongsTo
    {
        return $this->belongsTo(StudentAnswer::class, 'answer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
