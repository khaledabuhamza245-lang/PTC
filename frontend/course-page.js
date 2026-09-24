(function () {
  const esc = value => (typeof PTCUtils !== 'undefined' && PTCUtils.escapeHTML)
    ? PTCUtils.escapeHTML(String(value ?? ''))
    : String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      })[ch]);

  const kindLabels = {
    vid: 'فيديو', youtube: 'فيديو YouTube', drive: 'Google Drive', pdf: 'PDF',
    doc: 'ملف', assignment: 'تعيين', exercise: 'تدريب', exam: 'اختبار',
    book: 'مرجع', software: 'برنامج', github: 'GitHub', link: 'رابط',
    image: 'صورة', other: 'محتوى'
  };

  /*
   * كل قيمة هنا **يجب** أن تكون مفتاحًا في ICONS داخل icons.js، وإلا ترك
   * renderIcons الوسم <i> كما هو فظهر مربّع ٤٤×٤٤ ذهبي فارغ بلا رمز.
   * وثمانية من هذه الأنواع كانت كذلك — وهي الأشيع: pdf و doc و link.
   *
   * fileText و checkSquare و externalLink لم تُضف بل رُدّت إلى ما يقابلها
   * في الطقم أصلًا (notes و clipboard و external): إضافة مسارات مكرّرة
   * تُبقي رمزين متطابقين يتباعدان مع أول تعديل على أحدهما.
   *
   * يحرس التطابقَ tests/frontend/icons.js.
   */
  const kindIcons = {
    vid: 'play', youtube: 'play', drive: 'folder', pdf: 'notes',
    doc: 'notes', assignment: 'clipboard', exercise: 'edit', exam: 'award',
    book: 'bookOpen', software: 'settings', github: 'code', link: 'external',
    image: 'image', other: 'paperclip'
  };

  /*
   * زرّا "لخّصلي" و"بطاقات مراجعة" يظهران فقط لو الطاقم فعليًا حدّد
   * هذا الملف بالذات كقابل للتلخيص (item.ai_summarizable من لوحة
   * التحكم) — لا تخمين تلقائي حسب "kind" هون بعد اليوم. السبب: أحيانًا
   * ملف مصنَّف drive أو doc هو فعليًا برنامج للتحميل أو أرشيف مضغوط لا
   * مستندًا قابلًا للقراءة، فالطاقم هو الوحيد اللي يعرف فعليًا محتوى كل
   * ملف. الباك-إند (Staff\CourseFileController::defaultAiSummarizable)
   * يشتق قيمة افتراضية ذكية حسب النوع لأي ملف جديد ما يحدّد له الطاقم
   * شيئًا صراحة، فسلوك الملفات القديمة يبقى كما هو بلا أي تغيير مفاجئ
   * بعد رفع هذا التحديث.
   *
   * لو ضغط الطالب "لخّصلي"/"بطاقات مراجعة" على رابط drive مو ملفًا
   * حقيقيًا (مجلد مثلًا، أو مستند Google Docs بلا معرّف قابل للتنزيل)،
   * الباك-إند نفسه يرجّع رسالة عربية واضحة ("ما قدرت أجهّز هذا
   * الملف...") بدل ما يفشل بصمت — هذا سلوك موجود أصلًا لأي ملف يتعذّر
   * تجهيزه، بلا حاجة لأي فحص إضافي بالواجهة.
   */
  const isAiSummarizable = item => item.ai_summarizable !== false;

  const ref = () =>
    new URLSearchParams(location.search)
      .get('course') || '';

  function safePart(value, fallback = 'item') {
    return String(value ?? '')
      .replace(/[^a-zA-Z0-9_-]/g, '')
      || fallback;
  }

  const sectionId = (section, index = 0) =>
    `section-${safePart(
      section?.id,
      `s${index}`
    )}`;

  const unitId = (unit, index = 0) =>
    `unit-${safePart(
      unit?.id,
      `u${index}`
    )}`;

  const categoryId = (category, fallback) =>
    `category-${safePart(
      category?.id,
      fallback
    )}`;

  const contentId = (item, fallback) =>
    `content-${safePart(
      item?.id,
      fallback
    )}`;

  /*
   * روابط المعاينة تُحفظ هنا لا في سمة على العنصر: فلا يمر أي src من
   * سياق HTML نصي، ولا يحتاج مدقّق XSS استثناءً له. المفتاح معرّف
   * اللوحة وهو مشتقّ من safePart فمحاره آمنة.
   */
  const previewSrc = new Map();

  /* بيانات المساق المعروض، تُستعمل لتعبئة رسالة المساهمة. */
  let currentCourse = null;
  let allContentItems = [];
  let favoriteIds = new Set();

  /*
   * قائمة مسطّحة بكل عناصر محتوى المادة — تُبنى من نفس بنية الأقسام
   * المستخدمة أصلًا لعرض الصفحة، فلا حاجة لأي طلب شبكة إضافي. تُستخدم
   * لملء القائمة المنسدلة بحوار الإبلاغ عن مشكلة («أي عنصر يخصّ البلاغ؟»).
   */
  function flattenContentItems(sections) {
    const items = [];

    (sections || []).forEach(section => {
      (section.contents || []).forEach(item => {
        items.push(item);
      });

      (section.units || []).forEach(unit => {
        (unit.categories || []).forEach(category => {
          (category.contents || []).forEach(item => {
            items.push(item);
          });
        });
      });
    });

    return items;
  }

  /*
   * true إذا كانت حالة المساق بالخطة "منجز" — تُستعمل لعرض ١٠٠٪ حتى
   * لو المادة ما فيها ولا عنصر متتبَّع أصلًا (٠ من ٠ لا يعني ٠٪ هنا).
   */
  let planMarksCompleted = false;

  function installStyles() {
    if (
      document.getElementById(
        'ptc-course-tree-style'
      )
    ) {
      return;
    }

    const style =
      document.createElement('style');

    style.id =
      'ptc-course-tree-style';

    style.textContent = `
      .course-section-card{
        border:1px solid var(--line);
        background:var(--paper);
        border-radius:20px;
        box-shadow:var(--shadow);
        overflow:hidden;
        scroll-margin-top:100px
      }

      .course-section-title{
        width:100%;
        border:0;
        padding:20px 22px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:16px;
        text-align:right;
        cursor:pointer;
        color:var(--ink);
        background:
          linear-gradient(
            135deg,
            color-mix(
              in srgb,
              var(--olive) 14%,
              var(--paper)
            ),
            var(--paper)
          )
      }

      .course-section-title b{
        display:block;
        font:800 21px var(--font-ar);
        color:var(--olive)
      }

      .course-section-title small{
        display:block;
        margin-top:4px;
        font:12.5px var(--font-ar);
        color:var(--muted)
      }

      .course-section-title svg,
      .course-unit-title svg{
        flex:0 0 auto;
        transition:
          transform .38s
          cubic-bezier(.2,.8,.2,1)
          !important
      }

      .course-section-card.collapsed
      >.course-section-title svg,

      .course-unit-card.collapsed
      >.course-unit-title svg{
        transform:
          rotate(90deg)
          !important
      }

      .course-section-content,
      .course-unit-content{
        display:grid!important;
        grid-template-rows:1fr;
        opacity:1;
        padding:0!important;
        overflow:hidden;

        transition:
          grid-template-rows
          .46s
          cubic-bezier(.2,.8,.2,1),

          opacity .28s ease
          !important
      }

      .course-section-card.collapsed
      >.course-section-content,

      .course-unit-card.collapsed
      >.course-unit-content{
        display:grid!important;
        grid-template-rows:0fr;
        opacity:0
      }

      .course-section-content-inner,
      .course-unit-content-inner{
        min-height:0;
        overflow:hidden
      }

      .course-section-content-pad{
        padding:12px 18px 20px
      }

      .course-unit-content-pad{
        padding:5px 18px 20px
      }

      .course-section-card
      .course-unit-card{
        margin:10px 0;
        box-shadow:none;

        background:
          color-mix(
            in srgb,
            var(--paper) 88%,
            var(--olive) 12%
          )
      }

      .course-section-card
      .course-unit-title{
        padding:16px 18px
      }

      .course-category,
      .course-resource{
        scroll-margin-top:110px
      }

      .course-empty-inside{
        padding:18px;
        text-align:center;
        color:var(--muted);
        font:13px var(--font-ar)
      }

      @media(max-width:720px){
        .course-section-title{
          padding:17px 16px
        }

        .course-section-title b{
          font-size:18px
        }

        .course-section-content-pad{
          padding:10px
        }

        .course-unit-content-pad{
          padding:4px 12px 15px
        }
      }
    `;

    document.head.appendChild(style);
  }

  /*
   * عدّاد «٣ من ٧» على رأس الوحدة والتصنيف.
   *
   * الدلالة هي نفسها التي يحسبها الخادم حرفيًا: المقام هو المحتوى
   * المعلَّم counts_toward_progress، لا كل المحتوى. أي اختلاف هنا
   * يعطي الطالب رقمين متناقضين في الشاشة نفسها.
   */
  function progressChip(contents, done) {
    const tracked =
      (Array.isArray(contents) ? contents : [])
        .filter(item =>
          !!item.counts_toward_progress
        );

    if (!tracked.length) return '';

    const finished =
      tracked.filter(item =>
        done.has(Number(item.id))
      ).length;

    return `
      <span
        class="course-progress-chip"
        data-progress-chip
      >${finished} من ${tracked.length}</span>
    `;
  }

  /* محتوى الوحدة موزّع على تصنيفاتها، فيُجمع قبل العدّ. */
  function unitContents(categories) {
    return (Array.isArray(categories) ? categories : [])
      .flatMap(category =>
        Array.isArray(category.contents)
          ? category.contents
          : []
      );
  }

  function contentCard(
    item,
    done,
    fallbackKey
  ) {
    const tracked =
      !!item.counts_toward_progress;

    const id =
      contentId(
        item,
        fallbackKey
      );

    /*
     * القرار يُتخذ هنا قبل أي محاولة تأطير: ما لا يُعاين لا يُرسم له زر
     * أصلًا. السبب أن فشل التضمين غير قابل للكشف برمجيًا — الشرح في
     * رأس course-preview.js.
     */
    const preview =
      window.PTCCoursePreview
        ? PTCCoursePreview.resolve({
            kind: item.kind,
            url: item.external_url
          })
        : {
            embeddable: false,
            src: null,
            label: ''
          };

    if (preview.embeddable) {
      previewSrc.set(
        `${id}-preview`,
        preview.src
      );
    }

    /*
     * "مكتمل" و"معاينة" يتشاركان صفًّا واحدًا نص/نص لو مكتمل موجود،
     * وإلا "معاينة" تنضم لصفّ "فتح الرابط" (ولخّصلي/بطاقات مراجعة لو
     * وُجدا) وتتساوى معهم بنفس المنطق الموجود أصلًا لذاك الصفّ —
     * فلا تضل "معاينة" وحدها بصفّ منفصل بعرض كامل بلا داعٍ على الجوال.
     */
    const showDone = tracked && PTCAuth.user;

    const previewButtonHtml =
      preview.embeddable
        ? `
          <button
            type="button"
            class="abtn ghost course-preview-btn"
            data-preview-toggle="${id}"
            aria-expanded="false"
            aria-controls="${id}-preview"
          >
            معاينة
          </button>
        `
        : '';

    return `
      <article
        class="course-resource
        ${done ? 'done' : ''}"
        id="${id}"
        data-content-id="${esc(item.id)}"
        data-tracked="${tracked ? '1' : '0'}"
      >
        <div class="course-resource-icon">
          <i
            data-icon="${
              esc(
                kindIcons[item.kind]
                || 'paperclip'
              )
            }"
          ></i>
        </div>

        <div class="course-resource-body">
          <div class="course-resource-head">
            <div class="course-resource-type">
              ${
                esc(
                  kindLabels[item.kind]
                  || 'محتوى'
                )
              }
            </div>

            ${
              PTCAuth.user
                ? `
                  <div class="course-resource-quick-actions">
                    <button
                      type="button"
                      class="course-fav-btn${favoriteIds.has(Number(item.id)) ? ' is-fav' : ''}"
                      data-fav-id="${Number(item.id)}"
                      onclick="PTCCoursePage.toggleFavorite(${Number(item.id)}, this)"
                      title="${favoriteIds.has(Number(item.id)) ? 'إزالة من المفضّلة' : 'إضافة للمفضّلة'}"
                      aria-label="مفضّلة"
                    >
                      <svg viewBox="0 0 24 24" fill="${favoriteIds.has(Number(item.id)) ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </button>
                    <button
                      type="button"
                      class="report-btn"
                      title="الإبلاغ عن مشكلة بهذا المحتوى"
                      onclick="
                        PTCCoursePage.openReport(
                          ${Number(item.id)},
                          '${esc((item.title || 'محتوى').replace(/'/g, '&#39;'))}'
                        )
                      "
                    >
                      <i data-icon="flag"></i>
                    </button>
                  </div>
                `
                : ''
            }
          </div>

          <h4>
            ${esc(item.title || 'محتوى')}
          </h4>

          ${
            item.description
              ? `<p>${esc(item.description)}</p>`
              : ''
          }

          ${
            !preview.embeddable && preview.label
              ? `
                <div class="course-resource-dest">
                  يفتح في ${esc(preview.label)}
                </div>
              `
              : ''
          }
        </div>

        <div class="course-resource-actions">
          <div class="course-resource-actions-primary">
            ${
              PTCAuth.user && isAiSummarizable(item)
                ? `
                  <button
                    type="button"
                    class="abtn ghost course-summarize-btn"
                    onclick="PTCCoursePage.summarize(${Number(item.id)}, this)"
                    title="لخّص هذا الملف بمساعد PtcHub AI"
                  >
                    ✨ لخّصلي
                  </button>
                  <button
                    type="button"
                    class="abtn ghost course-flashcards-btn"
                    onclick="PTCCoursePage.flashcards(${Number(item.id)}, this)"
                    title="حوّل هذا الملف إلى بطاقات مراجعة سريعة"
                  >
                    <i data-icon="layers"></i> بطاقات مراجعة
                  </button>
                `
                : ''
            }

            <button
              type="button"
              class="abtn"
              onclick="
                PTCCoursePage.open(
                  ${Number(item.id)},
                  this
                )
              "
            >
              فتح الرابط ↗
            </button>

            ${!showDone ? previewButtonHtml : ''}
          </div>

          <div class="course-resource-actions-secondary${showDone && preview.embeddable ? ' has-preview-pair' : ''}">
            ${
              showDone
                ? `
                  <label class="course-done-check">
                    <input
                      type="checkbox"
                      ${done ? 'checked' : ''}
                      onchange="
                        PTCCoursePage.complete(
                          ${Number(item.id)},
                          this.checked,
                          this
                        )
                      "
                    >

                    <span>
                      ${
                        done
                          ? 'مكتمل'
                          : 'غير مكتمل'
                      }
                    </span>
                  </label>
                `
                : ''
            }

            ${showDone ? previewButtonHtml : ''}
          </div>
        </div>

        ${
          preview.embeddable
            ? `
              <div
                class="course-preview"
                id="${id}-preview"
                hidden
              >
                <div class="course-preview-frame">
                  <div class="course-preview-fallback">
                    <p>
                      تعذّر عرض هذا المحتوى داخل الصفحة.
                      قد يكون صاحبه يمنع التضمين،
                      أو صلاحية المشاركة تمنعك.
                    </p>

                    <button
                      type="button"
                      class="abtn"
                      onclick="
                        PTCCoursePage.open(
                          ${Number(item.id)},
                          this
                        )
                      "
                    >
                      فتح الرابط ↗
                    </button>
                  </div>
                </div>

                <div class="course-preview-note">
                  لم يظهر المحتوى؟
                  <button
                    type="button"
                    class="course-preview-link"
                    onclick="
                      PTCCoursePage.open(
                        ${Number(item.id)},
                        this
                      )
                    "
                  >
                    افتحه في تبويب جديد ↗
                  </button>
                </div>
              </div>
            `
            : ''
        }
      </article>
    `;
  }

  /*
   * التصنيف الوحيد داخل وحدته لا عنوان له.
   *
   * الـ API يقبل تعليق ملف مباشرة على قسم، لكن نموذج الأدمن يشترط
   * الأربعة، فيضطر الطاقم لاختراع «وحدة» و«تصنيف» وهميين لكل محاضرة.
   * النتيجة عنوان زائد فوق كل مجموعة ملفات — وهو مصدر الإحساس بأن
   * التصنيف غير احترافي.
   *
   * الإخفاء هنا عرضٌ فقط: القسم يبقى في الـDOM بمعرّفه، فالروابط
   * العميقة والشجرة الجانبية تظل تصله. والعدّاد يُحذف معه لأنه يكرّر
   * عدّاد الوحدة حرفيًا في هذه الحالة. والوصف يبقى إن كتبه الطاقم —
   * ذاك معلومة مقصودة لا حشو بنيوي.
   */
  function categoryBlock(
    category,
    done,
    fallbackKey,
    sole
  ) {
    const id =
      categoryId(
        category,
        fallbackKey
      );

    const contents =
      Array.isArray(category.contents)
        ? category.contents
        : [];

    const head =
      sole
        ? (
            category.description
              ? `
                <div class="course-category-head bare">
                  <p>${esc(category.description)}</p>
                </div>
              `
              : ''
          )
        : `
          <div class="course-category-head">
            <h3>
              ${
                esc(
                  category.title
                  || 'تصنيف'
                )
              }

              ${progressChip(contents, done)}
            </h3>

            ${
              category.description
                ? `<p>${esc(category.description)}</p>`
                : ''
            }
          </div>
        `;

    return `
      <section
        class="course-category${sole ? ' sole' : ''}"
        id="${id}"
      >
        ${head}

        <div class="course-resources">
          ${
            contents.length
              ? renderContentsWithCollapse(
                  contents,
                  done,
                  fallbackKey,
                  id
                )
              : `
                <div class="course-empty-inside">
                  لا يوجد محتوى داخل هذا التصنيف.
                </div>
              `
          }
        </div>
      </section>
    `;
  }

  /*
   * تصنيف فيه ملفات كثيرة كان يعني تمريرًا طويلًا جدًا على الموبايل
   * قبل ما توصل لأي تصنيف تاني — القائمة كلها ترتسم دفعة وحدة بلا حد
   * أقصى. صار يظهر أول COLLAPSE_THRESHOLD عنصر مباشرة، والباقي خلف
   * زرّ "عرض كل المحتوى" — أقل تمرير أول ما تفتح التصنيف، وبضغطة وحدة
   * يشوف الطالب كل شي لو احتاج.
   */
  const COLLAPSE_THRESHOLD = 6;

  function renderContentsWithCollapse(contents, done, fallbackKey, sectionId) {
    const cards = (list, offset) =>
      list
        .map(
          (item, index) =>
            contentCard(
              item,
              done.has(Number(item.id)),
              `${fallbackKey}-c${offset + index}`
            )
        )
        .join('');

    if (contents.length <= COLLAPSE_THRESHOLD) {
      return cards(contents, 0);
    }

    const visible = contents.slice(0, COLLAPSE_THRESHOLD);
    const rest = contents.slice(COLLAPSE_THRESHOLD);
    const restId = `${sectionId}-more`;

    return `
      ${cards(visible, 0)}
      <div class="course-resources-more" id="${restId}" hidden>
        ${cards(rest, COLLAPSE_THRESHOLD)}
      </div>
      <button
        type="button"
        class="abtn ghost course-resources-toggle"
        data-more-toggle="${restId}"
        aria-expanded="false"
        aria-controls="${restId}"
      >
        عرض كل المحتوى (${contents.length}) <i data-icon="chevronDown"></i>
      </button>
    `;
  }

//   function unitBlock(
//     unit,
//     done,
//     index
//   ) {
//     const id =
//       unitId(unit, index);

//     const categories =
//       Array.isArray(unit.categories)
//         ? unit.categories
//         : [];

//     return `
//       <article
//         class="course-unit-card collapsed"
//         id="${id}"
//       >
//         <button
//           class="course-unit-title"
//           type="button"
//           data-page-toggle="${id}"
//         >
//           <span>
//             <b>
//               ${
//                 esc(
//                   unit.title
//                   || 'وحدة'
//                 )
//               }
//             </b>

//             ${
//               unit.description
//                 ? `<small>${esc(unit.description)}</small>`
//                 : ''
//             }
//           </span>

//           <i data-icon="chevronDown"></i>
//         </button>

//         <div class="course-unit-content">
//           <div class="course-unit-content-inner">
//             <div class="course-unit-content-pad">
//               ${
//                 categories.length
//                   ? categories
//                       .map(
//                         (
//                           category,
//                           categoryIndex
//                         ) =>
//                           categoryBlock(
//                             category,
//                             done,
//                             `${id}-cat${categoryIndex}`
//                           )
//                       )
//                       .join('')
//                   : `
//                     <div class="course-empty-inside">
//                       لا توجد تصنيفات داخل هذه الوحدة.
//                     </div>
//                   `
//               }
//             </div>
//           </div>
//         </div>
//       </article>
//     `;
//   }


function unitBlock(
  unit,
  done,
  index
) {
  const id =
    unitId(unit, index);

  /*
   * نعرض فقط التصنيفات التي تحتوي
   * على محتوى أو روابط فعلية.
   */
  const categories =
    Array.isArray(unit.categories)
      ? unit.categories.filter(
          category =>
            Array.isArray(
              category.contents
            )
            && category.contents.length > 0
        )
      : [];

  return `
    <article
      class="course-unit-card collapsed"
      id="${id}"
    >
      <button
        class="course-unit-title"
        type="button"
        data-page-toggle="${id}"
      >
        <span>
          <b>
            ${
              esc(
                unit.title
                || 'وحدة'
              )
            }

            ${
              progressChip(
                unitContents(categories),
                done
              )
            }
          </b>

          ${
            unit.description
              ? `
                <small>
                  ${esc(unit.description)}
                </small>
              `
              : ''
          }
        </span>

        <i data-icon="chevronDown"></i>
      </button>

      <div class="course-unit-content">
        <div class="course-unit-content-inner">
          <div class="course-unit-content-pad">
            ${
              categories.length
                ? categories
                    .map(
                      (
                        category,
                        categoryIndex
                      ) =>
                        categoryBlock(
                          category,
                          done,
                          `${id}-cat${categoryIndex}`,
                          categories.length === 1
                        )
                    )
                    .join('')
                : `
                  <div class="course-empty-inside">
                    لا يوجد محتوى داخل هذه الوحدة.
                  </div>
                `
            }
          </div>
        </div>
      </div>
    </article>
  `;
}
  function normalizeSections(payload) {
    if (
      Array.isArray(payload.sections)
      && payload.sections.length
    ) {
      return payload.sections;
    }

    const units =
      Array.isArray(payload.units)
        ? payload.units
        : [];

    const oldSections =
      Array.isArray(
        payload.general_sections
      )
        ? payload.general_sections
        : [];

    const sections =
      oldSections.map(section => ({
        ...section,

        units:
          Array.isArray(section.units)
            ? section.units
            : units.filter(
                unit =>
                  Number(
                    unit.course_section_id
                  )
                  === Number(section.id)
              ),

        contents:
          section.contents || []
      }));

    const linked =
      new Set(
        sections.flatMap(
          section =>
            (section.units || [])
              .map(
                unit =>
                  Number(unit.id)
              )
        )
      );

    const orphanUnits =
      units.filter(
        unit =>
          !linked.has(
            Number(unit.id)
          )
      );

    if (orphanUnits.length) {
      sections.push({
        id: 'unclassified',
        title: 'محتوى إضافي',
        units: orphanUnits,
        contents: []
      });
    }

    return sections;
  }

  function sectionBlock(
    section,
    done,
    index
  ) {
    const id =
      sectionId(
        section,
        index
      );

    const units =
      Array.isArray(section.units)
        ? section.units
        : [];

    const direct =
      Array.isArray(section.contents)
        ? section.contents
        : [];

    const directHTML =
      direct.length
        ? categoryBlock(
            {
              id: `${id}-direct`,
              title: 'محتوى القسم',
              contents: direct
            },

            done,
            `${id}-direct`,
            /* وحده في القسم فلا داعي لعنوان يفصله عمّا لا يوجد. */
            !units.length
          )
        : '';

    return `
      <article
        class="course-section-card collapsed"
        id="${id}"
      >
        <button
          class="course-section-title"
          type="button"
          data-page-toggle="${id}"
        >
          <span>
            <b>
              ${
                esc(
                  section.title
                  || 'قسم'
                )
              }
            </b>

            ${
              section.description
                ? `<small>${esc(section.description)}</small>`
                : ''
            }
          </span>

          <i data-icon="chevronDown"></i>
        </button>

        <div class="course-section-content">
          <div class="course-section-content-inner">
            <div class="course-section-content-pad">
              ${
                units
                  .map(
                    (unit, unitIndex) =>
                      unitBlock(
                        unit,
                        done,
                        unitIndex
                      )
                  )
                  .join('')
              }

              ${directHTML}

              ${
                !units.length
                && !direct.length
                  ? `
                    <div class="course-empty-inside">
                      لا توجد وحدات داخل هذا القسم.
                    </div>
                  `
                  : ''
              }
            </div>
          </div>
        </div>
      </article>
    `;
  }

  /*
   * روابط الملفات في الشجرة. مفصولة لأن مسارين يستعملانها: التصنيف
   * العادي، والوحدة التي تصنيفها وحيد فتُرفع ملفاته إليها مباشرة.
   */
  function sidebarContents(contents, fallbackKey) {
    if (!contents.length) {
      return `
        <div class="course-tree-empty">
          لا يوجد محتوى
        </div>
      `;
    }

    return contents
      .map((item, index) => {
        const target =
          contentId(
            item,
            `${fallbackKey}-c${index}`
          );

        return `
          <a
            class="course-tree-content"
            href="#${target}"
            data-tree-target="${target}"
          >
            <span>
              ${
                esc(
                  item.title
                  || 'محتوى'
                )
              }
            </span>
          </a>
        `;
      })
      .join('');
  }

  function sidebarCategory(
    category,
    parentKey,
    categoryIndex
  ) {
    /*
     * المفتاح الاحتياطي نفسه الذي يمرّره
     * unitBlock إلى categoryBlock، لأن
     * contentId يبني عليه معرّف كل عنصر
     * حين ينقص item.id.
     */
    const fallbackKey =
      `${parentKey}-cat${categoryIndex}`;

    const id =
      categoryId(
        category,
        fallbackKey
      );

    const contents =
      Array.isArray(category.contents)
        ? category.contents
        : [];

    return `
      <div
        class="
          course-tree-node
          course-tree-category
        "
      >
        <button
          type="button"
          class="
            course-tree-row
            course-tree-toggle
          "
          data-tree-target="${id}"
        >
          <span>
            ${
              esc(
                category.title
                || 'تصنيف'
              )
            }
          </span>

          <small>
            ${contents.length}
          </small>

          <i data-icon="chevronDown"></i>
        </button>

        <div class="course-tree-children">
          ${sidebarContents(contents, fallbackKey)}
        </div>
      </div>
    `;
  }

  function sidebarTree(sections) {
  return sections
    .map((section, sectionIndex) => {
      const sid =
        sectionId(
          section,
          sectionIndex
        );

      const units =
        Array.isArray(section.units)
          ? section.units
          : [];

      /*
       * محتوى معلَّق مباشرة على القسم بلا وحدة.
       * sectionBlock يلفّه في تصنيف اصطناعي
       * بالمفتاح نفسه، فتتطابق المعرّفات.
       */
      const direct =
        Array.isArray(section.contents)
          ? section.contents
          : [];

      const directHTML =
        direct.length
          ? (
              /* وحده في القسم: يُرفع إلى القسم مباشرة كالوحدة أعلاه. */
              units.length
                ? sidebarCategory(
                    {
                      id: `${sid}-direct`,
                      title: 'محتوى القسم',
                      contents: direct
                    },

                    sid,
                    'direct'
                  )
                : sidebarContents(
                    direct,
                    `${sid}-direct`
                  )
            )
          : '';

      const unitsHTML =
  units.length
    ? units
        .map((unit, unitIndex) => {
          const uid =
            unitId(
              unit,
              unitIndex
            );

          /*
           * نفس ترشيح unitBlock حرفيًا:
           * التصنيفات الفارغة لا تُرسم هناك،
           * فلو رُسمت هنا صار في الشجرة
           * رابط إلى عنصر غير موجود.
           * والترتيب بعد الترشيح هو ما يبني
           * المفتاح الاحتياطي، فأي اختلاف
           * في الترشيح يكسر التطابق.
           */
          const categories =
            Array.isArray(unit.categories)
              ? unit.categories.filter(
                  category =>
                    Array.isArray(
                      category.contents
                    )
                    && category.contents.length > 0
                )
              : [];

          return `
            <div
              class="
                course-tree-node
                course-tree-unit
              "
            >
              <button
                type="button"
                class="
                  course-sidebar-unit
                  course-tree-toggle
                "
                data-tree-target="${uid}"
              >
                <span>
                  ${
                    esc(
                      unit.title
                      || 'وحدة'
                    )
                  }
                </span>

                <i data-icon="chevronDown"></i>
              </button>

              <div class="course-tree-children">
                ${
                  /*
                   * تصنيف وحيد: تُرفع ملفاته إلى الوحدة مباشرة، مطابقةً
                   * لإخفاء عنوانه في المحتوى. وإلا صارت الشجرة تعرض
                   * طبقة لا وجود لها على الشاشة، وكلّفت الطالب ضغطة
                   * ثالثة للوصول إلى ملف.
                   */
                  categories.length === 1
                    ? sidebarContents(
                        Array.isArray(categories[0].contents)
                          ? categories[0].contents
                          : [],
                        `${uid}-cat0`
                      )
                    : categories.length
                      ? categories
                          .map(
                            (
                              category,
                              categoryIndex
                            ) =>
                              sidebarCategory(
                                category,
                                uid,
                                categoryIndex
                              )
                          )
                          .join('')
                      : `
                        <div class="course-tree-empty">
                          لا يوجد محتوى
                        </div>
                      `
                }
              </div>
            </div>
          `;
        })
        .join('')
    : (
        direct.length
          ? ''
          : `
            <div class="course-sidebar-no-units">
              لا توجد وحدات
            </div>
          `
      );

      /*
       * كل الأقسام مطوية عند الفتح، بلا استثناء لأولها: الشجرة فهرس
       * يُتصفَّح لا محتوى يُقرأ، وقسم مفتوح سلفًا يدفع بقية الأقسام
       * تحت حافة الشريط فتُخفي الفهرسَ الذي جاء الطالب من أجله.
       * ما يُفتح يفتحه الطالب بيده.
       */
      return `
        <div
          class="
            course-tree-node
            course-tree-section
          "
        >
          <button
            type="button"
            class="
              course-tree-row
              course-tree-toggle
            "
            data-tree-target="${sid}"
          >
            <span>
              ${
                esc(
                  section.title
                  || 'قسم'
                )
              }
            </span>

            <small>
              ${
                units.length
                + (direct.length ? 1 : 0)
              }
            </small>

            <i data-icon="chevronDown"></i>
          </button>

          <div class="course-tree-children">
            ${unitsHTML}
            ${directHTML}
          </div>
        </div>
      `;
    })
    .join('');
}

  /*
   * المصدر هو الـDOM نفسه لا حالة موازية تُمسك في الذاكرة: ما يراه
   * الطالب هو ما يُعدّ، فلا شيء يمكن أن ينحرف عن شيء.
   */
  function countTracked(scope) {
    return {
      total:
        scope.querySelectorAll(
          '[data-tracked="1"]'
        ).length,

      done:
        scope.querySelectorAll(
          '[data-tracked="1"].done'
        ).length
    };
  }

  function setChip(scope, selector) {
    const chip =
      scope && scope.querySelector(selector);

    if (!chip) return;

    const counts = countTracked(scope);

    chip.textContent =
      `${counts.done} من ${counts.total}`;
  }

  function refreshCounters(card) {
    if (!card) return;

    setChip(
      card.closest('.course-category'),
      '.course-category-head [data-progress-chip]'
    );

    setChip(
      card.closest('.course-unit-card'),
      '.course-unit-title [data-progress-chip]'
    );
  }

  /* الصيغة هي صيغة الخادم حرفيًا (CourseStructureController:260). */
  function currentCoursePercentage() {
    const box =
      document.getElementById(
        'courseDetailMain'
      );

    if (!box) return 0;

    const counts = countTracked(box);

    if (!counts.total) {
      return planMarksCompleted ? 100 : 0;
    }

    return Math.round(
      counts.done * 100 / counts.total
    );
  }

  function recomputeProgress() {
    const box =
      document.getElementById(
        'courseDetailMain'
      );

    if (!box) return;

    const counts = countTracked(box);

    const percentage =
      counts.total
        ? Math.round(
            counts.done * 100 / counts.total
          )
        : (planMarksCompleted ? 100 : 0);

    updateProgress({
      completed: counts.done,
      total: counts.total,
      percentage
    });
  }

  function updateProgress(
    progress = {}
  ) {
    const completed =
      Number(
        progress.completed || 0
      );

    const total =
      Number(
        progress.total || 0
      );

    const percentage =
      Math.max(
        0,
        Math.min(
          100,
          Number(
            progress.percentage || 0
          )
        )
      );

    const value =
      document.getElementById(
        'courseProgressValue'
      );

    const bar =
      document.getElementById(
        'courseProgressBar'
      );

    const text =
      document.getElementById(
        'courseProgressText'
      );

    const wrap =
      document.getElementById(
        'courseProgressWrap'
      );

    if (value) {
      value.textContent =
        `${percentage}%`;
    }

    if (bar) {
      bar.style.width =
        `${percentage}%`;
    }

    if (text) {
      text.textContent =
        `${completed} من ${total} عناصر مكتملة`;
    }

    if (wrap) {
      wrap.hidden = !total;
    }
  }

  /*
   * الإطار يُبنى بـ createElement لا بقالب نصي، فلا يمر src من سياق
   * HTML أصلًا. ويُبنى عند أول فتح لا مع الصفحة: مساق فيه عشرون ملفًا
   * كان سيحمّل عشرين إطارًا دفعة واحدة.
   */
  function mountPreview(panel) {
    const frame =
      panel.querySelector(
        '.course-preview-frame'
      );

    if (
      !frame
      || frame.querySelector('iframe')
    ) {
      return;
    }

    const src =
      PTCUtils.safeUrl(
        previewSrc.get(panel.id) || ''
      );

    if (!src) return;

    const iframe =
      document.createElement('iframe');

    iframe.setAttribute('src', src);
    iframe.setAttribute('loading', 'lazy');
    iframe.setAttribute('title', 'معاينة المحتوى');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('allow', 'fullscreen; encrypted-media');

    iframe.setAttribute(
      'referrerpolicy',
      'strict-origin-when-cross-origin'
    );

    /*
     * بلا sandbox عمدًا: بلا allow-scripts يتعطّل مشغّل يوتيوب
     * ومعاينة درايف بالكامل، فيصير الإطار فارغًا في كل الأحوال.
     */
    frame.appendChild(iframe);
  }

  function togglePreview(button) {
    const panel =
      document.getElementById(
        button.getAttribute('aria-controls')
      );

    if (!panel) return;

    const willOpen = panel.hidden;

    if (willOpen) mountPreview(panel);

    panel.hidden = !willOpen;

    button.setAttribute(
      'aria-expanded',
      String(willOpen)
    );

    button.textContent =
      willOpen
        ? 'إخفاء المعاينة'
        : 'معاينة';
  }

  function openPageParents(target) {
    if (!target) return;

    /*
     * بطاقة الأدوات مطوية افتراضيًا، فالقفز إليها من الشريط الجانبي
     * بلا فتحها يُنزل الطالب على شريط مغلق ويجعل الضغطة تبدو معطّلة.
     */
    if (
      target.classList.contains(
        'course-tools'
      )
    ) {
      target.classList.remove(
        'collapsed'
      );
    }

    const section =
      target.classList.contains(
        'course-section-card'
      )
        ? target
        : target.closest(
            '.course-section-card'
          );

    const unit =
      target.classList.contains(
        'course-unit-card'
      )
        ? target
        : target.closest(
            '.course-unit-card'
          );

    section?.classList.remove(
      'collapsed'
    );

    unit?.classList.remove(
      'collapsed'
    );
  }

  function openSidebarParents(node) {
    let current = node;

    while (current) {
      current.classList.add('open');

      current =
        current.parentElement
          ?.closest(
            '.course-tree-node'
          );
    }
  }

  function setSidebarActive(
    side,
    trigger
  ) {
    side
      .querySelectorAll('.active')
      .forEach(element =>
        element.classList.remove(
          'active'
        )
      );

    trigger?.classList.add(
      'active'
    );
  }

  function render(payload) {
    installStyles();

    const course =
      payload.course || {};

    /*
     * يُحفظ ليعبّئ رسالة المساهمة برمز المساق واسمه: الرسالة الحاملة
     * للرمز تجعل محادثات تيليجرام قابلة للبحث، وهي بديل السجل الذي
     * لا يملكه المشروع.
     */
    currentCourse = {
      code: course.code || '',
      name: course.name_ar || course.name_en || ''
    };

    const title =
      course.name_ar
      || course.name_en
      || 'المادة';

    document.title =
      `${title} — هندسة أنظمة الحاسوب`;

    const codeElement =
      document.getElementById(
        'courseCode'
      );

    const titleElement =
      document.getElementById(
        'courseTitle'
      );

    const subtitleElement =
      document.getElementById(
        'courseSubtitle'
      );

    const backElement =
      document.getElementById(
        'courseBack'
      );

    if (codeElement) {
      codeElement.textContent =
        course.code || '—';
      codeElement.classList.remove(
        'skeleton', 'skeleton-inline'
      );
    }

    if (titleElement) {
      titleElement.textContent =
        title;
      titleElement.classList.remove(
        'skeleton', 'skeleton-line', 'skeleton-line-lg'
      );
    }

    if (subtitleElement) {
      subtitleElement.classList.remove(
        'skeleton', 'skeleton-line', 'skeleton-line-sm'
      );

      subtitleElement.textContent = [
        course.name_en,

        course.year
          ? `السنة ${course.year}`
          : '',

        course.semester === 3
          ? 'مساق اختياري'
          : course.semester
            ? `الفصل ${course.semester}`
            : ''
      ]
        .filter(Boolean)
        .join(' · ');
    }

    if (backElement) {
      const isElective =
        course.semester === 3;

      backElement.href =
        isElective
          ? 'electives.html'
          : `year${course.year || 1}.html`;

      backElement.textContent =
        isElective
          ? 'المساقات الاختيارية'
          : `السنة ${course.year || 1}`;
    }

    const crumbTitleElement =
      document.getElementById(
        'courseCrumbTitle'
      );

    if (crumbTitleElement) {
      crumbTitleElement.textContent =
        title;
    }

    updateProgress(
      payload.progress
    );

    const done =
      new Set(
        (
          payload.completed_content_ids
          || []
        ).map(Number)
      );

    const sections =
      normalizeSections(payload);

    allContentItems =
      flattenContentItems(sections);

    const side =
      document.getElementById(
        'courseSidebar'
      );

    const box =
      document.getElementById(
        'courseDetailMain'
      );

    if (!side || !box) return;

    /*
     * عدّة الشغل: أدوات هذه المادة، من مصدرين يراهما الطالب قسمًا واحدًا.
     *
     * الأول سجل ثابت لأدواتنا المبنية — هي كود له منطق واختبارات.
     * الثاني دليل يحرّره المشرف من اللوحة — روابط وأوصاف، أي بيانات.
     * الطالب لا يعنيه الفرق، فالدمج هنا لا في مكانين على الصفحة.
     *
     * وبلا تجميع بالنوع: المادة الواحدة أدواتها قليلة، فترويسات
     * فوقها تقطّع قائمة قصيرة. التصنيف مكانه صفحة الأدوات.
     *
     * ويُحسب قبل الشريط الجانبي لأن مدخل الشريط يحتاج عددها.
     */
    const TOOL_KINDS = {
      software: {
        icon: 'download',
        label: 'تحميل',
        tag: 'برنامج'
      },

      online: {
        icon: 'external',
        label: 'فتح مباشرة',
        tag: 'أداة ويب'
      },

      concept: {
        icon: 'bookOpen',
        label: 'شرح مختصر',
        tag: 'مفهوم'
      },

      library: {
        icon: 'book',
        label: 'التوثيق',
        tag: 'مكتبة'
      }
    };

    /*
     * نصوص المفاهيم تُحفظ هنا لا في سمة على الزر: الشرح فقرة كاملة،
     * ووضعها في data-* يعني هروبًا مزدوجًا لكل اقتباس فيها.
     */
    const conceptTexts = new Map();

    const builtinTools =
      (
        window.PTCTools
          ? PTCTools.forCourse(course.code)
          : []
      )
        .map(tool => ({
          uid: `builtin-${tool.id}`,
          name: tool.name,
          type: 'online',
          description: tool.blurb,
          href: `tools.html#${tool.id}`,
          icon: tool.icon,
          builtin: true
        }));

    const directoryTools =
      (payload.tools || [])
        .map(tool => ({
          uid: `tool-${tool.id}`,
          name: tool.name,
          type: TOOL_KINDS[tool.type]
            ? tool.type
            : 'online',
          description: tool.description,
          href: tool.official_url,
          video: tool.video_url,
          explanation: tool.explanation,
          builtin: false
        }));

    const tools =
      builtinTools.concat(directoryTools);

    /*
     * الأدوات لم تعد مدخلًا بالشريط الجانبي — صارت تحت قسم «موارد
     * ومساعدة» في نهاية المحتوى وحده، فالشريط الجانبي صار خاصًّا
     * بمحتوى المادة التعليمي فقط، لا مزيجًا من المحتوى والمراجع.
     */
    side.innerHTML =
      sidebarTree(sections)
      || `
        <div class="course-empty">
          لا يوجد محتوى منشور بعد.
        </div>
      `;

    /*
     * مؤشر ثابت أسفل الشريط الجانبي: بمادة طويلة كثيرة الأقسام، لا
     * طريقة يعرف بها الطالب أن قسم «موارد ومساعدة» موجود أصلًا في
     * آخر الصفحة إلا بالنزول العشوائي. رابط قفزٍ مباشر يغني عن ذلك.
     */
    side.insertAdjacentHTML(
      'beforeend',
      `
        <a
          href="#course-resources"
          class="course-sidebar-resources-link"
        >
          <i data-icon="link"></i>
          موارد ومساعدة
        </a>
      `
    );

    /*
     * زر المساهمة في أنسب لحظة: الطالب واقف أمام الفراغ ويعرف بالضبط
     * ما ينقص. وأسفل المحتوى أيضًا، لمن بحث فلم يجد ما يريد.
     *
     * والنصّ ينصّ على «رابط» صراحةً: المشروع لا يستقبل ملفات إطلاقًا،
     * فوعدٌ بغير ذلك يخلق توقّعًا كاذبًا ينتهي بطالب ينتظر ردًّا.
     */
    const contributeBlock = (heading) => `
      <div class="course-contribute">
        <h4>${esc(heading)}</h4>

        <p>
          الموقع لا يستقبل الملفات نفسها —
          أرسل رابطًا من درايف أو يوتيوب
          وسيُضاف للمادة.
        </p>

        <button
          type="button"
          class="tg-btn"
          onclick="PTCCoursePage.contribute(this)"
        >
          <i data-icon="send"></i>
          ساهم برابط ملف عبر تيليجرام
        </button>
      </div>
    `;

    const toolRow = tool => {
      const kind =
        TOOL_KINDS[tool.type]
        || TOOL_KINDS.online;

      /*
       * زر المفهوم يفتح نافذة، وما عداه يذهب إلى وجهة. ورابطٌ لم
       * ينجُ من safeUrl لا يُرسم زرّه أصلًا: زر معطوب أسوأ من غيابه.
       */
      let action = '';

      if (tool.type === 'concept') {
        if (tool.explanation) {
          conceptTexts.set(
            tool.uid,
            tool.explanation
          );

          action = `
            <button
              type="button"
              class="course-tool-go"
              data-tool-concept="${esc(tool.uid)}"
            >
              <i data-icon="${esc(kind.icon)}"></i>
              ${kind.label}
            </button>
          `;
        }

      } else {
        const href =
          PTCUtils.safeUrl(tool.href);

        if (href) {
          action = `
            <a
              class="course-tool-go"
              href="${esc(href)}"
              ${
                tool.builtin
                  ? ''
                  : 'target="_blank" rel="noopener noreferrer"'
              }
            >
              <i data-icon="${esc(kind.icon)}"></i>
              ${kind.label}
            </a>
          `;
        }
      }

      const video =
        PTCUtils.safeUrl(tool.video);

      return `
        <div class="course-tool">
          <span class="course-tool-ic">
            <i data-icon="${
              esc(tool.icon || kind.icon)
            }"></i>
          </span>

          <span class="course-tool-body">
            <b>
              ${esc(tool.name)}

              <em class="course-tool-tag">
                ${
                  tool.builtin
                    ? 'داخل المنصة'
                    : kind.tag
                }
              </em>
            </b>

            <small>${esc(tool.description)}</small>
          </span>

          <span class="course-tool-acts">
            ${action}

            ${
              video
                ? `
                  <a
                    class="course-tool-video"
                    href="${esc(video)}"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <i data-icon="play"></i>
                    شرح بالعربي
                  </a>
                `
                : ''
            }
          </span>
        </div>
      `;
    };

    /*
     * مفتاح تخزين حالة طيّ «أدوات هندسية» — مفصول باسم المادة فلا
     * تتداخل حالة مادة بأخرى.
     */
    function toolsStateKey(courseKey) {
      return `ptc_tools_open_${courseKey || 'x'}`;
    }

    const toolsWasOpened = (() => {
      try {
        return localStorage.getItem(
          toolsStateKey(course.key)
        ) === '1';
      } catch (_) {
        return false;
      }
    })();

    /*
     * مطوي افتراضيًا لأول زيارة: القسم مرجع يُقصد عند الحاجة لا محتوى
     * يُتصفَّح. لكن إن كان الطالب فتحه سابقًا في هذه المادة تحديدًا،
     * يبقى مفتوحًا بعد أي تحديث للصفحة حتى يطويه هو بنفسه.
     */
    const toolsBlock =
      tools.length
        ? `
          <div
            class="course-tools${toolsWasOpened ? '' : ' collapsed'}"
            id="course-tools-card"
          >
            <button
              type="button"
              class="course-tools-head"
              data-page-toggle="course-tools-card"
            >
              <span class="course-tools-head-text">
                <b>أدوات هندسية</b>

                <small>
                  البرامج والأدوات التي
                  تحتاجها هذه المادة
                </small>
              </span>

              <span class="course-tools-count">
                ${tools.length}
              </span>

              <i data-icon="chevronDown"></i>
            </button>

            <div class="course-tools-list">
              ${
                tools
                  .map(toolRow)
                  .join('')
              }
            </div>
          </div>
        `
        : '';

    const resourcesDivider = `
      <div class="course-resources-divider" id="course-resources">
        <span>موارد ومساعدة</span>
      </div>
    `;

    box.innerHTML =
      sections.length
        ? sections
            .map(
              (
                section,
                index
              ) =>
                sectionBlock(
                  section,
                  done,
                  index
                )
            )
            .join('')
          + resourcesDivider
          + toolsBlock
          + contributeBlock(
              'تعرف ملفًا ينقص هذه المادة؟'
            )
        : `
          <div class="course-empty-card">
            <i data-icon="paperclip"></i>

            <h3>
              لا يوجد محتوى منشور لهذه المادة بعد
            </h3>

            <p>
              سيظهر هنا فور إضافته من لوحة التحكم.
            </p>
          </div>
        `
          + resourcesDivider
          + toolsBlock
          + contributeBlock(
              'عندك ملف لهذه المادة؟ كن أول من يساهم'
            );

    side.onclick = event => {
      const trigger =
        event.target.closest(
          '[data-tree-target]'
        );

      if (
        !trigger
        || !side.contains(trigger)
      ) {
        return;
      }

      event.preventDefault();

      const targetId =
        trigger.dataset.treeTarget;

      const target =
        document.getElementById(
          targetId
        );

      const node =
        trigger.closest(
          '.course-tree-node'
        );

      const isToggle =
        trigger.classList.contains(
          'course-tree-toggle'
        );

      if (isToggle && node) {
        const willOpen =
          !node.classList.contains(
            'open'
          );

        node.classList.toggle(
          'open',
          willOpen
        );

        if (
          target?.classList.contains(
            'course-section-card'
          )
        ) {
          target.classList.toggle(
            'collapsed',
            !willOpen
          );

        } else if (
          target?.classList.contains(
            'course-unit-card'
          )
        ) {
          target.classList.toggle(
            'collapsed',
            !willOpen
          );

          target
            .closest(
              '.course-section-card'
            )
            ?.classList.remove(
              'collapsed'
            );

        } else {
          openPageParents(target);
        }

      } else {
        openPageParents(target);
      }

      openSidebarParents(
        node?.parentElement
          ?.closest(
            '.course-tree-node'
          )
      );

      setSidebarActive(
        side,
        trigger
      );

      if (!target) return;

      target.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });

      /*
       * الطيّ والفتح لا يُكتبان في العنوان: العنوان يبقى بعد التحديث،
       * فقسمٌ فتحه الطالب بضغطة كان يعود مفتوحًا وحده — وهذا حفظُ حالةٍ
       * لا نريده. أما رابط ملف بعينه فوجهة قصدها الطالب وتستحق البقاء.
       */
      if (!isToggle) {
        history.replaceState(
          null,
          '',
          `#${targetId}`
        );
      }
    };

    box.onclick = event => {
      /*
       * المفهوم ليس له وجهة يُذهب إليها، فشرحه يُعرض مكانه في نافذة.
       */
      const conceptButton =
        event.target.closest(
          '[data-tool-concept]'
        );

      if (
        conceptButton
        && box.contains(conceptButton)
      ) {
        event.preventDefault();

        const uid =
          conceptButton.dataset
            .toolConcept;

        SiteDialog.message({
          title:
            conceptButton
              .closest('.course-tool')
              ?.querySelector('b')
              ?.firstChild
              ?.textContent
              ?.trim()
            || 'شرح مختصر',

          message:
            conceptTexts.get(uid) || ''
        });

        return;
      }

      /*
       * زرّ "عرض كل المحتوى" — يُلتقط قبل معالج الطي لنفس سبب زرّ
       * المعاينة تحته: هو داخل تصنيف قد يكون داخل وحدة قابلة للطي.
       */
      const moreButton =
        event.target.closest(
          '[data-more-toggle]'
        );

      if (
        moreButton
        && box.contains(moreButton)
      ) {
        event.preventDefault();

        const restId = moreButton.dataset.moreToggle;
        const restBox = document.getElementById(restId);
        if (!restBox) return;

        const nowExpanded = restBox.hidden;
        restBox.hidden = !nowExpanded;
        moreButton.setAttribute('aria-expanded', String(nowExpanded));
        moreButton.classList.toggle('is-expanded', nowExpanded);

        if (!moreButton.dataset.moreLabelCollapsed) {
          moreButton.dataset.moreLabelCollapsed = moreButton.innerHTML;
        }
        moreButton.innerHTML = nowExpanded
          ? 'إظهار أقل <i data-icon="chevronDown"></i>'
          : moreButton.dataset.moreLabelCollapsed;

        return;
      }

      /*
       * المعاينة تُلتقط أولًا: زرها داخل بطاقة الملف، وبطاقة الملف
       * داخل بطاقة الوحدة، فلو مرّ الحدث لمعالج الطي لطوى الوحدة كلها
       * تحت المستخدم في نفس النقرة.
       */
      const previewButton =
        event.target.closest(
          '[data-preview-toggle]'
        );

      if (
        previewButton
        && box.contains(previewButton)
      ) {
        event.preventDefault();
        togglePreview(previewButton);

        return;
      }

      const button =
        event.target.closest(
          '[data-page-toggle]'
        );

      if (
        !button
        || !box.contains(button)
      ) {
        return;
      }

      const targetId =
        button.dataset.pageToggle;

      const card =
        document.getElementById(
          targetId
        );

      if (!card) return;

      card.classList.toggle(
        'collapsed'
      );

      /*
       * حالة «أدوات هندسية» تُحفظ باسم المادة: لو فتحها الطالب تبقى
       * مفتوحة بعد أي تحديث للصفحة حتى يطويها هو بنفسه، والعكس صحيح.
       * غير هذه البطاقة بالذات لا يُحفَظ — الطي الافتراضي لبقية
       * البطاقات القابلة للطي يبقى كما كان.
       */
      if (targetId === 'course-tools-card') {
        try {
          localStorage.setItem(
            toolsStateKey(course.key),
            card.classList.contains('collapsed')
              ? '0'
              : '1'
          );
        } catch (_) {}
      }

      const sidebarTrigger =
        side.querySelector(
          `[data-tree-target="${targetId}"]`
        );

      const sidebarNode =
        sidebarTrigger?.closest(
          '.course-tree-node'
        );

      sidebarNode?.classList.toggle(
        'open',
        !card.classList.contains(
          'collapsed'
        )
      );

      openSidebarParents(
        sidebarNode?.parentElement
          ?.closest(
            '.course-tree-node'
          )
      );

      setSidebarActive(
        side,
        sidebarTrigger
      );
    };

    if (location.hash) {
      const hashId =
        location.hash.slice(1);

      const target =
        document.getElementById(
          hashId
        );

      const sidebarTrigger =
        side.querySelector(
          `[data-tree-target="${hashId}"]`
        );

      openPageParents(target);

      openSidebarParents(
        sidebarTrigger?.closest(
          '.course-tree-node'
        )
      );

      setSidebarActive(
        side,
        sidebarTrigger
      );
    }

    window.PTCIcons?.refresh?.();
  }

  /**
   * بطاقة المتطلبات — تُلحَق أسفل شجرة المحتوى لا تصير تبويبًا.
   *
   * التبويب يخفي المعلومة خلف نقرة ويزاحم المحتوى على الانتباه، وهي
   * معلومة يُنظر إليها مرة ثم تُترك. وأسفل الشجرة موضعها الطبيعي:
   * الطالب الذي وصل إلى آخر قائمة المحتوى هو من يسأل «وما الذي
   * يسبق هذه المادة؟».
   *
   * وتُجلب بعد الرسم الأساسي لا معه: /structure لا يحملها، وانتظارها
   * كان سيؤخّر ظهور المحتوى كله من أجل سطرين.
   */
  function prerequisiteCard(data) {
    const item = entry => {
      const codeHtml = `<code>${esc(entry.code || '')}</code>`;

      if (!entry.course) {
        /*
         * رمز لا يقابل مساقًا في الكتالوج — ستّة مراجع اليوم. يُعرض
         * كما ورد ولا يُخمَّن ولا يُخفى: إخفاؤه يُري الطالب متطلبات
         * أقلّ مما تفرضه الخطة فعلًا.
         */
        return `<div class="course-prereq-item course-prereq-raw">${codeHtml}</div>`;
      }

      const safeHref =
        `course.html?course=${encodeURIComponent(entry.course.key)}`;

      return `<div class="course-prereq-item">${codeHtml}
        <a href="${safeHref}">${
          esc(entry.course.name_ar || entry.course.name_en || '')
        }</a>
      </div>`;
    };

    const before = data.prerequisites || [];
    const after = (data.required_for || []).map(course => ({ code: course.code, course: course }));

    if (!before.length && !after.length) {
      return `<div class="course-prereq">
        <h4><i data-icon="link"></i> المتطلبات السابقة</h4>
        <div class="course-prereq-empty">لا متطلب سابق لهذه المادة.</div>
      </div>`;
    }

    const block = (title, rows) => {
      if (!rows.length) return '';

      const itemsHtml = rows.map(item).join('');

      return `<div class="course-prereq-group">
        <h4><i data-icon="link"></i> ${esc(title)}</h4>
        <div class="course-prereq-list">${itemsHtml}</div>
      </div>`;
    };

    const beforeHtml = block('المتطلبات السابقة', before);
    const afterHtml = block('هذه المادة متطلب لـ', after);

    return `<div class="course-prereq">${beforeHtml}${afterHtml}</div>`;
  }

  async function loadPrerequisites(courseRef) {
    const side = document.getElementById('courseSidebar');
    if (!side) return;

    try {
      const data = await PTCAuth.getCoursePrerequisites(courseRef);
      side.insertAdjacentHTML('beforeend', prerequisiteCard(data));
    } catch (error) {
      /* صامت عمدًا: البطاقة إضافة لا شرط لعمل الصفحة. */
      console.error('تعذّر تحميل المتطلبات:', error);
    }
  }

  /*
   * إذا كانت حالة المساق بالخطة الدراسية "منجز"، فمن غير المنطقي أن
   * يبقى تقدّم محتواه صفرًا — الطالب أصلًا أنهى المادة ونجح فيها.
   * نكمّل كل عناصره المتتبَّعة تلقائيًا هون، مرة وحدة فقط (العناصر
   * المكتملة أصلاً تُستثنى)، ولا نلمس شيئًا إذا لم تكن الحالة "منجز".
   */
  async function syncCompletedFromPlan(courseKey) {
    if (!courseKey) return;

    try {
      const summary =
        await PTCAuth.getPlanSummary();

      const row =
        summary
        && summary.statuses
        && summary.statuses[courseKey];

      const status =
        row && row.status;

      if (status !== 'completed') return;

      planMarksCompleted = true;

      const box =
        document.getElementById(
          'courseDetailMain'
        );

      if (!box) return;

      const pending =
        [...box.querySelectorAll(
          '[data-tracked="1"]:not(.done)'
        )];

      if (!pending.length) {
        recomputeProgress();
        return;
      }

      for (const card of pending) {
        const id =
          card.dataset.contentId;

        if (!id) continue;

        try {
          await PTCAuth
            .setContentCompleted(
              Number(id),
              true
            );

          card.classList.add('done');

          const input =
            card.querySelector(
              'input[type="checkbox"]'
            );

          if (input) input.checked = true;

          const label =
            card.querySelector(
              '.course-done-check span'
            );

          if (label) {
            label.textContent = 'مكتمل';
          }

          refreshCounters(card);
        } catch (itemError) {
          /* نتابع باقي العناصر حتى لو فشل عنصر واحد. */
          console.error(
            'تعذّر إكمال عنصر تلقائيًا:',
            itemError
          );
        }
      }

      recomputeProgress();
    } catch (error) {
      /* صامت عمدًا: لا نكسر الصفحة إن تعذّر جلب حالة الخطة. */
      console.error(
        'تعذّر مزامنة تقدّم المادة المنجزة:',
        error
      );
    }
  }

  async function load() {
    const courseRef = ref();

    if (!courseRef) {
      location.replace(
        'index.html'
      );

      return;
    }

    try {
      await PTCAuth.requireAuth(
        'login.html'
      );

      const [payload, favIds] =
        await Promise.all([
          PTCAuth.getCourseStructure(courseRef),
          PTCAuth.getFavoriteIds().catch(() => [])
        ]);

      favoriteIds = new Set(favIds.map(Number));

      render(payload);

      highlightContentFromUrl();

      syncCompletedFromPlan(
        payload.course
        && payload.course.key
      );

      loadPrerequisites(courseRef);

    } catch (error) {
      const box =
        document.getElementById(
          'courseDetailMain'
        );

      const side =
        document.getElementById(
          'courseSidebar'
        );

      if (box) {
        box.innerHTML = `
          <div class="course-empty-card">
            <h3>
              تعذّر تحميل المادة
            </h3>

            <p>
              ${
                esc(
                  error.message
                  || 'حاول مرة أخرى.'
                )
              }
            </p>

            <button
              class="abtn"
              onclick="location.reload()"
            >
              إعادة المحاولة
            </button>
          </div>
        `;
      }

      if (side) {
        side.innerHTML = '';
      }
    }
  }

  async function complete(
    id,
    completed,
    input
  ) {
    const card =
      input.closest(
        '.course-resource'
      );

    const label =
      input.parentElement
        ?.querySelector('span');

    /* نلتقط نسبة الإنجاز *قبل* هذه الضغطة تحديدًا — الاحتفال يجب أن
       يظهر فقط لحظة العبور من غير مكتمل إلى 100%، لا في كل ضغطة على
       مادة أنجزها الطالب أصلًا من قبل. */
    const wasComplete = currentCoursePercentage() >= 100;

    input.disabled = true;

    try {
      await PTCAuth
        .setContentCompleted(
          id,
          completed
        );

      card?.classList.toggle(
        'done',
        completed
      );

      if (label) {
        label.textContent =
          completed
            ? 'مكتمل'
            : 'غير مكتمل';
      }

      /*
       * الحساب محليًا لا بإعادة جلب الهيكل.
       *
       * كان هنا getCourseStructure كامل بعد كل ضغطة: يعيد كل الأقسام
       * والوحدات والتصنيفات وكل رابط في المساق، ليُقرأ منه رقمان.
       * على استضافة مشتركة، وبضغطة لكل ملف يفتحه الطالب، هذا أثقل
       * طلب في الصفحة وأكثرها تكرارًا.
       *
       * ولا يعاد الرسم أصلًا، لذلك تبقى التفريعات والمعاينات مفتوحة.
       */
      refreshCounters(card);
      recomputeProgress();

      if (completed && !wasComplete && currentCoursePercentage() >= 100) {
        window.PTCCelebration?.burst(currentCourse?.name);
      }

    } catch (error) {
      input.checked =
        !completed;

      card?.classList.toggle(
        'done',
        !completed
      );

      if (label) {
        label.textContent =
          !completed
            ? 'مكتمل'
            : 'غير مكتمل';
      }

      /* الرجوع يعيد العدّادات أيضًا، وإلا بقيت على رقم لم يُحفظ. */
      refreshCounters(card);
      recomputeProgress();

      alert(
        error.message
        || 'تعذّر حفظ الإنجاز.'
      );

    } finally {
      input.disabled = false;
    }
  }

  async function open(
    id,
    button
  ) {
    try {
      button.disabled = true;

      await PTCAuth
        .openCourseFile(id);

    } catch (error) {
      alert(
        error.message
        || 'تعذّر فتح الرابط.'
      );

    } finally {
      button.disabled = false;
    }
  }

  /* ── صندوق الخبرة ──────────────────────────────────────────────────
     أرشيف لا يظهر تلقائيًا أبدًا — الطالب هو من يفتحه بضغطة، ويُحمَّل
     أول مرة يُفتح فيها بس (لا داعي لطلب شبكة لصفحة قد لا يفتحها أحد). */
  let tipsLoaded = false;

  function escapeText(value) {
    return PTCUtils.escapeHTML(
      value == null ? '' : String(value)
    );
  }

  function renderTipsList(tips) {
    const box = document.getElementById('tipBoxList');

    if (!box) return;

    if (!tips.length) {
      box.innerHTML = `
        <div class="tip-box-empty">
          ما في نصايح لهالمادة لسا — كن أول من يساعد زملاءك.
        </div>
      `;

      return;
    }

    box.innerHTML = tips.map(
      tip => `
        <div class="tip-box-item" data-tip-id="${Number(tip.id)}">
          ${escapeText(tip.message)}
        </div>
      `
    ).join('');

    highlightTipFromUrl();
  }

  /*
   * وصول من إشعار الجرس بلوحة الأدمن: ?tip=<id> بالرابط — بعد ما
   * تُبنى القائمة، ندوّر على العنصر ونظلّله وننزلق إليه، مرة وحدة بس
   * (لا نكرر التظليل كل مرة تُعاد فيها القائمة بلا داعٍ).
   */
  let highlightedOnce = false;

  function highlightTipFromUrl() {
    if (highlightedOnce) return;

    let tipId = '';

    try {
      tipId = new URL(location.href)
        .searchParams.get('tip') || '';
    } catch (_) {
      return;
    }

    if (!tipId) return;

    const target = document.querySelector(
      `.tip-box-item[data-tip-id="${tipId}"]`
    );

    if (!target) return;

    highlightedOnce = true;

    target.classList.add('tip-box-item-highlight');

    target.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    });
  }

  async function openTipBox() {
    const overlay = document.getElementById('tipBoxOverlay');

    if (!overlay) return;

    document.getElementById('tipBoxSubtitle').textContent =
      currentCourse && (currentCourse.name)
        ? `حول مساق ${currentCourse.name}`
        : 'حول هذا المساق';

    overlay.classList.add('is-open');

    if (tipsLoaded) return;

    tipsLoaded = true;

    try {
      const tips = await PTCAuth.getCourseTips(ref());

      renderTipsList(tips);
    } catch (error) {
      tipsLoaded = false;

      document.getElementById('tipBoxList').innerHTML = `
        <div class="tip-box-empty">
          تعذّر تحميل النصائح. حاول مرة أخرى.
        </div>
      `;
    }
  }

  function closeTipBox() {
    const overlay = document.getElementById('tipBoxOverlay');

    if (overlay) overlay.classList.remove('is-open');
  }

  document.getElementById('tipBoxOverlay')
    ?.addEventListener('click', event => {
      if (event.target.id === 'tipBoxOverlay') closeTipBox();
    });

  document.getElementById('reportOverlay')
    ?.addEventListener('click', event => {
      if (event.target.id === 'reportOverlay') closeReport();
    });

  async function submitTip() {
    const input = document.getElementById('tipBoxInput');

    const message = input.value.trim();

    const msgBox = document.getElementById('tipBoxMsg');

    if (message.length < 10) {
      msgBox.textContent =
        'اكتب نصيحة أطول شوي (١٠ أحرف على الأقل) حتى تفيد غيرك.';

      msgBox.className = 'tip-box-msg show err';

      return;
    }

    const button = document.getElementById('tipBoxSubmit');

    button.disabled = true;

    try {
      const saved = await PTCAuth.addCourseTip(
        ref(),
        message
      );

      input.value = '';

      msgBox.textContent = 'تم نشر نصيحتك، شكرًا لمشاركتها!';
      msgBox.className = 'tip-box-msg show ok';

      const box = document.getElementById('tipBoxList');

      const empty = box.querySelector('.tip-box-empty');

      if (empty) empty.remove();

      box.insertAdjacentHTML(
        'afterbegin',
        `
          <div class="tip-box-item">
            ${escapeText(saved.message)}
          </div>
        `
      );
    } catch (error) {
      msgBox.textContent =
        error.message
        || 'تعذّر نشر النصيحة، حاول مرة أخرى.';

      msgBox.className = 'tip-box-msg show err';
    } finally {
      button.disabled = false;
    }
  }

  /* ── الإبلاغ عن مشكلة بمحتوى المساق ────────────────────────────────
     زر واحد أعلى الصفحة كلها — الحوار نفسه يتيح تحديد أي عنصر يخص
     البلاغ (أو تركه عامًّا)، فلا حاجة لزر منفصل لكل عنصر. البلاغ إداري
     صرف: يصل للطاقم فقط، ولا قائمة عرض له هنا. */
  const REPORT_REASONS = {
    broken_link: 'الرابط لا يعمل',
    wrong_file: 'الملف خاطئ',
    duplicate: 'الملف مكرر',
    wrong_title: 'الاسم غير صحيح',
    wrong_course: 'المساق غير صحيح',
    inappropriate: 'محتوى غير مناسب',
    other: 'سبب آخر',
  };

  let reportReason = null;
  let reportOptionsBuilt = false;

  function renderReportContentOptions(selectedId) {
    const select = document.getElementById('reportContentSelect');

    if (!select) return;

    select.innerHTML = `
      <option value="">عام — غير مرتبط بعنصر محدد</option>
      ${allContentItems.map(
        item => `
          <option value="${Number(item.id)}">
            ${escapeText(item.title || 'محتوى')}
          </option>
        `
      ).join('')}
    `;

    select.value =
      selectedId
        ? String(selectedId)
        : '';
  }

  function renderReportReasons() {
    const box = document.getElementById('reportReasons');

    if (!box) return;

    box.innerHTML = Object.entries(REPORT_REASONS).map(
      ([key, label]) => `
        <button
          type="button"
          class="report-chip${reportReason === key ? ' on' : ''}"
          data-report-reason="${key}"
        >
          ${escapeText(label)}
        </button>
      `
    ).join('');

    box.querySelectorAll('[data-report-reason]').forEach(chip => {
      chip.addEventListener('click', () => {
        reportReason = chip.dataset.reportReason;
        renderReportReasons();
      });
    });
  }

  /*
   * الفتح لا يمسح شيئًا أبدًا — لو أغلق الطالب الحوار بمنتصف تعبئته
   * (بالخطأ أو ليكمل لاحقًا)، ما كتبه يبقى كما هو عند إعادة الفتح.
   * المسح الوحيد يصير بعد نجاح الإرسال فعليًا، داخل submitReport.
   */
  function openReport(contentId) {
    const subtitle = document.getElementById('reportSubtitle');

    if (subtitle) {
      subtitle.textContent =
        currentCourse && currentCourse.name
          ? `بخصوص مساق ${currentCourse.name}`
          : 'بخصوص هذا المساق';
    }

    if (!reportOptionsBuilt) {
      reportOptionsBuilt = true;
      renderReportContentOptions(contentId);
    }

    renderReportReasons();

    const msgBox = document.getElementById('reportMsg');

    if (msgBox) msgBox.className = 'tip-box-msg';

    document.getElementById('reportOverlay')
      .classList.add('is-open');
  }

  function closeReport() {
    const overlay = document.getElementById('reportOverlay');

    if (overlay) overlay.classList.remove('is-open');
  }

  async function submitReport() {
    const msgBox = document.getElementById('reportMsg');

    if (!reportReason) {
      msgBox.textContent = 'اختر سبب البلاغ أولًا.';
      msgBox.className = 'tip-box-msg show err';

      return;
    }

    const button = document.getElementById('reportSubmit');

    button.disabled = true;

    try {
      const contentSelect =
        document.getElementById('reportContentSelect');

      await PTCAuth.submitCourseReport(
        ref(),
        {
          courseFileId: contentSelect.value || null,
          reason: reportReason,
          details: document.getElementById('reportNote').value.trim()
        }
      );

      msgBox.textContent = 'تم إرسال البلاغ، شكرًا لمساعدتك!';
      msgBox.className = 'tip-box-msg show ok';

      /*
       * التصفير هنا فقط — بعد نجاح فعلي. لو أُغلق الحوار قبل هذا،
       * openReport لا يمسح شيئًا فتبقى المسودة كما هي.
       */
      reportReason = null;
      document.getElementById('reportNote').value = '';
      contentSelect.value = '';
      renderReportReasons();

      setTimeout(closeReport, 1200);
    } catch (error) {
      msgBox.textContent =
        error.message
        || 'تعذّر إرسال البلاغ، حاول مرة أخرى.';

      msgBox.className = 'tip-box-msg show err';
    } finally {
      button.disabled = false;
    }
  }

  async function toggleFavorite(id, button) {
    const isFav = favoriteIds.has(Number(id));
    const icon = button.querySelector('svg');

    button.disabled = true;

    try {
      if (isFav) {
        await PTCAuth.removeFavorite(id);
        favoriteIds.delete(Number(id));
        button.classList.remove('is-fav');
        button.title = 'إضافة للمفضّلة';
        if (icon) icon.setAttribute('fill', 'none');
      } else {
        await PTCAuth.addFavorite(id);
        favoriteIds.add(Number(id));
        button.classList.add('is-fav');
        button.title = 'إزالة من المفضّلة';
        if (icon) icon.setAttribute('fill', 'currentColor');
      }
    } catch (error) {
      // فشل صامت: نقرة نجمة لا تستحق حوارًا يقاطع الطالب، والحالة تبقى كما كانت.
    } finally {
      button.disabled = false;
    }
  }

  /*
   * زر "لخّصلي" — يفتح مساعد PtcHub AI ويطلب منه تلخيص هذا الملف
   * بالذات، بمعرّفه مباشرة، بلا حاجة لكتابة اسمه بالنص (وبلا احتمال
   * تلخبط الأداة بين ملفات متشابهة الاسم).
   */
  function summarize(id, button) {
    if (!window.PTCAssistant?.summarizeFile) {
      return;
    }

    if (button) button.disabled = true;

    Promise.resolve(window.PTCAssistant.summarizeFile(Number(id)))
      .finally(() => {
        if (button) button.disabled = false;
      });
  }

  /*
   * زر "بطاقات مراجعة" — نفس آلية "لخّصلي" بالضبط (يفتح المساعد بمحادثة
   * جديدة مثبَّتة على هذا الملف)، بس بطلب Gemini صراحة يرجع بتنسيق
   * سؤال/جواب صارم (mode='flashcards') يعرضه app.js كبطاقات قابلة
   * للقلب بدل نص عادي — راجع summarizeFile بالباك-إند لتفاصيل التنسيق.
   */
  function flashcards(id, button) {
    if (!window.PTCAssistant?.summarizeFile) {
      return;
    }

    if (button) button.disabled = true;

    Promise.resolve(window.PTCAssistant.summarizeFile(Number(id), 'flashcards'))
      .finally(() => {
        if (button) button.disabled = false;
      });
  }

  window.PTCCoursePage = {
    load,
    complete,
    open,
    summarize,
    flashcards,

    contribute(button) {
      window.PTCContribute
        ?.openFrom(button, currentCourse);
    },

    getCurrentCourseName() {
      return currentCourse?.name || null;
    },

    getCurrentCourseCode() {
      return currentCourse?.code || null;
    },

    openTipBox,
    closeTipBox,
    submitTip,

    openReport,
    closeReport,
    submitReport,

    toggleFavorite,
  };

  /*
   * وصول من إشعار الجرس (محتوى جديد): ?content=<id> بالرابط — نفس
   * فكرة تظليل النصيحة بالضبط، بس على عنصر محتوى داخل شجرة المادة.
   */
  function highlightContentFromUrl() {
    let contentId = '';

    try {
      contentId = new URL(location.href)
        .searchParams.get('content') || '';
    } catch (_) {
      return;
    }

    if (!contentId) return;

    const target = document.querySelector(
      `[data-content-id="${contentId}"]`
    );

    if (!target) return;

    /*
     * وصول من إشعار: الوحدة أو القسم الحاوي قد يكون مطويًّا افتراضيًا
     * (كل شيء غير المحدَّد الحالي يُطوى)، فيهبط الطالب على عنوان مطويّ
     * لا على المحتوى نفسه. نفتح كل سلف مطويّ صراحة قبل التمرير إليه.
     */
    let ancestor = target.parentElement;

    while (ancestor) {
      if (
        ancestor.classList
        && (
          ancestor.classList.contains('course-section-card')
          || ancestor.classList.contains('course-unit-card')
        )
        && ancestor.classList.contains('collapsed')
      ) {
        ancestor.classList.remove('collapsed');
      }

      ancestor = ancestor.parentElement;
    }

    target.classList.add('tip-box-item-highlight');

    target.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    });
  }

  window.addEventListener(
    'DOMContentLoaded',
    async () => {
      await load();

      let hasTip = false;

      try {
        hasTip = !!new URL(location.href)
          .searchParams.get('tip');
      } catch (_) {}

      if (hasTip) openTipBox();
    }
  );
})();