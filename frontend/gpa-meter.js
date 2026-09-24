/*
 * عدّاد المعدل التراكمي الدائري — بديل بصري مختصر لحاسبة المعدل
 * الكاملة، يظهر بصفحة "حسابي" بجانب شجرة التخرّج. ملف مستقل تمامًا
 * (بنفس فلسفة growth-tree.js): لا يقرأ ولا يكتب أي شيء من حاسبة
 * المعدل نفسها (gpa.js) ولا العكس، فتعديل أي وحدة منهم لا يخاطر
 * بكسر التانية — الثمن المقبول هو تكرار بسيط لمنطق حساب المعدل،
 * تمامًا متل فصل جدول gpa_entries عن my_courses لنفس السبب.
 *
 * compute() حساب بحت بلا أي DOM، وrenderDial() رسم بحت بلا أي شبكة
 * — بالضبط متل تقسيم عمل PTCGrowthTree.render(container, pct).
 */
(function () {
  const WARNING_THRESHOLD = 60;

  /*
   * يحسب المعدل التراكمي العام من كتالوج المساقات + علامات الطالب
   * + ملخص الخطة الرسمي — بنفس قاعدة gpa.js: المساقات الإجبارية
   * كلها + المساقات الاختيارية المُعلَّمة فعليًا لكن بحدّ أقصى عدد
   * المقاعد الاختيارية المسموحة بالخطة (لا الـ24 المتاحين بالكتالوج).
   */
  function compute(courses, grades, planSummary) {
    const list = Array.isArray(courses) ? courses : [];
    const safeGrades = grades && typeof grades === 'object' ? grades : {};

    const required = list.filter(c => c.course_type === 'required');
    const electives = list.filter(c => c.course_type === 'elective');

    const allowedSlots =
      (planSummary && planSummary.electives && Number(planSummary.electives.allowed)) ||
      (planSummary && Number(planSummary.required_electives)) ||
      5;

    let weightedSum = 0;
    let gradedHours = 0;

    required.forEach(course => {
      const raw = safeGrades[course.key];
      if (raw === undefined || raw === null || raw === '') return;
      const g = Number(raw);
      if (Number.isNaN(g)) return;
      const hours = Number(course.credit_hours) || 0;
      weightedSum += g * hours;
      gradedHours += hours;
    });

    const gradedElectives = electives
      .map(course => {
        const raw = safeGrades[course.key];
        if (raw === undefined || raw === null || raw === '') return null;
        const g = Number(raw);
        if (Number.isNaN(g)) return null;
        return { hours: Number(course.credit_hours) || 0, grade: g };
      })
      .filter(Boolean)
      .slice(0, allowedSlots);

    gradedElectives.forEach(entry => {
      weightedSum += entry.grade * entry.hours;
      gradedHours += entry.hours;
    });

    const totalHours = (planSummary && Number(planSummary.total_credit_hours)) || null;
    const avg = gradedHours > 0 ? weightedSum / gradedHours : null;

    return { avg, gradedHours, totalHours };
  }

  function statusLabel(avg) {
    if (avg === null) return 'ما أدخلت أي علامة بعد';
    if (avg < WARNING_THRESHOLD) return 'بحاجة لتحسين';
    if (avg < 70) return 'بداية جيدة';
    if (avg < 85) return 'أداء جيد';
    if (avg < 95) return 'متفوّق';
    return 'استثنائي';
  }

  /*
   * اسم أيقونة (راجع icons.js) يوازي كل مستوى من statusLabel أعلاه —
   * دالة منفصلة عمدًا: statusLabel يضل نصًا صرفًا يصلح لـ textContent،
   * والأيقونة تُدرَج فقط بمكان يُبنى بـ innerHTML.
   */
  function statusIcon(avg) {
    if (avg === null) return 'info';
    if (avg < WARNING_THRESHOLD) return 'warning';
    if (avg < 70) return 'leaf';
    if (avg < 85) return 'checkCircle';
    if (avg < 95) return 'star';
    return 'trophy';
  }

  function bandColorVar(avg) {
    if (avg === null) return 'var(--faint)';
    if (avg < WARNING_THRESHOLD) return '#c0392b';
    if (avg < 75) return 'var(--copper)';
    return 'var(--olive)';
  }

  /* رسم عدّاد دائري (SVG) — حلقة تقدّم متحرّكة برقم المعدل بمنتصفها،
     بنفس أبعاد شجرة التخرّج (118px) عشان يتناسقوا جنب بعض. */
  function renderDial(container, avg) {
    if (!container) return;

    const size = 118;
    const stroke = 11;
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    const pct = avg === null ? 0 : Math.max(0, Math.min(100, avg));
    const offset = c * (1 - pct / 100);
    const color = bandColorVar(avg);
    const center = size / 2;

    container.innerHTML = `
      <svg viewBox="0 0 ${size} ${size}" class="gpa-dial-svg" role="img" aria-label="عدّاد المعدل التراكمي، ${avg === null ? 'بلا علامات بعد' : Math.round(avg) + ' من 100'}">
        <circle cx="${center}" cy="${center}" r="${r}" class="gpa-dial-track"></circle>
        <circle
          cx="${center}" cy="${center}" r="${r}"
          class="gpa-dial-fill"
          style="stroke:${color};stroke-dasharray:${c};stroke-dashoffset:${offset}"
        ></circle>
      </svg>
      <div class="gpa-dial-caption">
        <strong style="color:${color}">${avg === null ? '—' : Math.round(avg * 10) / 10}</strong>
        <span>${avg === null ? 'من 100' : '/ 100'}</span>
      </div>
    `;
  }

  window.PTCGpaMeter = { compute, statusLabel, statusIcon, renderDial };
})();
