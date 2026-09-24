<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        /*
         * كان `return;` صامتًا — والصمت هنا أسوأ من أي خطأ.
         *
         * هذا آخر بذرة في DatabaseSeeder، فما قبله زُرع كاملًا: الفصول
         * والمساقات والإعدادات. ثم يخرج الأمر بصفر ويقرأ العميل «تمّت
         * البذرة» ويظن أن كل شيء تمام، بينما لا حساب مدير في القاعدة —
         * فالموقع حيّ ولا أحد يستطيع دخول لوحة التحكم، ولا رسالة يبحث عنها.
         *
         * والرمي آمن هنا تحديدًا لأنه الأخير: لا يُسقط شيئًا زُرع قبله.
         */
        if (! $email || ! $password) {
            throw new RuntimeException(
                'AdminSeeder: لم يُنشأ حساب المدير لأن ADMIN_EMAIL أو ADMIN_PASSWORD فارغ في .env — '
                .'املأهما ثم أعد `php artisan db:seed --force`. '
                .'وإن كنت قد شغّلت `php artisan config:cache` فنفّذ `php artisan config:clear` أولًا، '
                .'وإلا ظلّت القيم غير مقروءة. (ما زُرع قبل هذه الخطوة سليم ولا يحتاج إعادة.)'
            );
        }

        // ADMIN_NAME يبقى متغيّرًا واحدًا في .env ويُقسَّم هنا على الأعمدة الثلاثة.
        $parts = preg_split(
            '/\s+/u',
            trim((string) config('admin.name', 'PTC Hub Admin')),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        User::updateOrCreate(
            ['email' => strtolower($email)],
            [
                'first_name' => $parts[0] ?? 'PTC',
                'father_name' => $parts[1] ?? 'Hub',
                'last_name' => count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : 'Admin',
                'password' => $password,
                'role' => 'admin',
            ],
        );
    }
}
