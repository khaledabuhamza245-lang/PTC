<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قلب بنية المحتوى.
 *
 * قبل: المادة ← القسم ← الوحدة ← المحتوى
 * بعد: المادة ← القسم ← الوحدة ← التصنيف ← المحتوى
 *
 * يضيف هذا الملف العمودين اللذين تعتمد عليهما الموديلات والكنترولرات:
 *
 *   course_units.course_id          ربط الوحدة بالمادة مباشرة
 *                                   (App\Models\CourseUnit::course)
 *
 *   course_sections.course_unit_id  يجعل course_sections تؤدي دورين:
 *                                   NULL     = قسم رئيسي
 *                                   غير NULL = تصنيف داخل وحدة
 *                                   (App\Models\CourseUnit::categories)
 *
 * الملف مكتوب ليكون آمن التكرار: كل خطوة محروسة بـ hasColumn، فتشغيله
 * على قاعدة تحتوي العمودين مسبقًا لا يفعل شيئًا ولا يفشل.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addCourseIdToUnits();
        $this->addUnitIdToSections();
    }

    public function down(): void
    {
        if (Schema::hasColumn('course_sections', 'course_unit_id')) {
            Schema::table('course_sections', function (Blueprint $table) {
                $table->dropConstrainedForeignId('course_unit_id');
            });
        }

        if (Schema::hasColumn('course_units', 'course_id')) {
            Schema::table('course_units', function (Blueprint $table) {
                $table->dropConstrainedForeignId('course_id');
            });
        }
    }

    /**
     * course_units.course_id — الوحدة تتبع المادة مباشرة.
     *
     * يُضاف قابلًا لـ NULL أولًا، ثم تُملأ القيم من المادة التي يتبعها
     * القسم الأب، ثم يُشدَّد إلى NOT NULL. هذا الترتيب يسمح بتشغيل
     * الترحيل على قاعدة تحتوي بيانات فعلية دون فشل.
     */
    private function addCourseIdToUnits(): void
    {
        if (Schema::hasColumn('course_units', 'course_id')) {
            return;
        }

        Schema::table('course_units', function (Blueprint $table) {
            $table->unsignedBigInteger('course_id')->nullable();
        });

        // الوحدة كانت تتبع قسمًا، والقسم يتبع مادة — نأخذ المادة من القسم.
        // استعلام مترابط قياسي يعمل على MySQL و PostgreSQL معًا.
        DB::statement('
            UPDATE course_units
            SET course_id = (
                SELECT course_sections.course_id
                FROM course_sections
                WHERE course_sections.id = course_units.course_section_id
            )
            WHERE course_id IS NULL
        ');

        // لا نشدّد القيد إلا إذا اكتمل الملء فعلًا، حتى لا ينهار الترحيل
        // على بيانات غير متسقة ويترك القاعدة في منتصف الطريق.
        $unresolved = DB::table('course_units')->whereNull('course_id')->count();

        if ($unresolved === 0) {
            Schema::table('course_units', function (Blueprint $table) {
                $table->unsignedBigInteger('course_id')->nullable(false)->change();
            });
        }

        Schema::table('course_units', function (Blueprint $table) {
            $table->foreign('course_id')
                ->references('id')
                ->on('courses')
                ->cascadeOnDelete();

            $table->index(['course_id', 'sort_order']);
        });
    }

    /**
     * course_sections.course_unit_id — يحوّل السجل إلى تصنيف داخل وحدة.
     *
     * يبقى قابلًا لـ NULL لأن القيمة NULL هي ما يميّز القسم الرئيسي عن
     * التصنيف، وهو التمييز الذي تعتمد عليه الاستعلامات في كل مكان.
     *
     * الحذف تسلسلي: حذف الوحدة يحذف تصنيفاتها. البديل (nullOnDelete)
     * كان سيحوّل التصنيفات اليتيمة إلى أقسام رئيسية وهمية تظهر للطالب.
     */
    private function addUnitIdToSections(): void
    {
        if (Schema::hasColumn('course_sections', 'course_unit_id')) {
            return;
        }

        Schema::table('course_sections', function (Blueprint $table) {
            $table->foreignId('course_unit_id')
                ->nullable()
                ->constrained('course_units')
                ->cascadeOnDelete();

            $table->index(['course_unit_id', 'sort_order']);
        });
    }
};
