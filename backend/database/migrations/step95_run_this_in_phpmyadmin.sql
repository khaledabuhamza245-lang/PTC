-- خطوة 95 — تصويت الطلاب على دقّة الإجابات ("✅ صحيحة" / "❌ غير دقيقة")
-- بميزة "🙋 مساعدة الطلاب".
--
-- هذا الملف مستقل بذاته وآمن التشغيل سواء شغّلت step94_run_this_in_phpmyadmin.sql
-- قبله أو لأ (يعيد إنشاء نفس جدولي step94 لو ناقصين، ثم يضيف عمودي
-- التصويت وجدول التصويت الجديد). شغّله كاملًا بـphpMyAdmin على قاعدة
-- بيانات الإنتاج، ثم تحقق بـ:
--   SHOW TABLES LIKE 'student_%';
-- يفترض يطلع لك: student_questions, student_answers, student_answer_votes
--   SHOW COLUMNS FROM student_answers LIKE '%helpful%';
-- يفترض يطلع لك عمودين: helpful_count, unhelpful_count

-- === تكرار آمن لجداول step94 (لو ما اتشغّل قبل) ===

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

-- === الجديد بخطوة 95: عمودا العدّاد + جدول التصويت ===

-- MySQL 8.0.29+ يدعم ADD COLUMN IF NOT EXISTS مباشرة. لو نسخة أقدم
-- عندك وطلعت رسالة خطأ هون تحديدًا، شيل "IF NOT EXISTS" من هالسطرين
-- وشغّلهم مرة وحدة بس (بما إنه الأعمدة أصلًا مش موجودة أول مرة).
ALTER TABLE `student_answers`
  ADD COLUMN IF NOT EXISTS `helpful_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `answer`,
  ADD COLUMN IF NOT EXISTS `unhelpful_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `helpful_count`;

CREATE TABLE IF NOT EXISTS `student_answer_votes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `answer_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `vote` VARCHAR(4) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_answer_votes_answer_id_user_id_unique` (`answer_id`, `user_id`),
  KEY `student_answer_votes_user_id_foreign` (`user_id`),
  CONSTRAINT `student_answer_votes_answer_id_foreign` FOREIGN KEY (`answer_id`) REFERENCES `student_answers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_answer_votes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجّل الميغريشنين حتى ما يحاول artisan (لو صار متاحًا مستقبلًا) يعيد تنفيذهم:
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_27_000010_create_student_qa_tables', (SELECT MAX(`batch`) FROM (SELECT `batch` FROM `migrations`) AS m)
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_27_000010_create_student_qa_tables');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_27_000020_add_answer_votes', (SELECT MAX(`batch`) FROM (SELECT `batch` FROM `migrations`) AS m)
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_09_27_000020_add_answer_votes');
