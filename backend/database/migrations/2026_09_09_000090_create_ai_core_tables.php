<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
/*
 * توثيق للجداول الأربعة كما هي فعليًا بقاعدة بيانات الإنتاج (تم
 * التحقق من بنيتها الحقيقية عبر information_schema بتاريخ إنشاء هذا
 * الملف). هذه الهجرة **موسومة كمُنفَّذة مسبقًا** بجدول migrations —
 * راجع تعليمات الرفع المرفقة — الغرض منها توثيق البنية لأي بيئة
 * جديدة لاحقًا (تطوير محلي، سيرفر بديل)، لا إعادة إنشاء الجداول على
 * الإنتاج الحالي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->longText('messages');
            $table->timestamps();
        });
 
        Schema::create('ai_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()
                ->constrained('ai_conversations')->cascadeOnDelete();
            $table->text('message');
            $table->string('course_name')->nullable();
            $table->enum('status', ['pending', 'done', 'failed'])->default('pending');
            $table->text('reply')->nullable();
            $table->string('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
 
        Schema::create('ai_answer_cache', function (Blueprint $table) {
            $table->id();
            $table->string('question_hash', 64)->unique();
            $table->text('question_sample');
            $table->text('reply');
            $table->unsignedInteger('hit_count')->default(1);
            $table->timestamps();
        });
 
        Schema::create('course_file_ai_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_file_id')->constrained('course_files')->cascadeOnDelete();
            $table->string('google_file_uri');
            $table->string('google_file_name');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('course_file_ai_meta');
        Schema::dropIfExists('ai_answer_cache');
        Schema::dropIfExists('ai_questions');
        Schema::dropIfExists('ai_conversations');
    }
};
 

