/* ────────────────────────────────────────────────────────────────────
   مرجع Big-O: مقارنة رتب النمو عدديًا ورسمًا.

   القيمة ليست في حفظ الترتيب — ذاك في أي كتاب — بل في رؤية الفجوة:
   عند n = 1000 تفعل O(n log n) عشرة آلاف خطوة و O(n²) مليونًا. الرقم
   يُقنع حيث لا يُقنع الترتيب.

   والرسم يُبنى بمسارات SVG محسوبة هنا لا بمكتبة رسم: المشروع بلا خطوة
   build وبلا اعتماديات.

   حساب خالص، يُختبر بـ node.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  /*
   * الترتيب تصاعدي بالنمو. كل رتبة: دالّتها، ومثال مألوف من المقرر.
   * لا لوغاريتم للصفر: n تبدأ من 1 دائمًا في هذا السياق.
   */
  const ORDERS = [
    {
      id: 'constant',
      label: 'O(1)',
      name: 'ثابت',
      example: 'الوصول لعنصر بمصفوفة بالفهرس',
      fn: () => 1
    },
    {
      id: 'log',
      label: 'O(log n)',
      name: 'لوغاريتمي',
      example: 'البحث الثنائي',
      fn: n => Math.log2(n)
    },
    {
      id: 'linear',
      label: 'O(n)',
      name: 'خطي',
      example: 'البحث الخطي · المرور على مصفوفة',
      fn: n => n
    },
    {
      id: 'linearithmic',
      label: 'O(n log n)',
      name: 'خطي لوغاريتمي',
      example: 'Merge Sort · Quick Sort (المتوسط)',
      fn: n => n * Math.log2(n)
    },
    {
      id: 'quadratic',
      label: 'O(n²)',
      name: 'تربيعي',
      example: 'Bubble Sort · حلقتان متداخلتان',
      fn: n => n * n
    },
    {
      id: 'cubic',
      label: 'O(n³)',
      name: 'تكعيبي',
      example: 'ضرب مصفوفات بالطريقة المباشرة',
      fn: n => n * n * n
    },
    {
      id: 'exponential',
      label: 'O(2ⁿ)',
      name: 'أسّي',
      example: 'فيبوناتشي التعاودي بلا حفظ',
      fn: n => Math.pow(2, n)
    },
    {
      id: 'factorial',
      label: 'O(n!)',
      name: 'عاملي',
      example: 'توليد كل التباديل',
      fn: n => {
        let r = 1;

        for (let i = 2; i <= n; i++) {
          r *= i;

          /* يتجاوز مدى العدد سريعًا؛ نتوقف بدل إعادة Infinity صامتة. */
          if (!isFinite(r)) return Infinity;
        }

        return r;
      }
    }
  ];

  function byId(id) {
    return ORDERS.find(o => o.id === id) || null;
  }

  /* تنسيق الأعداد الضخمة: ١٫٢٧e30 لا سلسلة أرقام لا تُقرأ. */
  function formatSteps(value) {
    if (!isFinite(value)) return '∞';
    if (value < 1) return value.toFixed(2);

    const rounded = Math.round(value);

    if (rounded < 1e6) {
      return rounded.toLocaleString('en-US');
    }

    return rounded.toExponential(2).replace('e+', '×10^');
  }

  /** عدد الخطوات لكل رتبة عند حجم مدخل معيّن. */
  function stepsAt(n) {
    const size = Number(n);

    if (!isFinite(size) || size < 1) {
      return { ok: false, error: 'حجم المدخل عدد صحيح موجب.' };
    }

    return {
      ok: true,
      n: size,
      rows: ORDERS.map(order => {
        const raw = order.fn(size);

        return {
          id: order.id,
          label: order.label,
          name: order.name,
          example: order.example,
          steps: raw,
          display: formatSteps(raw)
        };
      })
    };
  }

  /**
   * مسار SVG لمنحنى رتبة داخل صندوق width×height.
   * القياس لوغاريتمي على المحور الرأسي: بلا ذلك يبتلع O(n!) الرسم
   * كله فتصير كل المنحنيات الأخرى خطًّا واحدًا على المحور.
   */
  function curvePath(id, options) {
    const order = byId(id);

    if (!order) return '';

    const opt = options || {};
    const width = opt.width || 520;
    const height = opt.height || 260;
    const maxN = opt.maxN || 32;
    const pad = opt.pad || 4;

    /* السقف: أعلى قيمة منطقية نعرضها، وما فوقها يُقصّ عند الحافة. */
    const ceiling = opt.ceiling || Math.pow(2, maxN);
    const logCeil = Math.log2(ceiling);

    const points = [];

    for (let i = 0; i <= 100; i++) {
      const n = 1 + (maxN - 1) * (i / 100);
      const raw = order.fn(n);

      const value = isFinite(raw) ? raw : ceiling;
      const clamped = Math.max(1, Math.min(value, ceiling));

      const x = pad + (width - 2 * pad) * (i / 100);
      const y = height - pad
        - (height - 2 * pad) * (Math.log2(clamped) / logCeil);

      points.push(
        x.toFixed(1) + ',' + y.toFixed(1)
      );
    }

    return 'M' + points.join(' L');
  }

  const api = {
    ORDERS: ORDERS,
    byId: byId,
    formatSteps: formatSteps,
    stepsAt: stepsAt,
    curvePath: curvePath
  };

  if (typeof window !== 'undefined') window.PTCBigO = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
