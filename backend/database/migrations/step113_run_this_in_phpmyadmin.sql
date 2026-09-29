-- خطوة ١١٣ — تشغيل يدوي عبر phpMyAdmin (لا SSH/artisan على هذه الاستضافة)
-- شغّل كل أمر لحاله بالترتيب. لو ظهر خطأ "Duplicate column"/"already exists"
-- على أي سطر، يعني هذا التعديل بالذات موجود مسبقًا على قاعدة البيانات
-- الحية (نفس ما صار بخطوة ١١٢) — تجاهله وكمّل للسطر التالي بأمان.

-- ١) جدول التوكنات المؤقتة لروابط "لخّصلي"/"بطاقات مراجعة" من الموقع لبوت تيليجرام
CREATE TABLE IF NOT EXISTS `telegram_ai_deeplinks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(40) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `course_file_id` BIGINT UNSIGNED NOT NULL,
  `mode` VARCHAR(20) NOT NULL DEFAULT 'summary',
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_ai_deeplinks_token_unique` (`token`),
  KEY `telegram_ai_deeplinks_user_id_foreign` (`user_id`),
  KEY `telegram_ai_deeplinks_course_file_id_foreign` (`course_file_id`),
  CONSTRAINT `telegram_ai_deeplinks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `telegram_ai_deeplinks_course_file_id_foreign` FOREIGN KEY (`course_file_id`) REFERENCES `course_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ٢) عمود إشعار البوت لما يجهّز الرد المتأخر (pending) لسؤال جاي من تيليجرام
ALTER TABLE `ai_questions`
  ADD COLUMN `notify_telegram_chat_id` VARCHAR(32) NULL DEFAULT NULL AFTER `referenced_course_file_id`;

-- ٣) عمودان كانا ناقصين فعليًا من قبل هذه الخطوة (اكتُشفا أثناء اختبار الميزة محليًا):
ALTER TABLE `course_files`
  ADD COLUMN `ai_summarizable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

ALTER TABLE `ai_conversations`
  ADD COLUMN `pinned_course_file_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `title`,
  ADD CONSTRAINT `ai_conversations_pinned_course_file_id_foreign`
    FOREIGN KEY (`pinned_course_file_id`) REFERENCES `course_files` (`id`) ON DELETE SET NULL;

-- ملاحظة: لو عمودا (٣) موجودان مسبقًا على قاعدة الإنتاج (نفس مفاجأة خطوة ١١٢)
-- فهذا يعني ميزة "لخّصلي" الأصلية كانت تعمل بالفعل بلا مشاكل — تجاهل الخطأ وكمّل.
