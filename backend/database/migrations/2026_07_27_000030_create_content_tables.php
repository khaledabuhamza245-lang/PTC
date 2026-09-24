<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('course_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->string('kind', 20)->default('pdf');
            $table->string('storage_disk', 50)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 190)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('visibility', 30)->default('public')->index();
            $table->string('status', 20)->default('ready')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 190);
            $table->text('body')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('course_files');
    }
};
