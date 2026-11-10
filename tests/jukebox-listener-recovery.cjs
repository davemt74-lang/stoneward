'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets/js/jukebox-listener.js'), 'utf8');
const flush = async () => { for (let index = 0; index < 8; index++) await Promise.resolve(); };

function emitter() {
  const handlers = new Map();
  return {
    addEventListener(name, handler) {
      if (!handlers.has(name)) handlers.set(name, new Set());
      handlers.get(name).add(handler);
    },
    fire(name, event = {}) {
      for (const handler of [...(handlers.get(name) || [])]) handler(event);
    }
  };
}

// The fake exposes native events, media position and deferred play promises;
// assertions below exercise consent, freshness and audible-activity contracts.
function setup() {
  let now = 100, timerId = 0, src = '', muted = false;
  const timers = new Map(), views = [], activity = [], attempts = [];
  const document = {...emitter(), hidden: false};
  const window = emitter();
  const audio = {
    ...emitter(), currentTime: 0, duration: 120, readyState: 4, seeking: false,
    paused: true, ended: false, volume: 1, playbackRate: 1, error: null,
    defaultMuted: false, deferred: false, playRejection: '', resetMutedOnPlay: false,
    loads: 0,
    get src() { return src; },
    set src(value) {
      src = value; this.currentTime = 0; this.paused = true;
      this.readyState = 4; this.error = null;
    },
    get currentSrc() { return src; },
    get muted() { return muted; },
    set muted(value) {
      const next = Boolean(value);
      if (next !== muted) { muted = next; this.fire('volumechange'); }
    },
    nativeMuted(value, notify = true) {
      muted = Boolean(value);
      if (notify) this.fire('volumechange');
    },
    removeAttribute(name) { if (name === 'src') src = ''; },
    load() {
      this.loads++; this.currentTime = 0; this.paused = true;
      this.error = null; this.readyState = src ? 4 : 0;
    },
    pause() {
      if (!this.paused) { this.paused = true; this.fire('pause'); }
    },
    play() {
      let resolve, reject, settled = false;
      const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });
      const attempt = {
        resolve: () => {
          if (settled) return;
          settled = true;
          if (this.resetMutedOnPlay) this.nativeMuted(false);
          this.paused = false; this.fire('playing'); resolve();
        },
        reject: name => {
          if (settled) return;
          settled = true; this.paused = true;
          const error = new Error(name); error.name = name; reject(error);
        }
      };
      attempts.push(attempt);
      if (!this.deferred) {
        const rejection = this.playRejection;
        queueMicrotask(() => rejection ? attempt.reject(rejection) : attempt.resolve());
      }
      return promise;
    }
  };
  vm.runInNewContext(source, {
    window, document, performance: {now: () => now},
    setTimeout(callback, delay) {
      const id = ++timerId; timers.set(id, {callback, due: now + delay}); return id;
    },
    clearTimeout(id) { timers.delete(id); },
    HTMLMediaElement: {HAVE_METADATA: 1, HAVE_CURRENT_DATA: 2},
    Promise, Number, Math, Error
  });
  const listener = window.StonefellowJukeboxListener.create({
    audio, urlForItem: id => '/audio?item=' + id,
    onChange: view => views.push({...view}),
    onActivity: sample => activity.push({...sample})
  });
  const base = {
    allowed: true, online: true, itemId: '1', generation: 1,
    transport: 'playing', seconds: 8, sampleAgeMs: 0
  };
  return {
    listener, audio, window, document, activity, attempts, base,
    get now() { return now; },
    get view() { return views.at(-1); },
    update(extra = {}, started = now) {
      listener.update({...base, ...extra, requestStartedAt: started});
    },
    advance(ms) {
      now += ms;
      for (let guard = 0; guard < 100; guard++) {
        const next = [...timers].find(([, timer]) => timer.due <= now);
        if (!next) return;
        timers.delete(next[0]); next[1].callback();
      }
      throw new Error('Freshness timer did not settle');
    },
    async start() {
      this.update(); listener.listen(); await flush();
    }
  };
}

test('fresh updates and lifecycle recovery never opt in a stopped phone', async () => {
  const h = setup();
  h.update(); h.listener.reconnect(); h.update({seconds: 12});
  h.document.fire('visibilitychange');
  assert.equal(h.attempts.length, 0);
  assert.equal(h.listener.resync(), false);
  await h.start();
  assert.equal(h.audio.paused, false);
  h.listener.stop();
  const attempts = h.attempts.length;
  h.listener.disconnect(); h.advance(100); h.listener.reconnect();
  h.update({seconds: 20}); h.document.fire('visibilitychange'); await flush();
  assert.equal(h.view.listening, false);
  assert.equal(h.audio.paused, true);
  assert.equal(h.audio.src, '');
  assert.equal(h.attempts.length, attempts);
});

test('Resync waits for a newly requested snapshot and preserves mute', async () => {
  const h = setup(); await h.start(); h.listener.toggleMute();
  const oldRequest = h.now; h.advance(100);
  assert.equal(h.listener.resync(), true);
  assert.equal(h.audio.paused, true);
  assert.equal(h.view.resyncing, true);
  h.update({seconds: 30}, oldRequest); await flush();
  assert.equal(h.audio.paused, true);
  h.update({seconds: 17}); await flush();
  assert.equal(h.audio.currentTime, 17);
  assert.equal(h.audio.paused, false);
  assert.equal(h.audio.muted, true);
});

test('a native media reload cannot silently clear the phone mute preference', async () => {
  const h = setup(); await h.start(); h.listener.toggleMute();
  h.audio.nativeMuted(false, false);
  h.advance(250); h.audio.currentTime += .25; h.audio.fire('timeupdate');
  assert.equal(h.activity.some(sample => sample.active && sample.advancedMs > 0), false,
    'a native reset awaiting volumechange must not credit a muted phone');
  h.audio.fire('volumechange');
  h.audio.nativeMuted(false);
  assert.equal(h.audio.muted, true);
  assert.equal(h.view.muted, true);
  h.audio.resetMutedOnPlay = true;
  h.advance(100); h.listener.resync(); h.update({seconds: 12}); await flush();
  assert.equal(h.audio.muted, true);
  assert.equal(h.audio.defaultMuted, true);
  h.listener.toggleMute();
  assert.equal(h.audio.muted, false);
  assert.equal(h.audio.defaultMuted, false);
});

test('reconnecting respects an externally muted phone until explicit Unmute', async () => {
  const h = setup(); await h.start(); h.audio.nativeMuted(true);
  assert.equal(h.view.muted, true);
  h.advance(100); h.listener.reconnect(); h.update({seconds: 14}); await flush();
  assert.equal(h.audio.muted, true);
  h.listener.toggleMute();
  assert.equal(h.audio.muted, false);
  assert.equal(h.view.muted, false);
});

test('late play completion cannot restart after local Stop or revoked access', async () => {
  for (const revoke of [false, true]) {
    const h = setup(); h.audio.deferred = true; h.update(); h.listener.listen();
    if (revoke) h.listener.update({allowed: false}); else h.listener.stop();
    h.attempts[0].resolve(); await flush();
    assert.equal(h.audio.paused, true);
    assert.equal(h.audio.src, '');
    assert.equal(h.view.listening, false);
    assert.equal(h.attempts.length, 1);
  }
});

test('a delayed play cannot resume while Resync is waiting for fresh state', async () => {
  const h = setup(); h.audio.deferred = true; h.update(); h.listener.listen();
  h.advance(100); h.listener.resync(); h.attempts[0].resolve(); await flush();
  assert.equal(h.audio.paused, true);
  assert.equal(h.view.connection, 'reconnecting');
  h.update({seconds: 15});
  assert.equal(h.attempts.length, 2);
  h.attempts[1].resolve(); await flush();
  assert.equal(h.audio.paused, false);
  assert.equal(h.audio.currentTime, 15);
});

test('a newer authorization denial wins during recovery', async () => {
  const h = setup(); await h.start(); const old = h.now;
  h.advance(100); h.listener.resync();
  h.update({allowed: false}); h.update({seconds: 20}, old); await flush();
  assert.equal(h.audio.paused, true);
  assert.equal(h.view.connection, 'unavailable');
  assert.equal(h.audio.src, '');
  const attempts = h.attempts.length;
  h.update({seconds: 22}); await flush();
  assert.equal(h.view.listening, false);
  assert.equal(h.attempts.length, attempts);
});

test('recovery requires request evidence but an untimestamped denial still revokes', async () => {
  const h = setup(); await h.start(); h.advance(100); h.listener.reconnect();
  h.listener.update({...h.base, seconds: 20}); await flush();
  assert.equal(h.audio.paused, true);
  assert.equal(h.view.connection, 'reconnecting');
  h.listener.update({allowed: false});
  assert.equal(h.view.connection, 'unavailable');
  assert.equal(h.audio.src, '');
});

test('autoplay rejection stays actionable until another explicit Listen gesture', async () => {
  const h = setup(); h.audio.playRejection = 'NotAllowedError'; await h.start();
  assert.equal(h.view.connection, 'action-required');
  assert.equal(h.view.needsGesture, true);
  h.update(); await flush();
  assert.equal(h.attempts.length, 1);
  h.audio.playRejection = ''; h.listener.listen(); await flush();
  assert.equal(h.view.connection, 'listening');
  assert.equal(h.audio.paused, false);
});

test('failed audio does not loop retries and a fresh manual Resync can reload it', async () => {
  const h = setup(); await h.start();
  h.audio.error = {code: 3}; h.audio.fire('error');
  assert.equal(h.view.connection, 'error');
  assert.equal(h.audio.paused, true);
  const attempts = h.attempts.length, loads = h.audio.loads;
  h.update(); h.update(); await flush();
  assert.equal(h.attempts.length, attempts);
  assert.equal(h.audio.loads, loads);
  h.advance(100); h.listener.resync(); h.update({seconds: 12}); await flush();
  assert.equal(h.audio.loads, loads + 1);
  assert.equal(h.audio.paused, false);
  assert.equal(h.view.connection, 'listening');
});

test('network loss, host pause and host absence have distinct connection states', async () => {
  const h = setup(); await h.start(); h.listener.disconnect();
  assert.equal(h.view.connection, 'reconnecting');
  assert.equal(h.audio.paused, true);
  h.update({online: false});
  assert.equal(h.view.connection, 'waiting');
  h.update({transport: 'paused'});
  assert.equal(h.view.connection, 'paused');
  h.update({transport: 'buffering'});
  assert.equal(h.view.connection, 'buffering');
  h.update(); await flush();
  assert.equal(h.view.connection, 'listening');
});

for (const readyState of [2, 4]) {
  test(`phone buffering clears on real native progress at readyState ${readyState}`, async () => {
    const h = setup(); await h.start(); h.audio.readyState = readyState;
    h.audio.fire('waiting');
    assert.equal(h.view.connection, 'buffering');
    h.audio.fire('timeupdate');
    assert.equal(h.view.connection, 'buffering', 'queued unchanged-position event is not progress');
    h.update();
    assert.equal(h.view.connection, 'buffering', 'polling cannot clear native buffering');
    h.advance(250); h.audio.currentTime += .25; h.audio.fire('timeupdate');
    assert.equal(h.view.connection, 'listening');
    h.update({seconds: h.audio.currentTime});
    assert.equal(h.view.connection, 'listening');
  });
}

test('recovery seeks never count the skipped gap as listening', async () => {
  const h = setup(); await h.start();
  const credited = () => h.activity.reduce((total, sample) => total + (sample.active ? sample.advancedMs : 0), 0);
  h.advance(1000); h.audio.currentTime += 1; h.audio.fire('timeupdate');
  assert.equal(credited(), 1000);
  h.advance(100); h.listener.resync(); h.update({seconds: 40}); await flush();
  assert.equal(credited(), 1000);
  h.advance(1000); h.audio.currentTime += 1; h.audio.fire('timeupdate');
  assert.equal(credited(), 2000);
  h.advance(2500); h.audio.currentTime += 2.5; h.audio.fire('timeupdate');
  assert.equal(credited(), 2000, 'a long suspended interval is not listening credit');
});

test('stale host playback cannot continue until fresh transport arrives', async () => {
  const h = setup(); await h.start(); h.advance(5001);
  assert.equal(h.audio.paused, true);
  assert.equal(h.view.connection, 'reconnecting');
  h.update({sampleAgeMs: 5100}); await flush();
  assert.equal(h.audio.paused, true);
  h.update({seconds: 20, sampleAgeMs: 0}); await flush();
  assert.equal(h.audio.paused, false);
  assert.equal(h.audio.currentTime, 20);
});
