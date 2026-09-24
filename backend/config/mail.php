<?php

return [
    'default' => env('MAIL_MAILER', 'log'),
    'mailers' => [
        /*
         * ناقل Brevo مسجَّل في AppServiceProvider — انظر التعليق في
         * App\Mail\BrevoApiTransport لسبب اختيار HTTPS على SMTP.
         *
         * والمهلة صريحة لأن الطابور sync: الإرسال داخل دورة الطلب، فلو
         * انقطعت Brevo بلا مهلة صار انقطاعُهم تعليقًا أمام الطالب.
         */
        'brevo' => [
            'transport' => 'brevo',
            'key' => env('BREVO_API_KEY'),
            'timeout' => (int) env('BREVO_TIMEOUT', 10),
        ],
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'array' => ['transport' => 'array'],
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name' => env('MAIL_FROM_NAME', 'PTC Hub'),
    ],

    /*
     * صندوق استفسارات «تواصل معنا».
     *
     * منفصل عن from عمدًا وإن تطابقا اليوم: Brevo لا تقبل في sender إلا
     * عنوانًا متحقَّقًا في الحساب، فلو أراد العميل تحويل الاستفسارات لبريد
     * آخر وجب أن يتحرّك المستقبِل وحده دون أن يتحرّك المُرسِل المتحقَّق.
     * والافتراض يجعل المفتاح غير مطلوب ما دام الصندوقان واحدًا.
     */
    'contact' => [
        'inbox' => env('CONTACT_INBOX_ADDRESS', env('MAIL_FROM_ADDRESS')),
    ],
];
