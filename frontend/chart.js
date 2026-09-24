/* ────────────────────────────────────────────────────────────────────
   رسوم بيانية بسيطة — بلا مكتبة وبلا خطوة build.

   لماذا وحدة مستقلة لا رسم مخصص داخل admin.html: لوحة التحكم تحتاج
   أربعة رسوم على الأقل (انضمام الطلاب، الطلاب حسب السنة، الزيارات
   اليومية، أكثر المواد زيارة). كتابة كل واحد على حدة تعني أربع نسخ
   تتباعد عند أول تعديل في التنسيق.

   ولماذا createElementNS لا قوالب نصية: بناء SVG بسلسلة نصّية يمرّ
   بسياق HTML، فيلزمه هروب في كل قيمة ويقع تحت مدقّق XSS. البناء بالعقد
   يجعل كل قيمة نصًّا لا وسمًا بحكم الـDOM نفسه — لا هروب ولا استثناء.
   ──────────────────────────────────────────────────────────────────── */
const PTCChart = (function () {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';

  const VIEW_W = 720;
  const PAD = { top: 18, right: 10, bottom: 36, left: 38 };

  function node(name, attrs) {
    const el = document.createElementNS(NS, name);

    Object.keys(attrs || {}).forEach(function (key) {
      el.setAttribute(key, String(attrs[key]));
    });

    return el;
  }

  function text(value, attrs) {
    const el = node('text', attrs);
    el.textContent = String(value);
    return el;
  }

  function resolve(target) {
    return typeof target === 'string'
      ? document.getElementById(target)
      : target;
  }

  /*
   * أرقام المحور: نصفٌ وقمة فقط.
   *
   * القمة تُدوَّر لأعلى إلى رقم مقروء (٥، ١٠، ٢٥...) بدل أن تكون قيمة
   * البيانات نفسها — عمودٌ يلامس السقف تمامًا يبدو مقصوصًا، والقارئ
   * لا يعرف إن كان فوقه المزيد.
   */
  function niceMax(value) {
    if (value <= 0) return 1;

    const steps = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000];

    for (let i = 0; i < steps.length; i++) {
      if (value <= steps[i]) return steps[i];
    }

    return Math.ceil(value / 1000) * 1000;
  }

  /**
   * رسم أعمدة.
   *
   * @param {HTMLElement|string} target  الحاوية أو معرّفها
   * @param {Array<{label:string,value:number,hint?:string}>} points
   * @param {{height?:number,emptyText?:string,maxLabels?:number}} [options]
   */
  function bars(target, points, options) {
    const box = resolve(target);
    if (!box) return;

    const opts = options || {};
    const data = (points || []).map(function (point) {
      return {
        label: String(point && point.label != null ? point.label : ''),
        value: Math.max(0, Number(point && point.value) || 0),
        hint: point && point.hint ? String(point.hint) : '',
      };
    });

    box.replaceChildren();

    if (!data.length) {
      const empty = document.createElement('div');
      empty.className = 'chart-empty';
      empty.textContent = opts.emptyText || 'لا بيانات لعرضها.';
      box.appendChild(empty);
      return;
    }

    const viewH = Number(opts.height) || 210;
    const plotW = VIEW_W - PAD.left - PAD.right;
    const plotH = viewH - PAD.top - PAD.bottom;

    const peak = data.reduce(function (max, point) {
      return Math.max(max, point.value);
    }, 0);

    const top = niceMax(peak);

    /*
     * بلا preserveAspectRatio="none": التمديد غير المتناسب يسحب الحروف
     * أفقيًّا فتصير الأرقام عريضة مشوّهة. viewBox ثابت مع width:100%
     * وheight:auto في CSS يعطي تحجيمًا متناسبًا يبقي النصّ سليمًا.
     */
    const svg = node('svg', {
      viewBox: '0 0 ' + VIEW_W + ' ' + viewH,
      class: 'ptc-chart',
      role: 'img',
      'aria-label': opts.ariaLabel || 'رسم بياني',
    });

    /* ── خطوط المسطرة الثلاثة: صفر ونصف وقمة ── */
    [0, 0.5, 1].forEach(function (ratio) {
      const y = PAD.top + plotH - plotH * ratio;

      svg.appendChild(node('line', {
        x1: PAD.left, x2: VIEW_W - PAD.right, y1: y, y2: y,
        class: ratio === 0 ? 'chart-axis' : 'chart-grid',
      }));

      svg.appendChild(text(Math.round(top * ratio), {
        x: PAD.left - 8, y: y + 4,
        class: 'chart-tick', 'text-anchor': 'end',
      }));
    });

    /* ── الأعمدة ── */
    const slot = plotW / data.length;
    const width = Math.max(2, Math.min(38, slot * 0.62));

    /*
     * تخفيف الوسوم: ثلاثون يومًا على عرض واحد تتراكب حروفها فتصير
     * سطرًا رماديًا لا يُقرأ. نعرض واحدًا كل خطوة محسوبة، وأول وآخر
     * يومٍ دائمًا لأنهما حدّا المدى وبهما تُقرأ البقية.
     */
    const maxLabels = Number(opts.maxLabels) || 8;
    const every = Math.max(1, Math.ceil(data.length / maxLabels));

    data.forEach(function (point, index) {
      const center = PAD.left + slot * index + slot / 2;
      const height = top > 0 ? (point.value / top) * plotH : 0;

      const group = node('g', { class: 'chart-bar-group' });

      const title = node('title', {});
      title.textContent = point.hint || point.label + ': ' + point.value;
      group.appendChild(title);

      /*
       * عمود القيمة صفر يبقى شعرة مرئية لا فراغًا: الفراغ يُقرأ «لا
       * بيانات لهذا اليوم» بينما المعنى «لم ينضم أحد» — والفرق كبير.
       */
      const drawn = Math.max(point.value > 0 ? 2 : 1, height);

      group.appendChild(node('rect', {
        x: center - width / 2,
        y: PAD.top + plotH - drawn,
        width: width,
        height: drawn,
        rx: Math.min(3, width / 2),
        class: point.value > 0 ? 'chart-bar' : 'chart-bar chart-bar-zero',
      }));

      if (point.value > 0 && data.length <= 14) {
        svg.appendChild(text(point.value, {
          x: center, y: PAD.top + plotH - drawn - 5,
          class: 'chart-value', 'text-anchor': 'middle',
        }));
      }

      const isEdge = index === 0 || index === data.length - 1;

      if (isEdge || index % every === 0) {
        svg.appendChild(text(point.label, {
          x: center, y: viewH - 12,
          class: 'chart-label', 'text-anchor': 'middle',
        }));
      }

      svg.appendChild(group);
    });

    box.appendChild(svg);

    if (!peak && opts.emptyText) {
      const note = document.createElement('div');
      note.className = 'chart-empty';
      note.textContent = opts.emptyText;
      box.appendChild(note);
    }
  }

  /** يوم بصيغة قصيرة للوسم: 2026-08-09 ← 8/9 */
  function shortDay(iso) {
    const parts = String(iso || '').split('-');
    return parts.length === 3 ? Number(parts[1]) + '/' + Number(parts[2]) : String(iso || '');
  }

  return { bars, shortDay };
})();
