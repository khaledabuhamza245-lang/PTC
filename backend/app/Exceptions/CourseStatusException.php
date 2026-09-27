<?php

namespace App\Exceptions;

/**
 * استثناء منطق عمل صريح لعمليات تعديل حالة مساق الطالب (my_courses) —
 * يحمل رمز حالة HTTP مقترح مع كل حالة حتى يقدر أي مستدعٍ (متحكّم HTTP
 * عادي، أو معالج أزرار بوت تيليجرام) يترجمه لاستجابته الخاصة بشكله
 * المناسب (JSON بالموقع، Toast/رسالة بالبوت) بدل تكرار رسائل الخطأ.
 *
 * راجع App\Services\MyCourseStatusService — المصدر الوحيد لقواعد
 * العمل هذه، يستخدمها الموقع (عبر MyCourseController مستقبلًا) والبوت
 * معًا فلا يوجد مكانان يمكن أن يختلفا برسالة أو قاعدة.
 */
class CourseStatusException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
