/*
 * شجرة التخرّج النامية — بديل بصري للدائرة الرقمية بصفحة "حسابي".
 * ملف مستقل تمامًا عن profile-page.js عمدًا: أقل خطرًا من حشر منطق
 * رسم جديد داخل ملف كبير قائم أصلًا، وأسهل صيانة لاحقًا لحاله.
 *
 * الفكرة: شجرة واحدة، عناصرها (الجذع، خمس مجموعات أغصان وأوراق،
 * أزهار القمة) تُكشَف تباعًا مع ارتفاع النسبة — لا سبع رسومات منفصلة
 * يُختار بينها، بل شجرة واحدة حقيقية تكبر أمام عينيك بمرور تقدّمك.
 */
(function () {
  /*
   * كل مجموعة (غصن + عنقود أوراق) لها عتبة نسبة تظهر عندها، وموضعها
   * على الجذع (ارتفاع 0 القاعدة، 1 القمة) وزاوية ميلها. القيم هنا
   * صيغت يدويًا لتوزيع متوازن يشبه شجرة حقيقية لا نمطًا آليًا متكررًا.
   */
  const BRANCHES = [
    { at: 12, side: -1, height: 0.38, angle: -34, scale: 0.82 },
    { at: 24, side: 1, height: 0.48, angle: 30, scale: 0.9 },
    { at: 38, side: -1, height: 0.62, angle: -28, scale: 1 },
    { at: 52, side: 1, height: 0.7, angle: 26, scale: 1.05 },
    { at: 66, side: -1, height: 0.84, angle: -22, scale: 0.95 },
    { at: 80, side: 1, height: 0.9, angle: 20, scale: 0.88 },
  ];

  const STAGES = [
    { min: 0, label: 'بذرة مزروعة' },
    { min: 8, label: 'شتلة صغيرة' },
    { min: 24, label: 'شجرة يافعة' },
    { min: 45, label: 'شجرة نامية' },
    { min: 65, label: 'شجرة مورقة' },
    { min: 85, label: 'شجرة مزهرة' },
    { min: 100, label: 'شجرة مكتملة النمو <i data-icon="graduation"></i>' },
  ];

  function stageLabel(pct) {
    let label = STAGES[0].label;

    for (const stage of STAGES) {
      if (pct >= stage.min) label = stage.label;
    }

    return label;
  }

  /* ارتفاع الجذع يتحرك بين حد أدنى (بذرة لا تُرى بالعدم) وحد أقصى،
     غير خطي قليلًا (جذر تربيعي) حتى يبان نمو محسوس بأول نسب صغيرة
     بدل ما يبقى الجذع شبه ثابت لحد نص الطريق. */
  function trunkHeight(pct) {
    const min = 18;
    const max = 128;

    return min + (max - min) * Math.sqrt(pct / 100);
  }

  function leafCluster(cx, cy, scale, colorVar) {
    /* عنقود أوراق: خمس دوائر متداخلة بأحجام وإزاحات مختلفة، لا دائرة
       واحدة — هذا وحده الفرق بين "بالون أخضر" و"ورقة شجر". الإحداثيات
       مطلقة (cx, cy هما موقع طرف الغصن الفعلي) لا نسبية داخل مجموعة
       منقولة بـtransform: تحريك css scale لاحقًا يُلغي أي transform
       بصفة SVG على نفس العنصر، فأي ترحيل بـ<g transform="translate">
       كان يضيع بمجرد ما يُطبَّق css transform عليه أو على أبيه.
       الحل: نضع كل دائرة بموقعها الحقيقي مباشرة. */
    const blobs = [
      [0, 0, 13],
      [-10, 4, 10],
      [9, 5, 10],
      [-4, -9, 9],
      [6, -8, 9],
    ];

    return blobs
      .map(
        ([dx, dy, r]) => `
          <circle
            cx="${cx + dx * scale}"
            cy="${cy + dy * scale}"
            r="${r * scale}"
            fill="${colorVar}"
          />
        `
      )
      .join('');
  }

  function branchMarkup(trunkX, groundY, height, branch, pct) {
    const grown = pct >= branch.at;
    const branchY = groundY - height * branch.height;
    const reach = 34 * branch.scale * branch.side;
    const tipX = trunkX + reach;
    const tipY = branchY - 22 * branch.scale;
    const midX = trunkX + reach * 0.55;
    const midY = branchY - 6;

    /* نسبة التقدّم داخل عتبة هذا الغصن تحديدًا (لا نسبة الخطة كلها) —
       تتيح تكبّر العنقود تدريجيًا لحظة ظهوره بدل قفزة كاملة الحجم. */
    const nextAt = branch.at + 10;
    const localGrowth = grown
      ? Math.min(1, (pct - branch.at) / (nextAt - branch.at) + 0.4)
      : 0;

    return `
      <g class="growth-branch${grown ? ' is-grown' : ''}" style="--local:${localGrowth}">
        <path
          d="M ${trunkX} ${branchY} Q ${midX} ${midY} ${tipX} ${tipY}"
          class="growth-branch-line"
        />
        <g
          class="growth-leaf-cluster"
          style="transform-origin:${tipX}px ${tipY}px"
        >
          ${leafCluster(tipX, tipY, branch.scale, 'var(--growth-leaf)')}
        </g>
      </g>
    `;
  }

  function fruitMarkup(trunkX, groundY, height, branch, pct) {
    if (pct < 85) return '';

    const branchY = groundY - height * branch.height;
    const reach = 34 * branch.scale * branch.side;
    const tipX = trunkX + reach;
    const tipY = branchY - 22 * branch.scale;

    return `
      <circle
        class="growth-fruit"
        cx="${tipX + 5 * branch.side}"
        cy="${tipY + 7}"
        r="4"
      />
    `;
  }

  function render(container, pct) {
    pct = Math.max(0, Math.min(100, Number(pct) || 0));

    const groundY = 172;
    const trunkX = 100;
    const height = trunkHeight(pct);
    const topY = groundY - height;
    const baseWidth = 5 + 9 * (height / 128);

    const branches = BRANCHES.map(b =>
      branchMarkup(trunkX, groundY, height, b, pct)
    ).join('');

    const fruits = BRANCHES.map(b =>
      fruitMarkup(trunkX, groundY, height, b, pct)
    ).join('');

    const crown =
      pct >= 100
        ? `
          <g class="growth-crown">
            <path d="M84 ${topY - 10} l4 -10 4 10 -4 -3 z" />
            <path d="M112 ${topY - 6} l4 -11 4 11 -4 -4 z" />
            <path d="M98 ${topY - 16} l4 -12 4 12 -4 -4 z" />
          </g>
        `
        : '';

    container.innerHTML = `
      <svg
        class="growth-tree-svg"
        viewBox="0 0 200 200"
        role="img"
        aria-label="شجرة تخرّجك، بنسبة ${Math.round(pct)} بالمئة"
      >
        <ellipse
          cx="100" cy="${groundY + 8}"
          rx="46" ry="8"
          class="growth-ground"
        />

        <path
          d="M ${trunkX - baseWidth} ${groundY}
             Q ${trunkX - baseWidth * 0.6} ${topY + (height * 0.4)} ${trunkX} ${topY}
             Q ${trunkX + baseWidth * 0.6} ${topY + (height * 0.4)} ${trunkX + baseWidth} ${groundY}
             Z"
          class="growth-trunk"
        />

        ${branches}
        ${fruits}
        ${crown}
      </svg>

      <div class="growth-caption">
        <strong>${Math.round(pct)}٪</strong>
        <span>${stageLabel(pct)}</span>
      </div>
    `;
  }

  window.PTCGrowthTree = { render };
})();
