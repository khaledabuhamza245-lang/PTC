-- خطوة إضافة "تذكيرات المحاضرات" للبوت — عمود جديد يتحكم فيه الطالب
-- بتشغيل/إيقاف التذكير التلقائي قبل ١٥ دقيقة من كل محاضرة.
-- شغّل هذا السطر مرة واحدة فقط بـphpMyAdmin على قاعدة البيانات الحقيقية.

ALTER TABLE telegram_links
    ADD COLUMN reminders_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER mode;
