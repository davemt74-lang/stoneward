'use strict';

(() => {
  const maxProjectionMs = 4000;
  const staleAfterMs = 6000;
  const maxActivityGapMs = 2000;
  const activityToleranceMs = 150;

  function create({audio, urlForItem, onChange, onActivity}) {
    let snapshot = null;
    let observedAt = 0;
    let listening = false;
    let itemId = '';
    let seekOnStart = false;
    let playAttempt = null;
    let blocked = '';
    let status = '';
    let previousView = '';
    let freshnessTimer;
    let activity = null;

    function report(message = status) {
      status = message;
      const view = {
        allowed: Boolean(snapshot?.allowed), listening, muted: audio.muted,
        needsGesture: Boolean(blocked), status
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
      if (!listening || !itemId || itemId !== snapshot.itemId || !snapshot.allowed || !snapshot.online ||
          snapshot.transport !== 'playing' || !Number.isSafeInteger(snapshot.generation) || snapshot.generation < 0 ||
          now - observedAt >= staleAfterMs || audio.paused || audio.ended || audio.seeking ||
          audio.muted || audio.volume === 0 || audio.readyState < 2 || !Number.isFinite(positionMs)) {
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

    function pause() {
      endActivity();
      playAttempt = null;
      audio.pause();
      seekOnStart = true;
    }

    function clearSource() {
      if (!itemId) return;
      pause();
      itemId = '';
      audio.removeAttribute('src');
      audio.load();
    }

    function stop() {
      clearTimeout(freshnessTimer);
      listening = false;
      blocked = '';
      clearSource();
      report('');
    }

    function hostPosition() {
      const sampleAge = Math.min(maxProjectionMs, snapshot.sampleAgeMs + performance.now() - observedAt);
      return snapshot.seconds + sampleAge / 1000;
    }

    function seekToHost(position) {
      if (audio.readyState < 1 || audio.seeking) return;
      const target = Number.isFinite(audio.duration) ? Math.min(position, audio.duration) : position;
      if (Math.abs(audio.currentTime - target) > (seekOnStart ? 0.15 : 2)) {
        endActivity();
        audio.currentTime = target;
      }
      seekOnStart = false;
    }

    function showBlocked() {
      report(blocked === 'gesture' ? 'Tap to continue listening.' : 'Audio could not load. Tap to retry.');
    }

    function sync() {
      if (!snapshot?.allowed) {
        stop();
        return;
      }
      if (!listening) {
        report('');
        return;
      }
      if (snapshot.online && performance.now() - observedAt >= staleAfterMs) {
        disconnect();
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
        }[snapshot.transport] || 'Waiting for the next song.'));
        return;
      }
      if (blocked) {
        showBlocked();
        return;
      }
      if (!itemId) {
        itemId = snapshot.itemId;
        audio.src = urlForItem(itemId);
        seekOnStart = true;
      }
      const position = hostPosition();
      if (Number.isFinite(audio.duration) && position >= audio.duration) {
        pause();
        report('Waiting for the next song.');
        return;
      }
      seekToHost(position);
      if (playAttempt || !audio.paused) return;

      const attempt = {};
      playAttempt = attempt;
      report('Connecting audio…');
      audio.play().then(() => {
        if (playAttempt !== attempt) return;
        playAttempt = null;
        report('Listening on this phone.');
      }).catch(error => {
        if (playAttempt !== attempt) return;
        playAttempt = null;
        if (error.name === 'AbortError') return;
        blocked = error.name === 'NotAllowedError' ? 'gesture' : 'error';
        showBlocked();
      });
    }

    function update(next) {
      const reconnecting = snapshot && !snapshot.online && next.online;
      snapshot = {
        ...next, itemId: next.itemId == null ? '' : String(next.itemId),
        seconds: Math.max(0, Number(next.seconds) || 0), sampleAgeMs: Math.max(0, Number(next.sampleAgeMs) || 0)
      };
      if (activity && (snapshot.itemId !== activity.itemId || snapshot.generation !== activity.generation)) endActivity();
      if (reconnecting && (blocked === 'error' || audio.error)) {
        clearSource();
        blocked = '';
      }
      observedAt = performance.now();
      keepFresh();
      sync();
    }

    function disconnect() {
      if (!snapshot) return;
      clearTimeout(freshnessTimer);
      snapshot.online = false;
      sync();
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
      if (listening && snapshot.online && performance.now() - observedAt >= staleAfterMs) disconnect();
    }

    function keepFresh() {
      clearTimeout(freshnessTimer);
      if (listening && snapshot.online) {
        const remaining = staleAfterMs - (performance.now() - observedAt);
        freshnessTimer = setTimeout(checkFreshness, Math.ceil(Math.max(0, remaining)));
      }
    }

    audio.addEventListener('loadedmetadata', sync);
    audio.addEventListener('waiting', () => {
      endActivity();
      if (listening && !audio.paused) report('Buffering on this phone…');
    });
    audio.addEventListener('playing', () => {
      if (listening && !audio.paused) {
        report('Listening on this phone.');
        if (!activity) measureActivity();
      }
    });
    audio.addEventListener('ended', () => {
      endActivity();
      if (listening && itemId) report('Waiting for the next song.');
    });
    audio.addEventListener('error', () => {
      endActivity();
      if (!listening || !itemId || !audio.error) return;
      blocked = 'error';
      sync();
    });
    audio.addEventListener('pause', endActivity);
    audio.addEventListener('seeking', endActivity);
    audio.addEventListener('volumechange', () => {
      if (audio.muted || audio.volume === 0) endActivity();
    });
    audio.addEventListener('timeupdate', () => {
      checkFreshness();
      measureActivity();
    });
    if (onActivity) window.addEventListener('pagehide', endActivity);

    function toggleMute() {
      audio.muted = !audio.muted;
      if (audio.muted) endActivity();
      report();
    }

    return {update, disconnect, listen, stop, toggleMute};
  }

  window.StonefellowJukeboxListener = {create};
})();
