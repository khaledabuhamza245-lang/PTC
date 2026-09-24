<?php

return [

    /*
     * حدود المعدّل — عدد الطلبات في الدقيقة.
     *
     * تُقرأ في App\Providers\AppServiceProvider::boot وتُطبَّق عبر
     * ->throttleApi() في bootstrap/app.php.
     *
     * user   لكل مستخدم مصادَق (المفتاح معرّفه لا عنوانه، لأن شبكة
     *        الجامعة تضع عشرات الطلاب خلف IP واحد)
     * guest  لكل عنوان IP للزوار غير المصادَقين
     * auth_* لمسارات تسجيل الدخول والتسجيل وإعادة تعيين كلمة السر:
     *        حدّ لكل (بريد، IP) يمنع تخمين حساب بعينه، وحدّ لكل IP
     *        وحده يمنع رشّ عشرات الحسابات من نفس المصدر
     *
     * password_change لتغيير كلمة السر من داخل الحساب، مفتاحه معرّف
     *        المستخدم. حدٌّ خاصّ لأن الحدّين أعلاه لا ينفعان: مفتاح auth
     *        هو البريد وهو غائب عن هذا الطلب، وحدّ user فضفاض على مسار
     *        يُخمَّن فيه current_password
     *
     * contact_* لنموذج «تواصل معنا»، و**وحدتهما الساعة لا الدقيقة**:
     *        النموذج يُملأ مرة، ومن يرسل خمسًا في ساعة إما مخطئ أو بوت.
     *        والمصادَق أسخى لأنه معروف بعينه، بينما مفتاح الزائر عنوانه
     *        وشبكة الجامعة تضع عشرات الطلاب خلفه
     */
    'rate_limit' => [
        'user' => (int) env('API_RATE_LIMIT_USER', 120),
        'guest' => (int) env('API_RATE_LIMIT_GUEST', 60),
        'auth_per_email' => (int) env('AUTH_RATE_LIMIT_EMAIL', 5),
        'auth_per_ip' => (int) env('AUTH_RATE_LIMIT_IP', 20),
        'password_change' => (int) env('AUTH_RATE_LIMIT_PASSWORD_CHANGE', 6),
        'contact_user' => (int) env('CONTACT_RATE_LIMIT_USER', 10),
        'contact_guest' => (int) env('CONTACT_RATE_LIMIT_GUEST', 5),
    ],

];
