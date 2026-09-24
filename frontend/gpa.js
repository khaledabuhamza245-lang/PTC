/*
 * حاسبة المعدل التراكمي — تقدير شخصي للطالب على كامل خطة تخصّصه
 * (كل السنوات والفصول)، بلا أي علاقة بـ"مساقاتي الحالية" الحقيقية.
 * كل الحسابات تصير فورًا بالمتصفح مع كل تعديل — لا رحلة شبكة لإعادة
 * حساب معدل، فقط لحفظ العلامة نفسها بالخلفية.
 */
window.Gpa = (function () {
  const WARNING_THRESHOLD = 60;
  const DEFAULT_ELECTIVE_SLOTS = 5;

  let allCourses = [];       // مساقات الخطة الإجبارية فقط (course_type === 'required')
  let electiveCourses = [];  // كل المساقات الاختيارية المتاحة بالكتالوج — الطالب ياخد عدد محدود منها فقط
  let totalCreditHours = null;      // إجمالي ساعات الخطة الرسمي (من /me/plan-summary)
  let allowedElectiveSlots = DEFAULT_ELECTIVE_SLOTS; // كم مساق اختياري مطلوب فعليًا بالخطة
  let savedGrades = {};     // آخر نسخة محفوظة فعليًا بالسيرفر: { courseKey: grade }
  let workingGrades = {};   // ما هو معروض حاليًا (يساوي savedGrades خارج وضع المحاكاة)
  let simulationMode = false;
  let pendingSaves = new Set(); // مفاتيح مساقات قيد الحفظ حاليًا (لعرض حالة الحفظ)

  /* ── تحميل البيانات ──────────────────────────────────────────────── */

  async function load() {
    const [courses, grades, planSummary] = await Promise.all([
      PTCAuth.getCourses(),
      PTCAuth.getMyGpa(),
      /*
       * ملخّص الخطة الرسمي اختياري هون: لو فشل تحميله (خطأ شبكة عابر
       * مثلًا) ما نوقف الحاسبة كاملة، بس نرجع لتقدير احتياطي بالساعات.
       */
      PTCAuth.getPlanSummary().catch(() => null),
    ]);

    /*
     * الإجباري فقط يدخل شبكة السنوات/الفصول — المساقات الاختيارية
     * (course_type === 'elective') لها قسم منفصل تمامًا تحت الخطة،
     * لأنها مش جزء من شبكة سنة/فصل ثابتة: كل مساقات EEEX 35XX
     * الاختيارية (٢٤ مساق تقريبًا) مسجَّلة بالكتالوج بنفس year=4
     * وsemester=3 كرمز تصنيف لا كموقع فعلي بالخطة — خلطها هون قبل
     * كان يُظهرها وكأنها كلها مساقات حقيقية بالسنة الرابعة الفصل
     * الأول، وهذا بالضبط الخطأ اللي انصلح هون.
     */
    allCourses = courses
      .filter(c => c.course_type === 'required')
      .slice()
      .sort((a, b) => (a.semester - b.semester) || (a.sort_order - b.sort_order) || (a.id - b.id));

    electiveCourses = courses
      .filter(c => c.course_type === 'elective')
      .slice()
      .sort((a, b) => String(a.name_ar || a.name_en || '').localeCompare(String(b.name_ar || b.name_en || ''), 'ar'));

    totalCreditHours = planSummary ? Number(planSummary.total_credit_hours) || null : null;
    allowedElectiveSlots =
      (planSummary && planSummary.electives && Number(planSummary.electives.allowed)) ||
      (planSummary && Number(planSummary.required_electives)) ||
      DEFAULT_ELECTIVE_SLOTS;

    savedGrades = { ...grades };
    workingGrades = { ...grades };
  }

  function findCourse(key) {
    return allCourses.find(c => c.key === key) || electiveCourses.find(c => c.key === key);
  }

  /* ── حسابات ──────────────────────────────────────────────────────── */

  function localSemesterLabel(semester) {
    return ((semester - 1) % 2 === 0) ? 'الفصل الأول' : 'الفصل الثاني';
  }

  function yearLabel(year) {
    return ['', 'السنة الأولى', 'السنة الثانية', 'السنة الثالثة', 'السنة الرابعة'][year] || `السنة ${year}`;
  }

  /*
   * يحسب كل الإحصاءات من مساقات الخطة الإجبارية + المساقات الاختيارية
   * المُعلَّمة فعليًا بعلامة + خريطة علامات — دالة واحدة نقية بلا أثر
   * جانبي، تُستدعى بنفس الشكل لوضع المحاكاة والوضع الحقيقي.
   *
   * المساقات الاختيارية لا تدخل تقسيم الفصل/السنة (لأنها مش موقع
   * ثابت بالخطة)، بس تدخل المعدل العام والساعات الكلية — وبحدّ أقصى
   * عدد المقاعد الاختيارية المسموحة فعليًا (allowedElectiveSlots)،
   * تمامًا متل منطق "نسبة إنجاز الخطة" الحقيقي بالموقع: أي مساق
   * اختياري زيادة عن العدد المسموح يُعرض بعلامته لكن لا يُحتسب.
   */
  function computeStats(requiredCourses, electiveList, grades) {
    let weightedSum = 0;
    let gradedHours = 0;
    const bySemester = new Map(); // semester(1-8) -> {weightedSum, hours, year}
    const byYear = new Map();     // year(1-4) -> {weightedSum, hours}

    let requiredPlanHours = 0;

    requiredCourses.forEach(course => {
      const hours = Number(course.credit_hours) || 0;
      requiredPlanHours += hours;

      const grade = grades[course.key];
      if (grade === undefined || grade === null || grade === '') return;

      const g = Number(grade);
      if (Number.isNaN(g)) return;

      const points = g * hours;
      weightedSum += points;
      gradedHours += hours;

      if (!bySemester.has(course.semester)) {
        bySemester.set(course.semester, { weightedSum: 0, hours: 0, year: course.year });
      }
      const semEntry = bySemester.get(course.semester);
      semEntry.weightedSum += points;
      semEntry.hours += hours;

      if (!byYear.has(course.year)) {
        byYear.set(course.year, { weightedSum: 0, hours: 0 });
      }
      const yearEntry = byYear.get(course.year);
      yearEntry.weightedSum += points;
      yearEntry.hours += hours;
    });

    const gradedElectives = electiveList
      .map(course => {
        const grade = grades[course.key];
        if (grade === undefined || grade === null || grade === '') return null;
        const g = Number(grade);
        if (Number.isNaN(g)) return null;
        return { course, grade: g, hours: Number(course.credit_hours) || 0 };
      })
      .filter(Boolean);

    const creditedElectives = gradedElectives.slice(0, allowedElectiveSlots);
    const extraElectiveKeys = new Set(gradedElectives.slice(allowedElectiveSlots).map(e => e.course.key));

    creditedElectives.forEach(entry => {
      weightedSum += entry.grade * entry.hours;
      gradedHours += entry.hours;
    });

    /*
     * إجمالي ساعات الخطة: نفضّل الرقم الرسمي من /me/plan-summary
     * (يشمل بالضبط عدد المقاعد الاختيارية المطلوبة، لا الـ٢٤ مساقًا
     * الاختياريًا المتاحًا كلهم) — واحتياطي تقديري لو تعذّر تحميله.
     */
    const totalPlanHours = totalCreditHours !== null
      ? totalCreditHours
      : requiredPlanHours + allowedElectiveSlots * 3;

    const cumulativeAvg = gradedHours > 0 ? weightedSum / gradedHours : null;
    const remainingHours = Math.max(0, totalPlanHours - gradedHours);

    const semesterAverages = [...bySemester.entries()]
      .map(([semester, entry]) => ({
        semester,
        year: entry.year,
        average: entry.hours > 0 ? entry.weightedSum / entry.hours : null,
        hours: entry.hours,
      }))
      .sort((a, b) => a.semester - b.semester);

    const yearAverages = [...byYear.entries()]
      .map(([year, entry]) => ({
        year,
        average: entry.hours > 0 ? entry.weightedSum / entry.hours : null,
        hours: entry.hours,
      }))
      .sort((a, b) => a.year - b.year);

    return {
      weightedSum, gradedHours, totalPlanHours, remainingHours,
      cumulativeAvg, semesterAverages, yearAverages,
      gradedElectivesCount: gradedElectives.length,
      creditedElectivesCount: creditedElectives.length,
      extraElectiveKeys,
    };
  }

  function requiredAverageForTarget(stats, target) {
    if (stats.remainingHours <= 0) return { status: 'no-remaining' };

    const t = Number(target);
    if (Number.isNaN(t) || t < 0 || t > 100) return { status: 'invalid' };

    const totalHours = stats.gradedHours + stats.remainingHours;
    const needed = (t * totalHours - stats.weightedSum) / stats.remainingHours;

    if (needed <= 0) return { status: 'already-met' };
    if (needed > 100) return { status: 'impossible', needed };
    return { status: 'ok', needed };
  }

  /* ── مساعدات عرض ─────────────────────────────────────────────────── */

  function fmt(n, digits = 2) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    return Number(n).toFixed(digits).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
  }

  function gradeClass(avg) {
    if (avg === null) return '';
    if (avg < WARNING_THRESHOLD) return 'gpa-danger';
    if (avg < 75) return 'gpa-warn';
    return 'gpa-good';
  }

  function cssEscape(value) {
    return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/["\\]/g, '\\$&');
  }

  /*
   * يحفظ مكان التركيز الحالي (لو كان بخانة علامة) قبل إعادة رسم جدول
   * يعيد بناء كل صفوفه من الصفر، وبيرجّعه بعد الرسم. هذا هو حل مشكلة
   * "التاب بياخدني لخانة ثانية بس تركيز الكتابة بيروح ولازم أرجع
   * أضغط بالماوس" — لأن renderPlan/renderElectives يبنيان <input>
   * جديدة تمامًا بكل مرة (عشان القيم تنعكس صح)، فأي عنصر كان عليه
   * تركيز (حتى لو التاب نقله فعليًا للخانة التالية قبل ما توصل هون)
   * ينمسح ويتبدّل بعنصر جديد ما إله علاقة فيه — فيروح التركيز فعليًا.
   */
  function withFocusPreserved(rerenderFn) {
    const active = document.activeElement;
    let key = null;
    let selStart = null;
    let selEnd = null;

    if (active && active.classList && active.classList.contains('gpa-grade-input')) {
      const tr = active.closest('tr[data-course-key]');
      key = tr ? tr.dataset.courseKey : null;
      try {
        selStart = active.selectionStart;
        selEnd = active.selectionEnd;
      } catch (_) {}
    }

    rerenderFn();

    if (key) {
      const el = document.querySelector(`tr[data-course-key="${cssEscape(key)}"] .gpa-grade-input`);
      if (el) {
        el.focus();
        try { el.setSelectionRange(selStart, selEnd); } catch (_) {}
      }
    }
  }

  /* ── الرسم ────────────────────────────────────────────────────────── */

  function renderAll() {
    renderSummary();
    renderTarget();
    renderTrend();
    renderPlan();
    renderElectives();
  }

  function renderSummary() {
    const stats = computeStats(allCourses, electiveCourses, workingGrades);
    const box = document.getElementById('gpaSummary');
    const warn = stats.cumulativeAvg !== null && stats.cumulativeAvg < WARNING_THRESHOLD;
    const percentDone = stats.totalPlanHours > 0
      ? Math.min(100, Math.round((stats.gradedHours / stats.totalPlanHours) * 100))
      : 0;

    box.innerHTML = `
      <div class="gpa-stat-card gpa-stat-main ${gradeClass(stats.cumulativeAvg)}">
        <span class="gpa-stat-label">المعدل التراكمي العام (0-100)</span>
        <span class="gpa-stat-value">${fmt(stats.cumulativeAvg)}${stats.cumulativeAvg !== null ? '%' : ''}</span>
        ${warn ? '<span class="gpa-badge gpa-badge-warn"><i data-icon="warning"></i> تحذير / إنذار أكاديمي</span>' : ''}
      </div>
      <div class="gpa-stat-card">
        <span class="gpa-stat-label">إجمالي النقاط الموزونة</span>
        <span class="gpa-stat-value">${fmt(stats.weightedSum, 1)}</span>
      </div>
      <div class="gpa-stat-card">
        <span class="gpa-stat-label">الساعات المُدخَلة بالخطة</span>
        <span class="gpa-stat-value">${stats.gradedHours} / ${stats.totalPlanHours} ساعة</span>
        <div class="gpa-progress"><div class="gpa-progress-bar" style="width:${percentDone}%"></div></div>
      </div>
    `;
  }

  /*
   * renderTarget تبني هيكل البطاقة كاملًا (تُستدعى فقط بالرسم
   * الأساسي/بعد المحاكاة/تفريغ الخطة — لا مع كل ضغطة مفتاح)، بينما
   * updateTargetResult تلمس فقط فقرة النتيجة تحت الخانة. الفصل بينهم
   * هو حل مشكلة "الأرقام بتتلخبط بخانة الهدف" — كانت كل ضغطة مفتاح
   * تعيد بناء الخانة نفسها من الصفر (عنصر <input> جديد بالكامل)،
   * فيروح التركيز فور كتابة أول رقم ولازم تضغط بالماوس من جديد لكل
   * رقم إضافي.
   */
  function renderTarget() {
    const card = document.getElementById('gpaTargetCard');
    const existingInput = card.querySelector('#gpaTargetInput');
    const targetValue = existingInput ? existingInput.value : '';

    card.querySelector('.gpa-target-body').innerHTML = `
      <label class="gpa-target-field">
        <span>المعدل التراكمي المستهدف</span>
        <input type="number" id="gpaTargetInput" min="0" max="100" step="0.5" value="${targetValue}" placeholder="مثلاً 85" oninput="Gpa.onTargetInput()">
      </label>
      <div id="gpaTargetResult"></div>
    `;

    updateTargetResult();
  }

  function updateTargetResult() {
    const resultBox = document.getElementById('gpaTargetResult');
    if (!resultBox) return;

    const input = document.getElementById('gpaTargetInput');
    const targetValue = input ? input.value : '';

    const stats = computeStats(allCourses, electiveCourses, workingGrades);
    const result = targetValue === '' ? null : requiredAverageForTarget(stats, targetValue);

    let resultHtml = '<p class="gpa-target-hint">اكتب المعدل التراكمي اللي بدك توصله، ورح نحسبلك شو لازم يكون معدلك بباقي الساعات (' + stats.remainingHours + ' ساعة) عشان توصله.</p>';

    if (result) {
      if (result.status === 'invalid') {
        resultHtml = '<p class="gpa-target-result gpa-danger">اكتب رقم بين 0 و100.</p>';
      } else if (result.status === 'no-remaining') {
        resultHtml = '<p class="gpa-target-result">كل مساقات الخطة معلَّمة بعلامة — ما في ساعات متبقية تُحسَب عليها.</p>';
      } else if (result.status === 'already-met') {
        resultHtml = '<p class="gpa-target-result gpa-good"><i data-icon="checkCircle"></i> معدلك الحالي أصلًا يحقق هالهدف أو أعلى منه</p>';
      } else if (result.status === 'impossible') {
        resultHtml = '<p class="gpa-target-result gpa-danger">هذا الهدف غير قابل للتحقيق حتى لو حصلت على 100 بكل الساعات المتبقية (' + stats.remainingHours + ' ساعة).</p>';
      } else {
        const cls = result.needed <= 75 ? 'gpa-good' : (result.needed <= 90 ? 'gpa-warn' : 'gpa-danger');
        resultHtml = `<p class="gpa-target-result ${cls}">لازم يكون معدلك على الساعات المتبقية (${stats.remainingHours} ساعة) على الأقل <b>${fmt(result.needed, 1)}</b>.</p>`;
      }
    }

    resultBox.innerHTML = resultHtml;
  }

  function onTargetInput() {
    updateTargetResult();
  }

  function renderTrend() {
    const card = document.getElementById('gpaTrendCard');
    const stats = computeStats(allCourses, electiveCourses, workingGrades);
    const points = stats.semesterAverages.filter(s => s.average !== null);

    if (points.length < 2) {
      card.innerHTML = '<h3>تطوّر معدلك عبر الفصول</h3><p class="gpa-empty-hint">أدخل علامات فصلين مختلفين على الأقل عشان يظهر الرسم البياني.</p>';
      return;
    }

    const w = 640, h = 180, pad = 30;
    const minV = Math.min(40, Math.floor(Math.min(...points.map(p => p.average)) / 10) * 10);
    const maxV = 100;
    const xStep = (w - pad * 2) / Math.max(1, points.length - 1);
    const yFor = v => h - pad - ((v - minV) / (maxV - minV)) * (h - pad * 2);
    const xFor = i => pad + i * xStep;

    const path = points.map((p, i) => `${xFor(i)},${yFor(p.average)}`).join(' ');

    const circles = points.map((p, i) => `
      <circle cx="${xFor(i)}" cy="${yFor(p.average)}" r="4" style="fill:var(--olive)"></circle>
      <text x="${xFor(i)}" y="${yFor(p.average) - 10}" text-anchor="middle" class="gpa-trend-value">${fmt(p.average, 1)}</text>
      <text x="${xFor(i)}" y="${h - 6}" text-anchor="middle" class="gpa-trend-label">${yearLabel(p.year).replace('السنة ', 'سنة ')}<tspan x="${xFor(i)}" dy="11">${localSemesterLabel(p.semester)}</tspan></text>
    `).join('');

    const warnY = yFor(WARNING_THRESHOLD);

    card.innerHTML = `
      <h3>تطوّر معدلك عبر الفصول</h3>
      <svg viewBox="0 0 ${w} ${h}" class="gpa-trend-svg" role="img" aria-label="رسم بياني لتطور المعدل الفصلي">
        <line x1="${pad}" y1="${warnY}" x2="${w - pad}" y2="${warnY}" class="gpa-trend-warnline"></line>
        <polyline points="${path}" style="fill:none;stroke:var(--olive);stroke-width:2.5"></polyline>
        ${circles}
      </svg>
    `;
  }

  function renderPlan() {
    const container = document.getElementById('gpaPlan');
    const byYear = new Map();

    allCourses.forEach(course => {
      if (!byYear.has(course.year)) byYear.set(course.year, []);
      byYear.get(course.year).push(course);
    });

    const years = [...byYear.keys()].sort((a, b) => a - b);
    const stats = computeStats(allCourses, electiveCourses, workingGrades);

    container.innerHTML = years.map(year => {
      const courses = byYear.get(year);
      const bySem = new Map();
      courses.forEach(c => {
        if (!bySem.has(c.semester)) bySem.set(c.semester, []);
        bySem.get(c.semester).push(c);
      });
      const semesters = [...bySem.keys()].sort((a, b) => a - b);

      const yearStat = stats.yearAverages.find(y => y.year === year);

      const semBlocks = semesters.map(sem => {
        const semStat = stats.semesterAverages.find(s => s.semester === sem);
        const rows = bySem.get(sem).map(course => renderRow(course)).join('');

        return `
          <div class="gpa-sem-block" data-semester="${sem}">
            <div class="gpa-sem-head">
              <span>${localSemesterLabel(sem)}</span>
              <span class="gpa-sem-avg ${gradeClass(semStat ? semStat.average : null)}">
                ${semStat && semStat.average !== null ? `معدل الفصل: ${fmt(semStat.average, 1)}` : 'ما في علامات لسا'}
              </span>
            </div>
            <table class="gpa-table">
              <thead>
                <tr>
                  <th>اسم المساق</th>
                  <th>الساعات</th>
                  <th>العلامة (0-100)</th>
                  <th>النقاط الموزونة</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        `;
      }).join('');

      return `
        <details class="gpa-year-card" data-year="${year}" open>
          <summary>
            <span class="gpa-year-title">${yearLabel(year)}</span>
            <span class="gpa-year-avg ${gradeClass(yearStat ? yearStat.average : null)}">
              ${yearStat && yearStat.average !== null ? `معدل السنة: ${fmt(yearStat.average, 1)}` : 'ما في علامات لسا'}
            </span>
          </summary>
          <div class="gpa-year-body">${semBlocks}</div>
        </details>
      `;
    }).join('');
  }

  /*
   * قسم المساقات الاختيارية — منفصل تمامًا عن شبكة السنوات/الفصول
   * لأنها ليست موقعًا ثابتًا بالخطة: الطالب ياخد عددًا محدودًا منها
   * فقط (allowedElectiveSlots) من بين كل الاختياريات المتاحة
   * بالكتالوج. أي علامة يدخلها الطالب لمساق اختياري تُحسب ضمن
   * معدله العام طالما ضمن الحد المسموح؛ وأي زيادة عنه تُعرض بعلامتها
   * (للمعرفة الشخصية فقط) لكن تُعلَّم بوضوح أنها غير محتسبة.
   */
  function renderElectives() {
    const container = document.getElementById('gpaElectives');
    if (!container) return;

    const stats = computeStats(allCourses, electiveCourses, workingGrades);
    const rows = electiveCourses.map(course => renderRow(course, stats.extraElectiveKeys)).join('');

    container.innerHTML = `
      <details class="gpa-year-card gpa-elective-card" open>
        <summary>
          <span class="gpa-year-title">المساقات الاختيارية <i data-icon="target"></i></span>
          <span class="gpa-sem-avg">
            مُدخَل: ${stats.gradedElectivesCount} — محتسَب منها: ${Math.min(stats.gradedElectivesCount, allowedElectiveSlots)} من ${allowedElectiveSlots}
          </span>
        </summary>
        <div class="gpa-year-body">
          <p class="gpa-elective-hint">
            خطتك بتتطلّب <b>${allowedElectiveSlots}</b> مساق${allowedElectiveSlots === 1 ? '' : 'ات'} اختياري${allowedElectiveSlots === 1 ? '' : 'ة'} بس، من بين كل هاي القائمة.
            أدخل علامة أي مساق اخترته وأخذته فعلًا فقط — الباقي اتركه فاضي. أول ${allowedElectiveSlots} مساق${allowedElectiveSlots === 1 ? '' : 'ات'} تُدخلها بتُحتسب تلقائيًا ضمن معدلك العام،
            وأي زيادة عنها تظهر بعلامتها لكن بشارة "زيادة" لأنها ما بتُحتسب.
          </p>
          <table class="gpa-table">
            <thead>
              <tr>
                <th>اسم المساق</th>
                <th>الساعات</th>
                <th>العلامة (0-100)</th>
                <th>النقاط الموزونة</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </details>
    `;
  }

  /*
   * تحديث "معدل الفصل"/"معدل السنة" بعد حفظ علامة، بلا إعادة بناء
   * أي <input> إطلاقًا — فقط نص وصنف شارتي المتوسط بكل سنة/فصل. هذا
   * هو الحل الجذري لمشاكل التركيز المتكررة (التاب، التحديد الأزرق
   * اللي يختفي بسرعة، إلخ): طالما ما في عنصر إدخال ينمسح وينبنى من
   * جديد، ما في تركيز ممكن يضيع أصلًا — أي سلوك افتراضي بالمتصفح
   * (تحديد كامل الرقم عند التنقّل بالتاب مثلًا) يضل موجود لأن العنصر
   * نفسه ما لمسناه.
   */
  function updatePlanAverages() {
    const stats = computeStats(allCourses, electiveCourses, workingGrades);

    document.querySelectorAll('#gpaPlan .gpa-year-card[data-year]').forEach(card => {
      const year = Number(card.dataset.year);
      const yearStat = stats.yearAverages.find(y => y.year === year);
      const yearBadge = card.querySelector('summary .gpa-year-avg');
      if (yearBadge) {
        yearBadge.className = `gpa-year-avg ${gradeClass(yearStat ? yearStat.average : null)}`;
        yearBadge.textContent = yearStat && yearStat.average !== null
          ? `معدل السنة: ${fmt(yearStat.average, 1)}`
          : 'ما في علامات لسا';
      }

      card.querySelectorAll('.gpa-sem-block[data-semester]').forEach(block => {
        const sem = Number(block.dataset.semester);
        const semStat = stats.semesterAverages.find(s => s.semester === sem);
        const semBadge = block.querySelector('.gpa-sem-avg');
        if (semBadge) {
          semBadge.className = `gpa-sem-avg ${gradeClass(semStat ? semStat.average : null)}`;
          semBadge.textContent = semStat && semStat.average !== null
            ? `معدل الفصل: ${fmt(semStat.average, 1)}`
            : 'ما في علامات لسا';
        }
      });
    });
  }

  /*
   * نفس فكرة updatePlanAverages بس لقسم الاختيارية: نحدّث سطر
   * الملخّص ("مُدخَل: X — محتسَب منها: Y") وشارة "زيادة" لكل صف حسب
   * الحد المسموح — بلا لمس أي <input> إطلاقًا.
   */
  function updateElectiveAverages() {
    const container = document.getElementById('gpaElectives');
    if (!container) return;

    const stats = computeStats(allCourses, electiveCourses, workingGrades);

    const summaryBadge = container.querySelector('.gpa-elective-card summary .gpa-sem-avg');
    if (summaryBadge) {
      summaryBadge.textContent =
        `مُدخَل: ${stats.gradedElectivesCount} — محتسَب منها: ${Math.min(stats.gradedElectivesCount, allowedElectiveSlots)} من ${allowedElectiveSlots}`;
    }

    electiveCourses.forEach(course => {
      const row = container.querySelector(`tr[data-course-key="${cssEscape(course.key)}"]`);
      if (!row) return;

      const nameCell = row.querySelector('.gpa-course-name');
      if (!nameCell) return;

      const grade = workingGrades[course.key];
      const hasGrade = grade !== undefined && grade !== null && grade !== '';
      const isExtra = hasGrade && stats.extraElectiveKeys.has(course.key);
      const existingBadge = nameCell.querySelector('.gpa-extra-badge');

      if (isExtra && !existingBadge) {
        const span = document.createElement('span');
        span.className = 'gpa-extra-badge';
        span.title = 'ما بتُحتسب ضمن معدلك — تجاوزت عدد المقاعد الاختيارية المسموحة';
        span.textContent = 'زيادة';
        nameCell.appendChild(span);
      } else if (!isExtra && existingBadge) {
        existingBadge.remove();
      }
    });
  }

  /* يعلّم/يمسح مؤشر "قيد الحفظ" البصري على خانة علامة محدَّدة مباشرة
     — بلا إعادة رسم أي شيء، لنفس سبب الدالتين أعلاه. */
  function setSavingIndicator(key, isSaving) {
    const input = document.querySelector(`tr[data-course-key="${cssEscape(key)}"] .gpa-grade-input`);
    if (input) input.classList.toggle('gpa-saving', isSaving);
  }

  function renderRow(course, extraElectiveKeys) {
    const grade = workingGrades[course.key];
    const hours = Number(course.credit_hours) || 0;
    const hasGrade = grade !== undefined && grade !== null && grade !== '';
    const points = hasGrade ? Number(grade) * hours : null;
    const savingCls = pendingSaves.has(course.key) ? 'gpa-saving' : '';
    const isExtra = hasGrade && extraElectiveKeys && extraElectiveKeys.has(course.key);

    return `
      <tr data-course-key="${course.key}">
        <td class="gpa-course-name">
          ${PTCUtils.escapeHTML(course.name_ar || course.name_en || 'مساق')}
          ${isExtra ? '<span class="gpa-extra-badge" title="ما بتُحتسب ضمن معدلك — تجاوزت عدد المقاعد الاختيارية المسموحة">زيادة</span>' : ''}
        </td>
        <td class="gpa-hours">${hours || '—'}</td>
        <td>
          <input
            type="number" min="0" max="100" step="0.5"
            class="gpa-grade-input ${savingCls}"
            value="${hasGrade ? grade : ''}"
            placeholder="—"
            oninput="Gpa.onGradeInput('${course.key}', this.value)"
            onblur="Gpa.onGradeBlur('${course.key}', this.value)"
          >
          <span class="gpa-invalid-msg">لازم 0-100</span>
        </td>
        <td class="gpa-points">${points !== null ? fmt(points, 1) : '—'}</td>
      </tr>
    `;
  }

  /* ── تفاعل الطالب ─────────────────────────────────────────────────── */

  function onGradeInput(key, value) {
    if (value === '') {
      delete workingGrades[key];
    } else {
      const n = Number(value);
      if (!Number.isNaN(n)) workingGrades[key] = n;
    }

    /* تحديث خانة النقاط الموزونة بنفس الصف فقط، بلا إعادة رسم الجدول
       كامل — إعادة رسمه بكل ضغطة مفتاح كانت ستفقد تركيز الكتابة. */
    const row = document.querySelector(`tr[data-course-key="${cssEscape(key)}"]`);
    if (row) {
      const course = findCourse(key);
      const hours = course ? Number(course.credit_hours) || 0 : 0;
      const grade = workingGrades[key];
      const hasGrade = grade !== undefined;
      row.querySelector('.gpa-points').textContent = hasGrade ? fmt(grade * hours, 1) : '—';

      /* لو كان مُعلَّمًا خطأ (رقم خارج 0-100) من محاولة سابقة، وصار
         الآن بحدود صحيحة أو فاضي، امسح تعليم الخطأ فورًا وهو لسا
         يكتب — بلا انتظار الخروج من الخانة. */
      const input = row.querySelector('.gpa-grade-input');
      if (input && input.classList.contains('gpa-invalid')) {
        const trimmed = String(value).trim();
        const stillInvalid = trimmed !== '' && (Number.isNaN(Number(trimmed)) || Number(trimmed) < 0 || Number(trimmed) > 100);
        if (!stillInvalid) input.classList.remove('gpa-invalid');
      }
    }

    /*
     * هذه الحاويات (الملخص/الهدف/الرسم البياني) منفصلة تمامًا عن
     * الجدول اللي فيه خانة العلامة المركَّز عليها حاليًا، فإعادة
     * رسمها بكل ضغطة مفتاح لا تفقد أي تركيز — بعكس renderPlan
     * وrenderElectives اللي ما بيصيرو إلا بعد blur (onGradeBlur).
     */
    renderSummary();
    updateTargetResult();
    renderTrend();
  }

  async function onGradeBlur(key, value) {
    const trimmed = String(value).trim();
    const row = document.querySelector(`tr[data-course-key="${cssEscape(key)}"]`);
    const input = row ? row.querySelector('.gpa-grade-input') : null;

    /*
     * رقم خارج 0-100: نعلّم الخانة نفسها (حدّ أحمر + رسالة صغيرة
     * تحتها) ونرجّع التركيز لها فورًا مع تحديد كل النص — الطالب
     * يقدر يصحّح الرقم بالكتابة مباشرة بلا ما يرجع يدوس بالماوس،
     * وبلا نافذة alert() تحجب المتصفح وما ترجعه للخانة. ما منحفظ
     * ولا منعيد رسم الجدول هون أبدًا، عشان الخانة تضل نفسها بالضبط
     * (تعليم الخطأ ينمسح تلقائيًا بمجرد ما يصير الرقم صحيحًا —
     * راجع onGradeInput).
     */
    if (trimmed !== '' && (Number.isNaN(Number(trimmed)) || Number(trimmed) < 0 || Number(trimmed) > 100)) {
      if (input) {
        input.classList.add('gpa-invalid');
        input.focus();
        try { input.select(); } catch (_) {}
      }
      return;
    }

    if (input) input.classList.remove('gpa-invalid');

    if (simulationMode) {
      /* بوضع المحاكاة ما نحفظ إطلاقًا — بس نحدّث متوسطات الفصل/السنة
         بلا لمس السيرفر، وبلا إعادة بناء أي خانة إدخال (راجع تعليق
         updatePlanAverages لسبب هذا تحديدًا). */
      updatePlanAverages();
      updateElectiveAverages();
      renderSummary();
      updateTargetResult();
      renderTrend();
      return;
    }

    const course = findCourse(key);
    if (!course) return;

    pendingSaves.add(key);
    setSavingIndicator(key, true);

    try {
      if (trimmed === '') {
        if (savedGrades[key] !== undefined) {
          await PTCAuth.clearGpaGrade(key);
          delete savedGrades[key];
        }
      } else {
        const n = Number(trimmed);
        await PTCAuth.saveGpaGrade(key, n);
        savedGrades[key] = n;
      }

      pendingSaves.delete(key);
      setSavingIndicator(key, false);

      /*
       * مسار النجاح (الغالب الأعمّ): تحديث سطحي فقط لشارات المتوسطات
       * — بلا لمس أي <input> إطلاقًا. هذا هو حل مشكلة "التحديد
       * الأزرق يختفي بسرعة بعد التاب": كان renderPlan/renderElectives
       * يهدمان كل خانات الجدول ويبنيانها من جديد بعد كل حفظ ناجح
       * (حتى مع محاولة إرجاع التركيز يدويًا)، فأي سلوك تحديد افتراضي
       * من المتصفح كان ينمحي فورًا لحظة إعادة البناء. الآن العنصر
       * نفسه ما ينلمس أبدًا، فيضل بالضبط متل ما تركه المتصفح.
       */
      updatePlanAverages();
      updateElectiveAverages();
      renderSummary();
      updateTargetResult();
      renderTrend();
    } catch (error) {
      alert('تعذّر حفظ العلامة: ' + (error?.message || 'حاول مرة أخرى.'));
      workingGrades = { ...savedGrades };
      pendingSaves.delete(key);
      setSavingIndicator(key, false);

      /*
       * مسار الفشل نادر: workingGrades كامل يرجع لآخر نسخة محفوظة
       * (مش بس هالمساق)، فبيلزم فعليًا إعادة بناء كل الصفوف دفعة
       * وحدة عشان تعكس ذلك — هون فقط نلجأ لحفظ/استرجاع مكان التركيز.
       */
      withFocusPreserved(() => { renderPlan(); renderElectives(); });
      renderSummary();
      updateTargetResult();
      renderTrend();
    }
  }

  /* ── وضع المحاكاة ("ماذا لو؟") ────────────────────────────────────── */

  function toggleSimulation() {
    simulationMode = !simulationMode;
    workingGrades = { ...savedGrades };

    const btn = document.getElementById('gpaSimBtn');
    const banner = document.getElementById('gpaSimBanner');
    if (btn) btn.classList.toggle('active', simulationMode);
    if (banner) banner.style.display = simulationMode ? '' : 'none';

    renderAll();
  }

  /* ── تفريغ الخطة / طباعة ──────────────────────────────────────────── */

  async function clearPlan() {
    const hasAny = Object.keys(savedGrades).length > 0;
    if (!hasAny) return;

    const confirmed = window.SiteDialog
      ? await SiteDialog.confirm({
          title: 'تفريغ كل العلامات؟',
          message: 'رح تنمسح كل العلامات اللي أدخلتها بحاسبة المعدل من كل السنوات (بما فيها الاختيارية). هذا الإجراء لا يمكن التراجع عنه.',
        })
      : confirm('تفريغ كل العلامات؟ هذا الإجراء لا يمكن التراجع عنه.');

    if (!confirmed) return;

    try {
      await PTCAuth.clearAllGpa();
    } catch (error) {
      alert('تعذّر تفريغ الخطة: ' + (error?.message || 'حاول مرة أخرى.'));
      return;
    }

    savedGrades = {};
    workingGrades = {};
    renderAll();
  }

  /*
   * زر "طباعة التقرير": بنعبّي ترويسة الطباعة (اسم الطالب + تاريخ
   * اليوم) قبل ما نستدعي window.print()، عشان التقرير المطبوع/PDF
   * يكون تقريرًا كاملًا ومستقلًا بذاته (شعار + عنوان + اسم الطالب +
   * تاريخ + كل الملخّصات والجداول)، لا مجرد جزء من صفحة الموقع.
   */
  function printReport() {
    const meta = document.getElementById('gpaPrintMeta');
    if (meta) {
      const name = (PTCAuth.user && (PTCAuth.user.full_name || PTCAuth.user.email)) || '';
      let dateStr = '';
      try {
        dateStr = new Date().toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });
      } catch (_) {
        dateStr = new Date().toLocaleDateString();
      }
      /* سطرين منفصلين (لا سطر واحد طويل) عشان يبقوا مقروئين حتى لو
         الاسم طويل — meta محدودة العرض بالطباعة (max-width). بناء
         العناصر بـ createElement/textContent (لا innerHTML) عشان
         اسم الطالب ما يُعامَل كـHTML لو فيه رمز خاص. */
      meta.textContent = '';
      const nameLine = document.createElement('div');
      nameLine.textContent = `الطالب: ${name}`;
      const dateLine = document.createElement('div');
      dateLine.textContent = `تاريخ التقرير: ${dateStr}`;
      meta.appendChild(nameLine);
      meta.appendChild(dateLine);
    }
    window.print();
  }

  /* ── الإقلاع ──────────────────────────────────────────────────────── */

  window.addEventListener('DOMContentLoaded', async () => {
    try {
      await PTCAuth.requireAuth('login.html');
    } catch (_) {
      return;
    }

    try {
      await load();
    } catch (error) {
      document.getElementById('gpaPlan').innerHTML =
        `<p class="gpa-empty-hint">تعذّر تحميل بيانات الخطة: ${PTCUtils.escapeHTML(error?.message || 'حاول تحديث الصفحة.')}</p>`;
      return;
    }

    renderAll();
  });

  return {
    onGradeInput,
    onGradeBlur,
    onTargetInput,
    toggleSimulation,
    clearPlan,
    printReport,
  };
})();
