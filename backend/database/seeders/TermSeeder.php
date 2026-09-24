<?php

namespace Database\Seeders;

use App\Models\Term;
use Illuminate\Database\Seeder;

class TermSeeder extends Seeder
{
    /**
     * الفصول الدراسية.
     *
     * التواريخ تُترك فارغة عمدًا: التقويم الأكاديمي الرسمي غير متاح،
     * وتواريخ مخترَعة أسوأ من غيابها — الفصل يعمل بلا تاريخ، ويكذب
     * بتاريخ خاطئ.
     *
     * والحالي يُضبط هنا مرة واحدة كنقطة بداية ثم يملكه الأدمن. لا cron
     * ولا حساب من التاريخ: كلاهما يستند إلى تواريخ فارغة أصلًا.
     */
    public function run(): void
    {
        $years = ['2025/2026', '2026/2027'];

        $semesters = [
            1 => 'الفصل الأول',
            2 => 'الفصل الثاني',
            3 => 'الفصل الصيفي',
        ];

        $current = '2026-1';
        $order = 0;

        foreach ($years as $academicYear) {
            $short = substr($academicYear, 0, 4);

            foreach ($semesters as $semester => $label) {
                $code = $short.'-'.$semester;
                $order++;

                Term::firstOrCreate(['code' => $code], [
                    'label' => $label.' '.$academicYear,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                    'is_current' => $code === $current,
                    'sort_order' => $order,
                ]);
            }
        }
    }
}
