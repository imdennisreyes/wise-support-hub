(() => {
  'use strict';
  document.querySelectorAll('.needs-validation').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!form.checkValidity()) {
        event.preventDefault(); event.stopPropagation(); form.classList.add('was-validated');
      }
    });
  });
  document.querySelectorAll('[data-loading-form]').forEach((form) => {
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;
      const button = form.querySelector('[type="submit"]:focus') || form.querySelector('[type="submit"]');
      if (!button) return;
      const label = button.dataset.loadingText;
      if (label) button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${label}`;
      button.disabled = true;
    });
  });
  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = button.parentElement.querySelector('input');
      const showing = input.type === 'text'; input.type = showing ? 'password' : 'text';
      button.innerHTML = `<i class="bi bi-eye${showing ? '' : '-slash'}"></i>`;
      button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
  });
  const feedbackNo = document.querySelector('[data-feedback-no]');
  if (feedbackNo) feedbackNo.addEventListener('click', () => {
    const reason = document.querySelector('[data-feedback-reason]');
    reason.classList.remove('d-none'); reason.querySelector('textarea').required = true; reason.querySelector('textarea').focus();
  });
  document.querySelectorAll('[data-confirm]').forEach((button) => button.addEventListener('click', (event) => {
    if (!window.confirm(button.dataset.confirm)) event.preventDefault();
  }));
})();
