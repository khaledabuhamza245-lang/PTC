<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * رسالة إعادة تعيين كلمة السر بقالب الموقع العربي.
 *
 * الافتراضي في لارافيل إنجليزي بمحاذاة LTR وزرّ «Reset Password»، وهو
 * أول ما يصل الطالب من موقعٍ كلّ واجهته عربية — فيقرأ كأنه من جهة أخرى
 * لا كأنه من الموقع الذي طلب منه.
 *
 * ويرث resetUrl() كما هو: الرابط يبنيه createUrlUsing المسجَّل في
 * AppServiceProvider ليشير إلى login.html في الفرونت لا إلى مسار في
 * الباك لا وجود له.
 */
class ResetPasswordNotification extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
        );

        return (new MailMessage)
            ->subject('إعادة تعيين كلمة السر — '.config('app.name'))
            ->view('emails.reset-password', [
                'url' => $url,
                'minutes' => $minutes,
                'appName' => config('app.name'),
            ]);
    }
}
