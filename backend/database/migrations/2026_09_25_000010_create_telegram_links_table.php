<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيق فقط — التعديل الفعلي يُطبَّق يدويًا بـphpMyAdmin عبر
 * telegram_bot_step1_run_this_in_phpmyadmin.sql (لا صلاحية Terminal/
 * artisan على الاستضافة، نفس قيد جدول gpa_entries).
 *
 * جدول واحد بسيط يربط حساب الطالب بمحادثته على تيليجرام:
 * - link_token: كود مؤقت يُولَّد من صفحة الحساب بالموقع، صالح لمدة
 *   قصيرة فقط، ويُستهلك (يصير null) فور نجاح الربط — لا يبقى صالحًا
 *   لإعادة استخدام لاحقة.
 * - telegram_chat_id: معرّف محادثة تيليجرام الفعلي بعد نجاح الربط.
 *   null يعني "لسا ما انربط". فريد (unique) حتى ما تنربط نفس محادثة
 *   تيليجرام بأكثر من حساب طالب بالغلط.
 *
 * مقصود إنه جدول منفصل عن users تمامًا (لا عمود جديد عليه) — ميزة
 * تجريبية بمرحلتها الأولى، وفصلها بجدول خاص يخليها قابلة للحذف
 * بالكامل بأمان لو قررنا التراجع عنها بلا أي أثر على جدول المستخدمين.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_links')) {
            Schema::create('telegram_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                $table->string('link_token', 64)->nullable()->unique();
                $table->timestamp('token_expires_at')->nullable();

                $table->unsignedBigInteger('telegram_chat_id')->nullable()->unique();
                $table->string('telegram_first_name', 190)->nullable();
                $table->timestamp('linked_at')->nullable();

                $table->timestamps();

                $table->unique('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_links');
    }
};
