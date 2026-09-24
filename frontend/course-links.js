(function () {
  function normalize(value) {
    return String(value || '')
      .replace(/[^A-Za-z0-9]/g, '')
      .toUpperCase();
  }

  function getCourseKey(card) {
    if (!card) return '';

    const existingKey =
      card.dataset.courseKey ||
      card.id;

    if (existingKey) {
      return existingKey;
    }

    const codeElement =
      card.querySelector('.badge, .code2');

    const code = normalize(codeElement?.textContent);

    return code ? `c_${code}` : '';
  }

  function openCourse(card) {
    const key = getCourseKey(card);

    if (!key) return;

    window.location.assign(
      `course.html?course=${encodeURIComponent(key)}`
    );
  }

  document.addEventListener(
    'click',
    event => {
      const card = event.target.closest('.course, .elec');

      if (!card) return;

      if (
        event.target.closest(
          'a, input, select, textarea'
        )
      ) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();

      openCourse(card);
    },
    true
  );

  document.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') {
      return;
    }

    const card = event.target.closest('.course, .elec');

    if (!card) return;

    event.preventDefault();
    openCourse(card);
  });

  function prepareCards() {
    document
      .querySelectorAll('.course, .elec')
      .forEach(card => {
        card.classList.add('course-clickable');
        card.setAttribute('role', 'link');
        card.setAttribute('tabindex', '0');

        const arrow = card.querySelector('.chev');

        if (arrow) {
          arrow.textContent = '↗';
        }
      });
  }

  window.addEventListener(
    'DOMContentLoaded',
    prepareCards
  );
})();