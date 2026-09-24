<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->string('slug', 190)->nullable();
            $table->string('icon', 50)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('counts_toward_progress')->default(false);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('course_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->index(['course_section_id', 'sort_order']);
        });

        Schema::table('course_files', function (Blueprint $table) {
            $table->foreignId('course_section_id')->nullable()->after('course_id')->constrained()->nullOnDelete();
            $table->foreignId('course_unit_id')->nullable()->after('course_section_id')->constrained()->nullOnDelete();
            $table->text('description')->nullable()->after('title');
            $table->boolean('counts_toward_progress')->default(false)->after('sort_order');
            $table->boolean('is_published')->default(true)->after('counts_toward_progress');
        });

        Schema::create('course_content_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_file_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_content_progress');
        Schema::table('course_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_unit_id');
            $table->dropConstrainedForeignId('course_section_id');
            $table->dropColumn(['description', 'counts_toward_progress', 'is_published']);
        });
        Schema::dropIfExists('course_units');
        Schema::dropIfExists('course_sections');
    }
};
