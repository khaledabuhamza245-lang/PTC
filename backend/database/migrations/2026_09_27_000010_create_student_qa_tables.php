<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيق فقط — التعديل الفعلي يُطبَّق يدويًا بـphpMyAdmin عبر
 * step94_run_this_in_phpmyadmin.sql (لا صلاحية Terminal/artisan على
 * الاستضافة، نفس بروتوكول gpa_entries).
 *
 * ميزة "🙋 مساعدة الطلاب" (بند من الأفكار الست المقترحة، جلسة سابعة) —
 * سؤال طالب بمادة معيّنة يُخزَّن + يُبَث لبقية الطلاب المسجَّلين بنفس
 * المادة (عبر my_courses.status='registered' + telegram_links)، وأي
 * إجابة تُخزَّن مربوطة بالسؤال وتُبلَّغ لصاحب السؤال تلقائيًا. الأسئلة
 * تبقى محفوظة كأرشيف قابل للتصفح لكل مادة (فايدة مستمرة لكل دفعة
 * جديدة تاخد نفس المادة لاحقًا)، لا مجرد بث لحظي يُنسى.
 *
 * هويّة السائل/المجيب تُخزَّن دائمًا (user_id) للمراجعة الإدارية
 * المستقبلية، لكن تُعرض للطلاب الآخرين بشكل عام ("طالب") حتى ما حدا
 * يتردد يسأل/يجاوب خجلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_questions')) {
            Schema::create('student_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->text('question');
                $table->unsignedInteger('answers_count')->default(0);
                $table->timestamps();

                $table->index(['course_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('student_answers')) {
            Schema::create('student_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('question_id')->constrained('student_questions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->text('answer');
                $table->timestamps();

                $table->index(['question_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answers');
        Schema::dropIfExists('student_questions');
    }
};
