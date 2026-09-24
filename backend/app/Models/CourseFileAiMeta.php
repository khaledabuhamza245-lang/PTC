<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseFileAiMeta extends Model
{
    use HasFactory;

    protected $table = 'course_file_ai_meta';

    protected $fillable = [
        'course_file_id',
        'google_file_uri',
        'google_file_name',
        'google_mime_type',
        'chunks_json',
        'expires_at',
        'summary_text',
        'summary_generating_at',
        'flashcards_text',
        'flashcards_generating_at',
    ];

    /*
     * بدون هذا التحويل، Eloquent يرجّع expires_at كنص خام (string) من
     * قاعدة البيانات لا ككائن تاريخ (Carbon) — وأي استدعاء لدالة على
     * الكائن (مثل isFuture() في CourseFileGeminiPreparer::fresh())
     * يفشل فورًا بخطأ "Call to a member function ... on string". هذا
     * بالضبط سبب عطل processPending المتكرر (خطوة ٧٠). نفس السبب
     * بالضبط يفرض تحويل حقلي القفل الجديدين (خطوة ٧١) لتاريخ حقيقي —
     * وإلا فحص isGenerating() أدناه يفشل بنفس الطريقة.
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'summary_generating_at' => 'datetime',
            'flashcards_generating_at' => 'datetime',
        ];
    }

    public function courseFile()
    {
        return $this->belongsTo(CourseFile::class, 'course_file_id');
    }

    /*
     * ذاكرة ملخّصات/بطاقات الملف المشتركة (خطوة ٧١) — النص المخزَّن
     * هنا عام دائمًا (بلا اسم أو تخصيص طالب)، راجع
     * AiAssistantController::buildSystemInstruction وcacheFileTaskResult.
     */
    public function cachedText(string $mode): ?string
    {
        return $mode === 'flashcards' ? $this->flashcards_text : $this->summary_text;
    }

    /*
     * true فقط لو فيه توليد فعلي انطلق لهذا النمط بالتحديد خلال آخر
     * FILE_TASK_LOCK_MINUTES دقائق ولسا بلا نتيجة مخزَّنة — بعد هذي
     * المهلة يُعتبر القفل منتهي الصلاحية تلقائيًا (تعطّلت الجولة مثلًا)
     * فيُسمح بمحاولة جديدة بدل انتظار الأبد.
     */
    public function isGenerating(string $mode): bool
    {
        $at = $mode === 'flashcards' ? $this->flashcards_generating_at : $this->summary_generating_at;
        return $at !== null && $at->gt(now()->subMinutes(5));
    }
}