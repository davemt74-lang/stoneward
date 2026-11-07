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
    const response = await fetch(endpoint(action, extra), options);
    const data = await response.json();
    if (!response.ok || !data.ok) {
      const error = new Error(data.message || 'Jukebox request failed.');
      error.status = response.status;
      throw error;
    }
    return data;
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
  const canRequest = () => state && !Number(state.ended) && (host || state.phone?.status === 'approved');

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

  function renderRecap(recap) {
    $('recap-start').textContent = 'Session opened ' + date(recap.created);
    const totals = $('recap-totals');
    totals.replaceChildren();
    const metrics = [
      ['Phone connections', recap.phones.connections], ['Approved phones', recap.phones.approved],
      ['Requests', recap.totals.requests], ['Guest requests', recap.totals.guest_requests],
      ['Host requests', recap.totals.host_requests], ['Confirmed plays', recap.totals.confirmed_plays],
      ...recapOutcomes.map(([key, label]) => [label, recap.totals[key]])
    ];
    for (const [label, value] of metrics) {
      const metric = document.createElement('div');
      const term = document.createElement('dt');
      const count = document.createElement('dd');
      term.textContent = label;
      count.textContent = value;
      metric.append(term, count);
      totals.append(metric);
    }
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

  function render() {
    const approved = state.phone?.status === 'approved' && !Number(state.ended);
    const ended = Boolean(Number(state.ended));
    $('title').textContent = state.title;
    $('pairing').hidden = host;
    $('join').hidden = host || Boolean(state.phone) || ended;
    $('remember').hidden = !Number(state.venue_id) || $('forget').dataset.remembered === 'true';
    $('approval').textContent = ended ? 'This session has ended.' : ({
      pending: 'Waiting for the host to approve this phone.',
      revoked: 'The host closed this connection.'
    }[state.phone?.status] || '');
    $('music').hidden = !catalog || ended || !Object.hasOwn(state, 'queue');
    $('footer').hidden = !Object.hasOwn(state, 'queue') || ended;
    const playing = state.current;
    $('now').textContent = playing ? title(playing.track_id) : 'Jukebox idle';
    $('progress').textContent = !state.online ? 'Session player offline' : playing ?
      (Number(state.paused) ? 'Paused · ' : (playing.played ? '' : 'Starting · ')) + time(Number(state.seconds)) : 'Ready';
    $('queue-label').textContent = 'Up Next (' + (state.queue?.length || 0) + ')';
    if (changed('queue', [state.queue, Boolean(catalog), ended])) list('queue', state.queue || [], row => [
      title(row.track_id),
      ...(host && !ended ? [
        button('Move up', () => mutate('control', {event: 'up', item: row.id})),
        button('Remove', () => mutate('control', {event: 'remove', item: row.id}))
      ] : [])
    ]);
    if (changed('catalog-rights', [canRequest(), Boolean(catalog)])) renderCatalog();
    requestButtons.forEach(control => { control.textContent = playing ? 'Add to Queue' : 'Play'; });
    if (!host) thread = approved ? state.phone.id : '';
    if (host) {
      $('host').hidden = ended;
      $('recap').hidden = !ended;
      $('crm').hidden = !Number(state.venue_id);
      if (!ended) renderHost();
      if (state.phones?.find(phone => phone.id === thread)?.status !== 'approved' || ended) thread = '';
    }
    $('chat').hidden = !thread;
    if (!thread) {
      $('messages').replaceChildren();
      renderKeys.delete('thread');
    }
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
    return api('player', {player, event, item, seconds: Math.floor(audio.currentTime || 0), ...extras});
  }
  function releasePlayer() {
    active = false;
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
    currentItem = '';
    lastPlaying = false;
  }
  async function syncPlayer() {
    if (!active) return;
    try {
      await playerEvent(lastPlaying && !audio.paused ? 'progress' : 'heartbeat');
    } catch (error) {
      audio.pause();
      if (error.status === 409) releasePlayer();
      throw error;
    }
    const item = state.current;
    if (!item) {
      $('audio-start').hidden = true;
      audio.pause();
      currentItem = '';
      lastPlaying = false;
      return;
    }
    if (String(item.id) !== currentItem) {
      currentItem = String(item.id);
      lastPlaying = false;
      audio.src = 'api/canvas-audio.php?' + new URLSearchParams({action: 'source', track_id: item.track_id});
      if (Number(state.seconds) > 0) audio.currentTime = Number(state.seconds);
    }
    if (Number(state.paused)) {
      $('audio-start').hidden = true;
      audio.pause();
      return;
    }
    if (audio.paused && !audio.ended) {
      try {
        await audio.play();
        $('player-status').textContent = '';
        $('audio-start').hidden = true;
      } catch (error) {
        if (error.name === 'NotAllowedError') {
          $('player-status').textContent = 'Press Start audio on this device to allow playback.';
          $('audio-start').hidden = false;
        } else if (error.name !== 'AbortError') {
          $('player-status').textContent = 'A track was unavailable. Advancing the queue.';
          await playerEvent('failed');
        }
      }
    }
  }

  async function tick() {
    if (stopped) return;
    if (ticking) { tickAgain = true; return; }
    ticking = true;
    try {
      const result = await api('state');
      state = result.state;
      $('forget').hidden = !result.remembered;
      $('forget').dataset.remembered = String(result.remembered);
      if (!catalog && !catalogPending && !Number(state.ended) && Object.hasOwn(state, 'queue')) {
        catalogPending = true;
        api('catalog').then(data => { catalog = data; render(); }).catch(notice)
          .finally(() => { catalogPending = false; });
      }
      render();
      if (Number(state.ended)) {
        stopped = true;
        if (host) {
          if (active) releasePlayer();
          await refreshRecap();
        }
        return;
      }
      if (host) await syncPlayer();
      await Promise.all([refreshThread(), host ? refreshCrm() : Promise.resolve()]);
    } catch (error) {
      notice(error);
      if (error.status === 404 || error.status === 401) stopped = true;
    } finally {
      ticking = false;
      if (tickAgain) { tickAgain = false; void tick(); }
    }
  }

  async function directory() {
    const data = await api('directory');
    $('directory').hidden = false;
    $('session-heading').textContent = host ? 'Your sessions' : 'Live public sessions';
    $('mode-link').href = host ? 'jukebox.php' : 'jukebox.php?host=1';
    $('mode-link').textContent = host ? 'Public sessions' : 'Host jukebox';
    $('create').hidden = !host || (!data.admin && !data.venue);
    $('venue').hidden = !data.admin;
    $('listing').hidden = !data.admin;
    list('sessions', data.sessions, row => [link(row.title + (Number(row.ended) ? ' (ended)' : ''), page(row.id, host))]);
    if (!data.sessions.length) $('sessions').textContent = 'No sessions yet.';
    if (host && !data.admin && !data.venue) notice('Ask a Stonefellow admin to enable your venue account.');
  }

  $('create').onsubmit = handle(async event => {
    const values = new FormData(event.currentTarget);
    const result = await api('create', {title: values.get('title'), listed: values.has('listed')});
    location.href = page(result.id, true);
  });
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

  if (!id) { directory().catch(notice); return; }
  if (host) {
    $('mode-link').textContent = 'Your sessions';
    $('recap-retry').onclick = handle(refreshRecap);
    const join = new URL(page(id, false), location.href);
    $('join-link').href = join.href;
    $('qr').innerHTML = window.StonefellowJukeboxQR.svg(join.href);
    const audioStart = button('Start audio', async () => { await audio.play(); audioStart.hidden = true; });
    audioStart.id = 'audio-start';
    audioStart.hidden = true;
    $('player-status').after(audioStart);
    $('volume').oninput = () => { audio.volume = Number($('volume').value); };
    $('enable').onclick = handle(async () => {
      const takeover = Boolean(state?.online);
      if (takeover && !confirm('Move this session’s playback to this device?')) return;
      await playerEvent('claim', '', {takeover});
      active = true;
      $('enable').hidden = true;
      await tick();
    });
    audio.addEventListener('playing', () => {
      if (!active || !currentItem || lastPlaying) return;
      const item = currentItem;
      void playerEvent('playing', item).then(() => {
        if (item === currentItem) lastPlaying = true;
      }).catch(notice);
    });
    audio.addEventListener('ended', () => {
      if (active && currentItem) void playerEvent('ended').then(tick).catch(notice);
    });
  }
  void tick();
  setInterval(tick, 1500);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) void tick(); });
})();
