<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationCheckpoint extends Model
{
    protected $fillable = [
        'user_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
