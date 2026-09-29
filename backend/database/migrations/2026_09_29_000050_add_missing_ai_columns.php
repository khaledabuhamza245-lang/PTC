<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * خطوة ١١٣: نفس نوع العلة اللي اكتُشفت بخطوة ١١٢ بالضبط (عمود مُشار
 * له بالكود — fillable/casts — بلا أي هجرة تُنشئه فعليًا)، هالمرة
 * بعمودين اكتُشفا أثناء تشغيل الاختبارات محليًا لميزة تحويل "لخّصلي"/
 * "بطاقات مراجعة" لبوت تيليجرام (خطوة ١١٣):
 *
 * - `course_files.ai_summarizable`: يستخدمه CourseFile::$fillable/
 *   $casts وStaff\CourseFileController::store/update (تحكّم الطاقم
 *   بإمكانية تلخيص ملف بعينه) منذ ميزة "لخّصلي" الأصلية — بلا هذا
 *   العمود، أي إنشاء ملف مساق جديد كان (أو كان يفترض أن) يفشل بخطأ
 *   SQL فورًا (نفس نمط خطأ users.semester بخطوة ١١٢).
 * - `ai_conversations.pinned_course_file_id`: يستخدمه AiConversation::
 *   $fillable وAiAssistantController::summarizeFile/performSummarize
 *   (تثبيت الملف الملخَّص على المحادثة). بلا هذا العمود، أي طلب
 *   "لخّصلي" كان (أو كان يفترض أن) يفشل بنفس الشكل.
 *
 * لم يُلغَ توليد `cache_mode` بـAiQuestion (نفس الملف) لأنه غير
 * مُستخدَم فعليًا بأي مكان بالكود (حقل fillable ميت، لا يُمرَّر له
 * أي قيمة عند أي create()) — لا خطر SQL فعلي منه، فلا حاجة لعمود جديد
 * لحقل لا يُكتَب إليه أصلًا.
 *
 * القيمة الافتراضية لـai_summarizable هون false (لا true) تحديدًا
 * للصفوف القديمة (لو وُجدت) التي أُنشئت قبل هذا العمود — أي ملف موجود
 * مسبقًا يبقى غير قابل للتلخيص افتراضيًا حتى يراجعه الطاقم صراحة، بدل
 * افتراض "قابل للتلخيص" لملف لم يُفحص لهذا الغرض من الأساس. كل ملف
 * *جديد* بعد هذا يمر أصلًا عبر defaultAiSummarizable() فيُحدَّد صراحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_files', function (Blueprint $table) {
            if (! Schema::hasColumn('course_files', 'ai_summarizable')) {
                $table->boolean('ai_summarizable')->default(false)->after('status');
            }
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_conversations', 'pinned_course_file_id')) {
                $table->foreignId('pinned_course_file_id')
                    ->nullable()
                    ->after('title')
                    ->constrained('course_files')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('ai_conversations', 'pinned_course_file_id')) {
                $table->dropConstrainedForeignId('pinned_course_file_id');
            }
        });

        Schema::table('course_files', function (Blueprint $table) {
            if (Schema::hasColumn('course_files', 'ai_summarizable')) {
                $table->dropColumn('ai_summarizable');
            }
        });
    }
};
