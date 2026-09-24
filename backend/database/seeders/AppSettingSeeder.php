<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingSeeder extends Seeder
{
    /**
     * قواعد البرنامج — بذرة أوّلية لا مصدر حقيقة دائم.
     *
     * firstOrCreate لا updateOrCreate عمدًا: هذه القيم يحرّرها الأدمن من
     * اللوحة، ولو كتبها البذر كل مرة لأعاد ١٤٢ فوق رقم عدّله الأدمن
     * إلى ١٢٧ عند أول إعادة بذر — أي أن البذرة تصير تراجعًا صامتًا.
     */
    public function run(): void
    {
        $settings = [
            [
                'key' => 'program.total_credit_hours',
                'value' => '142',
                'group' => 'program',
                /*
                 * عام: شريط التقدّم على الرئيسية يحتاج المقام قبل تسجيل
                 * الدخول، وبطاقة «١٤٢ ساعة معتمدة» معروضة للزائر أصلًا.
                 */
                'is_public' => true,
            ],
            [
                'key' => 'program.required_electives',
                'value' => '5',
                'group' => 'program',
                'is_public' => true,
            ],
            [
                'key' => 'contact.telegram_username',
                'value' => 'Ptchup',
                'group' => 'contact',
                'is_public' => true,
            ],
        ];

        foreach ($settings as $setting) {
            AppSetting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
