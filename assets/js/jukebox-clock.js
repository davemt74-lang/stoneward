'use strict';

(() => {
  // Compare clocks using round trips, without depending on the phone's wall clock.
  function create() {
    let samples = [];

    function observe(serverTimeMs, startedAt, receivedAt) {
      if (![serverTimeMs, startedAt, receivedAt].every(Number.isFinite) || serverTimeMs <= 0 ||
          startedAt < 0 || receivedAt < startedAt || receivedAt - startedAt > 5000) return;
      samples = samples.filter(sample => receivedAt - sample.at <= 60000 && receivedAt >= sample.at);
      samples.push({at: receivedAt, rtt: receivedAt - startedAt,
        offset: serverTimeMs - (startedAt + receivedAt) / 2});
      if (samples.length > 16) samples.shift();
    }

    function serverAt(localAt = performance.now()) {
      if (!Number.isFinite(localAt)) return null;
      const recent = samples.filter(sample => localAt >= sample.at && localAt - sample.at <= 60000);
      if (!recent.length) return null;
      const best = recent.reduce((first, next) => next.rtt <= first.rtt ? next : first);
      return localAt + best.offset;
    }

    function transitMs(serverTimeMs, startedAt, receivedAt) {
      if (![startedAt, receivedAt].every(Number.isFinite) || receivedAt < startedAt) return 0;
      const rtt = receivedAt - startedAt;
      const estimated = serverAt(receivedAt);
      const age = Number.isFinite(estimated) && Number.isFinite(serverTimeMs) && serverTimeMs > 0
        ? estimated - serverTimeMs : rtt / 2;
      return Math.max(0, Math.min(rtt, age));
    }

    return {observe, serverAt, transitMs};
  }

  window.StonefellowJukeboxClock = {create};
})();
