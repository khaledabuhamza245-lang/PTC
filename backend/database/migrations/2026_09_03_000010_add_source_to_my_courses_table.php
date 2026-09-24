<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('my_courses', function (Blueprint $table) {
            if (! Schema::hasColumn('my_courses', 'source')) {
                $table->string('source', 20)
                    ->default('manual')
                    ->after('course_id')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('my_courses', function (Blueprint $table) {
            if (Schema::hasColumn('my_courses', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};