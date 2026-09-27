<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAnswer extends Model
{
    protected $fillable = [
        'question_id',
        'user_id',
        'answer',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(StudentQuestion::class, 'question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
