-- خطوة إضافة "القائمة الذكية" للبوت — عمود جديد يخزّن الأداة الحالية
-- المختارة لكل حساب مربوط (chat / debug / quiz / summarize).
-- شغّل هذا السطر مرة واحدة فقط بـphpMyAdmin على قاعدة البيانات الحقيقية.

ALTER TABLE telegram_links
    ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT 'chat' AFTER telegram_chat_id;
