-- خطوة 1 من بوت تيليجرام — ننفّذها يدويًا بـ phpMyAdmin فقط
-- (نفس سبب gpa_entries: لا صلاحية Terminal/artisan على هذه الاستضافة).
--
-- طريقة التنفيذ:
-- 1) ادخل لوحة cPanel → phpMyAdmin.
-- 2) اختر قاعدة بيانات الموقع من القائمة الجانبية.
-- 3) افتح تبويب "SQL" من الأعلى.
-- 4) الصق هذا الكود بالكامل واضغط "Go" / "تنفيذ".
--
-- لا تُنفَّذ هذه الجملة إلا مرة واحدة، ووقتها فقط ندمج فرع telegram-bot
-- بفرع main. قبل هالخطوة، هذا الملف مجرد توثيق بالريبو ولا أثر له.

CREATE TABLE IF NOT EXISTS `telegram_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `link_token` VARCHAR(64) NULL,
  `token_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `telegram_chat_id` BIGINT UNSIGNED NULL,
  `telegram_first_name` VARCHAR(190) NULL,
  `linked_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_links_link_token_unique` (`link_token`),
  UNIQUE KEY `telegram_links_telegram_chat_id_unique` (`telegram_chat_id`),
  UNIQUE KEY `telegram_links_user_id_unique` (`user_id`),
  CONSTRAINT `telegram_links_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
