'use strict';

(() => {
  const validId = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  const element = (tag, value = '') => {
    const node = document.createElement(tag);
    node.textContent = value;
    return node;
  };
  const button = value => {
    const node = element('button', value);
    node.type = 'button';
    return node;
  };
  let sequence = 0;

  function create(options = {}) {
    const api = options.api || {};
    const owner = () => String(options.getOwner?.() || '');
    let current = null;

    function close(restoreFocus = true) {
      if (!current) return;
      const view = current;
      current = null;
      view.request.abort();
      view.dialog.close();
      view.dialog.remove();
      if (restoreFocus && view.opener?.isConnected) view.opener.focus({preventScroll: true});
    }

    function active(view) {
      if (current !== view) return false;
      if (view.owner === owner() && view.isCurrent() && view.dialog.open) return true;
      close(false);
      return false;
    }

    function confirmedApp(result, expectedId) {
      const app = result?.app;
      if (result?.ok === false || !validId(app?.id) || (expectedId && app.id !== expectedId) ||
          (app.gallery_id !== null && !validId(app.gallery_id)) || typeof app.title !== 'string' ||
          (app.supports_alerts === true && !validId(app.gallery_id)) ||
          ['installed', 'enabled', 'supports_alerts'].some(key => typeof app[key] !== 'boolean')) {
        throw new Error('The app notification status could not be verified.');
      }
      return app;
    }

    function updateApp(view, result, expectedId, notify = false) {
      if (!active(view)) return;
      view.app = confirmedApp(result, expectedId);
      if (notify) {
        try { view.onChange?.(view.app); } catch (_) {}
      }
    }

    function render(view, notice = '') {
      const {app, action, heading, description, phone, status} = view;
      heading.textContent = app ? 'Notifications for ' + (app.title.trim() || 'this canvas app') : 'App notifications';
      action.hidden = true;
      phone.hidden = !(app?.enabled && app.supports_alerts && typeof options.onPhoneAlerts === 'function');
      action.disabled = phone.disabled = view.busy;
      if (!view.owner) {
        description.textContent = 'Sign in to manage notifications for this app.';
        action.textContent = 'Sign in';
        action.hidden = typeof options.onSignIn !== 'function';
      } else if (!app) {
        description.textContent = view.busy ? 'Checking your notification preference…' : 'App notification settings are unavailable.';
      } else if (app.enabled) {
        description.textContent = app.supports_alerts
          ? 'You’ll get an inbox alert when a new version of this app is published. Phone delivery is optional.'
          : 'This app can’t send alerts right now. Its notifications are paused, and you can still turn them off.';
        action.textContent = 'Turn off app notifications';
        action.hidden = false;
      } else if (!app.supports_alerts) {
        description.textContent = 'This app has no notifications available.';
      } else if (!app.installed) {
        description.textContent = 'Install this app to manage its notifications. Installing leaves app notifications off.';
        action.textContent = 'Install app';
        action.hidden = false;
      } else {
        description.textContent = 'Get an inbox alert when a new version of this app is published. Phone delivery is optional.';
        action.textContent = 'Enable app notifications';
        action.hidden = false;
      }
      status.textContent = notice;
    }

    async function change(view) {
      if (!active(view) || view.busy || view.action.hidden) return;
      if (!view.owner) {
        close(false);
        options.onSignIn?.();
        return;
      }
      const app = view.app;
      if (!app || (!app.supports_alerts && !app.enabled)) return;
      const installing = !app.installed;
      const enabled = !app.enabled;
      const ref = {app_id: app.id};
      view.busy = true;
      render(view, installing ? 'Installing app…' : 'Saving your preference…');
      let notice = '';
      let appChanged = false;
      try {
        const result = installing
          ? await api.install(ref, view.request.signal)
          : await api.setNotifications(enabled ? {...ref, expected_gallery_id: app.gallery_id} : ref, enabled, view.request.signal);
        if (!active(view)) return;
        updateApp(view, result, app.id, true);
        notice = installing && view.app.installed ? 'App installed.' : '';
      } catch (error) {
        if (!installing && enabled && error.status === 409 && active(view)) {
          appChanged = true;
          view.app = null;
          render(view, 'This app was updated. Checking its current notification settings…');
          try {
            const result = await api.status(ref, view.request.signal);
            updateApp(view, result, app.id, true);
            notice = 'This app was updated. Review its current notification settings before continuing.';
          } catch (reloadError) {
            if (reloadError.name !== 'AbortError') notice = 'This app was updated. Close and reopen to check its current settings.';
          }
        } else if (error.name !== 'AbortError') notice = error.message || 'The app could not be updated. Try again.';
      } finally {
        if (active(view)) {
          view.busy = false;
          render(view, notice);
          (appChanged || view.action.hidden ? view.dismiss : view.action).focus({preventScroll: true});
        }
      }
    }

    function open(reference, {isCurrent = () => true, onChange} = {}) {
      const ref = validId(reference?.app_id) ? {app_id: reference.app_id}
        : validId(reference?.gallery_id) ? {gallery_id: reference.gallery_id} : null;
      if (!ref || !isCurrent()) return false;
      if (current && current.ref.app_id === ref.app_id && current.ref.gallery_id === ref.gallery_id && active(current)) return true;
      const opener = current?.opener || document.activeElement;
      close(false);
      const dialog = element('dialog');
      dialog.className = 'sf-canvas-app-notifications';
      const panel = element('section');
      const heading = element('h2', 'App notifications');
      const description = element('p');
      const status = element('p');
      const controls = element('div');
      const action = button('Enable app notifications');
      const phone = button('Manage phone alerts');
      const dismiss = button('Close');
      const prefix = 'sfCanvasAppNotifications-' + (++sequence);
      heading.id = prefix + '-title';
      description.id = prefix + '-description';
      dialog.setAttribute('aria-labelledby', heading.id);
      dialog.setAttribute('aria-describedby', description.id);
      status.setAttribute('role', 'status');
      controls.className = 'sf-canvas-app-notification-actions';
      controls.append(action, phone, dismiss);
      panel.append(heading, description, status, controls);
      dialog.append(panel);
      const view = {app: null, owner: owner(), request: new AbortController(), dialog, opener, ref,
        heading, description, status, action, phone, dismiss, isCurrent, onChange, busy: false};
      current = view;
      action.onclick = event => { if (event.isTrusted && event.detail < 2) void change(view); };
      action.addEventListener('keydown', event => {
        if (event.repeat && (event.key === 'Enter' || event.key === ' ')) event.preventDefault();
      });
      phone.onclick = event => {
        if (!event.isTrusted || !active(view) || phone.hidden || view.busy) return;
        close(false);
        options.onPhoneAlerts?.();
      };
      dismiss.onclick = () => { if (current === view) close(); };
      dialog.addEventListener('cancel', event => { event.preventDefault(); dismiss.onclick(); });
      dialog.addEventListener('close', () => { if (current === view) close(); });
      document.body.append(dialog);
      view.busy = !!view.owner;
      render(view);
      try { dialog.showModal(); } catch (_) { close(false); return false; }
      dismiss.focus({preventScroll: true});
      if (view.owner) void (async () => {
        let notice = '';
        try {
          const result = await api.status(ref, view.request.signal);
          updateApp(view, result, ref.app_id);
        } catch (error) {
          if (error.name !== 'AbortError') notice = error.message || 'Your preference could not be loaded. Reopen to try again.';
        } finally {
          if (active(view)) {
            view.busy = false;
            render(view, notice);
          }
        }
      })();
      return true;
    }

    function setOwner() {
      if (current && current.owner !== owner()) close(false);
    }

    return Object.freeze({open, close, setOwner});
  }

  window.SFCanvasAppNotifications = Object.freeze({create});
})();
