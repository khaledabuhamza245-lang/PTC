<?php

namespace App\Services;

use App\Exceptions\CourseStatusException;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * منطق تعديل تسجيلات الطالب (my_courses) — مُستخرَج حرفيًا من
 * App\Http\Controllers\Api\V1\MyCourseController (store/update/destroy)
 * ليُستخدَم من مصدرين بلا أي تكرار أو احتمال انحراف: الموقع (لاحقًا،
 * بتحويل ذاك المتحكّم ليستدعي هذه الخدمة بدل جسمه الحالي — خارج نطاق
 * هذه الخطوة عمدًا) وبوت تيليجرام (فعليًا الآن، عبر TelegramWebhookController
 * لميزة التحكّم بالخطة من داخل الشات).
 *
 * ⚠ قرار متعمَّد: MyCourseController نفسه **لم يُلمس إطلاقًا** بهذه
 * الخطوة — هو كود حيّ يعمل فعليًا على الإنتاج الآن، وتعديله بلا إشراف
 * حي وقت كتابة هذا الكود خطر لا داعي له. هذه الخدمة نسخة موازية
 * مطابقة حرفيًا (كل سطر، كل قاعدة عمل) تحقّقنا من تطابقها بمجموعة
 * اختبارات جديدة (tests/Feature/MyCourseStatusServiceTest.php) تغطي
 * نفس السيناريوهات الموجودة أصلًا بـCoursePlanAdminTest/AcademicPlanTest/
 * ManualEnrollmentOnlyTest. توحيدهما بمصدر واحد فعلي (تحويل المتحكّم
 * ليستدعي هذه الخدمة) خطوة تحسين لاحقة آمنة بعد تثبيت ميزة البوت حيًّا.
 */
class MyCourseStatusService
{
    private const ALLOWED_STATUSES = ['registered', 'completed', 'dropped'];

    /**
     * إضافة مساق يدويًا لمساقات الطالب — مطابقة MyCourseController::store().
     */
    public function attach(User $user, Course $course): void
    {
        if (! $course->is_active) {
            throw new CourseStatusException('هذا المساق غير متاح.', 404);
        }

        if ($course->course_type === 'elective') {
            $planSemester = $this->currentPlanSemester($user);

            $hasOpenSlot = $planSemester && Course::query()
                ->where('is_active', true)
                ->where('course_type', 'placeholder')
                ->where('year', (int) $user->year)
                ->where('semester', $planSemester)
                ->exists();

            if (! $hasOpenSlot) {
                throw new CourseStatusException('ما في خانة اختيارية متاحة إلك بفصلك الدراسي الحالي.', 422);
            }
        }

        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $existing) {
            DB::table('my_courses')->insert([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'source' => 'manual',
                'term_id' => $user->current_term_id,
                'status' => 'registered',
                'completed_at' => null,
                'grade' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $update = [
            'source' => 'manual',
            'updated_at' => now(),
        ];

        if (($existing->status ?? 'registered') === 'dropped') {
            $update['status'] = 'registered';
            $update['term_id'] = $user->current_term_id;
            $update['completed_at'] = null;
        } elseif (($existing->status ?? 'registered') === 'registered') {
            if ($user->current_term_id) {
                $update['term_id'] = $user->current_term_id;
            }
        }

        DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->update($update);
    }

    /**
     * تحديث حالة مساق مسجَّل أصلًا — مطابقة فرع "status" من
     * MyCourseController::update(). لا تلمس term_id/grade (المتحكّم
     * وحده يدعمهما حاليًا، والبوت لا يحتاجهما بهذه الميزة).
     *
     * @return array{status: string, completed_at: ?string}
     */
    public function setStatus(User $user, Course $course, string $status): array
    {
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new CourseStatusException('حالة غير صالحة.', 422);
        }

        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $existing) {
            throw new CourseStatusException('المساق غير مسجَّل لهذا الطالب.', 404);
        }

        $pivot = ['status' => $status];

        if ($status === 'completed') {
            $pivot['completed_at'] = $existing->completed_at ?: now();
        } else {
            $pivot['completed_at'] = null;
        }

        $pivot['source'] = 'manual';
        $pivot['updated_at'] = now();

        DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->update($pivot);

        $updated = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        return [
            'status' => $updated->status,
            'completed_at' => $updated->completed_at,
        ];
    }

    /**
     * إضافة المساق إن لم يكن مسجَّلًا ثم ضبط حالته مباشرة — اختصار
     * يستخدمه البوت لأن أزراره لا تفرّق بين "مساق جديد" و"مساق مسجَّل
     * أصلًا": الطالب يضغط ✅/⏳/🔴 فقط، بغضّ النظر عن كونه أول تعديل
     * لهذا المساق أو تعديلًا لاحقًا. يطابق فلسفة profile-page.js::applyStatus
     * بالموقع تمامًا (addMyCourse إن لزم ثم setCourseStatus).
     */
    public function ensureStatus(User $user, Course $course, string $status): array
    {
        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();

        if (! $existing) {
            $this->attach($user, $course);
        }

        return $this->setStatus($user, $course, $status);
    }

    /**
     * إزالة/تصفير حالة مساق ("متبقٍ") — مطابقة MyCourseController::destroy()
     * حرفيًا: مساق تلقائي أو من فصل الطالب الحالي المُعلَن لا يُحذف
     * فعليًا (يتحوّل لـ"منسحب" فقط، لأن أي مزامنة لاحقة ستُعيده)، وغيره
     * يُحذف فعليًا من الجدول.
     */
    public function remove(User $user, Course $course): void
    {
        $existing = DB::table('my_courses')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $existing) {
            return;
        }

        $isAutomatic = ($existing->source ?? 'manual') === 'automatic';

        if ($isAutomatic || $this->isCurrentSuggestedCourse($user, $course)) {
            DB::table('my_courses')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->update([
                    'status' => 'dropped',
                    'source' => 'manual',
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('my_courses')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->delete();
        }
    }

    /**
     * رقم الفصل العالمي (١..٨) — مطابقة MyCourseController::currentPlanSemester().
     */
    public function currentPlanSemester(User $user): ?int
    {
        $user->loadMissing('currentTerm');

        $term = $user->currentTerm;
        $year = (int) $user->year;

        if (
            ! $year
            || ! $term
            || ! in_array((int) $term->semester, [1, 2], true)
        ) {
            return null;
        }

        return (($year - 1) * 2) + (int) $term->semester;
    }

    /**
     * مطابقة MyCourseController::isCurrentSuggestedCourse().
     */
    public function isCurrentSuggestedCourse(User $user, Course $course): bool
    {
        $user->loadMissing('currentTerm');

        $term = $user->currentTerm;
        $year = (int) $user->year;

        if (
            ! $year
            || ! $term
            || ! in_array((int) $term->semester, [1, 2], true)
        ) {
            return false;
        }

        $planSemester = (($year - 1) * 2) + (int) $term->semester;

        return
            (bool) $course->is_active
            && (int) $course->year === $year
            && (int) $course->semester === $planSemester
            && in_array($course->course_type, ['required', 'placeholder'], true);
    }
}
