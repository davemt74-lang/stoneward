/* Section 91B11B — Browser-owned, opt-in player audio capture.
 * Ordinary capture remains entirely local. A second, separately authorized
 * recorder produces complete, decodable audio containers for live drafts.
 * Neither recorder ever captures the microphone.
 */
(() => {
  'use strict';
  const MAX_SECONDS = 90;
  const MAX_BYTES = 20 * 1024 * 1024;
  const mimeCandidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
  const position = audio => Math.max(0, Math.round((Number(audio?.currentTime) || 0) * 1000));
  function create({ audio, getTrack, onChange = () => {}, onSegment = () => {},
    now = () => new Date(), recorderFactory, maxSeconds = MAX_SECONDS, segmentSeconds = 10 } = {}) {
    if (!audio || typeof getTrack !== 'function') throw new Error('Song capture needs the real audio player.');
    const Record = recorderFactory || window.MediaRecorder;
    const maxMs = Math.min(MAX_SECONDS, Math.max(5, Number(maxSeconds) || MAX_SECONDS)) * 1000;
    const segmentMs = Math.min(20000, Math.max(2000, Number(segmentSeconds) * 1000 || 10000));
    let active = null;
    let lastCapture = null;
    const status = () => ({ active: !!active, live_enabled: !!active?.live?.enabled,
      track_id: active?.track_id || '',
      start_ms: active?.start_ms ?? null, last: lastCapture && {
        track_id: lastCapture.track_id, title: lastCapture.title,
        start_ms: lastCapture.start_ms, end_ms: lastCapture.end_ms,
        captured_at: lastCapture.captured_at, bytes: lastCapture.blob.size
      } });
    function begin() {
      if (active) return { ok: false, text: 'I am already capturing this song section.' };
      const track = getTrack();
      if (!track?.id || !track.audio || audio.paused || !audio.currentSrc && !audio.src)
        return { ok: false, text: 'Start playing a published song before asking me to listen.' };
      if (typeof Record !== 'function' || typeof (audio.captureStream || audio.mozCaptureStream) !== 'function')
        return { ok: false, text: 'This browser cannot capture the player audio. No recording was started.' };
      let stream, recorder;
      try {
        stream = (audio.captureStream || audio.mozCaptureStream).call(audio);
        if (!stream?.getAudioTracks?.().length) throw new Error('no-audio-track');
        const mime = mimeCandidates.find(type => Record.isTypeSupported?.(type));
        recorder = new Record(stream, mime ? { mimeType: mime } : undefined);
      } catch (_) {
        stream?.getTracks?.().forEach(t => t.stop());
        return { ok: false, text: 'The player audio stream is unavailable for capture. Playback is unchanged.' };
      }
      const session = {
        track_id: String(track.id), title: String(track.title || ''),
        release: String(track.release || ''), started_at: now().toISOString(),
        start_ms: position(audio), last_ms: position(audio), chunks: [],
        bytes: 0, stream, recorder, timer: null, stopped: false, live: null,
        resolve: null, reject: null, promise: null
      };
      session.promise = new Promise((resolve, reject) => { session.resolve = resolve; session.reject = reject; });
      // A failed capture is an expected runtime result, not an unhandled promise.
      session.promise.catch(() => {});
      recorder.ondataavailable = ev => {
        if (!ev.data?.size) return;
        session.bytes += ev.data.size;
        if (session.bytes > MAX_BYTES) {
          session.discard = true;
          if (recorder.state !== 'inactive') recorder.stop();
          return;
        }
        session.chunks.push(ev.data);
      };
      recorder.onerror = () => { session.discard = true; if (recorder.state !== 'inactive') recorder.stop(); };
      recorder.onstop = () => {
        clearTimeout(session.timer);
        session.stream?.getTracks?.().forEach(t => t.stop());
        if (active === session) active = null;
        if (session.discard || session.bytes > MAX_BYTES || session.last_ms <= session.start_ms) {
          onChange(status()); session.reject(new Error('The song section was not captured.')); return;
        }
        const blob = new Blob(session.chunks, { type: recorder.mimeType || 'application/octet-stream' });
        if (!blob.size) { onChange(status()); session.reject(new Error('No audio was captured.')); return; }
        lastCapture = Object.freeze({ track_id: session.track_id, title: session.title,
          release: session.release, started_at: session.started_at, captured_at: now().toISOString(),
          start_ms: session.start_ms, end_ms: session.last_ms, mime_type: blob.type, blob });
        onChange(status()); session.resolve(lastCapture);
      };
      try { recorder.start(1000); }
      catch (_) { session.stream?.getTracks?.().forEach(t => t.stop()); return { ok: false, text: 'The browser could not start song capture.' }; }
      // A fresh capture invalidates the previously heard excerpt; never
      // submit stale lyrics after starting a new verse.
      lastCapture = null;
      active = session;
      session.timer = setTimeout(() => { void stop('time_limit'); }, maxMs);
      onChange(status());
      return { ok: true, text: 'Listening to “' + session.title + '” from ' + Math.floor(session.start_ms / 1000) + ' seconds. Say “stop listening” when you are ready.' };
    }
    function startLiveSegment(session) {
      if (active !== session || session.stopped || session.discard || !session.live?.enabled) return false;
      let recorder;
      try {
        const mime = session.recorder.mimeType;
        recorder = new Record(session.stream, mime ? { mimeType: mime } : undefined);
      } catch (_) { return false; }
      const segment = { recorder, start_ms: position(audio), chunks: [], bytes: 0, timer: null };
      recorder.ondataavailable = ev => {
        if (!ev.data?.size) return;
        segment.bytes += ev.data.size;
        // A preview is expendable: the original 20 MB recording remains the
        // final source of truth even when a segment exceeds this limit.
        if (segment.bytes > 4 * 1024 * 1024) { segment.chunks=[]; return; }
        segment.chunks.push(ev.data);
      };
      recorder.onerror = () => {
        session.live.enabled = false;
        clearTimeout(segment.timer);
        if (recorder.state !== 'inactive') recorder.stop();
        onChange(status());
      };
      recorder.onstop = () => {
        clearTimeout(segment.timer);
        if (session.live?.current === segment) session.live.current = null;
        const end_ms = position(audio);
        if (!session.discard && !session.stopped && segment.chunks.length &&
            segment.bytes <= 4 * 1024 * 1024 && end_ms - segment.start_ms >= 500) {
          const blob = new Blob(segment.chunks, { type: recorder.mimeType || 'application/octet-stream' });
          // Callers decide whether a segment may leave the browser. The
          // recorder itself never performs a network request.
          try { Promise.resolve(onSegment(Object.freeze({
            track_id: session.track_id, title: session.title, release: session.release,
            captured_at: now().toISOString(), start_ms: segment.start_ms,
            end_ms, mime_type: blob.type, blob
          }))).catch(() => {}); } catch (_) { /* Preview is best-effort. */ }
        }
        if (session.live?.enabled && !session.stopped && !session.discard &&
            !startLiveSegment(session)) {
          session.live.enabled = false;
          onChange(status());
        }
      };
      try { recorder.start(1000); }
      catch (_) { return false; }
      session.live.current = segment;
      segment.timer = setTimeout(() => {
        if (recorder.state !== 'inactive') recorder.stop();
      }, segmentMs);
      return true;
    }
    function enableLive() {
      const session = active;
      if (!session || session.stopped) return { ok: false, text: 'Start listening to a song section before enabling live transcription.' };
      if (session.live?.enabled) return { ok: true, text: 'Live lyric transcription is already running.' };
      session.live = { enabled: true, current: null };
      if (!startLiveSegment(session)) {
        session.live = null;
        return { ok: false, text: 'Live captions are unavailable in this browser. Local song capture is still working.' };
      }
      onChange(status());
      return { ok: true, text: 'Live transcription started. Short sections of the player audio will be sent for provisional lyrics until listening stops.' };
    }
    async function stop(reason = 'user') {
      const session = active;
      if (!session) return { ok: false, text: lastCapture ? 'The last song section is already captured.' : 'I am not capturing a song section.' };
      if (session.stopped) return { ok: false, text: 'The song capture is already ending.' };
      session.stopped = true;
      session.last_ms = reason === 'seek' ? session.last_ms : position(audio);
      if (reason === 'switch' || reason === 'seek' || reason === 'error') session.discard = true;
      clearTimeout(session.timer);
      if (session.live) {
        session.live.enabled = false;
        const segment = session.live.current;
        if (segment) {
          clearTimeout(segment.timer);
          if (segment.recorder.state !== 'inactive') segment.recorder.stop();
        }
      }
      if (session.recorder.state !== 'inactive') session.recorder.stop();
      try {
        const capture = await session.promise;
        const seconds = ((capture.end_ms - capture.start_ms) / 1000).toFixed(1);
        return { ok: true, capture, text: 'Captured ' + seconds + ' seconds from “' + capture.title + '.” The audio is ready for lyric transcription.' };
      } catch (_) {
        return { ok: false, text: reason === 'seek' || reason === 'switch'
          ? 'Song capture was discarded because playback moved to another position or song.'
          : 'The browser could not capture that section. Playback was not changed.' };
      }
    }
    function playbackPosition() { if (active) active.last_ms = position(audio); }
    function discard() {
      lastCapture = null;
      if (active) { active.discard = true; void stop('error'); }
      onChange(status());
    }
    return Object.freeze({ begin, enableLive, stop, status, playbackPosition, discard,
      getCapture: () => lastCapture });
  }
  window.StonefellowSongCapture = Object.freeze({ version: '91B8A', MAX_SECONDS, create });
})();
