<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إصلاح علة قديمة موثَّقة (خطوة ٩٠/٩١): عمود `semester` مُشار له فعليًا
 * بكود User (`$fillable`, `$casts`) وبتحقّق AuthController/ProfileController/
 * Staff\UserController منذ فترة، وترسله الواجهة فعليًا (نموذج التسجيل،
 * نافذة الإكمال الأولى «onboarding»، ولوحة تحكم الطاقم) — لكن العمود نفسه
 * لم يُنشأ أبدًا بقاعدة البيانات. هذا يعني عمليًا أن أي تسجيل حساب جديد أو
 * تعديل بروفايل يرسل قيمة `semester` فعلية يفشل حاليًا بخطأ SQL حي
 * ("no such column: semester" / "Unknown column"), لا مجرد كود ميت غير
 * مستخدَم — **علة نشطة على الإنتاج**، لا تحسين مؤجَّل.
 *
 * `unsignedTinyInteger` نفس نمط `terms.semester`/`courses.semester`
 * تمامًا (القيم ١ أو ٢ فقط، لا حاجة لعمود أكبر)، و`nullable` لأن الطالب
 * قد يسجّل سنته بلا فصل محدَّد بعد (نفس منطق `year` الحالي أصلًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'semester')) {
                $table->unsignedTinyInteger('semester')->nullable()->after('year');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'semester')) {
                $table->dropColumn('semester');
            }
        });
    }
};
