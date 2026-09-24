<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    protected $fillable = [
        'user_id',
        'course_file_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function courseFile()
    {
        return $this->belongsTo(CourseFile::class);
    }
}
