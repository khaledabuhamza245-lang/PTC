<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CurrentCoursesSyncService
{
    public const SOURCE_AUTOMATIC = 'automatic';

    public const SOURCE_MANUAL = 'manual';

    /**
     * مزامنة مساقات الطالب مع السنة والفصل المختارين.
     */
    public function sync(User $user): array
    {
        /*
         * حماية للاستضافة الحالية:
         * لو source مش موجود، أنشئه.
         */
        $this->ensureSourceColumn();

        $user->load('currentTerm');

        $year = (int) $user->year;
        $term = $user->currentTerm;

        if (
            ! $year
            || ! $term
            || ! in_array(
                (int) $term->semester,
                [1, 2],
                true
            )
        ) {
            return $this
                ->removeObsoleteAutomaticCourses($user);
        }

        /*
         * مهم جدًا:
         *
         * Term.semester:
         * 1 أو 2
         *
         * لكن Course.semester:
         * 1 إلى 8
         *
         * السنة الأولى:
         * الفصل الأول = 1
         * الفصل الثاني = 2
         *
         * السنة الثانية:
         * الفصل الأول = 3
         * الفصل الثاني = 4
         *
         * السنة الثالثة:
         * 5 و 6
         *
         * السنة الرابعة:
         * 7 و 8
         */
        $planSemester =
            (($year - 1) * 2)
            + (int) $term->semester;

        $recommendedIds = Course::query()
            ->where('is_active', true)
            ->where('year', $year)
            ->where('semester', $planSemester)
            ->whereIn('course_type', [
                'required',
                'placeholder',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(
                fn ($id) => (int) $id
            )
            ->values()
            ->all();

        return DB::transaction(function () use (
            $user,
            $term,
            $recommendedIds
        ) {
            $rows = DB::table('my_courses')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(
                    fn ($row) =>
                        (int) $row->course_id
                );

            /*
             * عند تغيير السنة أو الفصل:
             *
             * نحذف التسجيلات القديمة فقط إذا:
             * - النظام أضافها automatic
             * - ما زالت registered
             * - لم تعد من مواد الفصل الجديد
             *
             * اختيارات الطالب اليدوية لا نمسها.
             */
            $obsoleteIds = $rows
                ->filter(
                    function ($row) use ($recommendedIds) {
                        $source =
                            $row->source
                            ?? self::SOURCE_MANUAL;

                        $status =
                            $row->status
                            ?? 'registered';

                        return
                            $source
                                === self::SOURCE_AUTOMATIC

                            && $status
                                === 'registered'

                            && ! in_array(
                                (int) $row->course_id,
                                $recommendedIds,
                                true
                            );
                    }
                )
                ->pluck('course_id')
                ->map(
                    fn ($id) => (int) $id
                )
                ->values()
                ->all();

            $removed = 0;

            if ($obsoleteIds) {
                $removed = DB::table('my_courses')
                    ->where('user_id', $user->id)
                    ->whereIn(
                        'course_id',
                        $obsoleteIds
                    )
                    ->delete();
            }

            $added = 0;

            foreach ($recommendedIds as $courseId) {
                $existing =
                    $rows->get($courseId);

                if ($existing) {
                    $source =
                        $existing->source
                        ?? self::SOURCE_MANUAL;

                    $status =
                        $existing->status
                        ?? 'registered';

                    /*
                     * الطالب تدخل بهذا المساق:
                     * احترم قراره.
                     */
                    if (
                        $source === self::SOURCE_MANUAL
                    ) {
                        continue;
                    }

                    /*
                     * لا نعيد المساق المنجز أو المستبعد
                     * إلى جاري.
                     */
                    if (
                        $status === 'completed'
                        || $status === 'dropped'
                    ) {
                        continue;
                    }

                    DB::table('my_courses')
                        ->where(
                            'user_id',
                            $user->id
                        )
                        ->where(
                            'course_id',
                            $courseId
                        )
                        ->update([
                            'term_id' =>
                                $term->id,

                            'status' =>
                                'registered',

                            'updated_at' =>
                                now(),
                        ]);

                    continue;
                }

                /*
                 * مساق الفصل المختار غير موجود:
                 * أضفه تلقائيًا جاري.
                 */
                DB::table('my_courses')->insert([
                    'user_id' =>
                        $user->id,

                    'course_id' =>
                        $courseId,

                    'source' =>
                        self::SOURCE_AUTOMATIC,

                    'term_id' =>
                        $term->id,

                    'status' =>
                        'registered',

                    'completed_at' =>
                        null,

                    'grade' =>
                        null,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);

                $added++;
            }

            return [
                'added' =>
                    $added,

                'removed' =>
                    $removed,

                'recommended' =>
                    count($recommendedIds),
            ];
        });
    }

    /**
     * لو الطالب جعل السنة أو الفصل «غير محدد»
     * نحذف فقط المساقات الجارية التي أضافها النظام.
     */
    private function removeObsoleteAutomaticCourses(
        User $user
    ): array {
        $removed = DB::table('my_courses')
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'source',
                self::SOURCE_AUTOMATIC
            )
            ->where(
                'status',
                'registered'
            )
            ->delete();

        return [
            'added' => 0,
            'removed' => $removed,
            'recommended' => 0,
        ];
    }

    /**
     * إنشاء source إذا لم يكن موجودًا.
     */
    private function ensureSourceColumn(): void
    {
        if (
            ! Schema::hasColumn(
                'my_courses',
                'source'
            )
        ) {
            Schema::table(
                'my_courses',
                function (Blueprint $table) {
                    $table
                        ->string(
                            'source',
                            20
                        )
                        ->default(
                            self::SOURCE_MANUAL
                        );
                }
            );
        }

        /*
         * التسجيلات القديمة نحسبها manual
         * حتى لا يحذفها النظام.
         */
        DB::table('my_courses')
            ->where(function ($query) {
                $query
                    ->whereNull('source')
                    ->orWhere(
                        'source',
                        ''
                    );
            })
            ->update([
                'source' =>
                    self::SOURCE_MANUAL,
            ]);
    }
}