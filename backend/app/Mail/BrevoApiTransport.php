<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * إرسال البريد عبر واجهة Brevo على HTTPS لا عبر SMTP.
 *
 * السبب قيدُ الاستضافة لا تفضيلٌ في الشكل: `QUEUE_CONNECTION=sync` يعني
 * أن الإرسال يجري داخل دورة الطلب نفسه، وكثير من استضافات cPanel تسكّر
 * المنفذين ٥٨٧ و٢٥ صادرًا. فمنفذٌ مسكَّر هنا لا يعني رسالةً متأخرة في
 * طابور بل طلبًا معلَّقًا أمام الطالب حتى ينقضي timeout. والمنفذ ٤٤٣ لا
 * يُسكَّر، وطلبٌ واحد عليه أخفّ من مصافحة SMTP كاملة بـTLS.
 *
 * وكُتب الصنف هنا بدل جسر symfony/brevo-mailer الرسمي لأن جهاز التطوير
 * بلا composer، فتبعية لا يمكن تركيبها ولا التحقق منها محليًّا. وهو
 * الأنسب للتسليم كذلك: العميل يشغّل `composer install` على استضافة
 * مشتركة، وكل تبعية أقلّ خطرُ فشلٍ أقلّ.
 */
class BrevoApiTransport extends AbstractTransport
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        private readonly string $key,
        private readonly int $timeout = 10,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new TransportException('نقل Brevo يرسل رسائل Email وحدها.');
        }

        $response = Http::withHeaders([
            'api-key' => $this->key,
            'accept' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->asJson()
            ->post(self::ENDPOINT, $this->payload($email));

        /*
         * الفشل يُرمى ولا يُبتلع. الصامت منه أسوأ من الظاهر: طلبُ إعادة
         * تعيين يردّ «تم الإرسال» ثم لا تصل رسالة يترك الطالب ينتظر بريدًا
         * لن يأتي، ويترك السبب خارج السجلّات.
         */
        if ($response->failed()) {
            throw new TransportException(sprintf(
                'رفضت Brevo الرسالة (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        // messageId يغيب عن الردّ حين يتعدّد المستقبِلون (تردّ Brevo
        // messageIds جمعًا)، فلا يُسنَد إلا إذا جاء.
        if ($id = (string) $response->json('messageId', '')) {
            $message->setMessageId($id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        if (! $from instanceof Address) {
            throw new TransportException('الرسالة بلا مُرسِل — اضبط MAIL_FROM_ADDRESS.');
        }

        $payload = [
            'sender' => $this->address($from),
            'to' => $this->addresses($email->getTo()),
            'subject' => $email->getSubject() ?? '',
        ];

        if ($html = $email->getHtmlBody()) {
            $payload['htmlContent'] = $this->body($html);
        }

        if ($text = $email->getTextBody()) {
            $payload['textContent'] = $this->body($text);
        }

        /*
         * Brevo ترفض رسالة بلا htmlContent ولا textContent. ورسالةٌ بلا
         * متن ليست حالةً نمرّرها لتُرفض عندهم برسالة غامضة.
         */
        if (! isset($payload['htmlContent']) && ! isset($payload['textContent'])) {
            throw new TransportException('الرسالة بلا متن — لا HTML ولا نصّ.');
        }

        foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $field => $list) {
            if ($list !== []) {
                $payload[$field] = $this->addresses($list);
            }
        }

        if ($replyTo = $email->getReplyTo()[0] ?? null) {
            $payload['replyTo'] = $this->address($replyTo);
        }

        return $payload;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array<string, string>>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map($this->address(...), $addresses));
    }

    /**
     * @return array<string, string>
     */
    private function address(Address $address): array
    {
        $payload = ['email' => $address->getAddress()];

        if ($address->getName() !== '') {
            $payload['name'] = $address->getName();
        }

        return $payload;
    }

    private function body(string|\Stringable $body): string
    {
        return (string) $body;
    }

    public function __toString(): string
    {
        return 'brevo+api://';
    }
}
