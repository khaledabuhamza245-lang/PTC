<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CoursePrepTopic;
use Illuminate\Database\Seeder;

class PrepTopicSeeder extends Seeder
{
    /**
     * مواضيع المراجعة — ٣٥٢ صفًّا مولَّدة من prep-guide.js.
     *
     * المصدر يبقى في الفرونت ولا يُحذف: study-tools.html تقرأه مباشرة
     * فتعمل بلا شبكة. الجدول يضيف ما لا يستطيعه الملف الثابت — عمود
     * link وتحرير من اللوحة.
     *
     * التوليد: node tests/frontend/extract-prep-topics.js --write
     */
    public function run(): void
    {
        $path = database_path('data/prep-topics.json');

        if (! is_file($path)) {
            $this->command?->warn('prep-topics.json غير موجود — تُخطّى مواضيع المراجعة.');

            return;
        }

        $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $idByKey = Course::query()->pluck('id', 'key');

        foreach ($rows as $row) {
            $courseId = $idByKey[$row['course_key']] ?? null;
            if (! $courseId) {
                continue;
            }

            /*
             * المطابقة بالنصّ لا بالترتيب: الأدمن قد يحذف موضوعًا فتتزحزح
             * أرقام الترتيب، وإعادة البذر بمفتاح sort_order كانت ستكتب
             * موضوعًا فوق آخر.
             */
            CoursePrepTopic::updateOrCreate(
                ['course_id' => $courseId, 'topic' => $row['topic']],
                ['sort_order' => $row['sort_order']],
            );
        }
    }
}
