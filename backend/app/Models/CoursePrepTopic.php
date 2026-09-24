<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** موضوع يُنصح بمراجعته قبل المساق. */
class CoursePrepTopic extends Model
{
    protected $fillable = ['course_id', 'topic', 'link', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }
}
