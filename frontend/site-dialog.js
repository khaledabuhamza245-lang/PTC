const SiteDialog = (() => {
  let overlay = null;
  let resolver = null;
  let previousFocus = null;

  function ensure() {
    if (overlay) return;

    const style = document.createElement('style');

    style.textContent = `
      .ptc-dialog-overlay {
        position: fixed;
        inset: 0;
        z-index: 999999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(25, 29, 22, .58);
        backdrop-filter: blur(4px);
      }

      .ptc-dialog-overlay.is-open {
        display: flex;
      }

      .ptc-dialog {
        width: min(520px, 100%);
        max-height: min(86vh, 760px);
        overflow: auto;
        direction: rtl;
        background: var(--card, #fffaf1);
        color: var(--ink, #302d27);
        border: 1px solid var(--line-2, #d8c7a5);
        border-radius: 22px;
        padding: 24px;
        box-shadow: 0 28px 80px rgba(30, 27, 20, .34);
        animation: ptcDialogIn .18s ease-out;
      }

      @keyframes ptcDialogIn {
        from {
          opacity: 0;
          transform: translateY(12px) scale(.975);
        }

        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }

      .ptc-dialog-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 16px;
      }

      .ptc-dialog-title {
        margin: 0;
        font-family: var(--font-display, "Tajawal", sans-serif);
        color: var(--olive, #3c4a2f);
        font-size: 22px;
        line-height: 1.5;
      }

      .ptc-dialog-close {
        width: 38px;
        height: 38px;
        border: 1px solid var(--line-2, #d8c7a5);
        border-radius: 50%;
        background: var(--paper-2, #f2eadb);
        color: var(--muted, #746d60);
        font-size: 23px;
        line-height: 1;
        cursor: pointer;
      }

      .ptc-dialog-message {
        margin: 0 0 16px;
        color: var(--muted, #746d60);
        font-family: var(--font-ar, "Tajawal", sans-serif);
        font-size: 14px;
        line-height: 1.9;
        white-space: pre-wrap;
      }

      .ptc-dialog-fields {
        display: grid;
        gap: 13px;
      }

      .ptc-dialog-field label {
        display: block;
        margin-bottom: 6px;
        color: var(--muted, #746d60);
        font-family: var(--font-ar, "Tajawal", sans-serif);
        font-size: 13px;
        font-weight: 700;
      }

      .ptc-dialog-input,
      .ptc-dialog-textarea,
      .ptc-dialog-select {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid var(--line-2, #d8c7a5);
        border-radius: 11px;
        padding: 12px 14px;
        background: var(--paper, #fffdf8);
        color: var(--ink, #302d27);
        font: 14px var(--font-ar, "Tajawal", sans-serif);
        outline: none;
        transition: .18s;
      }

      .ptc-dialog-textarea {
        min-height: 92px;
        resize: vertical;
      }

      .ptc-dialog-input:focus,
      .ptc-dialog-textarea:focus,
      .ptc-dialog-select:focus {
        border-color: var(--copper, #b8793e);
        box-shadow: 0 0 0 4px rgba(184, 121, 62, .14);
      }

      .ptc-dialog-input.is-invalid,
      .ptc-dialog-textarea.is-invalid,
      .ptc-dialog-select.is-invalid {
        border-color: #c0392b;
        box-shadow: 0 0 0 4px rgba(192, 57, 43, .10);
      }

      .ptc-dialog-field-error {
        min-height: 18px;
        margin-top: 4px;
        color: #c0392b;
        font-family: var(--font-ar, "Tajawal", sans-serif);
        font-size: 12px;
      }

      .ptc-dialog-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 22px;
      }

      .ptc-dialog-btn {
        min-width: 112px;
        padding: 11px 20px;
        border-radius: 11px;
        border: 1px solid transparent;
        font: 700 14px var(--font-ar, "Tajawal", sans-serif);
        cursor: pointer;
      }

      .ptc-dialog-btn.primary {
        background: var(--olive, #3c4a2f);
        color: var(--paper, #fffaf1);
      }

      .ptc-dialog-btn.secondary {
        background: var(--paper-2, #f2eadb);
        border-color: var(--line-2, #d8c7a5);
        color: var(--olive, #3c4a2f);
      }

      .ptc-dialog-btn.danger {
        background: #b83b2f;
        color: #fff;
      }

      @media (max-width: 560px) {
        .ptc-dialog {
          padding: 20px;
          border-radius: 18px;
        }

        .ptc-dialog-actions {
          flex-direction: column;
        }

        .ptc-dialog-btn {
          width: 100%;
        }
      }
    `;

    document.head.appendChild(style);

    overlay = document.createElement('div');
    overlay.className = 'ptc-dialog-overlay';
    overlay.id = 'ptcSiteDialog';

    overlay.innerHTML = `
      <section
        class="ptc-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ptcDialogTitle"
      >
        <div class="ptc-dialog-head">
          <h2
            class="ptc-dialog-title"
            id="ptcDialogTitle"
          ></h2>

          <button
            class="ptc-dialog-close"
            type="button"
            aria-label="إغلاق"
          >
            ×
          </button>
        </div>

        <p
          class="ptc-dialog-message"
          id="ptcDialogMessage"
        ></p>

        <div
          class="ptc-dialog-fields"
          id="ptcDialogFields"
        ></div>

        <div class="ptc-dialog-actions">
          <button
            class="ptc-dialog-btn primary"
            id="ptcDialogConfirm"
            type="button"
          >
            حفظ
          </button>

          <button
            class="ptc-dialog-btn secondary"
            id="ptcDialogCancel"
            type="button"
          >
            إلغاء
          </button>
        </div>
      </section>
    `;

    document.body.appendChild(overlay);

    overlay
      .querySelector('.ptc-dialog-close')
      .addEventListener('click', () => close(null));

    document
      .getElementById('ptcDialogCancel')
      .addEventListener('click', () => close(null));

    document.addEventListener('keydown', event => {
      if (!overlay.classList.contains('is-open')) return;

      if (event.key === 'Escape') {
        event.preventDefault();
        close(null);
        return;
      }

      if (event.key === 'Enter' && !event.shiftKey) {
        const active = document.activeElement;

        if (active?.tagName === 'TEXTAREA') return;

        event.preventDefault();

        document
          .getElementById('ptcDialogConfirm')
          .click();
      }
    });
  }

  function close(value) {
    if (!overlay?.classList.contains('is-open')) return;

    overlay.classList.remove('is-open');
    document.body.style.overflow = '';

    const done = resolver;
    resolver = null;

    if (
      previousFocus &&
      typeof previousFocus.focus === 'function'
    ) {
      previousFocus.focus();
    }

    previousFocus = null;

    if (done) done(value);
  }

  function buildField(field, index) {
    const id = `ptcDialogField_${index}`;

    const wrap = document.createElement('div');
    wrap.className = 'ptc-dialog-field';

    const label = document.createElement('label');
    label.htmlFor = id;
    label.textContent = field.label || 'القيمة';

    wrap.appendChild(label);

    let control;

    if (field.type === 'textarea') {
      control = document.createElement('textarea');
      control.className = 'ptc-dialog-textarea';
      control.rows = field.rows || 3;
    } else if (field.type === 'select') {
      control = document.createElement('select');
      control.className = 'ptc-dialog-select';

      (field.options || []).forEach(option => {
        control.appendChild(
          new Option(option.label, option.value)
        );
      });
    } else {
      control = document.createElement('input');
      control.className = 'ptc-dialog-input';
      control.type = field.type || 'text';
    }

    control.id = id;

    control.dataset.fieldName =
      field.name || `field_${index}`;

    control.value = field.value ?? '';
    control.placeholder = field.placeholder || '';
    control.autocomplete = field.autocomplete || 'off';

    if (field.dir) {
      control.dir = field.dir;
    }

    if (field.maxLength) {
      control.maxLength = field.maxLength;
    }

    const error = document.createElement('div');
    error.className = 'ptc-dialog-field-error';

    control.addEventListener('input', () => {
      control.classList.remove('is-invalid');
      error.textContent = '';
    });

    wrap.append(control, error);

    return wrap;
  }

  function open(options = {}) {
    ensure();

    if (resolver) {
      close(null);
    }

    previousFocus = document.activeElement;

    const title =
      document.getElementById('ptcDialogTitle');

    const message =
      document.getElementById('ptcDialogMessage');

    const fieldsBox =
      document.getElementById('ptcDialogFields');

    const confirmButton =
      document.getElementById('ptcDialogConfirm');

    const cancelButton =
      document.getElementById('ptcDialogCancel');

    const closeButton =
      overlay.querySelector('.ptc-dialog-close');

    title.textContent =
      options.title || 'تنبيه';

    message.textContent =
      options.message || '';

    message.style.display =
      options.message ? '' : 'none';

    fieldsBox.replaceChildren();

    (options.fields || []).forEach(
      (field, index) => {
        fieldsBox.appendChild(
          buildField(field, index)
        );
      }
    );

    confirmButton.textContent =
      options.confirmText || 'حفظ';

    cancelButton.textContent =
      options.cancelText || 'إلغاء';

    cancelButton.style.display =
      options.hideCancel ? 'none' : '';

    closeButton.style.display =
      options.hideClose ? 'none' : '';

    confirmButton.className =
      `ptc-dialog-btn ${
        options.danger
          ? 'danger'
          : 'primary'
      }`;

    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    return new Promise(resolve => {
      resolver = resolve;

      confirmButton.onclick = () => {
        const result = {};
        let firstInvalid = null;

        (options.fields || []).forEach(
          (field, index) => {
            const control =
              document.getElementById(
                `ptcDialogField_${index}`
              );

            const error =
              control.parentElement.querySelector(
                '.ptc-dialog-field-error'
              );

            const rawValue = control.value;

            const value =
              field.trim === false
                ? rawValue
                : rawValue.trim();

            if (field.required && !value) {
              control.classList.add('is-invalid');

              error.textContent =
                field.requiredMessage ||
                'هذا الحقل مطلوب.';

              if (!firstInvalid) {
                firstInvalid = control;
              }

              return;
            }

            if (
              typeof field.validate === 'function'
            ) {
              const validationMessage =
                field.validate(value);

              if (validationMessage) {
                control.classList.add('is-invalid');

                error.textContent =
                  validationMessage;

                if (!firstInvalid) {
                  firstInvalid = control;
                }

                return;
              }
            }

            result[
              field.name || `field_${index}`
            ] = value;
          }
        );

        if (firstInvalid) {
          firstInvalid.focus();
          return;
        }

        close(
          options.fields?.length
            ? result
            : true
        );
      };

      requestAnimationFrame(() => {
        const firstField =
          fieldsBox.querySelector(
            'input, textarea, select'
          );

        (firstField || confirmButton).focus();

        if (firstField?.select) {
          firstField.select();
        }
      });
    });
  }

  return {
    form(options) {
      return open(options);
    },

    async input(options = {}) {
      const result = await open({
        title: options.title,
        message: options.message,
        confirmText:
          options.confirmText || 'حفظ',
        cancelText:
          options.cancelText || 'إلغاء',

        fields: [
          {
            name: 'value',
            label:
              options.label || 'القيمة',

            value:
              options.value || '',

            placeholder:
              options.placeholder || '',

            required:
              options.required !== false,

            requiredMessage:
              options.requiredMessage ||
              'هذا الحقل مطلوب.',

            type:
              options.type || 'text',

            dir:
              options.dir
          }
        ]
      });

      return result
        ? result.value
        : null;
    },

    confirm(options = {}) {
      return open({
        title:
          options.title ||
          'تأكيد العملية',

        message:
          options.message ||
          'هل أنت متأكد؟',

        confirmText:
          options.confirmText ||
          'تأكيد',

        cancelText:
          options.cancelText ||
          'إلغاء',

        danger:
          Boolean(options.danger),

        fields: []
      });
    },

    message(options = {}) {
      return open({
        title:
          options.title ||
          'تنبيه',

        message:
          options.message || '',

        confirmText:
          options.confirmText ||
          'حسنًا',

        hideCancel: true,
        fields: []
      });
    }
  };
})();