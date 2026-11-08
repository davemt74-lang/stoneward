(() => {
  'use strict';
  const context = window.STONEFELLOW_ADMIN_CONTEXT;
  if (!context) return;
  const {api, uploadApi, canvas, head} = context;
  const endpoint = 'pwa-splash.php';
  const artworkMime = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
  const assetTypes = {
    iconAssetId: {title: 'Install icon', help: 'Use a square PNG at least 192 × 192. A 512 × 512 image is recommended. Leaving this empty uses the site icon.', mime: ['image/png']},
    startupAssetId: {title: 'iOS startup artwork', help: 'Use a PNG, JPEG, or WebP. Device support and cropping vary.', mime: ['image/png', 'image/jpeg', 'image/webp']},
    'launch.assetId': {title: 'App splash artwork', help: 'Use a PNG, JPEG, WebP, or GIF.', mime: artworkMime},
    'waiting.assetId': {title: 'QR waiting splash artwork', help: 'Use a PNG, JPEG, WebP, or GIF.', mime: artworkMime}
  };
  const surfaces = {launch: 'App splash', waiting: 'QR waiting splash'};
  const surfaceNames = Object.keys(surfaces);
  const codeFields = ['html', 'css', 'js'];
  const maxImageSlots = 4;
  const maxBriefBytes = 1800;
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

  function draftPreviewPath(value) {
    const path = safePath(value);
    if (!path) return '';
    const url = new URL(path, location.origin);
    return url.pathname.endsWith('/pwa-splash-preview.php') &&
      url.searchParams.get('draft') === '1' && url.searchParams.get('shell') === '1' ? path : '';
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
      : 'Shared by scheduled jukeboxes. Guests see their session name and start time around the design.';
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
        <p class="pwa-help">Describe a new design or how to revise the current code. The Agent will use the artwork slot names you provide.</p>
        <label class="field">Design instructions <textarea data-agent-brief rows="3" maxlength="${maxBriefBytes}" placeholder="Make the artwork fade in over a dark background, with a still version for reduced motion."></textarea></label>
        <div class="actions">
          <button type="button" class="secondary" data-agent-generate disabled>Generate code</button>
          <button type="button" class="secondary" data-agent-stage hidden>Stage code</button>
        </div>
        <p class="status-line" data-agent-status role="status"></p>
        <details class="pwa-agent-proposal" data-agent-proposal hidden>
          <summary>Review generated code</summary><pre data-agent-source></pre>
        </details>
        <section class="pwa-code-artwork">
          <h3>Code artwork</h3>
          <p class="pwa-help">Choose up to four images from Media Library and name each one. Use lowercase letters, digits, and hyphens. Each name starts with a letter and can be up to 24 characters.</p>
          <p class="pwa-help">Images can be up to 1,600 × 1,600 pixels and 512 KiB each, with a 1 MiB total for this splash. Published code artwork is visible to visitors who open this splash.</p>
          <div data-code-image-slots></div>
          <button type="button" class="secondary" data-add-code-image>Add image slot</button>
          <details class="pwa-code-help">
            <summary>Use artwork in JavaScript</summary>
            <pre>const images = JSON.parse(document.getElementById('sf-splash-media').textContent);
const artwork = document.querySelector('.splash-art');
if (artwork &amp;&amp; images.background) {
  const image = document.createElement('img');
  image.src = images.background;
  image.alt = '';
  artwork.append(image);
}</pre>
            <p class="pwa-help">Add an empty figure with the class <code>splash-art</code> in HTML and select an image named <code>background</code> above.</p>
          </details>
        </section>
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
          <iframe data-preview-code title="Splash code preview" referrerpolicy="no-referrer" hidden></iframe>
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
    const imageSlots = {launch: [], waiting: []};
    const agent = {launch: {epoch: 0}, waiting: {epoch: 0}};
    const surfaceSection = key => $(`[data-surface="${key}"]`);
    let state = initial;
    let busy = false;
    let dirty = false;
    let mediaSelection = null;
    let mediaRequest = 0;

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

    function readImageSlots(key, ignoreIncomplete = false) {
      const names = new Set();
      const slots = [];
      for (const slot of imageSlots[key]) {
        const name = slot.name.trim();
        if (!/^[a-z][a-z0-9-]{0,23}$/.test(name) || names.has(name)) {
          if (ignoreIncomplete) continue;
          throw new Error(`${surfaces[key]} needs a unique name for each code image. Start with a lowercase letter and use up to 24 letters, digits, or hyphens.`);
        }
        if (!slot.assetId) {
          if (ignoreIncomplete) continue;
          throw new Error(`Choose an image for the ${surfaces[key]} slot “${name}” or remove the slot.`);
        }
        names.add(name);
        slots.push({name, assetId: slot.assetId});
      }
      return slots;
    }

    function renderImageSlots(key) {
      const section = surfaceSection(key);
      const list = section.querySelector('[data-code-image-slots]');
      list.replaceChildren();
      for (const slot of imageSlots[key]) {
        const row = document.createElement('div');
        row.className = 'pwa-code-image-slot';
        row.dataset.codeImageSlot = '';
        row.innerHTML = `<label class="field">Image slot name <input data-code-image-name maxlength="24" autocapitalize="none" spellcheck="false"></label>
          <div class="pwa-asset-selected"><img alt="" hidden><span></span></div>
          <div class="actions">
            <button type="button" class="secondary" data-choose-code-image>Choose from Media Library</button>
            <button type="button" class="secondary" data-remove-code-image>Remove slot</button>
          </div>`;
        const name = row.querySelector('[data-code-image-name]');
        name.value = slot.name;
        name.oninput = () => {
          slot.name = name.value;
          invalidateAgent(key);
          edited();
        };
        const showImage = () => {
          const image = row.querySelector('img');
          image.hidden = !slot.assetUrl;
          if (slot.assetUrl) image.src = slot.assetUrl;
          row.querySelector('span').textContent = slot.assetId ? slot.label || 'Media Library asset #' + slot.assetId : 'No image selected';
        };
        row.querySelector('[data-choose-code-image]').onclick = () => openMediaPicker('code', asset => {
          slot.assetId = Number(asset.id);
          slot.assetUrl = assetSource(asset);
          slot.label = asset.title || asset.original_name;
          showImage();
          invalidateAgent(key);
          edited();
        });
        row.querySelector('[data-remove-code-image]').onclick = () => {
          const index = imageSlots[key].indexOf(slot);
          imageSlots[key].splice(index, 1);
          renderImageSlots(key);
          invalidateAgent(key);
          edited();
          section.querySelector('[data-add-code-image]').focus();
        };
        showImage();
        list.append(row);
      }
      section.querySelector('[data-add-code-image]').disabled = imageSlots[key].length >= maxImageSlots;
    }

    function addImageSlot(key) {
      if (imageSlots[key].length >= maxImageSlots) return;
      const names = new Set(imageSlots[key].map(slot => slot.name));
      let name = 'artwork';
      for (let suffix = 2; names.has(name); suffix++) name = 'artwork-' + suffix;
      imageSlots[key].push({name, assetId: 0});
      renderImageSlots(key);
      invalidateAgent(key);
      edited();
      surfaceSection(key).querySelector('[data-code-image-slot]:last-child input').focus();
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
        settings[key].imageSlots = readImageSlots(key, settings[key].mode !== 'code');
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
      const preview = mode === 'code' && saved.mode === 'code' && draftPreviewPath(saved.previewUrl);
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
        const section = surfaceSection(key);
        const mode = field(key + '.mode').value;
        const {request, proposal} = agent[key];
        section.querySelector('[data-image-fields]').hidden = mode !== 'image';
        section.querySelector('[data-code-fields]').hidden = mode !== 'code';
        const generate = section.querySelector('[data-agent-generate]');
        generate.disabled = Boolean(request) || !section.querySelector('[data-agent-brief]').value.trim();
        generate.textContent = request ? 'Generating code…' : 'Generate code';
        section.querySelector('[data-agent-stage]').hidden = !proposal;
        section.querySelector('[data-agent-proposal]').hidden = !proposal;
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
        imageSlots[key] = (surface.imageSlots || []).map(slot => ({
          name: slot.name, assetId: Number(slot.assetId), assetUrl: safePath(slot.assetUrl)
        }));
        renderImageSlots(key);
        if (!agent[key].request && !agent[key].proposal) announceAgent(key, '');
        invalidateAgent(key);
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

    function announceAgent(key, message, error = false) {
      const label = surfaceSection(key).querySelector('[data-agent-status]');
      label.textContent = message;
      label.classList.toggle('form-error', error);
    }

    function invalidateAgent(key) {
      const panel = agent[key];
      panel.epoch++;
      if (!panel.proposal) return;
      panel.proposal = null;
      announceAgent(key, 'The draft inputs changed. Generate again to get updated code.');
    }

    function currentRequest(key, request) {
      return root.isConnected && request.revision === state.draft.revision &&
        request.epoch === agent[key].epoch && field(key + '.mode').value === 'code';
    }

    async function generateCode(key) {
      const panel = agent[key];
      if (busy || panel.request || field(key + '.mode').value !== 'code') return;
      const brief = surfaceSection(key).querySelector('[data-agent-brief]').value.trim();
      if (!brief || new TextEncoder().encode(brief).length > maxBriefBytes) {
        announceAgent(key, 'Enter design instructions up to 1,800 UTF-8 bytes.', true);
        return;
      }
      let slots;
      try {
        slots = readImageSlots(key);
      } catch (error) {
        announceAgent(key, error.message, true);
        return;
      }
      const request = {revision: state.draft.revision, epoch: panel.epoch};
      const design = Object.fromEntries(codeFields.map(name => [name, field(key + '.' + name).value]));
      panel.request = request;
      panel.proposal = null;
      announceAgent(key, 'The Agent is preparing splash code…');
      updateControls();
      try {
        const result = await api('pwa-splash-code.php', {surface: key, revision: request.revision, brief, design, imageSlots: slots});
        if (panel.request !== request || !root.isConnected) return;
        if (!currentRequest(key, request)) {
          announceAgent(key, 'The draft inputs changed while the Agent was working. Generate again with the current inputs.');
          return;
        }
        if (result.surface !== key || result.revision !== request.revision ||
            !codeFields.every(name => typeof result.code?.[name] === 'string')) {
          throw new Error('The Agent returned an invalid splash design.');
        }
        panel.proposal = {request, code: result.code};
        const details = surfaceSection(key).querySelector('[data-agent-proposal]');
        details.open = false;
        details.querySelector('pre').textContent = codeFields.map(name => name.toUpperCase() + '\n' + result.code[name]).join('\n\n');
        announceAgent(key, 'Code is ready to review and stage.');
      } catch (error) {
        if (panel.request !== request || !root.isConnected) return;
        if (!currentRequest(key, request)) {
          announceAgent(key, 'The draft inputs changed while the Agent was working. Generate again with the current inputs.');
        } else {
          announceAgent(key, error.status === 409 ? 'The saved draft changed. Reload the editor before generating again.' : error.message, true);
        }
      } finally {
        if (panel.request === request) panel.request = null;
        if (root.isConnected) updateControls();
      }
    }

    function stageCode(key) {
      const panel = agent[key];
      const proposal = panel.proposal;
      if (busy || !proposal) return;
      if (!currentRequest(key, proposal.request)) {
        invalidateAgent(key);
        updateControls();
        return;
      }
      for (const name of codeFields) field(key + '.' + name).value = proposal.code[name];
      panel.proposal = null;
      panel.epoch++;
      $('[data-preview-surface]').value = key;
      edited();
      announceAgent(key, 'Code staged. Edit it here, then save the draft to preview.');
      field(key + '.html').focus();
    }

    function eligible(key, asset) {
      if (!(key === 'code' ? artworkMime : assetTypes[key].mime).includes(asset.mime_type)) return false;
      return key !== 'iconAssetId' || Number(asset.width) >= 192 && Number(asset.width) === Number(asset.height);
    }

    function chooseAsset(key, asset) {
      setAsset(key, asset.id, assetSource(asset), asset.title || asset.original_name);
      edited();
    }

    function openMediaPicker(key, choose) {
      mediaSelection = {key, choose};
      $('[data-media-search]').reset();
      picker.showModal();
      void searchMedia();
    }

    async function searchMedia() {
      if (!mediaSelection) return;
      const token = ++mediaRequest;
      const {key, choose} = mediaSelection;
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
          button.onclick = () => { choose(asset); picker.close(); };
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

    form.addEventListener('submit', event => event.preventDefault());
    form.addEventListener('input', event => {
      const name = event.target.name || '';
      const key = event.target.closest('[data-surface]')?.dataset.surface;
      if (key && (event.target.matches('[data-agent-brief]') || ['mode', ...codeFields].includes(name.split('.')[1]))) {
        invalidateAgent(key);
        if (!name) updateControls();
      }
      if (name) edited();
    });
    $('[data-preview-surface]').onchange = updatePreview;
    $('[data-save-draft]').onclick = async () => {
      try {
        await apply('save', {settings: readSettings()}, 'Draft saved. Preview updated.');
      } catch (error) {
        announce(error.message, true);
      }
    };
    $('[data-publish-splash]').onclick = async () => {
      const revision = state.draft.revision;
      const confirmed = await window.SFAdminConfirm.ask({
        title: 'Publish PWA & Splash?', description: 'Visitors will see this draft’s launch settings, app splash, QR waiting splash, and artwork used in those designs.',
        actionLabel: 'Publish splash settings'
      });
      if (confirmed && root.isConnected && !dirty && revision === state.draft.revision) {
        await apply('publish', {}, 'Splash settings published.');
      }
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
      row.querySelector('[data-choose]').onclick = () => openMediaPicker(key, asset => chooseAsset(key, asset));
      row.querySelector('[data-upload]').onclick = () => input.click();
      row.querySelector('[data-clear]').onclick = () => { setAsset(key, 0); edited(); };
      input.onchange = () => {
        const file = input.files[0];
        input.value = '';
        if (file && !busy) void upload(key, file);
      };
    }
    for (const key of surfaceNames) {
      const section = surfaceSection(key);
      section.querySelector('[data-surface-preview]').onclick = () => {
        $('[data-preview-surface]').value = key;
        updatePreview();
        $('.pwa-preview').scrollIntoView({block: 'nearest'});
        $('.pwa-preview').focus({preventScroll: true});
      };
      section.querySelector('[data-agent-generate]').onclick = () => generateCode(key);
      section.querySelector('[data-agent-stage]').onclick = () => stageCode(key);
      section.querySelector('[data-add-code-image]').onclick = () => addImageSlot(key);
    }
    hydrate();
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
