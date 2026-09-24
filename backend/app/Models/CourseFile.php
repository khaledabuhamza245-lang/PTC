<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseFile extends Model
{
    protected $hidden = [
        'storage_disk',
        'storage_path',
    ];

    protected $fillable = [
        'course_id', 'course_section_id', 'course_unit_id', 'title', 'description', 'kind', 'storage_disk', 'storage_path',
        'external_url', 'original_name', 'mime_type', 'size_bytes',
        'sort_order', 'counts_toward_progress', 'is_published', 'visibility', 'status', 'created_by',
        'ai_summarizable',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'counts_toward_progress' => 'boolean',
            'is_published' => 'boolean',
            'ai_summarizable' => 'boolean',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function section(){ return $this->belongsTo(CourseSection::class, 'course_section_id'); }

    public function unit(){ return $this->belongsTo(CourseUnit::class, 'course_unit_id'); }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canBeViewedBy(?User $user, bool $isCourseStudent = false): bool
    {
        return match ($this->visibility) {
            'public' => true,
            'authenticated' => $user !== null,
            'staff_only' => $user?->isStaff() ?? false,
            'course_students' => $isCourseStudent,
            default => false,
        };
    }

    /**
     * هل يظهر هذا المحتوى للقارئ في المسارات العامة؟
     *
     * يجمع الشروط الثلاثة في موضع واحد: جاهزية الملف، ونشره، وقاعدة
     * الظهور. كانت مفرّقة على ثلاثة كنترولرات فانحرفت — CourseStructureController
     * يفحص is_published بينما CourseFileController لا يفحصه، فكانت
     * المسوّدات مقروءة وقابلة للتحميل لأي زائر.
     *
     * أي مسار عام جديد يجب أن يمر من هنا لا أن يعيد تركيب الشرط.
     */
    public function isVisibleTo(?User $user, bool $isCourseStudent = false): bool
    {
        return $this->status === 'ready'
            && $this->is_published
            && $this->canBeViewedBy($user, $isCourseStudent);
    }
}