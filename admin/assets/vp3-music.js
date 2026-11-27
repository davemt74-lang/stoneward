(() => {
  'use strict';
  const ctx = window.STONEFELLOW_ADMIN_CONTEXT;
  if (!ctx) return;
  const { api, canvas, head, esc } = ctx;
  const state = {
    connection: { configured: false, revision: 0, state: 'disconnected' },
    catalogHash: '', items: [], selected: new Set(), savedSelection: new Set(),
    selectionRevision: null, nextOffset: null, query: '', loaded: false, needsReload: false,
    pending: null, lastImport: null, retry: null, busy: false, review: false,
    showConnection: false, error: '', notice: '', epoch: 0
  };
  const $ = (selector) => canvas.querySelector(selector);
  const active = (epoch) => epoch === state.epoch && ctx.app.view === 'vp3-music';
  const isReady = (item) => item.availability === 'ready';
  const title = (item) => String(item.metadata?.title || 'Unavailable recording');
  const album = (item) => String(item.metadata?.album || 'Singles / unreleased');
  const artist = (item) => (item.metadata?.performers || []).map(p => p.name).filter(Boolean).join(', ');
  const selectedIds = () => Array.from(state.selected).sort();
  const uuid = () => {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) +
      '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  };
  function updateStatus(result) {
    if (state.loaded && ((typeof result.catalog_hash === 'string' && state.catalogHash && result.catalog_hash !== state.catalogHash) ||
        (Number.isInteger(result.connection?.selection_revision) && result.connection.selection_revision !== state.selectionRevision) ||
        (result.connection && result.connection.state !== 'ready'))) {
      state.needsReload = true; state.review = false;
    }
    if (result.connection) {
      const old = state.connection;
      const next = result.connection;
      if (old.revision !== next.revision || old.installation_id !== next.installation_id || old.site_id !== next.site_id) {
        state.loaded = false; state.items = []; state.selected = new Set();
        state.savedSelection = new Set(); state.selectionRevision = null;
        state.review = false; state.nextOffset = null; state.query = ''; state.needsReload = false;
      }
      state.connection = next;
    }
    if (typeof result.catalog_hash === 'string') state.catalogHash = result.catalog_hash;
    if (Object.hasOwn(result, 'pending')) state.pending = result.pending;
    if (Object.hasOwn(result, 'last_import')) state.lastImport = result.last_import;
  }
  function safeHref(raw, providerOnly = false) {
    try {
      const url = new URL(String(raw));
      if (url.protocol !== 'https:' || url.username || url.password) return '';
      if (providerOnly && url.origin !== new URL(state.connection.provider_url).origin) return '';
      return url.href;
    } catch (_) { return ''; }
  }
  function providerBase() {
    const href = safeHref(state.connection.provider_url);
    return href ? href.replace(/\/api\/music-provider-v1\.php\/?$/, '').replace(/\/$/, '') : '';
  }
  function messages() {
    return '<div id="vp3MusicStatus" class="vp3-music-status" role="status" aria-live="polite">' +
      (state.notice ? '<p class="notice">' + esc(state.notice) + '</p>' : '') +
      (state.error ? '<p class="form-error" role="alert">' + esc(state.error) + '</p>' : '') +
      (state.retry ? '<p>Resolve the saved request before making another change. Retrying sends exactly the same settings or selection.</p><button type="button" class="secondary" id="vp3Retry">Retry the same request</button>' : '') +
      '</div>';
  }
  function connectionForm() {
    const connected = state.connection.configured;
    if (connected && !state.showConnection) return '';
    return '<section class="panel vp3-connection-form"><h2>' + (connected ? 'Replace connection' : 'Connect this artist site') + '</h2>' +
      '<ol class="vp3-setup-steps"><li>In VP3, open Music Workspace → Sites &amp; streaming and register this website.</li>' +
      '<li>Copy the site verification JSON and the one-time music connection credential into the fields below.</li>' +
      '<li>Save here, choose Verify site in VP3, then return and check the connection.</li></ol>' +
      '<form id="vp3ConnectForm"><div class="vp3-form-grid">' +
      '<label class="field">VP3 address<input name="provider_url" type="url" inputmode="url" maxlength="512" required placeholder="https://your-vp3.example" value="' +
      esc(providerBase()) + '"><small>The HTTPS address of your VP3 installation.</small></label>' +
      '<label class="field">Music connection credential<input name="credential" type="password" autocomplete="off" spellcheck="false" minlength="69" maxlength="69" required placeholder="vmp1_…">' +
      '<small>Saved privately on this server. Your VP3 password is not needed.</small></label>' +
      '<label class="field vp3-full-row">Site verification JSON<textarea name="verification" rows="5" maxlength="4096" required spellcheck="false" placeholder="Paste the verification response from VP3"></textarea></label>' +
      '</div><div class="actions"><button class="primary" type="submit">Save connection</button>' +
      (connected ? '<button class="secondary" id="vp3CancelConnection" type="button">Cancel</button>' : '') +
      '</div></form></section>';
  }
  function connectionPanel() {
    const c = state.connection;
    if (!c.configured) return connectionForm();
    const labels = { ready: 'Connected', pending_verification: 'Verification needed', unavailable: 'Check connection', disconnected: 'Disconnected' };
    const base = providerBase();
    const href = base ? base + '/music-workspace.php' : '';
    return '<section class="panel vp3-connection"><div class="panel-title"><div><h2>VP3 connection</h2>' +
      '<p class="vp3-connection-state"><span class="badge ' + (c.state === 'ready' ? 'good' : '') + '">' +
      esc(labels[c.state] || 'Check connection') + '</span></p></div><div class="actions">' +
      '<button class="secondary" id="vp3Check" type="button">Check connection</button>' +
      '<button class="secondary" id="vp3Replace" type="button">Replace connection</button>' +
      '<button class="secondary" id="vp3Disconnect" type="button">Disconnect</button></div></div>' +
      '<dl class="vp3-connection-details"><div><dt>VP3</dt><dd>' +
      (href ? '<a href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">Open VP3 Music Workspace</a><small>' + esc(base) + '</small>' : esc(c.provider_url)) +
      '</dd></div><div><dt>Artist site</dt><dd>' + esc(c.origin || '') + '</dd></div>' +
      '<div><dt>Credential</dt><dd>' + (c.credential_saved ? 'Saved privately' : 'Not configured') + '</dd></div></dl>' +
      (c.state === 'pending_verification' ? '<p class="notice">The verification response is ready on this site. Choose <strong>Verify site</strong> in VP3, then use <strong>Check connection</strong> here.</p>' : '') +
      '<p class="hint">Disconnecting removes this installation’s saved connection. Revoke the site in VP3 to stop existing provider grants.</p></section>' + connectionForm();
  }
  function recoveryPanel() {
    if (!state.pending) return '';
    return '<section class="panel vp3-recovery" aria-labelledby="vp3RecoveryTitle"><h2 id="vp3RecoveryTitle">Finish the saved import</h2>' +
      '<p>An import has a saved result or unfinished publication. Resume it to keep the same request and selection.</p>' +
      '<p class="hint">If the local catalog changed, discard the pending import and review a fresh selection. The VP3 selection may already have been saved; discarding does not undo that remote change.</p>' +
      '<div class="actions"><button class="primary" id="vp3Resume" type="button">Resume saved import</button>' +
      '<button class="secondary" id="vp3Discard" type="button">Discard pending import</button></div></section>';
  }
  function trackCard(item) {
    const id = String(item.recording_id || '');
    const selected = state.selected.has(id);
    const cover = safeHref(item.artwork?.url, true);
    const unavailable = !isReady(item);
    return '<label class="vp3-track ' + (unavailable ? 'is-unavailable' : '') + '">' +
      '<input type="checkbox" data-vp3-recording="' + esc(id) + '" ' + (selected ? 'checked ' : '') +
      (unavailable && !selected ? 'disabled ' : '') + 'aria-label="' + esc(title(item)) + '">' +
      (cover ? '<img src="' + esc(cover) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '<span class="vp3-art-placeholder" aria-hidden="true">♫</span>') +
      '<span class="vp3-track-text"><strong>' + esc(title(item)) + '</strong>' +
      '<span>' + esc([artist(item), album(item)].filter(Boolean).join(' · ')) + '</span>' +
      (item.metadata?.isrc ? '<small>ISRC ' + esc(item.metadata.isrc) + '</small>' : '') +
      (unavailable ? '<small class="vp3-unavailable">' + (item.availability_reason === 'audio_requires_repair' ? 'Repair the audio file in VP3 before importing.' : 'Unavailable for streaming. Remove it from this selection.') + '</small>' : '') +
      '</span></label>';
  }
  function catalogPanel() {
    if (!state.connection.configured || state.showConnection) return '';
    if (!state.loaded) return '<section class="panel"><h2>Select your music</h2><p>Check the connection to browse your published public songs.</p></section>';
    const unknown = selectedIds().filter(id => !state.items.some(x => x.recording_id === id));
    const groups = new Map();
    for (const item of state.items) {
      const key = item.album_id == null ? '__singles' : String(item.album_id);
      if (!groups.has(key)) groups.set(key, { title: album(item), items: [] });
      groups.get(key).items.push(item);
    }
    const canSelectAlbums = state.nextOffset === null && !state.query;
    let groupsHtml = '';
    for (const [key, group] of groups) {
      const candidates = group.items.filter(isReady);
      groupsHtml += '<section class="vp3-album"><div class="vp3-album-heading"><h3>' + esc(group.title) + '</h3>' +
        (candidates.length && canSelectAlbums ? '<button class="secondary" type="button" data-vp3-album="' + esc(key) + '">Select album</button>' : '') +
        '</div><div class="vp3-track-list">' + group.items.map(trackCard).join('') + '</div></section>';
    }
    return '<section class="panel vp3-catalog"><div class="panel-title"><div><h2>Choose music for this site</h2>' +
      '<p>Import published public songs as VP3-hosted streams. Local catalog edits stay in Stoneward.</p></div>' +
      '<button class="secondary" id="vp3Reload" type="button">Reload catalog</button></div>' +
      '<form id="vp3SearchForm" class="vp3-search"><label class="field">Search song titles<input name="search" type="search" maxlength="100" value="' + esc(state.query) +
      '" placeholder="Find a song"></label><button class="secondary" type="submit">Search</button></form>' +
      '<div class="vp3-selection-summary"><strong id="vp3SelectionCount">' + state.selected.size + ' selected</strong>' +
      '<span>Up to 500 songs per site</span><button class="secondary" id="vp3ClearSelection" type="button">Clear selection</button></div>' +
      (groupsHtml || '<p class="empty">No matching public songs. Publish songs in VP3 or try another search.</p>') +
      (state.nextOffset !== null ? '<div class="actions"><button class="secondary" id="vp3LoadMore" type="button">Load more songs</button></div>' : '') +
      (unknown.length ? '<details class="vp3-other-selected"><summary>' + unknown.length + ' selected recording' + (unknown.length === 1 ? '' : 's') +
      ' outside this view</summary><p>These remain selected unless you remove them. Load more songs or clear the search to find available titles.</p>' +
      '<ul>' + unknown.map(id => '<li><span>' + esc(id) + '</span><button type="button" class="secondary" data-vp3-remove="' + esc(id) + '">Remove</button></li>').join('') + '</ul></details>' : '') +
      '<div class="vp3-import-footer"><p>Audio stays in VP3. Streaming imports do not enable paid downloads or manufacturing.</p>' +
      '<button class="primary" id="vp3Review" type="button" ' + (state.pending || state.retry || state.needsReload ? 'disabled ' : '') + '>Review selection</button></div></section>' +
      (state.review ? reviewPanel() : '');
  }
  function reviewPanel() {
    const ids = selectedIds();
    const removed = Array.from(state.savedSelection).filter(id => !state.selected.has(id)).length;
    return '<section class="panel vp3-review" aria-labelledby="vp3ReviewTitle"><h2 id="vp3ReviewTitle" tabindex="-1">Review this import</h2>' +
      '<p><strong>' + ids.length + ' song' + (ids.length === 1 ? '' : 's') + '</strong> will be selected for this artist site. ' +
      (removed ? '<strong>' + removed + '</strong> previously selected recording' + (removed === 1 ? '' : 's') + ' will be removed from the site’s VP3 selection. ' : '') +
      'Previously imported tracks keep their Stoneward identities and local editorial changes.</p>' +
      '<p>Saving a changed selection stops existing provider playback sessions for this site. New playback uses the saved selection.</p>' +
      '<div class="actions"><button class="primary" id="vp3Import" type="button">Import selected songs</button>' +
      '<button class="secondary" id="vp3CancelReview" type="button">Keep choosing</button></div></section>';
  }
  function paint() {
    if (ctx.app.view !== 'vp3-music') return;
    canvas.innerHTML = head('CONNECTED MUSIC', 'Import Music from VP3',
      'Choose your catalog here. VP3 hosts the audio and receives playback reports.',
      '<button class="secondary" id="vp3BackCatalog" type="button">Back to Catalog</button>') +
      '<div class="vp3-music" aria-busy="' + state.busy + '">' + messages() + connectionPanel() + recoveryPanel() + catalogPanel() + '</div>';
    $('#vp3BackCatalog').onclick = () => ctx.openView('catalog');
    const bind = (selector, handler) => { const el = $(selector); if (el) el.onclick = handler; };
    bind('#vp3Check', reloadCatalog);
    bind('#vp3Reload', reloadCatalog);
    bind('#vp3Replace', () => { state.showConnection = true; state.review = false; paint(); });
    bind('#vp3CancelConnection', () => { state.showConnection = false; paint(); });
    bind('#vp3Disconnect', disconnect);
    bind('#vp3Retry', () => { if (state.retry) void mutate(state.retry.body, state.retry.success); });
    bind('#vp3Resume', () => mutate({ action: 'resume', request_id: state.pending.request_id, expected_revision: state.connection.revision }, 'import'));
    bind('#vp3Discard', discard);
    bind('#vp3LoadMore', () => loadCatalog(state.nextOffset, false));
    bind('#vp3ClearSelection', () => { state.selected.clear(); state.review = false; paint(); });
    bind('#vp3Review', () => {
      state.review = true; state.error = ''; paint();
      $('#vp3ReviewTitle')?.focus({ preventScroll: true });
      $('#vp3ReviewTitle')?.scrollIntoView({ block: 'center', behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    });
    bind('#vp3CancelReview', () => { state.review = false; paint(); $('#vp3Review')?.focus(); });
    bind('#vp3Import', () => mutate({
      action: 'import', request_id: uuid(), expected_revision: state.connection.revision,
      expected_selection_revision: state.selectionRevision, catalog_hash: state.catalogHash, recording_ids: selectedIds()
    }, 'import'));
    const form = $('#vp3ConnectForm');
    if (form) form.onsubmit = connect;
    const searchForm = $('#vp3SearchForm');
    if (searchForm) searchForm.onsubmit = (event) => {
      event.preventDefault(); state.query = String(new FormData(searchForm).get('search') || '').trim(); void loadCatalog(0, false);
    };
    canvas.querySelectorAll('[data-vp3-recording]').forEach(input => {
      input.onchange = () => {
        const id = input.dataset.vp3Recording;
        if (input.checked && !state.selected.has(id) && state.selected.size >= 500) {
          input.checked = false; state.error = 'Choose no more than 500 songs for this site.'; paint(); return;
        }
        if (input.checked) state.selected.add(id); else state.selected.delete(id);
        state.review = false; $('#vp3SelectionCount').textContent = state.selected.size + ' selected';
        canvas.querySelector('.vp3-review')?.remove();
      };
    });
    canvas.querySelectorAll('[data-vp3-remove]').forEach(button => {
      button.onclick = () => { state.selected.delete(button.dataset.vp3Remove); state.review = false; paint(); };
    });
    canvas.querySelectorAll('[data-vp3-album]').forEach(button => {
      button.onclick = () => {
        const items = state.items.filter(x => (x.album_id == null ? '__singles' : String(x.album_id)) === button.dataset.vp3Album && isReady(x));
        const next = new Set([...state.selected, ...items.map(x => x.recording_id)]);
        if (next.size > 500) { state.error = 'This album would exceed the 500-song selection limit.'; paint(); return; }
        state.selected = next; state.review = false; paint();
      };
    });
    if (state.busy) canvas.querySelectorAll('.vp3-music button,.vp3-music input,.vp3-music textarea').forEach(el => { el.disabled = true; });
    else if (state.retry) canvas.querySelectorAll('.vp3-music button,.vp3-music input,.vp3-music textarea').forEach(el => {
      if (el.id !== 'vp3Retry') el.disabled = true;
    });
    else if (state.pending) canvas.querySelectorAll('#vp3Check,#vp3Replace,#vp3Disconnect,#vp3Reload,#vp3LoadMore,#vp3SearchForm button,#vp3SearchForm input').forEach(el => { el.disabled = true; });
  }
  async function confirm(options) {
    if (window.SFAdminConfirm?.ask) return window.SFAdminConfirm.ask(options);
    return window.confirm(options.title + '\n\n' + options.description);
  }
  async function reloadCatalog() {
    if (JSON.stringify(selectedIds()) !== JSON.stringify(Array.from(state.savedSelection).sort())) {
      if (!await confirm({ title: 'Reload the VP3 selection?', description: 'Unsaved song selections will be replaced with the saved VP3 selection.', actionLabel: 'Reload catalog' })) return;
    }
    state.query = ''; await loadCatalog(0, true);
  }
  async function loadCatalog(offset = 0, resetSelection = false) {
    if (state.busy || state.pending || state.retry) return;
    const epoch = state.epoch;
    const requestQuery = state.query;
    state.busy = true; state.error = ''; state.review = false; paint();
    try {
      const result = await api('vp3-music.php', { action: 'catalog', offset, search: requestQuery });
      if (!active(epoch)) return;
      if (state.loaded && !resetSelection && (result.selection_revision !== state.selectionRevision ||
          result.connection?.revision !== state.connection.revision ||
          result.connection?.installation_id !== state.connection.installation_id || result.connection?.site_id !== state.connection.site_id)) {
        state.needsReload = true;
        throw new Error('The VP3 selection changed. Reload the catalog before importing.');
      }
      updateStatus(result);
      state.query = requestQuery;
      if (!state.loaded || resetSelection) {
        state.selected = new Set(result.recording_ids || []);
        state.savedSelection = new Set(result.recording_ids || []);
      }
      const combined = offset === 0 ? [] : state.items;
      state.items = Array.from(new Map([...combined, ...(result.items || [])].map(x => [x.recording_id, x])).values());
      state.selectionRevision = result.selection_revision;
      state.nextOffset = result.next_offset == null ? null : result.next_offset;
      state.loaded = true; state.needsReload = false; state.notice = '';
    } catch (error) {
      if (active(epoch)) {
        state.error = error.message || 'The VP3 catalog could not be loaded.';
        if (state.loaded) state.needsReload = true;
        if ([401, 403, 409].includes(error.status)) {
          try { const fresh = await api('vp3-music.php?action=status'); if (active(epoch)) updateStatus(fresh); } catch (_) {}
        }
      }
    } finally { if (active(epoch)) { state.busy = false; paint(); } }
  }
  async function connect(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    let verification;
    try { verification = JSON.parse(String(data.get('verification') || '')); }
    catch (_) { showFormError('Paste the complete site verification JSON supplied by VP3.'); return; }
    if (!verification || typeof verification !== 'object' || Array.isArray(verification)) {
      showFormError('The site verification response must be a JSON object.'); return;
    }
    const body = { action: 'connect', request_id: uuid(), expected_revision: state.connection.revision,
      provider_url: String(data.get('provider_url') || '').trim(), credential: String(data.get('credential') || '').trim(), verification };
    form.reset();
    await mutate(body, 'connect');
  }
  function showFormError(message) {
    state.error = message;
    const status = $('#vp3MusicStatus');
    if (status) { status.textContent = message; status.setAttribute('role', 'alert'); }
    $('#vp3ConnectForm [name="verification"]')?.focus();
  }
  async function disconnect() {
    if (!await confirm({ title: 'Disconnect this VP3 connection?',
      description: 'New VP3 playback from this installation will stop. Imported track identities remain. Revoke the site in VP3 to stop existing provider grants.',
      actionLabel: 'Disconnect', danger: true })) return;
    await mutate({ action: 'disconnect', request_id: uuid(), expected_revision: state.connection.revision }, 'disconnect');
  }
  async function discard() {
    if (!state.pending || !await confirm({ title: 'Discard the pending import?',
      description: 'This abandons the pending local publication. The VP3 selection may already have changed. Review a fresh selection afterward.',
      actionLabel: 'Discard pending import', danger: true })) return;
    await mutate({ action: 'discard', request_id: state.pending.request_id, expected_revision: state.connection.revision, confirmed: true }, 'discard');
  }
  async function mutate(body, success) {
    if (state.busy) return;
    const epoch = state.epoch;
    const frozen = JSON.parse(JSON.stringify(body));
    state.busy = true; state.error = ''; state.notice = ''; state.review = false;
    state.retry = { body: frozen, success }; paint();
    try {
      const result = await api('vp3-music.php', frozen);
      if (!active(epoch)) return;
      state.retry = null;
      updateStatus(result);
      state.showConnection = false;
      if (result.status === 'complete') state.notice = 'Import complete: ' + Number(result.imported_count || 0) + ' added, ' + Number(result.updated_count || 0) +
        ' refreshed, ' + Number(result.unavailable_count || 0) + ' unavailable. Open Catalog to review your songs.';
      else if (result.status === 'discarded') state.notice = 'Pending local import discarded. The VP3 selection may already have changed. Check the connection and review its current selection.';
      else if (success === 'connect') state.notice = 'Connection saved. Verify this site in VP3, then check the connection here.';
      else if (success === 'disconnect') state.notice = 'This installation is disconnected. Imported track identities are preserved.';
      else state.notice = 'The saved operation has been confirmed. Check the connection to review the current selection.';
      state.loaded = false; state.items = []; state.pending = null;
      try {
        const fresh = await api('vp3-music.php?action=status');
        if (active(epoch)) updateStatus(fresh);
      } catch (_) { /* Acknowledged write stays acknowledged; status can be reloaded. */ }
      if (result.status === 'complete') {
        try { const catalog = await api('catalog.php'); if (active(epoch)) ctx.app.catalog = catalog.catalog || []; } catch (_) {}
      }
    } catch (error) {
      if (!active(epoch)) return;
      state.error = error.message || 'The response could not be confirmed. Retry the same request.';
      if (['catalog_conflict', 'connection_conflict', 'provider_conflict'].includes(error.payload?.code)) state.needsReload = true;
      if (error.payload?.pending) { state.pending = error.payload.pending; state.retry = null; }
      else if (error.payload?.protocol === 'stoneward_vp3_music_v1' && error.payload?.retryable === false) state.retry = null;
      else if (error.status && error.status < 500 && !error.payload?.retryable) state.retry = null;
    } finally {
      if (active(epoch)) { state.busy = false; paint(); }
      if (frozen.credential && (!active(epoch) || !state.retry)) frozen.credential = '';
    }
  }
  async function render() {
    const epoch = ++state.epoch;
    state.busy = true; state.error = ''; state.review = false; state.showConnection = false;
    paint();
    try {
      const result = await api('vp3-music.php?action=status');
      if (!active(epoch)) return;
      updateStatus(result);
    } catch (error) { if (active(epoch)) state.error = error.message || 'Connection status could not be loaded.'; }
    finally { if (active(epoch)) { state.busy = false; paint(); } }
  }
  function leave() {
    state.epoch++; state.busy = false; state.review = false;
    if (state.retry?.body?.credential) { state.retry.body.credential = ''; state.retry = null; }
  }
  window.SFVP3MusicAdmin = Object.freeze({ render, leave });
})();
