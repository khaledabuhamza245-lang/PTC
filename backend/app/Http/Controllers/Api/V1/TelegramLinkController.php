<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TelegramLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/*
 * توليد رابط ربط حساب الطالب ببوت تيليجرام (المرحلة الأولى فقط).
 *
 * الطالب المسجّل دخوله يضغط زر بصفحة حسابه بالموقع، هالكنترولر
 * يولّد كود عشوائي مؤقت (صالح 10 دقائق فقط) ويرجّع رابط تيليجرام
 * جاهز (t.me/<bot>?start=<code>) — الطالب يضغطه فيفتح تطبيق تيليجرام
 * تلقائيًا ويبعت /start للبوت، والبوت (TelegramWebhookController)
 * هو يلي يتحقق من الكود ويربط المحادثة فعليًا.
 */
class TelegramLinkController extends Controller
{
    public function store(Request $request)
    {
        $botUsername = (string) config('services.telegram.bot_username', '');

        if ($botUsername === '') {
            return response()->json([
                'message' => 'ربط تيليجرام غير مُفعَّل حاليًا.',
            ], 500);
        }

        $user = $request->user();

        $token = Str::random(32);

        $link = TelegramLink::updateOrCreate(
            ['user_id' => $user->id],
            [
                'link_token' => $token,
                'token_expires_at' => now()->addMinutes(10),
            ]
        );

        return response()->json([
            'data' => [
                'url' => "https://t.me/{$botUsername}?start={$token}",
                'expires_at' => $link->token_expires_at,
                'already_linked' => $link->isLinked(),
            ],
        ]);
    }

    /*
     * حالة الربط الحالية — تستخدمها صفحة الحساب لتعرف تعرض زرّ "اربط"
     * أو "متصل بالفعل" (واسم أول محادثة تيليجرام لو موجود).
     */
    public function show(Request $request)
    {
        $link = TelegramLink::where('user_id', $request->user()->id)->first();

        return response()->json([
            'data' => [
                'linked' => (bool) ($link?->isLinked()),
                'telegram_first_name' => $link?->telegram_first_name,
            ],
        ]);
    }

    public function destroy(Request $request)
    {
        TelegramLink::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'message' => 'تم فصل الحساب عن تيليجرام.',
        ]);
    }
}
