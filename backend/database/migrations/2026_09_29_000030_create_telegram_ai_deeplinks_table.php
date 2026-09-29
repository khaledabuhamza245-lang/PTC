<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * خطوة ١١٣: تحويل زرّي "لخّصلي" و"بطاقات مراجعة" بصفحة المادة من
 * الموقع لتشغيل شات AI الموقع (AiAssistantController) إلى تحويل
 * الطالب مباشرة لشات بوت تيليجرام (يعيد استخدام نفس منطق التلخيص/
 * البطاقات — راجع performSummarize بنفس الكنترولر)، بقرار صريح من
 * المستخدم بعد توثيق مشاكل كثيرة بمساعد الموقع (خطوات ٦٩/٧٠/٧١/٧٥/٧٦).
 *
 * هذا الجدول توكن ربط مؤقّت (١٠ دقائق، استخدام واحد) يحمل: أي ملف
 * (course_file_id)، أي وضع (mode: summary أو flashcards)، ولأي طالب
 * (user_id) — يُنشأ من زر الموقع، ويُستهلَك من داخل معالج /start
 * ببوت تيليجرام (TelegramWebhookController) بنفس نمط التوكن الحالي
 * لربط الحساب بـTelegramLink::link_token، لكن بجدول منفصل لأنه غرضه
 * مختلف تمامًا (تشغيل إجراء محدد، لا ربط حساب) وعمره أقصر بطبيعته.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_ai_deeplinks')) {
            Schema::create('telegram_ai_deeplinks', function (Blueprint $table) {
                $table->id();
                $table->string('token', 40)->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_file_id')->constrained()->cascadeOnDelete();
                $table->string('mode', 20)->default('summary');
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_ai_deeplinks');
    }
};
