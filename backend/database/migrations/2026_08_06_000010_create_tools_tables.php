<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دليل أدوات المساق.
 *
 * لماذا جدول هنا بينما tools-registry.js ملف ثابت: الأدوات الستّ
 * المبنية داخل المنصة كود — لها منطق واختبارات ولا تتغير إلا بنشر.
 * أما هذا الدليل فروابط وأوصاف، أي بيانات محضة يحرّرها المشرف من
 * اللوحة. الاثنان يتعايشان، وصفحة المساق تدمجهما في قسم واحد.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);

            /*
             * النوع يحدّد شكل الزر ووجهته:
             * software → تحميل، online → فتح مباشرة،
             * concept  → شرح في نافذة، library → توثيق.
             */
            $table->string('type', 20)->default('online');

            $table->string('description', 300);

            /*
             * المفهوم قد لا يكون له رابط رسمي أصلًا، ونصّ شرحه هو
             * محتوى النافذة. لذلك كلاهما اختياري على مستوى الجدول
             * والشرط المتبادل يفرضه الكنترولر حسب النوع.
             */
            $table->string('official_url', 500)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->text('explanation')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['type', 'sort_order']);
        });

        /*
         * many-to-many لا hasMany: Git وMATLAB وWireshark وFigma
         * تتكرر في مساقين فأكثر — 17 برنامجًا في 22 موضعًا. بجدول
         * وسيط يُحرَّر البرنامج مرة واحدة فيسري على كل مساقاته.
         */
        Schema::create('course_tool', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tool_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'tool_id']);
            $table->index(['course_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_tool');
        Schema::dropIfExists('tools');
    }
};
