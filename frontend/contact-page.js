/* ────────────────────────────────────────────────────────────────────
   صفحة «تواصل معنا».

   الصفحة عامة لا تشترط تسجيل الدخول: أكثر حالة يُحتاج فيها التواصل هي
   حالة من لا يستطيع الدخول أصلًا — تسجيل متعثّر، أو رسالة إعادة تعيين لا
   تصل. فاشتراط الدخول يقفل الباب في وجه من يطرقه.

   والرسالة تُرسَل من الباك ببريد الموقع لا ببريد الطالب — Brevo لا تقبل
   مُرسِلًا غير متحقَّق — وبريدُ الطالب يوضع في Reply-To، فالردّ من صندوق
   الموقع يصله مباشرة.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  const $ = id => document.getElementById(id);

  const MAX = 2000;

  let sending = false;

  /* ─────────────────────────── الرسائل ─────────────────────────── */

  function notice(text, extraNode) {
    const box = $('ctMsg');
    if (!box) return;

    if (!text) {
      box.classList.remove('show');
      box.textContent = '';
      return;
    }

    // textContent لا innerHTML: نصّ الخطأ يأتي من الخادم، وإدراجه وسمًا
    // يجعل رسالة تحقّق تحمل اسم حقل مطبوعًا كما أرسله المستخدم قناةَ حقن.
    box.textContent = text;
    box.className = 'ct-msg show err';

    if (extraNode) box.appendChild(extraNode);
  }

  /*
   * بديل التيليجرام يُجلب عند الفشل وحده لا عند فتح الصفحة: طلبٌ لا يخدم
   * إلا حالة نادرة لا يُدفع ثمنه في كل زيارة. والمعرّف من إعدادات الموقع
   * لا مكتوبًا هنا، فتغييره من لوحة التحكم يكفي.
   */
  async function telegramFallback() {
    try {
      const program = await PTCAuth.getProgram();
      const username = (program && program.telegram_username) || '';
      if (!username) return null;

      const line = document.createElement('div');
      line.style.marginTop = '8px';

      const link = document.createElement('a');
      link.href = 'https://t.me/' + encodeURIComponent(username);
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'راسلنا على تيليجرام';

      line.append('أو ', link, ' حتى نصلح المشكلة.');
      return line;
    } catch (_) {
      return null;
    }
  }

  /* ─────────────────────────── التعبئة ─────────────────────────── */

  /*
   * الحقول تُملأ ولا تُقفل: الطالب قد يسأل عن حساب غير حسابه، أو يفضّل
   * ردًّا على بريد آخر. والتعبئة تتخطّى أي حقل بدأ المستخدم كتابته —
   * init قد تصل بعد أن يكون قد بدأ.
   */
  function prefill() {
    const profile = PTCAuth.profile;
    if (!profile) return;

    const name = $('ctName');
    const email = $('ctEmail');

    if (name && !name.value) name.value = profile.full_name || '';
    if (email && !email.value) email.value = profile.email || '';
  }

  /* ─────────────────────────── العدّاد ─────────────────────────── */

  function updateCount() {
    const body = $('ctBody');
    const counter = $('ctCount');
    if (!body || !counter) return;

    const length = body.value.length;
    counter.textContent = length + ' / ' + MAX;
    counter.classList.toggle('over', length > MAX);
  }

  /* ─────────────────────────── الإرسال ─────────────────────────── */

  function showForm() {
    $('ctForm').style.display = 'block';
    $('ctDone').style.display = 'none';
    notice('');
  }

  function showDone() {
    $('ctForm').style.display = 'none';
    $('ctDone').style.display = 'block';
    notice('');

    // التركيز ينتقل للوحة: مستخدم قارئ الشاشة لا يرى أن النموذج اختفى
    // وحلّ محلّه تأكيد ما لم يُنقل إليه.
    $('ctDone').setAttribute('tabindex', '-1');
    $('ctDone').focus();
  }

  /* التحقق هنا قبل الشبكة: رسالة عربية فورية أوضح من رحلة ذهاب وإياب
     تعود بـ422، والخادم يعيد التحقق على أي حال. */
  function localError() {
    const name = $('ctName').value.trim();
    const email = $('ctEmail').value.trim();
    const subject = $('ctSubject').value.trim();
    const message = $('ctBody').value.trim();

    if (name.length < 2) return 'اكتب اسمك.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'اكتب بريدًا إلكترونيًّا صحيحًا.';
    if (subject.length < 3) return 'اكتب موضوع الرسالة.';
    if (message.length < 10) return 'اكتب رسالتك — عشرة أحرف على الأقل.';
    if (message.length > MAX) return 'الرسالة أطول من ' + MAX + ' حرف. اختصرها قليلًا.';

    return '';
  }

  async function submit(event) {
    event.preventDefault();
    if (sending) return;

    const problem = localError();
    if (problem) {
      notice(problem);
      return;
    }

    const button = $('ctBtn');
    sending = true;
    button.disabled = true;
    button.textContent = 'جارٍ الإرسال…';
    notice('');

    try {
      await PTCAuth.sendContactMessage({
        name: $('ctName').value.trim(),
        email: $('ctEmail').value.trim(),
        subject: $('ctSubject').value.trim(),
        message: $('ctBody').value.trim(),
        website: $('ctWebsite').value
      });

      $('ctForm').reset();
      updateCount();
      showDone();
    } catch (error) {
      const status = error && error.status;

      if (status === 429) {
        notice('أرسلتَ رسائل كثيرة في وقت قصير. انتظر قليلًا ثم حاول.');
      } else if (status === 502 || status === 500) {
        notice(
          (error && error.message) || 'تعذّر إرسال رسالتك الآن. حاول بعد قليل.',
          await telegramFallback()
        );
      } else {
        notice((error && error.message) || 'تعذّر إرسال رسالتك. حاول مرة أخرى.');
      }
    } finally {
      sending = false;
      button.disabled = false;
      button.textContent = 'إرسال';
    }
  }

  /* ─────────────────────────── التهيئة ─────────────────────────── */

  function init() {
    const form = $('ctForm');
    if (!form) return;

    form.addEventListener('submit', submit);
    $('ctBody').addEventListener('input', updateCount);

    $('ctAgain').addEventListener('click', () => {
      showForm();
      prefill();
      $('ctName').focus();
    });

    updateCount();

    /*
     * التعبئة على ثلاث فرص لأن الملف الشخصي قد يصل بعد أول رسم: فورًا إن
     * كانت الجلسة مقروءة من التخزين المحلي، وعند استقرار init، وعند أي
     * تغيّر لاحق في الجلسة — auth.js يتحقق من صلاحية الرمز في وقت خامل
     * بعد أن يعرض الصفحة، فيصل الملف محدَّثًا بعد init.
     *
     * والفحص بـtypeof لا بـwindow.PTCAuth: الاسم معرَّف بـconst في أعلى
     * auth.js، وconst في نطاق السكربت **لا تُعلَّق على window** — فشرطٌ
     * على window.PTCAuth يسقط صامتًا وإن كان الكائن حاضرًا.
     */
    if (typeof PTCAuth === 'undefined') return;

    prefill();
    window.addEventListener('ptc-auth-change', prefill);

    if (PTCAuth.enabled()) {
      PTCAuth.init().then(prefill).catch(() => {});
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
