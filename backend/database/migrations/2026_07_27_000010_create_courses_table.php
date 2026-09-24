<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('code', 50)->index();
            $table->string('name_ar', 190);
            $table->string('name_en', 190);
            $table->unsignedSmallInteger('year')->index();
            $table->unsignedSmallInteger('semester')->index();
            $table->string('page', 100)->nullable();
            $table->string('keywords', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
