/* ────────────────────────────────────────────────────────────────────
   جرس الإعلانات.
 
   قبل هذا الملف كان الإعلان يُكتب في اللوحة ويُحفظ في القاعدة ويُخدَم
   من `GET /v1/announcements` — **ولا يستدعيه أحد**. أنبوب مكتمل من طرف
   واحد: الخادم يقدّم والموقع لا يطلب. هذا الملف هو الطرف الناقص.
 
   لماذا الحقن من سكربت لا تعديل الصفحات: الشريط العلوي مكتوب يدويًا في
   أربع عشرة صفحة، فزرٌّ فيها يعني أربعة عشر تعديلًا متطابقًا تتباعد عند
   أول تغيير. الحقن يجعلها موضعًا واحدًا — وهو نمط المستودع المستقرّ
   (contribute.js يفعل الشيء نفسه في التذييل).
 
   ولماذا createElement لا قوالب نصية: نصّ الإعلان يكتبه الطاقم في لوحة
   التحكم ويُعرض لكل زائر. البناء بالعقد يجعله نصًّا بحكم الـDOM نفسه لا
   بحكم تهريب قد يُنسى في تعديل لاحق.
   ──────────────────────────────────────────────────────────────────── */
const PTCAnnouncements = (function () {
  'use strict';
 
  const SEEN_KEY = 'ptc_seen_announcement';
  const DAY = 86400000;
 
  /*
   * صفحتان بلا جرس: login.html — الزائر لم يدخل الموقع بعد فالإعلان
   * يزاحم المهمة الوحيدة للصفحة. و admin.html — حيث يُكتب الإعلان،
   * وعرضه على كاتبه في الشريط تكرارٌ لا تنبيه.
   */
  const SKIP_PAGES = new Set(['login.html', 'admin.html']);
 
  function currentPage() {
    return (location.pathname.split('/').pop() || 'index.html').toLowerCase();
  }
 
  function readSeen() {
    try { return Number(localStorage.getItem(SEEN_KEY)) || 0; } catch (_) { return 0; }
  }
 
  function writeSeen(id) {
    try { localStorage.setItem(SEEN_KEY, String(Number(id) || 0)); } catch (_) {}
  }
 
  /*
   * ── الذاكرة المحلية ──────────────────────────────────────────────
   *
   * سببها زمنيّ بحت: الجرس كان لا يُبنى إلا بعد وصول ردّ الشبكة، فيظهر
   * متأخرًا عن بقية الشريط ويزحزح ما بجانبه حين يظهر. القائمة المحفوظة
   * ترسمه في اللحظة الأولى، والشبكة تصحّحه بعدها بصمت.
   *
   * والمفتاح يحمل بصمة الرمز لا اسمًا ثابتًا: الحمولة **موجَّهة** منذ
   * صار للإعلان جمهور، فذاكرة باسم واحد على جهاز مشترك تعرض إعلانات
   * سنة الطالب السابق لمن يفتح بعده. تبدُّل الرمز يبدّل المفتاح فتسقط
   * المسألة من أصلها. (نفس ما حرست منه ذاكرة المساقات في auth.js.)
   */
  const CACHE_PREFIX = 'ptc_announcements_';
  const CACHE_TTL = 24 * 60 * 60 * 1000;
 
  /** djb2 — مفتاح ذاكرة لا أكثر، لا يُقصد به إخفاء الرمز ولا حمايته. */
  function fingerprint(value) {
    const text = String(value || '');
    let hash = 5381;
 
    for (let i = 0; i < text.length; i++) {
      hash = ((hash * 33) ^ text.charCodeAt(i)) >>> 0;
    }
 
    return hash.toString(36);
  }
 
  function cacheKey() {
    const token = (typeof PTCApi !== 'undefined' && PTCApi.getToken)
      ? PTCApi.getToken()
      : '';
 
    return CACHE_PREFIX + fingerprint(token);
  }
 
  function readCache() {
    try {
      const raw = localStorage.getItem(cacheKey());
      if (!raw) return null;
 
      const saved = JSON.parse(raw);
      if (!saved || !Array.isArray(saved.items)) return null;
 
      /* ذاكرة قديمة جدًّا قد تُحيي إعلانًا محذوفًا للحظة — تُهمَل. */
      if (Date.now() - Number(saved.at || 0) > CACHE_TTL) return null;
 
      return saved.items;
    } catch (_) {
      return null;
    }
  }
 
  function writeCache(items) {
    try {
      const key = cacheKey();
 
      /* تنظيف ذاكرات الحسابات السابقة على هذا الجهاز. */
      Object.keys(localStorage)
        .filter(name => name.indexOf(CACHE_PREFIX) === 0 && name !== key)
        .forEach(name => localStorage.removeItem(name));
 
      localStorage.setItem(key, JSON.stringify({ at: Date.now(), items: items }));
    } catch (_) {}
  }
 
  /** أعلى معرّف في القائمة — عليه وحده تُبنى حالة «مقروء». */
  function topId(items) {
    return (items || []).reduce(
      (max, item) => Math.max(max, Number(item && item.id) || 0),
      0,
    );
  }
 
  /*
   * «غير المقروء» = ما معرّفه أعلى من آخر معرّف رآه هذا الجهاز.
   *
   * ولماذا علامة في المتصفح لا جدول قراءات في الخادم: الجدول يعني صفًّا
   * لكل مستخدم × كل إعلان ومسارَي قراءة وكتابة، مقابل نقطة على جرس.
   * والعلامة المحلية **أدقّ** على جهاز مشترك: من قرأ هو من يجلس أمامه
   * لا من سجّل دخوله آخر مرة.
   */
  function unreadCount(items, seenId) {
    const seen = Number(seenId) || 0;
 
    return (items || []).filter(
      item => (Number(item && item.id) || 0) > seen,
    ).length;
  }
 
  /*
   * الفرق بالأيام التقويمية لا بمضاعفات أربع وعشرين ساعة: إعلانٌ نُشر
   * الحادية عشرة مساءً يصير «أمس» بعد ساعتين لا بعد يوم كامل — وهو ما
   * يتوقّعه القارئ حين ينظر إلى التاريخ.
   */
  function relativeDay(iso, now) {
    /*
     * الحارس قبل new Date لا بعده: `new Date(null)` **تاريخ صالح** عند
     * جافاسكربت — بداية الحقبة — فيمرّ من فحص NaN ويطبع «1/1/1970».
     * حقلٌ فارغ يجب أن يُسقط سطر التاريخ لا أن يخترع واحدًا.
     */
    if (iso === null || iso === undefined || iso === '') return '';
 
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
 
    const today = now instanceof Date ? now : new Date();
 
    const then = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
    const here = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    const days = Math.round((here - then) / DAY);
 
    if (days <= 0) return 'اليوم';
    if (days === 1) return 'أمس';
    if (days === 2) return 'أول أمس';
    if (days <= 6) return 'قبل ' + days + ' أيام';
 
    return date.toLocaleDateString('ar-EG-u-nu-latn');
  }
 
  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }
 
  function renderList(list, items, seen) {
    list.replaceChildren();
 
    items.forEach(item => {
      const card = el('div', 'ptc-bell-item');
      if ((Number(item.id) || 0) > seen) card.classList.add('unread');
 
      card.appendChild(el('div', 'ptc-bell-title', item.title || 'إعلان'));
 
      const body = String(item.body || '').trim();
      if (body) card.appendChild(el('div', 'ptc-bell-body', body));
 
      /*
       * الإعلان الموجَّه إلى مساق يحمل رمزه: الطالب مسجّل في ستة مساقات،
       * و«موعد التسليم الخميس» بلا رمز سؤالٌ لا خبر.
       */
      const parts = [];
      if (item.course && item.course.code) parts.push('مساق ' + item.course.code);
 
      const when = relativeDay(item.created_at);
      if (when) parts.push(when);
 
      if (parts.length) card.appendChild(el('div', 'ptc-bell-when', parts.join(' · ')));
 
      list.appendChild(card);
    });
  }
 
  function buildBell(initialItems) {
    /*
     * القائمة متغيّرة لا ثابتة: الجرس يُرسم أولًا من الذاكرة ثم يُصحَّح
     * حين يصل ردّ الشبكة. لو أُغلقت على قيمتها الأولى لبقي زرّ الفتح
     * يحفظ «المقروء» على آخر معرّف **قديم**، فتعود النقطة الحمراء بعد
     * كل تحديث للصفحة.
     */
    let items = initialItems;
 
    const seen = readSeen();
    const unread = unreadCount(items, seen);
 
    const wrap = el('div', 'ptc-bell');
 
    const button = el('button', 'ptc-bell-btn');
    button.type = 'button';
    button.title = 'الإعلانات';
    button.setAttribute('aria-label', 'الإعلانات');
    button.setAttribute('aria-haspopup', 'true');
    button.setAttribute('aria-expanded', 'false');
 
    /*
     * جرس لا مكبّر صوت: megaphone في هذه المجموعة مخروطٌ بموجتين
     * يُقرأ «الصوت» أو «كتم» قبل أن يُقرأ «إعلان» — وهو ما ظهر فعلًا
     * أول مرة. الجرس اصطلاحٌ لا يحتاج تفسيرًا.
     *
     * والأيقونة من icons.js حين يكون محمَّلًا، وإلا رمز نصّي — لا صفحة
     * بلا جرس لأن ملف أيقونات لم يصل.
     */
    if (typeof ic === 'function') button.innerHTML = ic('bell', 20);
    else button.textContent = '🔔';
 
    const badge = el('span', 'ptc-bell-badge', unread > 9 ? '+9' : String(unread));
    badge.hidden = unread === 0;
    button.appendChild(badge);
 
    const panel = el('div', 'ptc-bell-panel');
    panel.hidden = true;
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', 'آخر الإعلانات');
 
    panel.appendChild(el('div', 'ptc-bell-head', 'الإعلانات'));
 
    const list = el('div', 'ptc-bell-list');
    renderList(list, items, seen);
    panel.appendChild(list);
 
    /*
     * موضع اللوحة يُحسب في JS ولا يُترك لـ CSS — وليست هذه رفاهية:
     *
     * الشريط العلوي `.nav` يحمل `backdrop-filter: blur(12px)`، وأي عنصر
     * بهذه الخاصية **يصير الإطار المرجعي لكل `position:fixed` بداخله**
     * بدل الشاشة. أي أن `top: 144px` للوحة داخل الشريط تعني «١٤٤ من
     * أعلى الشريط» لا «من أعلى الشاشة» — قِيست فخرجت ٧٨ بكسل أوطأ.
     *
     * ولهذا تُلحَق اللوحة بـ body لا بالجرس، ويُقاس موضعها من مستطيل
     * الزر في الشاشة. والقصّ داخل الحدّين يجعلها تفِ على ٣٧٥ بكسل كما
     * تفِ على ١٩٢٠ بلا media query ولا حالة خاصة.
     */
    function place() {
      const rect = button.getBoundingClientRect();
      const margin = 14;
      const width = Math.min(360, window.innerWidth - margin * 2);
 
      const wanted = rect.right - width;
      const left = Math.max(margin, Math.min(wanted, window.innerWidth - margin - width));
 
      panel.style.width = width + 'px';
      panel.style.left = Math.round(left) + 'px';
      panel.style.top = Math.round(rect.bottom + 10) + 'px';
      panel.style.maxHeight = Math.round(window.innerHeight - rect.bottom - 30) + 'px';
    }
 
    function setOpen(open) {
      panel.hidden = !open;
      wrap.classList.toggle('open', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
 
      if (!open) return;
 
      place();
 
      /*
       * يُحفَظ «المقروء» عند الفتح وتختفي النقطة، **ويبقى تظليل الجديد
       * على البطاقات حتى تحميل الصفحة التالية**: لو زال التظليل لحظة
       * الفتح لاختفى الجديد من تحت عين قارئه قبل أن يميّزه.
       */
      writeSeen(topId(items));
      badge.hidden = true;
    }
 
    button.addEventListener('click', event => {
      event.stopPropagation();
      setOpen(panel.hidden);
    });
 
    /* اللوحة خارج wrap الآن، فلا بدّ من فحصها معه وإلا أغلقت نفسها
       بمجرّد النقر داخلها. */
    document.addEventListener('click', event => {
      if (!wrap.contains(event.target) && !panel.contains(event.target)) setOpen(false);
    });
 
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') setOpen(false);
    });
 
    /* موضعها محسوب من مستطيل الزر، فتدوير الجهاز أو تغيير المقاس
       يتركها معلّقة في مكان قديم ما لم تُعاد. */
    window.addEventListener('resize', () => { if (!panel.hidden) place(); });
    window.addEventListener('scroll', () => { if (!panel.hidden) place(); }, { passive: true });
 
    wrap.appendChild(button);
    document.body.appendChild(panel);
 
    /**
     * تبديل القائمة بعد وصول الشبكة — بلا إعادة بناء الزرّ ولا اللوحة.
     *
     * إعادة البناء تعني إزالة عقدة وإدراج أخرى مكانها، فيومض الشريط
     * وتُفقد اللوحة إن كانت مفتوحة. والتبديل هنا يمسّ المحتوى والنقطة
     * وحدهما.
     */
    wrap.setItems = function (nextItems) {
      items = nextItems;
 
      const nowSeen = readSeen();
      const nowUnread = unreadCount(items, nowSeen);
 
      renderList(list, items, nowSeen);
 
      badge.textContent = nowUnread > 9 ? '+9' : String(nowUnread);
      badge.hidden = nowUnread === 0;
 
      if (!panel.hidden) place();
    };
 
    return wrap;
  }
 
  /** الجرس المعروض حاليًا — واحد لا أكثر في الصفحة. */
  let bell = null;
 
  function show(host, items) {
    if (bell) {
      bell.setItems(items);
      return;
    }
 
    bell = buildBell(items);
    host.insertBefore(bell, host.firstChild);
 
    if (window.PTCIcons && typeof window.PTCIcons.refresh === 'function') {
      window.PTCIcons.refresh();
    }
  }
 
  function hide() {
    if (!bell) return;
 
    /* اللوحة مُلحَقة بـ body لا بالجرس، فإزالة الجرس وحده تتركها يتيمة. */
    const panel = document.querySelector('.ptc-bell-panel');
    if (panel) panel.remove();
 
    bell.remove();
    bell = null;
  }
 
  async function fetchItems() {
    try {
      /*
       * الرمز يُرسَل — ولا يجوز غير ذلك منذ صار الإعلان موجَّهًا.
       *
       * المسار عام ويقبل الطلب بلا رمز، لكنه حينها يردّ بحصّة الضيف:
       * الإعلانات العامة وحدها. فطلبٌ بـ auth:false يجعل الطالب يرى
       * إعلانات سنته ومساقاته **غائبة تمامًا**، والجرس يبدو سليمًا وهو
       * يكذب. (وقع هذا فعلًا قبل الاستهداف ولم يُكشف إلا بالمقارنة مع
       * ردّ الـAPI مباشرة.)
       */
      const response = await PTCApi.get('/announcements');
      return (response && response.data) || [];
    } catch (_) {
      /*
       * null لا [] — والفرق جوهري: [] تعني «لا إعلانات» فتُخفي الجرس،
       * و null تعني «لم أعرف» فيبقى المرسوم من الذاكرة كما هو. شبكةٌ
       * متقطّعة يجب ألّا تمحو ما يراه الطالب.
       */
      return null;
    }
  }
 
  async function mount() {
    /*
     * هذا الجرس المستقل صار مكرَّرًا تمامًا: الجرس الموحّد (tip-bell في
     * app.js) يعرض الإعلانات الآن ضمن نفس قائمة الإشعارات لكل الأدوار،
     * فوجود الاثنين معًا كان يُظهر نفس الإعلان مرتين بجرسين منفصلين.
     * الكود هنا يبقى كما هو تحسّبًا، لكن mount توقف عن الرسم فعليًا.
     */
    return;
  }
 
  async function mountLegacyDisabled() {
    if (SKIP_PAGES.has(currentPage())) return;
    if (typeof PTCApi === 'undefined') return;
 
    const host = document.querySelector('.nav-actions');
    if (!host || host.querySelector('.ptc-bell')) return;
 
    /*
     * الرسم من الذاكرة **قبل** أي انتظار — وهذا هو حلّ التأخير: الجرس
     * كان لا يُبنى إلا بعد ردّ الشبكة، فيتأخّر عن بقية الشريط ويزحزح
     * ما بجانبه حين يظهر. الآن يظهر في الإطار الأول من الزيارة الثانية
     * فصاعدًا، والشبكة تصحّحه بعدها بلا وميض.
     */
    const cached = readCache();
    if (cached && cached.length) show(host, cached);
 
    const items = await fetchItems();
    if (items === null) return;
 
    writeCache(items);
 
    /* جرسٌ لا شيء خلفه ضجيج دائم — يظهر حين يوجد ما يُعرَض. */
    if (items.length) show(host, items);
    else hide();
  }
 
  /*
   * السكربت في آخر body، فقد يكون DOMContentLoaded قد مضى قبل تسجيل
   * المستمع — وحينها لا يُنادى mount أبدًا ولا يظهر جرس إطلاقًا.
   */
  if (document.readyState === 'loading') {
    window.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
 
  return { unreadCount, relativeDay, topId, fingerprint };
})();
 
