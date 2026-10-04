/*
 * Progressive enhancement only: every form and link works without JavaScript.
 * No inline scripts are allowed (Content-Security-Policy: script-src 'self').
 */
(function () {
  'use strict';

  const i18n = (() => {
    const el = document.getElementById('i18n-js');
    try { return el ? JSON.parse(el.textContent) : {}; } catch (e) { return {}; }
  })();

  // Show / hide password --------------------------------------------------
  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    const input = document.getElementById(btn.getAttribute('data-toggle-password'));
    if (!input) return;
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? i18n.hidePassword : i18n.showPassword);
      const icon = btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('bi-eye', !show);
        icon.classList.toggle('bi-eye-slash', show);
      }
    });
  });

  // Confirmation dialog for destructive actions -------------------------------
  const modalEl = document.getElementById('confirmModal');
  let pendingForm = null;
  if (modalEl && window.bootstrap) {
    const modal = new bootstrap.Modal(modalEl);
    const body = modalEl.querySelector('[data-confirm-body]');
    const okBtn = modalEl.querySelector('[data-confirm-ok]');
    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
      if (form.dataset.confirmed === '1') return;
      event.preventDefault();
      pendingForm = form;
      body.textContent = form.getAttribute('data-confirm') || i18n.confirmDefault;
      okBtn.textContent = form.getAttribute('data-confirm-ok') || i18n.confirm;
      okBtn.className = 'btn ' + (form.getAttribute('data-confirm-tone') === 'danger' ? 'btn-danger' : 'btn-primary');
      modal.show();
    }, true);
    okBtn.addEventListener('click', () => {
      if (!pendingForm) return;
      pendingForm.dataset.confirmed = '1';
      modal.hide();
      if (typeof pendingForm.requestSubmit === 'function') pendingForm.requestSubmit(); else pendingForm.submit();
    });
  }

  // Loading / disabled state on submit (prevents double submission) --------
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    if (form.hasAttribute('data-confirm') && form.dataset.confirmed !== '1') return;
    const btn = event.submitter || form.querySelector('[type="submit"]');
    if (!btn || btn.hasAttribute('data-no-loading') || form.method.toLowerCase() === 'get') return;
    btn.setAttribute('aria-busy', 'true');
    btn.setAttribute('disabled', 'disabled');
    if (btn.name) { // keep the clicked button's value in the submission
      const hidden = document.createElement('input');
      hidden.type = 'hidden'; hidden.name = btn.name; hidden.value = btn.value;
      form.appendChild(hidden);
    }
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border me-2';
    spinner.setAttribute('role', 'status');
    spinner.setAttribute('aria-label', i18n.loading || 'Loading');
    btn.prepend(spinner);
  });

  // Filter forms: apply immediately when a select/date changes ---------------
  const autosubmit = (form) => {
    if (form && form.hasAttribute('data-autosubmit')) {
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    }
  };
  document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
    form.querySelectorAll('select, input[type="date"]:not([data-datepicker])').forEach((el) => {
      el.addEventListener('change', () => autosubmit(form));
    });
  });

  // Date filters: flatpickr in the page language ------------------------------
  // The native control renders the Arabic placeholder with broken letters in some browsers,
  // so date filters use flatpickr: it still submits Y-m-d (unchanged for the server)
  // and shows d/m/Y. If the library fails to load, the native input keeps working.
  const dateInputs = Array.from(document.querySelectorAll('input[data-datepicker]'));
  const dp = i18n.datepicker;
  const nativeDates = () => dateInputs.forEach((input) => {
    input.addEventListener('change', () => autosubmit(input.form));
  });
  const load = (tag, attrs) => new Promise((resolve, reject) => {
    const el = document.createElement(tag);
    Object.assign(el, attrs);
    el.addEventListener('load', resolve);
    el.addEventListener('error', reject);
    document.head.appendChild(el);
  });
  if (dateInputs.length && dp) {
    Promise.all([load('link', { rel: 'stylesheet', href: dp.css }), load('script', { src: dp.js })])
      .then(() => (dp.l10n ? load('script', { src: dp.l10n }) : null))
      .then(() => {
        const rtl = document.documentElement.dir === 'rtl';
        dateInputs.forEach((input) => {
          const id = input.id;
          window.flatpickr(input, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd/m/Y',
            allowInput: true,
            disableMobile: true,
            locale: dp.locale === 'ar' && window.flatpickr.l10ns.ar ? 'ar' : 'default',
            position: rtl ? 'auto right' : 'auto left',
            onReady: (dates, str, fp) => {
              // The visible field takes over the label and the placeholder.
              input.removeAttribute('id');
              fp.altInput.id = id;
              fp.altInput.placeholder = dp.placeholder;
              fp.altInput.setAttribute('lang', dp.locale);
              fp.altInput.setAttribute('autocomplete', 'off');
              // Western digits in the year box, like every other number on the site
              // (a number input would localise them to Arabic-Indic digits).
              if (fp.currentYearElement) {
                fp.currentYearElement.type = 'text';
                fp.currentYearElement.inputMode = 'numeric';
                fp.currentYearElement.value = String(fp.currentYear);
              }
            },
            onChange: () => autosubmit(input.form),
          });
        });
      })
      .catch(nativeDates);
  }

  // Auto-dismiss success flashes -------------------------------------------
  document.querySelectorAll('.flash-stack .alert-success').forEach((el) => {
    setTimeout(() => {
      if (window.bootstrap) bootstrap.Alert.getOrCreateInstance(el).close();
    }, 6000);
  });

  // Keep end > start on paired datetime inputs --------------------------------
  document.querySelectorAll('input[data-starts]').forEach((start) => {
    const end = document.getElementById(start.getAttribute('data-starts'));
    if (!end) return;
    start.addEventListener('change', () => {
      end.min = start.value;
      if (end.value && end.value <= start.value) end.value = '';
    });
  });
})();
