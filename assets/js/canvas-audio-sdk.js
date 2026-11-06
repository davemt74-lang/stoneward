(() => {
  'use strict';

  const channel = 'stonefellow-canvas-audio';
  const version = 1;
  const maxBlobBytes = 128 * 1024 * 1024;
  const diagnosticLimit = 40;
  const diagnosticEvents = new Set(['session_ready', 'play_requested', 'rpc_error', 'local_playback_error', 'local_media_error', 'media_csp_violation', 'unhandled_rejection']);
  const diagnosticMethods = new Set(['catalog', 'getTrack', 'getAudio', 'play', 'pause', 'seek', 'setVolume', 'getState']);
  const diagnosticCodes = new Set(['audio_unavailable', 'audio_session_closed', 'audio_busy', 'audio_asset_released', 'invalid_audio_source',
    'invalid_catalog', 'invalid_audio_request', 'invalid_audio_state', 'invalid_track', 'track_unavailable', 'audio_gesture_required',
    'audio_source_unavailable', 'audio_network_error', 'audio_decode_error', 'audio_playback_interrupted', 'audio_seek_unavailable', 'audio_catalog_unavailable']);
  const diagnosticNames = new Set(['Error', 'TypeError', 'ReferenceError', 'SyntaxError', 'RangeError', 'EvalError', 'URIError',
    'NotAllowedError', 'NotSupportedError', 'AbortError', 'InvalidStateError', 'SecurityError', 'NetworkError', 'EncodingError',
    'NotReadableError', 'NotFoundError', 'OperationError', 'DataError', 'QuotaExceededError', 'DOMException']);
  function safeDiagnostic(value) {
    if (!value || !diagnosticEvents.has(value.event)) return null;
    const result = { event: value.event };
    if (diagnosticMethods.has(value.method)) result.method = value.method;
    if (diagnosticCodes.has(value.code)) result.code = value.code;
    if (diagnosticNames.has(value.name)) result.name = value.name;
    if (['catalog', 'selection', 'site_catalog', 'source', 'playback', 'resume_seek', 'session'].includes(value.stage)) result.stage = value.stage;
    if (Number.isInteger(value.media_code) && value.media_code >= 1 && value.media_code <= 4) result.media_code = value.media_code;
    if (['host_reference', 'blob', 'other'].includes(value.source_kind)) result.source_kind = value.source_kind;
    return Object.freeze(result);
  }
  const source = String.raw`(() => {
  'use strict';
  const channel = 'stonefellow-canvas-audio';
  const version = 1;
  const documentId = Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
  const pending = new Map();
  const readQueue = [];
  const controlQueue = [];
  const audioCache = new Map();
  const listeners = new Set();
  let nonce = null;
  let session = null;
  let ready = false;
  let closed = false;
  let sequence = 0;
  let activeReads = 0;
  let currentState = null;
  let diagnosticCount = 0;
  const diagnosticQueue = [];
  const observedMedia = new Map();
  const observedMediaErrors = new WeakSet();
  let mediaPrototype = null;
  let originalMediaPlay = null;
  let observedMediaPlay = null;
  const diagnosticNames = new Set(['Error', 'TypeError', 'ReferenceError', 'SyntaxError', 'RangeError', 'EvalError', 'URIError',
    'NotAllowedError', 'NotSupportedError', 'AbortError', 'InvalidStateError', 'SecurityError', 'NetworkError', 'EncodingError',
    'NotReadableError', 'NotFoundError', 'OperationError', 'DataError', 'QuotaExceededError', 'DOMException']);
  const diagnosticCodes = new Set(['audio_unavailable', 'audio_session_closed', 'audio_busy', 'audio_asset_released', 'invalid_audio_source',
    'invalid_catalog', 'invalid_audio_request', 'invalid_audio_state', 'invalid_track', 'track_unavailable', 'audio_gesture_required',
    'audio_source_unavailable', 'audio_network_error', 'audio_decode_error', 'audio_playback_interrupted', 'audio_seek_unavailable', 'audio_catalog_unavailable']);
  function sourceKind(value) {
    if (typeof value !== 'string' || !value) return 'other';
    if (/^blob:/i.test(value)) return 'blob';
    if (/^(?:https?:\/\/[^/]+\/)?(?:[^?#]*\/)?api\/(?:canvas-audio|media)\.php(?:[?#]|$)/i.test(value) ||
      /^(?:https?:\/\/[^/]+\/)?(?:assets|uploads)\/[^?#]*\.mp3(?:[?#]|$)/i.test(value)) return 'host_reference';
    return 'other';
  }
  function diagnostic(value) {
    if (closed || diagnosticCount >= 40) return;
    diagnosticCount++;
    if (!ready) diagnosticQueue.push(value);
    else post({ type: 'diagnostic', diagnostic: value });
  }
  function onLocalMediaError(event) {
    const media = event.target;
    if (typeof HTMLMediaElement === 'undefined' || !(media instanceof HTMLMediaElement)) return;
    if (observedMediaErrors.has(event)) return;
    observedMediaErrors.add(event);
    const code = media.error?.code;
    diagnostic({ event: 'local_media_error', ...(Number.isInteger(code) && code >= 1 && code <= 4 ? { media_code: code } : {}),
      source_kind: sourceKind(media.currentSrc || media.src) });
  }
  function onMediaCSP(event) {
    const directive = event.effectiveDirective || event.violatedDirective;
    if (typeof directive !== 'string' || directive.split(/\s/)[0] !== 'media-src') return;
    diagnostic({ event: 'media_csp_violation', source_kind: sourceKind(event.blockedURI) });
  }
  function onUnhandledRejection(event) {
    const reason = event.reason;
    diagnostic({ event: 'unhandled_rejection', ...(diagnosticNames.has(reason?.name) ? { name: reason.name } : {}),
      ...(diagnosticCodes.has(reason?.code) ? { code: reason.code } : {}) });
  }
  function localPlaybackFailure(media, reason) {
    diagnostic({ event: 'local_playback_error', ...(diagnosticNames.has(reason?.name) ? { name: reason.name } : {}),
      ...(diagnosticCodes.has(reason?.code) ? { code: reason.code } : {}),
      ...(Number.isInteger(media?.error?.code) && media.error.code >= 1 && media.error.code <= 4 ? { media_code: media.error.code } : {}),
      source_kind: sourceKind(media?.currentSrc || media?.src) });
  }
  function observeLocalPlayback() {
    if (typeof HTMLMediaElement === 'undefined') return;
    mediaPrototype = HTMLMediaElement.prototype;
    originalMediaPlay = mediaPrototype.play;
    if (typeof originalMediaPlay !== 'function') return;
    observedMediaPlay = function(...args) {
      const media = this;
      if (!closed && media instanceof HTMLMediaElement && !observedMedia.has(media) && observedMedia.size < 40) {
        media.addEventListener('error', onLocalMediaError);
        observedMedia.set(media, onLocalMediaError);
      }
      let result;
      try { result = originalMediaPlay.apply(media, args); }
      catch (reason) { localPlaybackFailure(media, reason); throw reason; }
      // Observe failures only; retain the exact native return value, sources,
      // timing and authored handlers. No retry or playback mutation occurs.
      try { if (result && typeof result.then === 'function') result.then(undefined, reason => { localPlaybackFailure(media, reason); }); } catch (_) {}
      return result;
    };
    try { mediaPrototype.play = observedMediaPlay; } catch (_) {}
  }
  function clearDiagnostics() {
    diagnosticQueue.length = 0;
    for (const [media, listener] of observedMedia) media.removeEventListener('error', listener);
    observedMedia.clear();
    if (mediaPrototype && mediaPrototype.play === observedMediaPlay) {
      try { mediaPrototype.play = originalMediaPlay; } catch (_) {}
    }
    removeEventListener('error', onLocalMediaError, true);
    removeEventListener('securitypolicyviolation', onMediaCSP);
    removeEventListener('unhandledrejection', onUnhandledRejection);
  }

  function failure(message, code = 'audio_unavailable') {
    const error = new Error(message);
    error.code = code;
    return error;
  }
  function validToken(value) {
    return typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
  }
  function trackId(value) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      const hasCanonical = Object.prototype.hasOwnProperty.call(value, 'track_id');
      const hasAlias = Object.prototype.hasOwnProperty.call(value, 'id');
      const canonical = hasCanonical ? value.track_id : undefined;
      const alias = hasAlias ? value.id : undefined;
      if (hasCanonical && hasAlias && canonical !== alias) throw failure('Choose one catalog track.', 'invalid_track');
      value = hasCanonical ? canonical : alias;
    }
    if (typeof value !== 'string' || !/^[A-Za-z0-9_-]{1,180}$/.test(value)) throw failure('Choose a track from the catalog.', 'invalid_track');
    return value;
  }
  function post(message) {
    parent.postMessage({ channel, version, documentId, nonce, session, ...message }, '*');
  }
  function clearAudio() {
    for (const entry of audioCache.values()) if (entry.url) URL.revokeObjectURL(entry.url);
    audioCache.clear();
  }
  function rejectPending(message) {
    for (const entry of pending.values()) entry.reject(failure(message, 'audio_session_closed'));
    for (const entry of readQueue) entry.reject(failure(message, 'audio_session_closed'));
    for (const entry of controlQueue) entry.reject(failure(message, 'audio_session_closed'));
    pending.clear();
    readQueue.length = 0;
    controlQueue.length = 0;
    activeReads = 0;
  }
  function updateState(state) {
    if (!state || typeof state !== 'object') return;
    currentState = Object.freeze({ ...state });
    for (const listener of listeners) {
      try { listener(currentState); } catch (_) {}
    }
  }
  function send(entry) {
    if (!ready || closed) return;
    entry.id = 'r-' + (++sequence);
    pending.set(entry.id, entry);
    if (entry.media) activeReads++;
    post({ type: 'request', id: entry.id, method: entry.method, args: entry.args });
  }
  function pumpReads() {
    while (ready && !closed && activeReads < 4 && readQueue.length) send(readQueue.shift());
  }
  function request(method, args = []) {
    if (closed) return Promise.reject(failure('Canvas audio is no longer available.', 'audio_session_closed'));
    const media = ['catalog', 'getTrack', 'getAudio'].includes(method);
    const controls = controlQueue.length + Array.from(pending.values()).filter(entry => !entry.media).length;
    if (!media && controls >= 16) return Promise.reject(failure('Too many audio controls are pending.', 'audio_busy'));
    return new Promise((resolve, reject) => {
      const entry = { method, args, resolve, reject, media };
      if (media) { readQueue.push(entry); pumpReads(); }
      else if (ready) send(entry);
      else controlQueue.push(entry);
    });
  }
  function onMessage(event) {
    const message = event.data;
    if (event.source !== parent || !message || message.channel !== channel || message.version !== version || closed) return;
    if (message.type === 'disconnect' && (!message.documentId || message.documentId === documentId)) {
      if (nonce && message.nonce !== nonce) return;
      if (session && message.session && message.session !== session) return;
      closed = true;
      ready = false;
      rejectPending('Canvas audio access ended.');
      clearAudio();
      listeners.clear();
      clearDiagnostics();
      return;
    }
    if (message.type === 'probe' && validToken(message.nonce)) {
      if (nonce && nonce !== message.nonce) {
        rejectPending('The canvas audio session changed.');
        clearAudio();
        currentState = null;
        session = null;
        ready = false;
      }
      nonce = message.nonce;
      post({ type: 'connect' });
      return;
    }
    if (message.documentId !== documentId || message.nonce !== nonce) return;
    if (message.type === 'hello' && validToken(message.session)) {
      if (session && session !== message.session) {
        rejectPending('The canvas audio session changed.');
        clearAudio();
      }
      session = message.session;
      ready = true;
      updateState(message.state);
      post({ type: 'ready' });
      while (diagnosticQueue.length) post({ type: 'diagnostic', diagnostic: diagnosticQueue.shift() });
      while (controlQueue.length) send(controlQueue.shift());
      pumpReads();
      return;
    }
    if (!ready || message.session !== session) return;
    if (message.type === 'state') {
      updateState(message.state);
      return;
    }
    if (message.type !== 'response' || typeof message.id !== 'string') return;
    const entry = pending.get(message.id);
    if (!entry) return;
    pending.delete(message.id);
    if (entry.media) activeReads--;
    if (message.ok === true) entry.resolve(message.value);
    else entry.reject(failure(message.error?.message || 'The audio request did not complete.', message.error?.code || 'audio_unavailable'));
    pumpReads();
  }
  function close() {
    if (closed) return;
    post({ type: 'unload' });
    closed = true;
    ready = false;
    rejectPending('The canvas audio session ended.');
    clearAudio();
    listeners.clear();
    clearDiagnostics();
    removeEventListener('message', onMessage);
  }

  const api = {
    catalog(filters = {}) {
      try {
        const captured = filters && typeof filters === 'object' && !Array.isArray(filters) ? { ...filters } : filters;
        return request('catalog', [captured]);
      } catch (error) { return Promise.reject(error); }
    },
    getTrack(id) {
      try { return request('getTrack', [trackId(id)]); }
      catch (error) { return Promise.reject(error); }
    },
    getAudio(id) {
      try { id = trackId(id); } catch (error) { return Promise.reject(error); }
      if (closed) return Promise.reject(failure('Canvas audio is no longer available.', 'audio_session_closed'));
      const cached = audioCache.get(id);
      if (cached) return cached.promise;
      const entry = { url: null, promise: null };
      entry.promise = request('getAudio', [id]).then(result => {
        if (closed || audioCache.get(id) !== entry) throw failure('The audio asset was released.', 'audio_asset_released');
        if (!(result?.blob instanceof Blob) || result.blob.size < 1 || result.blob.size > 128 * 1024 * 1024)
          throw failure('The audio source is unavailable or too large.', 'invalid_audio_source');
        entry.url = URL.createObjectURL(result.blob);
        return Object.freeze({ track: result.track, blob: result.blob, url: entry.url });
      }).catch(error => {
        if (audioCache.get(id) === entry) audioCache.delete(id);
        throw error;
      });
      audioCache.set(id, entry);
      return entry.promise;
    },
    play(id) {
      try { return request('play', arguments.length === 0 ? [] : [trackId(id)]); }
      catch (error) { diagnostic({ event: 'rpc_error', method: 'play', code: 'invalid_track', name: 'Error' }); return Promise.reject(error); }
    },
    pause() { return request('pause'); },
    seek(seconds) { return request('seek', [seconds]); },
    setVolume(value) { return request('setVolume', [value]); },
    getState() { return request('getState'); },
    releaseAudio(id) {
      try { id = trackId(id); } catch (error) { return Promise.reject(error); }
      const entry = audioCache.get(id);
      if (entry?.url) URL.revokeObjectURL(entry.url);
      audioCache.delete(id);
      return Promise.resolve(!!entry);
    },
    subscribe(listener) {
      if (typeof listener !== 'function') throw new TypeError('Audio state subscribers must be functions.');
      if (closed) return () => {};
      listeners.add(listener);
      if (currentState) listener(currentState);
      return () => listeners.delete(listener);
    }
  };
  Object.defineProperty(window, 'StonefellowAudio', { value: Object.freeze(api), configurable: false, writable: false });
  observeLocalPlayback();
  addEventListener('message', onMessage);
  addEventListener('error', onLocalMediaError, true);
  addEventListener('securitypolicyviolation', onMediaCSP);
  addEventListener('unhandledrejection', onUnhandledRejection);
  addEventListener('pagehide', close, { once: true });
  addEventListener('unload', close, { once: true });
  post({ type: 'connect' });
})();`;

  const hash = "'sha256-r50gknW0876XMqedAVN9F42tXzrUIaaerHIyys1SBbY='";

  function token() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
  }
  function record(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
  }
  function validTrackId(value) {
    return typeof value === 'string' && /^[A-Za-z0-9_-]{1,180}$/.test(value);
  }
  function error(message, code = 'audio_unavailable') {
    const result = new Error(message);
    result.code = code;
    return result;
  }
  function text(value, maximum = 300) {
    return typeof value === 'string' ? value.slice(0, maximum) : '';
  }
  function number(value, fallback = 0) {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : fallback;
  }
  function normalizeTrack(track) {
    if (!record(track) || !validTrackId(track.track_id)) throw error('The catalog returned an invalid track.', 'invalid_catalog');
    return {
      track_id: track.track_id,
      id: track.track_id,
      title: text(track.title),
      duration_seconds: number(track.duration_seconds),
      release_ids: Array.isArray(track.release_ids) ? track.release_ids.filter(validTrackId) : [],
      audio_url: text(track.audio_url, 2048),
      source_available: track.source_available === true,
      source_reason: text(track.source_reason),
      source_url: text(track.source_url, 2048)
    };
  }
  function normalizeCatalog(result) {
    if (!record(result) || !Array.isArray(result.tracks) || !Array.isArray(result.releases)) throw error('The audio catalog is unavailable.', 'invalid_catalog');
    return {
      tracks: result.tracks.map(normalizeTrack),
      releases: result.releases.map(release => {
        if (!record(release) || !validTrackId(release.release_id)) throw error('The catalog returned an invalid album.', 'invalid_catalog');
        return { release_id: release.release_id, id: release.release_id, title: text(release.title), track_ids: Array.isArray(release.track_ids) ? release.track_ids.filter(validTrackId) : [] };
      })
    };
  }
  function validateArgs(method, args) {
    if (!Array.isArray(args)) throw error('Invalid audio request arguments.', 'invalid_audio_request');
    switch (method) {
      case 'catalog': {
        if (args.length !== 1 || !record(args[0]) || Object.keys(args[0]).some(key => !['track_id', 'release_id'].includes(key)))
          throw error('Use track_id or release_id to filter the catalog.', 'invalid_audio_request');
        const filters = {};
        for (const key of Object.keys(args[0])) {
          if (!validTrackId(args[0][key])) throw error('Choose a track or album from the catalog.', 'invalid_track');
          filters[key] = args[0][key];
        }
        return [filters];
      }
      case 'getTrack':
      case 'getAudio':
        if (args.length !== 1 || !validTrackId(args[0])) throw error('Choose a track from the catalog.', 'invalid_track');
        return args;
      case 'play':
        if (args.length > 1 || (args.length === 1 && !validTrackId(args[0]))) throw error('Choose a track from the catalog.', 'invalid_track');
        return args;
      case 'pause':
      case 'getState':
        if (args.length !== 0) throw error('Invalid audio control arguments.', 'invalid_audio_request');
        return args;
      case 'seek':
        if (args.length !== 1 || !Number.isFinite(args[0]) || args[0] < 0) throw error('Seek to a valid number of seconds.', 'invalid_audio_request');
        return args;
      case 'setVolume':
        if (args.length !== 1 || !Number.isFinite(args[0]) || args[0] < 0 || args[0] > 1) throw error('Volume must be between zero and one.', 'invalid_audio_request');
        return args;
      default: throw error('This audio operation is unavailable.', 'invalid_audio_request');
    }
  }

  function createHost(options) {
    const { frame, audio, getOwner, canUse, loadCatalog, loadAudio, play, pause, seek, setVolume, getState, onDiagnostic } = options;
    if (!frame || !audio || [getOwner, canUse, loadCatalog, loadAudio, play, pause, seek, setVolume].some(callback => typeof callback !== 'function'))
      throw new TypeError('Canvas audio requires a frame, the canonical audio element and host callbacks.');
    const owner = getOwner();
    const nonce = token();
    let current = null;
    let destroyed = false;
    let revoked = false;
    let diagnosticCount = 0;
    const events = ['play', 'pause', 'timeupdate', 'seeking', 'seeked', 'durationchange', 'loadedmetadata', 'volumechange', 'emptied', 'ended'];

    function post(message) {
      try { frame.contentWindow?.postMessage({ channel, version, nonce, ...message }, '*'); } catch (_) {}
    }
    function abortSession(session, notify) {
      if (!session || session.closed) return;
      session.closed = true;
      for (const job of session.jobs.values()) job.controller.abort();
      session.jobs.clear();
      session.queue.length = 0;
      session.tracks.clear();
      if (notify) post({ type: 'disconnect', documentId: session.documentId, session: session.id });
      if (current === session) current = null;
    }
    function authorized() {
      if (destroyed || revoked) return false;
      let allowed = false;
      try { allowed = Object.is(owner, getOwner()) && canUse() === true; } catch (_) {}
      if (!allowed) {
        revoked = true;
        abortSession(current, true);
        post({ type: 'disconnect' });
      }
      return allowed;
    }
    function active(session) {
      return authorized() && current === session && !session.closed;
    }
    function snapshot() {
      const supplied = typeof getState === 'function' ? getState() : {};
      if (supplied && typeof supplied.then === 'function') throw error('The host audio state must be synchronous.', 'invalid_audio_state');
      const state = record(supplied) ? supplied : {};
      return {
        trackId: validTrackId(state.trackId) ? state.trackId : null,
        title: text(state.title),
        currentTime: number(state.currentTime, number(audio.currentTime)),
        duration: number(state.duration, number(audio.duration)),
        playing: typeof state.playing === 'boolean' ? state.playing : !audio.paused && !audio.ended,
        volume: Math.min(1, number(state.volume, number(audio.volume, 1))),
        muted: typeof state.muted === 'boolean' ? state.muted : !!audio.muted,
        ended: typeof state.ended === 'boolean' ? state.ended : !!audio.ended
      };
    }
    function refresh() {
      if (!authorized()) return;
      if (!current) { post({ type: 'probe' }); return; }
      try { post({ type: 'state', documentId: current.documentId, session: current.id, state: snapshot() }); } catch (_) {}
    }
    function bootstrap() {
      if (destroyed) return;
      if (!authorized()) { post({ type: 'disconnect' }); return; }
      post({ type: 'probe' });
    }
    function reportDiagnostic(session, value) {
      if (!active(session) || diagnosticCount >= diagnosticLimit || typeof onDiagnostic !== 'function') return;
      const safe = safeDiagnostic(value);
      if (!safe) return;
      diagnosticCount++;
      try { onDiagnostic(safe); } catch (_) { /* Diagnostics cannot interrupt playback. */ }
    }
    function response(session, id, value, failure, method) {
      if (!active(session)) return;
      const code = failure && diagnosticCodes.has(failure.code) ? failure.code : 'audio_unavailable';
      if (failure) reportDiagnostic(session, { event: 'rpc_error', method, code, name: failure.name,
        media_code: failure.media_code, source_kind: failure.source_kind, stage: failure.stage });
      post({ type: 'response', documentId: session.documentId, session: session.id, id,
        ok: !failure, ...(failure ? { error: { code, message: 'The audio request could not be completed.' } } : { value }) });
    }
    async function catalog(session, filters, signal) {
      const received = await loadCatalog(filters, signal);
      if (!active(session)) throw error('Canvas audio access ended.', 'audio_session_closed');
      const result = normalizeCatalog(received);
      for (const track of result.tracks) session.tracks.set(track.track_id, track);
      return result;
    }
    async function operation(session, job) {
      if (!active(session)) throw error('Canvas audio access ended.', 'audio_session_closed');
      const [argument] = job.args;
      switch (job.method) {
        case 'catalog': return catalog(session, argument, job.controller.signal);
        case 'getTrack': {
          const result = await catalog(session, { track_id: argument }, job.controller.signal);
          const track = result.tracks.find(item => item.track_id === argument);
          if (!track) throw error('This track is unavailable.', 'track_unavailable');
          return track;
        }
        case 'getAudio': {
          const result = await loadAudio(argument, job.controller.signal);
          if (!active(session)) throw error('Canvas audio access ended.', 'audio_session_closed');
          const blob = result instanceof Blob ? result : result?.blob;
          if (!(blob instanceof Blob) || blob.size < 1 || blob.size > maxBlobBytes) throw error('The audio source is unavailable or too large.', 'invalid_audio_source');
          let track = result?.track ? normalizeTrack(result.track) : session.tracks.get(argument);
          if (!track) track = (await catalog(session, { track_id: argument }, job.controller.signal)).tracks.find(item => item.track_id === argument);
          if (!track || track.track_id !== argument || !track.source_available) throw error('This track source is unavailable.', 'track_unavailable');
          return { track, blob };
        }
        case 'play': await play(argument); break;
        case 'pause': await pause(); break;
        case 'seek': await seek(argument); break;
        case 'setVolume': await setVolume(argument); break;
        case 'getState': return snapshot();
      }
      if (!active(session)) throw error('Canvas audio access ended.', 'audio_session_closed');
      refresh();
      return snapshot();
    }
    function pump(session) {
      while (active(session) && session.mediaActive < 4 && session.queue.length) execute(session, session.queue.shift(), true);
    }
    function execute(session, job, media) {
      if (media) session.mediaActive++;
      else session.controlsActive++;
      Promise.resolve().then(() => operation(session, job)).then(
        value => response(session, job.id, value),
        failure => response(session, job.id, null, failure || error('The audio request did not complete.'), job.method)
      ).finally(() => {
        session.jobs.delete(job.id);
        if (media) session.mediaActive--;
        else session.controlsActive--;
        pump(session);
      });
    }
    function onMessage(event) {
      const message = event.data;
      if (destroyed || event.origin !== 'null' || event.source !== frame.contentWindow || !record(message) || message.channel !== channel || message.version !== version) return;
      if (message.type === 'connect') {
        if (!/^[a-f0-9]{32}$/.test(message.documentId || '')) return;
        if (!authorized()) { post({ type: 'disconnect', documentId: message.documentId }); return; }
        if (message.nonce !== nonce) { bootstrap(); return; }
        if (!current || current.documentId !== message.documentId) {
          abortSession(current, true);
          current = { id: token(), documentId: message.documentId, closed: false, lastRequest: 0,
            jobs: new Map(), queue: [], mediaActive: 0, controlsActive: 0, tracks: new Map(), readyReported: false };
        }
        try { post({ type: 'hello', documentId: current.documentId, session: current.id, state: snapshot() }); }
        catch (_) { abortSession(current, true); }
        return;
      }
      const session = current;
      if (!session || !active(session) || message.nonce !== nonce || message.session !== session.id || message.documentId !== session.documentId) return;
      if (message.type === 'diagnostic') { reportDiagnostic(session, message.diagnostic); return; }
      if (message.type === 'ready') {
        if (!session.readyReported) { session.readyReported = true; reportDiagnostic(session, { event: 'session_ready' }); }
        refresh(); return;
      }
      if (message.type === 'unload') { abortSession(session, false); return; }
      if (message.type !== 'request' || typeof message.id !== 'string' || !/^r-[1-9][0-9]{0,15}$/.test(message.id)) return;
      const sequence = Number(message.id.slice(2));
      if (!Number.isSafeInteger(sequence) || sequence <= session.lastRequest) return;
      session.lastRequest = sequence;
      let args;
      try { args = validateArgs(message.method, message.args); }
      catch (failure) { response(session, message.id, null, failure, message.method); return; }
      if (message.method === 'play') reportDiagnostic(session, { event: 'play_requested', method: 'play' });
      const media = ['catalog', 'getTrack', 'getAudio'].includes(message.method);
      if ((media && session.queue.length >= 32) || (!media && session.controlsActive >= 16)) {
        response(session, message.id, null, error('Too many audio requests are pending.', 'audio_busy'), message.method);
        return;
      }
      const job = { id: message.id, method: message.method, args, controller: new AbortController() };
      session.jobs.set(job.id, job);
      if (media) { session.queue.push(job); pump(session); }
      else execute(session, job, false);
    }
    function destroy() {
      if (destroyed) return;
      abortSession(current, true);
      post({ type: 'disconnect' });
      destroyed = true;
      window.removeEventListener('message', onMessage);
      frame.removeEventListener('load', bootstrap);
      for (const event of events) audio.removeEventListener(event, refresh);
    }

    window.addEventListener('message', onMessage);
    frame.addEventListener('load', bootstrap);
    for (const event of events) audio.addEventListener(event, refresh);
    bootstrap();
    return Object.freeze({ destroy, refresh });
  }

  window.SFCanvasAudioSDK = Object.freeze({ source, hash, createHost });
})();
