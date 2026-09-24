<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CoursePrerequisite;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/courses.json');
        $courses = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($courses as $course) {
            /*
             * المتطلبات صفوف في جدول آخر لا عمود هنا. تركها في المصفوفة
             * لا يرمي خطأ — $fillable يُسقطها بصمت — لكن إخراجها صراحةً
             * يقول ذلك في الكود بدل الاتّكال على سلوك صامت.
             */
            Course::updateOrCreate(
                ['key' => $course['key']],
                collect($course)->except('prerequisites')->all()
            );
        }

        $this->seedPrerequisites($courses);
    }

    /**
     * المتطلبات السابقة.
     *
     * البذرة **تضيف ولا تحذف**: الأدمن يضيف متطلبات من اللوحة، ومزامنة
     * كاملة مع الملف كانت ستمحو شغله عند أول إعادة بذر.
     */
    private function seedPrerequisites(array $courses): void
    {
        /*
         * أول ظهور للرمز يكفي: الرموز فريدة إلا خانات EEEX 35XX الخمس،
         * وهي مواضع لا يطلبها أحد متطلبًا.
         */
        $idByCode = Course::query()
            ->orderBy('id')
            ->pluck('id', 'code');

        foreach ($courses as $course) {
            $courseId = $idByCode[$course['code']] ?? null;
            if (! $courseId) {
                continue;
            }

            foreach ($course['prerequisites'] ?? [] as $code) {
                $prerequisiteId = $idByCode[$code] ?? null;

                if ($prerequisiteId) {
                    CoursePrerequisite::updateOrCreate(
                        [
                            'course_id' => $courseId,
                            'prerequisite_course_id' => $prerequisiteId,
                        ],
                        [
                            'prerequisite_code_raw' => $code,
                            'needs_review' => false,
                        ],
                    );

                    continue;
                }

                /*
                 * رمز لا يقابل مساقًا — ستّة مراجع بخمسة رموز اليوم.
                 * لا يُخمَّن ولا يُطابَق بأقرب شبيه: يُحفظ نصًّا ويُرفع
                 * علم المراجعة، فيراه الطالب كما ورد ويراه الأدمن مهمة.
                 *
                 * firstOrCreate على الرمز لا اتّكالًا على القيد الفريد:
                 * MySQL يعدّ كل NULL مميّزًا، فالقيد لا يمنع تكرار هذه
                 * الصفوف وإعادة البذر كانت ستضاعفها في كل تشغيل.
                 */
                CoursePrerequisite::firstOrCreate(
                    [
                        'course_id' => $courseId,
                        'prerequisite_course_id' => null,
                        'prerequisite_code_raw' => $code,
                    ],
                    ['needs_review' => true],
                );
            }
        }
    }
}
