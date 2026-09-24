<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\User;
use App\Services\CurrentCoursesSyncService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    /*
     * ليس سرًّا: هذا المعرّف يظهر أصلًا صراحةً بكود الواجهة (login.html)
     * لتهيئة زر Google، فحمايته هنا كسرّ بيئة لا تضيف أمانًا حقيقيًا.
     */
    private const GOOGLE_CLIENT_ID =
        '1065926604389-ioevt968vm90np1osfmdir1h2r93g4pr.apps.googleusercontent.com';

    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'father_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'year' => ['nullable', 'integer', 'between:1,4'],
            'semester' => ['nullable', 'integer', 'between:1,2'],
        ]);

        /*
         * السنة المعلَنة تُحفظ ولا تُسجَّل مساقاتها: الحساب الجديد يفتح
         * على «مساقاتي الحالية» فارغة، والطالب يضيف ما يدرسه فعلًا.
         *
         * فالسنة موضعٌ في الخطة لا جدولُ فصلٍ قائم: الطالب يرسب فيحمل من
         * سنة سابقة، ويؤجّل مساقًا، ويأخذ أقلّ من الحمل الكامل — وحالة
         * المساق **مصدر الحقيقة الوحيد** لحساب التخرّج (قرار ٧٫١)، فكتابة
         * «جارٍ» على ١٣ صفًّا لم يقرّها أحد تجعل أول عمل الطالب حذفَ ما
         * لم يطلبه، وما ينساه منها يُحتسب عليه.
         */
        $user = User::create($data + [
            'role' => 'student',
            'current_term_id' => Term::current()?->id,
        ]);

        app(CurrentCoursesSyncService::class)->sync($user);

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'message' => 'تم إنشاء الحساب بنجاح.',
            'data' => ['user' => $user, 'token' => $token],
        ], 201);
    }

    public function login(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة.'], 422);
        }

        /*
         * الطوابع معطَّلة في هذه الكتابة عمدًا.
         *
         * الدخول ليس تعديلًا على الحساب، فتحريك updated_at معه يجعل
         * «آخر تعديل» يعني «آخر دخول» — ويفقد الحقلان معنييهما معًا.
         * والتسجيل قبل إصدار الرمز ليخرج الوقت الجديد في الحمولة.
         */
        $user->timestamps = false;
        $user->last_login_at = now();
        $user->save();
        $user->timestamps = true;

        $token = $user->createToken('web-'.Str::uuid())->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول.',
            'data' => ['user' => $user, 'token' => $token],
        ]);
    }

    public function google(Request $request)
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        /*
         * التحقق من الرمز يصير مع Google مباشرة، لا بفكّ تشفيره محليًا —
         * فلا حاجة لمكتبة JWT إضافية، وGoogle نفسها ترفض أي رمز منتهٍ أو
         * مزوَّر قبل أن يصل هذا الكود إطلاقًا.
         */
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $data['id_token'],
        ]);

        if (! $response->ok()) {
            return response()->json([
                'message' => 'تعذّر التحقق من حساب Google. حاول مرة أخرى.',
            ], 422);
        }

        $claims = $response->json();

        if (($claims['aud'] ?? null) !== self::GOOGLE_CLIENT_ID) {
            return response()->json([
                'message' => 'رمز الدخول غير صالح لهذا الموقع.',
            ], 422);
        }

        if (($claims['email_verified'] ?? 'false') !== 'true') {
            return response()->json([
                'message' => 'بريد حساب Google غير مؤكَّد.',
            ], 422);
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        if (! $email) {
            return response()->json([
                'message' => 'تعذّر قراءة بريد حساب Google.',
            ], 422);
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            /*
             * ربط الحساب الحالي بمعرّف Google أول مرة يدخل بيها هيك،
             * حتى لو أنشأه أصلًا بإيميل وكلمة سر عاديين — نفس البريد
             * المؤكَّد من Google كافٍ لإثبات أنه صاحب الحساب نفسه.
             */
            if (! $user->google_id) {
                $user->google_id = $claims['sub'] ?? null;
                $user->save();
            }
        } else {
            $nameParts = preg_split(
                '/\s+/u',
                trim((string) ($claims['name'] ?? '')),
                -1,
                PREG_SPLIT_NO_EMPTY
            ) ?: [];

            $firstName = $nameParts[0] ?? ($claims['given_name'] ?? 'طالب');
            $fatherName = $nameParts[1] ?? '-';
            $lastName = count($nameParts) > 2
                ? implode(' ', array_slice($nameParts, 2))
                : ($claims['family_name'] ?? '-');

            $user = User::create([
                'first_name' => $firstName,
                'father_name' => $fatherName,
                'last_name' => $lastName,
                'email' => $email,
                'google_id' => $claims['sub'] ?? null,
                /*
                 * كلمة سر عشوائية غير قابلة للاستخدام فعليًا — العمود
                 * بقاعدة البيانات لا يقبل قيمة فارغة، والطالب أصلًا لن
                 * يحتاجها طالما يدخل عبر Google، وإن أراد لاحقًا كلمة
                 * سر عادية يقدر يستعملها "نسيت كلمة السر؟" كأي حساب آخر.
                 */
                'password' => Hash::make(Str::random(40)),
                'role' => 'student',
                'current_term_id' => Term::current()?->id,
            ]);
        }

        /*
         * السنة والفصل فارغان لحساب Google الجديد تمامًا كالتسجيل العادي
         * (قرار ٧٫١: لا مساق يُسجَّل بدون إقرار الطالب) — needs_onboarding
         * تخبر الواجهة أن تعرض حوار اختيار السنة والفصل قبل أي شيء آخر.
         */
        $token = $user->createToken('web-google-'.Str::uuid())->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول.',
            'data' => [
                'user' => $user,
                'token' => $token,
                'needs_onboarding' => is_null($user->year),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function forgotPassword(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'إذا كان البريد مسجلاً، سيتم إرسال رابط إعادة التعيين.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'تم تغيير كلمة المرور.']);
    }
}
