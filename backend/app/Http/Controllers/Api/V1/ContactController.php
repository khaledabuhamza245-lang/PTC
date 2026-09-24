<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * استقبال استفسارات «تواصل معنا» وإرسالها لصندوق الموقع.
 *
 * المسار عام لا محميّ بـauth:sanctum عمدًا: أكثر حالة يُحتاج فيها التواصل
 * هي حالة من لا يستطيع الدخول أصلًا — تسجيل متعثّر، أو رسالة إعادة تعيين
 * لا تصل. فقصر الصفحة على المسجَّلين يقفل الباب في وجه من يطرقه.
 *
 * ولا جدول للرسائل: الجيميل نفسه صندوق وارد كامل فيه بحث وتصنيف وردّ،
 * وصندوقٌ ثانٍ في لوحة التحكم يشتّت المتابعة بين اثنين فلا يُتابَع أيّهما.
 */
class ContactController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'string', 'email', 'max:120'],
            'subject' => ['required', 'string', 'min:3', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],

            /*
             * حقل فخّ مخفيّ في الصفحة. البشر لا يرونه فلا يملؤونه، والبوت
             * يملأ كل حقل يجده.
             */
            'website' => ['nullable', 'string', 'max:200'],
        ]);

        /*
         * الفخّ يُبتلع لا يُرفض: ردّ 422 يعلّم البوت أن الحقل فخّ فيتجنّبه في
         * المحاولة التالية، والنجاح الكاذب يجعله ينصرف راضيًا. ويُسجَّل
         * ليبقى الصمت مرئيًّا في السجلّات لا مجهولًا.
         */
        if (trim((string) ($data['website'] ?? '')) !== '') {
            Log::info('أُسقطت رسالة تواصل بحقل الفخّ.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'وصلتنا رسالتك.']);
        }

        $mail = new ContactMessageMail(
            senderName: $data['name'],
            senderEmail: $data['email'],
            subjectLine: $data['subject'],
            body: $data['message'],
        );

        try {
            Mail::to(config('mail.contact.inbox') ?: config('mail.from.address'))
                ->send($mail);
        } catch (TransportException $e) {
            /*
             * الفشل يظهر ولا يُبتلع. «تم الإرسال» ثم لا تصل رسالة يترك
             * الطالب ينتظر ردًّا لن يأتي، ويترك السبب خارج السجلّات.
             */
            Log::error('تعذّر إرسال رسالة تواصل.', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'تعذّر إرسال رسالتك الآن. حاول بعد قليل.',
            ], 502);
        }

        return response()->json(['message' => 'وصلتنا رسالتك.']);
    }
}
