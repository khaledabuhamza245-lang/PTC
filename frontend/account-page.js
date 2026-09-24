/* إعدادات الحساب: تغيير كلمة السر.
 *
 * صفحة مستقلة لا بطاقة داخل profile.html: النموذج مفتوحًا دائمًا وسط
 * الخطة الدراسية كان يعرض ثلاثة حقول فارغة لا يمسّها الطالب في تسع
 * زيارات من عشر، ويقطع صفحةً موضوعها التقدّم نحو التخرّج.
 */
(function () {
  const $ = id => document.getElementById(id);

  function hint(message, kind) {
    const node = $('pfPassHint');
    node.textContent = message;
    node.className = 'pf-hint' + (kind ? ' pf-hint-' + kind : '');
  }

  async function changePassword(event) {
    event.preventDefault();

    const current = $('pfPassCurrent').value;
    const next = $('pfPassNew').value;
    const confirm = $('pfPassConfirm').value;

    if (next !== confirm) {
      hint('كلمتا السر الجديدتان غير متطابقتين.', 'bad');
      return;
    }

    const button = $('pfPassBtn');
    button.disabled = true;
    hint('جارٍ التغيير...');

    try {
      const response = await PTCAuth.changePassword({
        currentPassword: current,
        password: next,
        passwordConfirmation: confirm,
      });

      $('pfPassForm').reset();

      /*
       * عدد الجلسات المُلغاة يُذكر لأن الأثر يقع خارج هذه الشاشة: هاتفُ
       * الطالب سيطلب دخولًا جديدًا ولا شيء هنا يُنبئه بذلك.
       */
      const revoked = Number(response && response.revoked_sessions) || 0;
      hint(
        revoked
          ? `تم تغيير كلمة السر، وأُنهيت ${revoked} من جلساتك على أجهزة أخرى.`
          : 'تم تغيير كلمة السر.',
        'ok',
      );
    } catch (error) {
      const text = (error && error.message) || '';
      hint(
        text.includes('الحالية') || text.includes('current')
          ? 'كلمة السر الحالية غير صحيحة.'
          : (text || 'تعذّر تغيير كلمة السر، حاول مرة أخرى.'),
        'bad',
      );
    } finally {
      button.disabled = false;
    }
  }

  async function boot() {
    if (typeof PTCAuth === 'undefined') return;
    if (!await PTCAuth.requireAuth('login.html')) return;

    /*
     * البريد يُعرض لأن الطالب قد يملك حسابين — واحدًا بالجامعي وآخر
     * بالشخصي. أن يعرف أيّهما يغيّر قبل أن يغيّر.
     */
    const user = PTCAuth.user;
    if (user && user.email) {
      $('acMeta').textContent = 'كلمة سرّ الحساب: ' + user.email;
      $('acMeta').setAttribute('dir', 'auto');
    }

    $('pfPassForm').onsubmit = changePassword;
  }

  window.addEventListener('DOMContentLoaded', boot);
})();
