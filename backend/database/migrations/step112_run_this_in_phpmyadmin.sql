-- خطوة 112 — تشغيل يدوي على قاعدة البيانات الحية عبر phpMyAdmin
-- (بعد رفع ملفات الكود عبر push+merge+push المعتاد لـmain)
--
-- يصلح علتين نشطتين مكتشفتين أثناء هذه الخطوة:
-- 1) عمود users.semester كان مُشارًا له بالكود منذ فترة (تسجيل حساب،
--    تعديل بروفايل، لوحة تحكم الطاقم) لكنه غير موجود فعليًا — أي طلب
--    يرسل قيمة semester فعلية كان يفشل بخطأ SQL حي.
-- 2) عمود announcements.audience كان VARCHAR(10) فقط — قصير جدًا
--    لقيمة 'year_semester' (13 حرفًا)، وaudience_semester غير موجود
--    إطلاقًا. كان هذا سيسبب اقتطاعًا صامتًا لو استُخدم الاستهداف هذا.

ALTER TABLE `users`
  ADD COLUMN `semester` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `year`;

ALTER TABLE `announcements`
  MODIFY COLUMN `audience` VARCHAR(20) NOT NULL DEFAULT 'all';

ALTER TABLE `announcements`
  ADD COLUMN `audience_semester` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `audience_year`;
