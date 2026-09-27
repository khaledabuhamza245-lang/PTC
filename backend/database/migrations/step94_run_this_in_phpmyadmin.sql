-- خطوة 94 — جداول "🙋 مساعدة الطلاب" (Peer Help / أسئلة وأجوبة لكل مادة)
-- شغّل هذا الملف يدويًا بـphpMyAdmin على قاعدة بيانات الإنتاج (نفس بروتوكول
-- step13_run_this_in_phpmyadmin.sql السابق لـgpa_entries) — لا صلاحية
-- Terminal/artisan على الاستضافة الحالية.
--
-- بعد تشغيله، شغّل هذا الفحص للتأكد:
--   SHOW TABLES LIKE 'student_%';
-- يفترض يطلع لك: student_questions, student_answers

CREATE TABLE IF NOT EXISTS `student_questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `question` TEXT NOT NULL,
  `answers_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_questions_course_id_created_at_index` (`course_id`, `created_at`),
  KEY `student_questions_user_id_foreign` (`user_id`),
  CONSTRAINT `student_questions_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_questions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_answers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `answer` TEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_answers_question_id_created_at_index` (`question_id`, `created_at`),
  KEY `student_answers_user_id_foreign` (`user_id`),
  CONSTRAINT `student_answers_question_id_foreign` FOREIGN KEY (`question_id`) REFERENCES `student_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_answers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجّل هذا Migration بجدول migrations حتى ما يحاول artisan (لو صار متاحًا
-- مستقبلًا) يعيد تنفيذه من الصفر:
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_27_000010_create_student_qa_tables', (SELECT MAX(`batch`) FROM (SELECT `batch` FROM `migrations`) AS m)
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_27_000010_create_student_qa_tables');
