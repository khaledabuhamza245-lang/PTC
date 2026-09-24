<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيق فقط — التعديل الفعلي يُطبَّق يدويًا بـphpMyAdmin عبر
 * step13_run_this_in_phpmyadmin.sql (لا صلاحية Terminal/artisan على
 * الاستضافة).
 *
 * جدول منفصل تمامًا عن my_courses عمدًا: my_courses يحدّد "مساقاتي
 * الحالية" بالواجهة الرئيسية وسياق الذكاء الاصطناعي (أي حالة غير
 * completed/dropped تُحسب مساقًا حاليًا) وتقدّم التخرّج الفعلي
 * (PlanCalculator). لو استُعملت لتخزين علامات حاسبة المعدل مباشرة،
 * كل مساق يُدخَل له معدّل تقديري لمساق مستقبلي أو فصل قديم كان
 * سيظهر خطأً كـ"مساق حالي مسجَّل" على الصفحة الرئيسية ومساعد الذكاء
 * الاصطناعي، ويشوّه نسبة إنجاز الخطة الحقيقية. حاسبة المعدل أداة
 * تقدير شخصية مستقلة — تخزينها في جدول خاص بها يمنع أي تداخل جانبي
 * مع أي ميزة أخرى بالموقع.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gpa_entries')) {
            Schema::create('gpa_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();

                /* علامة 0-100 (تُقبَل كسور مثل 87.5). */
                $table->decimal('grade', 5, 2);

                $table->timestamps();

                $table->unique(['user_id', 'course_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gpa_entries');
    }
};
