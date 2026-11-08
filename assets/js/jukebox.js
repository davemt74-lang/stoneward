'use strict';

(() => {
  const $ = id => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const id = params.get('id') || '';
  const host = params.get('host') === '1';
  const csrf = document.querySelector('meta[name=sf-csrf]').content;
  const audio = $('audio');
  const nonce = (length = 16) => [...crypto.getRandomValues(new Uint8Array(length))]
    .map(value => value.toString(16).padStart(2, '0')).join('');
  const player = host ? nonce(32) : '';
  let active = false;
  let currentItem = '';
  let hostTransport = 'starting';
  let hostPlayAttempt = null;
  let playerSequence = 0;
  let playerReports = Promise.resolve();
  let remoteChangePending = false;
  let listener = null;
  let stateFetchError = '';
  let state = null;
  let catalog = null;
  let catalogPending = false;
  let thread = '';
  let tab = 'feed';
  let album = '';
  let lastPlaying = false;
  let stopped = false;
  let ticking = false;
  let tickAgain = false;
  let directoryPending = false;
  let requestButtons = [];
  const renderKeys = new Map();

  function endpoint(action, extra = {}) {
    return 'api/jukebox.php?' + new URLSearchParams({action, id, ...(host ? {host: '1'} : {}), ...extra});
  }
  function page(session, isHost) {
    return 'jukebox.php?' + new URLSearchParams({id: session, ...(isHost ? {host: '1'} : {})});
  }
  async function api(action, body, extra) {
    const options = body === undefined ? {} : {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify(body)
    };
    if (action === 'listener' && body?.active === false) options.keepalive = true;
    const controller = ['state', 'player'].includes(action) || (!host && action === 'directory') ? new AbortController() : null;
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
      return data;
    } catch (error) {
      if (controller?.signal.aborted) throw new Error('The session connection timed out.');
      throw error;
    } finally {
      if (timer !== null) clearTimeout(timer);
    }
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
    $('status').textContent = typeof error === 'string' ? error : error.message;
  }
  function handle(work) {
    return async event => {
      event?.preventDefault();
      const control = event?.submitter || (event?.currentTarget instanceof HTMLButtonElement ? event.currentTarget : null);
      if (control) control.disabled = true;
      try {
        notice('');
        await work(event);
      } catch (error) {
        notice(error);
      } finally {
        if (control) control.disabled = false;
      }
    };
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
    $(id).replaceChildren(...rows.map(row => {
      const item = document.createElement('li');
      item.append(...build(row));
      return item;
    }));
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

  function renderCatalog() {
    if (!catalog) return;
    requestButtons = [];
    $('tabs').querySelectorAll('button').forEach(control => control.setAttribute('aria-pressed', control.dataset.tab === tab));
    if (tab === 'albums' && !album) {
      list('catalog', catalog.releases, release => [button(release.title, () => {
        album = release.release_id;
        renderCatalog();
      })]);
      return;
    }
    const release = catalog.releases.find(row => row.release_id === album);
    const tracks = tab === 'albums' ? (release?.track_ids || []).map(key =>
      catalog.tracks.find(track => track.track_id === key)).filter(Boolean) : [...catalog.tracks];
    if (tab === 'songs') tracks.sort((a, b) => a.title.localeCompare(b.title));
    list('catalog', tracks, track => {
      const parts = [track.title];
      if (canRequest() && track.source_available) {
        const control = button(state.current ? 'Add to Queue' : 'Play', async () => {
          const request = {track: track.track_id, nonce: nonce()};
          try {
            await api('request', request);
          } catch (error) {
            if (error.status) throw error;
            await api('request', request);
          }
          await tick();
        });
        requestButtons.push(control);
        parts.push(control);
      }
      return parts;
    });
    if (tab === 'albums') {
      const back = document.createElement('li');
      back.append(button('All albums', () => { album = ''; renderCatalog(); }));
      $('catalog').prepend(back);
    }
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
    if (changed('phones', state.phones)) list('phones', state.phones || [], phone => {
      const label = phone.name + ' · ' + phone.status + (phone.last_visit ? ' · Returning guest, last visit ' + date(phone.last_visit) : '');
      if (phone.status === 'pending') return [label,
        button('Approve', () => mutate('approve', {phone: phone.id})),
        button('Deny', () => mutate('revoke', {phone: phone.id}))];
      if (phone.status === 'approved') return [label,
        button('Chat', async () => {
          if (thread !== phone.id) {
            $('messages').replaceChildren();
            $('send').reset();
          }
          thread = phone.id;
          $('chat-title').textContent = 'Private messages with ' + phone.name;
          await tick();
        }),
        button('Revoke', () => mutate('revoke', {phone: phone.id}))];
      return [label];
    });
    if (changed('history', [state.history, Boolean(catalog)])) renderHistory('history', title);
    if (changed('controls', [state.paused, Boolean(state.current)])) {
      const controls = $('controls');
      controls.replaceChildren();
      if (state.current) controls.append(
        button(Number(state.paused) ? 'Resume' : 'Pause', () => mutate('control', {event: Number(state.paused) ? 'resume' : 'pause'})),
        button('Skip', () => mutate('control', {event: 'skip'})));
      controls.append(button('End session', async () => {
        if (confirm('End this jukebox session for all connected guests?')) await mutate('control', {event: 'end'});
      }));
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

  function render() {
    const phase = phaseOf(state);
    const live = phase === 'live';
    const ended = phase === 'ended';
    const approved = live && state.phone?.status === 'approved';
    $('title').textContent = state.title;
    renderSchedule(phase);
    $('pairing').hidden = host || (!live && !ended);
    $('join').hidden = host || !live || Boolean(state.phone);
    $('remember').hidden = !Number(state.venue_id) || $('forget').dataset.remembered === 'true';
    $('approval').textContent = ended ? 'This session has ended.' : ({
      pending: 'Waiting for the host to approve this phone.',
      revoked: 'The host closed this connection.'
    }[state.phone?.status] || '');
    $('music').hidden = !catalog || !live || !Object.hasOwn(state, 'queue');
    $('footer').hidden = !live || !Object.hasOwn(state, 'queue');
    const playing = state.current;
    $('now').textContent = playing ? title(playing.track_id) : 'Jukebox idle';
    const transport = state.transport?.status;
    $('progress').textContent = !state.online ? 'Session player offline' : playing ?
      (Number(state.paused) || transport === 'paused' ? 'Paused · ' : transport === 'buffering' ? 'Buffering · ' :
        (transport === 'starting' || !playing.played ? 'Starting · ' : '')) + time(Number(state.seconds)) : 'Ready';
    $('queue-label').textContent = 'Up Next (' + (state.queue?.length || 0) + ')';
    if (changed('queue', [state.queue, Boolean(catalog), live])) list('queue', state.queue || [], row => [
      title(row.track_id),
      ...(host && live ? [
        button('Move up', () => mutate('control', {event: 'up', item: row.id})),
        button('Remove', () => mutate('control', {event: 'remove', item: row.id}))
      ] : [])
    ]);
    if (changed('catalog-rights', [canRequest(), Boolean(catalog)])) renderCatalog();
    requestButtons.forEach(control => { control.textContent = playing ? 'Add to Queue' : 'Play'; });
    if (!host) thread = approved ? state.phone.id : '';
    if (host) {
      $('host').hidden = !live;
      $('recap').hidden = !ended;
      $('crm').hidden = !Number(state.venue_id);
      if (live) renderHost();
      if (state.phones?.find(phone => phone.id === thread)?.status !== 'approved' || !live) thread = '';
    }
    $('chat').hidden = !thread;
    if (!thread) {
      $('messages').replaceChildren();
      renderKeys.delete('thread');
    }
  }

  function updateListener() {
    if (!listener) return;
    const transport = state.transport;
    listener.update({
      allowed: state.remote_listening === true && phaseOf(state) === 'live',
      online: Boolean(state.online),
      itemId: state.current?.id ?? '',
      generation: Number(transport?.generation ?? -1),
      transport: transport?.status ?? 'offline',
      seconds: Number(transport?.position_ms || 0) / 1000,
      sampleAgeMs: Math.max(0, Number(transport?.server_time_ms || 0) - Number(transport?.sampled_at_ms || 0))
    });
  }

  async function refreshThread() {
    if (!thread) return;
    const key = thread;
    const result = await api('thread', undefined, {phone: key});
    if (thread !== key || !changed('thread', [key, result.messages])) return;
    list('messages', result.messages, row => {
      const parts = [(row.sender === 'host' ? 'Host' : 'Guest') + ' · ' + row.body];
      if (Number(row.attachment)) {
        const image = document.createElement('img');
        image.src = endpoint('image', {phone: key, message: row.id});
        image.alt = 'Chat attachment';
        image.loading = 'lazy';
        parts.push(image);
      }
      return parts;
    });
  }

  async function refreshCrm() {
    if (!$('crm').open) return;
    const {guests} = await api('crm');
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
    const request = playerReports.then(() => api('player', sample));
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
    if (error.status === 409) releasePlayer();
    notice(error);
  }
  function confirmHostPlayback(item) {
    return playerEvent('playing', item).then(() => {
      if (item === currentItem) lastPlaying = true;
    });
  }
  function reportNativeTransport(transport) {
    if (!active || !currentItem || audio.currentSrc !== audio.src || transport === hostTransport) return;
    hostTransport = transport;
    const report = transport === 'playing' && !lastPlaying ? confirmHostPlayback(currentItem) : playerEvent('progress');
    void report.catch(reportHostError);
  }
  function startHostAudio() {
    if (hostPlayAttempt) return;
    const item = currentItem;
    const attempt = audio.play().then(() => {
      if (item !== currentItem || !active) return;
      $('player-status').textContent = '';
      $('audio-start').hidden = true;
    }).catch(async error => {
      if (item !== currentItem || !active) return;
      if (error.name === 'NotAllowedError') {
        $('player-status').textContent = 'Press Start audio on this device to allow playback.';
        $('audio-start').hidden = false;
      } else if (error.name !== 'AbortError') {
        $('player-status').textContent = 'A track was unavailable. Advancing the queue.';
        await playerEvent('failed', item);
        await tick();
      }
    }).catch(reportHostError).finally(() => {
      if (hostPlayAttempt === attempt) hostPlayAttempt = null;
    });
    hostPlayAttempt = attempt;
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
      else await playerEvent(item ? 'progress' : 'heartbeat');
    } catch (error) {
      audio.pause();
      if (error.status === 409) releasePlayer();
      throw error;
    }
    if (active && item && !Number(state.paused) && audio.paused && !audio.ended && $('audio-start').hidden) startHostAudio();
  }

  async function tick() {
    if (stopped) return;
    if (ticking) { tickAgain = true; return; }
    ticking = true;
    let stateReceived = false;
    try {
      const result = await api('state');
      state = result.state;
      const phase = phaseOf(state);
      stateReceived = true;
      if (stateFetchError && $('status').textContent === stateFetchError) notice('');
      stateFetchError = '';
      updateListener();
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
      if (host) await syncPlayer();
      await Promise.all([refreshThread(), host ? refreshCrm() : Promise.resolve()]);
    } catch (error) {
      if (!stateReceived) {
        stateFetchError = error.message;
        if (error.status === 404 || error.status === 401) {
          listener?.update({allowed: false});
          if (active) releasePlayer();
        } else listener?.disconnect();
      }
      notice(error);
      if (error.status === 404 || error.status === 401) stopped = true;
    } finally {
      ticking = false;
      if (tickAgain) { tickAgain = false; void tick(); }
    }
  }

  function renderPublicSessions(containerId, sessions, upcoming = false) {
    const container = $(containerId);
    const existing = new Map([...container.children].map(item => [item.dataset.sessionId, item]));
    const visible = new Set(sessions.map(row => row.id));
    existing.forEach((item, sessionId) => { if (!visible.has(sessionId)) item.remove(); });
    sessions.forEach((row, index) => {
      let item = existing.get(row.id);
      if (!item) {
        item = document.createElement('li');
        item.dataset.sessionId = row.id;
        const badge = document.createElement('span');
        badge.className = 'session-badge';
        item.append(link(row.title, page(row.id, false)), badge);
        if (upcoming) {
          const scheduled = document.createElement('time');
          scheduled.className = 'session-date';
          item.append(scheduled);
        }
      }
      const [title, badge, scheduled] = item.children;
      const status = upcoming ? (row.remote_listening ? 'Phone listening planned' : '')
        : (row.online ? 'Live' : 'Host player offline') + (row.remote_listening ? ' · Phone listening enabled' : '');
      if (title.textContent !== row.title) title.textContent = row.title;
      if (badge.textContent !== status) badge.textContent = status;
      badge.hidden = !status;
      if (scheduled) setScheduleDate(scheduled, row.scheduled_at);
      if (container.children[index] !== item) container.insertBefore(item, container.children[index] ?? null);
    });
  }
  async function directory() {
    if (directoryPending) return;
    directoryPending = true;
    try {
      const data = await api('directory');
      $('directory').hidden = false;
      if (host) {
        $('session-empty').hidden = Boolean(data.sessions.length);
        $('session-heading').textContent = 'Your sessions';
        $('mode-link').href = 'jukebox.php';
        $('mode-link').textContent = 'Live Sessions';
        $('create').hidden = !data.admin && !data.venue;
        $('venue').hidden = !data.admin;
        $('listing').hidden = !data.admin;
        list('sessions', data.sessions, row => {
          const phase = phaseOf(row);
          const suffix = phase === 'ended' || phase === 'canceled' ? ` (${phase})` : '';
          const entry = link(row.title + suffix, page(row.id, true));
          if (phase !== 'upcoming') return [entry];
          const scheduled = document.createElement('time');
          scheduled.className = 'session-date';
          setScheduleDate(scheduled, row.scheduled_at);
          return [entry, ' · Upcoming', scheduled];
        });
        if (!data.admin && !data.venue) notice('Ask a Stonefellow admin to enable your venue account.');
      } else {
        $('title').textContent = 'Live Sessions';
        $('session-heading').textContent = 'Live';
        $('session-intro').hidden = false;
        $('upcoming-directory').hidden = false;
        const live = data.sessions.filter(row => phaseOf(row) === 'live');
        const upcoming = data.sessions.filter(row => phaseOf(row) === 'upcoming');
        $('session-empty').textContent = 'No live public sessions.';
        $('session-empty').hidden = Boolean(live.length);
        $('upcoming-empty').hidden = Boolean(upcoming.length);
        notice('');
        renderPublicSessions('sessions', live);
        renderPublicSessions('upcoming-sessions', upcoming, true);
      }
    } finally {
      directoryPending = false;
    }
  }

  $('create').onsubmit = handle(async event => {
    const values = new FormData(event.currentTarget);
    const result = await api('create', {
      title: values.get('title'), listed: values.has('listed'), remote_listening: values.has('remote_listening'),
      scheduled_at: scheduleTimestamp(values.get('scheduled_at'))
    });
    location.href = page(result.id, true);
  });
  for (const label of document.querySelectorAll('[data-schedule-zone]')) {
    label.textContent = ` (${scheduleDate.resolvedOptions().timeZone})`;
  }
  $('venue').onsubmit = handle(async event => {
    await api('venue', Object.fromEntries(new FormData(event.currentTarget)));
    notice('Venue account enabled.');
    await directory();
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
    form.reset();
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
    const join = new URL(page(id, false), location.href);
    $('join-link').href = join.href;
    $('qr').innerHTML = window.StonefellowJukeboxQR.svg(join.href);
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
    $('reschedule').onsubmit = handle(async event => {
      const values = new FormData(event.currentTarget);
      await mutate('control', {event: 'reschedule', scheduled_at: scheduleTimestamp(values.get('scheduled_at'))});
    });
    $('start-session').onclick = handle(() => mutate('control', {event: 'start'}));
    $('cancel-session').onclick = handle(async () => {
      if (confirm('Cancel this scheduled jukebox session?')) await mutate('control', {event: 'cancel'});
    });
    const audioStart = button('Start audio', startHostAudio);
    audioStart.id = 'audio-start';
    audioStart.hidden = true;
    $('player-status').after(audioStart);
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
    $('enable').onclick = handle(async () => {
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
      onChange({allowed, listening, muted, needsGesture, status}) {
        $('listener').hidden = !allowed;
        $('listen').hidden = listening && !needsGesture;
        $('listener-mute').hidden = !listening;
        $('listener-mute').setAttribute('aria-pressed', String(muted));
        $('listener-stop').hidden = !listening;
        $('listener-status').textContent = status;
      }
    });
    $('listen').onclick = listener.listen;
    $('listener-stop').onclick = listener.stop;
    $('listener-mute').onclick = listener.toggleMute;
    window.addEventListener('offline', listener.disconnect);
    window.addEventListener('online', () => { void tick(); });
  }
  function poll() {
    if (stopped) return;
    void tick();
    setTimeout(poll, state?.phase === 'upcoming' ? 3000 : 1500);
  }
  poll();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) void tick(); });
})();
