<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('course_units', 'course_section_id')) {
            return;
        }

        // فك الارتباط القديم (الوحدة كانت تابعة لقسم) لتصبح الوحدة تابعة للمادة مباشرة.
        Schema::table('course_units', function (Blueprint $table) {
            $table->dropForeign(['course_section_id']);
        });

        Schema::table('course_units', function (Blueprint $table) {
            $table->unsignedBigInteger('course_section_id')->nullable()->change();
            $table->foreign('course_section_id')
                ->references('id')
                ->on('course_sections')
                ->nullOnDelete();
        });

        $containerIds = DB::table('course_sections')
            ->where('slug', '__unit_container__')
            ->pluck('id');

        if ($containerIds->isNotEmpty()) {
            DB::table('course_units')
                ->whereIn('course_section_id', $containerIds)
                ->update(['course_section_id' => null]);

            DB::table('course_sections')->whereIn('id', $containerIds)->delete();
        }
    }

    public function down(): void
    {
        // لا نعيد البنية الدائرية القديمة؛ إبقاء الحقل nullable آمن للبيانات.
    }
};
