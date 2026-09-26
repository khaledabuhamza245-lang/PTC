<?php

namespace App\Services\Ai;

/*
 * مجمّع مزوّدي الذكاء الاصطناعي (4 مفاتيح Gemini مجانية + OpenRouter
 * كخط دفاع أخير) — نفس آلية providerPool()/rotatedProviders() الموجودة
 * أصلًا بـAiAssistantController (مساعد الموقع) بالضبط، مستخرَجة هون
 * كخدمة مستقلة قابلة لإعادة الاستخدام بدل تكرار نفس المنطق بمكانين.
 *
 * ⚠ عمدًا بلا أي تعديل على AiAssistantController نفسه — الموقع يبقى
 * شغّال بمنطقه الأصلي المستقل 100% بلا أي مخاطرة، وهذي الخدمة تُستخدم
 * فقط من TelegramAiAssistant حاليًا (البوت كان يستخدم مفتاح Gemini
 * الأساسي وحده بلا أي تدوير ولا OpenRouter — راجع تعليق callGemini
 * بـTelegramAiAssistant للسبب والتفصيل الكامل).
 *
 * الفرق الوحيد عن نسخة الموقع: rotated() هون بترتيب عشوائي البداية لا
 * seed ثابت من معرّف سؤال — لأنه كل نداء بوت مستقل تمامًا بذاته (لا
 * إعادة محاولة لاحقة على نفس السجل بجولات دُفعة متكررة متل الموقع)،
 * فالعشوائية كافية لتوزيع الحمل بين الطلبات المتزامنة.
 */
class GeminiProviderPool
{
    /**
     * كل المزوّدين المتاحين حاليًا (حسب المفاتيح المضبوطة بـ.env) —
     * Gemini الأساسي + الثاني + الثالث + الرابع (أي منها فارغ يُستبعَد
     * تلقائيًا)، ثم OpenRouter أخيرًا لو مفتاحه مضبوط.
     *
     * @return array<int, array{type: string, key?: string}>
     */
    public function all(): array
    {
        $providers = [];

        foreach (array_filter([
            config('services.gemini.key'),
            env('GEMINI_API_KEY_2'),
            env('GEMINI_API_KEY_3'),
            env('GEMINI_API_KEY_4'),
        ]) as $key) {
            $providers[] = ['type' => 'gemini', 'key' => $key];
        }

        if (config('services.openrouter.key')) {
            $providers[] = ['type' => 'openrouter'];
        }

        return $providers;
    }

    /**
     * مزوّدو Gemini فقط (بلا OpenRouter) — لازمة لأي نداء فيه محتوى
     * ملف (inline أو مرفوع)، لأنه OpenRouter لا يدعم المرفقات إطلاقًا.
     *
     * @return array<int, array{type: string, key: string}>
     */
    public function geminiOnly(): array
    {
        return array_values(array_filter($this->all(), static fn (array $p) => $p['type'] === 'gemini'));
    }

    /**
     * نفس قائمة المزوّدين بترتيب مُدار (بداية عشوائية) — لو مزوّد واحد
     * بس أو القائمة فاضية، ترجع كما هي بلا أي تغيير.
     *
     * @param array<int, array{type: string, key?: string}> $providers
     * @return array<int, array{type: string, key?: string}>
     */
    public function rotated(array $providers): array
    {
        if (count($providers) <= 1) {
            return $providers;
        }

        $start = random_int(0, count($providers) - 1);

        return array_merge(array_slice($providers, $start), array_slice($providers, 0, $start));
    }
}
