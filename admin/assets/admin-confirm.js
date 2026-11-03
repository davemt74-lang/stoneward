/* Shared Stonefellow Admin lifecycle confirmation: a branded modal, never a browser alert. */
(() => {
  'use strict';
  let dialog;
  let resolvePending;
  let lastFocus;

  function ensure() {
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.className = 'sf-admin-confirm';
    dialog.setAttribute('aria-labelledby', 'sfAdminConfirmTitle');
    dialog.setAttribute('aria-describedby', 'sfAdminConfirmDescription');
    dialog.innerHTML = `
      <div class="sf-admin-confirm-inner">
        <div class="sf-admin-confirm-head">
          <span>STONEFELLOW / ADMIN ACTION</span>
          <button type="button" class="sf-admin-confirm-x" data-cancel aria-label="Close confirmation">×</button>
        </div>
        <div class="sf-admin-confirm-icon" aria-hidden="true">!</div>
        <h2 id="sfAdminConfirmTitle"></h2>
        <p id="sfAdminConfirmDescription"></p>
        <div class="sf-admin-confirm-actions">
          <button type="button" class="secondary" data-cancel>Cancel</button>
          <button type="button" class="sf-admin-confirm-accept" data-accept></button>
        </div>
      </div>`;
    document.body.append(dialog);
    dialog.querySelectorAll('[data-cancel]').forEach(button => {
      button.addEventListener('click', () => dialog.close('cancel'));
    });
    dialog.querySelector('[data-accept]').addEventListener('click', () => dialog.close('confirm'));
    dialog.addEventListener('cancel', event => { event.preventDefault(); dialog.close('cancel'); });
    dialog.addEventListener('click', event => {
      if (event.target === dialog) dialog.close('cancel');
    });
    dialog.addEventListener('close', () => {
      const finish = resolvePending;
      resolvePending = null;
      finish?.(dialog.returnValue === 'confirm');
      if (lastFocus?.isConnected) lastFocus.focus({ preventScroll: true });
      lastFocus = null;
    });
    return dialog;
  }

  function ask({ title, description, actionLabel = 'Confirm', danger = false }) {
    const modal = ensure();
    if (modal.open || resolvePending) return Promise.resolve(false);
    modal.querySelector('#sfAdminConfirmTitle').textContent = String(title || 'Confirm action');
    modal.querySelector('#sfAdminConfirmDescription').textContent = String(description || 'Do you want to continue?');
    const accept = modal.querySelector('[data-accept]');
    accept.textContent = String(actionLabel);
    modal.classList.toggle('is-danger', Boolean(danger));
    lastFocus = document.activeElement;
    return new Promise(resolve => {
      resolvePending = resolve;
      modal.showModal();
      modal.querySelector('[data-cancel]:not(.sf-admin-confirm-x)').focus();
    });
  }

  window.SFAdminConfirm = Object.freeze({ ask });
})();
