<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\PublicCache;

class ProgramController extends Controller
{
    /**
     * قواعد البرنامج للواجهة العامة.
     *
     * is_public ليس تجميلًا: الفلترة هنا تعني أن أي مفتاح إداري يُضاف
     * لاحقًا لا يتسرّب لمجرّد إضافته. القائمة البيضاء بالقيمة لا
     * بالاستثناء.
     *
     * (خطوة ١٠٣) بلا أي تخصيص شخصي — كل زائر يرى نفس الجواب بالضبط —
     * فتُخزَّن مؤقتًا عبر PublicCache لتخفيف التزاحم وقت الذروة.
     */
    public function __invoke()
    {
        $data = PublicCache::rememberProgram(function () {
            $settings = AppSetting::where('is_public', true)
                ->orderBy('key')
                ->get(['key', 'value', 'group']);

            return [
                'settings' => $settings->pluck('value', 'key'),
                'total_credit_hours' => AppSetting::int('program.total_credit_hours', 142),
                'required_electives' => AppSetting::int('program.required_electives', 5),
                'telegram_username' => AppSetting::get('contact.telegram_username'),
            ];
        });

        return response()->json(['data' => $data]);
    }
}
