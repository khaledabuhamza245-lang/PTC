<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CourseContentProgress extends Model
{
    protected $table='course_content_progress';
    protected $fillable=['user_id','course_file_id','completed_at'];
    protected function casts(): array { return ['completed_at'=>'datetime']; }
    public function courseFile(){return $this->belongsTo(CourseFile::class,'course_file_id');}
}
