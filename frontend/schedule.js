/*
 * جدولي الأسبوعي — محفوظ بقاعدة البيانات الآن (بعد أن كان محليًا
 * بالمتصفح فقط)، فيرافق الطالب على أي جهاز يدخل منه، ويُحفظ فورًا مع
 * أي تعديل. كل طلب مربوط بحساب الطالب نفسه من الخادم مباشرة.
 */
window.Schedule = (function () {
  /* السبت أولًا كما طُلب صراحة، ثم الأحد إلى الخميس. */
  const DAYS = [
    { key: 6, label: 'السبت', short: 'سبت' },
    { key: 0, label: 'الأحد', short: 'أحد' },
    { key: 1, label: 'الاثنين', short: 'اثنين' },
    { key: 2, label: 'الثلاثاء', short: 'ثلاثاء' },
    { key: 3, label: 'الأربعاء', short: 'أربعاء' },
    { key: 4, label: 'الخميس', short: 'خميس' },
  ];

  const HOUR_START = 8;
  const HOUR_END = 19;
  /*
   * كبّرنا الارتفاع من 52 إلى 68px عشان يصير في مساحة كافية لخط
   * منتصف الساعة (النصف) + رقم النصف الصغير بعمود الأوقات — بدونه ما
   * كان فيه أي مرجع بصري يوضّح وين بالضبط تقع محاضرة تنتهي الساعة
   * ونص، متل 9:00–10:30، داخل صف الساعة. يطابق .sch-cell{height:68px}
   * بالـCSS تمامًا — أي تغيير هون لازم ينعكس هناك وبالعكس.
   */
  const ROW_HEIGHT = 68;

  const COLORS = [
    '#8a9a6f', '#c9986a', '#7ba0b0', '#b98a9a',
    '#a68fc4', '#c4a05a', '#6fa88a', '#9a9a9a',
  ];

  let lectures = [];
  let editingId = null;
  let selectedType = 'in_person';
  let selectedDays = new Set();
  let selectedColor = COLORS[0];
  let myCourses = [];

  /* ── تطويع الشكل بين الخادم (snake_case) والواجهة (camelCase) ──────
     يبقي كل منطق العرض والحساب أدناه كما هو دون إعادة كتابة، فالتحويل
     يحدث بنقطتين فقط: عند الجلب وعند الإرسال. */
  function fromApi(record) {
    return {
      id: record.id,
      courseKey: record.course_key || '',
      name: record.name,
      instructor: record.instructor || '',
      type: record.type,
      days: record.days || [],
      start: String(record.start_time || '').slice(0, 5),
      end: String(record.end_time || '').slice(0, 5),
      color: record.color || COLORS[0],
    };
  }

  function toApiPayload(local) {
    return {
      course_key: local.courseKey || null,
      name: local.name,
      instructor: local.instructor || null,
      type: local.type,
      days: local.days,
      start_time: local.start,
      end_time: local.end,
      color: local.color,
    };
  }

  /* ── تحميل من الخادم ────────────────────────────────────────────── */
  async function load() {
    try {
      const rows = await PTCAuth.getSchedule();
      lectures = rows.map(fromApi);
    } catch (_) {
      lectures = [];
    }
  }

  function escapeHTML(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  function timeToMinutes(time) {
    const [h, m] = String(time || '0:0').split(':').map(Number);
    return h * 60 + m;
  }

  function minutesToLabel(mins) {
    const h = Math.floor(mins / 60);
    const period = h >= 12 ? 'م' : 'ص';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(mins % 60).padStart(2, '0')} ${period}`;
  }

  /* نسخة مختصرة بلا ص/م لتسمية النصف بعمود الأوقات — أصغر وأخف، والساعة
     الكاملة فوقها أصلًا كافية لمعرفة الفترة (صباحًا/مساءً). */
  function minutesToShortLabel(mins) {
    const h = Math.floor(mins / 60);
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(mins % 60).padStart(2, '0')}`;
  }

  /* ── تعارض الأوقات ──────────────────────────────────────────────── */
  function findConflicts(day, start, end, excludeId) {
    const s = timeToMinutes(start);
    const e = timeToMinutes(end);

    return lectures.filter(lecture => {
      if (lecture.id === excludeId) return false;
      if (!lecture.days.includes(day)) return false;

      const ls = timeToMinutes(lecture.start);
      const le = timeToMinutes(lecture.end);

      return s < le && e > ls;
    });
  }

  /* ── الشبكة ─────────────────────────────────────────────────────── */
  function render() {
    renderGrid();
    renderSummary();
  }

  function renderGrid() {
    const grid = document.getElementById('schGrid');
    if (!grid) return;

    grid.style.gridTemplateColumns = `64px repeat(${DAYS.length},minmax(140px,1fr))`;

    const today = new Date().getDay();
    const totalHours = HOUR_END - HOUR_START;

    let html = '<div class="sch-corner"></div>';

    DAYS.forEach(day => {
      html += `
        <div class="sch-day-head${day.key === today ? ' is-today' : ''}">
          ${day.label}
          <span>${day.key === today ? 'اليوم' : ''}</span>
        </div>
      `;
    });

    for (let h = 0; h < totalHours; h++) {
      const hour = HOUR_START + h;
      html += `
        <div class="sch-hour-label">
          <span class="sch-hour-main">${minutesToLabel(hour * 60)}</span>
          <span class="sch-hour-half">${minutesToShortLabel(hour * 60 + 30)}</span>
        </div>
      `;

      DAYS.forEach(day => {
        html += `<div class="sch-cell${day.key === today ? ' is-today-col' : ''}" data-day="${day.key}" data-hour="${hour}"></div>`;
      });
    }

    grid.innerHTML = html;

    if (!lectures.length) {
      grid.insertAdjacentHTML('beforeend', `
        <div class="sch-empty" style="grid-column:1/-1">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
          <h3 style="font-family:var(--font-display);color:var(--ink);margin-bottom:6px">جدولك فاضي لسا</h3>
          <p>دوس "+ إضافة محاضرة" وابدأ ببناء جدولك.</p>
        </div>
      `);
      return;
    }

    /*
     * البطاقة تُلصق كابنة لخليّتها الفعلية (يوم × ساعة البدء) بدل
     * الاعتماد على grid-column وحدها بعنصر مستقل — هذا بالضبط كان
     * سبب الخلل: عنصر مموضَع absolute داخل حاوية Grid وله grid-column
     * محدَّد لكن بلا grid-row، فيمتد افتراضيًا ليغطي طول الشبكة كاملة
     * رأسيًا (القاعدة القياسية بالمواصفة لأي بعد غير محدَّد بعنصر
     * ممركَز مطلقًا داخل Grid). الإلصاق بخليّة عادية `position:relative`
     * يزيل هذا اللبس تمامًا: الحاوية الآن معروفة الحدود بدقة.
     */
    lectures.forEach(lecture => {
      const startMin = timeToMinutes(lecture.start) - HOUR_START * 60;
      const endMin = timeToMinutes(lecture.end) - HOUR_START * 60;
      const startHour = HOUR_START + Math.floor(startMin / 60);
      const offsetWithinHour = (startMin % 60) / 60 * ROW_HEIGHT;
      const height = Math.max(28, ((endMin - startMin) / 60) * ROW_HEIGHT - 4);

      lecture.days.forEach(day => {
        const dayIndex = DAYS.findIndex(d => d.key === day);
        if (dayIndex === -1) return;

        const cell = grid.querySelector(`.sch-cell[data-day="${day}"][data-hour="${startHour}"]`);
        if (!cell) return;

        const conflicts = findConflicts(day, lecture.start, lecture.end, lecture.id);
        const card = document.createElement('div');
        card.className = 'sch-lecture' + (conflicts.length ? ' conflict' : '');
        card.style.cssText = `
          top:${offsetWithinHour}px;height:${height}px;
          background:color-mix(in srgb,${lecture.color} 22%,var(--card));
          border-inline-start-color:${lecture.color};
          color:var(--ink)
        `;
        /* title سمة نصّ صرف (تلميح المتصفح) — ما بتعرض أي HTML، فالإيموجي
           هون نُزع بلا بديل أيقونة، لا لأنه مش مهم بل لاستحالة عرضها هون. */
        card.title = conflicts.length
          ? `تعارض توقيت مع: ${conflicts.map(c => c.name).join('، ')}`
          : '';
        card.innerHTML = `
          <div class="sch-lecture-title">
            <span class="sch-lecture-icon">${lecture.type === 'online' ? '<i data-icon="globe"></i>' : '<i data-icon="building"></i>'}</span>
            ${escapeHTML(lecture.name)}
          </div>
          <div class="sch-lecture-meta">
            ${minutesToLabel(timeToMinutes(lecture.start))} – ${minutesToLabel(timeToMinutes(lecture.end))}
            ${lecture.instructor ? `<br>${escapeHTML(lecture.instructor)}` : ''}
          </div>
        `;
        card.onclick = () => openEditModal(lecture.id);
        cell.appendChild(card);
      });
    });
  }

  function renderSummary() {
    const box = document.getElementById('schSummary');
    if (!box) return;

    let inPersonMins = 0, onlineMins = 0;

    lectures.forEach(lecture => {
      const duration = (timeToMinutes(lecture.end) - timeToMinutes(lecture.start)) * lecture.days.length;
      if (lecture.type === 'online') onlineMins += duration;
      else inPersonMins += duration;
    });

    const toHours = mins => (mins / 60).toFixed(1).replace(/\.0$/, '');

    box.innerHTML = `
      <div class="sch-summary-item"><i data-icon="building"></i> وجاهي: <b>${toHours(inPersonMins)}</b> ساعة/أسبوع</div>
      <div class="sch-summary-item online"><i data-icon="globe"></i> إلكتروني: <b>${toHours(onlineMins)}</b> ساعة/أسبوع</div>
      <div class="sch-summary-item">الإجمالي: <b>${toHours(inPersonMins + onlineMins)}</b> ساعة/أسبوع</div>
    `;
  }

  /* ── المودال ────────────────────────────────────────────────────── */
  async function ensureCoursesLoaded() {
    if (myCourses.length || typeof PTCAuth === 'undefined') return;

    try {
      myCourses = await PTCAuth.getCourses();
    } catch (_) {
      myCourses = [];
    }

    /*
     * القائمة المخفية <select> تحتاج خيارات <option> حقيقية بداخلها —
     * وإلا فتعيين .value برمجيًا لقيمة لا يقابلها خيار موجود يُرفَض
     * صامتًا (سلوك قياسي بعناصر select)، فتبقى القيمة فارغة رغم أن
     * الكود يظنّها مضبوطة. هذا بالضبط كان سبب "اختر مساقًا" حتى بعد
     * اختيار مساق فعليًا.
     */
    const select = document.getElementById('schCourseSelect');
    if (select) {
      select.innerHTML =
        '<option value="">— لم يُختَر —</option>' +
        '<option value="__custom__">نشاط مخصّص</option>' +
        myCourses.map(c => `<option value="${c.key}">${c.name_ar || c.name_en}</option>`).join('');
    }
  }

  /* ── قائمة مساقات قابلة للبحث ───────────────────────────────────── */
  let coursePickShown = [];
  let coursePickAt = -1;

  function courseNormalize(text) {
    return String(text || '').toLowerCase()
      .replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
      .replace(/\s+/g, ' ').trim();
  }

  function coursePickRender() {
    const list = document.getElementById('schCourseList');
    const words = courseNormalize(document.getElementById('schCourseSearch').value).split(' ').filter(Boolean);
    const chosen = document.getElementById('schCourseSelect').value;

    const matched = myCourses.filter(course => {
      if (!words.length) return true;
      const hay = courseNormalize(`${course.code || ''} ${course.name_ar || ''} ${course.name_en || ''}`);
      return words.every(w => hay.includes(w));
    });

    coursePickShown = [];
    list.replaceChildren();

    function addRow(key, label, code) {
      const row = document.createElement('div');
      row.className = 'sch-course-pick-item' + (key === chosen ? ' on' : '');
      row.id = 'sch-course-opt-' + coursePickShown.length;
      row.setAttribute('role', 'option');

      const name = document.createElement('span');
      name.textContent = label;
      const codeEl = document.createElement('code');
      codeEl.textContent = code;
      row.append(name, codeEl);

      row.addEventListener('mousedown', event => {
        event.preventDefault();
        coursePickChoose(key, label);
      });

      list.appendChild(row);
      coursePickShown.push({ key, label });
    }

    addRow('__custom__', 'نشاط مخصّص (غير مرتبط بمساق)', '');

    if (!matched.length) {
      const empty = document.createElement('div');
      empty.className = 'sch-course-pick-empty';
      empty.textContent = 'لا مساق بهذا الاسم أو الرمز.';
      list.appendChild(empty);
    } else {
      matched.forEach(course => addRow(course.key, course.name_ar || course.name_en || 'مساق', course.code || ''));
    }

    coursePickAt = 0;
    coursePickHighlight();
  }

  function coursePickHighlight() {
    coursePickShown.forEach((_, i) => {
      document.getElementById('sch-course-opt-' + i)?.classList.toggle('kbd-active', i === coursePickAt);
    });
  }

  function coursePickChoose(key, label) {
    document.getElementById('schCourseSelect').value = key;
    document.getElementById('schCourseSearch').value = key === '__custom__' ? '' : label;
    coursePickClose();
    onCourseSelectChange();
  }

  function coursePickOpen(clear) {
    if (clear) document.getElementById('schCourseSearch').value = '';
    document.getElementById('schCourseList').hidden = false;
    document.getElementById('schCourseSearch').setAttribute('aria-expanded', 'true');
    coursePickRender();
  }

  function coursePickClose() {
    setTimeout(() => {
      document.getElementById('schCourseList').hidden = true;
      document.getElementById('schCourseSearch').setAttribute('aria-expanded', 'false');
    }, 150);
  }

  function coursePickKey(event) {
    if (!['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(event.key)) return;
    if (event.key === 'Escape') { event.target.blur(); return; }

    event.preventDefault();

    if (event.key === 'Enter') {
      const item = coursePickShown[coursePickAt];
      if (item) coursePickChoose(item.key, item.label);
      return;
    }

    if (event.key === 'ArrowDown') coursePickAt = (coursePickAt + 1) % coursePickShown.length;
    else coursePickAt = coursePickAt <= 0 ? coursePickShown.length - 1 : coursePickAt - 1;

    coursePickHighlight();
    document.getElementById('sch-course-opt-' + coursePickAt)?.scrollIntoView({ block: 'nearest' });
  }

  function renderDaysPicker() {
    const box = document.getElementById('schDaysPicker');
    if (!box) return;

    box.innerHTML = DAYS.map(day => `
      <button type="button" class="sch-day-btn${selectedDays.has(day.key) ? ' active' : ''}" data-day="${day.key}">
        ${day.short}
      </button>
    `).join('');

    box.querySelectorAll('[data-day]').forEach(btn => {
      btn.onclick = () => {
        const day = Number(btn.dataset.day);
        if (selectedDays.has(day)) selectedDays.delete(day);
        else selectedDays.add(day);
        renderDaysPicker();
      };
    });
  }

  function renderColorPicker() {
    const box = document.getElementById('schColorPicker');
    if (!box) return;

    box.innerHTML = COLORS.map(color => `
      <button type="button" class="sch-color-swatch${color === selectedColor ? ' active' : ''}"
        style="background:${color}" data-color="${color}" aria-label="اختر هذا اللون"></button>
    `).join('');

    box.querySelectorAll('[data-color]').forEach(btn => {
      btn.onclick = () => {
        selectedColor = btn.dataset.color;
        renderColorPicker();
      };
    });
  }

  function setType(type) {
    selectedType = type;
    document.getElementById('schTypeInPerson').classList.toggle('active', type === 'in_person');
    document.getElementById('schTypeOnline').classList.toggle('active', type === 'online');
  }

  function onCourseSelectChange() {
    const value = document.getElementById('schCourseSelect').value;
    document.getElementById('schCustomNameField').style.display = value === '__custom__' ? '' : 'none';
  }

  function showMsg(text, type) {
    const box = document.getElementById('schModalMsg');
    box.textContent = text;
    box.className = 'sch-modal-msg show ' + type;
  }

  function hideMsg() {
    document.getElementById('schModalMsg').className = 'sch-modal-msg';
  }

  async function openAddModal() {
    editingId = null;
    selectedType = 'in_person';
    selectedDays = new Set();
    selectedColor = COLORS[lectures.length % COLORS.length];

    document.getElementById('schModalTitle').textContent = 'إضافة محاضرة';
    document.getElementById('schCourseSelect').value = '';
    document.getElementById('schCourseSearch').value = '';
    document.getElementById('schCustomNameField').style.display = 'none';
    document.getElementById('schCustomName').value = '';
    document.getElementById('schInstructor').value = '';
    document.getElementById('schStartTime').value = '09:00';
    document.getElementById('schEndTime').value = '10:00';
    document.getElementById('schEditId').value = '';
    document.getElementById('schDeleteBtn').style.display = 'none';
    hideMsg();
    setType('in_person');

    await ensureCoursesLoaded();
    renderDaysPicker();
    renderColorPicker();

    document.getElementById('schModalOverlay').classList.add('open');
  }

  async function openEditModal(id) {
    const lecture = lectures.find(l => l.id === id);
    if (!lecture) return;

    editingId = id;
    selectedType = lecture.type;
    selectedDays = new Set(lecture.days);
    selectedColor = lecture.color;

    await ensureCoursesLoaded();

    document.getElementById('schModalTitle').textContent = 'تعديل المحاضرة';

    const select = document.getElementById('schCourseSelect');
    const matchesCourse = myCourses.some(c => c.key === lecture.courseKey);
    select.value = matchesCourse ? lecture.courseKey : '__custom__';
    document.getElementById('schCourseSearch').value = matchesCourse
      ? (myCourses.find(c => c.key === lecture.courseKey)?.name_ar || lecture.name)
      : '';
    document.getElementById('schCustomNameField').style.display = matchesCourse ? 'none' : '';
    document.getElementById('schCustomName').value = matchesCourse ? '' : lecture.name;

    document.getElementById('schInstructor').value = lecture.instructor || '';
    document.getElementById('schStartTime').value = lecture.start;
    document.getElementById('schEndTime').value = lecture.end;
    document.getElementById('schEditId').value = id;
    document.getElementById('schDeleteBtn').style.display = '';
    hideMsg();
    setType(lecture.type);
    renderDaysPicker();
    renderColorPicker();

    document.getElementById('schModalOverlay').classList.add('open');
  }

  function closeModal() {
    document.getElementById('schModalOverlay').classList.remove('open');
  }

  async function save() {
    const courseSelect = document.getElementById('schCourseSelect');
    const courseKey = courseSelect.value;
    const isCustom = courseKey === '__custom__' || !courseKey;
    const course = myCourses.find(c => c.key === courseKey);

    const name = isCustom
      ? document.getElementById('schCustomName').value.trim()
      : (course?.name_ar || course?.name_en || '');

    const instructor = document.getElementById('schInstructor').value.trim();
    const start = document.getElementById('schStartTime').value;
    const end = document.getElementById('schEndTime').value;

    if (!name) {
      showMsg('اختر مساقًا أو اكتب اسم النشاط.', 'err');
      return;
    }
    if (!selectedDays.size) {
      showMsg('اختر يومًا واحدًا على الأقل.', 'err');
      return;
    }
    if (!start || !end || timeToMinutes(end) <= timeToMinutes(start)) {
      showMsg('وقت الانتهاء يجب أن يكون بعد وقت البدء.', 'err');
      return;
    }

    const conflictDays = [...selectedDays].filter(day =>
      findConflicts(day, start, end, editingId).length > 0
    );

    const local = {
      courseKey: isCustom ? '' : courseKey,
      name,
      instructor,
      type: selectedType,
      days: [...selectedDays],
      start,
      end,
      color: selectedColor,
    };

    /*
     * الزر كان يصير disabled بصمت بلا أي تغيير على شكله — فيبدو للطالب
     * وكأن الضغطة ما سجّلت أصلًا، خصوصًا لو استغرق طلب الشبكة لحظة.
     * هلق نبدّل نصّه فورًا لمؤشر "جارٍ الحفظ..." مع دوّارة صغيرة، ونرجّعه
     * لأصله بعد ما يوصل الردّ (نجاحًا كان أو فشلًا) — إحساس الاستجابة
     * الفورية هو المطلوب هون، لا تسريع الشبكة نفسها (خارج قدرتنا هون).
     */
    const saveBtn = document.querySelector('.sch-modal-actions .sch-tbtn.primary');
    const saveBtnOriginalHTML = saveBtn ? saveBtn.innerHTML : '';
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<span class="sch-btn-spinner"></span> جارٍ الحفظ...';
    }

    try {
      if (editingId) {
        const updated = await PTCAuth.updateScheduleLecture(editingId, toApiPayload(local));
        const index = lectures.findIndex(l => l.id === editingId);
        lectures[index] = fromApi(updated);
      } else {
        const created = await PTCAuth.createScheduleLecture(toApiPayload(local));
        lectures.push(fromApi(created));
      }
    } catch (error) {
      showMsg('تعذّر حفظ المحاضرة: ' + (error?.message || 'حاول مرة أخرى.'), 'err');
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = saveBtnOriginalHTML;
      }
      return;
    }

    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.innerHTML = saveBtnOriginalHTML;
    }
    render();

    if (conflictDays.length) {
      const dayNames = conflictDays.map(d => DAYS.find(x => x.key === d)?.short).join('، ');
      showMsg(`تنبيه: هذه المحاضرة تتعارض بالوقت مع محاضرة أخرى يوم ${dayNames} — تحقّق من الجدول.`, 'warn');
      setTimeout(closeModal, 2200);
    } else {
      closeModal();
    }
  }

  async function deleteCurrent() {
    if (!editingId) return;

    try {
      await PTCAuth.deleteScheduleLecture(editingId);
    } catch (error) {
      showMsg('تعذّر حذف المحاضرة: ' + (error?.message || 'حاول مرة أخرى.'), 'err');
      return;
    }

    lectures = lectures.filter(l => l.id !== editingId);
    render();
    closeModal();
  }

  async function clearAll() {
    if (!lectures.length) return;

    const confirmed = window.SiteDialog
      ? await SiteDialog.confirm({
          title: 'مسح الجدول بالكامل؟',
          message: 'رح تنمسح كل المحاضرات المحفوظة. هذا الإجراء لا يمكن التراجع عنه.',
        })
      : confirm('مسح كل المحاضرات؟ هذا الإجراء لا يمكن التراجع عنه.');

    if (!confirmed) return;

    try {
      await PTCAuth.clearSchedule();
    } catch (_) {
      alert('تعذّر مسح الجدول، حاول مرة أخرى.');
      return;
    }

    lectures = [];
    render();
  }

  /* ── تصدير .ics — يُستورَد مباشرة بـGoogle Calendar أو أي تطبيق تقويم
     آخر. لا اتصال مباشر بحساب جوجل (يحتاج OAuth كاملًا)، لكن نفس
     النتيجة العملية بجهد أبسط بكثير وأوثق. */
  function exportIcs() {
    if (!lectures.length) {
      alert('جدولك فاضي — أضف محاضرة أولًا.');
      return;
    }

    const dayCodes = { 0: 'SU', 1: 'MO', 2: 'TU', 3: 'WE', 4: 'TH', 6: 'SA' };
    const now = new Date();
    const nextSunday = new Date(now);
    nextSunday.setDate(now.getDate() + ((7 - now.getDay()) % 7));

    const pad = n => String(n).padStart(2, '0');
    const fmtDate = d => `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}`;

    let ics = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//PTC Hub//Weekly Schedule//AR\r\n';

    lectures.forEach(lecture => {
      const [sh, sm] = lecture.start.split(':').map(Number);
      const [eh, em] = lecture.end.split(':').map(Number);
      const byDays = lecture.days.map(d => dayCodes[d]).join(',');

      const startDate = new Date(nextSunday);
      startDate.setHours(sh, sm, 0);
      const endDate = new Date(nextSunday);
      endDate.setHours(eh, em, 0);

      ics += 'BEGIN:VEVENT\r\n';
      ics += `UID:${lecture.id}@ptchub\r\n`;
      ics += `SUMMARY:${lecture.name}${lecture.type === 'online' ? ' (إلكتروني)' : ' (وجاهي)'}\r\n`;
      if (lecture.instructor) ics += `DESCRIPTION:المدرّس: ${lecture.instructor}\r\n`;
      ics += `DTSTART:${fmtDate(startDate)}T${pad(sh)}${pad(sm)}00\r\n`;
      ics += `DTEND:${fmtDate(endDate)}T${pad(eh)}${pad(em)}00\r\n`;
      ics += `RRULE:FREQ=WEEKLY;BYDAY=${byDays}\r\n`;
      ics += 'END:VEVENT\r\n';
    });

    ics += 'END:VCALENDAR\r\n';

    const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'جدولي-الأسبوعي.ics';
    link.click();
    URL.revokeObjectURL(url);
  }

  /*
   * الطباعة السابقة كانت تحاول تطويع نفس شبكة الصفحة التفاعلية
   * (Grid موضعة بالمطلق، رؤوس ثابتة، حاويات تمرير) لتعمل بالطباعة —
   * وتبيّن أن هذا هش عبر عدة محاولات، لأن سلوك الطباعة يختلف بتفاصيل
   * دقيقة بين المتصفحات لا يمكن ضمانها كلها بتنسيقات CSS متراكبة.
   * الحل الحاسم: جدول HTML عادي تمامًا (<table> بصفوف وأعمدة بسيطة)
   * يُبنى من الصفر بنافذة جديدة خاصة بالطباعة فقط — نمط مضمون النتيجة
   * بكل متصفح لأنه لا يعتمد على أي شيء متقدم أو حساسّ للتموضع.
   */
  function exportPdf() {
    if (!lectures.length) {
      alert('جدولك فاضي — أضف محاضرة أولًا.');
      return;
    }

    const SLOT_MIN = 30;
    const slotsCount = ((HOUR_END - HOUR_START) * 60) / SLOT_MIN;

    /*
     * المشكلة الحقيقية اللي رجّعها المستخدم: بجدول HTML عادي، الخط
     * الفاصل بين صفّين مشترَك بينهم — ما في طريقة أكيدة تفرّق "هاد
     * الخط يخص الموعد اللي فوق" عن "يخص اللي تحت" بمجرد تغميق الخط،
     * خصوصًا بصفوف صغيرة قريبة من بعض. الحل الحاسم: كل صف يكتب مدى
     * وقته بالكامل بشكل صريح (من–إلى) بدل رقم وحيد يحتاج تفسير أي
     * خط يخصّه — هيك ما في أي غموض إطلاقًا، النص نفسه يجاوب.
     */
    function slotLabel(i) {
      const from = minutesToLabel(HOUR_START * 60 + i * SLOT_MIN);
      const to = minutesToLabel(HOUR_START * 60 + (i + 1) * SLOT_MIN);
      return `${from}<br>–${to}`;
    }

    /* شبكة إشغال: لكل يوم مصفوفة بطول عدد الشرائح، كل خانة إما فارغة
       أو تحمل مرجع محاضرة — تمنع رسم نفس المحاضرة مرتين بسبب rowspan. */
    const occupied = {};
    DAYS.forEach(d => { occupied[d.key] = new Array(slotsCount).fill(null); });

    lectures.forEach(lecture => {
      const startSlot = Math.round((timeToMinutes(lecture.start) - HOUR_START * 60) / SLOT_MIN);
      const endSlot = Math.round((timeToMinutes(lecture.end) - HOUR_START * 60) / SLOT_MIN);

      lecture.days.forEach(day => {
        if (!occupied[day]) return;
        for (let s = startSlot; s < endSlot && s < slotsCount; s++) {
          occupied[day][s] = { lecture, isStart: s === startSlot, span: endSlot - startSlot };
        }
      });
    });

    let rows = '';
    for (let s = 0; s < slotsCount; s++) {
      /*
       * كل صفين (٦٠ دقيقة) يمثّلان ساعة كاملة — الصف الأول (s زوجي)
       * هو بداية الساعة والثاني (s فردي) هو النصف. نميّزهم بصفّين
       * مختلفين بصريًا (خط أعلى أغمق للساعة الكاملة، متقطّع للنصف)
       * بنفس منطق التمييز المستخدم بالجدول التفاعلي على الشاشة —
       * فيصير واضحًا فورًا أي صف يمثّل بداية الساعة وأيّ نصفها،
       * وبالتالي وين بالضبط تبدأ/تنتهي أي محاضرة.
       */
      const isHourRow = s % 2 === 0;
      const rowClass = isHourRow ? 'hour-row' : 'half-row';
      const timeClass = isHourRow ? 'pt hour-cell' : 'pt half-cell';
      let cells = `<td class="${timeClass}">${slotLabel(s)}</td>`;

      DAYS.forEach(day => {
        const cellInfo = occupied[day.key][s];

        if (!cellInfo) {
          cells += '<td></td>';
          return;
        }
        if (!cellInfo.isStart) return; /* مُغطاة بـrowspan من صف سابق */

        const l = cellInfo.lecture;
        cells += `
          <td rowspan="${cellInfo.span}" class="pc" style="background:${l.color}22;border-inline-start:3px solid ${l.color}">
            <b>${escapeHTML(l.name)} ${l.type === 'online' ? '(إلكتروني)' : '(وجاهي)'}</b>
            <span>${minutesToLabel(timeToMinutes(l.start))}–${minutesToLabel(timeToMinutes(l.end))}</span>
            ${l.instructor ? `<span>${escapeHTML(l.instructor)}</span>` : ''}
          </td>
        `;
      });

      rows += `<tr class="${rowClass}">${cells}</tr>`;
    }

    const headCells = DAYS.map(d => `<th>${d.label}</th>`).join('');

    let inPersonMins = 0, onlineMins = 0;
    lectures.forEach(l => {
      const dur = (timeToMinutes(l.end) - timeToMinutes(l.start)) * l.days.length;
      if (l.type === 'online') onlineMins += dur; else inPersonMins += dur;
    });
    const toH = m => (m / 60).toFixed(1).replace(/\.0$/, '');

    const html = `
      <!DOCTYPE html>
      <html lang="ar" dir="rtl">
      <head>
        <meta charset="UTF-8">
        <title>جدولي الأسبوعي</title>
        <style>
          @page { margin: 10mm; }
          * { box-sizing: border-box; }
          body { font-family: 'Tajawal', Arial, sans-serif; margin: 0; padding: 16px; direction: rtl; }
          h1 { font-size: 18px; margin: 0 0 12px; text-align: center; }
          table { width: 100%; border-collapse: collapse; table-layout: fixed; }
          th, td { border: 1px solid #ddd; text-align: center; vertical-align: middle; padding: 4px; }
          th { background: #3c4a2f; color: #fff; font-size: 12px; padding: 8px 4px; }
          td.pt { font-size: 7.6px; color: #666; width: 60px; white-space: nowrap; line-height: 1.5; }
          /*
           * صف الساعة الكاملة يتميّز بخط أعلى أغمق وأسمك ونص أغمق وأثقل،
           * وصف النصف بخط متقطّع أفتح ونص أخفّ — نفس منطق التمييز
           * المستخدم بالجدول التفاعلي، عشان القارئ يعرف فورًا وهو ماسك
           * الورقة وين تبدأ كل ساعة بالضبط ووين نصفها، بدل صفوف متشابهة
           * كلها بلا أي تمييز.
           */
          tr.hour-row td, tr.hour-row th { border-top: 1.6px solid #8a8a7a; }
          tr.half-row td { border-top: 1px dashed #d8d8d0; }
          td.hour-cell { font-weight: 700; color: #3c4a2f; font-size: 8.5px; }
          td.half-cell { font-weight: 400; color: #999; font-size: 7.5px; }
          td.pc { text-align: start; padding: 5px 7px; font-size: 10px; }
          td.pc b { display: block; font-size: 10.5px; margin-bottom: 2px; }
          td.pc span { display: block; font-size: 8.5px; color: #444; }
          .summary { margin-top: 14px; text-align: center; font-size: 11px; color: #333; }
        </style>
      </head>
      <body>
        <h1>جدولي الأسبوعي</h1>
        <table>
          <thead><tr><th></th>${headCells}</tr></thead>
          <tbody>${rows}</tbody>
        </table>
        <div class="summary">
          وجاهي: ${toH(inPersonMins)} ساعة/أسبوع &nbsp;&nbsp;|&nbsp;&nbsp;
          إلكتروني: ${toH(onlineMins)} ساعة/أسبوع &nbsp;&nbsp;|&nbsp;&nbsp;
          الإجمالي: ${toH(inPersonMins + onlineMins)} ساعة/أسبوع
        </div>
      </body>
      </html>
    `;

    const win = window.open('', '_blank', 'width=1000,height=700');
    if (!win) {
      alert('المتصفح منع فتح نافذة جديدة — اسمح بالنوافذ المنبثقة لهذا الموقع وحاول مرة أخرى.');
      return;
    }

    win.document.write(html);
    win.document.close();
    win.onload = () => {
      win.focus();
      win.print();
    };
  }

  window.addEventListener('DOMContentLoaded', async () => {
    try {
      await PTCAuth.requireAuth('login.html');
    } catch (_) {
      return;
    }
    await load();
    render();
  });

  return {
    openAddModal,
    closeModal,
    setType,
    onCourseSelectChange,
    coursePickOpen,
    coursePickClose,
    coursePickKey,
    save,
    deleteCurrent,
    clearAll,
    exportIcs,
    exportPdf,
  };
})();