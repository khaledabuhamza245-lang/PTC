/* ────────────────────────────────────────────────────────────────────
   دليل الأدوات في صفحة الأدوات.

   هنا التصنيف بالنوع لا في صفحة المساق: المساق يعرض أدواته القليلة
   فتكفيه قائمة، وهنا تجتمع المئة فلا يُهتدى فيها بلا تصنيف. وهذا هو
   العرض «حسب نوع الأداة بدل حسب المساق» — الأداة تُكتب مرة وتحتها
   المساقات التي تخدمها، بدل تكرارها في كل واحد منها.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  const out = document.getElementById('dirOut');

  if (!out) return;

  const msg = document.getElementById('dirMsg');
  const search = document.getElementById('dirSearch');
  const filters = document.getElementById('dirFilters');

  /*
   * الفحص بـtypeof لا بـwindow.PTCUtils: الاسم معرَّف بـconst في أعلى
   * app.js، وconst في نطاق السكربت لا تُعلَّق على window — فالشرط كان
   * يسقط دائمًا، والفرع البديل كان String() بلا تهريب إطلاقًا. أي أن
   * esc() هنا كانت دالّة هوية، واسمها وحده هو ما أمرّها من xss-audit.
   *
   * والبديل يهرّب فعلًا الآن: لو غاب المُنقّي المشترك يبقى المخرج آمنًا
   * بدل أن ينهار الحقن صامتًا.
   */
  const esc = value =>
    (typeof PTCUtils !== 'undefined' && PTCUtils.escapeHTML)
      ? PTCUtils.escapeHTML(value)
      : String(value == null ? '' : value).replace(/[&<>'"]/g, ch => ({
          '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        })[ch]);

  /* الترتيب مقصود: البرامج أولًا لأنها ما يُثبَّت قبل بدء المساق. */
  const KINDS = [
    {
      type: 'software',
      icon: 'download',
      action: 'تحميل',
      title: 'برامج للتحميل',
      note: 'تُثبَّت على الجهاز'
    },
    {
      type: 'online',
      icon: 'external',
      action: 'فتح مباشرة',
      title: 'أدوات ويب',
      note: 'تعمل في المتصفح بلا تثبيت'
    },
    {
      type: 'concept',
      icon: 'bookOpen',
      action: 'شرح مختصر',
      title: 'مفاهيم وطرق',
      note: 'ليست برامج — تُفهم وتُطبَّق بالورقة والقلم'
    },
    {
      type: 'library',
      icon: 'book',
      action: 'التوثيق',
      title: 'مكتبات وأُطر',
      note: 'تُستدعى من داخل الشيفرة'
    }
  ];

  const KIND_BY_TYPE = KINDS.reduce((map, kind) => {
    map[kind.type] = kind;

    return map;
  }, {});

  let all = [];
  let activeType = '';
  let term = '';

  /* نصوص الشرح خارج الـ DOM: فقرة كاملة في data-* تعني هروبًا مزدوجًا. */
  const explanations = new Map();

  function matches(tool) {
    if (activeType && tool.type !== activeType) return false;

    if (!term) return true;

    const haystack = [
      tool.name,
      tool.description,
      (tool.courses || [])
        .map(course => course.code + ' ' + course.name_ar)
        .join(' ')
    ].join(' ').toLowerCase();

    return haystack.indexOf(term) !== -1;
  }

  /*
   * الاسم لا الرمز: «EEE4 3469» لا يقول للطالب شيئًا، و«شبكات الحاسوب»
   * يقول كل شيء. والرمز يبقى في التلميح لمن يبحث به.
   */
  function courseChips(tool) {
    const list = tool.courses || [];

    if (!list.length) return '';

    const safeChips = list
      .map(course => {
        const safeKey = PTCUtils.safeRef(course.key);

        const label = esc(course.name_ar || course.code || '');

        const safeHint = esc(course.code || '');

        if (!safeKey) {
          return `<span class="dir-course" title="${safeHint}">${label}</span>`;
        }

        return `
          <a class="dir-course" href="course.html?course=${safeKey}"
             title="${safeHint}">${label}</a>
        `;
      })
      .join('');

    return `
      <div class="dir-courses">
        <span class="dir-courses-lead">تُستعمل في:</span>
        ${safeChips}
      </div>
    `;
  }

  function actionButton(tool, kind) {
    if (tool.type === 'concept') {
      if (!tool.explanation) return '';

      explanations.set(String(tool.id), tool.explanation);

      return `
        <button type="button" class="dir-go" data-dir-concept="${esc(tool.id)}">
          <i data-icon="${esc(kind.icon)}"></i> ${esc(kind.action)}
        </button>
      `;
    }

    const href = PTCUtils.safeUrl(tool.official_url);

    if (!href) return '';

    return `
      <a class="dir-go" href="${esc(href)}" target="_blank" rel="noopener noreferrer">
        <i data-icon="${esc(kind.icon)}"></i> ${esc(kind.action)}
      </a>
    `;
  }

  function itemMarkup(tool) {
    const kind = KIND_BY_TYPE[tool.type] || KIND_BY_TYPE.online;

    const video = PTCUtils.safeUrl(tool.video_url);

    return `
      <article class="dir-item" id="tool-${esc(tool.id)}">
        <div class="dir-item-top">
          <b>${esc(tool.name)}</b>

          <span class="dir-acts">
            ${actionButton(tool, kind)}

            ${
              video
                ? `<a class="dir-video" href="${esc(video)}"
                       target="_blank" rel="noopener noreferrer">
                     <i data-icon="play"></i> شرح بالعربي
                   </a>`
                : ''
            }
          </span>
        </div>

        <p>${esc(tool.description)}</p>

        ${courseChips(tool)}
      </article>
    `;
  }

  function render() {
    const visible = all.filter(matches);

    if (!visible.length) {
      out.innerHTML =
        '<div class="dir-empty">لا أداة تطابق البحث.</div>';

      setMessage(`0 من ${all.length}`);

      return;
    }

    /* المجموعة الفارغة تُحذف كي لا يرى الطالب عنوانًا بلا شيء تحته. */
    out.innerHTML = KINDS
      .map(kind => {
        const group = visible.filter(tool => tool.type === kind.type);

        if (!group.length) return '';

        return `
          <section class="dir-group">
            <h4>
              <i data-icon="${esc(kind.icon)}"></i>
              ${esc(kind.title)}
              <em>${Number(group.length)}</em>
            </h4>

            <p class="dir-group-note">${esc(kind.note)}</p>

            <div class="dir-items">
              ${group.map(itemMarkup).join('')}
            </div>
          </section>
        `;
      })
      .join('');

    /* الأيقونات يرسمها المراقب في icons.js عند إضافة العقد — لا نداء هنا. */
    setMessage(`${visible.length} من ${all.length}`);

    highlightToolFromUrl();
  }

  /*
   * وصول من نتيجة بحث موحّد: ?tool=<id> بالرابط — يفتح تبويب الدليل
   * تلقائيًا (إن لم يكن مفتوحًا أصلًا) ثم ينزلق للأداة ويظلّلها، بنفس
   * فكرة تظليل عنصر محتوى داخل صفحة المساق تمامًا.
   */
  let toolHighlighted = false;

  function highlightToolFromUrl() {
    if (toolHighlighted) return;

    let toolId = '';

    try {
      toolId = new URL(location.href)
        .searchParams.get('tool') || '';
    } catch (_) {
      return;
    }

    if (!toolId) return;

    const target = document.getElementById(`tool-${toolId}`);

    if (!target) return;

    toolHighlighted = true;

    target.classList.add('dir-item-highlight');

    target.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    });
  }

  function setMessage(text, kind) {
    if (!msg) return;

    msg.textContent = text || '';
    msg.className = 'nb-msg' + (text ? ' ' + (kind || 'ok') : '');
  }

  out.addEventListener('click', event => {
    const button = event.target.closest('[data-dir-concept]');

    if (!button) return;

    event.preventDefault();

    SiteDialog.message({
      title:
        button.closest('.dir-item')?.querySelector('b')?.textContent.trim()
        || 'شرح مختصر',

      message: explanations.get(button.dataset.dirConcept) || ''
    });
  });

  if (search) {
    search.addEventListener('input', () => {
      term = search.value.trim().toLowerCase();
      render();
    });
  }

  if (filters) {
    filters.addEventListener('click', event => {
      const button = event.target.closest('[data-dir-type]');

      if (!button) return;

      filters.querySelectorAll('.dir-f')
        .forEach(item => item.classList.remove('active'));

      button.classList.add('active');
      activeType = button.dataset.dirType || '';
      render();
    });
  }

  /*
   * التحميل عند أول فتح للتبويب لا عند تحميل الصفحة: أكثر من يفتح
   * صفحة الأدوات يريد حاسبة بعينها، فطلب الدليل له ثمن بلا فائدة.
   */
  let loaded = false;

  async function load() {
    if (loaded) return;

    loaded = true;
    setMessage('جارٍ تحميل الدليل...');

    try {
      const response = await PTCApi.get('/tools');

      all = response.data || [];
      render();

    } catch (error) {
      loaded = false;

      out.innerHTML = '';

      setMessage(
        'تعذّر تحميل الدليل: ' + (error.message || 'حاول مرة أخرى.'),
        'err'
      );
    }
  }

  /*
   * فتح مباشر بـ tools.html#directory يمرّ من هنا أيضًا: معالج التبويبات
   * في tools-page.js يضغط الزر برمجيًا عند قراءة الهاش، فيصله الحدث.
   */
  document.querySelectorAll('[data-tool-tab="directory"]')
    .forEach(tab => tab.addEventListener('click', load));

  /* التبويب الافتراضي عند فتح الصفحة هو «دليل الأدوات»، فإن لم يكن
     هناك هاش آخر بالرابط، يجب تحميل القائمة فورًا لا انتظار نقرة لن تأتي. */
  const initialTab = (location.hash || '').replace('#', '').trim();

  if (!initialTab || initialTab === 'directory') {
    load();
  }
}());
