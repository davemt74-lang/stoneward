/* Catalog section selection and saved lyrics lookup.
 * Only catalog identity and playback times leave this controller.
 */
(() => {
  'use strict';
  const MAX_MS = 90000;
  const canceledReasons = new Set(['switch', 'seek', 'error', 'pagehide', 'navigation', 'cancel']);
  const position = audio => Number.isFinite(Number(audio?.currentTime))
    ? Math.max(0, Math.round(Number(audio.currentTime) * 1000)) : null;
  const message = error => String(error?.message || 'Saved lyrics could not be retrieved.');
  const savedText = text => typeof text === 'string' && text.length <= 4200 && !!text.trim() &&
    new TextEncoder().encode(text).length <= 4200;

  function create({ audio, getTrack, prepare, submit, onUpdate = () => {}, onFinish = () => {},
    now = () => new Date(), monotonic = () => performance.now(), crypto = window.crypto,
    eventTarget = window, maxSeconds = 90, requestTimeoutMs = 75000 } = {}) {
    if (!audio || typeof getTrack !== 'function' || typeof prepare !== 'function' || typeof submit !== 'function')
      throw new Error('Catalog lyric listening requires the current player and authenticated requests.');
    let active = null;
    let destroyed = false;
    const status = () => ({ active: !!active, mode: 'catalog', phase: active?.phase || '',
      track_id: active?.track_id || '', start_ms: active?.start_ms ?? null,
      request_id: active?.request_id || '', submitted: !!active?.submitted });
    const source = () => String(audio.currentSrc || audio.src || '');
    const matches = session => {
      const track = getTrack();
      return !!track && String(track.id) === session.track_id && String(track.audio || '') === session.audio_ref
        && source() === session.player_source;
    };
    function emit(session, text) {
      if (active !== session || session.ended) return;
      onUpdate({ track_id: session.track_id, title: session.title, start_ms: session.start_ms,
        end_ms: session.phase === 'transcribing' ? session.end_ms : session.last_ms,
        request_id: session.request_id, listening_mode: 'catalog', phase: session.phase,
        submitted: session.submitted, text: text || '' });
    }
    function finish(session, result) {
      if (session.ended) return session.done;
      session.ended = true;
      if (active === session) active = null;
      clearTimeout(session.deadline);
      clearTimeout(session.requestTimer);
      session.abort?.abort();
      const completed = { ...result, listening_mode: 'catalog', request_id: session.request_id };
      Promise.resolve().then(() => onFinish(completed)).then(
        text => session.resolve({ ...completed, text: typeof text === 'string' ? text : completed.text }),
        () => session.resolve(completed)
      );
      return session.done;
    }
    function discard(session, reason = 'cancel') {
      return finish(session, { ok: false, canceled: true, reason, receipt: null,
        text: 'Lyric selection stopped. No audio was sent and no transcription was charged.' });
    }
    function boundedRequest(session, operation, timeoutMs) {
      return new Promise((resolve, reject) => {
        let settled = false;
        const signal = session.abort?.signal;
        const settle = (fn, value) => {
          if (settled) return;
          settled = true; clearTimeout(timer);
          signal?.removeEventListener?.('abort', onAbort);
          if (session.requestTimer === timer) session.requestTimer = null;
          fn(value);
        };
        const onAbort = () => settle(reject, new Error('The lyric request was canceled.'));
        const timer = setTimeout(() => {
          settle(reject, new Error('The lyric request timed out. It was not automatically retried.'));
          session.abort?.abort();
        }, timeoutMs);
        session.requestTimer = timer;
        signal?.addEventListener?.('abort', onAbort, {once:true});
        if (signal?.aborted) { onAbort(); return; }
        Promise.resolve().then(() => {
          if (session.ended || signal?.aborted) throw new Error('The lyric request was canceled.');
          return operation();
        }).then(value => settle(resolve, value), error => settle(reject, error));
      });
    }
    function observe(session) {
      if (!matches(session) || audio.seeking) { void discard(session, 'seek'); return null; }
      const at = position(audio), clock = monotonic();
      if (at === null) { void discard(session, 'error'); return null; }
      const elapsed = Math.max(0, clock - session.last_clock);
      const rate = Math.max(0.1, Math.min(16, Number(audio.playbackRate) || 1));
      // A forward seek may precede its DOM event. A large unobserved jump must
      // never become a provider request for music the listener skipped.
      if (at + 150 < session.last_ms || at - session.last_ms > elapsed * Math.max(rate, session.last_rate) + 1500) {
        void discard(session, 'seek'); return null;
      }
      session.last_ms = at; session.last_clock = clock; session.last_rate = rate;
      return Math.min(at, session.start_ms + session.max_ms);
    }
    async function begin() {
      if (destroyed) return { ok: false, text: 'This lyric workspace is closed.' };
      if (active) return { ok: false, pending: true, text: 'A lyric session is already active. Use Stop to finish it.' };
      const track = getTrack(), start = position(audio);
      if (!track?.id || !track.audio || audio.paused || audio.seeking || !source() || start === null)
        return { ok: false, text: 'Play a published song before selecting Song Lyrics.' };
      if (typeof crypto?.getRandomValues !== 'function')
        return { ok: false, text: 'Secure lyric requests require a secure browser connection.' };
      const bytes = new Uint8Array(16); crypto.getRandomValues(bytes);
      const session = { request_id: Array.from(bytes, x => x.toString(16).padStart(2, '0')).join(''),
        track_id: String(track.id), title: String(track.title || ''), audio_ref: String(track.audio),
        player_source: source(), start_ms: start, last_ms: start, last_clock: monotonic(),
        last_rate: Number(audio.playbackRate) || 1, captured_at: now().toISOString(), phase: 'starting',
        submitted: false, ended: false, max_ms: Math.min(MAX_MS, Math.max(500, Number(maxSeconds) * 1000 || MAX_MS)),
        abort: typeof AbortController === 'function' ? new AbortController() : null };
      session.done = new Promise(resolve => { session.resolve = resolve; });
      active = session;
      emit(session, 'Checking saved song lyrics…');
      try {
        const ready = await boundedRequest(session, () => prepare(session.track_id, session.audio_ref, session.abort?.signal), 15000);
        if (active !== session || session.ended) return { ok: false, canceled: true, text: 'Lyric selection was canceled.' };
        if (!matches(session) || audio.paused || audio.seeking) {
          await discard(session, 'switch'); return { ok: false, canceled: true, text: 'Playback changed before lyric listening could start.' };
        }
        if (ready?.ok !== true || ready.available !== true) throw new Error(ready?.message || 'Saved lyrics are not available for this song yet.');
        if(typeof ready.source_fingerprint !== 'string' || !/^[a-f0-9]{64}$/.test(ready.source_fingerprint))
          throw new Error('The server could not verify the currently playing song source.');
        session.source_fingerprint = ready.source_fingerprint;
        // Start the selected interval after readiness, so a slow initial check
        // cannot silently add earlier audio to the listener's selection.
        session.start_ms = position(audio); session.last_ms = session.start_ms;
        session.last_clock = monotonic(); session.last_rate = Number(audio.playbackRate) || 1;
        session.captured_at = now().toISOString();
        session.max_ms = Math.min(session.max_ms, Math.max(500, Number(ready.max_seconds || 90) * 1000));
        session.phase = 'capturing';
        session.deadline = setTimeout(() => { void stop('time_limit'); }, session.max_ms + 1000);
        emit(session, 'Selecting this song section. Stop retrieves its saved lyrics; no audio is sent for transcription.');
        return { ok: true, mode: 'catalog', request_id: session.request_id,
          text: 'Selecting lyrics from “' + session.title + '.” Select Stop to retrieve the saved lyrics for this range.' };
      } catch (error) {
        if (active !== session || session.ended) return { ok: false, canceled: true, text: 'Lyric selection was canceled.' };
        const text = message(error);
        // Readiness failures are returned to the caller, not emitted as a
        // finished draft; the app can preserve the previous reviewed draft.
        session.ended = true; active = null; clearTimeout(session.requestTimer); session.abort?.abort();
        session.resolve({ ok: false, text, reason: 'unavailable' });
        return { ok: false, text };
      }
    }
    async function stop(reason = 'user') {
      const session = active;
      if (!session) return { ok: false, text: 'No lyric selection is active.' };
      if (canceledReasons.has(reason)) return discard(session, reason);
      if (session.phase === 'starting') return discard(session, 'cancel');
      if (session.phase === 'transcribing') return reason === 'user' ? discard(session, 'cancel') : session.done;
      const end = observe(session);
      if (active !== session || session.ended || end === null) return session.done;
      if (end - session.start_ms < 1000)
        return finish(session, { ok: false, receipt: null, reason: 'too_short', text: 'Listen for at least one second before selecting Stop. No audio was sent.' });
      session.end_ms = end; session.phase = 'transcribing'; session.submitted = true;
      clearTimeout(session.deadline);
      emit(session, 'Retrieving saved lyrics for this song section… No audio upload or transcription charge.');
      try {
        const payload = { track_id: session.track_id, start_ms: session.start_ms, end_ms: end,
          captured_at: session.captured_at, request_id: session.request_id,
          source_ref: session.audio_ref, source_fingerprint: session.source_fingerprint };
        const result = await boundedRequest(session, () => submit(payload, session.abort?.signal), requestTimeoutMs);
        if (active !== session || session.ended) return session.done;
        if (!matches(session)) return discard(session, 'switch');
        const draft = result?.transcript;
        const savedContract = draft?.source === 'saved-catalog-lyrics' && draft?.provider === 'stored' &&
          ['approved', 'corrected', 'unverified'].includes(draft.review_status) &&
          draft.selected_start_ms === session.start_ms && draft.selected_end_ms === end &&
          draft.start_ms === session.start_ms && draft.end_ms === end && draft.timing === 'saved_segments' && savedText(draft.text) &&
          Array.isArray(draft.segments) && draft.segments.length > 0 && draft.segments.length <= 100 &&
          draft.segments.every(segment => Number.isInteger(segment.start_ms) && Number.isInteger(segment.end_ms) &&
            segment.start_ms >= 0 && segment.end_ms > segment.start_ms && segment.start_ms < end &&
            segment.end_ms > session.start_ms && savedText(segment.text) && ['approved', 'corrected', 'unverified'].includes(segment.lyric_state) &&
            (draft.review_status !== 'approved' || segment.lyric_state === 'approved') &&
            ['whole_clip_estimate', 'catalog_chunk_estimate', 'manual', 'provider'].includes(segment.timing_source));
        if (result?.ok !== true || !savedContract || String(draft.track_id) !== session.track_id ||
            draft.source_fingerprint !== session.source_fingerprint ||
            draft.captured_at !== session.captured_at)
          throw new Error(result?.message || 'The server did not return a valid lyric draft for this song section.');
        return finish(session, { ok: true, receipt: Object.freeze({ ...draft }), reason,
          text: 'Saved lyrics ready. Review the words and their timing before interpretation.' });
      } catch (error) {
        if (active !== session || session.ended) return session.done;
        return finish(session, { ok: false, receipt: null, reason: 'error', error: message(error),
          text: message(error) + ' No automatic retry was made.' });
      }
    }
    const onProgress = () => {
      const session = active;
      if (!session || session.phase !== 'capturing') return;
      const at = observe(session);
      if (active !== session || at === null) return;
      emit(session);
      if (at - session.start_ms >= session.max_ms) void stop('time_limit');
    };
    const onSeek = () => { if (active) void stop('seek'); };
    const onError = () => { if (active) void stop('error'); };
    const onPause = () => { if (active && !audio.ended) void stop('pause'); };
    const onEnd = () => { if (active) void stop('end'); };
    const onHide = () => { if (active) void stop('pagehide'); };
    for (const [event, handler] of [['timeupdate', onProgress], ['seeking', onSeek], ['error', onError],
      ['emptied', onError], ['pause', onPause], ['ended', onEnd]]) audio.addEventListener?.(event, handler);
    eventTarget?.addEventListener?.('pagehide', onHide);
    function destroy() {
      destroyed = true;
      if (active) void discard(active, 'navigation');
      for (const [event, handler] of [['timeupdate', onProgress], ['seeking', onSeek], ['error', onError],
        ['emptied', onError], ['pause', onPause], ['ended', onEnd]]) audio.removeEventListener?.(event, handler);
      eventTarget?.removeEventListener?.('pagehide', onHide);
    }
    return Object.freeze({ begin, stop, status, destroy });
  }

  window.StonefellowCatalogListening = Object.freeze({ create });
})();
