<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * علة ثانية من نفس نوع "عمود مُشار له بالكود لكنه غير موجود فعليًا"
 * (اكتُشفت أثناء إصلاح خطوة ١١٢ لعمود users.semester — راجع الهجرة
 * السابقة لها بنفس التاريخ): `Announcement` (fillable/casts) و
 * `Staff\AnnouncementController::validated()` (قاعدة `required_if:audience,
 * year_semester`) يفترضان عمود `announcements.audience_semester` منذ
 * فترة، لكنه **لم يُنشأ أبدًا** — هجرة إضافة `audience` الأصلية
 * (`2026_08_10_000010`) أضافت `audience`/`audience_year`/`course_id`
 * فقط، بلا `audience_semester`.
 *
 * **علة إضافية مكتشَفة بنفس الهجرة**: عمود `audience` نفسه `VARCHAR(10)`
 * — القيمة `'year_semester'` طولها ١٣ حرفًا، أطول من الحد! على MySQL هذا
 * يعني اقتطاعًا صامتًا لقيمة الإعلان بدل خطأ واضح (تحذير `Data truncated`
 * لا استثناء)، فأي محاولة استخدام هذا الاستهداف كانت ستُخزَّن بقيمة
 * تالفة (`year_semes`) لا تطابق أي شرط `where('audience', 'year_semester')`
 * بأي مكان — فشل صامت خطير لولا اكتشافه هنا قبل أي استخدام فعلي.
 *
 * لا حاجة لـ`doctrine/dbal` (غير مثبَّت بالمشروع) لتعديل عمود موجود:
 * `ALTER TABLE ... MODIFY` مباشرة عبر `DB::statement()` على MySQL فقط
 * (نفس قيد "لا SSH" بهذا المشروع — لا حزمة إضافية تُضاف للاستضافة
 * المشتركة لأجل هجرة واحدة). SQLite (بيئة الاختبار) لا يفرض طول
 * VARCHAR أصلًا فلا حاجة لأي تعديل هناك.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE announcements MODIFY audience VARCHAR(20) NOT NULL DEFAULT 'all'");
        }

        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'audience_semester')) {
                $table->unsignedTinyInteger('audience_semester')->nullable()->after('audience_year');
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (Schema::hasColumn('announcements', 'audience_semester')) {
                $table->dropColumn('audience_semester');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE announcements MODIFY audience VARCHAR(10) NOT NULL DEFAULT 'all'");
        }
    }
};
