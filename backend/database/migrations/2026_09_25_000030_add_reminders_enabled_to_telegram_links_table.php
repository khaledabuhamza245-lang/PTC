<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيقية فقط (بلا artisan migrate) — التغيير الفعلي يدوي عبر phpMyAdmin
 * (telegram_bot_step_reminders_run_this_in_phpmyadmin.sql). عمود جديد
 * يتحكم به الطالب بتشغيل/إيقاف تذكيرات المحاضرات (راجع
 * SendTelegramLectureReminders و"تفعيل/إيقاف التذكيرات" بالـwebhook).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(true)->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropColumn('reminders_enabled');
        });
    }
};
