<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramLink extends Model
{
    protected $fillable = [
        'user_id',
        'link_token',
        'token_expires_at',
        'telegram_chat_id',
        'telegram_first_name',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'linked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return ! is_null($this->telegram_chat_id);
    }
}
