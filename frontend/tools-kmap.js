/* ────────────────────────────────────────────────────────────────────
   تبسيط التعابير البولية — Quine–McCluskey.

   لماذا لا شبكة كارنو مرسومة: تظليل المجموعات المتداخلة والملتفّة حول
   حواف الشبكة بترميز Gray، بلا أي مكتبة رسم، هو ٨٠٪ من عمل الأداة.
   والخوارزمية تعطي **نفس الجواب** الذي يُستخرج من الشبكة. فهذه نسخة
   تُخرج التعبير المبسَّط والحدود الأوّلية نصًّا — أكثر قيمة الأداة
   بأقل جزء من كلفتها. الشبكة المرسومة تُضاف لاحقًا فوق هذا الأساس
   إن ثبت أن أحدًا يطلبها.

   وQuine–McCluskey تصحّ لأي عدد متغيّرات، بينما الشبكة تتوقّف عمليًا
   عند أربعة — فالنصّ هنا أوسع لا أضيق.

   حساب خالص، يُختبر بـ node.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  const MAX_VARS = 6;

  const NAMES = ['A', 'B', 'C', 'D', 'E', 'F'];

  function normalizeDigits(text) {
    return String(text == null ? '' : text)
      .replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660))
      .replace(/[۰-۹]/g, d => String(d.charCodeAt(0) - 0x06F0));
  }

  /** يقرأ قائمة أرقام مفصولة بفواصل أو فراغات. */
  function parseTerms(text, vars) {
    const raw = normalizeDigits(text).trim();

    if (!raw) return { ok: true, values: [] };

    const parts = raw.split(/[\s,;]+/).filter(Boolean);
    const limit = Math.pow(2, vars);
    const values = [];

    for (const part of parts) {
      if (!/^\d+$/.test(part)) {
        return { ok: false, error: '«' + part + '» ليس رقمًا.' };
      }

      const n = Number(part);

      if (n >= limit) {
        return {
          ok: false,
          error: 'الحدّ ' + n + ' خارج المدى: ' + vars
            + ' متغيّرات تعني 0 إلى ' + (limit - 1) + '.'
        };
      }

      if (values.indexOf(n) === -1) values.push(n);
    }

    return { ok: true, values: values.sort((a, b) => a - b) };
  }

  /* الحدّ يُمثَّل نصًّا بـ0 و1 و'-' للبتة الملغاة. */
  function toBits(value, vars) {
    return value.toString(2).padStart(vars, '0');
  }

  function countOnes(bits) {
    return (bits.match(/1/g) || []).length;
  }

  /* يدمج حدّين يختلفان في بتة واحدة فقط، وإلا null. */
  function merge(a, b) {
    let diff = -1;

    for (let i = 0; i < a.length; i++) {
      if (a[i] !== b[i]) {
        if (diff !== -1) return null;
        diff = i;
      }
    }

    if (diff === -1) return null;

    return a.slice(0, diff) + '-' + a.slice(diff + 1);
  }

  function covers(pattern, value, vars) {
    const bits = toBits(value, vars);

    for (let i = 0; i < pattern.length; i++) {
      if (pattern[i] !== '-' && pattern[i] !== bits[i]) return false;
    }

    return true;
  }

  /** المرحلة الأولى: الحدود الأوّلية (prime implicants). */
  function primeImplicants(terms, vars) {
    let groups = terms.map(v => ({ bits: toBits(v, vars), used: false }));
    const primes = new Set();

    while (groups.length) {
      const next = new Map();

      for (let i = 0; i < groups.length; i++) {
        for (let j = i + 1; j < groups.length; j++) {
          /* الدمج ممكن فقط بين مجموعتين متجاورتين في عدد الآحاد. */
          if (Math.abs(countOnes(groups[i].bits) - countOnes(groups[j].bits)) !== 1) {
            continue;
          }

          const combined = merge(groups[i].bits, groups[j].bits);

          if (combined) {
            groups[i].used = true;
            groups[j].used = true;
            next.set(combined, { bits: combined, used: false });
          }
        }
      }

      groups.forEach(g => { if (!g.used) primes.add(g.bits); });

      groups = [...next.values()];
    }

    return [...primes];
  }

  /**
   * المرحلة الثانية: اختيار تغطية.
   * الأساسية أولًا (حدّ يغطّيه واحد فقط)، ثم جشع على الباقي.
   * الجشع لا يضمن الأصغر مطلقًا في الحالات المرَضية، لكنه يطابق ما
   * يستخرجه الطالب من الشبكة في كل حالة تُدرَّس.
   */
  function selectCover(primes, minterms, vars) {
    const remaining = new Set(minterms);
    const chosen = [];
    const essentials = [];

    minterms.forEach(m => {
      const hits = primes.filter(p => covers(p, m, vars));

      if (hits.length === 1 && chosen.indexOf(hits[0]) === -1) {
        chosen.push(hits[0]);
        essentials.push(hits[0]);
      }
    });

    chosen.forEach(p => {
      minterms.forEach(m => { if (covers(p, m, vars)) remaining.delete(m); });
    });

    while (remaining.size) {
      let best = null;
      let bestCount = 0;

      primes.forEach(p => {
        if (chosen.indexOf(p) !== -1) return;

        let count = 0;
        remaining.forEach(m => { if (covers(p, m, vars)) count++; });

        if (count > bestCount) { bestCount = count; best = p; }
      });

      if (!best) break;

      chosen.push(best);
      remaining.forEach(m => { if (covers(best, m, vars)) remaining.delete(m); });
    }

    return { chosen: chosen, essentials: essentials };
  }

  /* 1-0- → A·C̄ ، والبتة الملغاة تختفي. */
  function toExpression(pattern) {
    let out = '';

    for (let i = 0; i < pattern.length; i++) {
      if (pattern[i] === '-') continue;

      out += NAMES[i] + (pattern[i] === '0' ? '̄' : '');
    }

    return out || '1';
  }

  /**
   * @param {string} minText حدود القيمة 1
   * @param {string} dcText  الحدود اللامبالية (don't care)
   * @param {number} vars    عدد المتغيّرات
   */
  function simplify(minText, dcText, vars) {
    if (!(vars >= 2 && vars <= MAX_VARS)) {
      return { ok: false, error: 'عدد المتغيّرات بين 2 و' + MAX_VARS + '.' };
    }

    const mins = parseTerms(minText, vars);

    if (!mins.ok) return mins;

    const dontCares = parseTerms(dcText, vars);

    if (!dontCares.ok) return dontCares;

    const overlap = mins.values.filter(v => dontCares.values.indexOf(v) !== -1);

    if (overlap.length) {
      return {
        ok: false,
        error: 'الحدّ ' + overlap[0] + ' مذكور كـ1 ولامبالٍ معًا.'
      };
    }

    const total = Math.pow(2, vars);

    /* الحالتان الحديّتان: الدالّة صفر دائمًا أو واحد دائمًا. */
    if (!mins.values.length) {
      return {
        ok: true, vars: vars, expression: '0',
        terms: [], essentials: [], minterms: [], dontCares: dontCares.values,
        note: 'الدالّة صفر لكل المدخلات.'
      };
    }

    if (mins.values.length + dontCares.values.length === total) {
      return {
        ok: true, vars: vars, expression: '1',
        terms: [], essentials: [], minterms: mins.values, dontCares: dontCares.values,
        note: 'الدالّة واحد لكل المدخلات.'
      };
    }

    /* اللامبالي يدخل في بناء الحدود الأوّلية ولا يُشترط تغطيته. */
    const primes = primeImplicants(
      mins.values.concat(dontCares.values).sort((a, b) => a - b),
      vars
    );

    const cover = selectCover(primes, mins.values, vars);

    return {
      ok: true,
      vars: vars,
      minterms: mins.values,
      dontCares: dontCares.values,
      primes: primes.map(p => ({ pattern: p, expression: toExpression(p) })),
      terms: cover.chosen.map(p => ({
        pattern: p,
        expression: toExpression(p),
        essential: cover.essentials.indexOf(p) !== -1
      })),
      essentials: cover.essentials,
      expression: cover.chosen.map(toExpression).join(' + '),
      note: ''
    };
  }

  const api = {
    MAX_VARS: MAX_VARS,
    NAMES: NAMES,
    parseTerms: parseTerms,
    toBits: toBits,
    merge: merge,
    covers: covers,
    primeImplicants: primeImplicants,
    toExpression: toExpression,
    simplify: simplify
  };

  if (typeof window !== 'undefined') window.PTCKmap = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
