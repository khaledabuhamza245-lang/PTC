<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class AiAnswerCache extends Model
{
    protected $table = 'ai_answer_cache';
 
    protected $fillable = [
        'question_hash',
        'user_id',
        'question_sample',
        'reply',
        'hit_count',
    ];
}
 

