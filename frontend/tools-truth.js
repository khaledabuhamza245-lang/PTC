/* ────────────────────────────────────────────────────────────────────
   جدول الحقيقة — محلّل تعبير بولي مكتوب يدويًا.

   لماذا لا eval ولا new Function: التعبير نصّ يكتبه المستخدم. تمريره
   إلى مُنفِّذ جافاسكربت يعني تنفيذ ما يكتبه أي أحد في صفحته، وهو
   بالضبط ما يمنعه مدقّق XSS في هذا المشروع. المحلّل هنا لا ينفّذ شيئًا:
   يبني شجرة من رموز معدودة، ثم يقيّمها على قيم صفر وواحد.

   المحلّل نزول تعاودي بأسبقية:
     ↔ (تكافؤ) < → (استلزام) < ∨ (أو) < ⊕ (أو حصري) < ∧ (و) < ¬ (نفي)

   الاستلزام يمين-التجميع كالمعتاد: a→b→c تعني a→(b→c).

   حساب خالص، يُختبر بـ node.
   ──────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  /* الطلاب يكتبون بصيغ مختلفة حسب المقرر والمحاضر. كلها مقبولة. */
  const SYMBOLS = [
    [/<->|<=>|≡|↔/g, '='],
    [/->|=>|⊃|→/g, '>'],
    [/\bxor\b|⊕|\^/gi, '#'],
    [/\band\b|&&|∧|·|\*/gi, '&'],
    [/\bor\b|\|\||∨|\+/gi, '|'],
    [/\bnot\b|¬|~|!/gi, '-'],
    [/\btrue\b|\b1\b/gi, '1'],
    [/\bfalse\b|\b0\b/gi, '0']
  ];

  function normalize(text) {
    let out = String(text == null ? '' : text);

    SYMBOLS.forEach(([pattern, replacement]) => {
      out = out.replace(pattern, replacement);
    });

    return out.replace(/\s+/g, '');
  }

  /* المتغيّرات: حرف لاتيني مفرد. الحصر يمنع أي التباس مع الرموز. */
  function variables(normalized) {
    const found = normalized.match(/[A-Za-z]/g) || [];

    return [...new Set(found)].sort();
  }

  function tokenize(normalized) {
    const tokens = [];

    for (const ch of normalized) {
      if (/[A-Za-z01()=>#&|\-]/.test(ch)) {
        tokens.push(ch);
      } else {
        return { ok: false, error: 'رمز غير مفهوم: «' + ch + '»' };
      }
    }

    return { ok: true, tokens: tokens };
  }

  /* نزول تعاودي. كل مستوى يستدعي الأعلى أسبقية منه. */
  function parse(tokens) {
    let i = 0;

    const peek = () => tokens[i];
    const eat = () => tokens[i++];

    function primary() {
      const t = peek();

      if (t === undefined) {
        throw new Error('التعبير ناقص — يتوقّع قيمة.');
      }

      if (t === '(') {
        eat();
        const node = equivalence();

        if (peek() !== ')') {
          throw new Error('قوس مفتوح بلا إغلاق.');
        }

        eat();

        return node;
      }

      if (t === ')') {
        throw new Error('قوس مغلق بلا افتتاح.');
      }

      if (t === '0' || t === '1') {
        eat();

        return { type: 'const', value: t === '1' };
      }

      if (/[A-Za-z]/.test(t)) {
        eat();

        return { type: 'var', name: t };
      }

      throw new Error('يتوقّع قيمة، ووجد «' + t + '».');
    }

    function unary() {
      if (peek() === '-') {
        eat();

        return { type: 'not', a: unary() };
      }

      return primary();
    }

    function and() {
      let node = unary();

      while (peek() === '&') {
        eat();
        node = { type: 'and', a: node, b: unary() };
      }

      return node;
    }

    function xor() {
      let node = and();

      while (peek() === '#') {
        eat();
        node = { type: 'xor', a: node, b: and() };
      }

      return node;
    }

    function or() {
      let node = xor();

      while (peek() === '|') {
        eat();
        node = { type: 'or', a: node, b: xor() };
      }

      return node;
    }

    /* يمين-التجميع: a>b>c تعني a>(b>c). */
    function implies() {
      const left = or();

      if (peek() === '>') {
        eat();

        return { type: 'implies', a: left, b: implies() };
      }

      return left;
    }

    function equivalence() {
      let node = implies();

      while (peek() === '=') {
        eat();
        node = { type: 'iff', a: node, b: implies() };
      }

      return node;
    }

    const tree = equivalence();

    if (i < tokens.length) {
      throw new Error('زائد بعد نهاية التعبير: «' + tokens[i] + '».');
    }

    return tree;
  }

  function evaluate(node, env) {
    switch (node.type) {
      case 'const': return node.value;
      case 'var': return !!env[node.name];
      case 'not': return !evaluate(node.a, env);
      case 'and': return evaluate(node.a, env) && evaluate(node.b, env);
      case 'or': return evaluate(node.a, env) || evaluate(node.b, env);
      case 'xor': return evaluate(node.a, env) !== evaluate(node.b, env);
      case 'implies': return !evaluate(node.a, env) || evaluate(node.b, env);
      case 'iff': return evaluate(node.a, env) === evaluate(node.b, env);
      default: return false;
    }
  }

  /* حدّ المتغيّرات: ٢^١٠ = ١٠٢٤ صفًّا سقفٌ معقول لجدول يُقرأ بالعين. */
  const MAX_VARS = 10;

  function build(expression) {
    const normalized = normalize(expression);

    if (!normalized) {
      return { ok: false, error: '' };
    }

    const lexed = tokenize(normalized);

    if (!lexed.ok) return lexed;

    const names = variables(normalized);

    if (names.length > MAX_VARS) {
      return {
        ok: false,
        error: names.length + ' متغيّرات تعني ' + Math.pow(2, names.length)
          + ' صفًّا. الحدّ ' + MAX_VARS + '.'
      };
    }

    let tree;

    try {
      tree = parse(lexed.tokens);
    } catch (e) {
      return { ok: false, error: e.message };
    }

    const rows = [];
    const total = Math.pow(2, names.length);

    for (let mask = 0; mask < total; mask++) {
      const env = {};

      names.forEach((name, index) => {
        /* الترتيب المعتاد في الكتب: الأول يتغيّر أبطأ. */
        env[name] = !!(mask & (1 << (names.length - 1 - index)));
      });

      rows.push({
        values: names.map(name => env[name]),
        result: evaluate(tree, env)
      });
    }

    const trues = rows.filter(r => r.result).length;

    return {
      ok: true,
      variables: names,
      rows: rows,
      /* التصنيف المنطقي: يُسأل عنه مباشرة في الامتحانات. */
      classification: trues === rows.length
        ? 'تحصيل حاصل (Tautology)'
        : trues === 0
          ? 'تناقض (Contradiction)'
          : 'ممكن (Contingency)',
      trueCount: trues,
      total: rows.length
    };
  }

  const api = {
    normalize: normalize,
    variables: variables,
    build: build,
    MAX_VARS: MAX_VARS
  };

  if (typeof window !== 'undefined') window.PTCTruth = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
