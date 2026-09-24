const CACHE_NAME = 'ptc-hub-static-v7';

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys =>
        Promise.all(
          keys
            .filter(key => key !== CACHE_NAME)
            .map(key => caches.delete(key))
        )
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;

  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) return;

  const isApiRequest =
    url.pathname.startsWith('/index.php/api/') ||
    url.pathname.startsWith('/api/');

  /*
   * طلبات API ممنوع تخزينها.
   * لازم تصل دائمًا إلى Laravel وتجيب أحدث البيانات.
   */
  if (isApiRequest) {
    event.respondWith(
      fetch(request, {
        cache: 'no-store'
      })
    );

    return;
  }

  /*
   * التنقل بين الصفحات (فتح صفحة جديدة أو تحديث): بلا أي رجوع للكاش
   * عند الانقطاع، عمدًا.
   *
   * الموقع بالكامل قائم على بيانات حيّة من الخادم — صفحة "تفتح" من
   * الكاش بلا اتصال تبدو وكأنها تعمل، بينما كل شيء بداخلها فارغ أو
   * عالق على «جارٍ التحميل» بلا تفسير. الأفضل أن يرى الطالب شاشة
   * "غير متصل" الطبيعية من المتصفح نفسه فور محاولة التنقل أو
   * التحديث، تمامًا كما يحصل في أي موقع آخر لا يعمل بلا اتصال.
   */
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request, {
        cache: 'no-cache'
      })
    );

    return;
  }

  /*
   * الملفات الساكنة (CSS، JS، الصور، الخطوط): "اعرض من الكاش فورًا،
   * وحدّثه بالخلفية" (Stale-While-Revalidate) — بدل الجيل السابق
   * (v6) اللي كان "الشبكة أولًا، والكاش عند الانقطاع فقط".
   *
   * ليش هذا التغيير: بالنسخة القديمة، حتى الزيارة الخمسين لنفس
   * الطالب كانت تنتظر رد الخادم كامل قبل ما تعرض أي شيء — على نت بطيء
   * (أو استضافة مزدحمة، راجع تقرير خطوة ٥١) هذا يعني ثوانٍ من الانتظار
   * لملف JS/CSS الطالب نفسه فتحه عشرات المرات من قبل. بالاستراتيجية
   * الجديدة: لو الملف موجود بالكاش، يُعرض **فورًا** بلا أي انتظار
   * للشبكة إطلاقًا، وبنفس اللحظة يُطلق طلب شبكة بالخلفية يحدّث نسخة
   * الكاش لالمرة الجاية (الطالب لا ينتظره ولا يتأثر فيه). أول زيارة
   * فعلية لملف معيّن (ما في نسخة كاش أصلًا) تبقى تنتظر الشبكة عاديًا —
   * لا فرق عنها بالنسخة القديمة.
   *
   * ليش هذا ما يرجّع مشكلة "عالق على نسخة قديمة" (نفس القرار
   * الموثَّق بخطوة ٤٧ لأسباب أخرى): هناك كان القرار عن Cache-Control
   * طويل الأمد بالمتصفح نفسه (يوم لسنة كاملة بلا أي تحقق من الخادم
   * إطلاقًا). هون مختلف تمامًا: كل زيارة (تبويب/صفحة جديدة) **ما زالت
   * تطلق طلب شبكة فعلي فورًا** بالخلفية لكل ملف — فقط لا تنتظره قبل
   * الرسم. يعني إصلاح يُرفع اليوم يصل لجهاز الطالب فعليًا خلال نفس
   * الجلسة (الطلب الخلفي يحدّث الكاش أثناء تصفّحه)، ويظهر أكيد بأول
   * تنقّل/تحديث تالٍ — لا بعد أسبوع أو سنة زي سيناريو خطوة ٤٧.
   *
   * فشل التحديث الخلفي (لا اتصال إطلاقًا لحظتها) يُلتقَط بهدوء
   * (`.catch(() => null)`) بلا التأثير على الرد المعروض فعليًا للطالب
   * (سبق عرضه من الكاش أو من طلب الشبكة الأساسي، حسب الحالة).
   */
  event.respondWith(
    caches.open(CACHE_NAME).then(cache =>
      cache.match(request).then(cached => {
        const networkUpdate = fetch(request)
          .then(response => {
            if (
              response &&
              response.ok &&
              response.type === 'basic'
            ) {
              cache.put(request, response.clone());
            }

            return response;
          })
          .catch(() => null);

        return cached || networkUpdate;
      })
    )
  );
});