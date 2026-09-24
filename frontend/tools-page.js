/* ────────────────────────────────────────────────────────────────────
   ربط صفحة الأدوات بالمنطق في tools-numbers.js.

   كل الحساب في الوحدة النقية المختبَرة، وهذا الملف واجهة فقط: يقرأ
   من الحقول ويكتب في العناصر. الفصل مقصود — الحساب يُختبر بـ node،
   والواجهة تُرى بالعين، ولا يختبئ منطق في معالج حدث.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  /* ───────────── تبويبات الأدوات ─────────────
     تبقى مستقلة عن الحاسبات، حتى لو تعطل ملف أداة معيّن. */
  const tabs = document.getElementById('toolTabs');

  function activateToolTab(id, updateUrl = false) {
    if (!tabs) return;

    const button = Array.prototype.find.call(
      tabs.querySelectorAll('[data-tool-tab]'),
      function (item) {
        return item.dataset.toolTab === id;
      }
    );

    const pane = document.getElementById('pane-' + id);

    if (!button || !pane) return;

    tabs.querySelectorAll('[data-tool-tab]').forEach(function (tab) {
      tab.classList.toggle('active', tab === button);
    });

    document.querySelectorAll('.tl-pane').forEach(function (item) {
      item.classList.toggle('show', item === pane);
    });

    if (updateUrl) {
      history.replaceState(null, '', '#' + id);
    }
  }

  if (tabs) {
    tabs.addEventListener('click', function (event) {
      const button = event.target.closest('[data-tool-tab]');

      if (!button) return;

      event.preventDefault();

      activateToolTab(
        button.dataset.toolTab,
        true
      );
    });

    const initialHash = (location.hash || '')
      .replace('#', '')
      .trim();

    activateToolTab(
      initialHash || 'directory',
      false
    );

    window.addEventListener('hashchange', function () {
      const hash = (location.hash || '')
        .replace('#', '')
        .trim();

      activateToolTab(
        hash || 'directory',
        false
      );
    });
  }


  /* ───────────── أنظمة العد ───────────── */

  const N = window.PTCNumbers;

  if (!N) return;

  /*
   * الاختيار بـ[data-base] لا بـ.nb-in: الصنف مشترك بين كل حقول
   * الصفحة، فاختياره كان يجعل محوّل الأنظمة يلتقط حقول الشبكات
   * والقاموس — ثم يرفضها لأنها بلا أساس، فيمسحها بعضها ببعض.
   */
  const inputs = Array.prototype.slice.call(
    document.querySelectorAll('.nb-in[data-base]')
  );

  if (!inputs.length) return;

  const msg = document.getElementById('nbMsg');
  const twOut = document.getElementById('twOut');
  const twMsg = document.getElementById('twMsg');

  /* آخر قيمة صالحة: مكمّل الاثنين يتبع المحوّل، وتغيير العرض وحده
     يجب أن يعيد الرسم بلا أن يكتب الطالب من جديد. */
  let current = null;

  let bits = 8;

  function setMessage(element, text, kind) {
    if (!element) return;

    element.textContent = text || '';
    element.className = 'nb-msg' + (text ? ' ' + kind : '');
  }

  function renderTwos() {
    if (!twOut) return;

    if (current === null) {
      twOut.replaceChildren();
      setMessage(twMsg, '', '');

      return;
    }

    const result = N.twos(current, bits);

    if (!result.ok) {
      twOut.replaceChildren();
      setMessage(twMsg, result.error, 'bad');

      return;
    }

    setMessage(twMsg, '', '');

    /*
     * التجميع يجري على النمط كاملًا ثم تُلوَّن أول بتة فقط.
     * فصلُها قبل التجميع كان يكسر محاذاة الأرباع (1 111 1011)،
     * والطالب يقرأ الثنائي بالأرباع ليطابقه بالسداسي عشر.
     */
    const grouped = N.group(result.pattern, 4);
    const rest = grouped.slice(1);

    const rows = [
      ['نمط البتات', null],
      ['سداسي عشري', '0x' + N.group(result.hex, 2)],
      ['بلا إشارة (unsigned)', result.unsigned],
      ['بإشارة (signed)', result.signed]
    ];

    twOut.replaceChildren();

    rows.forEach(function (row) {
      const line = document.createElement('div');
      line.className = 'tw-row';

      const label = document.createElement('span');
      label.className = 'tw-key';
      label.textContent = row[0];
      line.appendChild(label);

      const value = document.createElement('span');
      value.className = 'tw-val';

      if (row[1] === null) {
        const sign = document.createElement('b');
        sign.className = 'tw-sign';
        sign.textContent = result.signBit;
        value.appendChild(sign);

        const body = document.createElement('span');
        body.textContent = rest;
        value.appendChild(body);

      } else {
        value.textContent = row[1];
      }

      line.appendChild(value);
      twOut.appendChild(line);
    });
  }

  function fill(source, result) {
    const map = {
      2: 'nbBin',
      8: 'nbOct',
      10: 'nbDec',
      16: 'nbHex'
    };

    const text = {
      2: N.group(result.bin, 4),
      8: result.oct,
      10: result.dec,
      16: N.group(result.hex, 2)
    };

    Object.keys(map).forEach(function (base) {
      const field = document.getElementById(map[base]);

      /* الخانة التي يكتب فيها الطالب لا تُلمس: إعادة كتابتها تقفز
         بمؤشّر الكتابة إلى آخر النصّ عند كل حرف. */
      if (!field || field === source) return;

      field.value = text[base];
    });
  }

  function clearAll(except) {
    inputs.forEach(function (field) {
      if (field !== except) field.value = '';
    });

    current = null;
    renderTwos();
  }

  function onInput(event) {
    const field = event.target;
    const base = Number(field.dataset.base);

    if (!field.value.trim()) {
      clearAll(field);
      setMessage(msg, '', '');

      return;
    }

    const result = N.convert(field.value, base);

    if (!result.ok) {
      clearAll(field);
      setMessage(msg, result.error, 'bad');

      return;
    }

    setMessage(msg, '', '');
    fill(field, result);

    current = result.value;
    renderTwos();
  }

  inputs.forEach(function (field) {
    field.addEventListener('input', onInput);
  });

  const clear = document.getElementById('nbClear');

  if (clear) {
    clear.addEventListener('click', function () {
      clearAll(null);
      setMessage(msg, '', '');
      inputs[0].focus();
    });
  }

  const widths = document.getElementById('twWidths');

  if (widths) {
    widths.addEventListener('click', function (event) {
      const button = event.target.closest('[data-bits]');

      if (!button) return;

      bits = Number(button.dataset.bits);

      widths
        .querySelectorAll('.tw-w')
        .forEach(function (element) {
          element.classList.toggle(
            'active',
            element === button
          );
        });

      renderTwos();
    });
  }


  /* ───────────── أدوات مشتركة لبناء الجداول ───────────── */

  function cell(tag, text, className) {
    const el = document.createElement(tag);

    el.textContent =
      text == null ? '' : String(text);

    if (className) {
      el.className = className;
    }

    return el;
  }

  function table(target, headers, rows) {
    target.replaceChildren();

    if (!rows.length) return;

    const thead =
      document.createElement('thead');

    const hr =
      document.createElement('tr');

    headers.forEach(function (h) {
      hr.appendChild(
        cell('th', h)
      );
    });

    thead.appendChild(hr);
    target.appendChild(thead);

    const tbody =
      document.createElement('tbody');

    rows.forEach(function (row) {
      const tr =
        document.createElement('tr');

      if (row.className) {
        tr.className =
          row.className;
      }

      (row.cells || row).forEach(function (c) {
        tr.appendChild(
          typeof c === 'object' &&
          c !== null
            ? cell(
                'td',
                c.text,
                c.className
              )
            : cell('td', c)
        );
      });

      tbody.appendChild(tr);
    });

    target.appendChild(tbody);
  }

  function pairs(target, rows) {
    target.replaceChildren();

    rows.forEach(function (row) {
      const line =
        document.createElement('div');

      line.className =
        'tw-row';

      line.appendChild(
        cell(
          'span',
          row[0],
          'tw-key'
        )
      );

      line.appendChild(
        cell(
          'span',
          row[1],
          'tw-val'
        )
      );

      target.appendChild(line);
    });
  }


  /* ───────────── الشبكات الفرعية ───────────── */

  const S = window.PTCSubnet;

  const snIp =
    document.getElementById('snIp');

  const snPrefix =
    document.getElementById('snPrefix');

  const snNew =
    document.getElementById('snNew');

  function renderSubnet() {
    if (!S || !snIp) return;

    const out =
      document.getElementById('snOut');

    const message =
      document.getElementById('snMsg');

    if (
      !snIp.value.trim() ||
      !snPrefix.value.trim()
    ) {
      out.replaceChildren();

      setMessage(
        message,
        '',
        ''
      );

      renderSplit();

      return;
    }

    const r =
      S.calculate(
        snIp.value,
        snPrefix.value
      );

    if (!r.ok) {
      out.replaceChildren();

      setMessage(
        message,
        r.error,
        'bad'
      );

      renderSplit();

      return;
    }

    setMessage(
      message,
      r.note || '',
      'note'
    );

    pairs(
      out,
      [
        ['الشبكة', r.network],
        ['البث', r.broadcast],
        ['أول مضيف', r.firstHost],
        ['آخر مضيف', r.lastHost],
        [
          'القناع',
          r.mask +
            '  (/' +
            r.prefix +
            ')'
        ],
        [
          'القناع البديل (wildcard)',
          r.wildcard
        ],
        [
          'عدد العناوين',
          r.total.toLocaleString(
            'en-US'
          )
        ],
        [
          'المضيفات الصالحة',
          r.usable.toLocaleString(
            'en-US'
          )
        ],
        [
          'الصنف',
          r.ipClass +
            (
              r.isPrivate
                ? ' · عنوان خاص'
                : ''
            )
        ],
        [
          'العنوان ثنائيًا',
          r.ipBinary
        ],
        [
          'القناع ثنائيًا',
          r.maskBinary
        ]
      ]
    );

    renderSplit();
  }

  function renderSplit() {
    if (!S || !snNew) return;

    const out =
      document.getElementById(
        'snSplitOut'
      );

    const message =
      document.getElementById(
        'snSplitMsg'
      );

    if (
      !snNew.value.trim() ||
      !snIp.value.trim() ||
      !snPrefix.value.trim()
    ) {
      out.replaceChildren();

      setMessage(
        message,
        '',
        ''
      );

      return;
    }

    const r =
      S.split(
        snIp.value,
        snPrefix.value,
        snNew.value,
        64
      );

    if (!r.ok) {
      out.replaceChildren();

      setMessage(
        message,
        r.error,
        'bad'
      );

      return;
    }

    setMessage(
      message,
      r.count.toLocaleString(
        'en-US'
      ) +
        ' شبكة' +
        (
          r.truncated
            ? ' — تُعرض أول ' +
              r.shown +
              ' منها'
            : ''
        ),
      'note'
    );

    table(
      out,
      [
        '#',
        'الشبكة',
        'أول مضيف',
        'آخر مضيف',
        'البث'
      ],
      r.rows.map(
        function (row, i) {
          return [
            String(i + 1),
            row.network +
              '/' +
              row.prefix,
            row.firstHost,
            row.lastHost,
            row.broadcast
          ];
        }
      )
    );
  }

  [
    snIp,
    snPrefix,
    snNew
  ].forEach(function (field) {
    if (field) {
      field.addEventListener(
        'input',
        renderSubnet
      );
    }
  });


  /* ───────────── جدول الحقيقة ───────────── */

  const T = window.PTCTruth;

  const thExpr =
    document.getElementById(
      'thExpr'
    );

  function renderTruth() {
    if (!T || !thExpr) return;

    const out =
      document.getElementById(
        'thOut'
      );

    const message =
      document.getElementById(
        'thMsg'
      );

    const verdict =
      document.getElementById(
        'thVerdict'
      );

    verdict.textContent = '';

    if (
      !thExpr.value.trim()
    ) {
      out.replaceChildren();

      setMessage(
        message,
        '',
        ''
      );

      return;
    }

    const r =
      T.build(
        thExpr.value
      );

    if (!r.ok) {
      out.replaceChildren();

      setMessage(
        message,
        r.error,
        'bad'
      );

      return;
    }

    setMessage(
      message,
      '',
      ''
    );

    verdict.textContent =
      r.classification +
      ' — صحيح في ' +
      r.trueCount +
      ' من ' +
      r.total +
      ' صفًّا';

    table(
      out,
      r.variables.concat([
        'النتيجة'
      ]),
      r.rows.map(
        function (row) {
          return {
            className:
              row.result
                ? 'th-true'
                : '',

            cells:
              row.values
                .map(function (v) {
                  return v
                    ? '1'
                    : '0';
                })
                .concat([
                  {
                    text:
                      row.result
                        ? '1'
                        : '0',

                    className:
                      row.result
                        ? 'th-yes'
                        : 'th-no'
                  }
                ])
          };
        }
      )
    );
  }

  if (thExpr) {
    thExpr.addEventListener(
      'input',
      renderTruth
    );
  }


  /* ───────────── Big-O ───────────── */

  const B =
    window.PTCBigO;

  const boN =
    document.getElementById(
      'boN'
    );

  function renderBigO() {
    if (!B || !boN) return;

    const out =
      document.getElementById(
        'boOut'
      );

    const message =
      document.getElementById(
        'boMsg'
      );

    const r =
      B.stepsAt(
        boN.value
      );

    if (!r.ok) {
      out.replaceChildren();

      setMessage(
        message,
        r.error,
        'bad'
      );

      return;
    }

    setMessage(
      message,
      '',
      ''
    );

    table(
      out,
      [
        'الرتبة',
        'الاسم',
        'الخطوات عند n=' +
          r.n,
        'مثال'
      ],
      r.rows.map(
        function (row) {
          return [
            {
              text:
                row.label,

              className:
                'bo-label'
            },

            row.name,

            {
              text:
                row.display,

              className:
                'bo-steps'
            },

            row.example
          ];
        }
      )
    );
  }

  function renderChart() {
    const holder =
      document.getElementById(
        'boChart'
      );

    if (!B || !holder) {
      return;
    }

    const W = 560;
    const H = 260;

    const NS =
      'http://www.w3.org/2000/svg';

    const svg =
      document.createElementNS(
        NS,
        'svg'
      );

    svg.setAttribute(
      'viewBox',
      '0 0 ' +
        W +
        ' ' +
        H
    );

    svg.setAttribute(
      'role',
      'img'
    );

    svg.setAttribute(
      'aria-label',
      'مقارنة منحنيات رتب النمو'
    );

    for (
      let i = 1;
      i < 5;
      i++
    ) {
      const line =
        document.createElementNS(
          NS,
          'line'
        );

      const y =
        (H / 5) * i;

      line.setAttribute(
        'x1',
        '0'
      );

      line.setAttribute(
        'x2',
        String(W)
      );

      line.setAttribute(
        'y1',
        String(y)
      );

      line.setAttribute(
        'y2',
        String(y)
      );

      line.setAttribute(
        'class',
        'bo-grid'
      );

      svg.appendChild(
        line
      );
    }

    B.ORDERS.forEach(
      function (
        order,
        index
      ) {
        const path =
          document.createElementNS(
            NS,
            'path'
          );

        path.setAttribute(
          'd',
          B.curvePath(
            order.id,
            {
              width: W,
              height: H,
              maxN: 32
            }
          )
        );

        path.setAttribute(
          'class',
          'bo-curve bo-c' +
            index
        );

        path.setAttribute(
          'fill',
          'none'
        );

        svg.appendChild(
          path
        );
      }
    );

    holder.replaceChildren(
      svg
    );

    const legend =
      document.getElementById(
        'boLegend'
      );

    if (legend) {
      legend.replaceChildren();

      B.ORDERS.forEach(
        function (
          order,
          index
        ) {
          const item =
            document.createElement(
              'span'
            );

          item.className =
            'bo-key';

          const swatch =
            document.createElement(
              'i'
            );

          swatch.className =
            'bo-swatch bo-c' +
            index;

          item.appendChild(
            swatch
          );

          item.appendChild(
            document.createTextNode(
              order.label
            )
          );

          legend.appendChild(
            item
          );
        }
      );
    }
  }

  if (boN) {
    boN.addEventListener(
      'input',
      renderBigO
    );
  }


  /* ───────────── التبسيط البولي ───────────── */

  const KM =
    window.PTCKmap;

  const kmMin =
    document.getElementById(
      'kmMin'
    );

  const kmDc =
    document.getElementById(
      'kmDc'
    );

  const kmVars =
    document.getElementById(
      'kmVars'
    );

  function renderKmap() {
    if (!KM || !kmMin) {
      return;
    }

    const result =
      document.getElementById(
        'kmResult'
      );

    const terms =
      document.getElementById(
        'kmTerms'
      );

    const message =
      document.getElementById(
        'kmMsg'
      );

    if (
      !kmMin.value.trim()
    ) {
      result.replaceChildren();
      terms.replaceChildren();

      setMessage(
        message,
        '',
        ''
      );

      return;
    }

    const r =
      KM.simplify(
        kmMin.value,
        kmDc
          ? kmDc.value
          : '',
        Number(
          kmVars.value
        )
      );

    if (!r.ok) {
      result.replaceChildren();
      terms.replaceChildren();

      setMessage(
        message,
        r.error,
        'bad'
      );

      return;
    }

    setMessage(
      message,
      r.note || '',
      'note'
    );

    result.replaceChildren();

    result.appendChild(
      cell(
        'span',
        'F = ',
        'km-eq'
      )
    );

    result.appendChild(
      cell(
        'b',
        r.expression,
        'km-expr'
      )
    );

    if (!r.terms.length) {
      terms.replaceChildren();

      return;
    }

    table(
      terms,
      [
        'الحدّ',
        'النمط',
        'أساسي؟',
        'مُختار؟'
      ],
      r.primes.map(
        function (prime) {
          const picked =
            r.terms.find(
              function (t) {
                return (
                  t.pattern ===
                  prime.pattern
                );
              }
            );

          return {
            className:
              picked
                ? 'th-true'
                : '',

            cells: [
              {
                text:
                  prime.expression,

                className:
                  'bo-label'
              },

              prime.pattern,

              picked &&
              picked.essential
                ? 'نعم'
                : '—',

              picked
                ? 'نعم'
                : '—'
            ]
          };
        }
      )
    );
  }

  [
    kmMin,
    kmDc,
    kmVars
  ].forEach(function (field) {
    if (field) {
      field.addEventListener(
        'input',
        renderKmap
      );
    }
  });

  if (kmVars) {
    kmVars.addEventListener(
      'change',
      renderKmap
    );
  }


  /* ───────────── القاموس ───────────── */

  const G =
    window.PTCGlossary;

  const glSearch =
    document.getElementById(
      'glSearch'
    );

  function renderGlossary() {
    if (!G) return;

    const out =
      document.getElementById(
        'glOut'
      );

    const message =
      document.getElementById(
        'glMsg'
      );

    if (!out) return;

    const query =
      glSearch
        ? glSearch.value
        : '';

    const found =
      G.search(
        query
      );

    setMessage(
      message,
      found.length
        ? found.length +
          ' من ' +
          G.all.length +
          ' مصطلحًا'
        : 'لا مصطلح يطابق البحث.',
      found.length
        ? 'note'
        : 'bad'
    );

    out.replaceChildren();

    found.forEach(
      function (term) {
        const card =
          document.createElement(
            'div'
          );

        card.className =
          'gl-item';

        const head =
          document.createElement(
            'div'
          );

        head.className =
          'gl-head';

        head.appendChild(
          cell(
            'b',
            term.en,
            'gl-en'
          )
        );

        head.appendChild(
          cell(
            'span',
            term.ar,
            'gl-ar'
          )
        );

        card.appendChild(
          head
        );

        card.appendChild(
          cell(
            'p',
            term.def,
            'gl-def'
          )
        );

        if (
          term.courses.length
        ) {
          const tags =
            document.createElement(
              'div'
            );

          tags.className =
            'gl-tags';

          term.courses.forEach(
            function (code) {
              tags.appendChild(
                cell(
                  'span',
                  code,
                  'gl-tag'
                )
              );
            }
          );

          card.appendChild(
            tags
          );
        }

        out.appendChild(
          card
        );
      }
    );
  }

  if (glSearch) {
    glSearch.addEventListener(
      'input',
      renderGlossary
    );
  }


  /* رسم أولي */

  renderBigO();
  renderChart();
  renderGlossary();

})();