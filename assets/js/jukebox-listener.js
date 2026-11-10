'use strict';

(() => {
  const staleAfterMs = 6000;
  const staleTransportMs = 5000;
  const initialDriftSeconds = 0.15;
  const hardDriftSeconds = 0.7;
  const quietDriftSeconds = 0.12;
  const seekCooldownMs = 1000;
  const initialBufferSeconds = 3;
  const maxBufferSeconds = 6;
  const stalledBufferSeconds = 1;
  const bufferPatienceMs = 8000;
  const maxActivityGapMs = 2000;
  const activityToleranceMs = 150;

  function create({audio, urlForItem, onChange, onActivity}) {
    let snapshot = null;
    let observedAt = 0;
    let listening = false;
    let itemId = '';
    let seekOnStart = false;
    let lastSeekAt = -Infinity;
    let playAttempt = null;
    let blocked = '';
    let status = '';
    let connection = 'idle';
    let networkAvailable = true;
    let awaitingUpdate = false;
    let freshAfter = -Infinity;
    let retryOnUpdate = false;
    let localBuffering = false;
    let bufferUnderruns = 0;
    let bufferStartedAt = 0;
    let bufferReanchored = false;
    let hasPlayed = false;
    let lastMediaPosition = 0;
    let muteIntent = Boolean(audio.muted);
    let previousView = '';
    let freshnessTimer;
    let activity = null;

    function report(message = status, nextConnection = connection) {
      status = message;
      connection = nextConnection;
      const view = {
        allowed: Boolean(snapshot?.allowed), listening, muted: muteIntent || audio.muted,
        needsGesture: Boolean(blocked), status, connection,
        resyncing: listening && awaitingUpdate,
        canResync: Boolean(snapshot?.allowed) && listening
      };
      const key = JSON.stringify(view);
      if (key !== previousView) {
        previousView = key;
        onChange(view);
      }
    }

    function endActivity() {
      const previous = activity;
      activity = null;
      if (previous?.active) onActivity({
        itemId: previous.itemId, generation: previous.generation, advancedMs: 0, active: false
      });
    }

    function measureActivity() {
      if (!onActivity) return;
      const now = performance.now();
      const positionMs = audio.currentTime * 1000;
      if (!listening || !snapshot || !networkAvailable || awaitingUpdate || blocked || localBuffering || !itemId || itemId !== snapshot.itemId || !snapshot.allowed || !snapshot.online ||
          snapshot.transport !== 'playing' || !Number.isSafeInteger(snapshot.generation) || snapshot.generation < 0 ||
          freshForMs() <= 0 || audio.paused || audio.ended || audio.seeking ||
          muteIntent || audio.muted || audio.volume === 0 || audio.readyState < 2 || !Number.isFinite(positionMs)) {
        endActivity();
        return;
      }

      if (activity) {
        const elapsedMs = now - activity.at;
        const advancedMs = positionMs - activity.positionMs;
        if (elapsedMs <= maxActivityGapMs && advancedMs >= 0 && advancedMs <= elapsedMs + activityToleranceMs) {
          activity.at = now;
          activity.positionMs = positionMs;
          const measuredMs = Math.floor(Math.min(elapsedMs, advancedMs));
          if (measuredMs > 0) {
            activity.active = true;
            onActivity({itemId, generation: activity.generation, advancedMs: measuredMs, active: true});
          }
          return;
        }
        endActivity();
      }

      activity = {itemId, generation: snapshot.generation, at: now, positionMs, active: false};
      onActivity({itemId, generation: activity.generation, advancedMs: 0, active: false, baseline: true});
    }

    function setRate(rate) {
      if (Math.abs(audio.playbackRate - rate) >= 0.005) {
        try { audio.playbackRate = rate; } catch {}
      }
    }

    function applyMuteIntent() {
      // WebKit can reset the native mute property while restoring a media source.
      // Never clear a native/system mute without an explicit Unmute action.
      if (!muteIntent) return;
      if (!audio.defaultMuted) audio.defaultMuted = true;
      if (!audio.muted) audio.muted = true;
    }

    function pause() {
      endActivity();
      playAttempt = null;
      if (!audio.paused) audio.pause();
      setRate(1);
      seekOnStart = true;
    }

    function clearBufferWait() {
      localBuffering = false;
      bufferStartedAt = 0;
      bufferReanchored = false;
    }

    function clearSource() {
      pause();
      clearBufferWait();
      hasPlayed = false;
      if (!itemId) return;
      itemId = '';
      lastSeekAt = -Infinity;
      audio.removeAttribute('src');
      audio.load();
      applyMuteIntent();
    }

    function stop() {
      clearTimeout(freshnessTimer);
      listening = false;
      blocked = '';
      bufferUnderruns = 0;
      audio.preload = 'none';
      clearSource();
      report(snapshot?.allowed ? 'Ready to listen on this phone.' : 'Remote listening is unavailable.', snapshot?.allowed ? 'idle' : 'unavailable');
    }

    function sampleAgeMs() {
      return snapshot.sampleAgeMs + performance.now() - observedAt;
    }

    function freshForMs() {
      const responseFreshFor = staleAfterMs - (performance.now() - observedAt);
      return snapshot.online && snapshot.transport === 'playing' ? Math.min(responseFreshFor, staleTransportMs - sampleAgeMs()) : responseFreshFor;
    }

    function playbackPermitted() {
      return listening && snapshot?.allowed && networkAvailable && !awaitingUpdate && !blocked && !localBuffering &&
        snapshot.online && snapshot.transport === 'playing' && itemId && itemId === snapshot.itemId && freshForMs() > 0;
    }

    function bufferedAhead(position) {
      // A later range does not make a hole around the live playhead playable.
      // Read native ranges each time: browsers can evict previously cached data.
      if (!Number.isFinite(position)) return 0;
      try {
        const ranges = audio.buffered;
        if (!ranges) return 0;
        for (let index = 0; index < ranges.length; index++) {
          const end = ranges.end(index);
          if (ranges.start(index) <= position && end > position) return end - position;
        }
      } catch {}
      return 0;
    }

    function bufferReady(position, seconds) {
      const remaining = Number.isFinite(audio.duration) ? Math.max(0, audio.duration - position) : seconds;
      const ahead = bufferedAhead(position);
      return ahead > 0 && ahead + 0.025 >= Math.min(seconds, remaining);
    }

    function bufferGoal() {
      return Math.min(maxBufferSeconds, initialBufferSeconds + Math.max(0, bufferUnderruns - 1) * 1.5);
    }

    function alignToHost(position) {
      if (audio.readyState < 1) {
        // Seed the media element's default start position before the gesture-bound play call.
        if (seekOnStart && !playAttempt) {
          seekOnStart = false;
          if (Math.abs(audio.currentTime - position) > initialDriftSeconds) {
            try { audio.currentTime = position; } catch { seekOnStart = true; }
          }
        }
        return;
      }
      if (audio.seeking) return;
      const target = Number.isFinite(audio.duration) ? Math.min(position, audio.duration) : position;
      const drift = target - audio.currentTime;
      const magnitude = Math.abs(drift);
      const now = performance.now();
      const cachedRecoveryTarget = seekOnStart && bufferedAhead(audio.currentTime) === 0 && bufferedAhead(target) > 0;
      const initialSeek = seekOnStart && (magnitude > initialDriftSeconds || (magnitude > 0 && cachedRecoveryTarget));
      if (initialSeek || (magnitude > hardDriftSeconds && now - lastSeekAt >= seekCooldownMs && bufferReady(target, stalledBufferSeconds))) {
        setRate(1);
        endActivity();
        audio.currentTime = target;
        lastSeekAt = now;
      } else {
        // Correct small differences without an audible jump or repeated network seeks.
        const lowBuffer = drift > 0 && bufferedAhead(audio.currentTime) < stalledBufferSeconds;
        setRate(magnitude <= quietDriftSeconds || lowBuffer ? 1 : 1 + Math.max(-0.04, Math.min(0.04, drift * 0.08)));
      }
      seekOnStart = false;
    }

    function showBlocked() {
      report(blocked === 'gesture' ? 'Tap to continue listening.' : 'Audio could not load. Tap to retry.',
        blocked === 'gesture' ? 'action-required' : 'error');
    }

    function sync() {
      applyMuteIntent();
      if (!snapshot?.allowed) {
        stop();
        return;
      }
      if (!networkAvailable || awaitingUpdate) {
        pause();
        report(networkAvailable ? 'Reconnecting to the session…' : 'Connection lost. Reconnecting when this phone is online.', 'reconnecting');
        return;
      }
      if (!listening) {
        report('Ready to listen on this phone.', 'idle');
        return;
      }
      if (freshForMs() <= 0) {
        pause();
        report('Waiting for a fresh host playback update.', 'reconnecting');
        return;
      }
      if (itemId !== snapshot.itemId) {
        clearSource();
        if (blocked === 'error') blocked = '';
      }
      if (!snapshot.online || snapshot.transport !== 'playing' || !snapshot.itemId) {
        pause();
        report(!snapshot.online ? 'Waiting for the session player.' : ({
          paused: 'The host paused playback.', buffering: 'The host player is buffering.'
        }[snapshot.transport] || 'Waiting for the next song.'),
        !snapshot.online ? 'waiting' : ({paused: 'paused', buffering: 'buffering'}[snapshot.transport] || 'waiting'));
        return;
      }
      if (blocked) {
        pause();
        showBlocked();
        return;
      }
      if (!itemId) {
        itemId = snapshot.itemId;
        audio.preload = 'auto';
        audio.src = urlForItem(itemId);
        lastMediaPosition = 0;
        seekOnStart = true;
      }
      const position = snapshot.seconds + sampleAgeMs() / 1000;
      if (Number.isFinite(audio.duration) && position >= audio.duration) {
        pause();
        report('Waiting for the next song.', 'waiting');
        return;
      }
      if (localBuffering) {
        // Preserve this source while it fills. Chasing the moving host target
        // with repeated seeks can restart range downloads on a weak connection.
        // Some browsers limit paused preloading. After a bounded wait, accept
        // a smaller real reserve, still covering the current live target.
        const now = performance.now();
        const waited = now - bufferStartedAt >= bufferPatienceMs;
        const goal = waited ? stalledBufferSeconds : bufferGoal();
        if (audio.seeking || !bufferReady(position, goal)) {
          pause();
          // If paused preloading stopped behind the host, move the download
          // once per underrun. Subsequent polls wait for data instead of
          // repeatedly restarting the network request as the target moves.
          if (waited && !bufferReanchored && !audio.seeking && bufferedAhead(position) === 0) {
            bufferReanchored = true;
            try { audio.currentTime = position; lastSeekAt = now; } catch {}
          }
          report(waited ? 'Waiting for audio at the live position…' : 'Building a steadier audio buffer on this phone…', 'buffering');
          return;
        }
        clearBufferWait();
        seekOnStart = true;
      }
      alignToHost(position);
      if (playAttempt) {
        report('Connecting audio…', 'connecting');
        return;
      }
      if (!audio.paused) {
        // WebKit may keep advancing at HAVE_CURRENT_DATA after returning from the background.
        // Native waiting/progress events remain authoritative about an actual buffer stall.
        report(audio.readyState < 2 ? 'Buffering on this phone…' : 'Listening on this phone.',
          audio.readyState < 2 ? 'buffering' : 'listening');
        return;
      }

      const attempt = {};
      playAttempt = attempt;
      report('Connecting audio…', 'connecting');
      applyMuteIntent();
      audio.play().then(() => {
        if (playAttempt !== attempt) {
          if (!playbackPermitted()) pause();
          return;
        }
        playAttempt = null;
        if (!playbackPermitted()) { pause(); sync(); return; }
        if (!audio.paused) { hasPlayed = true; report('Listening on this phone.', 'listening'); }
      }).catch(error => {
        if (playAttempt !== attempt) return;
        playAttempt = null;
        if (error.name === 'AbortError') return;
        blocked = error.name === 'NotAllowedError' ? 'gesture' : 'error';
        showBlocked();
      });
    }

    function update(next) {
      // A response started before Resync/reconnect cannot authorize playback after it.
      if (next.allowed && ((awaitingUpdate && !Number.isFinite(next.requestStartedAt)) ||
          (Number.isFinite(next.requestStartedAt) && next.requestStartedAt < freshAfter))) return;
      const reconnecting = snapshot && !snapshot.online && next.online;
      const changedGeneration = snapshot && next.generation !== snapshot.generation;
      snapshot = {
        ...next, itemId: next.itemId == null ? '' : String(next.itemId),
        seconds: Math.max(0, Number(next.seconds) || 0), sampleAgeMs: Math.max(0, Number(next.sampleAgeMs) || 0)
      };
      if (activity && (snapshot.itemId !== activity.itemId || snapshot.generation !== activity.generation)) endActivity();
      if (changedGeneration || reconnecting) seekOnStart = true;
      if ((reconnecting || retryOnUpdate) && (blocked === 'error' || audio.error)) {
        clearSource();
        if (blocked !== 'gesture') blocked = '';
      }
      networkAvailable = true;
      awaitingUpdate = false;
      retryOnUpdate = false;
      observedAt = performance.now();
      keepFresh();
      sync();
    }

    function disconnect() {
      clearTimeout(freshnessTimer);
      networkAvailable = false;
      awaitingUpdate = true;
      freshAfter = performance.now();
      retryOnUpdate = true;
      sync();
    }

    function transientFailure() {
      // A single failed poll does not revoke the last confirmed snapshot. Its
      // existing freshness timer still stops audio at the original deadline.
      if (!listening || !snapshot) return;
      if (freshForMs() <= 0 || awaitingUpdate || !networkAvailable || audio.paused || localBuffering || blocked) {
        sync();
        return;
      }
      report('Connection interrupted. Using buffered audio while reconnecting…', 'reconnecting');
    }

    function reconnect() {
      clearTimeout(freshnessTimer);
      networkAvailable = true;
      awaitingUpdate = true;
      freshAfter = performance.now();
      retryOnUpdate = true;
      seekOnStart = true;
      sync();
    }

    function resync() {
      if (!listening || !snapshot?.allowed) return false;
      // A user retry may clear a failed play, but never changes listening consent or mute.
      if (blocked === 'error' || audio.error) clearSource();
      else clearBufferWait();
      blocked = '';
      reconnect();
      return true;
    }

    function listen() {
      if (!snapshot?.allowed) return;
      if (blocked === 'error') clearSource();
      listening = true;
      blocked = '';
      keepFresh();
      sync();
    }

    function checkFreshness() {
      if (listening && snapshot && freshForMs() <= 0) sync();
    }

    function keepFresh() {
      clearTimeout(freshnessTimer);
      if (listening && snapshot && networkAvailable && !awaitingUpdate) {
        const remaining = freshForMs();
        if (remaining > 0) freshnessTimer = setTimeout(checkFreshness, Math.ceil(remaining));
      }
    }

    audio.addEventListener('loadedmetadata', () => { applyMuteIntent(); sync(); });
    audio.addEventListener('canplay', () => { applyMuteIntent(); sync(); });
    audio.addEventListener('progress', () => { if (localBuffering) sync(); });
    audio.addEventListener('seeked', () => { lastMediaPosition = audio.currentTime; sync(); });
    const buffering = () => {
      // Keep the first native play tied to the user's gesture. A pending
      // initial load/seek is not a fresh underrun to restart over and over.
      if (!playbackPermitted() || audio.paused || audio.seeking || playAttempt || !hasPlayed) return;
      localBuffering = true;
      bufferUnderruns++;
      bufferStartedAt = performance.now();
      bufferReanchored = false;
      lastMediaPosition = audio.currentTime;
      pause();
      report('Building a steadier audio buffer on this phone…', 'buffering');
    };
    audio.addEventListener('waiting', buffering);
    audio.addEventListener('stalled', () => {
      // A network download can stall while many seconds remain cached and
      // native playback is healthy; do not turn that into an audible pause.
      if (bufferedAhead(audio.currentTime) < stalledBufferSeconds) buffering();
    });
    audio.addEventListener('playing', () => {
      applyMuteIntent();
      if (!playbackPermitted()) { pause(); sync(); return; }
      if (!audio.paused) {
        hasPlayed = true;
        report('Listening on this phone.', 'listening');
        if (!activity) measureActivity();
      }
    });
    audio.addEventListener('ended', () => {
      endActivity();
      if (listening && itemId) report('Waiting for the next song.', 'waiting');
    });
    audio.addEventListener('error', () => {
      endActivity();
      if (!listening || !itemId || !audio.error) return;
      blocked = 'error';
      pause();
      sync();
    });
    audio.addEventListener('pause', () => {
      endActivity();
      if (playbackPermitted() && !playAttempt) report('Playback paused on this phone. Reconnecting…', 'reconnecting');
    });
    audio.addEventListener('seeking', endActivity);
    audio.addEventListener('volumechange', () => {
      applyMuteIntent();
      if (muteIntent || audio.muted || audio.volume === 0) endActivity();
      report();
    });
    audio.addEventListener('timeupdate', () => {
      checkFreshness();
      if (localBuffering && !audio.paused) pause();
      if (playbackPermitted() && !audio.paused && !audio.seeking && audio.readyState >= 2 && audio.currentTime > lastMediaPosition) {
        report('Listening on this phone.', 'listening');
      }
      lastMediaPosition = audio.currentTime;
      measureActivity();
    });
    if (onActivity) window.addEventListener('pagehide', endActivity);
    document.addEventListener('visibilitychange', () => {
      if (document.hidden || !listening) return;
      seekOnStart = true;
      checkFreshness();
    });

    function toggleMute() {
      muteIntent = !(muteIntent || audio.muted);
      audio.defaultMuted = muteIntent;
      if (audio.muted !== muteIntent) audio.muted = muteIntent;
      if (muteIntent) endActivity();
      report();
    }

    return {update, disconnect, transientFailure, reconnect, resync, listen, stop, toggleMute};
  }

  window.StonefellowJukeboxListener = {create};
})();
