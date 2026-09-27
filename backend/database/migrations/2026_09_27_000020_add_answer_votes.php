<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيق فقط — التعديل الفعلي يُطبَّق يدويًا بـphpMyAdmin عبر
 * step95_run_this_in_phpmyadmin.sql (نفس بروتوكول step94/gpa_entries).
 *
 * إضافة على ميزة "🙋 مساعدة الطلاب" (خطوة 94) — طلب المستخدم صراحة:
 * إتاحة تصويت الطلاب الآخرين على دقّة كل إجابة ("✅ صحيحة" / "❌ غير
 * دقيقة") حتى تبقى الإجابات المحفوظة بالأرشيف قابلة للثقة لمن يقرأها
 * لاحقًا. جدول تصويت منفصل (لا عمودين بس على student_answers) لمنع
 * نفس الطالب من التصويت أكثر من مرة على نفس الإجابة (unique)، مع
 * السماح له يبدّل رأيه (تحديث الصف نفسه بدل صف جديد).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('student_answers', 'helpful_count')) {
            Schema::table('student_answers', function (Blueprint $table) {
                $table->unsignedInteger('helpful_count')->default(0)->after('answer');
                $table->unsignedInteger('unhelpful_count')->default(0)->after('helpful_count');
            });
        }

        if (! Schema::hasTable('student_answer_votes')) {
            Schema::create('student_answer_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('answer_id')->constrained('student_answers')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('vote', 4); // 'up' أو 'down'
                $table->timestamps();

                $table->unique(['answer_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answer_votes');

        if (Schema::hasColumn('student_answers', 'helpful_count')) {
            Schema::table('student_answers', function (Blueprint $table) {
                $table->dropColumn(['helpful_count', 'unhelpful_count']);
            });
        }
    }
};
