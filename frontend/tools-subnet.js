/* ────────────────────────────────────────────────────────────────────
   حاسبة الشبكات الفرعية — IPv4.

   الفخّ الحاكم: عمليات البت في JavaScript تعمل على عدد صحيح مُوقَّع
   بـ٣٢ بت. فـ (255 << 24) تساوي ‑16777216 لا 4278190080، و ~0 تساوي
   ‑1 لا 4294967295. أي عنوان بتة أولى ≥ 128 يخرج سالبًا فيُطبع خطأً.
   لذلك كل ناتج بتّي هنا يمرّ بـ >>> 0 بلا استثناء.

   والحالتان اللتان تُخطئ فيهما أكثر الحاسبات: /31 و /32. القاعدة
   الكلاسيكية «المضيفات = 2^n ‑ 2» تعطي صفرًا وسالبًا فيهما، بينما
   /31 وصلة نقطة‑لنقطة بمضيفَين (RFC 3021) و /32 مضيف واحد.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  /* الأرقام الهندية والفارسية: الطالب على لوحة مفاتيح عربية. */
  function normalizeDigits(text) {
    return String(text == null ? '' : text)
      .replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660))
      .replace(/[۰-۹]/g, d => String(d.charCodeAt(0) - 0x06F0));
  }

  function parseIp(text) {
    const raw = normalizeDigits(text).trim();

    if (!raw) return { ok: false, error: '' };

    const parts = raw.split('.');

    if (parts.length !== 4) {
      return { ok: false, error: 'العنوان يتكوّن من أربع خانات مفصولة بنقاط.' };
    }

    let value = 0;

    for (const part of parts) {
      if (!/^\d{1,3}$/.test(part)) {
        return { ok: false, error: 'الخانة «' + part + '» ليست رقمًا صالحًا.' };
      }

      const n = Number(part);

      if (n > 255) {
        return { ok: false, error: 'الخانة ' + n + ' أكبر من 255.' };
      }

      /* الضرب لا الإزاحة: 256 * قيمة تتجاوز حدود العدد المُوقَّع بأمان. */
      value = value * 256 + n;
    }

    return { ok: true, value: value >>> 0 };
  }

  function formatIp(value) {
    const n = value >>> 0;

    return [
      (n >>> 24) & 255,
      (n >>> 16) & 255,
      (n >>> 8) & 255,
      n & 255
    ].join('.');
  }

  function toBinary(value) {
    return (value >>> 0)
      .toString(2)
      .padStart(32, '0')
      .replace(/(.{8})(?=.)/g, '$1.');
  }

  /* قناع البادئة. الحالة 0 تُعالَج منفصلة: الإزاحة بـ32 في JS تلتفّ
     إلى إزاحة بصفر فتعطي القناع الكامل بدل الصفر. */
  function maskFromPrefix(prefix) {
    if (prefix === 0) return 0;

    return (0xFFFFFFFF << (32 - prefix)) >>> 0;
  }

  function prefixFromMask(mask) {
    const m = mask >>> 0;
    const binary = m.toString(2).padStart(32, '0');

    /* قناع صالح: آحاد متصلة ثم أصفار، لا خليط. */
    if (!/^1*0*$/.test(binary)) return null;

    return (binary.match(/1/g) || []).length;
  }

  function parsePrefix(text) {
    const raw = normalizeDigits(text).trim();

    if (!raw) return { ok: false, error: '' };

    /* يقبل القناع النقطي أيضًا: 255.255.255.0 */
    if (raw.indexOf('.') !== -1) {
      const parsed = parseIp(raw);

      if (!parsed.ok) return parsed;

      const prefix = prefixFromMask(parsed.value);

      if (prefix === null) {
        return {
          ok: false,
          error: 'قناع غير صالح: البتات يجب أن تكون آحادًا متصلة ثم أصفارًا.'
        };
      }

      return { ok: true, value: prefix };
    }

    if (!/^\d{1,2}$/.test(raw)) {
      return { ok: false, error: 'البادئة رقم بين 0 و32.' };
    }

    const n = Number(raw);

    if (n > 32) {
      return { ok: false, error: 'البادئة لا تتجاوز 32.' };
    }

    return { ok: true, value: n };
  }

  /* صنف العنوان بالتقسيم القديم — لا يزال يُسأل عنه في المقررات. */
  function ipClass(value) {
    const first = (value >>> 24) & 255;

    if (first < 128) return 'A';
    if (first < 192) return 'B';
    if (first < 224) return 'C';
    if (first < 240) return 'D (بث متعدد)';

    return 'E (محجوز)';
  }

  function isPrivate(value) {
    const a = (value >>> 24) & 255;
    const b = (value >>> 16) & 255;

    return a === 10
      || (a === 172 && b >= 16 && b <= 31)
      || (a === 192 && b === 168);
  }

  function analyze(ip, prefix) {
    const mask = maskFromPrefix(prefix);
    const network = (ip & mask) >>> 0;
    const wildcard = (~mask) >>> 0;
    const broadcast = (network | wildcard) >>> 0;

    /* 2^32 يتجاوز مدى العدد الصحيح الآمن؟ لا — لكنه يتجاوز مدى
       العمليات البتّية، فيُحسب بالأسّ لا بالإزاحة. */
    const total = Math.pow(2, 32 - prefix);

    let usable;
    let first;
    let last;

    if (prefix === 32) {
      /* مضيف واحد: العنوان نفسه. */
      usable = 1;
      first = network;
      last = network;

    } else if (prefix === 31) {
      /* وصلة نقطة‑لنقطة: لا شبكة ولا بث، العنوانان مضيفان. */
      usable = 2;
      first = network;
      last = broadcast;

    } else {
      usable = total - 2;
      first = (network + 1) >>> 0;
      last = (broadcast - 1) >>> 0;
    }

    return {
      ok: true,
      prefix: prefix,
      mask: formatIp(mask),
      wildcard: formatIp(wildcard),
      network: formatIp(network),
      broadcast: prefix >= 31 ? '—' : formatIp(broadcast),
      firstHost: formatIp(first),
      lastHost: formatIp(last),
      total: total,
      usable: usable,
      ipClass: ipClass(ip),
      isPrivate: isPrivate(ip),
      ipBinary: toBinary(ip),
      maskBinary: toBinary(mask),
      networkBinary: toBinary(network),
      /* ملاحظة تُعرض للطالب في الحالتين الشاذّتين لا تُخفى عنه. */
      note: prefix === 32
        ? 'بادئة /32 تعني مضيفًا واحدًا لا شبكة.'
        : prefix === 31
          ? 'بادئة /31 وصلة نقطة‑لنقطة: العنوانان مضيفان ولا يوجد بث (RFC 3021).'
          : ''
    };
  }

  function calculate(ipText, prefixText) {
    const ip = parseIp(ipText);

    if (!ip.ok) return ip;

    const prefix = parsePrefix(prefixText);

    if (!prefix.ok) return prefix;

    return analyze(ip.value, prefix.value);
  }

  /**
   * تقسيم شبكة إلى شبكات فرعية ببادئة أطول.
   * limit يمنع توليد ملايين الصفوف على تقسيم واسع.
   */
  function split(ipText, prefixText, newPrefixText, limit) {
    const base = calculate(ipText, prefixText);

    if (!base.ok) return base;

    const target = parsePrefix(newPrefixText);

    if (!target.ok) return target;

    if (target.value < base.prefix) {
      return {
        ok: false,
        error: 'البادئة الجديدة يجب أن تكون أطول من ' + base.prefix + '.'
      };
    }

    const count = Math.pow(2, target.value - base.prefix);
    const step = Math.pow(2, 32 - target.value);
    const cap = limit || 64;

    const network = parseIp(base.network).value;
    const rows = [];

    for (let i = 0; i < Math.min(count, cap); i++) {
      /* الجمع لا الإزاحة: الناتج قد يتجاوز 2^31 فيصير سالبًا. */
      const start = (network + i * step) >>> 0;

      rows.push(analyze(start, target.value));
    }

    return {
      ok: true,
      count: count,
      shown: rows.length,
      truncated: count > cap,
      step: step,
      rows: rows
    };
  }

  const api = {
    normalizeDigits: normalizeDigits,
    parseIp: parseIp,
    formatIp: formatIp,
    toBinary: toBinary,
    maskFromPrefix: maskFromPrefix,
    prefixFromMask: prefixFromMask,
    parsePrefix: parsePrefix,
    analyze: analyze,
    calculate: calculate,
    split: split
  };

  if (typeof window !== 'undefined') window.PTCSubnet = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
