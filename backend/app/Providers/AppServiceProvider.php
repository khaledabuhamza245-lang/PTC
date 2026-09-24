<?php
 
namespace App\Providers;
 
use App\Mail\BrevoApiTransport;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
 
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
 
    public function boot(): void
    {
        /*
         * الحدّ العام لكل مسارات الـ API.
         *
         * يُطبَّق عبر ->throttleApi() في bootstrap/app.php. قبله كان
         * throttle:auth مطبَّقًا على 4 مسارات فقط من 49، فبقيت المسارات
         * العامة غير المرقّمة (/courses يرجع 75 سجلًا في كل طلب) ومسارات
         * الكتابة المدمّرة للطاقم بلا أي حدّ.
         *
         * المفتاح هو معرّف المستخدم عند المصادقة لا الـ IP، لأن شبكة
         * الجامعة تجعل عشرات الطلاب خلف عنوان واحد فيستهلك بعضهم حصة بعض.
         */
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(config('api.rate_limit.user'))
                    ->by('u:'.$request->user()->id)
                : Limit::perMinute(config('api.rate_limit.guest'))
                    ->by('ip:'.$request->ip());
        });
 
        /*
         * حدّ مسارات المصادقة.
         *
         * الحدّ الأول لكل (بريد، IP) يمنع تخمين كلمة سر حساب بعينه.
         * والثاني لكل IP وحده يمنع الرشّ: بدونه كان المهاجم يحصل على
         * حصة جديدة كاملة لكل بريد يجرّبه، فلا يُحدّ عمليًا.
         */
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
 
            return [
                Limit::perMinute(config('api.rate_limit.auth_per_email'))
                    ->by($email.'|'.$request->ip()),
                Limit::perMinute(config('api.rate_limit.auth_per_ip'))
                    ->by('authip:'.$request->ip()),
            ];
        });
 
        /*
         * حدّ تغيير كلمة السر من داخل الحساب.
         *
         * لا يصلح throttle:auth هنا لأن مفتاحه حقل email وهو غائب عن هذا
         * الطلب، فتقع كل الطلبات على مفتاح فارغ واحد يتقاسمه الجميع. وحدّ
         * api العام (١٢٠/دقيقة) فضفاض على مسار يُخمَّن فيه current_password.
         */
        RateLimiter::for('password-change', function (Request $request) {
            return Limit::perMinute(config('api.rate_limit.password_change'))
                ->by('pw:'.$request->user()?->id);
        });
 
        /*
         * حدّ نموذج «تواصل معنا».
         *
         * بالساعة لا بالدقيقة: النموذج يُملأ مرة، ومن يرسل خمسًا في ساعة
         * إما مخطئ أو بوت. وكل رسالة تكلّف طلبًا خارجيًّا لـBrevo من حصة
         * يوميّة محدودة، فالحدّ هنا يحمي الحصة لا الخادم وحده.
         *
         * والمفتاح معرّف المستخدم عند المصادقة كما في حدّ api العام: شبكة
         * الجامعة تضع عشرات الطلاب خلف عنوان واحد، فحدٌّ على الـIP وحده
         * يجعل طالبًا واحدًا مزعجًا يُسكِت قاعة كاملة.
         */
        RateLimiter::for('contact', function (Request $request) {
            return $request->user()
                ? Limit::perHour(config('api.rate_limit.contact_user'))
                    ->by('contact-u:'.$request->user()->id)
                : Limit::perHour(config('api.rate_limit.contact_guest'))
                    ->by('contact-ip:'.$request->ip());
        });
 
        /*
         * صندوق الخبرة: خمس نصائح بالساعة تكفي أي طالب حقيقي وتمنع
         * إغراق مساق واحد برسائل متكررة. المفتاح معرّف المستخدم دومًا
         * (الراوت أصلًا محمي بـauth:sanctum فلا حالة ضيف هنا).
         */
        RateLimiter::for('course-tips', function (Request $request) {
            return Limit::perHour(5)
                ->by('course-tip-u:'.$request->user()->id);
        });
 
        /*
         * الإبلاغ عن مشكلة: عشرة بلاغات بالساعة — أعلى من سقف صندوق
         * الخبرة عمدًا، فطالب يراجع مادة كاملة فيها روابط معطوبة قد
         * يحتاج يبلّغ عن أكثر من عنصر بجلسة واحدة.
         */
        RateLimiter::for('content-reports', function (Request $request) {
            return Limit::perHour(10)
                ->by('content-report-u:'.$request->user()->id);
        });

        RateLimiter::for('ai-upload', function (Request $request) {
            return Limit::perMinute(10)
                ->by('ai-upload-u:'.$request->user()->id);
        });
 
        Mail::extend('brevo', function (array $config) {
            return new BrevoApiTransport(
                (string) ($config['key'] ?? ''),
                (int) ($config['timeout'] ?? 10),
            );
        });
 
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $url = rtrim((string) config('app.frontend_url'), '/').'/login.html';
 
            return $url.'?token='.urlencode($token).'&email='.urlencode($user->email);
        });
    }
}
 
