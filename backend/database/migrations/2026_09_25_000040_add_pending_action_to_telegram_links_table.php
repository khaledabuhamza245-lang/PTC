<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * توثيقية فقط (بلا artisan migrate) — التغيير الفعلي يدوي عبر phpMyAdmin
 * (telegram_bot_step_schedule_crud_run_this_in_phpmyadmin.sql). عمود
 * جديد يخزّن حالة محادثة إضافة/تعديل/حذف محاضرة من داخل البوت (خطوة
 * بخطوة) — راجع TelegramLink::isInScheduleFlow() والـwebhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->text('pending_action')->nullable()->after('reminders_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropColumn('pending_action');
        });
    }
};
