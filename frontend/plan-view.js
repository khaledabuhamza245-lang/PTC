/* رسم الخطة الأكاديمية — سنة ← فصل ← مساق. */
const PTCPlanView = (function () {
  const YEAR_LABELS = [
    '',
    'السنة الأولى',
    'السنة الثانية',
    'السنة الثالثة',
    'السنة الرابعة',
  ];

  const STATUS = {
    completed: {
      label: 'منجز',
      cls: 'done',
    },

    registered: {
      label: 'جارٍ',
      cls: 'doing',
    },

    dropped: {
      label: 'منسحب',
      cls: 'dropped',
    },

    none: {
      label: 'متبقٍ',
      cls: 'none',
    },
  };

  const esc = value =>
    (
      typeof PTCUtils !== 'undefined'
      && PTCUtils.escapeHTML
    )
      ? PTCUtils.escapeHTML(value)
      : String(
          value == null ? '' : value
        )
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');

  const ref = value =>
    (
      typeof PTCUtils !== 'undefined'
      && PTCUtils.safeRef
    )
      ? PTCUtils.safeRef(value)
      : (
          /^[A-Za-z0-9_-]{1,80}$/.test(
            String(value || '').trim()
          )
            ? String(value).trim()
            : null
        );

  const isElective = course =>
    course.course_type === 'elective'
    ||
    (
      !course.course_type
      &&
      String(
        course.page || ''
      ).toLowerCase() === 'electives.html'
    );

  const isPlaceholder = course =>
    course.course_type === 'placeholder'
    ||
    String(
      course.code || ''
    ).toUpperCase() === 'EEEX 35XX';

  const hours = course => {
    const value =
      Number(course.credit_hours);

    return Number.isFinite(value)
      ? value
      : 0;
  };

  function prerequisitesOf(course) {
    const brief =
      course.prerequisites_brief;

    if (
      Array.isArray(brief)
      && brief.length
    ) {
      return brief.map(row => ({
        code: String(
          row.code == null
            ? ''
            : row.code
        ),

        name: String(
          row.name == null
          || row.name === ''
            ? row.code
            : row.name
        ),
      }));
    }

    return (
      course.prerequisite_codes || []
    ).map(code => ({
      code: String(code),
      name: String(code),
    }));
  }

  function statusOf(
    course,
    statuses
  ) {
    const row =
      statuses
      && statuses[course.key];

    const value =
      row && row.status;

    return STATUS[value]
      ? value
      : 'none';
  }

  function statusClass(value) {
    return (
      STATUS[value]
      || STATUS.none
    ).cls;
  }

  function statusLabel(value) {
    return (
      STATUS[value]
      || STATUS.none
    ).label;
  }

  /*
   * Course.semester = 1..8
   *
   * الفردي:
   * الفصل الأول
   *
   * الزوجي:
   * الفصل الثاني
   */
  function semesterLabel(course) {
    if (isElective(course)) {
      return 'اختياري';
    }

    return (
      Number(course.semester) % 2 === 1
    )
      ? 'الفصل الأول'
      : 'الفصل الثاني';
  }

  function localSemesterNumber(label) {
    const value =
      String(label || '');

    if (
      value.includes('الفصل الأول')
    ) {
      return 1;
    }

    if (
      value.includes('الفصل الثاني')
    ) {
      return 2;
    }

    return 0;
  }

  function group(
    courses,
    options
  ) {
    const labelOf =
      (
        options
        && options.semesterLabel
      )
      || semesterLabel;

    const years =
      new Map();

    const electives =
      [];

    (courses || []).forEach(course => {
      if (isElective(course)) {
        electives.push(course);
        return;
      }

      const year =
        Number(course.year) || 0;

      if (!years.has(year)) {
        years.set(
          year,
          new Map()
        );
      }

      const label =
        labelOf(course);

      const semesters =
        years.get(year);

      if (!semesters.has(label)) {
        semesters.set(
          label,
          []
        );
      }

      semesters
        .get(label)
        .push(course);
    });

    const output =
      [...years.keys()]
        .sort(
          (a, b) => a - b
        )
        .map(year => {
          const semesterGroups =
            [...years
              .get(year)
              .entries()
            ]
              .map(
                ([label, list]) => ({
                  label,

                  semester:
                    localSemesterNumber(
                      label
                    ),

                  courses:
                    list,

                  hours:
                    list.reduce(
                      (
                        sum,
                        course
                      ) =>
                        sum
                        + hours(course),
                      0
                    ),
                })
              )
              .sort((a, b) => {
                const av =
                  a.semester || 99;

                const bv =
                  b.semester || 99;

                return av - bv;
              });

          return {
            year,

            label:
              YEAR_LABELS[year]
              || ('السنة ' + year),

            groups:
              semesterGroups,
          };
        });

    if (electives.length) {
      output.push({
        year: 0,

        label:
          'المساقات الاختيارية',

        elective:
          true,

        groups: [{
          label:
            'اختر منها حسب سقف الخطة',

          semester:
            0,

          courses:
            electives,

          hours:
            electives.reduce(
              (
                sum,
                course
              ) =>
                sum
                + hours(course),
              0
            ),
        }],
      });
    }

    return output;
  }

  /*
   * بدون سنة محددة:
   * كل السنوات مقفلة.
   */
  function openIndex(
    groups,
    openYear
  ) {
    const wanted =
      Number(openYear);

    if (
      !Number.isFinite(wanted)
      || wanted <= 0
    ) {
      return -1;
    }

    return groups.findIndex(
      entry =>
        entry.year === wanted
    );
  }

  function statusCell(
    course,
    current,
    editable
  ) {
    const safeKey =
      ref(course.key);

    if (
      !editable
      || !safeKey
      || isPlaceholder(course)
    ) {
      return `
        <span
          class="plan-status ${statusClass(current)}"
        >
          ${esc(statusLabel(current))}
        </span>
      `;
    }

    const optionsHtml =
      Object.keys(STATUS)
        .map(
          safeValue => `
            <option
              value="${safeValue}"
              ${
                safeValue === current
                  ? 'selected'
                  : ''
              }
            >
              ${esc(
                statusLabel(
                  safeValue
                )
              )}
            </option>
          `
        )
        .join('');

    return `
      <select
        class="plan-status-pick ${statusClass(current)}"
        data-plan-key="${safeKey}"
        aria-label="حالة ${esc(
          course.name_ar
          || course.code
        )}"
      >
        ${optionsHtml}
      </select>
    `;
  }

  function renderCourseRows(
    sem,
    statuses,
    editable,
    electiveCtx
  ) {
    return sem.courses
      .map(courseIn => {
        let course =
          courseIn;

        let pickerHtml =
          null;

        let lockedHtml =
          null;

        if (
          isPlaceholder(courseIn)
          && electiveCtx
        ) {
          const isUnlocked =
            electiveCtx.currentYear
            &&
            electiveCtx.currentPlanSemester
            &&
            Number(courseIn.year)
              === electiveCtx.currentYear
            &&
            Number(courseIn.semester)
              === electiveCtx.currentPlanSemester;

          if (!isUnlocked) {
            lockedHtml = `
              <span
                class="plan-status none"
                title="تُفتح هذه الخانة عند وصولك لهذا الفصل ضمن خطتك"
              >
                <i data-icon="lock"></i> تُفتح عند الوصول لهذا الفصل
              </span>
            `;
          } else if (
            electiveCtx.cursor.i
            < electiveCtx.chosen.length
          ) {
            course =
              electiveCtx.chosen[
                electiveCtx.cursor.i
              ];

            electiveCtx.cursor.i++;
          } else {
            electiveCtx.cursor.i++;

            const options =
              electiveCtx.available
                .map(opt => `
                  <option value="${esc(opt.key)}">
                    ${esc(opt.code)} — ${esc(
                      opt.name_ar
                      || opt.name_en
                      || ''
                    )}
                  </option>
                `)
                .join('');

            pickerHtml = `
              <select
                class="plan-elective-pick"
                ${editable ? '' : 'disabled'}
              >
                <option value="">
                  اختر مادة اختيارية لهذه الخانة
                </option>
                ${options}
              </select>
            `;
          }
        }

        const current =
          statusOf(
            course,
            statuses
          );

        const before =
          prerequisitesOf(course);

        const preHtml =
          before.length
            ? before.map(
                item => `
                  <span
                    class="plan-pre-name"
                    title="${esc(item.code)}"
                  >
                    ${esc(item.name)}
                  </span>
                `
              ).join('، ')
            : `
                <span class="plan-none">
                  —
                </span>
              `;

        const hoursHtml =
          course.credit_hours == null
            ? `
                <span
                  class="plan-none"
                  title="الساعات غير مسجّلة"
                >
                  ؟
                </span>
              `
            : esc(
                course.credit_hours
              );

        const stateHtml =
          lockedHtml
            ? lockedHtml
            : pickerHtml
              ? `
                  <span class="plan-status none">
                    فارغة
                  </span>
                `
              : statusCell(
                  course,
                  current,
                  editable
                );

        if (lockedHtml) {
          return `
            <tr class="plan-row none plan-slot plan-slot-locked">
              <td class="plan-code">
                <code>
                  ${esc(
                    courseIn.code || ''
                  )}
                </code>
              </td>

              <td class="plan-name" colspan="3">
                <span class="plan-none">
                  مادة اختيارية —
                  ${esc(
                    courseIn.name_ar
                    || 'خانة اختيارية'
                  )}
                </span>
              </td>

              <td class="plan-state">
                ${stateHtml}
              </td>
            </tr>
          `;
        }

        if (pickerHtml) {
          return `
            <tr class="plan-row none plan-slot plan-slot-open">
              <td class="plan-code">
                <code>
                  ${esc(
                    courseIn.code || ''
                  )}
                </code>
              </td>

              <td class="plan-name" colspan="3">
                ${pickerHtml}
              </td>

              <td class="plan-state">
                ${stateHtml}
              </td>
            </tr>
          `;
        }

        return `
          <tr
            class="
              plan-row
              ${statusClass(current)}
              ${
                isPlaceholder(courseIn)
                  ? 'plan-slot plan-slot-filled'
                  : ''
              }
            "
          >
            <td class="plan-code">
              <code>
                ${esc(
                  course.code || ''
                )}
              </code>
            </td>

            <td class="plan-name">
              <b>
                ${esc(
                  course.name_ar
                  || course.name_en
                  || ''
                )}
              </b>

              <small dir="ltr">
                ${esc(
                  course.name_en
                  || ''
                )}
              </small>

              ${
                isPlaceholder(courseIn)
                  ? `
                      <button
                        type="button"
                        class="plan-elective-change"
                        data-plan-key="${esc(course.key)}"
                      >
                        <i data-icon="refresh"></i> تغيير المادة الاختيارية
                      </button>
                    `
                  : ''
              }
            </td>

            <td class="plan-hours">
              ${hoursHtml}
            </td>

            <td class="plan-pre">
              ${preHtml}
            </td>

            <td class="plan-state">
              ${stateHtml}
            </td>
          </tr>
        `;
      })
      .join('');
  }

  function renderSemester(
    sem,
    statuses,
    editable,
    shouldOpen,
    electiveCtx
  ) {
    const rowsHtml =
      renderCourseRows(
        sem,
        statuses,
        editable,
        electiveCtx
      );

    return `
      <div
        class="
          plan-sem
          ${shouldOpen ? 'open' : ''}
        "
        data-plan-semester="${esc(
          sem.semester || ''
        )}"
      >
        <div
          class="plan-sem-head"
          data-plan-sem-head
          role="button"
          tabindex="0"
          aria-expanded="${
            shouldOpen
              ? 'true'
              : 'false'
          }"
          style="cursor:pointer"
        >
          <span>
            ${esc(sem.label)}
          </span>

          <em>
            ${esc(sem.hours)} ساعة ·
            ${esc(
              sem.courses.length
            )} مساق
          </em>

          ${
            editable
              ? `
                <button
                  type="button"
                  class="plan-bulk-btn"
                  data-plan-bulk="sem"
                  title="حدّد كل مساقات هذا الفصل كمنجزة دفعة واحدة"
                >
                  <i data-icon="check"></i> منجز الكل
                </button>
              `
              : ''
          }

          <span
            class="plan-sem-chev"
            aria-hidden="true"
          >
            ▼
          </span>
        </div>

        <div
          class="plan-sem-body"
          ${
            shouldOpen
              ? ''
              : 'hidden'
          }
        >
          <div
            class="plan-table-scroll"
          >
            <table
              class="plan-table"
            >
              <thead>
                <tr>
                  <th>الرمز</th>
                  <th>المساق</th>
                  <th>س.م</th>
                  <th>متطلب سابق</th>
                  <th>الحالة</th>
                </tr>
              </thead>

              <tbody>
                ${rowsHtml}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;
  }

  function renderPlan(
    target,
    options
  ) {
    const node =
      typeof target === 'string'
        ? document.getElementById(
            target
          )
        : target;

    if (!node) {
      return;
    }

    const opts =
      options || {};

    /*
     * كل تغيير حالة (منجز/جارٍ...) يعيد رسم الخطة كاملة، وبدون هذا
     * الحفظ كانت تُغلق كل قسم فتحه الطالب يدويًا وترجع تلقائيًا لسنته
     * وفصله المُعلَنين بالبروفايل بدل ما تبقى مفتوحة عند ما تركها هو.
     * نحفظ قيمتي data-plan-year / data-plan-semester كما هي (بأي
     * ترقيم داخلي تُخزَّن فيه) لنعيد فتح نفس القسمين بعد إعادة الرسم،
     * دون التدخل في حسابات opts.openYear/openSemester نفسها.
     */
    const openYearEl =
      node.querySelector(
        '.plan-year.open'
      );

    const openSemEl =
      openYearEl
      && openYearEl.querySelector(
        '.plan-sem.open'
      );

    const preservedYear =
      openYearEl
      && openYearEl.dataset.planYear;

    const preservedSemester =
      openSemEl
      && openSemEl.dataset.planSemester;

    const statuses =
      opts.statuses || {};

    const editable =
      opts.editable !== false;

    const allGroups =
      group(
        opts.courses,
        opts
      );

    /*
     * الاختياريات صارت تُعرض داخل خانتها بكل فصل لا في قسم منفصل
     * بآخر الخطة — نسحب مجمّعها هون فقط لبناء «مين مختارة ومين
     * متاحة»، وما نعرضه كقسم قائم بذاته.
     */
    const electiveBucket =
      allGroups.find(
        entry => entry.elective
      );

    const electivePool =
      electiveBucket
        ? electiveBucket.groups[0].courses
        : [];

    const groups =
      allGroups.filter(
        entry => !entry.elective
      );

    const isChosenElective =
      course => {
        const value =
          statusOf(course, statuses);

        return (
          value === 'registered'
          || value === 'completed'
        );
      };

    const currentYear =
      Number(opts.openYear) || 0;

    const currentSemesterLocal =
      Number(opts.openSemester) || 0;

    /*
     * نفس معادلة الباك إند بالضبط (CurrentCoursesSyncService):
     * رقم الفصل العالمي = ((السنة-١)×٢) + رقم الفصل المحلي (١ أو ٢).
     * هيك نعرف بالضبط أي خانة اختيارية «مفتوحة» الآن.
     */
    const currentPlanSemester =
      (currentYear && currentSemesterLocal)
        ? ((currentYear - 1) * 2)
          + currentSemesterLocal
        : 0;

    const electiveCtx = {
      chosen:
        electivePool.filter(
          isChosenElective
        ),

      available:
        electivePool.filter(
          course =>
            !isChosenElective(course)
        ),

      cursor: { i: 0 },

      currentYear,

      currentPlanSemester,
    };

    if (!groups.length) {
      node.innerHTML =
        '<div class="plan-empty">تعذّر تحميل الخطة الدراسية.</div>';

      return;
    }

    const openAt =
      openIndex(
        groups,
        opts.openYear
      );

    const wantedSemester =
      Number(
        opts.openSemester
      ) || 0;

    node.innerHTML =
      groups
        .map(
          (year, index) => {
            const isYearOpen =
              index === openAt;

            const bodyHtml =
              year.groups
                .map(sem => {
                  const shouldOpenSemester =
                    isYearOpen
                    && !year.elective
                    && wantedSemester > 0
                    && Number(
                      sem.semester
                    ) === wantedSemester;

                  return renderSemester(
                    sem,
                    statuses,
                    editable,
                    shouldOpenSemester,
                    electiveCtx
                  );
                })
                .join('');

            return `
              <section
                class="
                  plan-year
                  ${
                    isYearOpen
                      ? 'open'
                      : ''
                  }
                  ${
                    year.elective
                      ? 'plan-year-elective'
                      : ''
                  }
                "
                data-plan-year="${esc(
                  year.year
                )}"
              >
                <div
                  class="plan-year-head"
                  data-plan-year-head
                  role="button"
                  tabindex="0"
                  aria-expanded="${
                    isYearOpen
                      ? 'true'
                      : 'false'
                  }"
                >
                  <b>
                    ${esc(year.label)}
                  </b>

                  <em>
                    ${esc(
                      year.groups.reduce(
                        (
                          sum,
                          sem
                        ) =>
                          sum
                          + sem.hours,
                        0
                      )
                    )} ساعة
                  </em>

                  ${
                    editable
                      ? `
                        <button
                          type="button"
                          class="plan-bulk-btn"
                          data-plan-bulk="year"
                          title="حدّد كل مساقات هذه السنة كمنجزة دفعة واحدة"
                        >
                          <i data-icon="check"></i> منجز الكل
                        </button>
                      `
                      : ''
                  }

                  <span class="chev">
                    ▼
                  </span>
                </div>

                <div
                  class="plan-year-body"
                >
                  ${bodyHtml}
                </div>
              </section>
            `;
          }
        )
        .join('');

    /*
     * نعيد فتح نفس السنة والفصل اللي كانا مفتوحين قبل إعادة الرسم،
     * إن وُجدا، بدل الاكتفاء بما حسبته القيم الافتراضية فوق.
     */
    if (preservedYear) {
      node
        .querySelectorAll('.plan-year.open')
        .forEach(section => {
          section.classList.remove('open');

          const head =
            section.querySelector(
              '.plan-year-head'
            );

          if (head) {
            head.setAttribute(
              'aria-expanded',
              'false'
            );
          }
        });

      const yearSection =
        [...node.querySelectorAll(
          '.plan-year'
        )].find(
          section =>
            section.dataset.planYear
            === preservedYear
        );

      if (yearSection) {
        yearSection.classList.add(
          'open'
        );

        const yearHead =
          yearSection.querySelector(
            '.plan-year-head'
          );

        if (yearHead) {
          yearHead.setAttribute(
            'aria-expanded',
            'true'
          );
        }

        if (preservedSemester) {
          yearSection
            .querySelectorAll('.plan-sem.open')
            .forEach(sem => {
              sem.classList.remove('open');

              const head =
                sem.querySelector(
                  '[data-plan-sem-head]'
                );

              if (head) {
                head.setAttribute(
                  'aria-expanded',
                  'false'
                );
              }

              const body =
                sem.querySelector(
                  '.plan-sem-body'
                );

              if (body) {
                body.hidden = true;
              }
            });

          const semSection =
            [...yearSection.querySelectorAll(
              '.plan-sem'
            )].find(
              sem =>
                sem.dataset.planSemester
                === preservedSemester
            );

          if (semSection) {
            semSection.classList.add(
              'open'
            );

            const semHead =
              semSection.querySelector(
                '[data-plan-sem-head]'
              );

            if (semHead) {
              semHead.setAttribute(
                'aria-expanded',
                'true'
              );
            }

            const semBody =
              semSection.querySelector(
                '.plan-sem-body'
              );

            if (semBody) {
              semBody.hidden = false;
            }
          }
        }
      }
    }

    function toggleYear(head) {
      const section =
        head.closest(
          '.plan-year'
        );

      if (!section) {
        return;
      }

      const willOpen =
        !section.classList
          .contains('open');

      section.classList.toggle(
        'open',
        willOpen
      );

      head.setAttribute(
        'aria-expanded',
        String(willOpen)
      );
    }

    function toggleSemester(
      head
    ) {
      const section =
        head.closest(
          '.plan-sem'
        );

      if (!section) {
        return;
      }

      const body =
        section.querySelector(
          '.plan-sem-body'
        );

      const willOpen =
        !section.classList
          .contains('open');

      section.classList.toggle(
        'open',
        willOpen
      );

      head.setAttribute(
        'aria-expanded',
        String(willOpen)
      );

      if (body) {
        body.hidden =
          !willOpen;
      }
    }

    /*
     * زر "منجز الكل" (سنة كاملة أو فصل واحد) — يقرأ كل خانات
     * ".plan-status-pick" الفعلية بنفس نطاق السنة/الفصل (تستثني
     * تلقائيًا أي خانة اختيارية فارغة/مقفلة، لأنها أصلًا لا تملك
     * select حقيقي، راجع statusCell)، ويتجاهل أي مساق "منجز" أصلًا
     * كي لا يرسل نداء حفظ لا داعي له، ثم يمرّ كل واحد عبر نفس
     * opts.onStatusChange المستخدم لكل تغيير فردي — فيستفيد تلقائيًا
     * من نفس آلية الطابور/الحفظ الدفعي (pending/drain) بدون أي حاجة
     * لتعديل منطق الحفظ بملف آخر، وتبقى إمكانية تعديل كل مساق على
     * حدة بعدها شغّالة تمامًا كالمعتاد.
     *
     * الزر أصلًا غير مفعّل افتراضيًا (IsCompleted=false) — ما بيتحول
     * لحالة "منجز" إلا بضغطة يدوية فعلية من الطالب، أو لو كانت كل
     * مساقات النطاق أصلًا منجزة يدويًا واحدًا واحدًا (نفس النتيجة
     * البصرية، لأن الحالة تُحسب ديناميكيًا من واقع حالات المساقات
     * الفعلية لا من أي علم/Flag منفصل مخزّن).
     */
    function countScopeStatuses(scope) {
      const picks =
        [...scope.querySelectorAll('.plan-status-pick')];

      const counts = {
        total: picks.length,
        completed: 0,
        registered: 0,
        dropped: 0,
        none: 0,
      };

      picks.forEach(pick => {
        const value =
          STATUS[pick.value]
            ? pick.value
            : 'none';

        counts[value] =
          (counts[value] || 0) + 1;
      });

      return counts;
    }

    /*
     * تصريف عربي مبسّط لعدد المساقات ضمن فئة معيّنة (منسحب/جارٍ/متبقٍ)
     * — واحد بصيغة مفردة، اثنان بصيغة مثنّى صريحة (زي ما طلب المستخدم
     * بالضبط لحالة "منجز ما عدا مساقين متبقيات")، وثلاثة فأكثر برقم.
     */
    function countedPhrase(count, forms) {
      if (count === 1) return forms.one;
      if (count === 2) return forms.two;
      return count + ' ' + forms.plural;
    }

    const REMAINING_FORMS = {
      one: 'مساق واحد متبقٍ',
      two: 'مساقين متبقيات',
      plural: 'مساقات متبقية',
    };

    const DROPPED_FORMS = {
      one: 'مساق منسحب',
      two: 'مساقين منسحبين',
      plural: 'مساقات منسحبة',
    };

    const REGISTERED_FORMS = {
      one: 'مساق جارٍ',
      two: 'مساقين جاريين',
      plural: 'مساقات جارية',
    };

    /*
     * يبني حالة الزر (state + النص الظاهر + tooltip كامل) اعتمادًا
     * فقط على مصفوفة حالات المساقات الحالية بهذا النطاق — بلا أي علم
     * منفصل محفوظ، فتنعكس أي تعديل يدوي لاحق (تغيير حالة مساق واحد
     * بعد تفعيل "منجز الكل") تلقائيًا بمجرد إعادة رسم/فحص الزر.
     */
    function bulkStateOf(scope) {
      const counts =
        countScopeStatuses(scope);

      if (!counts.total) {
        return { state: 'default', label: 'منجز الكل', title: '' };
      }

      if (counts.completed === counts.total) {
        return {
          state: 'done',
          label: 'منجز الكل',
          title: 'كل مساقات هذا النطاق مسجّلة كمنجزة — اضغط للتراجع',
        };
      }

      if (counts.completed === 0) {
        return {
          state: 'default',
          label: 'منجز الكل',
          title: 'حدّد كل مساقات هذا النطاق كمنجزة دفعة واحدة',
        };
      }

      const onlyRegistered =
        counts.registered > 0
        && counts.dropped === 0
        && counts.none === 0;

      if (onlyRegistered) {
        const verb =
          counts.registered === 1
            ? 'ما زال'
            : 'ما زالت';

        const label =
          'منجز لكن '
          + verb
          + ' '
          + countedPhrase(
              counts.registered,
              REGISTERED_FORMS
            );

        return { state: 'partial', label, title: label };
      }

      const clauses = [];

      if (counts.dropped > 0) {
        clauses.push(
          countedPhrase(
            counts.dropped,
            DROPPED_FORMS
          )
        );
      }

      if (counts.none > 0) {
        clauses.push(
          countedPhrase(
            counts.none,
            REMAINING_FORMS
          )
        );
      }

      if (counts.registered > 0) {
        clauses.push(
          countedPhrase(
            counts.registered,
            REGISTERED_FORMS
          )
        );
      }

      const label =
        'منجز ما عدا '
        + clauses.join(' و');

      return { state: 'partial', label, title: label };
    }

    function renderBulkBtn(bulkBtn) {
      const scopeSelector =
        bulkBtn.dataset.planBulk
          === 'year'
          ? '.plan-year'
          : '.plan-sem';

      const scope =
        bulkBtn.closest(scopeSelector);

      if (!scope) return;

      const info = bulkStateOf(scope);

      bulkBtn.classList.remove(
        'is-done',
        'is-partial'
      );

      if (info.state === 'done') {
        bulkBtn.classList.add('is-done');
      } else if (info.state === 'partial') {
        bulkBtn.classList.add('is-partial');
      }

      bulkBtn.title =
        info.title
        || 'حدّد كل مساقات هذا النطاق كمنجزة دفعة واحدة';

      const iconName =
        info.state === 'done'
          ? 'checkCircle'
          : (
              info.state === 'partial'
                ? 'circleDot'
                : 'check'
            );

      bulkBtn.innerHTML =
        `<i data-icon="${iconName}"></i> ${esc(info.label)}`;

      if (
        typeof renderIcons === 'function'
      ) {
        renderIcons(bulkBtn);
      }
    }

    function refreshBulkButtons(root) {
      (root || node)
        .querySelectorAll('.plan-bulk-btn')
        .forEach(renderBulkBtn);
    }

    function setPickStatus(pick, value) {
      pick.value = value;

      pick.className =
        'plan-status-pick '
        + statusClass(value);

      const row =
        pick.closest('.plan-row');

      if (row) {
        row.className =
          'plan-row '
          + statusClass(value);
      }

      opts.onStatusChange(
        pick.dataset.planKey,
        value,
        pick
      );
    }

    function applyBulkDone(bulkBtn) {
      if (
        typeof opts.onStatusChange
          !== 'function'
      ) {
        return;
      }

      const scopeSelector =
        bulkBtn.dataset.planBulk
          === 'year'
          ? '.plan-year'
          : '.plan-sem';

      const scope =
        bulkBtn.closest(
          scopeSelector
        );

      if (!scope) {
        return;
      }

      const info = bulkStateOf(scope);

      const allPicks =
        [...scope.querySelectorAll(
          '.plan-status-pick'
        )];

      /*
       * لو النطاق كامل منجز أصلًا (الزر بحالة is-done)، الضغطة هاي
       * تراجُع: ترجّع كل مساقاته لحالة "متبقٍ" — تصرّف زر تبديل
       * (Toggle) واضح للطالب بدل ما يبقى الزر بلا أي فائدة إضافية
       * بعد أول ضغطة.
       */
      if (info.state === 'done') {
        allPicks.forEach(pick => {
          setPickStatus(pick, 'none');
        });
      } else {
        allPicks
          .filter(
            pick =>
              pick.value !== 'completed'
          )
          .forEach(pick => {
            setPickStatus(pick, 'completed');
          });
      }

      refreshBulkButtons(node);
    }

    refreshBulkButtons(node);

    node.onclick = event => {
      const bulkBtn =
        event.target.closest(
          '.plan-bulk-btn'
        );

      if (
        bulkBtn
        && node.contains(bulkBtn)
        && editable
      ) {
        applyBulkDone(bulkBtn);

        return;
      }

      const changeBtn =
        event.target.closest(
          '.plan-elective-change'
        );

      if (
        changeBtn
        && node.contains(changeBtn)
        && editable
        &&
        typeof opts.onElectiveChange
          === 'function'
      ) {
        opts.onElectiveChange(
          changeBtn.dataset.planKey
        );

        return;
      }

      const semesterHead =
        event.target.closest(
          '[data-plan-sem-head]'
        );

      if (
        semesterHead
        && node.contains(
          semesterHead
        )
      ) {
        toggleSemester(
          semesterHead
        );

        return;
      }

      const yearHead =
        event.target.closest(
          '.plan-year-head'
        );

      if (
        yearHead
        && node.contains(
          yearHead
        )
      ) {
        toggleYear(
          yearHead
        );
      }
    };

    node.onkeydown = event => {
      if (
        event.key !== 'Enter'
        && event.key !== ' '
      ) {
        return;
      }

      /*
       * زر "منجز الكل" نفسه <button> حقيقي — تفعيله بلوحة المفاتيح
       * (Enter/مسافة) يطلق click حدثه الطبيعي تلقائيًا من المتصفح،
       * فلا داعي لأي معالجة إضافية هون؛ نمنع فقط أن يلتقطها معالج
       * تبديل السنة/الفصل الأب (لأن الزر متداخل بداخل رأس السنة).
       */
      if (
        event.target.closest(
          '.plan-bulk-btn'
        )
      ) {
        return;
      }

      const semesterHead =
        event.target.closest(
          '[data-plan-sem-head]'
        );

      if (
        semesterHead
        && node.contains(
          semesterHead
        )
      ) {
        event.preventDefault();

        toggleSemester(
          semesterHead
        );

        return;
      }

      const yearHead =
        event.target.closest(
          '[data-plan-year-head]'
        );

      if (
        yearHead
        && node.contains(yearHead)
      ) {
        event.preventDefault();

        toggleYear(yearHead);
      }
    };

    node.onchange = event => {
      const electivePick =
        event.target.closest(
          '.plan-elective-pick'
        );

      if (
        electivePick
        && node.contains(electivePick)
        && editable
        &&
        typeof opts.onElectivePick
          === 'function'
      ) {
        const chosenKey =
          electivePick.value;

        if (chosenKey) {
          opts.onElectivePick(
            chosenKey
          );
        }

        return;
      }

      if (
        !editable
        ||
        typeof opts.onStatusChange
          !== 'function'
      ) {
        return;
      }

      const pick =
        event.target.closest(
          '.plan-status-pick'
        );

      if (
        !pick
        || !node.contains(pick)
      ) {
        return;
      }

      pick.className =
        'plan-status-pick '
        + statusClass(
            pick.value
          );

      const row =
        pick.closest(
          '.plan-row'
        );

      if (row) {
        row.className =
          'plan-row '
          + statusClass(
              pick.value
            );
      }

      opts.onStatusChange(
        pick.dataset.planKey,
        pick.value,
        pick
      );

      /*
       * تعديل يدوي لمساق واحد بعد ما كان النطاق (فصل/سنة) بحالة
       * "منجز الكل" (أو أي حالة جزئية) لازم يحدّث شكل ونص زرّي
       * "منجز الكل" (السنة + الفصل الحاوي) فورًا وبدون إعادة رسم
       * كامل الصفحة.
       */
      const semScope =
        pick.closest('.plan-sem');

      const yearScope =
        pick.closest('.plan-year');

      if (semScope) {
        const semBulkBtn =
          semScope.querySelector(
            ':scope > [data-plan-sem-head] .plan-bulk-btn'
          );

        if (semBulkBtn) renderBulkBtn(semBulkBtn);
      }

      if (yearScope) {
        const yearBulkBtn =
          yearScope.querySelector(
            ':scope > [data-plan-year-head] .plan-bulk-btn'
          );

        if (yearBulkBtn) renderBulkBtn(yearBulkBtn);
      }
    };
  }

  /* ── عرض الشجرة: خريطة المتطلبات التفاعلية ────────────────────────────
     يبني «مين بيفتح مين» من بيانات المتطلبات نفسها (لا حاجة لأي حقل
     جديد بقاعدة البيانات) — لكل مادة، نفحص كل المواد الأخرى ونجمع
     أيّها يذكرها كمتطلب سابق. مادة «مفصلية» = تفتح مادتين فأكثر. */
  function buildUnlockMap(courses) {
    const map = {};

    const byCode = {};

    courses.forEach(course => {
      if (course.code) byCode[course.code] = course;
    });

    courses.forEach(course => {
      prerequisitesOf(course).forEach(pre => {
        const target = byCode[pre.code];

        const key =
          target
            ? target.key
            : pre.code;

        if (!map[key]) map[key] = [];

        map[key].push(
          course.name_ar
          || course.name_en
          || course.code
        );
      });
    });

    return map;
  }

  function renderTree(target, options) {
    const node =
      typeof target === 'string'
        ? document.getElementById(target)
        : target;

    if (!node) return;

    const opts = options || {};

    const courses =
      (opts.courses || []).filter(
        course =>
          !isElective(course)
          && !isPlaceholder(course)
          && course.year >= 1
          && course.year <= 4
      );

    const unlockMap =
      buildUnlockMap(opts.courses || []);

    const byYear = {};

    courses.forEach(course => {
      const y = Number(course.year);

      if (!byYear[y]) byYear[y] = [];

      byYear[y].push(course);
    });

    Object.values(byYear).forEach(
      list =>
        list.sort(
          (a, b) =>
            (a.semester || 0) - (b.semester || 0)
        )
    );

    node.innerHTML = `
      <div class="tree-years">
        ${[1, 2, 3, 4]
          .filter(y => byYear[y])
          .map(y => `
            <div class="tree-year-block">
              <div class="tree-year-title">
                ${YEAR_LABELS[y] || 'سنة ' + y}
              </div>

              <div class="tree-chip-row">
                ${byYear[y].map(course => {
                    const unlocks =
                      unlockMap[course.key] || [];

                    const pivotal =
                      unlocks.length >= 2;

                    return `
                      <button
                        type="button"
                        class="tree-chip${pivotal ? ' pivotal' : ''}"
                        data-tree-key="${esc(course.key)}"
                      >
                        ${esc(
                          course.name_ar
                          || course.name_en
                          || course.code
                        )}
                      </button>
                    `;
                  }).join('')}
              </div>
            </div>
          `).join('')}
      </div>

      <div class="tree-legend">
        <span>
          <i class="tree-legend-swatch pivotal"></i>
          مادة مفصلية
        </span>

        <span>
          <i class="tree-legend-swatch"></i>
          مادة عادية
        </span>
      </div>

      <div class="tree-overlay" id="${node.id}Overlay">
        <div class="tree-dialog" id="${node.id}DialogCard"></div>
      </div>
    `;

    const overlay =
      document.getElementById(node.id + 'Overlay');

    const card =
      document.getElementById(node.id + 'DialogCard');

    function closeDialog() {
      overlay.classList.remove('is-open');
    }

    overlay.onclick = event => {
      if (event.target === overlay) closeDialog();
    };

    node.querySelectorAll('.tree-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        chip.classList.remove('pressed');

        void chip.offsetWidth;

        chip.classList.add('pressed');

        const key = chip.dataset.treeKey;

        const course =
          courses.find(c => c.key === key);

        if (!course) return;

        const unlocks = unlockMap[key] || [];

        const pivotal = unlocks.length >= 2;

        let body;

        if (!unlocks.length) {
          body = `
            <div class="tree-dialog-title">
              ${esc(
                course.name_ar
                || course.name_en
              )}
            </div>

            <p class="tree-dialog-note">
              هذا المساق ليس متطلبًا لأي مساقات لاحقة.
            </p>
          `;
        } else if (pivotal) {
          body = `
            <div class="tree-dialog-badge">
              <i data-icon="star"></i> مادة مفصلية
            </div>

            <div class="tree-dialog-title">
              ${esc(
                course.name_ar
                || course.name_en
              )}
            </div>

            <p class="tree-dialog-note">
              هالمادة مهمة جدًا إلك كمهندس، ولازم تركّز فيها خلال دراستك —
              لأنها بتفتحلك المساقات التالية:
            </p>

            <div class="tree-dialog-list">
              ${unlocks.map(
                name => `
                  <div class="tree-dialog-item">
                    ← ${esc(name)}
                  </div>
                `
              ).join('')}
            </div>
          `;
        } else {
          body = `
            <div class="tree-dialog-title">
              ${esc(
                course.name_ar
                || course.name_en
              )}
            </div>

            <p class="tree-dialog-note">
              بتفتحلك المساق التالي:
            </p>

            <div class="tree-dialog-list">
              <div class="tree-dialog-item">
                ← ${esc(unlocks[0])}
              </div>
            </div>
          `;
        }

        card.innerHTML = `
          <button
            type="button"
            class="tree-dialog-close"
            aria-label="إغلاق"
          >
            ✕
          </button>

          ${body}
        `;

        card.querySelector('.tree-dialog-close')
          .addEventListener('click', closeDialog);

        overlay.classList.add('is-open');
      });
    });
  }

  return {
    YEAR_LABELS,
    STATUS,
    group,
    renderPlan,
    renderTree,
    statusOf,
    statusClass,
    statusLabel,
    isElective,
    isPlaceholder,
    hours,
  };
})();

if (
  typeof module !== 'undefined'
  && module.exports
) {
  module.exports =
    PTCPlanView;
}
