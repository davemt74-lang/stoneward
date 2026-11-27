/* VP3 public music transport. Credentials, tickets and receipt queues stay in
 * this parent-owned closure; catalog rows contain stable references only. */
(function (root) {
  'use strict';
  const protocol = 'vp3_music_provider_v1';
  const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  const maximumMs = 86400000;
  const publicPaths = new Set(['/', '/index.php', '/music', '/music/', '/music.php', '/listen', '/listen/', '/listen.php', '/track.php', '/song.php', '/album.php', '/albums.php', '/discography.php']);
  const number = value => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
  const milliseconds = value => Math.min(maximumMs, Math.floor(number(value)));
  const failure = (code = 'audio_source_unavailable', retryable = false) => Object.assign(new Error(
    retryable ? 'The music connection could not be confirmed. Try Play again.' : 'This VP3 recording is currently unavailable.'), { code, retryable });
  const interrupted = () => Object.assign(new Error('Playback was interrupted.'), { name: 'AbortError', code: 'audio_playback_interrupted' });
  function reference(track) {
    const value = track?.audio_source;
    return value?.kind === 'vp3' && ['installation_id', 'site_id', 'recording_id'].every(key => typeof value[key] === 'string' && uuidPattern.test(value[key]))
      ? { kind: 'vp3', installation_id: value.installation_id, site_id: value.site_id, recording_id: value.recording_id } : null;
  }
  function supports(track) { return reference(track) !== null; }
  function isProviderSource(track) { return track?.audio_source?.kind === 'vp3' || track?.audio_publishing_mode === 'vp3_stream_only'; }
  function hasPlayableSource(track) { return isProviderSource(track) ? supports(track) : !!track && typeof track.audio === 'string' && track.audio.trim() !== ''; }
  function sourceDescriptor(value, expected) {
    const supplied = reference({ audio_source: value });
    if (!supplied || !expected || ['installation_id', 'site_id', 'recording_id'].some(key => supplied[key] !== expected[key])) throw failure();
    let url;
    try { url = new URL(value.provider_api); } catch (_) { throw failure(); }
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash ||
        !/\/api\/music-provider-v1\.php$/.test(url.pathname)) throw failure();
    return Object.freeze({ ...supplied, provider_api: url.href });
  }
  function page(value, expectedOrigin = root.location?.origin) {
    try {
      const raw = value || root.location?.href || '';
      if (typeof raw !== 'string' || raw.length > 2048 || /[\x00-\x20\x7f\\]/.test(raw)) return '';
      // Inspect the original path before URL normalizes dot segments or encoded
      // separators. Only the real public music routes may leave this browser.
      const parts = /^https:\/\/[^/?#]+([^?#]*)/i.exec(raw);
      if (!parts) return '';
      const path = parts[1] || '/';
      const publicPath = publicPaths.has(path) || path === '/stonefellow-v120.php'
        || /^\/(?:track|release)\/[A-Za-z0-9_-]{1,160}\/?$/.test(path);
      const url = new URL(raw);
      return publicPath && url.protocol === 'https:' && !url.username && !url.password && url.pathname === path
        && (!expectedOrigin || url.origin === expectedOrigin) ? url.origin + path : '';
    } catch (_) { return ''; }
  }
  function create(options) {
    const audio = options?.audio;
    if (!audio?.addEventListener || typeof options.resolveSource !== 'function') throw new TypeError('VP3 playback requires owned media and a source resolver.');
    const fetcher = options.fetch || root.fetch.bind(root);
    const clock = options.now || (() => root.performance.now());
    const wallClock = options.wallNow || (() => Date.now());
    const schedule = options.setTimeout || root.setTimeout.bind(root), unschedule = options.clearTimeout || root.clearTimeout.bind(root);
    const random = options.crypto || root.crypto;
    const uuid = () => typeof random.randomUUID === 'function' ? random.randomUUID() : (() => {
      const b = random.getRandomValues(new Uint8Array(16)); b[6] = b[6] & 15 | 64; b[8] = b[8] & 63 | 128;
      const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
      return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    })();
    const key = () => 'mps1_' + Array.from(random.getRandomValues(new Uint8Array(32)), x => x.toString(16).padStart(2, '0')).join('');
    let current = null, disposed = false, operation = 0, activeOperation = null, draining = false, renewTimer = null;
    let wantsAudio = false, renewing = false, mediaChanges = 0;
    const queue = [];
    const rate = () => Math.max(.25, Math.min(4, number(audio.playbackRate) || 1));
    const position = () => milliseconds(number(audio.currentTime) * 1000);
    const looping = () => !!audio.loop || options.isLooping?.() === true;
    const active = () => !disposed && options.isActive?.() !== false;
    const same = (p, track) => p && p.trackId === String(track?.id ?? track?.track_id ?? '') &&
      JSON.stringify(p.reference) === JSON.stringify(reference(track));
    const clearRenew = () => { if (renewTimer !== null) unschedule(renewTimer); renewTimer = null; };
    function safeState() {
      const p = current;
      return p ? { kind: 'vp3', track_id: p.trackId, play_id: p.playId, session_id: p.sessionId || null,
        recording_id: p.reference.recording_id, asset_id: p.assetId || null, state: p.failed ? 'unavailable' : p.confirmationRequired ? 'confirmation_required' : p.state,
        accepted_seq: p.acceptedSeq, accepted_played_ms: p.acceptedMs, reported_played_ms: milliseconds(p.played),
        pending_events: queue.filter(entry => entry.play === p).length, renewing: renewing || mediaChanges > 0,
        confirmation_required: !!p.confirmationRequired, terminal: !!p.failed } : null;
    }
    function publish() { try { options.onStatus?.(safeState()); } catch (_) {} }
    function terminal(p, error) {
      if (p.failed) return;
      p.failed = error || failure();
      for (let i = queue.length - 1; i >= 0; i--) if (queue[i].play === p) queue.splice(i, 1);
      if (current === p) {
        wantsAudio = false; clearRenew(); cancelPending();
        try { audio.pause(); } catch (_) {}
        try { options.onError?.(p.failed); } catch (_) {}
        publish();
      }
    }
    function requireConfirmation(p, error) {
      if (p.failed) return;
      p.confirmationRequired = true; p.partial = true;
      if (current !== p || p.closed) {
        // A bounded final send for an abandoned play must not block the next
        // track's independent sequence indefinitely.
        for (let i = queue.length - 1; i >= 0; i--) if (queue[i].play === p) queue.splice(i, 1);
        return;
      }
      // A denied request can be opaque when authorization precedes CORS.
      // We cannot name the reason, but buffered bytes cannot keep playing
      // after this grant can no longer be confirmed. Explicit Play retries
      // the same session and frozen requests; it never allocates a new grant.
      cancelPending();
      try { audio.pause(); } catch (_) {}
      try { options.onError?.(error); } catch (_) {}
      publish();
    }
    async function post(p, body, signal) {
      const endpoint = new URL(p.source.provider_api); endpoint.searchParams.set('site_id', p.source.site_id);
      const serialized = JSON.stringify(body);
      let last = failure('audio_network_error', true);
      for (let attempt = 0; attempt < 3; attempt++) {
        if (signal?.aborted) throw interrupted();
        const request = new AbortController(), abort = () => request.abort();
        signal?.addEventListener('abort', abort, { once: true });
        const timeout = schedule(abort, options.requestTimeoutMs ?? 12000);
        try {
          const response = await fetcher(endpoint.href, { method: 'POST', mode: 'cors', credentials: 'omit',
            cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer', keepalive: true,
            headers: { 'Content-Type': 'application/json', ...(body.action === 'playback.begin' ? {} : { Authorization: 'Bearer ' + p.key }) },
            body: serialized, signal: request.signal });
          let result; try { result = await response.json(); } catch (_) { throw failure('audio_network_error', true); }
          if (!response.ok || result?.ok !== true || result?.protocol !== protocol) {
            const retryable = result?.retryable === true || response.status >= 500;
            throw Object.assign(failure(retryable ? 'audio_network_error' : 'audio_source_unavailable', retryable), { status: response.status });
          }
          return result;
        } catch (error) {
          if (signal?.aborted) throw interrupted();
          last = typeof error?.retryable === 'boolean' ? error : failure('audio_network_error', true);
          if (!last.retryable) throw last;
        } finally { unschedule(timeout); signal?.removeEventListener('abort', abort); }
        if (attempt < 2) await new Promise(resolve => schedule(resolve, options.retryDelayMs ?? 300 * (attempt + 1)));
      }
      throw last;
    }
    function receipt(p, result, seq = null) {
      if (result?.protocol !== protocol || result?.ok !== true || !uuidPattern.test(result.session_id || '') ||
          result.play_id !== p.playId || result.site_id !== p.reference.site_id || result.recording_id !== p.reference.recording_id ||
          !uuidPattern.test(result.asset_id || '') || result.identity?.provider_id !== p.reference.installation_id ||
          (p.sessionId && result.session_id !== p.sessionId) || (p.assetId && result.asset_id !== p.assetId) ||
          !Number.isInteger(result.accepted_seq) || result.accepted_seq < 0 || result.accepted_seq > 10000 ||
          !Number.isInteger(result.accepted_played_ms) || result.accepted_played_ms < 0 || result.accepted_played_ms > maximumMs ||
          (result.accepted_seq >= p.acceptedSeq && result.accepted_played_ms < p.acceptedMs) ||
          (seq !== null && result.accepted_seq !== seq)) throw failure();
      p.sessionId = result.session_id; p.assetId = result.asset_id;
      if (result.accepted_seq >= p.acceptedSeq) { p.acceptedSeq = result.accepted_seq; p.acceptedMs = result.accepted_played_ms; }
      publish();
    }
    function mediaTicket(p, result) {
      const value = result?.media; let url;
      try { url = new URL(value.url); } catch (_) { throw failure(); }
      const provider = new URL(p.source.provider_api);
      const expectedPath = provider.pathname.replace(/music-provider-v1\.php$/, 'music-provider-media-v1.php');
      if (url.origin !== provider.origin || url.pathname !== expectedPath || url.username || url.password || url.hash ||
          [...url.searchParams.keys()].length !== 1 || !url.searchParams.get('ticket')?.startsWith('mpd1.') ||
          !Number.isInteger(value.ticket_expires_ms) || !Number.isInteger(value.byte_size) || value.byte_size < 1 || value.byte_size > 64 * 1024 * 1024 ||
          typeof value.etag !== 'string' || !/^"sha256-[0-9a-f]{64}"$/.test(value.etag) ||
          !['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/vnd.wave', 'audio/ogg', 'application/ogg'].includes(value.mime) ||
          (p.media && (p.media.etag !== value.etag || p.media.byte_size !== value.byte_size || p.media.mime !== value.mime))) throw failure();
      p.media = { url: url.href, ticket_expires_ms: value.ticket_expires_ms, byte_size: value.byte_size, mime: value.mime, etag: value.etag };
    }
    function sample(p, allowPaused = false) {
      if (!p || !p.started || p.closed || p.failed) return;
      const now = clock(), at = position(), interval = Math.max(0, now - p.lastClock), advance = at - p.lastPosition;
      if (interval > 30000) p.partial = true;
      if (p.state === 'playing' && !audio.seeking && (!audio.paused || allowPaused) && advance > 0) {
        if (advance <= interval * Math.max(p.lastRate, rate()) + 500) p.played += Math.min(30000, interval, advance / Math.max(p.lastRate, rate()));
        else p.partial = true;
      }
      p.lastClock = now; p.lastPosition = at; p.lastRate = rate();
    }
    function enqueue(p, type, state) {
      if (!p?.sessionId || p.failed || p.closed || !p.started) return;
      if (queue.length >= 256 || p.seq >= 10000) { terminal(p, failure()); return; }
      const elapsed = milliseconds(Math.max(p.lastElapsed, clock() - p.created)); p.lastElapsed = elapsed;
      const body = Object.freeze({ protocol, action: 'playback.events', site_id: p.source.site_id, session_id: p.sessionId,
        event_id: uuid(), seq: ++p.seq, event_type: type, state, position_ms: position(), elapsed_ms: elapsed,
        cumulative_played_ms: Math.min(elapsed, milliseconds(p.played)), playback_rate: rate(), muted: !!audio.muted,
        loop: looping(), observation_partial: p.partial || queue.some(entry => entry.play === p) });
      p.state = state; p.lastReport = clock(); queue.push({ play: p, body }); void drain(); publish();
    }
    async function drain() {
      if (draining) return;
      draining = true;
      try {
        while (queue.length) {
          const task = queue[0], p = task.play;
          if (p.failed) { queue.shift(); continue; }
          if (p.confirmationRequired) break;
          try { const result = await post(p, task.body); receipt(p, result, task.body.seq); if (queue[0] === task) queue.shift(); }
          catch (error) {
            if (!error.retryable) { terminal(p, error); continue; }
            requireConfirmation(p, error);
            // The current play's exact frozen event stays first. A user retry
            // must confirm its authority before this sequence can continue.
            if (current === p && !p.closed) break;
          }
        }
      } finally { draining = false; publish(); }
    }
    function playing() {
      const p = current;
      if (!p?.sessionId) return;
      if (!active() || p.failed || p.closed || !wantsAudio || p.confirmationRequired) { if (!audio.paused) audio.pause(); return; }
      if (audio.paused || audio.seeking) return;
      if (!p.started) { p.started = true; p.played = 0; p.lastClock = clock(); p.lastPosition = position(); p.lastRate = rate(); enqueue(p, 'resume', 'playing'); }
      else if (p.state !== 'playing') { sample(p); p.lastClock = clock(); p.lastPosition = position(); enqueue(p, 'resume', 'playing'); }
      scheduleRenew();
    }
    function progress() {
      const p = current; if (!active() || !p?.started || p.failed || p.closed || p.confirmationRequired) return;
      sample(p);
      if (p.state === 'playing' && clock() - p.lastReport >= 10000) enqueue(p, 'heartbeat', 'playing');
    }
    function paused() {
      const p = current; if (!p?.started || p.failed || p.closed || audio.ended) return;
      sample(p, true);
      if (renewing || mediaChanges > 0 || audio.error) { if (p.state !== 'buffering') enqueue(p, 'waiting', 'buffering'); }
      else { wantsAudio = false; clearRenew(); if (p.state !== 'paused') enqueue(p, 'pause', 'paused'); }
    }
    function buffer() { const p = current; if (p?.started && !p.failed && !p.closed && p.state === 'playing') { sample(p); enqueue(p, 'waiting', 'buffering'); } }
    function seeking() { const p = current; if (p?.started && !p.failed && !p.closed) { p.lastClock = clock(); p.lastPosition = position(); enqueue(p, 'seek', audio.paused ? 'paused' : 'seeking'); } }
    function seeked() { const p = current; if (!p?.started || p.failed || p.closed) return; p.lastClock = clock(); p.lastPosition = position(); if (!audio.paused && !audio.seeking) playing(); }
    function ended() {
      const p = current; if (!p?.started || p.failed || p.closed || !audio.ended) return;
      sample(p, true);
      // A host-owned loop handle retains this logical play. Its subsequent
      // native seek/resume supplies the next observed segment.
      if (looping()) { enqueue(p, 'seek', 'paused'); clearRenew(); return; }
      enqueue(p, 'ended', 'ended'); p.closed = true; wantsAudio = false; clearRenew();
    }
    function finish() {
      const p = current;
      if (p?.confirmationRequired) {
        p.closed = true;
        for (let i = queue.length - 1; i >= 0; i--) if (queue[i].play === p) queue.splice(i, 1);
      }
      if (p?.started && !p.failed && !p.closed) {
        sample(p, true);
        // Queue controls may replace the track from an earlier ended listener.
        // Retain that real natural end before the parent clears the media.
        const natural = audio.ended && !looping();
        enqueue(p, natural ? 'ended' : 'stop', natural ? 'ended' : 'stopped'); p.closed = true;
      }
      clearRenew();
    }
    function cancelPending() { wantsAudio = false; operation++; activeOperation?.abort(); activeOperation = null; clearRenew(); }
    function operationContext(settings = {}) {
      activeOperation?.abort(); const controller = new AbortController(), generation = ++operation; activeOperation = controller;
      const abort = () => controller.abort(); settings.signal?.addEventListener('abort', abort, { once: true });
      if (settings.signal?.aborted) controller.abort();
      const valid = () => active() && operation === generation && !controller.signal.aborted && settings.isCurrent?.() !== false;
      return { signal: controller.signal, valid, close() { settings.signal?.removeEventListener('abort', abort); if (activeOperation === controller) activeOperation = null; } };
    }
    function restore(at, valid, signal) {
      if (!valid()) return Promise.reject(interrupted());
      if (at <= 0) { try { audio.currentTime = 0; } catch (_) {} return Promise.resolve(); }
      return new Promise((resolve, reject) => {
        let requested = false, complete = false, timeout;
        const events = ['loadedmetadata', 'durationchange', 'progress', 'canplay', 'seeked', 'timeupdate'];
        const done = error => { if (complete) return; complete = true; unschedule(timeout); events.forEach(name => audio.removeEventListener(name, check)); signal?.removeEventListener('abort', abort); error ? reject(error) : resolve(); };
        const abort = () => done(interrupted());
        const check = () => {
          if (!valid()) { abort(); return; }
          if (!Number.isFinite(audio.duration) || audio.duration <= 0 || at > audio.duration + .25) return;
          if (!requested) { try { audio.currentTime = Math.min(at, audio.duration); requested = true; } catch (_) { return; } }
          if (!audio.seeking && Math.abs(number(audio.currentTime) - at) <= .35) done();
        };
        events.forEach(name => audio.addEventListener(name, check)); signal?.addEventListener('abort', abort, { once: true });
        timeout = schedule(() => done(failure('audio_seek_unavailable', true)), options.seekTimeoutMs ?? 10000); check();
      });
    }
    async function ensureBegin(p, signal) {
      while (!p.sessionId) {
        if (signal?.aborted) throw interrupted();
        if (!p.beginTask) p.beginTask = (async () => { const result = await post(p, p.beginBody, signal); receipt(p, result, 0); mediaTicket(p, result); })().finally(() => { p.beginTask = null; });
        try { await p.beginTask; }
        catch (error) {
          // A new Tap Play can arrive while the previous request is aborting.
          // Replay the same begin identity after that task settles.
          if (error?.name !== 'AbortError' || signal?.aborted) throw error;
        }
      }
    }
    async function fetchRenewal(p, signal) {
      for (;;) {
        if (signal?.aborted) throw interrupted();
        if (!p.ticketTask) p.ticketTask = (async () => {
          // A timer and an explicit resume may share this request. Only one
          // renewal body is in flight, and a lost reply retains that exact body.
          for (let attempt = 0; attempt < 2; attempt++) {
            const body = p.renewBody ||= Object.freeze({ protocol, action: 'playback.renew', site_id: p.source.site_id, session_id: p.sessionId, request_id: uuid() });
            const result = await post(p, body, signal);
            if (result.request_id !== body.request_id) throw failure();
            receipt(p, result); mediaTicket(p, result); p.renewBody = null;
            if (p.media.ticket_expires_ms > wallClock() + 1000) { p.confirmationRequired = false; return; }
            // An exact replay may return an expired ticket. A genuinely later
            // renewal gets a new ID while preserving this session and asset.
          }
          throw failure('audio_network_error', true);
        })().finally(() => { p.ticketTask = null; });
        try { await p.ticketTask; return; }
        catch (error) { if (error?.name !== 'AbortError' || signal?.aborted) throw error; }
      }
    }
    async function applyMedia(p, at, valid, signal, resume) {
      if (!valid() || current !== p || p.failed || p.confirmationRequired) throw interrupted();
      mediaChanges++;
      try {
        if (audio.getAttribute?.('src') !== p.media.url || audio.error) {
          audio.pause(); audio.crossOrigin = 'anonymous'; audio.preload = 'metadata'; audio.src = p.media.url; audio.load();
        }
        await restore(at, valid, signal);
        if (!valid() || current !== p || p.failed) throw interrupted();
        if (resume && wantsAudio) {
          await audio.play();
          if (!valid() || current !== p) {
            if (current === p && !wantsAudio) audio.pause();
            throw interrupted();
          }
          playing();
        }
      } finally {
        mediaChanges--;
      }
    }
    async function play(track, settings = {}) {
      if (!supports(track) || !active()) throw failure();
      clearRenew(); const context = operationContext(settings); wantsAudio = true;
      const at = number(settings.position ?? audio.currentTime);
      try {
        if (!same(current, track) || current.closed) {
          finish(); audio.pause(); current = null;
          audio.removeAttribute?.('src'); audio.load();
          const expected = reference(track), result = await options.resolveSource(track, context.signal);
          if (!context.valid()) throw interrupted();
          const source = sourceDescriptor(result?.source || result, expected), playId = uuid(), playbackKey = key();
          const surface = ['player', 'agent', 'embed', 'canvas'].includes(settings.sourceSurface || options.sourceSurface) ? settings.sourceSurface || options.sourceSurface : 'player';
          current = { trackId: String(track.id ?? track.track_id), reference: expected, source, playId, key: playbackKey,
            created: clock(), started: false, state: 'pending', closed: false, failed: null, played: 0, seq: 0,
            acceptedSeq: 0, acceptedMs: 0, lastElapsed: 0, lastClock: clock(), lastPosition: milliseconds(at * 1000), lastRate: rate(), lastReport: clock(), partial: false,
            beginBody: Object.freeze({ protocol, action: 'playback.begin', site_id: source.site_id, recording_id: source.recording_id,
              play_id: playId, playback_key: playbackKey, source_surface: surface, source_url: page((typeof options.sourceUrl==='function'?options.sourceUrl():options.sourceUrl) || root.location?.href),
              position_ms: milliseconds(at * 1000), playback_rate: rate(), muted: !!audio.muted, loop: looping() }) };
        }
        const p = current; if (p.failed) throw p.failed;
        await ensureBegin(p, context.signal);
        if (!context.valid() || current !== p) throw interrupted();
        if (p.confirmationRequired || p.media.ticket_expires_ms <= wallClock() + 15000) {
          try { await fetchRenewal(p, context.signal); }
          catch (error) { if (error?.retryable === true) requireConfirmation(p, error); throw error; }
          await drain();
        }
        await applyMedia(p, at, context.valid, context.signal, true);
        scheduleRenew(); publish(); return safeState();
      } catch (error) {
        if (error?.name !== 'AbortError' && error?.name !== 'NotAllowedError' && error?.retryable === false && current) terminal(current, error);
        throw error;
      } finally { context.close(); }
    }
    async function renew() {
      const p = current;
      if (!active() || !p?.sessionId || p.closed || p.failed) throw p?.failed || failure();
      if (p.renewTask) return p.renewTask;
      const epoch = operation, valid = () => active() && current === p && !p.failed && operation === epoch;
      renewing = true;
      p.renewTask = (async () => {
        try {
          await fetchRenewal(p);
          await drain();
          if (!valid()) return safeState();
          const at = number(audio.currentTime), resume = wantsAudio && !audio.paused;
          sample(p); renewing = true; buffer();
          await applyMedia(p, at, valid, null, resume);
          scheduleRenew(); return safeState();
        } catch (error) {
          if (error?.retryable === false) terminal(p, error);
          else if (error?.name !== 'AbortError') requireConfirmation(p, error);
          throw error;
        } finally { renewing = false; p.renewTask = null; publish(); }
      })();
      return p.renewTask;
    }
    function scheduleRenew() {
      clearRenew(); const p = current;
      if (!active() || !p?.media || p.closed || p.failed || p.confirmationRequired || !wantsAudio || audio.paused) return;
      renewTimer = schedule(() => { renewTimer = null; void renew().catch(() => {}); }, Math.max(1000, p.media.ticket_expires_ms - wallClock() - 15000));
    }
    function pause() { cancelPending(); audio.pause(); publish(); }
    function stop() { cancelPending(); finish(); audio.pause(); current = null; publish(); }
    async function recover() {
      if (!current || current.closed || current.failed) throw current?.failed || failure();
      const p = current, epoch = operation, resume = wantsAudio, at = number(audio.currentTime) || current.lastPosition / 1000;
      await renew();
      if (resume && wantsAudio && active() && current === p && operation === epoch && !p.failed) {
        await applyMedia(p, at, () => active() && current === p && operation === epoch, null, true);
      }
      return safeState();
    }
    const handlers = { playing, timeupdate: progress, pause: paused, waiting: buffer, stalled: buffer, seeking, seeked, ended,
      ratechange: () => { const p = current; if (!p?.started || p.closed || p.failed) return; sample(p); enqueue(p, 'ratechange', audio.paused ? 'paused' : p.state === 'buffering' ? 'buffering' : 'playing'); },
      emptied: buffer };
    for (const [name, handler] of Object.entries(handlers)) audio.addEventListener(name, handler);
    const flush = () => { const p = current; if (p?.started && !p.failed && !p.closed && !p.confirmationRequired) { sample(p); enqueue(p, 'heartbeat', audio.paused ? 'paused' : p.state === 'playing' ? 'playing' : 'buffering'); } };
    root.addEventListener?.('pagehide', flush);
    return Object.freeze({ play, pause, stop, renew, recover, cancelPending, state: safeState, matches: track => same(current, track),
      isRenewing: () => renewing || mediaChanges > 0, flush,
      dispose() { if (disposed) return; stop(); disposed = true; clearRenew();
        root.removeEventListener?.('pagehide', flush); for (const [name, handler] of Object.entries(handlers)) audio.removeEventListener(name, handler); }
    });
  }
  const exported = Object.freeze({ protocol, supports, isProviderSource, hasPlayableSource, reference, sourceDescriptor, safeSourcePage: page, create });
  root.SFVP3Music = exported;
  if (typeof module !== 'undefined' && module.exports) module.exports = exported;
})(typeof window === 'undefined' ? globalThis : window);
