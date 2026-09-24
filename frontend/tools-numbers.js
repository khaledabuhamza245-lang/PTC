/* ────────────────────────────────────────────────────────────────────
   محوّل أنظمة العد ومكمّل الاثنين.

   BigInt لا Number: مساقات معمارية الحاسوب تتعامل مع كلمات ٦٤ بت،
   و Number يفقد الدقة فوق 2^53 — فيعطي تحويلًا خاطئًا بلا أي إنذار،
   وهو أسوأ من رفض المدخل.

   الدالّة نقية: لا DOM ولا شبكة، فتُختبر بـ node مباشرة، وتعمل بلا
   إنترنت لأنها حساب في المتصفح لا نداء خادم.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  const BASES = {
    2: { name: 'ثنائي', digits: '01', prefix: '0b', group: 4 },
    8: { name: 'ثماني', digits: '01234567', prefix: '0o', group: 3 },
    10: { name: 'عشري', digits: '0123456789', prefix: '', group: 3 },
    16: { name: 'سداسي عشري', digits: '0123456789abcdef', prefix: '0x', group: 2 }
  };

  const WIDTHS = [4, 8, 16, 32, 64];

  /* الطالب يكتب بلوحة مفاتيح عربية، فالأرقام قد تصل هندية أو فارسية. */
  function normalizeDigits(text) {
    return String(text == null ? '' : text)
      .replace(/[٠-٩]/g, d =>
        String(d.charCodeAt(0) - 0x0660))
      .replace(/[۰-۹]/g, d =>
        String(d.charCodeAt(0) - 0x06F0));
  }

  /**
   * يقرأ نصًّا في أساس معلوم.
   * يتسامح مع الفراغات والشرطات السفلية لأن الطلاب يجمّعون البتات،
   * ومع البادئة الموافقة للأساس، ولا يتسامح مع رقم خارج الأساس.
   */
  function parse(text, base) {
    const spec = BASES[base];

    if (!spec) {
      return { ok: false, error: 'أساس غير مدعوم.' };
    }

    let raw = normalizeDigits(text)
      .trim()
      .toLowerCase()
      .replace(/[\s_,]/g, '');

    if (!raw) {
      return { ok: false, error: '' };
    }

    let negative = false;

    if (raw[0] === '-' || raw[0] === '+') {
      negative = raw[0] === '-';
      raw = raw.slice(1);
    }

    if (spec.prefix && raw.startsWith(spec.prefix)) {
      raw = raw.slice(spec.prefix.length);
    }

    if (!raw) {
      return { ok: false, error: 'أدخل رقمًا بعد الإشارة.' };
    }

    for (const ch of raw) {
      if (spec.digits.indexOf(ch) === -1) {
        return {
          ok: false,
          error: 'الرمز «' + ch + '» ليس من أرقام النظام ال' + spec.name + '.'
        };
      }
    }

    let value = 0n;
    const radix = BigInt(base);

    for (const ch of raw) {
      value = value * radix + BigInt(spec.digits.indexOf(ch));
    }

    return {
      ok: true,
      value: negative ? -value : value
    };
  }

  function format(value, base) {
    if (!BASES[base]) return '';

    const text = value.toString(base);

    return base === 16 ? text.toUpperCase() : text;
  }

  /* تجميع من اليمين: البتات تُقرأ بالأنصاف والأرباع لا بالمئات. */
  function group(text, size) {
    if (!size || size < 2) return text;

    let sign = '';
    let body = String(text);

    if (body[0] === '-') {
      sign = '-';
      body = body.slice(1);
    }

    const parts = [];

    for (let end = body.length; end > 0; end -= size) {
      parts.unshift(
        body.slice(Math.max(0, end - size), end)
      );
    }

    return sign + parts.join(' ');
  }

  /** يحوّل مدخلًا واحدًا إلى الأنظمة الأربعة معًا. */
  function convert(text, base) {
    const parsed = parse(text, base);

    if (!parsed.ok) return parsed;

    const value = parsed.value;

    return {
      ok: true,
      value: value,
      negative: value < 0n,
      bin: format(value, 2),
      oct: format(value, 8),
      dec: format(value, 10),
      hex: format(value, 16)
    };
  }

  /**
   * مكمّل الاثنين بعرض ثابت.
   *
   * يقبل السالب في مداه المُوقَّع والموجب في مداه غير المُوقَّع، ويعيد
   * التفسيرين معًا: نمط البتات نفسه يعني ٢٥١ بلا إشارة و‑٥ بإشارة،
   * وعدم إظهار الاثنين هو أصل الالتباس الذي تُدرَّس هذه العملية لحلّه.
   */
  function twos(value, bits) {
    if (WIDTHS.indexOf(bits) === -1) {
      return { ok: false, error: 'عرض غير مدعوم.' };
    }

    const width = BigInt(bits);
    const span = 1n << width;
    const half = 1n << (width - 1n);

    if (value >= span || value < -half) {
      return {
        ok: false,
        error: 'لا يتّسع في ' + bits + ' بت. المدى من '
          + (-half).toString() + ' إلى ' + (span - 1n).toString() + '.'
      };
    }

    const pattern = value < 0n ? span + value : value;

    const binary = pattern
      .toString(2)
      .padStart(bits, '0');

    const signed = pattern >= half ? pattern - span : pattern;

    return {
      ok: true,
      bits: bits,
      pattern: binary,
      hex: pattern.toString(16).toUpperCase()
        .padStart(Math.ceil(bits / 4), '0'),
      unsigned: pattern.toString(),
      signed: signed.toString(),
      /* بتة الإشارة تُبرَز في الواجهة، فتُعاد منفصلة. */
      signBit: binary[0]
    };
  }

  const api = {
    BASES: BASES,
    WIDTHS: WIDTHS,
    normalizeDigits: normalizeDigits,
    parse: parse,
    format: format,
    group: group,
    convert: convert,
    twos: twos
  };

  if (typeof window !== 'undefined') window.PTCNumbers = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
