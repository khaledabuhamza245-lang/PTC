<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * استفسار طالب واصلًا لصندوق الموقع.
 *
 * **بريد الطالب في Reply-To لا في From.** أول ما يخطر هو وضعه مُرسِلًا
 * لتبدو الرسالة «منه»، لكن Brevo لا تقبل في sender إلا عنوانًا متحقَّقًا في
 * الحساب — فتُرفض الرسالة عندهم أو تُعدّ انتحالًا عند جيميل. فتُفصل
 * الهويتان: المُرسِل هو الموقع (MAIL_FROM_ADDRESS المتحقَّق)، والردّ يذهب
 * للطالب. والنتيجة عمليًّا أفضل: الضغط على «رد» في جيميل يصله مباشرة.
 */
class ContactMessageMail extends Mailable
{
    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $subjectLine,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // الموضوع يحمل الاسم والموضوع معًا ليُفرَز الوارد ببحث جيميل
            // وحده، بلا لوحة ولا جدول.
            subject: 'استفسار من '.$this->senderName.': '.$this->subjectLine,
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contact-message');
    }
}
