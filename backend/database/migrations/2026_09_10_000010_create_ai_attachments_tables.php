<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 512);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('provider_name', 100)->unique();
            $table->text('provider_uri');
            $table->string('status', 20)->default('ready');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('ai_question_attachments', function (Blueprint $table) {
            $table->foreignId('ai_question_id')->constrained('ai_questions')->cascadeOnDelete();
            $table->foreignId('ai_attachment_id')->constrained('ai_attachments')->cascadeOnDelete();
            $table->primary(['ai_question_id', 'ai_attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_question_attachments');
        Schema::dropIfExists('ai_attachments');
    }
};
