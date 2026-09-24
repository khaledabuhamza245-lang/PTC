<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيق فقط — التعديل الفعلي يُطبَّق يدويًا بـphpMyAdmin عبر
 * step5_run_this_in_phpmyadmin.sql (لا صلاحية Terminal/artisan على
 * الاستضافة). هذا الملف يوثّق ما تم لأي عملية migrate مستقبلية.
 *
 * يضيف user_id لجدول ai_answer_cache ويجعل الفريدة (question_hash,
 * user_id) بدل question_hash لوحده — لمنع تسريب إجابة مخصَّصة لطالب
 * معيّن (فيها اسمه وبياناته) لطالب آخر سأل نفس السؤال حرفيًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_answer_cache', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('question_hash')
                ->constrained('users')->cascadeOnDelete();
            $table->dropUnique(['question_hash']);
            $table->unique(['question_hash', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_answer_cache', function (Blueprint $table) {
            $table->dropUnique(['question_hash', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['question_hash']);
        });
    }
};
