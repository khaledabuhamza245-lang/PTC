<?php

namespace App\Services\Ai;
/*
 * مستخرج/مُقسِّم صفحات PDF بسيط ومباشر — بلا أي مكتبة خارجية، مصمَّم
 * حصرًا لملفات PDF "الكلاسيكية" البسيطة (جدول xref عادي غير مضغوط،
 * بلا تشفير، بلا object streams). أي ملف لا يطابق هذا الشكل تُرفَض
 * محاولة تقسيمه بهدوء (isSplittable() ترجع false) — لا نحاول تخمين
 * تعامل مع بنى أعقد (ObjStm / XRef streams / تشفير) لأن الفشل الصامت
 * أو الملف التالف أخطر بكثير من مجرد عدم التقسيم.
 *
 * الفكرة: PDF "التصوير" (صفحة = صورة JPEG واحدة، زي ملفات تطبيق
 * "الملاحظات" على آيفون) أكبر مشكلة تواجهنا مع الملخّصات الممسوحة
 * ضوئيًا الكبيرة — تتجاوز حد Gemini الأقصى للتوكنز بسهولة. الحل: نفصل
 * نطاق صفحات محدد لملف PDF جديد صغير مستقل، بنسخ الكائنات (objects)
 * الأصلية حرفيًا (بايت لبايت لمحتوى الصور/التدفقات) وإعادة ترقيمها
 * فقط، بلا أي فهم أو تفسير لمحتوى الصور نفسها.
 *
 * مهم لذاكرة PHP المحدودة على استضافة مشتركة (128 ميغا افتراضيًا):
 * الملف الأصلي لا يبقى بالذاكرة كاملًا طوال حياة الكائن. تدفّقات
 * الصور (أكبر جزء بالملف عادة) تُقرأ من القرص عند الحاجة فقط (offset
 * محفوظ لا محتوى منسوخ) — لا تُحمَّل كل الصفحات لملف مستقل واحد إلا
 * لحظة كتابتها فعليًا، وتُترَك للـGC فورًا بعدها. أول إصدار من هذا
 * الملف كان يحتفظ بنسخة كاملة من كل تدفقات الملف بالذاكرة (مضاعفًا
 * الاستهلاك) وسبّب "Allowed memory size exhausted" فعليًا على ملف
 * حقيقي بحجم ٤٣ ميغا — هذا التصميم بديل مباشر لتلك المشكلة.
 */
class PdfPageSplitter
{
    private string $filePath;

    /** @var array<int, array{dict:string, streamStart:?int, streamLen:?int}> */
    private array $objects = [];

    private ?int $rootObjNum = null;
    private ?int $pagesRootObjNum = null;

    /** @var int[] ترتيب ظهور الصفحات الفعلي بالمستند (أرقام كائنات) */
    private array $pageOrder = [];

    private ?string $inheritedMediaBox = null;
    private ?string $inheritedResources = null;

    private bool $parsed = false;
    private bool $parseOk = false;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /** @deprecated استخدم المُنشئ مباشرة بمسار ملف — أُبقيت للتوافق فقط. */
    public static function fromFile(string $path): self
    {
        return new self($path);
    }

    public function pageCount(): int
    {
        $this->ensureParsed();
        return count($this->pageOrder);
    }

    public function isSplittable(): bool
    {
        $this->ensureParsed();
        return $this->parseOk;
    }

    private function ensureParsed(): void
    {
        if ($this->parsed) {
            return;
        }
        $this->parsed = true;

        try {
            $this->parseOk = $this->parseInternal();
        } catch (\Throwable $e) {
            $this->parseOk = false;
        }
    }

    private function parseInternal(): bool
    {
        if (!is_file($this->filePath)) {
            return false;
        }

        /* قراءة الملف كامل مرة واحدة بس، بمتغيّر محلي (لا خاصية بالكائن)
           — يُترك للـGC فور خروجنا من هذي الدالة، فلا يبقى بالذاكرة أثناء
           extractRange() لاحقًا (هناك نقرأ فقط الأجزاء المطلوبة من القرص). */
        $bytes = (string) file_get_contents($this->filePath);
        if ($bytes === '') {
            return false;
        }

        // رفض مبكر وصريح لأي بنية أعقد من قدرتنا الآمنة.
        if (str_contains($bytes, '/Encrypt')) {
            return false;
        }
        if (preg_match('/\/Type\s*\/XRef\b/', $bytes)) {
            return false; // جدول مراجع مضغوط (cross-reference stream)
        }
        if (preg_match('/\/Type\s*\/ObjStm\b/', $bytes)) {
            return false; // كائنات مضغوطة بداخل object stream
        }

        $this->loadObjects($bytes);
        if (!$this->objects) {
            return false;
        }

        $rootNum = $this->findRootObjNum($bytes);
        if ($rootNum === null || !isset($this->objects[$rootNum])) {
            return false;
        }
        $this->rootObjNum = $rootNum;

        $rootDict = $this->objects[$rootNum]['dict'];
        $pagesNum = $this->refValue($rootDict, '/Pages');
        if ($pagesNum === null || !isset($this->objects[$pagesNum])) {
            return false;
        }
        $this->pagesRootObjNum = $pagesNum;

        // MediaBox/Resources الموروثة (inheritable attributes) — تُقرأ
        // من جذر شجرة الصفحات إن وُجدت هناك، لأن كثيرًا من مولّدات PDF
        // (مثل ملفات تطبيق الملاحظات) تضعها مرة واحدة بالجذر فقط ولا
        // تكررها بكل صفحة على حدة.
        $pagesDict = $this->objects[$pagesNum]['dict'];
        $this->inheritedMediaBox = $this->rawValue($pagesDict, '/MediaBox');
        $this->inheritedResources = $this->rawValue($pagesDict, '/Resources');

        $order = [];
        $seen = [];
        if (!$this->walkPagesTree($pagesNum, $order, $seen, 0)) {
            return false;
        }
        if (!$order) {
            return false;
        }

        $this->pageOrder = $order;
        return true;
    }

    /** مسح كل الكائنات "N G obj ... endobj" بالملف كامل — بلا الاعتماد
     *  على جدول xref (أوثق من ثقتنا بصحّة الأوفستات المكتوبة فيه).
     *  يُخزَّن نص القاموس فقط (صغير جدًا)، أما بيانات التدفق (الصورة
     *  عادة، وهي الجزء الضخم) فتُسجَّل كموقع/طول بالملف بس، لا كنسخة. */
    private function loadObjects(string $bytes): void
    {
        $len = strlen($bytes);
        $offset = 0;

        while (preg_match(
            '/(\d+)[ \t]+(\d+)[ \t]+obj\b/',
            $bytes,
            $m,
            PREG_OFFSET_CAPTURE,
            $offset
        )) {
            $objNum = (int) $m[1][0];
            $headerStart = (int) $m[0][1];
            $bodyStart = $headerStart + strlen($m[0][0]);

            $endobjPos = strpos($bytes, 'endobj', $bodyStart);
            if ($endobjPos === false) {
                break;
            }

            $streamPos = strpos($bytes, 'stream', $bodyStart);
            $dictEnd = ($streamPos !== false && $streamPos < $endobjPos) ? $streamPos : $endobjPos;
            $dictPart = substr($bytes, $bodyStart, $dictEnd - $bodyStart);

            $streamStart = null;
            $streamLen = null;

            if ($streamPos !== false && $streamPos < $endobjPos) {
                $afterKeyword = $streamPos + strlen('stream');
                if (preg_match('/\A(\r\n|\n)/', substr($bytes, $afterKeyword, 2), $nl)) {
                    $dataStart = $afterKeyword + strlen($nl[0]);
                    $endstreamPos = strpos($bytes, 'endstream', $dataStart);
                    if ($endstreamPos !== false && $endstreamPos <= $endobjPos) {
                        $streamStart = $dataStart;
                        $streamLen = $endstreamPos - $dataStart;
                    }
                }
            }

            $this->objects[$objNum] = [
                'dict' => $dictPart,
                'streamStart' => $streamStart,
                'streamLen' => $streamLen,
            ];

            $offset = $endobjPos + strlen('endobj');
            if ($offset >= $len) {
                break;
            }
        }
    }

    private function findRootObjNum(string $bytes): ?int
    {
        $lastTrailer = strrpos($bytes, 'trailer');
        if ($lastTrailer !== false) {
            $dictStart = strpos($bytes, '<<', $lastTrailer);
            if ($dictStart !== false) {
                $dictBytes = $this->extractBalanced($bytes, $dictStart);
                if ($dictBytes !== null) {
                    $root = $this->refValue($dictBytes, '/Root');
                    if ($root !== null) {
                        return $root;
                    }
                }
            }
        }

        // بديل احتياطي: كائن /Type /Catalog مباشرة، لو ما لقينا trailer
        // صريح (مثلًا PDF منتج بأداة غير معتادة).
        foreach ($this->objects as $num => $obj) {
            if (preg_match('/\/Type\s*\/Catalog\b/', $obj['dict'])) {
                return $num;
            }
        }
        return null;
    }

    /** يمشي شجرة /Pages بعمق، بترتيب /Kids كما هو مكتوب، ويجمع أرقام
     *  الكائنات الورقية (/Type /Page) فقط بترتيب ظهورها بالمستند. */
    private function walkPagesTree(int $objNum, array &$order, array &$seen, int $depth): bool
    {
        if ($depth > 64 || isset($seen[$objNum]) || !isset($this->objects[$objNum])) {
            return false; // حماية من حلقة لا نهائية بشجرة تالفة
        }
        $seen[$objNum] = true;

        $dict = $this->objects[$objNum]['dict'];
        $type = $this->nameValue($dict, '/Type');

        if ($type === 'Page') {
            $order[] = $objNum;
            return true;
        }

        $kidsRaw = $this->rawValue($dict, '/Kids');
        if ($kidsRaw === null) {
            return false;
        }

        preg_match_all('/(\d+)\s+(\d+)\s+R/', $kidsRaw, $matches);
        if (!$matches[1]) {
            return false;
        }

        foreach ($matches[1] as $kidNum) {
            if (!$this->walkPagesTree((int) $kidNum, $order, $seen, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    // ---------- أدوات قراءة قواميس PDF الخام (بلا محلِّل كامل) ----------

    /** يرجع رقم الكائن لو القيمة "N G R"، وإلا null. */
    private function refValue(string $dict, string $key): ?int
    {
        if (preg_match('/' . preg_quote($key, '/') . '\s+(\d+)\s+(\d+)\s+R\b/', $dict, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function nameValue(string $dict, string $key): ?string
    {
        if (preg_match('/' . preg_quote($key, '/') . '\s*\/([A-Za-z0-9_.+-]+)/', $dict, $m)) {
            return $m[1];
        }
        return null;
    }

    /** يرجع القيمة الخام (بلا تفسير) لأي مفتاح — تدعم: قاموس متداخل
     *  <<...>>، مصفوفة [...]، أو مرجع/قيمة بسيطة سطر واحد. */
    private function rawValue(string $dict, string $key): ?string
    {
        $pos = strpos($dict, $key);
        if ($pos === false) {
            return null;
        }
        $valueStart = $pos + strlen($key);
        // تخطّي الفراغات
        while ($valueStart < strlen($dict) && ctype_space($dict[$valueStart])) {
            $valueStart++;
        }
        if ($valueStart >= strlen($dict)) {
            return null;
        }

        if (substr($dict, $valueStart, 2) === '<<') {
            return $this->extractBalanced($dict, $valueStart, '<<', '>>');
        }
        if ($dict[$valueStart] === '[') {
            return $this->extractBalanced($dict, $valueStart, '[', ']');
        }

        // قيمة سطر واحد بسيطة (مرجع "N G R"، رقم، اسم...) — حتى أقرب
        // '/' يبدأ مفتاحًا جديدًا أو نهاية القاموس.
        $rest = substr($dict, $valueStart);
        if (preg_match('/\A.*?(?=\/[A-Za-z]|>>|$)/s', $rest, $m)) {
            return trim($m[0]);
        }
        return null;
    }

    /** يستخرج نصًا متوازن الأقواس بادئًا من $start (يشمل فتحه وإغلاقه). */
    private function extractBalanced(string $text, int $start, string $open = '<<', string $close = '>>'): ?string
    {
        $len = strlen($text);
        $openLen = strlen($open);
        $closeLen = strlen($close);
        $depth = 0;
        $i = $start;

        while ($i < $len) {
            if (substr($text, $i, $openLen) === $open) {
                $depth++;
                $i += $openLen;
                continue;
            }
            if (substr($text, $i, $closeLen) === $close) {
                $depth--;
                $i += $closeLen;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start);
                }
                continue;
            }
            $i++;
        }
        return null; // غير متوازن — ملف غير سليم
    }

    /** كل مراجع "N G R" الظاهرة بقاموس كائن (بلا تدقيق مفتاح) — تُستخدم
     *  لتجميع الكائنات القابلة للوصول (closure) عبر BFS. */
    private function allRefs(string $dict): array
    {
        preg_match_all('/(\d+)\s+(\d+)\s+R\b/', $dict, $m);
        return array_map('intval', $m[1]);
    }

    /** يقرأ تدفق كائن (لو له تدفق) من القرص مباشرة — بلا الاحتفاظ بأي
     *  نسخة أخرى بالذاكرة أطول من عمر هذا الاستدعاء. */
    private function readStream(int $objNum): ?string
    {
        $obj = $this->objects[$objNum];
        if ($obj['streamStart'] === null) {
            return null;
        }

        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            return null;
        }
        fseek($handle, $obj['streamStart']);
        $data = fread($handle, $obj['streamLen']);
        fclose($handle);

        return $data === false ? null : $data;
    }

    // ---------- بناء ملف PDF جديد لنطاق صفحات محدد ----------

    /**
     * يبني ملف PDF جديد صالح مستقل يحتوي فقط الصفحات [start, end)
     * (فهرسة تبدأ من صفر) من المستند الأصلي، ويكتبه مباشرة لملف
     * $outputPath (لا يرجع البايتات كسلسلة بالذاكرة — الملف الأصلي
     * غالبًا عشرات الميغا، وإرجاعها كسلسلة PHP يضاعف الذاكرة بلا داعٍ).
     */
    public function extractRangeToFile(int $start, int $end, string $outputPath): void
    {
        $this->ensureParsed();
        if (!$this->parseOk) {
            throw new \RuntimeException('هذا الملف ليس بالبنية البسيطة المدعومة للتقسيم.');
        }

        $selectedPages = array_slice($this->pageOrder, $start, $end - $start);
        if (!$selectedPages) {
            throw new \RuntimeException('نطاق صفحات فارغ.');
        }

        // BFS لتجميع كل الكائنات المطلوبة (الصفحة + كل ما تشير له
        // بشكل متعدٍّ، عدا /Parent الذي سنستبدله بجذر جديد خاص بنا).
        $closure = [];
        $queue = $selectedPages;
        $isPageObj = array_flip($selectedPages);

        while ($queue) {
            $num = array_shift($queue);
            if (isset($closure[$num]) || !isset($this->objects[$num])) {
                continue;
            }
            $closure[$num] = true;

            $dict = $this->objects[$num]['dict'];
            $dictForRefs = $dict;
            if (isset($isPageObj[$num])) {
                // لا نتبع /Parent من كائن صفحة — رح نستبدله بجذر جديد.
                $dictForRefs = preg_replace('/\/Parent\s+\d+\s+\d+\s+R/', '', $dict);
            }

            foreach ($this->allRefs($dictForRefs) as $ref) {
                if (!isset($closure[$ref])) {
                    $queue[] = $ref;
                }
            }
        }

        // ترقيم جديد: 1 = Catalog، 2 = Pages، 3.. بقية الكائنات
        // بترتيب اكتشافها (لا يهم الترتيب طالما المراجع تُعاد كتابتها).
        $newNum = [];
        $next = 3;
        foreach (array_keys($closure) as $oldNum) {
            $newNum[$oldNum] = $next++;
        }
        $catalogNum = 1;
        $pagesNum = 2;

        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('تعذّر إنشاء ملف الجزء المؤقت.');
        }

        $written = 0;
        $writeRaw = function (string $data) use ($out, &$written) {
            fwrite($out, $data);
            $written += strlen($data);
        };

        $writeRaw("%PDF-1.3\n");
        $offsets = [];

        $writeObj = function (int $num, string $dictPart, ?string $streamData) use ($writeRaw, &$offsets, &$written) {
            $offsets[$num] = $written;
            $writeRaw($num . " 0 obj\n" . $dictPart);
            if ($streamData !== null) {
                $writeRaw("\nstream\n");
                $writeRaw($streamData);
                $writeRaw("\nendstream");
            }
            $writeRaw("\nendobj\n");
        };

        // كائنات الصفحات المنسوخة (مُعاد ترقيم مراجعها + Parent جديد) —
        // تدفّق كل كائن يُقرأ من القرص هنا فقط، ويُكتب فورًا، بلا أي
        // تجميع لكل التدفقات بالذاكرة دفعة وحدة.
        foreach (array_keys($closure) as $oldNum) {
            $obj = $this->objects[$oldNum];
            $dict = $obj['dict'];

            $dict = preg_replace_callback(
                '/(\d+)(\s+)(\d+)(\s+)R\b/',
                function ($m) use ($newNum) {
                    $old = (int) $m[1];
                    return isset($newNum[$old])
                        ? $newNum[$old] . $m[2] . '0' . $m[4] . 'R'
                        : $m[0];
                },
                $dict
            );

            if (isset($isPageObj[$oldNum])) {
                if (preg_match('/\/Parent\s+\d+\s+\d+\s+R/', $dict)) {
                    $dict = preg_replace('/\/Parent\s+\d+\s+\d+\s+R/', '/Parent ' . $pagesNum . ' 0 R', $dict);
                } else {
                    $dict = rtrim($dict);
                    $dict = rtrim($dict, "> \t\r\n");
                    $dict .= ' /Parent ' . $pagesNum . ' 0 R >>';
                }
                if (!str_contains($dict, '/MediaBox') && $this->inheritedMediaBox) {
                    $dict = rtrim($dict);
                    $dict = rtrim($dict, "> \t\r\n");
                    $dict .= ' /MediaBox ' . $this->inheritedMediaBox . ' >>';
                }
                if (!str_contains($dict, '/Resources') && $this->inheritedResources) {
                    $inheritedRes = preg_replace_callback(
                        '/(\d+)(\s+)(\d+)(\s+)R\b/',
                        function ($m) use ($newNum) {
                            $old = (int) $m[1];
                            return isset($newNum[$old])
                                ? $newNum[$old] . $m[2] . '0' . $m[4] . 'R'
                                : $m[0];
                        },
                        $this->inheritedResources
                    );
                    $dict = rtrim($dict);
                    $dict = rtrim($dict, "> \t\r\n");
                    $dict .= ' /Resources ' . $inheritedRes . ' >>';
                }
            }

            $streamData = $this->readStream($oldNum);
            $writeObj($newNum[$oldNum], $dict, $streamData);
            unset($streamData); // يُفرَّغ فورًا قبل الانتقال للكائن التالي
        }

        // كائن Pages الجديد (بترتيب الصفحات المطلوب بالضبط).
        $kidsRefs = array_map(fn ($old) => $newNum[$old] . ' 0 R', $selectedPages);
        $pagesBody = '<< /Type /Pages /Kids [' . implode(' ', $kidsRefs) . '] /Count ' . count($selectedPages) . ' >>';
        $writeObj($pagesNum, $pagesBody, null);

        // كائن Catalog الجديد.
        $catalogBody = '<< /Type /Catalog /Pages ' . $pagesNum . ' 0 R >>';
        $writeObj($catalogNum, $catalogBody, null);

        // جدول xref + trailer.
        $allNums = array_merge([$catalogNum, $pagesNum], array_values($newNum));
        sort($allNums);
        $maxNum = max($allNums);

        $xrefStart = $written;
        $writeRaw("xref\n0 " . ($maxNum + 1) . "\n");
        $writeRaw("0000000000 65535 f \n");
        for ($n = 1; $n <= $maxNum; $n++) {
            if (isset($offsets[$n])) {
                $writeRaw(sprintf("%010d 00000 n \n", $offsets[$n]));
            } else {
                $writeRaw("0000000000 00000 f \n");
            }
        }
        $writeRaw("trailer\n<< /Size " . ($maxNum + 1) . " /Root " . $catalogNum . " 0 R >>\n");
        $writeRaw("startxref\n" . $xrefStart . "\n%%EOF");

        fclose($out);
    }

    /**
     * توافقيًا فقط — ترجع البايتات كسلسلة (تكلفة ذاكرة أعلى، تُستخدم
     * حصرًا بالاختبارات المحلية الصغيرة، لا بالكود الحقيقي على ملفات
     * كبيرة). الكود الفعلي يستخدم extractRangeToFile() حصرًا.
     */
    public function extractRange(int $start, int $end): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdfsplit_');
        $this->extractRangeToFile($start, $end, $tmp);
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}