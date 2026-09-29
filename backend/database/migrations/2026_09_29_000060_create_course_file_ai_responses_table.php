<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * خطوة ١١٤: تخزين الرد النهائي الجاهز (لا بس ملف Gemini المرفوع —
 * ذاك مخزَّن أصلًا بـcourse_file_ai_meta) لكل زوج (ملف، وضع). أول
 * طالب يطلب تلخيص/بطاقات ملف بعينه يولّد الرد ويُخزَّن هون تلقائيًا؛
 * أي طالب تاني بعده (من الموقع أو البوت، بغض النظر) يستلم نفس الرد
 * فورًا من قاعدة البيانات بلا أي استدعاء Gemini إضافي — يلغي عمليًا
 * انتظار "التجهيز الأول" لأي حد إلا أول شخص فعليًا يطلب ذاك الملف
 * بالذات، بلا حاجة لتوليد مسبق لكل ملف يترفع (أغلبها لن يُطلب أبدًا).
 *
 * unique(course_file_id, mode): سجل واحد فقط لكل زوج — updateOrCreate
 * يستبدله لو أُعيد توليده لأي سبب (لا آلية إبطال تلقائية حاليًا لو
 * الطاقم استبدل محتوى الملف نفسه، نفس قيد course_file_ai_meta تمامًا
 * — خارج نطاق هذه الخطوة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_file_ai_responses')) {
            Schema::create('course_file_ai_responses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_file_id')->constrained()->cascadeOnDelete();
                $table->string('mode', 20);
                $table->longText('response_text');
                $table->timestamps();

                $table->unique(['course_file_id', 'mode']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_file_ai_responses');
    }
};
