<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CurrentCoursesSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'data' => $request->user()->load('currentTerm'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:60'],
            'father_name' => ['sometimes', 'required', 'string', 'max:60'],
            'last_name' => ['sometimes', 'required', 'string', 'max:60'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1,4'],
            'semester' => ['sometimes', 'nullable', 'integer', 'between:1,2'],
            'current_term_id' => ['sometimes', 'nullable', 'integer', Rule::exists('terms', 'id')],
        ]);

        $user = $request->user();

        /*
         * السنة حقلٌ كبقيّته: تُحفظ ولا تُسجَّل مساقاتها.
         *
         * «مساقاتي الحالية» إقرارُ الطالب وحده — لا صفَّ واحدًا يكتبه
         * النظام نيابةً عنه. وحالة المساق **مصدر الحقيقة الوحيد** لحساب
         * التخرّج (قرار ٧٫١)، فصفٌّ لم يقرّه أحد يُحتسب على صاحبه إن
         * سها عنه.
         */
        $user->update($data);

        /*
         * إذا تغيّرت السنة أو الفصل، نزامن مساقات الفصل المقابلة
         * تلقائيًا إلى «جارٍ» — الخدمة نفسها تحترم أي مساق أضافه
         * الطالب يدويًا ولا تلمس ما هو منجز أو مستبعد.
         */
        if (array_key_exists('year', $data) || array_key_exists('semester', $data) || array_key_exists('current_term_id', $data)) {
            app(CurrentCoursesSyncService::class)->sync($user);
        }

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي.',
            'data' => $user->fresh()->load('currentTerm'),
        ]);
    }

    /**
     * تغيير كلمة السر من داخل الحساب.
     *
     * منفصل عن update() عمدًا: هذا وحده يشترط كلمة السر الحالية ويلغي
     * الجلسات، فخلطه بحقول الاسم والسنة يجعل تحرير الاسم يمرّ بشرط لا
     * يخصّه.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            /*
             * قاعدة current_password تقارن بحارس الجلسة الافتراضي (web)
             * وطلبنا مصادَق بـsanctum، فالمقارنة هنا بالهاش مباشرةً.
             * الشرط نفسه لا يسقط: من يمسك جهازًا مفتوحًا لا يستولي على
             * الحساب ما لم يعرف كلمة السر.
             */
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                PasswordRule::min(8),
            ],
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'كلمة السر الحالية غير صحيحة.',
            ]);
        }

        $user->forceFill([
            'password' => $request->input('password'),
            'remember_token' => Str::random(60),
        ])->save();

        /*
         * تُلغى الجلسات الأخرى وتبقى الحالية.
         *
         * والفرق عن مسار إعادة التعيين مقصود: هناك تُحذف التوكنات كلها
         * لأن صاحب الطلب لم يثبت أنه على جهاز موثوق — كل ما بيده رابطٌ
         * من بريد. وهنا أثبت بكلمة السر الحالية، فطردُه من الجهاز الذي
         * غيّر منه عقوبةٌ بلا سبب.
         */
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $revoked = $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'message' => 'تم تغيير كلمة السر.',
            'revoked_sessions' => $revoked,
        ]);
    }
}
