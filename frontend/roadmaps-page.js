/*
 * منطق صفحة خرائط الطريق — عرض بوابة المجالات، عرض خريطة مجال محدد
 * كمسار مراحل متصل، وتتبّع تقدّم شخصي محفوظ محليًا (لا حاجة لباك إند:
 * هذا محتوى إثرائي اختياري لا جزءًا من الخطة الأكاديمية الرسمية،
 * فذاكرة المتصفح تكفي تمامًا ولا تستحق جدولًا وAPI كاملًا).
 */
(function () {
  const fields = window.PTC_ROADMAPS || [];
  const PROGRESS_KEY = 'ptc_roadmap_progress';

  function getProgress() {
    try { return JSON.parse(localStorage.getItem(PROGRESS_KEY) || '{}'); }
    catch (_) { return {}; }
  }

  function setResourceDone(fieldId, resourceKey, done) {
    try {
      const all = getProgress();
      all[fieldId] = all[fieldId] || {};
      if (done) all[fieldId][resourceKey] = true;
      else delete all[fieldId][resourceKey];
      localStorage.setItem(PROGRESS_KEY, JSON.stringify(all));
    } catch (_) {}
  }

  function isResourceDone(fieldId, resourceKey) {
    const all = getProgress();
    return !!(all[fieldId] && all[fieldId][resourceKey]);
  }

  function resourceKey(stageIndex, resourceIndex) {
    return `${stageIndex}:${resourceIndex}`;
  }

  /* عدد المصادر المنجزة مقابل الكل — لكل مرحلة، ولكامل المجال. */
  function fieldStats(field) {
    let total = 0, done = 0;
    field.stages.forEach((stage, si) => {
      stage.resources.forEach((_, ri) => {
        total++;
        if (isResourceDone(field.id, resourceKey(si, ri))) done++;
      });
    });
    return { total, done, pct: total ? Math.round((done / total) * 100) : 0 };
  }

  function stageStats(field, stage, stageIndex) {
    let done = 0;
    stage.resources.forEach((_, ri) => {
      if (isResourceDone(field.id, resourceKey(stageIndex, ri))) done++;
    });
    return { total: stage.resources.length, done };
  }

  function escapeHTML(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  /* ── بوابة اختيار المجال ── */
  function renderGate() {
    const grid = document.getElementById('rmFieldsGrid');
    if (!grid) return;

    grid.innerHTML = fields.map(field => {
      const stats = fieldStats(field);
      return `
        <button type="button" class="rm-field-card" data-field="${field.id}">
          <span class="rm-field-icon">${field.icon}</span>
          <h3>${escapeHTML(field.title)}</h3>
          <p>${escapeHTML(field.description)}</p>
          <div class="rm-field-meta">
            <span>${field.stages.length} مراحل</span>
            <span>${stats.total} مصدر</span>
            ${stats.done ? `<span style="color:var(--olive);font-weight:700">${stats.pct}% مكتمل</span>` : ''}
          </div>
        </button>
      `;
    }).join('');

    grid.querySelectorAll('[data-field]').forEach(card => {
      card.addEventListener('click', () => openField(card.dataset.field));
    });
  }

  /* ── عرض خريطة مجال واحد ── */
  let expandedStage = null;

  function openField(fieldId) {
    const field = fields.find(f => f.id === fieldId);
    if (!field) return;

    location.hash = fieldId;
    expandedStage = 0;

    document.getElementById('rmGate').style.display = 'none';
    document.getElementById('rmView').classList.add('open');

    /* field.icon أصبح وسم <i data-icon> (icons.js) لا نصًّا خامًا — لازم
       innerHTML لا textContent عشان يتصيّر أيقونة فعلية لا نصًّا حرفيًا. */
    document.getElementById('rmViewIcon').innerHTML = field.icon;
    document.getElementById('rmViewTitle').textContent = field.title;
    document.getElementById('rmViewSub').textContent = field.fullDescription;

    renderStages(field);
  }

  window.rmShowGate = function () {
    location.hash = '';
    document.getElementById('rmView').classList.remove('open');
    document.getElementById('rmGate').style.display = '';
    renderGate();
  };

  function renderStages(field) {
    const stats = fieldStats(field);
    document.getElementById('rmOverallPct').textContent = stats.pct + '%';
    document.getElementById('rmOverallBar').style.width = stats.pct + '%';

    const path = document.getElementById('rmPath');

    path.innerHTML = field.stages.map((stage, si) => {
      const sStats = stageStats(field, stage, si);
      const isDone = sStats.total > 0 && sStats.done === sStats.total;
      const isExpanded = expandedStage === si;

      return `
        <div class="rm-stage${isDone ? ' is-done' : ''}${isExpanded ? ' expanded' : ''}" data-stage="${si}">
          <div class="rm-stage-node">${isDone ? '✓' : si + 1}</div>
          <div class="rm-stage-card">
            <div class="rm-stage-head" data-toggle-stage="${si}">
              <div class="rm-stage-title">
                <b>${escapeHTML(stage.title)}</b>
                <span>${escapeHTML(stage.subtitle)}</span>
              </div>
              <span class="rm-stage-count">${sStats.done}/${sStats.total}</span>
              <svg class="rm-stage-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="rm-stage-body">
              ${stage.resources.map((resource, ri) => {
                const key = resourceKey(si, ri);
                const done = isResourceDone(field.id, key);
                const learnList = (resource.learn || []).map(
                  item => `<li>${escapeHTML(item)}</li>`
                ).join('');
                return `
                  <div class="rm-resource${done ? ' is-checked' : ''}">
                    <button
                      type="button"
                      class="rm-resource-check"
                      data-check="${field.id}|${key}"
                      aria-label="تمّ الإنجاز"
                    >${done ? '✓' : ''}</button>
                    <div class="rm-resource-body">
                      <a href="${escapeHTML(resource.url)}" target="_blank" rel="noopener noreferrer">${escapeHTML(resource.title)}</a>
                      <div class="rm-resource-tags">
                        <span class="rm-tag lang-${resource.lang}">${resource.lang === 'ar' ? 'عربي' : 'إنجليزي'}</span>
                        <span class="rm-tag">${escapeHTML(resource.type)}</span>
                        ${resource.duration ? `<span class="rm-tag">⏱ ${escapeHTML(resource.duration)}</span>` : ''}
                      </div>
                      ${learnList ? `<ul class="rm-resource-learn">${learnList}</ul>` : ''}
                      ${resource.note ? `<div class="rm-resource-note"><i data-icon="bulb"></i> ${escapeHTML(resource.note)}</div>` : ''}
                    </div>
                  </div>
                `;
              }).join('')}
            </div>
          </div>
        </div>
      `;
    }).join('');

    path.querySelectorAll('[data-toggle-stage]').forEach(head => {
      head.addEventListener('click', () => {
        const si = Number(head.dataset.toggleStage);
        expandedStage = expandedStage === si ? null : si;
        renderStages(field);
      });
    });

    path.querySelectorAll('[data-check]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const [fieldId, key] = btn.dataset.check.split('|');
        const currentlyDone = isResourceDone(fieldId, key);
        setResourceDone(fieldId, key, !currentlyDone);
        renderStages(field);
      });
    });
  }

  function boot() {
    renderGate();

    const hashField = (location.hash || '').replace('#', '').trim();
    if (hashField && fields.some(f => f.id === hashField)) {
      openField(hashField);
    }
  }

  window.addEventListener('DOMContentLoaded', boot);
})();
