/* Optional conveniences; all management forms also work without JavaScript. */
'use strict';
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
document.querySelectorAll('button[data-copy]').forEach(button => {
  button.hidden = false;
  button.addEventListener('click', async () => {
    const source = document.getElementById(button.dataset.copy);
    const status = button.parentElement.querySelector('.copy-status');
    try {
      await navigator.clipboard.writeText(source.textContent);
      status.textContent = 'Invitation copied. Paste it into your agent’s chat.';
    } catch {
      const range = document.createRange();
      range.selectNodeContents(source);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      status.textContent = 'Select and copy the invitation above. Automatic copying is unavailable in this browser.';
    }
  });
});
