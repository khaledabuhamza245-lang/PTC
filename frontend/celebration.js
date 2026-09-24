/*
 * احتفال بصري لحظة إنجاز مساق كامل — كونفيتي حقيقي (لا مكتبة خارجية)
 * + رسالة تهنئة قصيرة. ملف مستقل عمدًا: يُستدعى سطر واحد من
 * course-page.js (window.PTCCelebration?.burst(...))، فحتى لو فشل
 * هذا الملف بالتحميل لأي سبب، لا ينهار شيء آخر بالصفحة — العلامة
 * الاختيارية (?.) تتجاهله بصمت.
 */
(function () {
  const COLORS = ['#EBAA4E', '#3c4a2f', '#8FCB3B', '#c0392b', '#F2C583'];

  function shape(color, x) {
    const el = document.createElement('div');
    el.className = 'ptc-confetti-piece';
    el.style.setProperty('--x', x + 'px');
    el.style.setProperty('--rot', (Math.random() * 360) + 'deg');
    el.style.setProperty('--delay', (Math.random() * 0.25) + 's');
    el.style.setProperty('--drift', (Math.random() * 140 - 70) + 'px');
    el.style.setProperty('--fall', (520 + Math.random() * 260) + 'px');
    el.style.background = color;
    if (Math.random() > 0.5) el.style.borderRadius = '50%';
    return el;
  }

  function confettiBurst() {
    const layer = document.createElement('div');
    layer.className = 'ptc-confetti-layer';
    document.body.appendChild(layer);

    const centerX = window.innerWidth / 2;

    for (let i = 0; i < 70; i++) {
      const spread = (Math.random() - 0.5) * Math.min(600, window.innerWidth * 0.8);
      const piece = shape(
        COLORS[Math.floor(Math.random() * COLORS.length)],
        centerX + spread
      );
      layer.appendChild(piece);
    }

    setTimeout(() => layer.remove(), 1900);
  }

  function toast(courseName) {
    const box = document.createElement('div');
    box.className = 'ptc-celebrate-toast';
    box.innerHTML = `
      <div class="ptc-celebrate-icon"><i data-icon="trophy" data-size="28"></i></div>
      <div>
        <div class="ptc-celebrate-title">أنجزتَ المادة بالكامل!</div>
        <div class="ptc-celebrate-sub">${courseName ? escapeHTML(courseName) : 'مبروك التقدّم'} — خطوة أقرب للتخرّج <i data-icon="graduation"></i></div>
      </div>
    `;
    document.body.appendChild(box);

    requestAnimationFrame(() => box.classList.add('show'));

    setTimeout(() => {
      box.classList.remove('show');
      setTimeout(() => box.remove(), 400);
    }, 4200);
  }

  function escapeHTML(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function burst(courseName) {
    confettiBurst();
    toast(courseName);

    /* اهتزاز خفيف على الأجهزة التي تدعمه — إحساس إضافي بلا أي كلفة. */
    if (navigator.vibrate) {
      try { navigator.vibrate([15, 40, 15]); } catch (_) {}
    }
  }

  window.PTCCelebration = { burst };
})();
