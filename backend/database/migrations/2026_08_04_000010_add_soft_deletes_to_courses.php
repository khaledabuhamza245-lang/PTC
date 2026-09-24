<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف ناعم للمساقات.
 *
 * حذف مساق يُطلق سلسلة cascade تمحو my_courses و course_progress
 * و course_files — أي تسجيلات كل الطلاب في المساق وتقدّمهم فيه، بلا
 * استرجاع. مع الحذف الناعم يصير الحذف وسمًا بـ deleted_at فلا تُطلق
 * السلسلة، ويبقى كل شيء قابلًا للاسترجاع بـ restore().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('courses', 'deleted_at')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('courses', 'deleted_at')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
