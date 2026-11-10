'use strict';

(() => {
  const $ = id => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const id = params.get('id') || '';
  const host = params.get('host') === '1';
  const csrf = document.querySelector('meta[name=sf-csrf]').content;
  const audio = $('audio');
  const playbackClock = window.StonefellowJukeboxClock.create();
  const sessionTabList = $('session-tabs');
  const sessionTabButtons = [...sessionTabList.querySelectorAll('[data-session-tab]')];
  const overviewDetails = $('session-overview-details');
  const mobileSession = window.matchMedia('(max-width: 42rem)');
  const detailHomes = id ? [
    $('session-connection'),
    document.querySelector('[data-pwa-session-follow]'),
    document.querySelector('[data-pwa-phone-alerts]'),
    document.querySelector('[data-pwa-install]')
  ].filter(Boolean).map(node => {
    const home = document.createComment('session detail');
    node.before(home);
    return {node, home};
  }) : [];
  const endedTools = $('ended-session-tools');
  const sessionFollowUp = $(host ? 'crm' : 'pairing');
  const sessionFollowUpParent = sessionFollowUp.parentElement;
  const nonce = (length = 16) => [...crypto.getRandomValues(new Uint8Array(length))]
    .map(value => value.toString(16).padStart(2, '0')).join('');
  const player = host ? nonce(32) : '';
  let active = false;
  let currentItem = '';
  let hostTransport = 'starting';
  let hostPlayAttempt = null;
  let playerSequence = 0;
  let playerReports = Promise.resolve();
  let hostProgressTask = null;
  let hostProgressWanted = false;
  let hostConfirmation = null;
  let playerActionPending = false;
  let remoteChangePending = false;
  let disconnectAllPending = false;
  let listener = null;
  let phoneStreaming = false;
  let stateFetchError = '';
  let sessionRefreshEpoch = 0;
  let connectionIssue = '';
  let connectionRefreshing = false;
  let networkOffline = navigator.onLine === false;
  let listenerView = null;
  let state = null;
  let catalog = null;
  let catalogPending = false;
  let activeSessionTab = 'overview';
  let thread = '';
  let threadRequest = null;
  let renderedThreadVersion = null;
  let chatUnread = 0;
  const chatReadKey = 'stonefellow:jukebox-chat:' + id + ':' + (host ? 'host' : 'guest');
  let chatReads = {};
  try {
    const stored = JSON.parse(sessionStorage.getItem(chatReadKey));
    if (stored && typeof stored === 'object' && !Array.isArray(stored)) chatReads = stored;
  } catch {}
  let tab = 'feed';
  let album = '';
  let lastPlaying = false;
  let stopped = false;
  let tickTask = null;
  let queuedTick = null;
  let directoryPending = false;
  let directoryScope = 'active';
  let directoryRows = [];
  let directoryCursor = null;
  let directoryGeneration = 0;
  let editingSession = '';
  let editSnapshot = null;
  let managementLoad = 0;
  let crmRequest = null;
  let requestButtons = [];
  const renderKeys = new Map();
  let pageBranding = {logo_url: null, revision: -1};
  let coverRevision = -1;
  let brandingOwnerAvailable = true;

  function endpoint(action, extra = {}) {
    return 'api/jukebox.php?' + new URLSearchParams({action, id, ...(host ? {host: '1'} : {}), ...extra});
  }
  function page(session, isHost) {
    return 'jukebox.php?' + new URLSearchParams({id: session, ...(isHost ? {host: '1'} : {})});
  }
  async function api(action, body, extra) {
    const startedAt = performance.now();
    const options = body === undefined ? {} : {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify(body)
    };
    if (action === 'listener' && body?.active === false) options.keepalive = true;
    const controller = ['state', 'player', 'thread', 'crm', 'venue_accounts', 'directory'].includes(action)
      || (['settings', 'branding', 'cover'].includes(action) && body === undefined) ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), 5000) : null;
    if (controller) options.signal = controller.signal;
    try {
      const response = await fetch(endpoint(action, extra), options);
      const data = await response.json();
      if (!response.ok || !data.ok) {
        const error = new Error(data.message || 'Jukebox request failed.');
        error.status = response.status;
        throw error;
      }
      if (action === 'state' || action === 'player') {
        playbackClock.observe(Number(data.server_time_ms ?? data.state?.transport?.server_time_ms), startedAt, performance.now());
      }
      return data;
    } catch (error) {
      if (controller?.signal.aborted) throw new Error('The session connection timed out.');
      throw error;
    } finally {
      if (timer !== null) clearTimeout(timer);
    }
  }

  function logoUrl(value) {
    if (!value || typeof value !== 'string') return null;
    try {
      const url = new URL(value, location.href);
      if (url.origin !== location.origin || url.pathname !== new URL('api/jukebox.php', location.href).pathname
          || url.searchParams.get('action') !== 'host_logo'
          || !/^[1-9][0-9]*$/.test(url.searchParams.get('owner') || '')
          || !/^[a-f0-9]{64}$/.test(url.searchParams.get('v') || '')) return null;
      return url.href;
    } catch { return null; }
  }

  function coverUrl(value) {
    if (!value || typeof value !== 'string') return null;
    try {
      const url = new URL(value, location.href);
      if (url.origin !== location.origin || url.pathname !== new URL('api/jukebox.php', location.href).pathname
          || url.hash || url.username || url.password || [...url.searchParams].length !== 3
          || url.searchParams.get('action') !== 'host_cover' || url.searchParams.get('host') !== '1'
          || !/^[a-f0-9]{64}$/.test(url.searchParams.get('v') || '')) return null;
      return url.href;
    } catch { return null; }
  }

  function paintArtwork(image, fallback, source) {
    const next = source || '';
    if (image.dataset.artworkSource === next) return;
    image.dataset.artworkSource = next;
    image.hidden = true;
    if (fallback) fallback.hidden = false;
    if (!next) { image.removeAttribute('src'); return; }
    image.onload = () => {
      if (image.dataset.artworkSource !== next) return;
      image.hidden = false;
      if (fallback) fallback.hidden = true;
    };
    image.onerror = () => {
      if (image.dataset.artworkSource !== next) return;
      image.hidden = true;
      if (fallback) fallback.hidden = false;
    };
    image.src = next;
  }

  function applyPageBranding(value) {
    if (!value || !Number.isSafeInteger(value.revision)) return;
    if (value.owner_available === false) brandingOwnerAvailable = false;
    if (!brandingOwnerAvailable) pageBranding = {logo_url: null, revision: 0};
    else {
      if (value.revision < pageBranding.revision) return;
      pageBranding = {logo_url: logoUrl(value.logo_url), revision: value.revision};
    }
    paintArtwork($('host-logo'), $('host-logo-fallback'), pageBranding.logo_url);
    if (host && id) {
      paintArtwork($('branding-current'), $('branding-current-fallback'), pageBranding.logo_url);
      connectionText('open-branding', pageBranding.logo_url ? 'Change logo' : 'Upload logo');
    } else if (host) {
      const opener = $('open-branding');
      const label = pageBranding.logo_url ? 'Edit profile picture' : 'Upload profile picture';
      opener.setAttribute('aria-label', label);
      opener.title = label;
    }
  }

  function applyHostCover(value) {
    const image = $('host-cover');
    if (!image || !value || !Number.isSafeInteger(value.revision) || value.revision < coverRevision) return;
    const source = coverUrl(value.cover_url);
    coverRevision = value.revision;
    paintArtwork(image, null, source);
    connectionText('cover-edit-label', source ? 'Edit cover image' : 'Add cover image');
  }

  function brandingEditable(editable) {
    if (!host) return;
    $(id ? 'host-branding' : 'open-branding').hidden = !editable || !brandingOwnerAvailable;
    const cover = $('open-cover');
    if (cover) cover.hidden = !editable;
  }

  function bindBrandingDialog() {
    if (!host) return;
    if (id) $('branding-settings-slot').append($('host-branding'));
    const dialog = $('branding-dialog');
    const form = $('branding');
    const file = $('branding-file');
    const close = [...dialog.querySelectorAll('[data-branding-close]')];
    const logo = {
      opener: $('open-branding'), action: 'branding', label: 'logo', name: 'host logo',
      heading: 'Host logo / icon', choice: 'Choose a logo or icon',
      description: 'This is a public logo, visible on your host page and every session you host.',
      help: 'Square artwork works well and fits without cropping.',
      url: value => logoUrl(value?.logo_url)
    };
    const cover = {
      opener: $('open-cover'), action: 'cover', label: 'cover image', name: 'cover image',
      heading: 'Cover image', choice: 'Choose a cover image',
      description: 'This image appears on your private hosted events page.',
      help: 'Wide landscape artwork works best. The edges may be cropped to fit.',
      url: value => coverUrl(value?.cover_url)
    };
    let asset = logo;
    let generation = 0, reading = 0, saving = false, snapshot = null, selected;
    let editable = false;
    const report = text => connectionText('branding-status', text);
    const preview = source => paintArtwork($('branding-preview'), $('branding-preview-fallback'), source);
    const controls = () => {
      file.disabled = saving || !snapshot || !editable;
      $('branding-save').disabled = saving || !editable || typeof selected !== 'string';
      $('branding-remove').disabled = saving || !editable || !asset.url(snapshot);
      $('branding-reload').disabled = saving;
      close.forEach(control => { control.disabled = saving; });
    };
    const resetSelection = () => {
      reading++;
      selected = undefined;
      form.reset();
      preview(asset.url(snapshot));
    };
    async function load() {
      const request = ++generation;
      snapshot = null;
      editable = false;
      resetSelection();
      report('Loading ' + asset.name + '…');
      $('branding-reload').hidden = true;
      controls();
      try {
        const data = await api(asset.action);
        if (!dialog.open || generation !== request) return;
        snapshot = data[asset.action];
        editable = data.branding_editable === true;
        if (asset === cover) applyHostCover(snapshot);
        resetSelection();
        report(editable ? '' : 'This account cannot change the ' + asset.name + '.');
        controls();
        if (editable && [dialog, document.body, $('branding-status'), $('branding-reload'), file].includes(document.activeElement)) file.focus({preventScroll: true});
      } catch (error) {
        if (!dialog.open || generation !== request) return;
        report(error.message);
        $('branding-reload').hidden = false;
        controls();
      }
    }
    function open(choice) {
      asset = choice;
      dialog.dataset.asset = asset.action;
      connectionText('branding-heading', asset.heading);
      connectionText('branding-description', asset.description);
      connectionText('branding-file-label', asset.choice);
      connectionText('branding-file-help', 'PNG, JPEG, or WebP. Maximum 1 MB. ' + asset.help);
      connectionText('branding-save', 'Save ' + asset.label);
      connectionText('branding-remove', 'Remove ' + asset.label);
      connectionText('branding-reload', 'Load latest ' + asset.label);
      close[0].setAttribute('aria-label', 'Close ' + asset.name);
      $('branding-preview').alt = asset === cover ? 'Cover image preview' : 'Host logo preview';
      dialog.showModal();
      $('branding-status').focus({preventScroll: true});
      void load();
    }
    logo.opener.onclick = () => open(logo);
    if (cover.opener) cover.opener.onclick = () => open(cover);
    close.forEach(control => { control.onclick = () => dialog.close(); });
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    dialog.addEventListener('close', () => {
      generation++;
      resetSelection();
      snapshot = null;
      report('');
      if (asset.opener.isConnected && !asset.opener.closest('[hidden]')) asset.opener.focus({preventScroll: true});
    });
    file.onchange = async () => {
      const request = ++reading;
      const context = generation;
      selected = undefined;
      const choice = file.files[0];
      preview(asset.url(snapshot));
      controls();
      if (!choice) { report(''); return; }
      if (!['image/png', 'image/jpeg', 'image/webp'].includes(choice.type) || choice.size < 1 || choice.size > 1048576) {
        report('Choose a PNG, JPEG, or WebP image up to 1 MB.');
        return;
      }
      report('Reading image…');
      try {
        const data = await new Promise((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(new Error('The image could not be read. Choose it again.'));
          reader.readAsDataURL(choice);
        });
        if (!dialog.open || request !== reading || context !== generation) return;
        await new Promise((resolve, reject) => {
          const image = new Image();
          image.onload = () => image.naturalWidth * image.naturalHeight <= 20000000 ? resolve()
            : reject(new Error('Choose an image smaller than 20 megapixels.'));
          image.onerror = () => reject(new Error('This image could not be opened. Choose a PNG, JPEG, or WebP image.'));
          image.src = data;
        });
        if (!dialog.open || request !== reading || context !== generation) return;
        selected = data;
        preview(data);
        report('Ready to upload ' + choice.name + (asset === cover
          ? '. Save to add it to your hosted events page.' : '. Save to publish it on your sessions.'));
        controls();
      } catch (error) {
        if (!dialog.open || request !== reading || context !== generation) return;
        report(error.message);
        controls();
      }
    };
    async function save(image) {
      if (saving || !snapshot || !editable) return;
      saving = true;
      controls();
      report((image === null ? 'Removing ' : 'Uploading ') + asset.label + '…');
      try {
        const data = await api(asset.action, {expected_revision: snapshot.revision, image});
        if (asset === cover) {
          applyHostCover(data.cover);
        } else {
          applyPageBranding(data.branding);
          if (state?.branding_editable) state.branding = data.branding;
          if (!id) {
            directoryRows = directoryRows.map(row => ({...row, branding: data.branding}));
            renderSessionList('sessions', directoryRows);
          }
        }
        dialog.close();
        const name = asset === cover ? 'Cover image' : 'Host logo';
        notice(name + (image === null ? ' removed.' : ' updated.'));
      } catch (error) {
        report(error.message);
        if (error.status === 409) {
          editable = false;
          $('branding-reload').hidden = false;
        }
      } finally {
        saving = false;
        controls();
        if (dialog.open && !$('branding-reload').hidden) $('branding-reload').focus();
      }
    }
    form.onsubmit = event => {
      event.preventDefault();
      if (typeof selected === 'string') void save(selected);
    };
    $('branding-remove').onclick = () => void save(null);
    $('branding-reload').onclick = () => void load();
  }

  const connectionLabels = {
    idle: 'Ready', connecting: 'Connecting', listening: 'Listening', paused: 'Paused',
    buffering: 'Buffering', reconnecting: 'Reconnecting', waiting: 'Waiting',
    'action-required': 'Tap to resume', error: 'Retry audio', unavailable: 'Unavailable'
  };

  function connectionText(id, text) {
    const target = $(id);
    if (target.textContent !== text) target.textContent = text;
  }

  function renderConnection() {
    if (!id) return;
    $('session-connection').hidden = false;
    let code = 'connecting';
    let label = 'Connecting';
    let detail = 'Checking the session connection…';
    let muted = false;
    const phase = state ? phaseOf(state) : '';
    if (connectionIssue === 'unavailable') {
      code = 'unavailable';
      label = 'Unavailable';
      detail = 'This session is unavailable or your access changed. Open the session link again to continue.';
    } else if (networkOffline) {
      code = 'offline';
      label = 'Offline';
      detail = 'Your device is offline. Reconnect to the internet; the session will refresh automatically.';
    } else if (connectionIssue || connectionRefreshing) {
      code = 'reconnecting';
      label = 'Reconnecting';
      detail = 'Refreshing the session connection. Phone audio waits for a fresh host update.';
      if (host) detail = 'Refreshing your connection to Stonefellow. The last player update may be out of date.';
    } else if (state) {
      code = 'connected';
      label = 'Connected';
      if (phase === 'ended' || phase === 'canceled') {
        label = phase === 'ended' ? 'Completed' : 'Canceled';
        detail = phase === 'ended' ? 'The session has ended.' : 'This session was canceled.';
      } else if (phase === 'upcoming') {
        code = 'waiting';
        label = 'Waiting';
        detail = host ? 'Session connected. Start when you’re ready; the QR link stays the same.'
          : 'Session connected. This page will update when the host starts.';
      } else if (host) {
        const transport = state.transport?.status;
        if (!state.online) {
          code = 'waiting';
          label = 'Player offline';
          detail = active ? 'Session connected. Waiting for this device to update playback.'
            : 'You’re connected to Stonefellow. Enable a session player to play music.';
        } else {
          label = transport === 'paused' || Number(state.paused) ? 'Player paused'
            : transport === 'buffering' ? 'Player buffering' : 'Player online';
          if (transport === 'buffering') code = 'buffering';
          detail = active ? 'Playback is hosted on this device.' : 'The session player is running on another device.';
          const count = state.listener_metrics?.active_players;
          if (state.remote_listening === true && count != null) {
            detail += ' ' + count + (Number(count) === 1 ? ' phone listening now.' : ' phones listening now.');
          }
        }
      } else if (state.remote_listening === true && listenerView?.allowed) {
        code = listenerView.connection;
        label = connectionLabels[code] || 'Connected';
        muted = listenerView.muted && code === 'listening';
        if (muted) label = 'Muted';
        detail = muted ? 'Phone audio is muted. Unmute when you’re ready.'
          : listenerView.status || 'Tap Stream on this phone to listen with the host.';
      } else if (state.phone?.status === 'pending') {
        code = 'waiting';
        label = 'Awaiting approval';
        detail = 'Session connected. Waiting for the host to approve this phone.';
      } else if (state.phone?.status === 'revoked') {
        code = 'unavailable';
        label = 'Access closed';
        detail = 'The host closed this phone connection. Phone audio has stopped.';
      } else {
        detail = 'Session connected. Phone streaming is not enabled for this connection.';
      }
    }
    const badge = $('connection-badge');
    badge.dataset.connection = code;
    badge.dataset.muted = String(muted);
    connectionText('connection-badge', label);
    connectionText('connection-detail', detail);
    const retry = $('connection-retry');
    retry.hidden = !connectionIssue || connectionIssue === 'unavailable';
    retry.disabled = connectionRefreshing || networkOffline;
    if (!host && listenerView) {
      $('listener-resync').disabled = !listenerView.canResync || connectionRefreshing || networkOffline;
      connectionText('listener-resync', connectionRefreshing && listenerView.listening ? 'Resyncing…' : 'Resync');
    }
  }

  function invalidateConnection() {
    applyPageBranding({owner_available: false, logo_url: null, revision: 0});
    brandingEditable(false);
    sessionRefreshEpoch++;
    connectionIssue = 'unavailable';
    connectionRefreshing = false;
    stopped = true;
    placeOverviewDetails();
    if (!host) $('guest-share').hidden = true;
    renderChat(false);
    listener?.update({allowed: false});
    if (active) releasePlayer();
    renderConnection();
  }

  function refreshConnection({resync = false} = {}) {
    if (stopped) return Promise.resolve();
    sessionRefreshEpoch++;
    networkOffline = navigator.onLine === false;
    connectionRefreshing = true;
    if (!resync) listener?.reconnect();
    renderConnection();
    return tick();
  }

  function createListenerReporter() {
    const storageKey = 'stonefellow:jukebox-listener:' + id;
    let playerId, sequence, pending;

    function send(item, generation, advancedMs, isActive) {
      if (!playerId) {
        let stored;
        try { stored = JSON.parse(sessionStorage.getItem(storageKey)); } catch {}
        playerId = /^[0-9a-f]{32}$/.test(stored?.player ?? '') ? stored.player : nonce();
        sequence = Number.isSafeInteger(stored?.seq) && stored.seq >= 0 ? stored.seq : 0;
      }
      const seq = ++sequence;
      try { sessionStorage.setItem(storageKey, JSON.stringify({player: playerId, seq})); } catch {}
      void api('listener', {player: playerId, seq, item, generation, advanced_ms: advancedMs, active: isActive}).catch(() => {});
    }

    return ({itemId, generation, advancedMs, active: isActive, baseline}) => {
      if (!/^[1-9]\d{0,18}$/.test(itemId) || !Number.isSafeInteger(generation) || generation < 0) return;
      const matches = pending?.itemId === itemId && pending?.generation === generation;
      if (!isActive && !baseline) {
        if (matches) {
          send(itemId, generation, pending.advancedMs, false);
          pending = null;
        }
        return;
      }
      const now = performance.now();
      if (baseline || !matches) {
        pending = {itemId, generation, advancedMs: 0, reportAt: now + 1000};
        send(itemId, generation, 0, false);
      }
      if (!isActive) return;
      pending.advancedMs += advancedMs;
      if (now < pending.reportAt) return;
      send(itemId, generation, pending.advancedMs, true);
      pending.advancedMs = 0;
      pending.reportAt = now + 4000;
    };
  }
  function notice(error) {
    connectionText('status', typeof error === 'string' ? error : error.message);
  }
  function focusSessionControl(control) {
    const target = control.closest('[hidden]')
      ? sessionTabButtons.find(tab => tab.dataset.sessionTab === activeSessionTab)
      : control;
    target?.focus({preventScroll: true});
  }
  function connectionAction(work) {
    const run = handle(work);
    return async event => {
      const control = event?.currentTarget;
      const focused = control === document.activeElement;
      await run(event);
      renderConnection();
      if (focused && control?.hidden) focusSessionControl($('connection-detail'));
    };
  }
  function playerNotice(error) {
    $('player-status').textContent = typeof error === 'string' ? error : error.message;
  }
  function handle(work, report = notice) {
    return async event => {
      event?.preventDefault();
      const control = event?.submitter || (event?.currentTarget instanceof HTMLButtonElement ? event.currentTarget : null);
      if (control) control.disabled = true;
      try {
        report('');
        await work(event);
      } catch (error) {
        report(error);
      } finally {
        if (control) control.disabled = false;
      }
    };
  }
  function handlePlayerAction(work) {
    return async () => {
      if (playerActionPending) return;
      playerActionPending = true;
      const controls = ['player-toggle', 'player-skip', 'enable', 'audio-start'].map($);
      controls.forEach(control => control.setAttribute('aria-disabled', 'true'));
      try {
        playerNotice('');
        await work();
      } catch (error) {
        playerNotice(error);
      } finally {
        playerActionPending = false;
        controls.forEach(control => control.removeAttribute('aria-disabled'));
      }
    };
  }
  function bindFormDialog(name, save, afterSave, onClose = () => {}) {
    const dialog = $(name + '-dialog');
    if (!dialog) return;
    const opener = $('open-' + name);
    const form = $(name);
    const dismiss = dialog.querySelectorAll('[data-jukebox-dialog-close]');
    let saving = false;
    let returnFocus = opener;
    const report = error => {
      $(name + '-status').textContent = typeof error === 'string' ? error : error.message;
    };
    const open = (control = opener) => {
      managementLoad++;
      returnFocus = control;
      form.reset();
      report('');
      if (!dialog.open) dialog.showModal();
    };
    if (opener) opener.onclick = () => open(opener);
    dismiss.forEach(control => { control.onclick = () => dialog.close(); });
    dialog.addEventListener('cancel', event => {
      if (saving) event.preventDefault();
    });
    dialog.addEventListener('close', () => {
      managementLoad++;
      form.reset();
      report('');
      onClose();
      if (returnFocus?.isConnected) returnFocus.focus({preventScroll: true});
    });
    form.onsubmit = handle(async () => {
      if (saving) return;
      saving = true;
      report('Saving…');
      dismiss.forEach(control => { control.disabled = true; });
      try {
        const result = await save(new FormData(form));
        dialog.close();
        await afterSave(result);
      } finally {
        saving = false;
        dismiss.forEach(control => { control.disabled = false; });
      }
    }, report);
    return {open, form, dialog, report};
  }
  function bindVenueAccountPicker() {
    const dialog = $('venue-dialog');
    if (!dialog) return;
    const owner = $('venue-owner');
    const lookup = $('venue-lookup');
    const input = $('venue-search');
    const searchButton = $('venue-search-button');
    const status = $('venue-search-status');
    const myAccount = owner.value;
    let generation = 0;

    function clearMatches() {
      owner.querySelectorAll('[data-venue-match]').forEach(option => option.remove());
      owner.value = '';
    }

    async function searchAccounts() {
      const query = input.value.trim();
      const request = ++generation;
      clearMatches();
      if (query.length < 2) {
        status.textContent = 'Enter at least two characters to search.';
        input.focus();
        return;
      }
      status.textContent = 'Searching for accounts…';
      try {
        const result = await api('venue_accounts', undefined, {q: query});
        if (!dialog.open || request !== generation) return;
        const accounts = result.accounts.filter(account => String(account.id) !== myAccount);
        for (const account of accounts) {
          const option = new Option([account.display_name, account.email].filter(Boolean).join(' · '), String(account.id));
          option.dataset.venueMatch = '';
          owner.add(option);
        }
        status.textContent = accounts.length ? 'Choose a matching account from the Account list.' : 'No other active accounts match that search.';
        if (accounts.length && [input, searchButton].includes(document.activeElement)) owner.focus();
      } catch (error) {
        if (dialog.open && request === generation) status.textContent = error.message || 'Account search failed. Try again.';
      }
    }

    input.addEventListener('input', () => {
      generation++;
      clearMatches();
      status.textContent = '';
    });
    input.addEventListener('keydown', event => {
      if (event.key !== 'Enter' || event.isComposing) return;
      event.preventDefault();
      void searchAccounts();
    });
    searchButton.onclick = () => void searchAccounts();
    dialog.addEventListener('close', () => {
      generation++;
      clearMatches();
      owner.value = myAccount;
      lookup.open = false;
      status.textContent = '';
    });
  }
  function button(label, work) {
    const control = document.createElement('button');
    control.type = 'button';
    control.textContent = label;
    control.onclick = handle(work);
    return control;
  }
  function link(label, url) {
    const item = document.createElement('a');
    item.textContent = label;
    item.href = url;
    return item;
  }
  function list(id, rows, build) {
    $(id).replaceChildren(...rows.map((row, index) => {
      const item = document.createElement('li');
      item.append(...build(row, index));
      return item;
    }));
  }
  function hostRow(label, ...buttons) {
    const row = document.createElement('div');
    row.className = 'jukebox-host-row';
    const text = document.createElement('span');
    text.className = 'jukebox-host-row-label';
    text.textContent = label;
    row.append(text);
    if (buttons.length) {
      const actions = document.createElement('div');
      actions.className = 'jukebox-host-row-actions';
      actions.append(...buttons);
      row.append(actions);
    }
    return [row];
  }
  function changed(key, value) {
    const encoded = JSON.stringify(value);
    if (renderKeys.get(key) === encoded) return false;
    renderKeys.set(key, encoded);
    return true;
  }
  const title = track => catalog?.tracks.find(row => row.track_id === track)?.title || track;
  const date = seconds => new Date(Number(seconds) * 1000).toLocaleString();
  const time = seconds => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
  const phaseOf = row => row.phase || (Number(row.ended) ? 'ended' : 'live');
  const canRequest = () => state && phaseOf(state) === 'live' && (host || state.phone?.status === 'approved');
  const scheduleDate = new Intl.DateTimeFormat(undefined, {
    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit', timeZoneName: 'short'
  });

  function localScheduleValue(seconds) {
    const instant = new Date(seconds * 1000);
    return new Date(instant.getTime() - instant.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }

  function scheduleTimestamp(value) {
    if (!value) return null;
    const instant = new Date(value);
    const seconds = instant.getTime() / 1000;
    if (!Number.isFinite(seconds) || localScheduleValue(seconds) !== value) {
      throw new Error('Choose a valid local date and time.');
    }
    const offset = instant.getTimezoneOffset();
    // During a fall-back hour, one local time maps to two possible instants.
    for (const day of [-86400, 86400]) {
      const otherOffset = new Date((seconds + day) * 1000).getTimezoneOffset();
      if (otherOffset !== offset && localScheduleValue(seconds + (otherOffset - offset) * 60) === value) {
        throw new Error('That local time occurs twice because the clocks change. Choose a different time.');
      }
    }
    if (seconds <= Date.now() / 1000) throw new Error('Choose a date and time in the future.');
    return seconds;
  }

  function setScheduleDate(element, seconds) {
    const instant = new Date(seconds * 1000);
    const timestamp = instant.toISOString();
    if (element.dateTime !== timestamp) {
      element.dateTime = timestamp;
      element.textContent = scheduleDate.format(instant);
    }
  }

  function catalogRow(label, metadata, action) {
    const row = document.createElement('div');
    row.className = 'jukebox-catalog-row-content';
    const copy = document.createElement('div');
    copy.className = 'jukebox-catalog-copy';
    const name = document.createElement('strong');
    name.className = 'jukebox-catalog-title';
    name.textContent = label;
    copy.append(name);
    if (metadata) {
      const detail = document.createElement('small');
      detail.className = 'jukebox-catalog-meta';
      detail.textContent = metadata;
      copy.append(detail);
    }
    row.append(copy);
    if (action) {
      const actions = document.createElement('div');
      actions.className = 'jukebox-catalog-actions';
      actions.append(action);
      row.append(actions);
    }
    return row;
  }

  function renderCatalog() {
    if (!catalog) return;
    requestButtons = [];
    $('tabs').querySelectorAll('button').forEach(control => control.setAttribute('aria-pressed', control.dataset.tab === tab));
    const context = $('catalog-context');
    context.replaceChildren();
    context.hidden = true;
    const releases = new Map(catalog.releases.map(release => [release.release_id, release]));
    if (tab === 'albums' && !album) {
      list('catalog', catalog.releases, release => {
        const count = release.track_ids.length;
        const open = button('View songs', () => {
          album = release.release_id;
          renderCatalog();
          $('catalog-back').focus({preventScroll: true});
        });
        open.setAttribute('aria-label', 'View songs in ' + release.title);
        const row = catalogRow(release.title, count + (count === 1 ? ' song' : ' songs'), open);
        row.dataset.releaseId = release.release_id;
        return [row];
      });
    } else {
      const release = releases.get(album);
      const tracks = tab === 'albums' ? (release?.track_ids || []).map(key =>
        catalog.tracks.find(track => track.track_id === key)).filter(Boolean) : [...catalog.tracks];
      if (tab === 'songs') tracks.sort((a, b) => a.title.localeCompare(b.title));
      list('catalog', tracks, track => {
        const details = (track.release_ids || []).map(key => releases.get(key)?.title).filter(Boolean);
        if (Number(track.duration_seconds) > 0) details.push(time(Number(track.duration_seconds)));
        let control;
        if (canRequest() && track.source_available) {
          control = button(state.current ? 'Add to Queue' : 'Play', async () => {
            const request = {track: track.track_id, nonce: nonce()};
            try { await api('request', request); }
            catch (error) { if (error.status) throw error; await api('request', request); }
            await tick();
          });
          requestButtons.push(control);
        } else if (!track.source_available) details.push('Audio unavailable');
        const row = catalogRow(track.title, details.join(' · '), control);
        row.dataset.trackId = track.track_id;
        return [row];
      });
      if (tab === 'albums') {
        context.hidden = false;
        const back = button('All albums', () => {
          const previousAlbum = album;
          album = '';
          renderCatalog();
          [...$('catalog').children].find(row => row.dataset.releaseId === previousAlbum)?.querySelector('button')?.focus({preventScroll: true});
        });
        back.id = 'catalog-back';
        context.append(back);
        const heading = document.createElement('h3');
        heading.textContent = release?.title || 'Album';
        context.append(heading);
      }
    }
    [...$('catalog').children].forEach(item => {
      item.className = 'jukebox-catalog-row';
      if (item.firstElementChild.dataset.trackId) item.dataset.trackId = item.firstElementChild.dataset.trackId;
      if (item.firstElementChild.dataset.releaseId) item.dataset.releaseId = item.firstElementChild.dataset.releaseId;
    });
    $('catalog-empty').hidden = $('catalog').children.length > 0;
  }

  async function mutate(action, body) {
    await api(action, body);
    await tick();
  }
  function renderHistory(id, resolveTitle) {
    list(id, state.history || [], row => [resolveTitle(row.track_id) + ' · ' + date(row.played) + (row.name ? ' · ' + row.name : '')]);
  }
  function renderHost() {
    if (!remoteChangePending) $('remote-listening').checked = state.remote_listening === true;
    const audience = $('remote-listener-count');
    const count = state.listener_metrics?.active_players;
    audience.hidden = state.remote_listening !== true || count == null;
    if (!audience.hidden && changed('listener-count', count)) {
      audience.textContent = count + (Number(count) === 1 ? ' phone listening now' : ' phones listening now');
    }
    const phones = state.phones || [];
    connectionText('phone-approval-mode', state.auto_approve_phones === true
      ? 'New phones are approved automatically.' : 'New phones need host approval.');
    $('disconnect-all-phones').disabled = stopped || disconnectAllPending
      || !phones.some(phone => phone.status === 'approved' || phone.status === 'pending');
    if (changed('phones', phones)) list('phones', [...phones].sort((a, b) =>
      Number(b.status === 'pending') - Number(a.status === 'pending')), phone => {
      const label = phone.name + ' · ' + phone.status + (phone.last_visit ? ' · Returning guest, last visit ' + date(phone.last_visit) : '');
      if (phone.status === 'pending') return hostRow(label,
        button('Approve', () => mutate('approve', {phone: phone.id})),
        button('Deny', () => mutate('revoke', {phone: phone.id})));
      if (phone.status === 'approved') return hostRow(label,
        button('Chat', () => openChatThread(phone.id)),
        button('Revoke', () => mutate('revoke', {phone: phone.id})));
      return hostRow(label);
    });
    if (changed('history', [state.history, Boolean(catalog)])) renderHistory('history', title);
    const controls = $('controls');
    const toggle = $('player-toggle');
    if (changed('controls', [state.paused, Boolean(state.current)])) {
      const focused = controls.contains(document.activeElement);
      const hasTrack = Boolean(state.current);
      controls.hidden = toggle.hidden = $('player-skip').hidden = !hasTrack;
      toggle.textContent = Number(state.paused) ? 'Resume' : 'Pause';
      if (!hasTrack && focused) $('volume').focus({preventScroll: true});
    }
    $('enable').hidden = active;
    $('enable').textContent = state.online ? 'Move playback to this device' : 'Enable player on this device';
  }

  const recapOutcomes = [
    ['completed', 'Completed'], ['skipped', 'Skipped'], ['failed', 'Failed'], ['removed', 'Removed'],
    ['left_in_queue', 'Left in queue'], ['current_at_close', 'Current at close']
  ];

  function renderMetrics(id, metrics) {
    const totals = $(id);
    totals.replaceChildren();
    for (const [label, value] of metrics) {
      const metric = document.createElement('div');
      const term = document.createElement('dt');
      const count = document.createElement('dd');
      term.textContent = label;
      count.textContent = value;
      metric.append(term, count);
      totals.append(metric);
    }
  }

  function listeningDuration(milliseconds) {
    if (milliseconds > 0 && milliseconds < 1000) return '<1 sec';
    const seconds = Math.floor(Number(milliseconds) / 1000);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor(seconds % 3600 / 60);
    return [hours ? hours + ' hr' : '', minutes ? minutes + ' min' : '', seconds % 60 + ' sec'].filter(Boolean).join(' ');
  }

  function renderRecap(recap) {
    $('recap-start').textContent = 'Session opened ' + date(recap.started_at ?? state.started_at ?? recap.created);
    renderMetrics('recap-totals', [
      ['Phone connections', recap.phones.connections], ['Approved phones', recap.phones.approved],
      ['Requests', recap.totals.requests], ['Guest requests', recap.totals.guest_requests],
      ['Host requests', recap.totals.host_requests], ['Confirmed plays', recap.totals.confirmed_plays],
      ...recapOutcomes.map(([key, label]) => [label, recap.totals[key]])
    ]);
    const listening = recap.listener_metrics;
    $('recap-listeners').hidden = !listening;
    if (listening) renderMetrics('recap-listener-totals', [
      ['Distinct phone players', listening.unique_players], ['Peak simultaneous players', listening.peak_players],
      ['Total phone listening time', listeningDuration(listening.listening_ms)]
    ]);
    const table = $('recap-tracks');
    const rows = table.tBodies[0];
    rows.replaceChildren();
    const trackTitles = new Map();
    for (const track of recap.tracks) {
      trackTitles.set(track.track_id, track.title);
      const row = rows.insertRow();
      const song = document.createElement('th');
      song.scope = 'row';
      song.textContent = track.title;
      row.append(song);
      row.insertCell().textContent = track.requests;
      row.insertCell().textContent = track.confirmed_plays;
      const outcomes = row.insertCell();
      for (const [key, label] of recapOutcomes) {
        if (!Number(track[key])) continue;
        const outcome = document.createElement('span');
        outcome.textContent = label + ' ' + track[key];
        outcomes.append(outcome);
      }
    }
    table.hidden = !recap.tracks.length;
    $('recap-empty').hidden = Boolean(recap.tracks.length);
    $('recap-history').hidden = !state.history?.length;
    renderHistory('recap-plays', trackId => trackTitles.get(trackId) || trackId);
    $('recap-content').hidden = false;
  }

  async function refreshRecap() {
    $('recap-retry').hidden = true;
    $('recap-status').textContent = 'Loading session recap…';
    try {
      const {recap} = await api('recap');
      renderRecap(recap);
      $('recap-status').textContent = '';
    } catch (error) {
      $('recap-status').textContent = 'Could not load the session recap.';
      $('recap-retry').hidden = false;
    }
  }

  function renderSchedule(phase) {
    const upcoming = phase === 'upcoming';
    const canceled = phase === 'canceled';
    $('upcoming').hidden = !upcoming && !canceled;
    if (host && changed('share-position', upcoming)) {
      $(upcoming ? 'upcoming-share' : 'active-share').append($('session-share'));
      $('session-share-description').hidden = !upcoming;
      $('join-link').textContent = upcoming ? 'Open guest session page' : 'Open guest join page';
      $('qr').querySelector('svg')?.setAttribute('aria-label', upcoming ? 'Scan to open the session waiting page' : 'Scan to pair your phone');
    }
    if ((!upcoming && !canceled) || !changed('schedule', [phase, state.scheduled_at, state.listed])) return;
    $('upcoming-heading').textContent = canceled ? 'Session canceled' : host ? 'Upcoming session' : 'Waiting for the session to start';
    $('upcoming-message').textContent = canceled ? 'The host canceled this session.' : host
      ? 'Guests who open the link or scan the code will wait here until you start the session.'
      : 'This page will open the jukebox when the host starts the session.';
    $('scheduled-start-line').hidden = !upcoming;
    if (upcoming) setScheduleDate($('scheduled-start'), state.scheduled_at);
    if (host) {
      $('schedule-controls').hidden = !upcoming;
      if (upcoming) {
        $('session-share-description').textContent = state.listed
          ? 'Share this public session with your guests.' : 'Private session. Share this link with your guests.';
        $('reschedule').elements.scheduled_at.value = localScheduleValue(state.scheduled_at);
      }
    }
  }

  function initializeSessionShare() {
    const join = new URL(page(id, false), location.href);
    const link = $('join-link');
    if (link) link.href = join.href;
    const qr = $('qr');
    qr.innerHTML = window.StonefellowJukeboxQR.svg(join.href);
    if (!host) qr.firstElementChild.setAttribute('aria-label', 'Scan to open this session on another device');
    $('session-link-value').value = join.href;
    $('copy-session-link').onclick = handle(async () => {
      const status = $('copy-session-status');
      try {
        await navigator.clipboard.writeText(join.href);
        $('manual-session-copy').hidden = true;
        status.textContent = 'Session link copied.';
      } catch {
        $('manual-session-copy').hidden = false;
        const field = $('session-link-value');
        field.focus();
        field.select();
        status.textContent = 'Copy the selected session link.';
      }
    });
  }

  function placeOverviewDetails() {
    const inOverview = mobileSession.matches && state !== null &&
      phaseOf(state) === 'live' && connectionIssue !== 'unavailable';
    if (overviewDetails.hidden === !inOverview) return;
    const focused = document.activeElement;
    const restoreFocus = detailHomes.some(({node}) => node.contains(focused));
    for (const {node, home} of detailHomes) {
      if (inOverview) overviewDetails.append(node);
      else home.after(node);
    }
    overviewDetails.hidden = !inOverview;
    if (restoreFocus) focusSessionControl(focused);
  }

  function renderSessionTabs(phase) {
    const live = phase === 'live';
    const ended = phase === 'ended';
    if (!live) activeSessionTab = 'overview';
    if (!changed('session-tabs', [phase, activeSessionTab])) return;
    sessionTabList.hidden = !live;
    for (const tabButton of sessionTabButtons) {
      const selected = tabButton.dataset.sessionTab === activeSessionTab;
      tabButton.setAttribute('aria-selected', String(selected));
      tabButton.tabIndex = selected ? 0 : -1;
      $(tabButton.getAttribute('aria-controls')).hidden = !live || !selected;
    }
    const parent = ended ? endedTools : sessionFollowUpParent;
    if (sessionFollowUp.parentElement !== parent) parent.append(sessionFollowUp);
    endedTools.hidden = !ended;
  }

  function showSessionTab(name, focus = false) {
    const target = sessionTabButtons.find(tabButton => tabButton.dataset.sessionTab === name);
    if (!target || sessionTabList.hidden || !state) return;
    activeSessionTab = name;
    renderSessionTabs(phaseOf(state));
    if (focus) target.focus();
    renderChat();
    if (name === 'chat') void refreshThread().catch(notice);
  }

  sessionTabList.addEventListener('click', event => {
    const target = event.target.closest('[data-session-tab]');
    if (target) showSessionTab(target.dataset.sessionTab);
  });
  sessionTabList.addEventListener('keydown', event => {
    const index = sessionTabButtons.indexOf(event.target);
    if (index < 0) return;
    const end = sessionTabButtons.length - 1;
    const target = {ArrowLeft: index === 0 ? end : index - 1,
      ArrowRight: index === end ? 0 : index + 1, Home: 0, End: end}[event.key];
    if (target === undefined) return;
    event.preventDefault();
    showSessionTab(sessionTabButtons[target].dataset.sessionTab, true);
  });
  for (const shortcut of document.querySelectorAll('[data-session-open]')) {
    shortcut.addEventListener('click', () => showSessionTab(shortcut.dataset.sessionOpen, true));
  }

  function render() {
    const phase = phaseOf(state);
    const live = phase === 'live';
    const ended = phase === 'ended';
    const approved = live && state.phone?.status === 'approved';
    $('title').textContent = state.title;
    applyPageBranding(state.branding);
    brandingEditable(state.branding_editable === true);
    if (host) {
      const destination = live ? $('branding-settings-slot') : $('branding-session-tools');
      if ($('host-branding').parentElement !== destination) destination.append($('host-branding'));
      $('branding-session-tools').hidden = live || state.branding_editable !== true;
    }
    renderSchedule(phase);
    renderSessionTabs(phase);
    placeOverviewDetails();
    if (!host) {
      const share = $('guest-share');
      const canShare = live && connectionIssue !== 'unavailable';
      if (canShare && share.hidden) initializeSessionShare();
      share.hidden = !canShare;
    }
    $('pairing').hidden = host || (!live && !ended);
    $('join').hidden = host || !live || Boolean(state.phone);
    $('remember').hidden = !Number(state.venue_id) || $('forget').dataset.remembered === 'true';
    $('approval').textContent = ended ? 'This session has ended.' : ({
      pending: 'Waiting for the host to approve this phone.',
      revoked: 'The host closed this connection.'
    }[state.phone?.status] || '');
    const canViewQueue = live && Object.hasOwn(state, 'queue');
    $('music').hidden = !catalog || !canViewQueue;
    $('footer').hidden = !canViewQueue;
    $('queue').closest('.jukebox-session-queue').hidden = !canViewQueue;
    const playing = state.current;
    const now = playing ? title(playing.track_id) : 'Jukebox idle';
    const transport = state.transport?.status;
    const progress = !state.online ? 'Session player offline' : playing ?
      (Number(state.paused) || transport === 'paused' ? 'Paused · ' : transport === 'buffering' ? 'Buffering · ' :
        (transport === 'starting' || !playing.played ? 'Starting · ' : '')) + time(Number(state.seconds)) : 'Ready';
    $('now').textContent = now;
    $('progress').textContent = progress;
    const queued = state.queue?.length || 0;
    $('queue-label').textContent = 'Up Next (' + queued + ')';
    $('queue-empty').hidden = queued > 0;
    if (changed('queue', [state.queue, Boolean(catalog), live])) list('queue', state.queue || [], (row, index) => {
      const label = title(row.track_id);
      if (!host || !live) return [label];
      const move = button('Move up', () => mutate('control', {event: 'up', item: row.id}));
      move.disabled = index === 0;
      return hostRow(label, move, button('Remove', () => mutate('control', {event: 'remove', item: row.id})));
    });
    if (changed('catalog-rights', [canRequest(), Boolean(catalog)])) renderCatalog();
    requestButtons.forEach(control => { control.textContent = playing ? 'Add to Queue' : 'Play'; });
    if (host) {
      $('host-now').textContent = now;
      $('host-progress').textContent = progress;
      $('host').hidden = !live;
      $('recap').hidden = !ended;
      $('crm').hidden = !Number(state.venue_id);
      $('upcoming-edit-session').textContent = phase === 'canceled' ? 'Rename session' : 'Edit session';
      if (live) renderHost();
    }
    renderChat();
    renderConnection();
  }

  function updateListener(responseMs, requestStartedAt) {
    if (!listener) return;
    const transport = state.transport;
    // Project the playhead from the server's sample through the trip back to this phone.
    const responseTransitMs = playbackClock.transitMs(Number(transport?.server_time_ms), requestStartedAt, requestStartedAt + responseMs);
    listener.update({
      requestStartedAt,
      allowed: state.remote_listening === true && phaseOf(state) === 'live',
      online: Boolean(state.online),
      itemId: state.current?.id ?? '',
      generation: Number(transport?.generation ?? -1),
      transport: transport?.status ?? 'offline',
      seconds: Number(transport?.position_ms || 0) / 1000,
      sampleAgeMs: Math.max(0, Number(transport?.server_time_ms || 0) - Number(transport?.sampled_at_ms || 0)) + responseTransitMs
    });
  }

  function chatStatus(message) {
    const status = $('chat-status');
    if (!status) return;
    status.hidden = !message;
    status.textContent = message;
  }

  function setChatThread(next) {
    if (thread === next) return;
    thread = next;
    threadRequest = null;
    renderedThreadVersion = null;
    $('messages').replaceChildren();
    $('send').reset();
    chatStatus('');
  }

  function openChatThread(phoneId) {
    if (stopped || !state || phaseOf(state) !== 'live' || !state.phones?.some(phone => phone.id === phoneId && phone.status === 'approved')) return;
    setChatThread(phoneId);
    showSessionTab('chat');
    if (!$('chat').hidden) {
      $('chat-title').scrollIntoView({block: 'nearest'});
      $('chat-title').focus({preventScroll: true});
    }
  }

  function renderChat(authorized = true) {
    const live = authorized && !stopped && state && phaseOf(state) === 'live';
    const phones = live && host ? (state.phones || []).filter(phone => phone.status === 'approved') : [];
    const selected = host ? phones.find(phone => phone.id === thread)
      : (live && state.phone?.status === 'approved' ? state.phone : null);
    const chat = $('chat');
    const wasFocused = chat.contains(document.activeElement);
    setChatThread(selected?.id || '');
    chat.hidden = !thread;
    $('chat-empty').hidden = Boolean(thread);
    if (thread) {
      $('chat-title').textContent = host ? 'Private messages with ' + (selected.name || 'Guest') : 'Private messages with the host';
    } else {
      $('chat-empty').textContent = host ? (phones.length ? 'Choose a phone to open its conversation.' : 'Approved phones will appear here.')
        : 'Request access on the Session tab. Once the host approves your phone, you can chat here.';
      if (live && activeSessionTab === 'chat' && wasFocused) $('session-tab-chat').focus({preventScroll: true});
    }

    const unread = new Map((live ? state.chat_threads || [] : []).map(row => [row.phone_id,
      Math.max(0, row.incoming_count - (Number(chatReads[row.phone_id]) || 0))]));
    const count = [...unread.values()].reduce((total, value) => total + value, 0);
    const label = count + (count === 1 ? ' unread message' : ' unread messages');
    const badge = $('chat-unread');
    if (count !== chatUnread || badge.hidden !== (count === 0)) {
      badge.hidden = count === 0;
      badge.textContent = count ? String(count) : '';
      $('session-tab-chat').setAttribute('aria-label', count ? 'Chat, ' + label : 'Chat');
      $('chat-notice').textContent = count > chatUnread ? label : '';
      chatUnread = count;
    }
    if (host && changed('chat-threads', [phones.map(phone => [phone.id, phone.name, unread.get(phone.id) || 0]), thread])) {
      const focusedPhone = document.activeElement?.dataset.chatPhone;
      list('chat-threads', phones, phone => {
        const control = button(phone.name || 'Guest', () => openChatThread(phone.id));
        control.dataset.chatPhone = phone.id;
        control.setAttribute('aria-pressed', String(phone.id === thread));
        const messages = unread.get(phone.id) || 0;
        if (messages) {
          const badge = document.createElement('span');
          badge.className = 'jukebox-chat-count';
          badge.textContent = String(messages);
          badge.setAttribute('aria-label', messages + (messages === 1 ? ' unread message' : ' unread messages'));
          control.append(badge);
        }
        return [control];
      });
      if (focusedPhone && activeSessionTab === 'chat') {
        [...$('chat-threads').querySelectorAll('[data-chat-phone]')].find(control => control.dataset.chatPhone === focusedPhone)?.focus({preventScroll: true});
      }
    }
  }

  async function refreshThread(force = false) {
    if (!thread || activeSessionTab !== 'chat' || document.hidden) return;
    const key = thread;
    const version = state.chat_threads?.find(row => row.phone_id === key)?.latest_id || '0';
    if (!force && renderedThreadVersion === version) return;
    if (threadRequest?.key === key) {
      if (force) threadRequest.reload = true;
      return;
    }
    const request = {key, reload: false};
    threadRequest = request;
    const visible = () => threadRequest === request && thread === key && activeSessionTab === 'chat' && !document.hidden;
    try {
      const {messages} = await api('thread', undefined, {phone: key, full: '1'});
      if (!visible()) return;
      const history = $('messages');
      const atBottom = renderedThreadVersion === null || history.scrollHeight - history.scrollTop - history.clientHeight < 32;
      const previous = messages.findIndex(row => String(row.id) === renderedThreadVersion);
      if (previous < 0) history.replaceChildren();
      for (const row of messages.slice(previous + 1)) {
        const item = document.createElement('li');
        item.textContent = (row.sender === 'host' ? 'Host' : 'Guest') + ' · ' + row.body;
        if (Number(row.attachment)) {
          const image = document.createElement('img');
          image.src = endpoint('image', {phone: key, message: row.id});
          image.alt = 'Chat attachment';
          image.loading = 'lazy';
          item.append(image);
        }
        history.append(item);
      }
      if (atBottom) history.scrollTop = history.scrollHeight;
      renderedThreadVersion = String(messages.at(-1)?.id || '0');
      chatReads[key] = messages.filter(row => row.sender === (host ? 'guest' : 'host')).length;
      try { sessionStorage.setItem(chatReadKey, JSON.stringify(chatReads)); } catch {}
      chatStatus('');
      renderChat();
    } catch (error) {
      if (!visible()) return;
      if ([401, 403, 404, 409].includes(error.status)) {
        setChatThread('');
        $('chat').hidden = true;
        $('chat-empty').hidden = false;
        $('chat-empty').textContent = 'This conversation is unavailable.';
      } else chatStatus(error.message);
    } finally {
      if (threadRequest === request) {
        threadRequest = null;
        if (request.reload) void refreshThread(true);
      }
    }
  }

  async function refreshCrm() {
    if (crmRequest || !$('crm').open || (state && phaseOf(state) === 'live' && activeSessionTab !== 'history')) return;
    crmRequest = api('crm').finally(() => { crmRequest = null; });
    const {guests} = await crmRequest;
    if (!changed('guests', [guests, Boolean(catalog)])) return;
    list('guests', guests, guest => {
      const history = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = (guest.name || 'Unnamed guest') + ' · ' + guest.visits + ' visits · last ' + date(guest.last_visit);
      const entries = document.createElement('ul');
      for (const row of guest.history) {
        const entry = document.createElement('li');
        entry.textContent = date(row.approved) + ' · ' + row.session_title + (row.track_id ?
          ' · ' + title(row.track_id) + (row.played ? ' played' : ' requested') : '');
        entries.append(entry);
      }
      history.append(summary, entries, button('Remove guest record', async () => {
        if (confirm('Remove this guest from venue visit history?')) {
          await api('remove_guest', {guest: guest.id});
          await refreshCrm();
        }
      }));
      return [history];
    });
  }

  function playerEvent(event, item = currentItem, extras = {}) {
    const positionMs = Math.floor((audio.currentTime || 0) * 1000);
    const sample = {
      player, event, item, seconds: Math.floor(positionMs / 1000), position_ms: positionMs,
      transport: hostTransport, sample_seq: ++playerSequence, observed_revision: state?.revision, ...extras
    };
    const stampCapture = () => {
      const capturedAt = playbackClock.serverAt();
      if (Number.isFinite(capturedAt)) sample.captured_at_ms = Math.floor(capturedAt);
      else delete sample.captured_at_ms;
    };
    stampCapture();
    const request = playerReports.then(() => {
      // Capture again at dispatch when the queued event still describes the native player.
      if (item && item === currentItem && sample.transport === hostTransport && (event === 'playing' || event === 'progress')) {
        sample.position_ms = Math.floor((audio.currentTime || 0) * 1000);
        sample.seconds = Math.floor(sample.position_ms / 1000);
        stampCapture();
      }
      return api('player', sample);
    });
    playerReports = request.catch(() => {});
    return request;
  }
  function clearHostSource() {
    currentItem = '';
    lastPlaying = false;
    hostTransport = 'starting';
    hostPlayAttempt = null;
    $('audio-start').hidden = true;
    if (!audio.hasAttribute('src')) return;
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
  }
  function releasePlayer() {
    active = false;
    clearHostSource();
  }
  function reportHostError(error) {
    if ([401, 403, 404].includes(error.status)) invalidateConnection();
    else if (error.status === 409) releasePlayer();
    if ($('footer').hidden) notice(error);
    else playerNotice(error);
  }
  function confirmHostPlayback(item) {
    if (hostConfirmation?.item === item) return hostConfirmation.task;
    const confirmation = {item};
    confirmation.task = playerEvent('playing', item).then(() => {
      if (item === currentItem) lastPlaying = true;
    }).finally(() => {
      if (hostConfirmation === confirmation) hostConfirmation = null;
    });
    hostConfirmation = confirmation;
    return confirmation.task;
  }
  function reportHostProgress() {
    if (!active || stopped || networkOffline) return Promise.resolve();
    // Coalesce timer/poll reports. Capture the latest native position at dispatch,
    // rather than queueing one request for every missed timer tick.
    if (hostProgressTask) {
      hostProgressWanted = true;
      return hostProgressTask;
    }
    const task = playerEvent(currentItem ? 'progress' : 'heartbeat').then(() => {
      if (active && !audio.paused && hostTransport === 'playing') playerNotice('');
    }).finally(() => {
      if (hostProgressTask !== task) return;
      hostProgressTask = null;
      if (hostProgressWanted) {
        hostProgressWanted = false;
        setTimeout(() => { void reportHostProgress().catch(reportHostError); }, 0);
      }
    });
    hostProgressTask = task;
    return task;
  }
  function reportNativeTransport(transport) {
    if (!active || !currentItem || audio.currentSrc !== audio.src || transport === hostTransport) return;
    hostTransport = transport;
    const report = transport === 'playing' && !lastPlaying ? confirmHostPlayback(currentItem) : reportHostProgress();
    void report.catch(reportHostError);
  }
  function startHostAudio() {
    if (hostPlayAttempt) return hostPlayAttempt;
    const item = currentItem;
    const attempt = audio.play().then(() => {
      if (item !== currentItem || !active) return;
      playerNotice('');
      $('audio-start').hidden = true;
    }).catch(async error => {
      if (item !== currentItem || !active) return;
      if (error.name === 'NotAllowedError') {
        playerNotice('Press Start audio on this device to allow playback.');
        $('audio-start').hidden = false;
      } else if (error.name !== 'AbortError') {
        playerNotice('A track was unavailable. Advancing the queue.');
        await playerEvent('failed', item);
        await tick();
      }
    }).catch(reportHostError).finally(() => {
      if (hostPlayAttempt === attempt) hostPlayAttempt = null;
    });
    hostPlayAttempt = attempt;
    return attempt;
  }
  async function syncPlayer() {
    if (!active) return;
    const item = state.current;
    if (!item) {
      clearHostSource();
    } else if (String(item.id) !== currentItem) {
      clearHostSource();
      currentItem = String(item.id);
      audio.src = 'api/canvas-audio.php?' + new URLSearchParams({action: 'source', track_id: item.track_id, item: item.id});
      const seconds = Number(state.transport?.position_ms ?? Number(state.seconds) * 1000) / 1000;
      if (seconds > 0) audio.currentTime = seconds;
    }
    if (item && Number(state.paused)) {
      hostTransport = 'paused';
      $('audio-start').hidden = true;
      audio.pause();
    } else if (hostTransport === 'paused' && audio.paused) {
      hostTransport = 'starting';
    }
    try {
      if (hostTransport === 'playing' && !lastPlaying) await confirmHostPlayback(currentItem);
      else void reportHostProgress().catch(reportHostError);
    } catch (error) {
      reportHostError(error);
      return;
    }
    if (active && item && !Number(state.paused) && audio.paused && !audio.ended && $('audio-start').hidden) startHostAudio();
  }

  function tick() {
    if (stopped) return;
    if (tickTask) {
      // Actions await a refresh that starts after their mutation, even if a poll is in flight.
      queuedTick ??= tickTask.then(() => {
        queuedTick = null;
        return tick();
      });
      return queuedTick;
    }
    tickTask = refreshSession().finally(() => { tickTask = null; });
    return tickTask;
  }

  async function refreshSession() {
    const requestEpoch = sessionRefreshEpoch;
    let stateReceived = false;
    try {
      const requestStarted = performance.now();
      const result = await api('state');
      if (requestEpoch !== sessionRefreshEpoch) return;
      const responseMs = performance.now() - requestStarted;
      state = result.state;
      connectionIssue = '';
      connectionRefreshing = false;
      networkOffline = false;
      if (!host) {
        window.StonefellowPwaAlerts?.updateSession(result);
        document.dispatchEvent(new CustomEvent('stonefellow:jukebox-state', {detail: state}));
      }
      const phase = phaseOf(state);
      stateReceived = true;
      if (stateFetchError && $('status').textContent === stateFetchError) notice('');
      stateFetchError = '';
      updateListener(responseMs, requestStarted);
      $('forget').hidden = !result.remembered;
      $('forget').dataset.remembered = String(result.remembered);
      if (!catalog && !catalogPending && phase === 'live' && Object.hasOwn(state, 'queue')) {
        catalogPending = true;
        api('catalog').then(data => { catalog = data; render(); }).catch(notice)
          .finally(() => { catalogPending = false; });
      }
      render();
      if (phase === 'ended' || phase === 'canceled') {
        stopped = true;
        if (host) {
          if (active) releasePlayer();
          if (phase === 'ended') await refreshRecap();
        }
        return;
      }
      if (phase === 'upcoming') return;
      void refreshThread();
      if (host) {
        void refreshCrm().catch(notice);
        await syncPlayer();
      }
    } catch (error) {
      if (requestEpoch !== sessionRefreshEpoch) return;
      connectionRefreshing = false;
      const terminal = [401, 403, 404].includes(error.status);
      if (!stateReceived) stateFetchError = error.message;
      if (terminal) {
        invalidateConnection();
      } else if (!stateReceived) {
        connectionIssue = 'reconnecting';
        listener?.transientFailure();
      }
      notice(error);
      renderConnection();
    }
  }

  async function openSessionEdit(sessionId, control) {
    const generation = ++managementLoad;
    const {settings} = await api('settings', undefined, {id: sessionId});
    if (generation !== managementLoad || !control.isConnected) return;
    editingSession = sessionId;
    editSnapshot = settings;
    editDialog.open(control);
    fillEditSettings(settings);
  }

  function fillEditSettings(settings) {
    const form = editDialog.form;
    const archived = settings.phase === 'ended' || settings.phase === 'canceled';
    const upcoming = settings.phase === 'upcoming';
    $('edit-heading').textContent = archived ? 'Rename session' : 'Edit session';
    form.elements.title.value = settings.title;
    form.elements.listed.checked = settings.listed;
    form.elements.listed.disabled = archived || $('edit-listing').hidden;
    form.elements.remote_listening.checked = settings.remote_listening;
    form.elements.remote_listening.disabled = archived;
    form.elements.auto_approve_phones.checked = settings.auto_approve_phones === true;
    form.elements.auto_approve_phones.disabled = archived;
    form.elements.scheduled_at.value = settings.scheduled_at ? localScheduleValue(settings.scheduled_at) : '';
    form.elements.scheduled_at.disabled = !upcoming;
    form.elements.scheduled_at.required = upcoming;
    $('edit-options').hidden = archived;
    $('edit-schedule').hidden = !upcoming;
    $('edit-schedule-help').hidden = !upcoming;
    $('edit-description').textContent = settings.phase === 'canceled' ? 'Canceled sessions can be renamed. The session link stays available.'
      : archived ? 'Completed sessions can be renamed. The recap and session link stay available.'
      : 'The session link stays the same, so guests can keep using it.';
    $('edit-reload').hidden = true;
  }

  async function reuseSessionSettings(sessionId, control) {
    const generation = ++managementLoad;
    const {settings} = await api('settings', undefined, {id: sessionId});
    if (generation !== managementLoad || !control.isConnected) return;
    createDialog.open(control);
    const form = createDialog.form;
    form.elements.title.value = settings.title;
    form.elements.listed.checked = settings.listed && !$('listing').hidden;
    form.elements.remote_listening.checked = settings.remote_listening;
    form.elements.auto_approve_phones.checked = settings.auto_approve_phones === true;
    form.elements.scheduled_at.value = '';
    $('create-source').textContent = `Using settings from “${settings.title}”. Choose when your new session starts.`;
    $('create-source').hidden = false;
  }

  function applySessionSettings(sessionId, settings) {
    directoryRows = directoryRows.map(row => row.id === sessionId ? {...row, ...settings, settings} : row);
    if (!id) renderSessionList('sessions', directoryRows);
    if (sessionId === id && state) {
      Object.assign(state, settings);
      render();
    }
  }

  function renderSessionList(containerId, sessions) {
    const container = $(containerId);
    const existing = new Map([...container.children].map(item => [item.dataset.sessionId, item]));
    const visible = new Set(sessions.map(row => row.id));
    existing.forEach((item, sessionId) => { if (!visible.has(sessionId)) item.remove(); });
    sessions.forEach((row, index) => {
      const phase = phaseOf(row);
      const upcoming = phase === 'upcoming';
      let item = existing.get(row.id);
      if (!item) {
        item = document.createElement('li');
        item.dataset.sessionId = row.id;
        const badge = document.createElement('span');
        badge.className = 'session-badge';
        const scheduled = document.createElement('time');
        scheduled.className = 'session-date';
        const sessionLink = link('', page(row.id, host));
        const logo = document.createElement('span');
        logo.className = 'jukebox-session-logo';
        const image = document.createElement('img');
        image.alt = '';
        image.width = image.height = 40;
        image.hidden = true;
        const fallback = document.createElement('span');
        fallback.className = 'jukebox-record';
        fallback.setAttribute('aria-hidden', 'true');
        logo.append(image, fallback);
        const text = document.createElement('span');
        text.className = 'session-title-copy';
        sessionLink.append(logo, text);
        item.append(sessionLink, badge, scheduled);
        if (host) {
          const actions = document.createElement('div');
          actions.className = 'jukebox-session-card-actions';
          const edit = button('Edit', event => openSessionEdit(row.id, event.currentTarget));
          edit.dataset.sessionEdit = row.id;
          edit.setAttribute('aria-haspopup', 'dialog');
          const reuse = button('Use settings', event => reuseSessionSettings(row.id, event.currentTarget));
          reuse.dataset.sessionReuse = row.id;
          reuse.setAttribute('aria-haspopup', 'dialog');
          const recap = link('View recap', page(row.id, true));
          recap.dataset.sessionRecap = row.id;
          actions.append(edit, reuse, recap);
          item.append(actions);
        }
      }
      const [title, badge, scheduled] = item.children;
      const label = row.title;
      const status = host ? ({upcoming: 'Upcoming', live: 'Open', ended: 'Completed', canceled: 'Canceled'}[phase] || '')
        : upcoming ? (row.remote_listening ? 'Phone listening planned' : '')
        : (row.online ? 'Live' : 'Host player offline') + (row.remote_listening ? ' · Phone listening enabled' : '');
      const titleText = title.querySelector('.session-title-copy');
      if (titleText.textContent !== label) titleText.textContent = label;
      const logo = title.querySelector('.jukebox-session-logo');
      paintArtwork(logo.querySelector('img'), logo.querySelector('.jukebox-record'), logoUrl((host ? pageBranding : row.branding)?.logo_url));
      if (host) title.setAttribute('aria-label', phase === 'ended' || phase === 'canceled' ? `${label} (${phase})` : label);
      if (badge.textContent !== status) badge.textContent = status;
      badge.hidden = !status;
      badge.classList.toggle('session-badge-live', phase === 'live' && Boolean(row.online));
      badge.classList.toggle('session-badge-upcoming', upcoming);
      if (host) {
        const edit = item.querySelector('[data-session-edit]');
        edit.textContent = phase === 'ended' || phase === 'canceled' ? 'Rename' : 'Edit';
        edit.setAttribute('aria-label', `${edit.textContent} ${row.title}`);
        item.querySelector('[data-session-reuse]').setAttribute('aria-label', `Use settings from ${row.title}`);
        const recap = item.querySelector('[data-session-recap]');
        recap.hidden = phase !== 'ended';
        recap.setAttribute('aria-label', `View recap for ${row.title}`);
      }
      scheduled.hidden = !upcoming;
      if (upcoming) setScheduleDate(scheduled, row.scheduled_at);
      if (container.children[index] !== item) container.insertBefore(item, container.children[index] ?? null);
    });
  }
  function applyHostVenueName(venue) {
    const name = venue?.trim() || '';
    const heading = name || $('host-username').textContent;
    connectionText('title', heading);
    $('host-venue-details').hidden = !name;
    $('venue-name').elements.name.defaultValue = name;
    document.title = heading + ' · Stonefellow';
  }
  async function hostDirectory(append = false) {
    if (append && (directoryPending || !directoryCursor)) return;
    const scope = directoryScope;
    const generation = append ? directoryGeneration : ++directoryGeneration;
    directoryPending = true;
    $('directory').hidden = false;
    $('directory-results').setAttribute('aria-busy', 'true');
    $('directory-more').disabled = true;
    $('directory-status').textContent = append ? 'Loading more sessions…' : `Loading ${scope === 'archive' ? 'archived' : 'active'} sessions…`;
    if (!append) {
      directoryRows = [];
      directoryCursor = null;
      renderSessionList('sessions', []);
      $('session-empty').hidden = true;
      $('directory-more').hidden = true;
    }
    try {
      const data = await api('directory', undefined, {scope, limit: '25', ...(append ? {cursor: directoryCursor} : {})});
      if (generation !== directoryGeneration) return;
      const previous = new Set(directoryRows.map(row => row.id));
      directoryRows = append ? directoryRows.concat(data.sessions.filter(row => !previous.has(row.id))) : data.sessions;
      directoryCursor = data.next_cursor;
      applyHostVenueName(data.venue);
      applyPageBranding(data.branding);
      applyHostCover(data.cover);
      brandingEditable(data.branding_editable === true);
      $('session-empty').hidden = Boolean(directoryRows.length);
      $('mode-link').href = 'jukebox.php';
      $('mode-link').textContent = 'Live Sessions';
      $('open-create').hidden = !data.admin && !data.venue;
      const venueButton = $('open-venue');
      if (venueButton) venueButton.hidden = !data.admin;
      $('listing').hidden = !data.admin;
      renderSessionList('sessions', directoryRows);
      $('directory-more').hidden = !directoryCursor;
      $('directory-more').textContent = 'Load more';
      $('directory-status').textContent = directoryRows.length
        ? `${directoryRows.length} ${scope === 'archive' ? 'archived' : 'active'} ${directoryRows.length === 1 ? 'session' : 'sessions'} shown.` : '';
      if (!data.admin && !data.venue) notice('Ask a Stonefellow admin to enable your venue account.');
    } catch (error) {
      if (generation !== directoryGeneration) return;
      $('directory-status').textContent = error.message;
      $('directory-more').textContent = 'Retry loading sessions';
      $('directory-more').hidden = false;
    } finally {
      if (generation === directoryGeneration) {
        directoryPending = false;
        $('directory-results').removeAttribute('aria-busy');
        $('directory-more').disabled = false;
      }
    }
  }

  function selectDirectoryScope(scope, focus = false) {
    if (scope === directoryScope) return;
    managementLoad++;
    directoryScope = scope;
    for (const control of $('directory-tabs').querySelectorAll('[data-directory-scope]')) {
      const selected = control.dataset.directoryScope === scope;
      control.setAttribute('aria-selected', String(selected));
      control.tabIndex = selected ? 0 : -1;
      if (selected && focus) control.focus();
    }
    $('directory-results').setAttribute('aria-labelledby', 'directory-tab-' + scope);
    $('session-heading').textContent = scope === 'archive' ? 'Archive' : 'Active sessions';
    $('directory-description').textContent = scope === 'archive'
      ? 'Ended and canceled sessions. Open an ended session to view its recap.'
      : 'Open and scheduled sessions. Completed sessions move to Archive.';
    $('session-empty').textContent = scope === 'archive' ? 'No archived sessions yet.' : 'No active sessions. Create a new session to get started.';
    void directory();
  }

  async function directory() {
    if (host) return hostDirectory();
    if (directoryPending) return;
    directoryPending = true;
    try {
      const data = await api('directory');
      $('directory').hidden = false;
      {
        $('title').textContent = 'Live Sessions';
        $('session-heading').textContent = 'Live';
        $('session-intro').hidden = false;
        $('upcoming-directory').hidden = false;
        const live = data.sessions.filter(row => phaseOf(row) === 'live');
        const upcoming = data.sessions.filter(row => phaseOf(row) === 'upcoming');
        const following = Array.isArray(data.following_sessions) ? data.following_sessions : [];
        $('session-empty').textContent = 'No live public sessions.';
        $('session-empty').hidden = Boolean(live.length);
        $('upcoming-empty').hidden = Boolean(upcoming.length);
        notice('');
        renderSessionList('sessions', live);
        renderSessionList('upcoming-sessions', upcoming);
        if ($('following-directory')) {
          $('following-directory').hidden = !following.length;
          renderSessionList('following-sessions', following);
        }
      }
    } finally {
      directoryPending = false;
    }
  }

  const createDialog = bindFormDialog('create', values => api('create', {
    title: values.get('title'), listed: values.has('listed'), remote_listening: values.has('remote_listening'),
    auto_approve_phones: values.has('auto_approve_phones'),
    scheduled_at: scheduleTimestamp(values.get('scheduled_at'))
  }), result => { location.href = page(result.id, true); }, () => { $('create-source').hidden = true; });
  const editDialog = bindFormDialog('edit', async values => {
    const archived = editSnapshot.phase === 'ended' || editSnapshot.phase === 'canceled';
    const body = {expected: editSnapshot, title: values.get('title')};
    if (!archived) {
      body.listed = $('edit-listing').hidden ? editSnapshot.listed : values.has('listed');
      body.remote_listening = values.has('remote_listening');
      body.auto_approve_phones = values.has('auto_approve_phones');
      if (editSnapshot.phase === 'upcoming' && values.get('scheduled_at') !== localScheduleValue(editSnapshot.scheduled_at)) {
        body.scheduled_at = scheduleTimestamp(values.get('scheduled_at'));
      }
    }
    try {
      return await api('settings', body, {id: editingSession});
    } catch (error) {
      if (error.status === 409) $('edit-reload').hidden = false;
      throw error;
    }
  }, ({settings}) => {
    applySessionSettings(editingSession, settings);
    notice('Session updated.');
  });
  if (host) {
    $('edit-reload').onclick = handle(async () => {
      const generation = managementLoad;
      const sessionId = editingSession;
      const {settings} = await api('settings', undefined, {id: sessionId});
      if (generation !== managementLoad || editingSession !== sessionId || !editDialog.dialog.open) return;
      editSnapshot = settings;
      fillEditSettings(settings);
    }, error => editDialog.report(error));
    $('directory-tabs').addEventListener('click', event => {
      const control = event.target.closest('[data-directory-scope]');
      if (control) selectDirectoryScope(control.dataset.directoryScope);
    });
    $('directory-tabs').addEventListener('keydown', event => {
      if (!event.target.matches('[data-directory-scope]') || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const scope = event.key === 'Home' ? 'active' : event.key === 'End' ? 'archive' : directoryScope === 'active' ? 'archive' : 'active';
      selectDirectoryScope(scope, true);
    });
    $('directory-more').onclick = () => { void hostDirectory(Boolean(directoryCursor)); };
    for (const target of ['edit-session', 'upcoming-edit-session', 'recap-edit-session']) {
      $(target).onclick = handle(event => openSessionEdit(id, event.currentTarget));
    }
    for (const target of ['reuse-session', 'upcoming-reuse-session', 'recap-reuse-session']) {
      $(target).onclick = handle(event => reuseSessionSettings(id, event.currentTarget));
    }
    $('session-heading').textContent = 'Active sessions';
    $('session-empty').textContent = 'No active sessions. Create a new session to get started.';
  }
  for (const label of document.querySelectorAll('[data-schedule-zone]')) {
    label.textContent = ` (${scheduleDate.resolvedOptions().timeZone})`;
  }
  bindFormDialog('venue', values => api('venue', Object.fromEntries(values)), () => {
    notice('Venue account enabled.');
    void directory().catch(notice);
  });
  bindFormDialog('venue-name', values => api('venue_name', {name: values.get('name')}), result => {
    applyHostVenueName(result.venue);
    notice('Venue name saved.');
    if (directoryPending) void directory().catch(notice);
  });
  bindVenueAccountPicker();
  bindBrandingDialog();
  const profile = $('jukebox-profile');
  document.addEventListener('click', event => {
    if (profile.open && !profile.contains(event.target)) profile.open = false;
  });
  profile.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || !profile.open) return;
    event.preventDefault();
    profile.open = false;
    profile.querySelector('summary').focus();
  });
  $('join').onsubmit = handle(async event => {
    const values = new FormData(event.currentTarget);
    await mutate('join', {name: values.get('name'), remember: values.has('remember')});
  });
  $('forget').onclick = handle(async () => {
    if (!confirm('Remove your saved guest record from this venue?')) return;
    await api('forget', {});
    const result = await api('state');
    $('forget').hidden = !result.remembered;
    $('forget').dataset.remembered = String(result.remembered);
    render();
  });
  $('tabs').onclick = event => {
    const selected = event.target.closest('button')?.dataset.tab;
    if (!selected) return;
    tab = selected;
    album = '';
    renderCatalog();
  };
  $('crm').addEventListener('toggle', () => { if ($('crm').open) void refreshCrm().catch(notice); });
  $('send').onsubmit = handle(async event => {
    const form = event.currentTarget;
    const values = new FormData(form);
    const file = values.get('image');
    const body = {text: values.get('text'), phone: thread};
    if (file?.size) {
      if (file.size > 1048576) throw new Error('Use an image under 1 MB.');
      body.image = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result.split(',')[1]);
        reader.onerror = reject;
        reader.readAsDataURL(file);
      });
    }
    if (!body.phone || body.phone !== thread) throw new Error('This conversation changed. Open it and try again.');
    await api('message', body);
    if (body.phone === thread) {
      form.reset();
      void refreshThread(true);
    }
    await tick();
  });

  if (!id) {
    void directory().catch(notice);
    if (!host) {
      const refreshDirectory = () => { if (!document.hidden) void directory().catch(notice); };
      setInterval(refreshDirectory, 8000);
      document.addEventListener('visibilitychange', refreshDirectory);
    }
    return;
  }
  if (host) {
    $('mode-link').textContent = 'Your sessions';
    $('recap-retry').onclick = handle(refreshRecap);
    initializeSessionShare();
    $('reschedule').onsubmit = handle(async event => {
      const values = new FormData(event.currentTarget);
      await mutate('control', {event: 'reschedule', scheduled_at: scheduleTimestamp(values.get('scheduled_at'))});
    });
    $('start-session').onclick = handle(() => mutate('control', {event: 'start'}));
    $('cancel-session').onclick = handle(async () => {
      if (confirm('Cancel this scheduled jukebox session?')) await mutate('control', {event: 'cancel'});
    });
    $('audio-start').onclick = handlePlayerAction(startHostAudio);
    $('player-toggle').onclick = handlePlayerAction(() => mutate('control', {
      event: Number(state.paused) ? 'resume' : 'pause'
    }));
    $('player-skip').onclick = handlePlayerAction(() => mutate('control', {event: 'skip'}));
    $('end-session').onclick = handle(async () => {
      if (confirm('End this jukebox session for all connected guests?')) await mutate('control', {event: 'end'});
    });
    $('disconnect-all-phones').onclick = async event => {
      const control = event.currentTarget;
      if (control.disabled || stopped || !confirm('Disconnect all connected phones and revoke pending requests? New phones will follow this session’s approval setting.')) return;
      disconnectAllPending = true;
      control.disabled = true;
      connectionText('phone-disconnect-status', 'Disconnecting phones…');
      try {
        const {disconnected} = await api('disconnect_all', {});
        await tick();
        connectionText('phone-disconnect-status', disconnected === 0 ? 'No phones remained to disconnect.'
          : `Disconnected ${disconnected} ${disconnected === 1 ? 'phone' : 'phones'}.`);
      } catch (error) {
        connectionText('phone-disconnect-status', error.message);
      } finally {
        disconnectAllPending = false;
        if (!stopped && phaseOf(state) === 'live') renderHost();
      }
    };
    $('volume').oninput = () => { audio.volume = Number($('volume').value); };
    $('remote-listening').onchange = handle(async event => {
      const setting = event.currentTarget;
      const enabled = setting.checked;
      remoteChangePending = true;
      setting.disabled = true;
      try {
        await api('control', {event: 'remote_listening', enabled});
        state.remote_listening = enabled;
        await tick();
      } finally {
        remoteChangePending = false;
        setting.disabled = false;
        if (!Number(state.ended)) renderHost();
      }
    });
    $('enable').onclick = handlePlayerAction(async () => {
      const takeover = Boolean(state?.online);
      if (takeover && !confirm('Move this session’s playback to this device?')) return;
      await playerEvent('claim', '', {takeover});
      active = true;
      $('enable').hidden = true;
      await tick();
    });
    audio.addEventListener('playing', () => {
      if (!audio.paused && !audio.ended && !Number(state?.paused)) reportNativeTransport('playing');
    });
    const reportBuffering = () => {
      if (!audio.paused && !audio.ended && audio.readyState < HTMLMediaElement.HAVE_FUTURE_DATA) reportNativeTransport('buffering');
    };
    audio.addEventListener('waiting', reportBuffering);
    audio.addEventListener('stalled', reportBuffering);
    audio.addEventListener('pause', () => {
      if (audio.paused && !audio.ended && hostTransport !== 'starting') reportNativeTransport('paused');
    });
    audio.addEventListener('ended', () => {
      if (active && currentItem && audio.ended && audio.currentSrc === audio.src) {
        void playerEvent('ended').then(tick).catch(reportHostError);
      }
    });
  } else {
    $('mode-link').href = 'jukebox.php';
    $('mode-link').textContent = 'Live Sessions';
    listener = window.StonefellowJukeboxListener.create({
      audio: $('listener-audio'), urlForItem: item => endpoint('audio', {item}),
      onActivity: createListenerReporter(),
      onChange(view) {
        const {allowed, listening, muted, needsGesture, status, connection} = view;
        listenerView = view;
        phoneStreaming = allowed && listening;
        const focused = document.activeElement;
        const controlsFocused = $('listener').contains(focused);
        $('listener').hidden = !allowed;
        $('listen').hidden = listening && !needsGesture;
        connectionText('listen', connection === 'error' ? 'Retry phone audio' : needsGesture ? 'Resume phone audio' : 'Stream on this phone');
        $('listener-resync').hidden = !listening;
        $('listener-mute').hidden = !listening;
        $('listener-mute').setAttribute('aria-pressed', String(muted));
        connectionText('listener-mute', muted ? 'Unmute phone' : 'Mute phone');
        $('listener-stop').hidden = !listening;
        const badge = $('listener-connection');
        badge.dataset.connection = connection;
        badge.dataset.muted = String(muted);
        connectionText('listener-connection', muted && connection === 'listening' ? 'Muted' : connectionLabels[connection] || 'Ready');
        connectionText('listener-status', muted && connection === 'listening'
          ? 'Phone audio is muted. Unmute when you’re ready.' : status);
        renderConnection();
        if (controlsFocused && (!allowed || focused.hidden)) {
          focusSessionControl(allowed && !$('listen').hidden ? $('listen') : $('connection-detail'));
        }
      }
    });
    $('listen').onclick = () => {
      listener.listen();
      void tick();
    };
    $('listener-resync').onclick = connectionAction(async () => {
      if (!listener.resync()) return;
      await refreshConnection({resync: true});
    });
    $('listener-stop').onclick = listener.stop;
    $('listener-mute').onclick = listener.toggleMute;

  }
  function poll() {
    if (stopped) return;
    void tick();
    setTimeout(poll, state?.phase === 'upcoming' ? 3000 : (phoneStreaming ? 1000 : 1500));
  }
  $('connection-retry').onclick = connectionAction(() => refreshConnection());
  mobileSession.addEventListener('change', placeOverviewDetails);
  window.addEventListener('offline', () => {
    if (stopped) return;
    sessionRefreshEpoch++;
    networkOffline = true;
    connectionRefreshing = false;
    connectionIssue = 'reconnecting';
    listener?.disconnect();
    renderConnection();
  });
  window.addEventListener('online', () => { void refreshConnection(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) void refreshConnection(); });
  window.addEventListener('pageshow', event => { if (event.persisted) void refreshConnection(); });
  renderConnection();
  poll();
  if (host && id) setInterval(() => {
    void reportHostProgress().catch(reportHostError);
  }, 1000);
})();
