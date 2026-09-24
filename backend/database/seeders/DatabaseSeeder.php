<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * الترتيب مُلزِم: TermSeeder قبل أي شيء يشير إلى فصل، و
         * PrepTopicSeeder بعد CourseSeeder لأنه يربط بـ course_id.
         *
         * ToolDirectorySeeder يبقى خارج القائمة عمدًا (٩٥ صفًّا يستدعيها
         * الأدمن صراحةً) — موثَّق في تسليم-المشروع.md.
         */
        $this->call([
            TermSeeder::class,
            CourseSeeder::class,
            PrepTopicSeeder::class,
            AppSettingSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
