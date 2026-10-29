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

  // Rich editor — paste screenshot support
  document.querySelectorAll('.rich-editor').forEach((editor) => {
    const hidden = editor.nextElementSibling;

    // Show placeholder
    function updatePlaceholder() {
      if (editor.innerHTML.trim() === '' || editor.innerHTML === '<br>') {
        editor.classList.add('is-empty');
      } else {
        editor.classList.remove('is-empty');
      }
    }
    updatePlaceholder();

    // Sync to hidden textarea on every input
    editor.addEventListener('input', () => {
      hidden.value = editor.innerHTML;
      updatePlaceholder();
    });

    // Handle paste
    editor.addEventListener('paste', (e) => {
      const items = e.clipboardData?.items;
      if (!items) return;

      // Check for image in clipboard
      for (const item of items) {
        if (item.type.startsWith('image/')) {
          e.preventDefault();
          const file = item.getAsFile();
          if (!file) return;
          const reader = new FileReader();
          reader.onload = (ev) => {
            const dataUrl = ev.target.result;
            // Insert image at cursor position
            const img = document.createElement('img');
            img.src = dataUrl;
            img.style.maxWidth = '100%';
            img.style.borderRadius = '4px';
            img.style.margin = '8px 0';
            img.style.display = 'block';
            const sel = window.getSelection();
            if (sel && sel.rangeCount) {
              const range = sel.getRangeAt(0);
              range.deleteContents();
              range.insertNode(img);
              // Move cursor after image
              range.setStartAfter(img);
              range.setEndAfter(img);
              sel.removeAllRanges();
              sel.addRange(range);
              // Add a line break after image for continued typing
              const br = document.createElement('br');
              range.insertNode(br);
              range.setStartAfter(br);
              range.setEndAfter(br);
              sel.removeAllRanges();
              sel.addRange(range);
            } else {
              editor.appendChild(img);
            }
            hidden.value = editor.innerHTML;
            editor.classList.remove('is-empty');
          };
          reader.readAsDataURL(file);
          return;
        }
      }

      // For plain text paste — strip HTML formatting but keep line breaks
      e.preventDefault();
      const text = e.clipboardData.getData('text/plain');
      document.execCommand('insertText', false, text);
    });
  });

  // Sync rich editors before form submit
  document.querySelectorAll('.article-form').forEach((form) => {
    form.addEventListener('submit', () => {
      form.querySelectorAll('.rich-editor').forEach((editor) => {
        editor.nextElementSibling.value = editor.innerHTML;
      });
    });
  });

  // Fix sidebar navigation on mobile — close offcanvas then navigate
  const sidebarEl = document.getElementById('sidebar');
  if (sidebarEl) {
    sidebarEl.querySelectorAll('.nav-link[href]').forEach((link) => {
      link.addEventListener('click', (event) => {
        const offcanvas = bootstrap.Offcanvas.getInstance(sidebarEl);
        if (offcanvas && window.getComputedStyle(sidebarEl).position === 'fixed' && sidebarEl.classList.contains('show')) {
          event.preventDefault();
          const href = link.getAttribute('href');
          offcanvas.hide();
          sidebarEl.addEventListener('hidden.bs.offcanvas', () => {
            window.location.href = href;
          }, { once: true });
        }
      });
    });
  }
})();
