<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * خطوة ١١٣ (تكملة): سؤال تلخيص/بطاقات جاي من بوت تيليجرام (لا من
 * صفحة الموقع) قد يرجع "pending" (202) لو الملف يحتاج تجهيزًا لأول
 * مرة — الموقع عنده استطلاع (polling) لصفحة /ai/result، لكن محادثة
 * تيليجرام لا "صفحة" تستطلع؛ لازم البوت نفسه يرسل الرد كرسالة جديدة
 * لما تجهّزه الدُفعة الدورية (processPending). هذا العمود يخزّن chat_id
 * تيليجرام المطلوب إشعاره عند اكتمال هذا السؤال بالذات — null لأي
 * سؤال عادي جاي من الموقع (لا تغيير بسلوكه إطلاقًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_questions', 'notify_telegram_chat_id')) {
                $table->string('notify_telegram_chat_id', 32)->nullable()->after('referenced_course_file_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_questions', function (Blueprint $table) {
            if (Schema::hasColumn('ai_questions', 'notify_telegram_chat_id')) {
                $table->dropColumn('notify_telegram_chat_id');
            }
        });
    }
};
