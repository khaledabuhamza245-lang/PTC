<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // يطبّق محدّد المعدّل 'api' المعرَّف في AppServiceProvider على كل
        // مسارات الـ API. بدونه كانت 45 من 49 بلا أي حدّ.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel returns JSON for API requests.

        /*
         * ربط النموذج بالمسار يرمي ModelNotFoundException، ولارافيل يحوّلها
         * إلى NotFoundHttpException **حاملةً نصّها الداخلي**، وconvertException
         * ToArray يمرّر نصّ أي HttpException حتى مع إطفاء التنقيح. فكان الطالب
         * يقرأ تحت جدول خطته:
         *
         *     No query results for model [App\Models\Course] c_ABC
         *
         * إنجليزيةً، وفيها الاسم الكامل للصنف. والحالة ليست نادرة: auth.js
         * يخزّن المساقات خمس دقائق، فحذفُ مادة من اللوحة يترك كل طالب مفتوحةٍ
         * لديه الصفحة على صفٍّ يشير إلى مساق زال.
         *
         * والتسجيل مزدوج عمدًا: ترتيب تحويل الاستثناء قبل نداءات render أو
         * بعدها تفصيلٌ داخلي في الإطار، فالتقاط الشكلين يغني عن الرهان عليه.
         */
        $notFound = fn ($e, $request) => $request->is('api/*')
            ? response()->json(
                ['message' => 'العنصر المطلوب غير موجود أو حُذف.'],
                404
            )
            : null;

        $exceptions->render(
            fn (ModelNotFoundException $e, $request) => $notFound($e, $request)
        );

        $exceptions->render(
            fn (NotFoundHttpException $e, $request) => $notFound($e, $request)
        );
    })
    ->create();
