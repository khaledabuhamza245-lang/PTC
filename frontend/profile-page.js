/* الصفحة الشخصية: التقدّم نحو التخرّج + الخطة الدراسية كاملة. */
(function () {
  const esc =
    value =>
      PTCUtils.escapeHTML(
        value
      );

  const $ =
    id =>
      document.getElementById(
        id
      );

  const YEAR_NAMES = [
    '',
    'الأولى',
    'الثانية',
    'الثالثة',
    'الرابعة',
  ];

  let catalog = [];
  let summary = null;
  let statuses = {};

  /*
   * علامات حاسبة المعدل (gpa_entries) — تُحمَّل هون فقط عشان عدّاد
   * المعدل الدائري بهاي الصفحة (راجع gpa-meter.js)، بلا أي علاقة
   * بمنطق التقدّم/الحالات أعلاه.
   */
  let gpaGrades = {};

  const pending =
    new Map();

  let draining =
    false;

  /*
   * السنة والفصل اللذان
   * سيتم فتحهما تلقائيًا.
   */
  let openYear =
    null;

  let openSemester =
    null;

  /*
   * term_id → 1 أو 2
   */
  const termSemesterById =
    new Map();

  function hint(
    message,
    kind
  ) {
    const node =
      $('pfSaveHint');

    if (!node) {
      return;
    }

    node.textContent =
      message;

    node.className =
      'pf-hint'
      +
      (
        kind
          ? ' pf-hint-' + kind
          : ''
      );
  }

  /* ───── التقدّم ───── */

  function renderSummary() {
    if (!summary) {
      return;
    }

    const percent =
      Number(
        summary.percent
      ) || 0;

    const treeBox = $('pfGrowthTree');

    if (treeBox && window.PTCGrowthTree) {
      window.PTCGrowthTree.render(treeBox, percent);
    }

    $('pfBar').style.width =
      percent + '%';

    $('pfHeadline').textContent =
      `أنجزتَ ${summary.completed_hours} من ${summary.total_credit_hours} ساعة معتمدة`;

    const figures = [
      {
        v:
          summary.completed_hours,

        l:
          'ساعة منجَزة',
      },

      {
        v:
          summary.remaining_hours,

        l:
          'ساعة متبقية',
      },

      {
        v:
          summary.counts.completed,

        l:
          'مساقًا أنهيته',
      },

      {
        v:
          summary.counts.registered,

        l:
          'مساقًا جاريًا',
      },

      {
        v:
          `${summary.electives.counted}/${summary.electives.allowed}`,

        l:
          'اختياري محتسَب',
      },
    ];

    $('pfFigures').innerHTML =
      figures
        .map(
          f => `
            <div class="pf-figure">
              <b>
                ${esc(f.v)}
              </b>

              <span>
                ${esc(f.l)}
              </span>
            </div>
          `
        )
        .join('');

    const warnings =
      [];

    if (
      summary.electives.extra > 0
    ) {
      warnings.push(
        `أنهيتَ ${summary.electives.extra} مساقًا اختياريًا فوق سقف الخطة `
        +
        `(${summary.electives.allowed}) — تُحسب الأقدم إنجازًا، والباقي لا يدخل الرصيد.`
      );
    }

    if (
      summary.courses_missing_hours
      > 0
    ) {
      warnings.push(
        `${summary.courses_missing_hours} مساقًا في الخطة بلا ساعات مسجّلة، `
        +
        'فلا تدخل الحساب. أبلغ المشرف ليُكملها.'
      );
    }

    $('pfWarnings').innerHTML =
      warnings
        .map(
          text => `
            <div class="pf-warn">
              <i
                data-icon="warning"
              ></i>

              <span>
                ${esc(text)}
              </span>
            </div>
          `
        )
        .join('');

    $('pfYears').innerHTML =
      (
        summary.by_year
        || []
      )
        .map(
          year => `
            <div class="pf-year">
              <div
                class="pf-year-top"
              >
                <b>
                  السنة ${
                    esc(
                      YEAR_NAMES[
                        year.year
                      ]
                      || year.year
                    )
                  }
                </b>

                <span>
                  ${
                    esc(
                      year.completed_hours
                    )
                  }
                  /
                  ${
                    esc(
                      year.plan_hours
                    )
                  }
                  ساعة
                </span>
              </div>

              <div
                class="pf-bar sm"
              >
                <i
                  style="
                    width:${
                      Number(
                        year.percent
                      ) || 0
                    }%
                  "
                ></i>
              </div>
            </div>
          `
        )
        .join('');
  }

  /*
   * عدّاد المعدل التراكمي (راجع gpa-meter.js) — ملف مستقل يحسب
   * ويرسم، وهاي الدالة بس تجهّز مدخلاته من البيانات المحمَّلة أصلًا
   * (catalog, summary, gpaGrades) وتحدّث نصوص البطاقة حولها.
   */
  function renderGpaMeter() {
    /*
     * لو ملف gpa-meter.js ما انرفع صح على السيرفر (أو الاسم اتغيّر)
     * window.PTCGpaMeter بيضل غير معرّف — بدل ما نسكت ونخلي البطاقة
     * عالقة على "جارٍ حساب معدلك..." للأبد، نوضّح المشكلة بالنص نفسه
     * عشان يصير واضح إنه في ملف ناقص لا خطأ بالحساب.
     */
    if (
      !window.PTCGpaMeter
    ) {
      const fallbackHeadline = $('pfGpaHeadline');
      if (fallbackHeadline) {
        fallbackHeadline.textContent = 'تعذّر تحميل عدّاد المعدل';
      }
      const fallbackSub = $('pfGpaSub');
      if (fallbackSub) {
        fallbackSub.textContent = 'تأكّد من رفع ملف gpa-meter.js على السيرفر بجانب باقي ملفات الموقع.';
      }
      return;
    }

    const stats =
      PTCGpaMeter.compute(
        catalog,
        gpaGrades,
        summary
      );

    PTCGpaMeter.renderDial(
      $('pfGpaDial'),
      stats.avg
    );

    const headline =
      $('pfGpaHeadline');

    if (headline) {
      headline.textContent =
        stats.avg === null
          ? 'لسا ما أدخلت أي علامة'
          : `معدلك التراكمي الحالي: ${Math.round(stats.avg * 10) / 10} من 100`;
    }

    const sub =
      $('pfGpaSub');

    if (sub) {
      /*
       * هون فقط (لا بالعنوان أعلاه) نستخدم innerHTML بدل textContent
       * عشان نقدر ندرج أيقونة statusIcon() قبل النص — كل القيم
       * المُدرَجة (statusLabel النصي الصرف + أرقام الساعات) ثابتة
       * ومن مصادرنا نفسها، بلا أي إدخال من المستخدم، فما في خطر حقن.
       */
      sub.innerHTML =
        stats.avg === null
          ? 'افتح الحاسبة وسجّل علاماتك عشان تشوف معدلك وتجرّب "ماذا لو؟" لعلامات مستقبلية.'
          : `<i data-icon="${PTCGpaMeter.statusIcon(stats.avg)}"></i> ${PTCGpaMeter.statusLabel(stats.avg)} — بناءً على ${stats.gradedHours} ساعة مُعلَّمة${stats.totalHours ? ` من ${stats.totalHours}` : ''}.`;
    }
  }

  /* ───── بياناتي ───── */

  function renderIdentity(
    user
  ) {
    const name =
      (
        user
        && user.full_name
      )
      ||
      (
        user
        && user.email
      )
      ||
      'حسابي';

    $('pfName').textContent =
      name;

    const parts = [
      user && user.email,
    ];

    if (
      user
      && user.year
    ) {
      parts.push(
        'السنة '
        +
        (
          YEAR_NAMES[
            user.year
          ]
          || user.year
        )
      );
    }

    if (
      user
      && user.current_term
    ) {
      parts.push(
        user.current_term.label
      );
    }

    $('pfMeta').textContent =
      parts
        .filter(Boolean)
        .join(' · ');

    $('pfYear').value =
      (
        user
        && user.year
      )
        ? String(user.year)
        : '';

    $('pfTerm').value =
      (
        user
        && user.current_term_id
      )
        ? String(
            user.current_term_id
          )
        : '';
  }

  function semesterNumber(
    term
  ) {
    const direct =
      Number(
        term
        && term.semester
      );

    if (
      direct === 1
      || direct === 2
    ) {
      return direct;
    }

    const label =
      String(
        (
          term
          && term.label
        )
        || ''
      );

    if (
      label.includes(
        'الفصل الأول'
      )
    ) {
      return 1;
    }

    if (
      label.includes(
        'الفصل الثاني'
      )
    ) {
      return 2;
    }

    return 0;
  }

  function academicYear(
    term
  ) {
    const label =
      String(
        (
          term
          && term.label
        )
        || ''
      );

    const match =
      label.match(
        /(\d{4})\s*\/\s*(\d{4})/
      );

    return match
      ? Number(match[1])
      : 0;
  }

  function semesterForTermId(
    termId
  ) {
    if (!termId) {
      return null;
    }

    return (
      termSemesterById.get(
        String(termId)
      )
      || null
    );
  }

  async function loadTerms(
    user
  ) {
    try {
      const response =
        await PTCAuth.getTerms();

      const terms =
        response.data || [];

      const regularTerms =
        terms.filter(term => {
          const semester =
            semesterNumber(term);

          return (
            semester === 1
            || semester === 2
          );
        });

      const latestYear =
        regularTerms.reduce(
          (
            max,
            term
          ) =>
            Math.max(
              max,
              academicYear(term)
            ),
          0
        );

      const visibleTerms =
        regularTerms
          .filter(
            term =>
              latestYear === 0
              ||
              academicYear(term)
                === latestYear
          )
          .sort(
            (
              a,
              b
            ) =>
              semesterNumber(a)
              -
              semesterNumber(b)
          );

      termSemesterById.clear();

      visibleTerms.forEach(
        term => {
          termSemesterById.set(
            String(term.id),
            semesterNumber(term)
          );
        }
      );

      $('pfTerm').innerHTML =
        visibleTerms
          .map(term => {
            const semester =
              semesterNumber(
                term
              );

            const label =
              semester === 1
                ? 'الفصل الأول'
                : 'الفصل الثاني';

            return `
              <option
                value="${Number(
                  term.id
                )}"
              >
                ${label}
              </option>
            `;
          })
          .join('');

      const currentId =
        (
          user
          && user.current_term_id
        )
          ? String(
              user.current_term_id
            )
          : '';

      if (
        currentId
        &&
        visibleTerms.some(
          term =>
            String(term.id)
            === currentId
        )
      ) {
        $('pfTerm').value =
          currentId;

        openSemester =
          semesterForTermId(
            currentId
          );
      } else {
        $('pfTerm').value =
          '';

        if (!currentId) {
          openSemester =
            null;
        }
      }
    } catch (error) {
      console.error(
        'تعذّر تحميل الفصول:',
        error
      );
    }
  }

  /**
   * حفظ السنة أو الفصل.
   *
   * الباك يقوم بالتسجيل التلقائي،
   * وبعده نعيد الخطة والحالات.
   */
  async function saveField(
    field,
    value
  ) {
    hint(
      'جارٍ الحفظ...'
    );

    try {
      const saved =
        await PTCAuth.updateProfile({
          [field]: value,
        });

      renderIdentity(
        saved.user
      );

      if (
        field === 'year'
      ) {
        openYear =
          value
            ? Number(value)
            : null;
      }

      if (
        field
        === 'current_term_id'
      ) {
        openSemester =
          value
            ? semesterForTermId(
                value
              )
            : null;
      }

      if (
        field === 'year'
        ||
        field
          === 'current_term_id'
      ) {
        await reloadSummary();

        if (
          summary
          && summary.current_term
          && !openSemester
        ) {
          openSemester =
            semesterNumber(
              summary.current_term
            )
            || null;
        }

        renderPlan();
      }

      hint(
        'تم الحفظ.',
        'ok'
      );
    } catch (error) {
      hint(
        error.message
        || 'تعذّر الحفظ.',
        'bad'
      );
    }
  }

  /* ───── حالة المساق ───── */

  async function reloadSummary() {
    const fresh =
      await PTCAuth
        .getPlanSummary();

    summary =
      fresh;

    statuses =
      fresh.statuses || {};

    renderSummary();
  }

  async function applyStatus(
    key,
    value
  ) {
    if (
      value === 'none'
    ) {
      await PTCAuth
        .removeMyCourse(
          key
        );

      delete statuses[key];

      return;
    }

    if (!statuses[key]) {
      await PTCAuth
        .addMyCourse(
          key
        );
    }

    statuses[key] =
      await PTCAuth
        .setCourseStatus(
          key,
          value
        );
  }

  function changeStatus(
    key,
    value
  ) {
    if (!key) {
      return;
    }

    pending.set(
      key,
      value
    );

    drain();
  }

  async function drain() {
    if (draining) {
      return;
    }

    draining =
      true;

    const failures =
      [];

    try {
      while (
        pending.size
      ) {
        while (
          pending.size
        ) {
          const [
            key,
            value,
          ] =
            pending
              .entries()
              .next()
              .value;

          pending.delete(
            key
          );

          hint(
            pending.size
              ? `جارٍ حفظ الحالات... (${pending.size + 1} متبقية)`
              : 'جارٍ حفظ حالة المساق...'
          );

          try {
            await applyStatus(
              key,
              value
            );
          } catch (error) {
            failures.push(
              error
            );
          }
        }

        try {
          await reloadSummary();
        } catch (error) {
          failures.push(
            error
          );
        }
      }
    } finally {
      draining =
        false;
    }

    if (
      failures.length
    ) {
      renderPlan();

      hint(
        failures[0].message
        || 'تعذّر حفظ الحالة.',
        'bad'
      );

      return;
    }

    renderPlan();

    hint(
      'تم حفظ الحالة.',
      'ok'
    );
  }

  async function pickElective(
    key
  ) {
    hint(
      'جارٍ إضافة المادة...'
    );

    try {
      if (!statuses[key]) {
        await PTCAuth
          .addMyCourse(
            key
          );
      }

      statuses[key] =
        await PTCAuth
          .setCourseStatus(
            key,
            'registered'
          );

      await reloadSummary();

      renderPlan();

      hint(
        'تمت الإضافة.',
        'ok'
      );
    } catch (error) {
      hint(
        error.message
        || 'تعذّر إضافة المادة.',
        'bad'
      );
    }
  }

  async function changeElective(
    key
  ) {
    hint(
      'جارٍ تغيير المادة...'
    );

    try {
      await PTCAuth
        .removeMyCourse(
          key
        );

      delete statuses[key];

      await reloadSummary();

      renderPlan();

      hint(
        'اختر المادة الجديدة الآن.',
        'ok'
      );
    } catch (error) {
      hint(
        error.message
        || 'تعذّر تغيير المادة.',
        'bad'
      );
    }
  }

  function renderPlan() {
    PTCPlanView.renderPlan(
      'pfPlan',
      {
        courses:
          catalog,

        statuses:
          statuses,

        editable:
          true,

        openYear:
          openYear,

        openSemester:
          openSemester,

        onStatusChange:
          changeStatus,

        onElectivePick:
          pickElective,

        onElectiveChange:
          changeElective,
      }
    );
  }

  function renderTree() {
    PTCPlanView.renderTree(
      'pfPlanTree',
      {
        courses:
          catalog,
      }
    );
  }

  /*
   * تبديل بين عرض القائمة (الجدول القابل للتعديل) وعرض الشجرة (خريطة
   * المتطلبات التفاعلية). الشجرة تُبنى أول مرة تُفتح بس — لا فائدة من
   * حسابها قبل ما يطلبها الطالب أصلًا.
   */
  let treeBuilt = false;

  window.switchPlanView = function (mode) {
    const isTree = mode === 'tree';

    document.getElementById('pfPlan').style.display =
      isTree ? 'none' : '';

    document.getElementById('pfPlanTree').style.display =
      isTree ? '' : 'none';

    document.getElementById('pfLegend').style.display =
      isTree ? 'none' : '';

    document.getElementById('pfFootNote').style.display =
      isTree ? 'none' : '';

    document.getElementById('pfViewListBtn')
      .classList.toggle('active', !isTree);

    document.getElementById('pfViewTreeBtn')
      .classList.toggle('active', isTree);

    if (isTree && !treeBuilt) {
      treeBuilt = true;
      renderTree();
    }
  };

  /* ───── الإقلاع ───── */

  async function boot() {
  if (
    typeof PTCAuth === 'undefined'
  ) {
    return;
  }

  if (
    !await PTCAuth.requireAuth(
      'login.html'
    )
  ) {
    return;
  }

  const user =
    PTCAuth.user;

  /*
   * حساب المدير الرئيسي ليس طالبًا فعليًا — لا سنة له ولا خطة دراسية
   * ولا فائدة من فرض اختيارها عليه. نعرض له بديلًا بسيطًا ونتوقف هنا،
   * بدل تحميل لوحة أكاديمية كاملة (نسبة إنجاز، ساعات معتمدة، جدول
   * خطة) لا معنى لأي رقم فيها بالنسبة له.
   */
  if (
    String(user?.email || '')
      .toLowerCase()
      .trim()
      === 'ptchub.duckdns.org@gmail.com'
  ) {
    renderIdentity(user);

    const grid = document.querySelector('.pf-grid');

    if (grid) {
      grid.innerHTML = `
        <div class="pf-card" style="grid-column:1/-1;text-align:center;padding:40px 24px">
          <h3 style="margin-bottom:10px">حساب المدير الرئيسي</h3>
          <p style="color:var(--muted);font-family:var(--font-ar);line-height:1.9">
            هذا الحساب إداري ولا يتبع خطة دراسية — لا سنة ولا فصل ولا
            نسبة إنجاز تخصّه. لإدارة الموقع، استخدم
            <a href="admin.html">لوحة التحكم</a>.
          </p>
        </div>
      `;
    }

    const yearsCard = document.querySelector('.pf-years-card');
    if (yearsCard) yearsCard.style.display = 'none';

    const fullPlanSection = document.getElementById('pfFullPlanSection');
    if (fullPlanSection) fullPlanSection.style.display = 'none';

    return;
  }

  /*
   * السنة المفتوحة تلقائيًا.
   */
  openYear =
    (
      user
      && user.year
    )
      ? Number(user.year)
      : null;

  /*
   * الفصل المفتوح تلقائيًا.
   */
  openSemester =
    (
      user
      && user.current_term
    )
      ? semesterNumber(
          user.current_term
        )
      : null;

  renderIdentity(
    user
  );

  /*
   * تغيير السنة.
   */
  $('pfYear').onchange =
    event => {
      const value =
        event.target.value
          ? Number(
              event.target.value
            )
          : null;

      saveField(
        'year',
        value
      );
    };

  /*
   * تغيير الفصل.
   */
  $('pfTerm').onchange =
    event => {
      const value =
        event.target.value
          ? Number(
              event.target.value
            )
          : null;

      saveField(
        'current_term_id',
        value
      );
    };

  /*
   * نحمل الفصول أولًا،
   * حتى نعرف رقم الفصل الحالي.
   */
  await loadTerms(
    user
  );

  /*
   * إذا الفصل محفوظ عند المستخدم
   * نحدد أي قائمة فرعية يجب فتحها.
   */
  if (
    user
    && user.current_term_id
  ) {
    openSemester =
      semesterForTermId(
        user.current_term_id
      )
      || openSemester;
  }

  /*
   * بعدها نحمل:
   * - كتالوج المساقات
   * - حالات الطالب
   */
  try {
    const [
      courses,
      plan,
      gpa,
    ] =
      await Promise.all([
        PTCAuth.getCourses(),
        PTCAuth.getPlanSummary(),
        /*
         * غير حرج لبقية الصفحة — لو فشل تحميله (خطأ شبكة عابر) ما
         * لازم يمنع تحميل الخطة كاملها، فقط عدّاد المعدل يضل فاضي.
         */
        PTCAuth.getMyGpa().catch(() => ({})),
      ]);

    catalog =
      courses || [];

    summary =
      plan || null;

    gpaGrades =
      gpa || {};

    statuses =
      (
        plan
        && plan.statuses
      )
        ? plan.statuses
        : {};

    /*
     * لو الفصل لم نعرفه من user،
     * نحاول أخذه من الملخص.
     */
    if (
      !openSemester
      && plan
      && plan.current_term
    ) {
      openSemester =
        semesterNumber(
          plan.current_term
        )
        || null;
    }

    renderSummary();
    renderPlan();
    renderGpaMeter();

    /*
     * في حال user المخزن محليًا
     * لا يحتوي current_term كاملًا.
     */
    if (
      plan
      && plan.current_term
      && user
      && !user.current_term
    ) {
      renderIdentity(
        Object.assign(
          {},
          user,
          {
            current_term:
              plan.current_term,
          }
        )
      );
    }

  } catch (error) {
    console.error(
      'تعذّر تحميل الخطة:',
      error
    );

    const planNode =
      $('pfPlan');

    if (planNode) {
      planNode.innerHTML =
        '<div class="plan-empty">'
        +
        'تعذّر تحميل الخطة الدراسية. '
        +
        'تأكد من الاتصال ثم أعد تحميل الصفحة.'
        +
        '</div>';
    }

    const headline =
      $('pfHeadline');

    if (headline) {
      headline.textContent =
        'تعذّر حساب التقدّم.';
    }
  }
}

window.addEventListener(
  'DOMContentLoaded',
  boot
);

})();