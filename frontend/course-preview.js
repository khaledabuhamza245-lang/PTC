/* ────────────────────────────────────────────────────────────────────
   مُطبِّع روابط المعاينة.

   القيد الحاكم: فشل التضمين غير قابل للكشف برمجيًا. المتصفح يطلق load
   على الإطار حتى حين يرفض المصدر البعيد العرض بـ X-Frame-Options، ولا
   يطلق error إطلاقًا، وقراءة contentDocument ترمي SecurityError في
   حالتَي النجاح والفشل معًا. فكل تصميم من نوع «جرّب ثم عالج الفشل»
   ساقط تقنيًا.

   لذلك القرار يُتخذ قبل المحاولة: قائمة بيضاء للمضيفين المعروف أنهم
   يسمحون بالتأطير، وما عداهم لا يُرسم له زر معاينة أصلًا.

   وأمر ثانٍ: الروابط العادية لا تُؤطَّر حتى من هؤلاء المضيفين. صفحة
   /view في درايف و /watch في يوتيوب و /edit في Docs كلها ترفض
   التأطير — فالتحويل شرط عمل لا تجميل.

   الدالة نقية: لا DOM ولا شبكة، فتُختبر بـ node مباشرة.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  /* معرّف فيديو يوتيوب ١١ محرفًا بالضبط، ومعرّفات جوجل أطول وبنفس
     المحارف الآمنة. الحصر بالطول والمحارف يمنع تسرّب أي شيء غريب
     إلى قالب الرابط الذي نبنيه. */
  const YT_VIDEO = /^[A-Za-z0-9_-]{11}$/;
  const YT_LIST = /^[A-Za-z0-9_-]{12,}$/;
  const G_ID = /^[A-Za-z0-9_-]{8,}$/;

  const DOC_TYPES = ['document', 'spreadsheets', 'presentation'];

  /* أسماء مضيفين نعرفها لنقول للطالب إلى أين يذهب الرابط قبل أن يضغط. */
  const HOST_NAMES = {
    'github.com': 'GitHub',
    'gist.github.com': 'GitHub Gist',
    'drive.google.com': 'Google Drive',
    'docs.google.com': 'Google Docs',
    'dropbox.com': 'Dropbox',
    'onedrive.live.com': 'OneDrive',
    'mega.nz': 'MEGA',
    'mediafire.com': 'MediaFire',
    't.me': 'تيليجرام',
  };

  /* مطابقة نطاق دقيقة: إما المضيف نفسه أو نطاق فرعي حقيقي تحته.
     endsWith على النطاق وحده تقبل evil-youtube.com، والنقطة تمنعها. */
  function hostIs(host, base) {
    return host === base || host.endsWith('.' + base);
  }

  function segments(pathname) {
    return pathname.split('/').filter(Boolean);
  }

  function friendlyName(host) {
    const bare = host.replace(/^www\./, '');
    if (HOST_NAMES[bare]) return HOST_NAMES[bare];

    const known = Object.keys(HOST_NAMES).find(base => hostIs(bare, base));
    return known ? HOST_NAMES[known] : bare;
  }

  /* يوتيوب: watch?v= · youtu.be/ · /shorts/ · /live/ · /embed/ · /v/
     والوجهة nocookie لأنها لا تزرع كوكيز تتبّع قبل التشغيل. */
  function youtube(u) {
    const host = u.hostname.replace(/^www\./, '');
    const seg = segments(u.pathname);

    let id = null;

    if (hostIs(host, 'youtu.be')) {
      id = seg[0] || null;
    } else if (seg[0] === 'watch') {
      id = u.searchParams.get('v');
    } else if (['shorts', 'live', 'embed', 'v'].includes(seg[0])) {
      id = seg[1] || null;
    } else if (seg[0] === 'playlist') {
      const list = u.searchParams.get('list');
      return list && YT_LIST.test(list)
        ? 'https://www.youtube-nocookie.com/embed/videoseries?list=' + list
        : null;
    }

    if (!id || !YT_VIDEO.test(id)) return null;

    /* قائمة تشغيل مرفقة بفيديو: نبقيها فيتصفّح الطالب المحاضرات تباعًا. */
    const list = u.searchParams.get('list');

    return 'https://www.youtube-nocookie.com/embed/' + id
      + (list && YT_LIST.test(list) ? '?list=' + list : '');
  }

  /* درايف: ملف /file/d/{ID}/view ← /preview · مجلد ← embeddedfolderview */
  function drive(u) {
    const seg = segments(u.pathname);

    if (seg[0] === 'file' && seg[1] === 'd' && G_ID.test(seg[2] || '')) {
      return 'https://drive.google.com/file/d/' + seg[2] + '/preview';
    }

    /* المجلد قد يأتي بـ /drive/folders/ID أو /drive/u/0/folders/ID */
    const folderAt = seg.indexOf('folders');
    if (folderAt > -1 && G_ID.test(seg[folderAt + 1] || '')) {
      return 'https://drive.google.com/embeddedfolderview?id=' + seg[folderAt + 1] + '#grid';
    }

    /* الصيغتان القديمتان: open?id= و uc?id= */
    if (seg[0] === 'open' || seg[0] === 'uc') {
      const id = u.searchParams.get('id');
      if (id && G_ID.test(id)) {
        return 'https://drive.google.com/file/d/' + id + '/preview';
      }
    }

    return null;
  }

  /* مستندات: /{type}/d/{ID}/edit ← /preview
     صيغة النشر /{type}/d/e/{ID} تسقط هنا عمدًا: الجزء بعد d يكون "e"
     فيرسب في فحص الطول، والافتراض الآمن هو «لا يُعاين». */
  function docs(u) {
    const seg = segments(u.pathname);

    if (!DOC_TYPES.includes(seg[0]) || seg[1] !== 'd') return null;
    if (!G_ID.test(seg[2] || '')) return null;

    return 'https://docs.google.com/' + seg[0] + '/d/' + seg[2] + '/preview';
  }

  /**
   * @param {{kind?: string, url?: string}} input
   * @returns {{embeddable: boolean, src: string|null,
   *            provider: string|null, label: string}}
   */
  function resolve(input) {
    const url = String((input && input.url) || '').trim();
    const deny = (label) => ({ embeddable: false, src: null, provider: null, label: label });

    if (!url) return deny('لا يوجد رابط');

    let u;
    try {
      u = new URL(url);
    } catch (e) {
      return deny('رابط غير صالح');
    }

    /* http داخل صفحة https يُحجب بصمت، فلا يُعرض له زر معاينة أبدًا.
       الباك اند يمنعه عند الإدخال، وهذا حارس الصفوف القديمة. */
    if (u.protocol !== 'https:') {
      return deny(friendlyName(u.hostname));
    }

    const host = u.hostname.replace(/^www\./, '');

    if (hostIs(host, 'youtube.com') || hostIs(host, 'youtu.be') || hostIs(host, 'youtube-nocookie.com')) {
      const src = youtube(u);
      return src
        ? { embeddable: true, src: src, provider: 'youtube', label: 'YouTube' }
        : deny('YouTube');
    }

    if (hostIs(host, 'drive.google.com')) {
      const src = drive(u);
      return src
        ? { embeddable: true, src: src, provider: 'drive', label: 'Google Drive' }
        : deny('Google Drive');
    }

    if (hostIs(host, 'docs.google.com')) {
      const src = docs(u);
      return src
        ? { embeddable: true, src: src, provider: 'docs', label: 'Google Docs' }
        : deny('Google Docs');
    }

    /* خارج القائمة البيضاء: لا نجرّب ولا نخمّن، ونكتفي بتسمية الوجهة. */
    return deny(friendlyName(u.hostname));
  }

  const api = { resolve: resolve };

  if (typeof window !== 'undefined') window.PTCCoursePreview = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
