<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ترتيب المواد داخل الفصل، وآخر تسجيل دخول للمستخدم.
 *
 * عمودان في هجرة واحدة لأنهما يُشحنان في دفعة واحدة — والاستضافة بلا
 * تراجع، فكل تشغيل ترحيل لحظة خطر تُجمَع لا تُفرَّق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            /*
             * الترتيب داخل السنة والفصل.
             *
             * `year` و`semester` يحدّدان أين تقع المادة، ولا يحدّدان
             * ترتيبها بين أخواتها في الفصل نفسه — كان الترتيب يتبع
             * `id`، أي ترتيب الإدخال، وهو لا يعني شيئًا للخطة.
             */
            if (! Schema::hasColumn('courses', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('semester');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * «آخر تسجيل دخول» لا «آخر نشاط» — والتسمية دقيقة عمدًا:
             * الموقع يستعمل رموزًا طويلة العمر، فطالب يفتحه يوميًا شهرًا
             * بلا تسجيل دخول جديد يبقى تاريخه قديمًا. قياس النشاط الفعلي
             * يعني كتابة في القاعدة مع كل تحميل صفحة، وهو ما ترفضه
             * استضافة مشتركة.
             */
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_login_at')) {
                $table->dropColumn('last_login_at');
            }
        });
    }
};
