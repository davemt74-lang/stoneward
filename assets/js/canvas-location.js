(() => {
  'use strict';

  let pending = false;
  const validPoint = point => point && Number.isFinite(point.lat) && Math.abs(point.lat) <= 90 &&
    Number.isFinite(point.lon) && Math.abs(point.lon) <= 180;
  const result = status => Object.freeze({status});

  function measure(position, target) {
    const {latitude, longitude, accuracy} = position?.coords || {};
    const age = Date.now() - position?.timestamp;
    if (!validPoint({lat: latitude, lon: longitude}) || !Number.isFinite(accuracy) || accuracy < 0 ||
        !Number.isFinite(age) || age < -5000 || age > 30000) return 'unavailable';

    const radians = Math.PI / 180;
    const from = latitude * radians;
    const to = target.lat * radians;
    const chord = Math.sin((to - from) / 2) ** 2 +
      Math.cos(from) * Math.cos(to) * Math.sin((target.lon - longitude) * radians / 2) ** 2;
    const distance = 2 * 6371008.8 * Math.asin(Math.sqrt(Math.min(1, chord)));
    if (distance + accuracy <= target.radius_m) return 'inside';
    if (distance - accuracy > target.radius_m) return 'outside';
    return 'uncertain';
  }

  function node(tag, text) {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    return element;
  }

  async function check(target, signal, activated = navigator.userActivation?.isActive === true) {
    if (!validPoint(target) || !Number.isInteger(target.radius_m) || target.radius_m < 10 ||
        target.radius_m > 10000 || signal?.aborted || pending || !window.isSecureContext ||
        typeof navigator.geolocation?.getCurrentPosition !== 'function') return result('unavailable');
    if (activated !== true) return result('denied');

    const opener = document.activeElement;
    const {lat, lon, radius_m} = target;
    const point = {lat, lon, radius_m};
    const label = typeof target.title === 'string' ? target.title.trim().slice(0, 160) : '';
    const coordinates = `${lat}°, ${lon}°`;
    const place = label ? `${label} (${coordinates})` : coordinates;

    return new Promise(resolve => {
      const dialog = node('dialog');
      if (typeof dialog.showModal !== 'function') { resolve(result('unavailable')); return; }
      dialog.className = 'sf-canvas-resource-picker sf-canvas-location';
      const header = node('header');
      const title = node('h2', 'Check your location');
      const description = node('p', `This canvas wants to know if you are within ${radius_m} m of ${place}. ` +
        'Stonefellow checks your location once in your browser. The canvas receives only the result.');
      title.id = 'sfCanvasLocationTitle';
      description.id = 'sfCanvasLocationDescription';
      dialog.setAttribute('aria-labelledby', title.id);
      dialog.setAttribute('aria-describedby', description.id);
      header.append(title, description);

      const footer = node('footer');
      const status = node('span');
      status.className = 'sf-canvas-resource-count';
      status.setAttribute('role', 'status');
      const actions = node('div');
      actions.className = 'sf-canvas-resource-actions';
      const cancel = node('button', 'Cancel');
      const allow = node('button', 'Check once');
      cancel.type = allow.type = 'button';
      cancel.autofocus = true;
      allow.className = 'sf-canvas-resource-primary';
      actions.append(cancel, allow);
      footer.append(status, actions);
      dialog.append(header, footer);

      let settled = false;
      function finish(value, restoreFocus = true) {
        if (settled) return;
        settled = true;
        pending = false;
        signal?.removeEventListener('abort', aborted);
        window.removeEventListener('pagehide', leaving);
        if (dialog.open) dialog.close();
        dialog.remove();
        if (restoreFocus && opener?.isConnected) opener.focus({preventScroll: true});
        resolve(result(value));
      }
      const aborted = () => finish('unavailable');
      const leaving = () => finish('unavailable', false);
      cancel.addEventListener('click', () => finish('denied'));
      dialog.addEventListener('cancel', event => { event.preventDefault(); finish('denied'); });
      dialog.addEventListener('close', () => finish('denied'));
      allow.addEventListener('click', event => {
        if (!event.isTrusted || allow.disabled || settled) return;
        allow.disabled = true;
        status.textContent = 'Checking your location…';
        try {
          navigator.geolocation.getCurrentPosition(
            position => { if (!settled) finish(measure(position, point)); },
            error => finish(error?.code === 1 ? 'denied' : 'unavailable'),
            {enableHighAccuracy: true, maximumAge: 0, timeout: 12000}
          );
        } catch (_) { finish('unavailable'); }
      });

      pending = true;
      signal?.addEventListener('abort', aborted, {once: true});
      window.addEventListener('pagehide', leaving, {once: true});
      try {
        document.body.append(dialog);
        dialog.showModal();
      } catch (_) { finish('unavailable'); }
    });
  }

  window.SFCanvasLocation = Object.freeze({check});
})();
