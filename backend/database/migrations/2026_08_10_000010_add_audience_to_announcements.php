<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توجيه الإعلان: لكل الطلاب، أو لسنة، أو لمساق.
 *
 * ثلاثة أعمدة لا جدول وسيط: الإعلان يُوجَّه إلى **فئة واحدة** في كل مرة
 * (هذا نصّ طلب العميل)، فجدول many-to-many يشتري مرونة لا تُستعمل بثمن
 * انضمام في كل قراءة على مسار عام يُطلب مع كل تحميل صفحة.
 *
 * وكل خطوة محروسة بـ hasColumn في الاتجاهين — نمط المستودع على استضافة
 * بلا تراجع: إعادة التشغيل بعد انقطاع تكمل من حيث وقفت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            /*
             * all | year | course — بفهرس لأن كل قراءة عامة ترشّح عليه.
             * والقيمة الافتراضية all فالإعلانات القائمة تبقى للجميع كما
             * كانت، بلا سطر ترحيل واحد.
             */
            if (! Schema::hasColumn('announcements', 'audience')) {
                $table->string('audience', 10)->default('all')->after('body')->index();
            }

            if (! Schema::hasColumn('announcements', 'audience_year')) {
                $table->unsignedTinyInteger('audience_year')->nullable()->after('audience');
            }

            /*
             * nullOnDelete لا cascadeOnDelete: حذف مساق يجب ألّا يمحو
             * إعلانًا قرأه طلاب. والمساقات تُحذف حذفًا ناعمًا أصلًا فلا
             * ينطلق القيد — يبقى حارسًا للحالة الدائمة لا لحالة اليوم.
             */
            if (! Schema::hasColumn('announcements', 'course_id')) {
                $table->foreignId('course_id')
                    ->nullable()
                    ->after('audience_year')
                    ->constrained()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (Schema::hasColumn('announcements', 'course_id')) {
                $table->dropConstrainedForeignId('course_id');
            }

            foreach (['audience_year', 'audience'] as $column) {
                if (Schema::hasColumn('announcements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
