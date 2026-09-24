<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * يخزّن الملف الذي طابقه CourseFileMatcher من نص سؤال الطالب (قسم ٥:
 * "اشرحلي ملف كذا") — يُحفظ وقت إنشاء السؤال حتى لا يُعاد تخمين
 * المطابقة عند معالجته لاحقًا بالدُفعة الدورية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_questions', function (Blueprint $table) {
            $table->foreignId('referenced_course_file_id')->nullable()
                ->after('course_name')
                ->constrained('course_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referenced_course_file_id');
        });
    }
};
