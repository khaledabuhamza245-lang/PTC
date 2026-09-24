<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAttachment extends Model
{
    protected $fillable = [
        'user_id',
        'display_name',
        'mime_type',
        'size_bytes',
        'provider_name',
        'provider_uri',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function questions()
    {
        return $this->belongsToMany(AiQuestion::class, 'ai_question_attachments');
    }
}
