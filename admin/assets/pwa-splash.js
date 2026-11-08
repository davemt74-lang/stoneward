(() => {
  'use strict';
  const context = window.STONEFELLOW_ADMIN_CONTEXT;
  if (!context) return;
  const {api, uploadApi, canvas, head} = context;
  const endpoint = 'pwa-splash.php';
  const assetTypes = {
    iconAssetId: {title: 'Install icon', help: 'Use a square PNG at least 192 × 192. A 512 × 512 image is recommended. Leaving this empty uses the site icon.', mime: ['image/png']},
    startupAssetId: {title: 'iOS startup artwork', help: 'Use a PNG, JPEG, or WebP. Device support and cropping vary.', mime: ['image/png', 'image/jpeg', 'image/webp']},
    'launch.assetId': {title: 'App splash artwork', help: 'Use a PNG, JPEG, WebP, or GIF.', mime: ['image/png', 'image/jpeg', 'image/webp', 'image/gif']},
    'waiting.assetId': {title: 'QR waiting splash artwork', help: 'Use a PNG, JPEG, WebP, or GIF.', mime: ['image/png', 'image/jpeg', 'image/webp', 'image/gif']}
  };
  const surfaces = {launch: 'App splash', waiting: 'QR waiting splash'};
  const surfaceNames = Object.keys(surfaces);
  const assetSource = asset => '../api/media.php?' + new URLSearchParams({id: asset.uuid});

  function safePath(value) {
    if (typeof value !== 'string' || !value.startsWith('/')) return '';
    try {
      const url = new URL(value, location.origin);
      return url.origin === location.origin ? url.pathname + url.search : '';
    } catch {
      return '';
    }
  }

  function assetField(key) {
    const {title, help, mime} = assetTypes[key];
    return `<div class="pwa-asset" data-asset-field="${key}">
      <h3>${title}</h3><p class="pwa-help">${help}</p>
      <div class="pwa-asset-selected" hidden><img alt=""><span></span></div>
      <div class="actions">
        <button type="button" class="secondary" data-choose>Choose from Media Library</button>
        <button type="button" class="secondary" data-upload>Upload image</button>
        <button type="button" class="secondary" data-clear hidden>Clear image</button>
      </div>
      <input type="file" accept="${mime.join(',')}" hidden>
    </div>`;
  }

  function surfaceEditor(key) {
    const description = key === 'launch'
      ? 'Shown in Stonefellow after the app opens.'
      : 'Shown when a guest opens the link or scans the QR code before the host starts.';
    return `<section class="pwa-card" data-surface="${key}">
      <div class="panel-title"><h2>${surfaces[key]}</h2>
        <button type="button" class="secondary" data-surface-preview>Preview</button>
      </div>
      <p class="pwa-help">${description}</p>
      <label class="field">Appearance
        <select name="${key}.mode">
          <option value="default">Built-in</option><option value="image">Image</option><option value="code">Code</option>
        </select>
      </label>
      <div data-surface-text>
        <label class="field">Heading <input name="${key}.title" maxlength="100"></label>
        <label class="field">Message <textarea name="${key}.message" rows="2" maxlength="300"></textarea></label>
      </div>
      <div data-image-fields hidden>${assetField(key + '.assetId')}</div>
      <div class="pwa-code" data-code-fields hidden>
        <h3>Agent Code Editor</h3>
        <p class="pwa-help">Ask the Agent to build a responsive splash canvas with standalone HTML, CSS, and JavaScript. Open its Code panel to review the result, then return here to import it from Canvas History.</p>
        <div class="actions">
          <a class="secondary" href="../stonefellow-v120.php?view=home" target="_blank" rel="noopener">Open Agent Code Editor</a>
          <button type="button" class="secondary" data-scene-refresh>Refresh saved canvases</button>
        </div>
        <label class="field">Saved canvas <select data-scene-select><option value="">Choose a saved canvas</option></select></label>
        <button type="button" class="secondary" data-scene-import disabled>Import code into draft</button>
        <p class="pwa-help" data-scene-status></p>
        <p class="pwa-help">The preview runs in an isolated frame. The waiting page supplies the session name, start time, and waiting status around the artwork.</p>
        <label class="field">HTML <textarea name="${key}.html" rows="6" maxlength="12000" spellcheck="false"></textarea></label>
        <label class="field">CSS <textarea name="${key}.css" rows="5" maxlength="12000" spellcheck="false"></textarea></label>
        <label class="field">JavaScript <textarea name="${key}.js" rows="5" maxlength="12000" spellcheck="false"></textarea></label>
      </div>
    </section>`;
  }

  function editorMarkup() {
    return `<div class="pwa-splash-layout">
      <form data-splash-form>
        <fieldset data-splash-controls>
          <section class="pwa-card">
            <h2>Device launch</h2>
            <p class="pwa-help">The install icon and background color are used by supported devices when launching Stonefellow. The device controls the native launch screen.</p>
            <label class="check-field"><input type="checkbox" name="inheritColor"> Use the published site theme color</label>
            <label class="field pwa-color">Launch background color <input type="color" name="backgroundColor"></label>
            ${assetField('iconAssetId')}${assetField('startupAssetId')}
          </section>
          ${surfaceNames.map(surfaceEditor).join('')}
        </fieldset>
      </form>
      <aside class="pwa-card pwa-preview" aria-label="Splash preview" tabindex="-1">
        <h2>Preview</h2>
        <label class="field">Splash surface
          <select data-preview-surface><option value="launch">App splash</option><option value="waiting">QR waiting splash</option></select>
        </label>
        <div class="pwa-phone-preview" data-preview-phone>
          <div class="pwa-preview-context" data-preview-context hidden>
            <strong>Friday listening session</strong>
            <small>Fri, Nov 13 · 8:00 PM</small>
            <span>Waiting for the host to start</span>
          </div>
          <div class="pwa-preview-copy" data-preview-copy>
            <img alt="" hidden><strong></strong><span></span>
          </div>
          <iframe data-preview-code title="Sandboxed splash code preview" sandbox="allow-scripts" referrerpolicy="no-referrer" hidden></iframe>
        </div>
        <p class="pwa-help" data-preview-note></p>
      </aside>
    </div>
    <dialog class="pwa-media-picker" aria-labelledby="pwa-media-title" data-media-picker>
      <div class="panel-title">
        <h2 id="pwa-media-title">Choose Media Library artwork</h2>
        <button class="secondary" type="button" data-media-close>Close</button>
      </div>
      <form class="pwa-media-search" data-media-search>
        <label class="field">Find images <input type="search" name="query"></label>
        <button class="secondary" type="submit">Search</button>
      </form>
      <p class="status-line" data-media-status role="status"></p>
      <div class="pwa-media-results" data-media-results></div>
    </dialog>`;
  }

  function mount(root, initial) {
    const $ = selector => root.querySelector(selector);
    const form = $('[data-splash-form]');
    const field = name => form.elements.namedItem(name);
    const status = $('[data-splash-status]');
    const picker = $('[data-media-picker]');
    const selected = new Map();
    let state = initial;
    let busy = false;
    let dirty = false;
    let mediaKey = '';
    let mediaRequest = 0;
    let scenes = null;
    let scenesLoading = false;

    function announce(message, error = false) {
      status.textContent = message;
      status.classList.toggle('form-error', error);
    }

    function setAsset(key, id, url = '', name = '') {
      const row = $(`[data-asset-field="${key}"]`);
      const figure = row.querySelector('.pwa-asset-selected');
      const image = figure.querySelector('img');
      const previous = selected.get(key);
      const label = name || (Number(id) === previous?.id ? previous.name : '') || 'Media Library asset #' + id;
      selected.set(key, {id: Number(id) || 0, url, name: label});
      figure.hidden = !id;
      row.querySelector('[data-clear]').hidden = !id;
      figure.querySelector('span').textContent = id ? label : '';
      if (url) image.src = url; else image.removeAttribute('src');
      image.hidden = !url;
    }

    function readSettings() {
      const settings = {
        iconAssetId: selected.get('iconAssetId').id,
        startupAssetId: selected.get('startupAssetId').id,
        backgroundColor: field('inheritColor').checked ? '' : field('backgroundColor').value
      };
      for (const key of surfaceNames) {
        settings[key] = Object.fromEntries(['mode', 'title', 'message', 'html', 'css', 'js'].map(name =>
          [name, field(key + '.' + name).value]));
        settings[key].assetId = selected.get(key + '.assetId').id;
      }
      return settings;
    }

    function updatePreview() {
      const key = $('[data-preview-surface]').value;
      const mode = field(key + '.mode').value;
      const box = $('[data-preview-phone]');
      const copy = $('[data-preview-copy]');
      const image = copy.querySelector('img');
      const frame = $('[data-preview-code]');
      const saved = state.draft[key];
      const artwork = mode === 'image' ? selected.get(key + '.assetId')?.url : '';
      const preview = mode === 'code' && saved.mode === 'code' && safePath(saved.previewUrl);
      const heading = field(key + '.title').value || (key === 'launch' ? 'Welcome to Stonefellow' : '');
      const message = field(key + '.message').value;
      box.style.backgroundColor = field('inheritColor').checked ? '' : field('backgroundColor').value;
      $('[data-preview-context]').hidden = key !== 'waiting';
      if (artwork) image.src = artwork; else image.removeAttribute('src');
      image.hidden = !artwork;
      copy.querySelector('strong').textContent = heading;
      copy.querySelector('span').textContent = message;
      copy.hidden = !heading && !message && !artwork;
      frame.hidden = !preview;
      if (preview) {
        if (frame.getAttribute('src') !== preview) frame.src = preview;
      } else frame.removeAttribute('src');
      $('[data-preview-note]').textContent = mode === 'code'
        ? (preview ? 'Showing the saved code draft. Save changes to refresh the preview.' : 'Save your code draft to load the sandbox preview.')
        : 'Preview of the current editor settings.';
    }

    function updateControls() {
      $('[data-splash-controls]').disabled = busy;
      field('backgroundColor').disabled = field('inheritColor').checked;
      const different = state.hasUnpublishedChanges;
      $('[data-save-draft]').disabled = busy || !dirty;
      $('[data-publish-splash]').disabled = busy || dirty || !different;
      $('[data-restore-splash]').disabled = busy || (!dirty && !different);
      $('[data-reload-splash]').disabled = busy;
      $('[data-revisions]').textContent = `Draft ${state.draft.revision} · Published ${state.published.revision}${dirty ? ' · Unsaved changes' : ''}`;
      for (const key of surfaceNames) {
        const section = $(`[data-surface="${key}"]`);
        const mode = field(key + '.mode').value;
        section.querySelector('[data-image-fields]').hidden = mode !== 'image';
        section.querySelector('[data-code-fields]').hidden = mode !== 'code';
        section.querySelector('[data-scene-import]').disabled = scenesLoading || !section.querySelector('[data-scene-select]').value;
      }
      updatePreview();
    }

    function hydrate() {
      const draft = state.draft;
      const color = /^#[0-9a-f]{6}$/i.test(draft.backgroundColor) ? draft.backgroundColor : '';
      field('inheritColor').checked = !color;
      field('backgroundColor').value = color || '#0b1019';
      setAsset('iconAssetId', draft.iconAssetId, safePath(draft.iconUrl));
      setAsset('startupAssetId', draft.startupAssetId, safePath(draft.startupUrl));
      for (const key of surfaceNames) {
        const surface = draft[key];
        for (const name of ['mode', 'title', 'message', 'html', 'css', 'js']) field(key + '.' + name).value = surface[name] ?? '';
        setAsset(key + '.assetId', surface.assetId, safePath(surface.assetUrl));
      }
      dirty = false;
      updateControls();
    }

    function edited() {
      dirty = true;
      announce('Save the draft before publishing.');
      updateControls();
    }

    async function apply(action, payload, success) {
      if (busy) return;
      busy = true;
      updateControls();
      announce('Working…');
      try {
        state = await api(endpoint, {action, revision: state.draft.revision, ...payload});
        if (!root.isConnected) return;
        hydrate();
        announce(success);
      } catch (error) {
        announce(error.status === 409 ? 'The draft changed in another tab. Reload the editor to get the current revision.' : error.message, true);
      } finally {
        busy = false;
        if (root.isConnected) updateControls();
      }
    }

    function eligible(key, asset) {
      if (!assetTypes[key].mime.includes(asset.mime_type)) return false;
      return key !== 'iconAssetId' || Number(asset.width) >= 192 && Number(asset.width) === Number(asset.height);
    }

    function chooseAsset(key, asset) {
      setAsset(key, asset.id, assetSource(asset), asset.title || asset.original_name);
      edited();
    }

    async function searchMedia() {
      const token = ++mediaRequest;
      const key = mediaKey;
      const mediaStatus = $('[data-media-status]');
      const results = $('[data-media-results]');
      const query = $('[data-media-search]').elements.query.value.trim();
      mediaStatus.textContent = 'Loading images…';
      results.replaceChildren();
      try {
        const data = await api('media.php?' + new URLSearchParams({category: 'image', q: query}));
        if (token !== mediaRequest || !picker.open) return;
        const images = (data.assets || []).filter(asset => eligible(key, asset));
        mediaStatus.textContent = images.length ? (images.length > 60 ? 'Showing the first 60 matches. Search to narrow the results.' : '') : 'No matching images.';
        for (const asset of images.slice(0, 60)) {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'secondary';
          const image = document.createElement('img');
          image.alt = '';
          image.loading = 'lazy';
          image.src = assetSource(asset) + (asset.variants?.thumb ? '&variant=thumb' : '');
          const label = document.createElement('span');
          label.textContent = asset.title || asset.original_name;
          button.append(image, label);
          button.onclick = () => { chooseAsset(key, asset); picker.close(); };
          results.append(button);
        }
      } catch (error) {
        if (token === mediaRequest && picker.open) mediaStatus.textContent = error.message;
      }
    }

    async function upload(key, file) {
      busy = true;
      updateControls();
      announce('Uploading to Media Library…');
      try {
        const data = new FormData();
        data.append('file', file);
        const {asset} = await uploadApi('media-upload.php', data);
        if (!root.isConnected) return;
        if (!eligible(key, asset)) throw new Error('The file was uploaded to Media Library. Choose an image that meets the requirements for this field.');
        chooseAsset(key, asset);
        announce('Image selected. Save the draft to keep it.');
      } catch (error) {
        announce(error.message, true);
      } finally {
        busy = false;
        if (root.isConnected) updateControls();
      }
    }

    async function loadScenes() {
      if (scenesLoading) return;
      scenesLoading = true;
      const labels = root.querySelectorAll('[data-scene-status]');
      labels.forEach(label => { label.textContent = 'Loading saved canvases…'; });
      updateControls();
      try {
        const response = await fetch('../api/canvas-resources.php?kind=canvas', {cache: 'no-store'});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Could not load saved canvases.');
        scenes = (data.items || []).filter(row => row.canvas_kind === 'custom');
        for (const select of root.querySelectorAll('[data-scene-select]')) {
          const previous = select.value;
          select.replaceChildren(new Option('Choose a saved canvas', ''));
          for (const row of scenes) select.add(new Option(row.title + ' · #' + row.id, row.id));
          if (scenes.some(row => row.id === previous)) select.value = previous;
        }
        labels.forEach(label => { label.textContent = scenes.length ? '' : 'No saved custom canvases. Open the Agent Code Editor to make one.'; });
      } catch (error) {
        labels.forEach(label => { label.textContent = error.message; });
      } finally {
        scenesLoading = false;
        if (root.isConnected) updateControls();
      }
    }

    form.addEventListener('submit', event => event.preventDefault());
    form.addEventListener('input', event => {
      if (event.target.name) edited();
      if (event.target.name?.endsWith('.mode') && event.target.value === 'code' && scenes === null) void loadScenes();
    });
    $('[data-preview-surface]').onchange = updatePreview;
    $('[data-save-draft]').onclick = () => apply('save', {settings: readSettings()}, 'Draft saved. Preview updated.');
    $('[data-publish-splash]').onclick = async () => {
      if (await window.SFAdminConfirm.ask({
        title: 'Publish PWA & Splash?', description: 'Visitors will see this draft’s launch settings, app splash, and QR waiting splash.',
        actionLabel: 'Publish splash settings'
      })) await apply('publish', {}, 'Splash settings published.');
    };
    $('[data-restore-splash]').onclick = async () => {
      if (await window.SFAdminConfirm.ask({
        title: 'Restore the published settings?', description: 'The published settings will replace the current draft and any unsaved edits.',
        actionLabel: 'Restore to draft'
      })) await apply('restore', {}, 'Published settings restored to the draft.');
    };
    $('[data-reload-splash]').onclick = async () => {
      if (!dirty || await window.SFAdminConfirm.ask({
        title: 'Reload the editor?', description: 'Reloading will discard unsaved edits.', actionLabel: 'Reload editor'
      })) await render();
    };
    $('[data-media-close]').onclick = () => picker.close();
    $('[data-media-search]').onsubmit = event => { event.preventDefault(); void searchMedia(); };

    for (const key of Object.keys(assetTypes)) {
      const row = $(`[data-asset-field="${key}"]`);
      const input = row.querySelector('input');
      row.querySelector('[data-choose]').onclick = () => {
        mediaKey = key;
        $('[data-media-search]').reset();
        picker.showModal();
        void searchMedia();
      };
      row.querySelector('[data-upload]').onclick = () => input.click();
      row.querySelector('[data-clear]').onclick = () => { setAsset(key, 0); edited(); };
      input.onchange = () => {
        const file = input.files[0];
        input.value = '';
        if (file && !busy) void upload(key, file);
      };
    }
    for (const key of surfaceNames) {
      const section = $(`[data-surface="${key}"]`);
      const select = section.querySelector('[data-scene-select]');
      section.querySelector('[data-surface-preview]').onclick = () => {
        $('[data-preview-surface]').value = key;
        updatePreview();
        $('.pwa-preview').scrollIntoView({block: 'nearest'});
        $('.pwa-preview').focus({preventScroll: true});
      };
      select.onchange = updateControls;
      section.querySelector('[data-scene-refresh]').onclick = loadScenes;
      section.querySelector('[data-scene-import]').onclick = async () => {
        const canvasId = select.value;
        if (!canvasId) return;
        const confirmed = await window.SFAdminConfirm.ask({
          title: 'Import this saved canvas?', description: 'The selected canvas will replace the code for ' + surfaces[key] + ' in the draft.',
          actionLabel: 'Import into draft'
        });
        if (!confirmed) return;
        $('[data-preview-surface]').value = key;
        await apply('import', {surface: key, canvasId, settings: readSettings()}, 'Canvas code imported. Review the draft preview before publishing.');
      };
    }
    hydrate();
    if (surfaceNames.some(key => field(key + '.mode').value === 'code')) void loadScenes();
  }

  async function render() {
    const root = document.createElement('div');
    root.className = 'pwa-splash-admin';
    root.innerHTML = head('APP EXPERIENCE', 'PWA & Splash',
      'Set the device launch appearance, the in-app welcome splash, and the waiting screen guests see before a scheduled jukebox starts.') +
      '<div class="empty" role="status" data-loading>Loading splash settings…</div>';
    canvas.replaceChildren(root);
    try {
      const data = await api(endpoint);
      if (!root.isConnected) return;
      root.querySelector('[data-loading]').outerHTML = `
        <div class="pwa-actions">
          <div class="actions">
            <button type="button" class="primary" data-save-draft>Save draft</button>
            <button type="button" class="secondary" data-publish-splash>Publish</button>
            <button type="button" class="secondary" data-restore-splash>Restore published to draft</button>
            <button type="button" class="secondary" data-reload-splash>Reload editor</button>
          </div>
          <p class="status-line" data-revisions></p>
          <p class="status-line" role="status" data-splash-status></p>
        </div>${editorMarkup()}`;
      mount(root, data);
    } catch (error) {
      if (!root.isConnected) return;
      const target = root.querySelector('[data-loading]');
      const message = document.createElement('p');
      message.className = 'form-error';
      message.textContent = error.message;
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'secondary';
      retry.textContent = 'Retry';
      retry.onclick = render;
      if (target) target.replaceChildren(message, retry);
      else root.append(message, retry);
    }
  }

  window.SFPwaSplashAdmin = Object.freeze({render});
})();
