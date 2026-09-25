/* بطاقة ربط تيليجرام بصفحة إعدادات الحساب — مرحلة أولى تجريبية.
 *
 * ملف مستقل بالكامل عن account-page.js (يخص كلمة السر فقط) — هيك أي
 * تعديل أو مشكلة بأحدهما ما إلها أي علاقة بالتاني، ونقدر نشيل هالملف
 * بالكامل بضغطة وحدة لو قررنا التراجع عن الميزة بمرحلتها التجريبية.
 */
(function () {
  const $ = id => document.getElementById(id);

  function hint(message, kind) {
    const node = $('tgLinkHint');
    if (!node) return;
    node.textContent = message || '';
    node.className = 'pf-hint' + (kind ? ' pf-hint-' + kind : '');
  }

  function renderLinked(firstName) {
    const body = $('tgLinkBody');
    const safeName = firstName
      ? ` باسم "${firstName}"`
      : '';

    body.innerHTML = '';

    const status = document.createElement('p');
    status.className = 'ac-intro';
    status.textContent = `حسابك متصل حاليًا بالبوت${safeName} ✅`;
    body.appendChild(status);

    const unlinkBtn = document.createElement('button');
    unlinkBtn.type = 'button';
    unlinkBtn.className = 'btn';
    unlinkBtn.textContent = 'فصل الحساب عن تيليجرام';
    unlinkBtn.onclick = unlink;
    body.appendChild(unlinkBtn);
  }

  function renderUnlinked() {
    const body = $('tgLinkBody');
    body.innerHTML = '';

    const linkBtn = document.createElement('button');
    linkBtn.type = 'button';
    linkBtn.className = 'btn btn-primary';
    linkBtn.id = 'tgLinkBtn';
    linkBtn.textContent = 'اربط حسابي بتيليجرام';
    linkBtn.onclick = requestLink;
    body.appendChild(linkBtn);
  }

  async function requestLink() {
    const button = $('tgLinkBtn');
    if (button) button.disabled = true;
    hint('جارٍ تجهيز الرابط...');

    try {
      const response = await PTCApi.post('/me/telegram-link');
      const url = response && response.data && response.data.url;

      if (!url) throw new Error('تعذّر إنشاء رابط الربط.');

      window.open(url, '_blank', 'noopener');
      hint('افتح تيليجرام واضغط "Start" داخل المحادثة — الرابط صالح ١٠ دقائق فقط.', 'ok');
    } catch (error) {
      const text = (error && error.message) || 'تعذّر إنشاء رابط الربط، حاول مرة أخرى.';
      hint(text, 'bad');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function unlink() {
    hint('جارٍ الفصل...');

    try {
      await PTCApi.delete('/me/telegram-link');
      renderUnlinked();
      hint('تم فصل حسابك عن تيليجرام.', 'ok');
    } catch (error) {
      hint((error && error.message) || 'تعذّر فصل الحساب، حاول مرة أخرى.', 'bad');
    }
  }

  async function boot() {
    if (typeof PTCApi === 'undefined' || !$('tgLinkCard')) return;

    try {
      const response = await PTCApi.get('/me/telegram-link');
      const data = (response && response.data) || {};

      if (data.linked) {
        renderLinked(data.telegram_first_name);
      } else {
        renderUnlinked();
      }
    } catch (error) {
      // فشل جلب الحالة (مثلًا الميزة لسا غير مفعّلة على الخادم) —
      // نترك زر الربط الافتراضي بالـHTML ظاهرًا بلا أي رسالة خطأ
      // مزعجة، لأن هذا متوقّع طول فترة التجربة قبل التفعيل الكامل.
    }
  }

  window.addEventListener('DOMContentLoaded', boot);
})();
