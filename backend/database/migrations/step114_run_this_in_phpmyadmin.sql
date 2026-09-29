-- خطوة ١١٤ — تشغيل يدوي عبر phpMyAdmin (لا SSH/artisan على هذه الاستضافة)
-- تذكير: افتح قاعدة البيانات نفسها من القائمة الجانبية أولًا (نفس اسم DB_DATABASE
-- بملف .env) قبل ما تروح لتبويب "SQL" وتشغّل هذا الاستعلام — وإلا رح تاخد
-- خطأ "#1046 - No database selected".

CREATE TABLE IF NOT EXISTS `course_file_ai_responses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_file_id` BIGINT UNSIGNED NOT NULL,
  `mode` VARCHAR(20) NOT NULL,
  `response_text` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_file_ai_responses_course_file_id_mode_unique` (`course_file_id`, `mode`),
  CONSTRAINT `course_file_ai_responses_course_file_id_foreign` FOREIGN KEY (`course_file_id`) REFERENCES `course_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
