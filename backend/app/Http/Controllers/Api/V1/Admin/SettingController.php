<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    /**
     * المفاتيح المسموح تعديلها — قائمة بيضاء لا فلترة بالاستثناء.
     *
     * upsert مفتوح على أي مفتاح يجعل الجدول مساحة كتابة حرة للمدير:
     * مفتاح مكتوب بخطأ إملائي يُنشئ صفًّا جديدًا صامتًا بدل أن يعدّل
     * المقصود، فتبقى القيمة القديمة سارية والمدير يظنّه غيّرها.
     */
    private const EDITABLE = [
        'program.total_credit_hours' => ['rule' => 'integer|between:1,400', 'public' => true, 'group' => 'program'],
        'program.required_electives' => ['rule' => 'integer|between:0,20', 'public' => true, 'group' => 'program'],
        'contact.telegram_username' => ['rule' => 'string|max:64', 'public' => true, 'group' => 'contact'],
    ];

    public function index()
    {
        return response()->json([
            'data' => AppSetting::orderBy('group')->orderBy('key')->get(),
            'editable' => array_keys(self::EDITABLE),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate(['settings' => ['required', 'array']]);

        $settings = $request->input('settings');

        $unknown = array_diff(array_keys($settings), array_keys(self::EDITABLE));

        if ($unknown) {
            return response()->json([
                'message' => 'مفاتيح غير معروفة: '.implode('، ', $unknown),
            ], 422);
        }

        /*
         * التحقّق مفتاحًا مفتاحًا لا بقواعد متداخلة: مفاتيح الإعدادات
         * تحمل نقطة (program.total_credit_hours)، والنقطة في قواعد
         * لارافيل فاصل مسار داخل مصفوفة — فقاعدة باسم
         * settings.program.total_credit_hours تصف عنصرًا متداخلًا
         * لا وجود له، فتمرّ القيمة بلا فحص إطلاقًا.
         */
        foreach ($settings as $key => $value) {
            Validator::make(
                ['value' => $value],
                ['value' => ['nullable', ...explode('|', self::EDITABLE[$key]['rule'])]],
                [],
                ['value' => $key],
            )->validate();
        }

        foreach ($settings as $key => $value) {
            AppSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value === null ? null : (string) $value,
                    'group' => self::EDITABLE[$key]['group'],
                    'is_public' => self::EDITABLE[$key]['public'],
                ],
            );
        }

        return response()->json([
            'message' => 'تم تحديث الإعدادات.',
            'data' => AppSetting::orderBy('group')->orderBy('key')->get(),
        ]);
    }
}
