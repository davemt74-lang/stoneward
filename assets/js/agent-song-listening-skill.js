/* Section 91B13B — Ephemeral player-track speech recognition.
 * Listen & Interpret does not create a MediaRecorder, Blob or stored clip.
 * The browser may send the player's track to its online speech service.
 * Record Clip remains independently owned by agent-song-capture-skill.js.
 */
(() => {
  'use strict';
  const MAX_SECONDS = 90;
  const position = audio => Math.max(0, Math.round((Number(audio?.currentTime) || 0) * 1000));
  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();

  // Older implementations silently ignore start(audioTrack) and listen to the
  // microphone. Allow only desktop engines with documented audioTrack support.
  function supportsPlayerTrack(env = window) {
    const ua = String(env.navigator?.userAgent || '');
    if (/Android|iPhone|iPad|iPod|Mobile/i.test(ua)) return false;
    const edge = ua.match(/Edg\/(\d+)/);
    const opera = ua.match(/OPR\/(\d+)/);
    const chrome = ua.match(/Chrome\/(\d+)/);
    const supported = edge ? Number(edge[1]) >= 135
      : opera ? Number(opera[1]) >= 120
      : chrome && !/Safari\/.*Version\/|Firefox\/|FxiOS\/|CriOS\//.test(ua) && Number(chrome[1]) >= 135;
    return !!(supported && typeof (env.SpeechRecognition || env.webkitSpeechRecognition) === 'function');
  }

  function create({ audio, getTrack, onUpdate = () => {}, onFinish = () => {},
    now = () => new Date(), Recognition = window.SpeechRecognition || window.webkitSpeechRecognition,
    supports = () => supportsPlayerTrack(window), maxSeconds = MAX_SECONDS } = {}) {
    if (!audio || typeof getTrack !== 'function') throw new Error('Player listening needs the existing song player.');
    let active = null;
    const status = () => ({ active: !!active, track_id: active?.track_id || '',
      start_ms: active?.start_ms ?? null });
    function begin() {
      if (active) return { ok: false, text: 'Already listening to the current song. Select Stop listening first.' };
      const song = getTrack();
      if (!song?.id || !song.audio || audio.paused || !(audio.currentSrc || audio.src))
        return { ok: false, text: 'Play a published song before selecting Listen & Interpret.' };
      if (!supports() || typeof Recognition !== 'function' ||
          typeof (audio.captureStream || audio.mozCaptureStream) !== 'function')
        return { ok: false, text: 'Player-track live recognition needs a supported desktop Chrome, Edge or Opera browser. No recording or microphone listening was started.' };
      let stream, audioTrack, recognizer;
      try {
        stream = (audio.captureStream || audio.mozCaptureStream).call(audio);
        audioTrack = stream?.getAudioTracks?.()[0];
        if (!audioTrack || audioTrack.kind !== 'audio' || audioTrack.readyState !== 'live')
          throw new Error('no live player audio track');
        recognizer = new Recognition();
      } catch (_) {
        stream?.getTracks?.().forEach(track => track.stop());
        return { ok: false, text: 'The player audio track is unavailable for live recognition. Nothing was recorded.' };
      }
      const session = {
        track_id: String(song.id), title: String(song.title || ''), release: String(song.release || ''),
        start_ms: position(audio), captured_at: now().toISOString(), stream, audioTrack,
        recognizer, sentences: [], interim: '', seen: new Set(), timer: null,
        restartTimer: null, finishTimer: null, stopping: false, ended: false, reason: '',
        resolve: null, done: null
      };
      session.done = new Promise(resolve => { session.resolve = resolve; });
      active = session;
      function emit() {
        if (active !== session || session.ended) return;
        onUpdate({ track_id: session.track_id, title: session.title,
          start_ms: session.start_ms, end_ms: position(audio),
          text: session.sentences.join(' '), interim: session.interim });
      }
      function finish(reason) {
        if (active !== session || session.ended) return;
        session.ended = true;
        active = null;
        clearTimeout(session.timer); clearTimeout(session.restartTimer); clearTimeout(session.finishTimer);
        try { recognizer.abort(); } catch (_) {}
        stream.getTracks?.().forEach(track => track.stop());
        const text = clean(session.sentences.join(' ')).slice(0, 4200);
        const endMs = Math.min(session.start_ms + 90000, position(audio));
        const discard = ['switch', 'seek', 'error'].includes(reason);
        const receipt = !discard && text && endMs - session.start_ms >= 500 ? Object.freeze({
          track_id: session.track_id, title: session.title, release: session.release,
          start_ms: session.start_ms, end_ms: endMs,
          captured_at: session.captured_at, text, review_status: 'unverified',
          provider: 'browser-speech-recognition'
        }) : null;
        const result = { ok: !!receipt, receipt,
          reason, error: reason === 'error' ? session.reason : '',
          text: discard ? 'Listening ended because playback changed or recognition failed. No clip was saved.'
            : receipt ? 'Listening finished. Reviewing the unverified lyrics…'
              : 'Listening stopped, but the browser did not recognize clear lyrics in that section. No clip was saved.' };
        Promise.resolve().then(() => onFinish(result)).then(
          message => session.resolve({ ...result, text: typeof message === 'string' ? message : result.text }),
          () => session.resolve(result)
        );
      }
      function run() {
        if (active !== session || session.stopping || session.ended) return;
        session.seen = new Set();
        try {
          // Always pass the player's audioTrack. NEVER fall back to start():
          // a no-argument call can activate the visitor's microphone.
          recognizer.start(audioTrack);
        } catch (_) {
          session.reason = 'The browser could not start player-track recognition.';
          finish('error');
        }
      }
      recognizer.continuous = true;
      recognizer.interimResults = true;
      recognizer.lang = document.documentElement.lang || 'en-US';
      if ('processLocally' in recognizer) recognizer.processLocally = false;
      recognizer.onresult = event => {
        if (active !== session || session.ended) return;
        let interim = '';
        for (let i = Math.max(0, Number(event.resultIndex) || 0); i < event.results.length; i++) {
          const item = event.results[i];
          const phrase = clean(item?.[0]?.transcript);
          if (!phrase) continue;
          if (item.isFinal) {
            if (!session.seen.has(i)) {
              session.seen.add(i);
              session.sentences.push(phrase);
            }
          } else interim += ' ' + phrase;
        }
        session.interim = clean(interim);
        emit();
      };
      recognizer.onerror = event => {
        if (session.stopping || session.ended || active !== session) return;
        const code = String(event?.error || 'recognition-error');
        if (code === 'no-speech' || code === 'aborted') return;
        session.reason = 'Player-track speech recognition: ' + code + '.';
        finish('error');
      };
      recognizer.onend = () => {
        if (session.ended || active !== session) return;
        if (session.stopping) { finish(session.reason || 'user'); return; }
        if (audio.paused || !getTrack() || String(getTrack().id) !== session.track_id) {
          finish('switch'); return;
        }
        session.restartTimer = setTimeout(run, 240);
      };
      session.timer = setTimeout(() => { void stop('time_limit'); },
        Math.max(5, Math.min(MAX_SECONDS, Number(maxSeconds) || MAX_SECONDS)) * 1000);
      run();
      if (active !== session) return { ok: false, text: session.reason || 'Could not start player-track recognition.' };
      emit();
      return { ok: true, text: 'Listening to “' + session.title +
        '” through the player (browser speech service; no clip saved). Select Stop listening for Claude interpretation.' };
    }
    async function stop(reason = 'user') {
      const session = active;
      if (!session) return { ok: false, text: 'No player-track listening session is active.' };
      if (session.stopping) return session.done;
      session.stopping = true;
      session.reason = reason;
      clearTimeout(session.timer); clearTimeout(session.restartTimer);
      if (['switch', 'seek', 'error'].includes(reason)) {
        // No partial lyrics from another song or timestamp are eligible.
        try { session.recognizer.abort(); } catch (_) {}
      } else {
        try { session.recognizer.stop(); } catch (_) {}
      }
      session.finishTimer = setTimeout(() => {
        if (active === session) {
          // Some recognizers never emit 'end' after stop. End via the
          // already registered handler without recording or reactivating.
          session.recognizer.onend?.();
        }
      }, 1800);
      return session.done;
    }
    return Object.freeze({ begin, stop, status, supportsPlayerTrack: supportsPlayerTrack });
  }
  window.StonefellowSongListening = Object.freeze({ create, supportsPlayerTrack });
})();
