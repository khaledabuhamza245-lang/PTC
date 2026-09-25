<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيقية فقط (بلا صلاحية artisan migrate على الاستضافة الحالية) —
 * التغيير الفعلي بقاعدة البيانات تم يدويًا عبر phpMyAdmin
 * (telegram_bot_step_mode_run_this_in_phpmyadmin.sql). عمود "mode"
 * الجديد يخزّن الأداة المختارة حاليًا من "القائمة الذكية" لكل حساب
 * مربوط (chat/debug/quiz/summarize) — راجع TelegramLink::currentMode().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->string('mode', 20)->default('chat')->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
