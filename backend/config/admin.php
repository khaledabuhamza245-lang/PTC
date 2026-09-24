<?php

return [

    /*
     * حساب المدير الأول — يقرأها AdminSeeder مرة واحدة عند `db:seed`.
     *
     * تمرّ عبر config لا عبر env() مباشرةً لسبب عملي: بعد
     * `php artisan config:cache` يكفّ لارافيل عن تحميل ملف .env أصلًا،
     * فـ env() ترجع null داخل أي أمر artisan. وترتيب خطوات النشر
     * يضع config:cache بعد db:seed مباشرة — فمن نسي ملء المفاتيح ثم أعاد
     * تشغيل البذرة كان يقع في الصمت نفسه بلا سبب ظاهر. أما config فيُخبَز
     * وقت التخزين المؤقت فيبقى مقروءًا.
     */

    'name' => env('ADMIN_NAME', 'PTC Hub Admin'),
    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),

];
