'use strict';

(() => {
  const maxProjectionMs = 4000;
  const staleAfterMs = 6000;

  function create({audio, urlForItem, onChange}) {
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

    function pause() {
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
      if (Math.abs(audio.currentTime - target) > (seekOnStart ? 0.15 : 2)) audio.currentTime = target;
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
      if (listening && !audio.paused) report('Buffering on this phone…');
    });
    audio.addEventListener('playing', () => {
      if (listening && !audio.paused) report('Listening on this phone.');
    });
    audio.addEventListener('ended', () => {
      if (listening && itemId) report('Waiting for the next song.');
    });
    audio.addEventListener('error', () => {
      if (!listening || !itemId || !audio.error) return;
      blocked = 'error';
      sync();
    });
    audio.addEventListener('timeupdate', checkFreshness);

    function toggleMute() {
      audio.muted = !audio.muted;
      report();
    }

    return {update, disconnect, listen, stop, toggleMute};
  }

  window.StonefellowJukeboxListener = {create};
})();
