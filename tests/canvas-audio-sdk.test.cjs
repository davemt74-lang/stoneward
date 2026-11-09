'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { createHash, webcrypto } = require('node:crypto');
const path = require('node:path');
const code = fs.readFileSync(path.join(__dirname, '../assets/js/canvas-audio-sdk.js'), 'utf8');
const flush = async () => { for (let count = 0; count < 8; count++) await new Promise(resolve => setImmediate(resolve)); };
const track = id => ({ track_id: id, title: 'Track ' + id, duration_seconds: 120, release_ids: ['album-one'],
  audio_url: '/preview/' + id, source_available: true, source_reason: '', source_url: '/source/' + id });
class Hub {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, callback) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(callback); }
  removeEventListener(type, callback) { this.listeners.get(type)?.delete(callback); }
  emit(type, event = { type }) { for (const listener of [...(this.listeners.get(type) || [])]) listener(event); }
  count() { return [...this.listeners.values()].reduce((total, listeners) => total + listeners.size, 0); }
}
function harness(overrides = {}) {
  const host = new Hub(), child = new Hub(), frame = new Hub(), audio = new Hub();
  Object.assign(audio, { currentTime: 0, duration: 120, paused: true, volume: 0.8, muted: false, ended: false });
  let owner = 1, permitted = true, currentTrack = 'song-one';
  host.navigator = { userActivation: { isActive: false } };
  host.document = { activeElement: frame };
  const messages = [], created = [], revoked = [], calls = [], diagnostics = [], hostMedia = [], hostURLs = [], hostRevoked = [];
  const timers = new Map(); let nextTimer = 0;
  class LocalMedia extends Hub {
    constructor() { super(); this.src = ''; this.currentSrc = ''; this.error = null; this.result = Promise.resolve(); }
    play() { return this.result; }
  }
  const nativeMediaPlay = LocalMedia.prototype.play;
  const parent = { postMessage(data) { messages.push({ direction: 'child', data }); queueMicrotask(() => host.emit('message', { source: frame.contentWindow, origin: 'null', isTrusted: true, data: structuredClone(data) })); } };
  frame.contentWindow = { postMessage(data) { messages.push({ direction: 'host', data }); queueMicrotask(() => child.emit('message', { source: parent, origin: 'https://artist.example', data: structuredClone(data) })); } };
  class HostMedia extends Hub {
    constructor() { super(); Object.assign(this, { currentTime: 0, duration: 120, paused: true, ended: false, volume: 1, muted: false,
      playbackRate: 1, preservesPitch: false, readyState: 4, src: '' }); hostMedia.push(this); }
    play() { if (this.pendingPlay) return this.pendingPlay; if (this.failure) return Promise.reject(this.failure); this.paused = false; this.ended = false; this.emit('play'); this.emit('playing'); return Promise.resolve(); }
    pause() { this.paused = true; this.emit('pause'); }
    removeAttribute(name) { if (name === 'src') this.src = ''; }
    load() { this.emit('loadedmetadata'); }
  }
  const hostContext = vm.createContext({ window: host, crypto: webcrypto, Blob, AbortController, Audio: HostMedia,
    setTimeout: (callback, delay) => { timers.set(++nextTimer, {callback, delay}); return nextTimer; },
    clearTimeout: id => { timers.delete(id); },
    URL: {createObjectURL(blob) { const url = 'blob:host-' + (hostURLs.length + 1); hostURLs.push({url, blob}); return url; }, revokeObjectURL(url) {hostRevoked.push(url);}}, console });
  vm.runInContext(code, hostContext);
  const sdk = host.SFCanvasAudioSDK;
  const childContext = vm.createContext({ window: child, parent, crypto: webcrypto, Blob, HTMLMediaElement: LocalMedia,
    URL: { createObjectURL(blob) { const url = 'blob:fixture-' + (created.length + 1); created.push({ url, blob }); return url; }, revokeObjectURL(url) { revoked.push(url); } },
    addEventListener: child.addEventListener.bind(child), removeEventListener: child.removeEventListener.bind(child) });
  vm.runInContext(sdk.source, childContext);
  const options = {
    onDiagnostic: diagnostic => diagnostics.push(diagnostic),
    frame, audio, getOwner: () => owner, canUse: () => permitted,
    loadCatalog: async filters => { calls.push(['catalog', filters]); return { tracks: [track(filters.track_id || 'song-one')], releases: [{ release_id: 'album-one', title: 'Album One', track_ids: ['song-one'] }] }; },
    loadAudio: async id => { calls.push(['source', id]); return { track: track(id), blob: new Blob(['ID3fixture'], { type: 'audio/mpeg' }) }; },
    play: async id => { calls.push(['play', id]); currentTrack = id || currentTrack; audio.paused = false; audio.ended = false; audio.emit('play'); },
    pause: () => { calls.push(['pause']); audio.paused = true; audio.emit('pause'); },
    seek: value => { calls.push(['seek', value]); audio.currentTime = value; audio.emit('seeked'); },
    setVolume: value => { calls.push(['volume', value]); audio.volume = value; audio.emit('volumechange'); },
    getState: () => ({ trackId: currentTrack, title: 'Track ' + currentTrack, currentTime: audio.currentTime, duration: audio.duration,
      playing: !audio.paused && !audio.ended, volume: audio.volume, muted: audio.muted, ended: audio.ended }),
    ...overrides
  };
  let controller;
  return { host, child, frame, audio, api: child.StonefellowAudio, data: child.StonefellowData, sdk, options, messages, created, revoked, calls,
    diagnostics, LocalMedia, nativeMediaPlay, hostMedia, hostURLs, hostRevoked, timers,
    start() { controller = sdk.createHost(options); return controller; },
    setOwner(value) { owner = value; }, setPermission(value) { permitted = value; },
    destroy() { controller?.destroy(); },
    current() { return messages.filter(message => message.direction === 'host' && message.data.type === 'hello').at(-1)?.data; }
  };
}

test('the fixed iframe runtime matches its CSP hash and exposes the complete async audio capability', async () => {
  const h = harness();
  assert.equal(h.sdk.hash, "'sha256-" + createHash('sha256').update(h.sdk.source).digest('base64') + "'");
  for (const method of ['catalog', 'getTrack', 'getAudio', 'play', 'pause', 'seek', 'setVolume', 'getState', 'subscribe', 'releaseAudio', 'createPlayer']) assert.equal(typeof h.api[method], 'function');
  const queued = h.api.catalog({ release_id: 'album-one' });
  h.start();
  const result = await queued;
  assert.equal(result.tracks[0].track_id, 'song-one');
  assert.equal(result.releases[0].release_id, 'album-one');
  assert.equal(h.calls[0][1].release_id, 'album-one');
  assert.equal((await h.api.getTrack('song-two')).track_id, 'song-two');
  h.destroy();
});

test('canonical transport controls and state subscriptions work without an SDK-owned player UI', async () => {
  const h = harness(); const controller = h.start(); await flush();
  const states = [];
  const unsubscribe = h.api.subscribe(state => states.push(state));
  assert.equal(typeof unsubscribe, 'function');
  assert.equal(states.at(-1).volume, 0.8);
  assert.ok(Object.isFrozen(states.at(-1)));
  await h.api.play('song-two');
  assert.equal(states.at(-1).trackId, 'song-two');
  assert.equal(states.at(-1).playing, true);
  await h.api.seek(37.5); await h.api.setVolume(0.25); await h.api.pause();
  const state = await h.api.getState();
  assert.equal(state.currentTime, 37.5); assert.equal(state.volume, 0.25); assert.equal(state.playing, false);
  h.audio.ended = true; h.audio.emit('ended'); await flush();
  assert.equal(states.at(-1).ended, true);
  const count = states.length; unsubscribe(); controller.refresh(); await flush(); assert.equal(states.length, count);
  assert.equal(h.frame.count(), 1); h.destroy(); assert.equal(h.frame.count(), 0); assert.equal(h.audio.count(), 0);
});

test('raw MP3 blobs have reusable local URLs and releaseAudio revokes them explicitly', async () => {
  const h = harness(); h.start(); await flush();
  const [first, repeated] = await Promise.all([h.api.getAudio('song-one'), h.api.getAudio('song-one')]);
  assert.equal(first, repeated); assert.equal(await first.blob.text(), 'ID3fixture');
  assert.equal(first.blob.type, 'audio/mpeg'); assert.match(first.url, /^blob:/);
  assert.equal(h.calls.filter(call => call[0] === 'source').length, 1);
  assert.equal(await h.api.releaseAudio('song-one'), true); assert.ok(h.revoked.includes(first.url));
  const second = await h.api.getAudio('song-one'); assert.notEqual(second.url, first.url);
  h.destroy(); await flush(); assert.ok(h.revoked.includes(second.url));
  await assert.rejects(h.api.getAudio('song-one'), error => error.code === 'audio_session_closed');
});

test('the host rejects arbitrary URLs, malformed filters, invalid controls and oversized sources', async () => {
  class TooLargeBlob extends Blob { get size() { return 128 * 1024 * 1024 + 1; } }
  const h = harness({ loadAudio: async id => ({ track: track(id), blob: new TooLargeBlob(['small fixture']) }) });
  h.start(); await flush();
  for (const promise of [h.api.getAudio('https://elsewhere.example/file.mp3'), h.api.play('../secret'),
    h.api.catalog({ url: '/private/source' }), h.api.seek(NaN), h.api.seek(-1), h.api.setVolume(2), h.api.setVolume('0.5')]) {
    await assert.rejects(promise);
  }
  assert.equal(h.calls.length, 0);
  await assert.rejects(h.api.getAudio('song-one'), error => error.code === 'invalid_audio_source');
  assert.equal(h.created.length, 0); h.destroy();
});

test('message origin, exact source, version, nonce, session, document and replay checks protect host controls', async () => {
  const h = harness(); h.start(); await flush();
  await h.api.seek(11);
  const original = h.messages.find(message => message.direction === 'child' && message.data.method === 'seek').data;
  const valid = { source: h.frame.contentWindow, origin: 'null' };
  for (const event of [
    { ...valid, source: {}, data: { ...original, id: 'r-100' } },
    { ...valid, origin: 'https://artist.example', data: { ...original, id: 'r-100' } },
    { ...valid, data: { ...original, id: 'r-100', version: 2 } },
    { ...valid, data: { ...original, id: 'r-100', nonce: '0'.repeat(32) } },
    { ...valid, data: { ...original, id: 'r-100', session: '0'.repeat(32) } },
    { ...valid, data: { ...original, id: 'r-100', documentId: '0'.repeat(32) } },
    { ...valid, data: original },
    { ...valid, data: { ...original, id: 'r-invalid' } }
  ]) h.host.emit('message', event);
  await flush(); assert.equal(h.calls.filter(call => call[0] === 'seek').length, 1);
  await h.api.seek(12); assert.equal(h.calls.filter(call => call[0] === 'seek').length, 2);
  h.destroy();
});

test('host failures reject callers without exposing upstream exception details', async () => {
  const h = harness({ loadCatalog: async () => { throw new Error('private upstream credentials'); }, pause: () => { throw null; } });
  h.start(); await flush();
  await assert.rejects(h.api.catalog(), failure => !failure.message.includes('credentials') && failure.code === 'audio_unavailable');
  await assert.rejects(h.api.pause(), failure => failure.code === 'audio_unavailable');
  h.destroy();
});

test('media concurrency is bounded while controls remain available and queued requests drain', async () => {
  let active = 0, maximum = 0; const waiting = [];
  const h = harness({ loadCatalog: filters => new Promise(resolve => {
    active++; maximum = Math.max(maximum, active);
    waiting.push(() => { active--; resolve({ tracks: [track(filters.track_id || 'song-one')], releases: [] }); });
  }) });
  h.start(); await flush();
  const all = Promise.allSettled(Array.from({ length: 80 }, () => h.api.catalog()));
  const pause = h.api.pause();
  await flush(); assert.equal(active, 4); await pause;
  assert.ok(h.calls.some(call => call[0] === 'pause'));
  for (let cycle = 0; cycle < 20 && (waiting.length || active); cycle++) {
    for (const complete of waiting.splice(0)) complete();
    await flush();
  }
  const results = await all;
  assert.equal(maximum, 4); assert.equal(results.filter(result => result.status === 'fulfilled').length, 80);
  assert.equal(results.filter(result => result.status === 'rejected').length, 0);
  h.destroy();
});

for (const [count, method] of [[120, 'getTrack'], [200, 'getAudio']]) {
  test(count + ' concurrent ' + method + ' calls all settle with four reads in flight and working controls', async () => {
    let active = 0, maximum = 0, reads = 0; const waiting = [];
    const read = (id, value) => new Promise(resolve => {
      active++; reads++; maximum = Math.max(maximum, active);
      waiting.push(() => { active--; resolve(value(id)); });
    });
    const h = harness({
      loadCatalog: filters => read(filters.track_id, id => ({ tracks: [track(id)], releases: [] })),
      loadAudio: id => read(id, selected => ({ track: track(selected), blob: new Blob(['ID3-' + selected], { type: 'audio/mpeg' }) }))
    });
    const all = Promise.all(Array.from({ length: count }, (_, index) => h.api[method]('song-' + index)));
    h.start(); await flush();
    assert.equal(reads, 4); assert.equal(active, 4);
    await h.api.seek(19); await h.api.setVolume(0.3); await h.api.pause();
    const state = await h.api.getState();
    assert.equal(state.currentTime, 19); assert.equal(state.volume, 0.3);
    assert.equal(reads, 4, 'canonical controls bypass queued read requests');
    for (let cycle = 0; cycle < count && waiting.length; cycle++) {
      for (const complete of waiting.splice(0)) complete();
      await flush();
    }
    const results = await all;
    assert.equal(results.length, count); assert.equal(reads, count); assert.equal(maximum, 4);
    for (let index = 0; index < count; index++) {
      const result = results[index];
      assert.equal((method === 'getAudio' ? result.track : result).track_id, 'song-' + index);
      if (method === 'getAudio') assert.equal(await result.blob.text(), 'ID3-song-' + index);
    }
    const ids = h.messages.filter(message => message.direction === 'child' && message.data.type === 'request')
      .map(message => Number(message.data.id.slice(2)));
    assert.ok(ids.every((id, index) => index === 0 || id > ids[index - 1]), 'controls and deferred reads retain replay-safe send order');
    if (method === 'getAudio') {
      assert.equal(h.created.length, count);
      h.destroy(); await flush(); assert.equal(h.revoked.length, count);
    } else h.destroy();
  });
}

test('a failed read releases its slot and does not retry or block later queued work', async () => {
  const seen = [];
  const h = harness({ loadCatalog: async filters => {
    seen.push(filters.track_id);
    if (filters.track_id === 'song-3') throw new Error('fixture failure');
    return { tracks: [track(filters.track_id)], releases: [] };
  } });
  h.start(); await flush();
  const results = await Promise.allSettled(Array.from({ length: 20 }, (_, index) => h.api.getTrack('song-' + index)));
  assert.equal(results.filter(result => result.status === 'fulfilled').length, 19);
  assert.equal(results[3].status, 'rejected'); assert.equal(seen.length, 20); assert.equal(new Set(seen).size, 20);
  h.destroy();
});

test('queued catalog reads retain the filter values present when the SDK call was made', async () => {
  const waiting = [];
  const h = harness({ loadCatalog: filters => new Promise(resolve => {
    waiting.push(() => resolve({ tracks: [track(filters.track_id)], releases: [] }));
  }) });
  h.start(); await flush();
  const initial = Promise.all(Array.from({ length: 4 }, (_, index) => h.api.getTrack('initial-' + index)));
  const filters = { track_id: 'chosen-track' };
  const queued = h.api.catalog(filters);
  filters.track_id = 'changed-after-queue';
  await flush();
  for (const complete of waiting.splice(0)) complete();
  await initial; await flush();
  for (const complete of waiting.splice(0)) complete();
  assert.equal((await queued).tracks[0].track_id, 'chosen-track');
  h.destroy();
});

test('Blob-only source callbacks can resolve metadata while all primitive read slots are occupied', async () => {
  const h = harness({ loadAudio: async () => new Blob(['ID3fallback'], { type: 'audio/mpeg' }) });
  h.start(); await flush();
  const results = await Promise.all(Array.from({ length: 12 }, (_, index) => h.api.getAudio('fallback-' + index)));
  assert.equal(results.length, 12);
  for (let index = 0; index < results.length; index++) assert.equal(results[index].track.track_id, 'fallback-' + index);
  h.destroy();
});

for (const shutdown of ['owner-change', 'unload']) {
  test(shutdown + ' rejects all queued reads and aborts only the four active sources without replay', async () => {
    const signals = [], complete = [];
    const h = harness({ loadAudio: (id, signal) => new Promise(resolve => { signals.push(signal); complete.push(() => resolve({ track: track(id), blob: new Blob(['ID3stale']) })); }) });
    const controller = h.start(); await flush();
    const all = Promise.allSettled(Array.from({ length: 200 }, (_, index) => h.api.getAudio('song-' + index)));
    await flush(); assert.equal(signals.length, 4);
    if (shutdown === 'owner-change') { h.setOwner(2); controller.refresh(); }
    else h.child.emit('pagehide');
    const results = await all;
    assert.equal(results.filter(result => result.status === 'rejected' && result.reason.code === 'audio_session_closed').length, 200);
    assert.ok(signals.every(signal => signal.aborted));
    for (const finish of complete) finish();
    await flush(); assert.equal(signals.length, 4); assert.equal(h.created.length, 0);
    await assert.rejects(h.api.getTrack('song-201')); h.destroy();
  });
}

test('owner changes abort pending fetches, reject callers and discard late bytes', async () => {
  let complete, signal;
  const h = harness({ loadAudio: (id, providedSignal) => new Promise(resolve => { complete = resolve; signal = providedSignal; }) });
  const controller = h.start(); await flush();
  const pending = h.api.getAudio('song-one'); const rejection = assert.rejects(pending, error => error.code === 'audio_session_closed');
  await flush(); h.setOwner(2); controller.refresh(); await rejection;
  assert.equal(signal.aborted, true);
  complete({ track: track('song-one'), blob: new Blob(['ID3stale']) }); await flush();
  assert.equal(h.created.length, 0); await assert.rejects(h.api.play('song-two'));
  assert.equal(h.calls.some(call => call[0] === 'play'), false); h.destroy();
});

test('permission revocation is checked at asynchronous completion even without a refresh event', async () => {
  let complete;
  const h = harness({ loadCatalog: () => new Promise(resolve => { complete = resolve; }) });
  h.start(); await flush();
  const pending = h.api.catalog(); const rejection = assert.rejects(pending, error => error.code === 'audio_session_closed');
  await flush(); h.setPermission(false); complete({ tracks: [track('song-one')], releases: [] });
  await rejection; assert.equal(h.created.length, 0); h.destroy();
});

test('repeated load bootstraps preserve the same document session and never impose a request deadline', async () => {
  let complete, signal;
  const h = harness({ loadAudio: (id, providedSignal) => new Promise(resolve => { complete = resolve; signal = providedSignal; }) });
  h.start(); await flush(); const initial = h.current();
  const pending = h.api.getAudio('song-one'); await flush();
  h.frame.emit('load'); await flush();
  assert.equal(h.current().session, initial.session); assert.equal(signal.aborted, false);
  complete({ track: track('song-one'), blob: new Blob(['ID3eventually']) });
  assert.equal(await (await pending).blob.text(), 'ID3eventually'); h.destroy();
});

test('frame unload and host destruction abort work and release local audio resources', async () => {
  let signal;
  const h = harness(); h.start(); await flush();
  const asset = await h.api.getAudio('song-one');
  h.child.emit('pagehide'); await flush();
  assert.ok(h.revoked.includes(asset.url)); await assert.rejects(h.api.catalog()); h.destroy();
  const second = harness({ loadAudio: (id, providedSignal) => { signal = providedSignal; return new Promise(() => {}); } });
  second.start(); await flush();
  const pending = second.api.getAudio('song-one'); const rejection = assert.rejects(pending);
  await flush(); second.destroy(); await rejection; assert.equal(signal.aborted, true);
});


test('diagnostics show readiness once and classify failed canonical playback without private exception content', async () => {
  const failure = Object.assign(new Error('secret URL https://private.example/token'), {
    name: 'NotAllowedError', code: 'audio_gesture_required', media_code: 4, source_kind: 'host_reference'
  });
  const h = harness({ play: async () => { throw failure; } }); h.start(); await flush();
  h.frame.emit('load'); h.frame.emit('load'); await flush();
  assert.equal(h.diagnostics.filter(value => value.event === 'session_ready').length, 1);
  await assert.rejects(h.api.play('song-one'), value => value.code === 'audio_gesture_required' && !value.message.includes('private'));
  const requested = h.diagnostics.find(value => value.event === 'play_requested');
  assert.equal(requested.method, 'play');
  const failed = h.diagnostics.find(value => value.event === 'rpc_error');
  assert.deepEqual({...failed}, {event:'rpc_error',method:'play',code:'audio_gesture_required',name:'NotAllowedError',media_code:4,source_kind:'host_reference'});
  assert.ok(Object.isFrozen(failed));assert.doesNotMatch(JSON.stringify(h.diagnostics), /private|secret|song-one|https:/);
  h.destroy();
});

test('unrecognized host error codes and names never enter responses or diagnostics', async () => {
  const h = harness({ pause: async () => { throw {code:'private-account-token',name:'private-name',message:'secret'}; } }); h.start(); await flush();
  await assert.rejects(h.api.pause(), value => value.code === 'audio_unavailable');
  assert.deepEqual({...h.diagnostics.find(value => value.event === 'rpc_error')}, {event:'rpc_error',method:'pause',code:'audio_unavailable'});
  h.destroy();
});

test('invalid child play IDs are diagnosed before any host playback request', async () => {
  const h = harness(); h.start(); await flush();
  await assert.rejects(h.api.play({title:'private title'}), error => error.code === 'invalid_track');await flush();
  assert.equal(h.calls.filter(call => call[0] === 'play').length, 0);
  assert.equal(h.diagnostics.filter(value => value.event === 'play_requested').length, 0);
  assert.deepEqual({...h.diagnostics.find(value => value.event === 'rpc_error')}, {event:'rpc_error',method:'play',code:'invalid_track',name:'Error'});
  assert.doesNotMatch(JSON.stringify(h.diagnostics), /private title/);h.destroy();
});

test('detached local media failures retain the exact native play promise and attach bounded safe observers', async () => {
  const h = harness(); h.start(); await flush();
  const media = new h.LocalMedia();media.src = 'api/canvas-audio.php?action=source&track_id=private-song';
  media.result = Promise.reject(Object.assign(new Error('private local source failed'),{name:'NotSupportedError'}));
  const result = media.play(); assert.equal(result, media.result);await assert.rejects(result);await flush();
  assert.deepEqual({...h.diagnostics.find(value => value.event === 'local_playback_error')}, {event:'local_playback_error',name:'NotSupportedError',source_kind:'host_reference'});
  media.error = {code:4}; const event = {target:media};media.emit('error',event);h.child.emit('error',event);await flush();
  const errors = h.diagnostics.filter(value => value.event === 'local_media_error');assert.equal(errors.length,1);
  assert.deepEqual({...errors[0]}, {event:'local_media_error',media_code:4,source_kind:'host_reference'});
  assert.doesNotMatch(JSON.stringify(h.diagnostics), /private|source failed|track_id/);
  h.destroy();await flush();assert.equal(h.LocalMedia.prototype.play,h.nativeMediaPlay);assert.equal(media.count(),0);
});

test('diagnostic cleanup respects authored prototype changes and ignores failures after disconnect', async () => {
  const h = harness();h.start();await flush();
  const authored = function(){return 'authored';};h.LocalMedia.prototype.play=authored;
  const before = h.diagnostics.length;h.destroy();await flush();
  assert.equal(h.LocalMedia.prototype.play,authored);
  h.child.emit('securitypolicyviolation',{effectiveDirective:'media-src',blockedURI:'blob:private'});
  h.child.emit('unhandledrejection',{reason:Object.assign(new Error('private'),{name:'NotAllowedError'})});await flush();
  assert.equal(h.diagnostics.length,before);
});

test('local CSP and rejected operations report only allowlisted classifications, including before handshake', async () => {
  const h = harness();
  h.child.emit('securitypolicyviolation',{effectiveDirective:'media-src',blockedURI:'api/media.php?id=secret'});
  h.start();await flush();
  h.child.emit('securitypolicyviolation',{effectiveDirective:'script-src',blockedURI:'private script'});
  h.child.emit('securitypolicyviolation',{effectiveDirective:'media-src',blockedURI:'blob:private'});
  h.child.emit('unhandledrejection',{reason:{name:'NotAllowedError',code:'audio_gesture_required',message:'private'}});
  h.child.emit('unhandledrejection',{reason:{name:'private-name',code:'private-code',message:'private'}});await flush();
  const events=h.diagnostics.filter(value=>value.event!=='session_ready').map(value=>({...value}));
  assert.deepEqual(events,[{event:'media_csp_violation',source_kind:'host_reference'}, {event:'media_csp_violation',source_kind:'blob'},
    {event:'unhandled_rejection',code:'audio_gesture_required',name:'NotAllowedError'}, {event:'unhandled_rejection'}]);
  assert.doesNotMatch(JSON.stringify(h.diagnostics), /private|secret/);h.destroy();
});

test('diagnostic messages retain frame, nonce, session, document and current-owner authorization', async () => {
  const h=harness();const controller=h.start();await flush();
  const hello=h.current(), base={channel:'stonefellow-canvas-audio',version:1,type:'diagnostic',nonce:hello.nonce,session:hello.session,documentId:hello.documentId,
    diagnostic:{event:'local_media_error',media_code:3,source_kind:'blob',message:'private',url:'https://private'}};
  const original=h.diagnostics.length;
  for(const event of [{source:{},origin:'null',data:base},{source:h.frame.contentWindow,origin:'https://attacker',data:base},
    ...['nonce','session','documentId'].map(key=>({source:h.frame.contentWindow,origin:'null',data:{...base,[key]:'0'.repeat(32)}}))]) h.host.emit('message',event);
  assert.equal(h.diagnostics.length,original);
  h.host.emit('message',{source:h.frame.contentWindow,origin:'null',data:base});
  assert.deepEqual({...h.diagnostics.at(-1)},{event:'local_media_error',media_code:3,source_kind:'blob'});
  h.setOwner(2);controller.refresh();h.host.emit('message',{source:h.frame.contentWindow,origin:'null',data:base});
  assert.equal(h.diagnostics.length,original+1);h.destroy();
});

test('diagnostic emissions have a hard per-canvas cap and callback failures never affect playback', async () => {
  const h=harness();h.start();await flush();
  for(let index=0;index<100;index++)h.child.emit('securitypolicyviolation',{effectiveDirective:'media-src',blockedURI:'blob:private'});
  await flush();assert.equal(h.diagnostics.length,40);
  h.frame.emit('load');await flush();await h.api.play('song-one');assert.equal(h.diagnostics.length,40);h.destroy();
  const callbackFailure=harness({onDiagnostic(){throw new Error('diagnostic callback');}});callbackFailure.start();await flush();
  assert.equal((await callbackFailure.api.play('song-one')).playing,true);callbackFailure.destroy();
});

test('safe RPC diagnostics distinguish verified-seek failures from catalog errors without arbitrary stage strings',async()=>{
 for(const [code,stage] of [['audio_seek_unavailable','resume_seek'],['audio_catalog_unavailable','catalog'],['audio_unavailable','private-stage']]){
  const h=harness({play:async()=>{throw Object.assign(new Error('Private source data'),{code,stage})}});h.start();await flush();
  await assert.rejects(h.api.play('song-one'),error=>error.code===code);
  const failed=h.diagnostics.find(row=>row.event==='rpc_error');assert.equal(failed.code,code);
  assert.equal(failed.stage,stage==='private-stage'?undefined:stage);assert.doesNotMatch(JSON.stringify(h.diagnostics),/Private|private-stage/);h.destroy();
 }
});

test('saved canvas code using catalog song.id plays the selected song instead of resuming a different current track',async()=>{
 const selected=['selected-one','selected-two','selected-three'];
 const h=harness({loadCatalog:async()=>({tracks:selected.map(id=>({...track(id),id:'discarded-upstream-alias'})),releases:[{release_id:'album-selected',id:'discarded-album-alias',title:'Selected album',track_ids:selected}]})});
 h.start();await flush();
 const before=await h.api.getState();assert.equal(before.trackId,'song-one');
 const catalog=await h.api.catalog();
 assert.deepEqual(Array.from(catalog.tracks,track=>[track.track_id,track.id]),selected.map(id=>[id,id]));
 assert.equal(catalog.releases[0].id,catalog.releases[0].release_id);
 // This is the existing saved-code shape; no rebuild or source rewrite occurs.
 const song=catalog.tracks[1];await h.api.play(song.id);
 assert.equal((await h.api.getState()).trackId,'selected-two');
 assert.deepEqual(h.calls.filter(call=>call[0]==='play'),[['play','selected-two']]);h.destroy();
});

test('track objects and canonical strings share one authorized MP3 cache and release the same resource',async()=>{
 const h=harness();h.start();await flush();
 const metadata=await h.api.getTrack({id:'song-two'});
 assert.equal(metadata.track_id,'song-two');assert.equal(metadata.id,'song-two');
 const [one,two,three]=await Promise.all([h.api.getAudio(metadata),h.api.getAudio({track_id:'song-two'}),h.api.getAudio('song-two')]);
 assert.equal(one,two);assert.equal(two,three);assert.equal(one.track.id,'song-two');
 assert.equal(h.calls.filter(call=>call[0]==='source').length,1);
 await h.api.play(metadata);assert.equal((await h.api.getState()).trackId,'song-two');
 assert.equal(await h.api.releaseAudio({track_id:'song-two',id:'song-two'}),true);assert.ok(h.revoked.includes(one.url));
 assert.equal(await h.api.releaseAudio('song-two'),false);h.destroy();
});

test('missing or conflicting explicit track references never resume the current song or reach host controls',async()=>{
 const h=harness();h.start();await flush();
 for(const reference of [undefined,null,{},{id:undefined},{track_id:undefined,id:'song-two'},{track_id:'song-one',id:'song-two'},{id:'https://private.example/audio.mp3'},['song-two']]){
  await assert.rejects(h.api.play(reference),error=>error.code==='invalid_track');
  await assert.rejects(h.api.getTrack(reference),error=>error.code==='invalid_track');
  await assert.rejects(h.api.getAudio(reference),error=>error.code==='invalid_track');
  await assert.rejects(h.api.releaseAudio(reference),error=>error.code==='invalid_track');
 }
 assert.equal(h.calls.length,0);assert.equal(h.diagnostics.filter(row=>row.event==='play_requested').length,0);
 await h.api.play();assert.deepEqual(h.calls,[['play',undefined]]);assert.equal((await h.api.getState()).trackId,'song-one');h.destroy();
});


test('independent host players expose arbitrary-code controls without invoking the canonical player or exporting their sources', async () => {
  const observations = [];
  const h = harness({observeIndependent: (media, track) => { const observation = {media, track, disposed:false}; observations.push(observation); return {dispose(){observation.disposed=true;}}; }});
  h.start(); await flush();
  await h.api.getAudio('song-one'); assert.equal(observations.length, 0, 'raw byte access is never a verified player');
  const player = await h.api.createPlayer({track_id:'song-two', id:'song-two'});
  assert.ok(Object.isFrozen(player)); assert.equal(observations.length, 1); assert.ok(Object.isFrozen(observations[0].track));
  assert.equal(h.hostMedia[0].src, 'blob:host-1');
  assert.ok(!JSON.stringify(h.messages.filter(row=>row.direction==='host')).includes('blob:host-1'));
  const states = []; const unsubscribe = player.subscribe(state=>states.push(state));
  await player.play(); await player.seek(40); await player.setVolume(.3); await player.pause();
  const state = await player.getState();
  assert.deepEqual({...state},{trackId:'song-two',title:'Track song-two',currentTime:40,duration:120,playing:false,volume:.3,muted:false,ended:false,
    seekable:true,playbackRate:1,preservesPitch:true,loop:null});
  assert.ok(states.every(Object.isFrozen)); assert.equal(h.calls.filter(row=>['play','pause','seek','volume'].includes(row[0])).length,0);
  assert.equal(h.calls.filter(row=>row[0]==='catalog').length,2,'create and each play check current selected/public catalog');
  h.hostMedia[0].currentTime=41;h.hostMedia[0].emit('timeupdate');await flush();assert.equal(states.at(-1).currentTime,41);
  const count=states.length;unsubscribe();h.hostMedia[0].emit('timeupdate');await flush();assert.equal(states.length,count);
  assert.equal(await player.dispose(),true);assert.equal(await player.dispose(),false);
  assert.ok(observations[0].disposed);assert.equal(h.hostMedia[0].paused,true);assert.equal(h.hostMedia[0].count(),0);assert.equal(h.hostMedia[0].src,'');
  assert.deepEqual(h.hostRevoked,['blob:host-1']);
  await assert.rejects(player.play(),error=>error.code==='audio_player_disposed');h.destroy();
});

test('independent A/B loops use native media time, validated seeks and pitch-preserving speed', async () => {
  const h = harness(); h.start(); await flush();
  const player = await h.api.createPlayer('song-one'), media = h.hostMedia[0];
  const states = []; player.subscribe(state => states.push(state));
  const selected = await player.setLoop(10, 12);
  assert.deepEqual({...selected.loop}, {startSeconds:10,endSeconds:12});
  assert.ok(Object.isFrozen(selected.loop));
  assert.equal(selected.currentTime,10); assert.equal(selected.playing,false); assert.equal(h.timers.size,0);
  await player.setPlaybackRate(.5);
  assert.equal((await player.play()).playbackRate,.5);
  assert.equal(media.preservesPitch,true);
  assert.equal([...h.timers.values()][0].delay,4000);
  // The clock can reach the expected boundary before buffering media actually advances.
  const first = [...h.timers.values()][0];
  media.currentTime=11; first.callback();
  assert.equal(media.currentTime,11); assert.equal(h.timers.size,1);
  assert.equal([...h.timers.values()][0].delay,2000);
  await player.setPlaybackRate(2);
  assert.equal([...h.timers.values()][0].delay,500);
  media.readyState=2; media.emit('waiting'); assert.equal(h.timers.size,0);
  media.readyState=4; media.emit('playing'); assert.equal([...h.timers.values()][0].delay,500);
  media.currentTime=12.03; media.emit('timeupdate'); await flush();
  assert.equal(media.currentTime,10); assert.equal(states.at(-1).currentTime,10);
  for(const time of [9.9,12,13])await assert.rejects(player.seek(time),{code:'audio_seek_unavailable'});
  await player.seek(11); await player.pause();
  assert.equal(h.timers.size,0);
  media.currentTime=12; media.emit('timeupdate'); assert.equal(media.currentTime,12);
  await player.play(); assert.equal(media.currentTime,10);
  await player.clearLoop(); assert.equal(h.timers.size,0); assert.equal((await player.getState()).loop,null);
  await player.seek(20);
  assert.ok(h.audio.paused); assert.equal(h.audio.currentTime,0);
  h.destroy();
});

test('loop controls fail closed on invalid bounds, stale players and revoked access', async () => {
  let canComplete;
  const h = harness({observeIndependent: (_media, _track, settings) => {canComplete=settings.canComplete;}});
  h.start(); await flush();
  const player=await h.api.createPlayer('song-one'), media=h.hostMedia[0];
  for(const [start,end] of [[NaN,1],[-1,1],[1,1.25],[4,3],[0,Infinity]])await assert.rejects(player.setLoop(start,end),{code:'invalid_audio_request'});
  for(const rate of [0,.49,2.01,NaN,'1.5'])await assert.rejects(player.setPlaybackRate(rate),{code:'invalid_audio_request'});
  await assert.rejects(player.setLoop(115,121),{code:'audio_seek_unavailable'});
  media.duration=NaN;
  assert.equal((await player.getState()).seekable,false);
  await assert.rejects(player.setLoop(1,2),{code:'audio_seek_unavailable'});
  media.duration=120;
  await player.setLoop(.9,1.4);
  await player.setLoop(118,120); await player.play();
  Object.assign(media,{currentTime:120,paused:true,ended:true});
  assert.equal(canComplete(),false);
  media.emit('ended'); await flush();
  assert.equal(media.currentTime,118); assert.equal(media.paused,false);
  const pending=[...h.timers.values()][0];
  h.setPermission(false); pending.callback(); await flush();
  assert.equal(h.timers.size,0); assert.equal(media.paused,true); assert.equal(media.count(),0);
  for(const operation of [()=>player.clearLoop(),()=>player.setLoop(1,2),()=>player.setPlaybackRate(1.5)])await assert.rejects(operation());
  assert.deepEqual(h.hostRevoked,['blob:host-1']); h.destroy();
});

test('independent controls recheck public music scope and preserve native autoplay failures', async () => {
  let allowed=true;
  const h=harness({loadCatalog:async filters=>({tracks:allowed?[track(filters.track_id)]:[],releases:[]})});h.start();await flush();
  const player=await h.api.createPlayer('song-two');
  h.hostMedia[0].failure=Object.assign(new Error('private native error'),{name:'NotAllowedError'});
  await assert.rejects(player.play(),error=>error.code==='audio_gesture_required'&&!error.message.includes('private'));
  assert.equal(h.hostMedia[0].paused,true);assert.equal(h.audio.paused,true);
  h.hostMedia[0].failure=null;allowed=false;
  await assert.rejects(player.play(),error=>error.code==='track_unavailable');assert.equal(h.hostMedia[0].paused,true);
  for(const promise of [player.seek(NaN),player.seek(121),player.setVolume(-1),player.setVolume(2)])await assert.rejects(promise);
  await assert.rejects(h.api.createPlayer({track_id:'one',id:'two'}),error=>error.code==='invalid_track');
  await player.dispose();h.destroy();
});

for(const shutdown of ['owner','permission','destroy','unload'])test('independent '+shutdown+' releases every player including paused media and rejects stale handles',async()=>{
  const disposed=[];const h=harness({observeIndependent:(media)=>({dispose(){disposed.push(media)}})});const controller=h.start();await flush();
  const one=await h.api.createPlayer('song-one'),two=await h.api.createPlayer('song-two');await one.play();
  assert.equal(h.hostMedia.filter(media=>!media.paused).length,1);
  if(shutdown==='owner'){h.setOwner(2);controller.refresh();}
  if(shutdown==='permission'){h.setPermission(false);h.hostMedia[0].emit('timeupdate');}
  if(shutdown==='destroy')h.destroy();
  if(shutdown==='unload')h.child.emit('pagehide');
  await flush();assert.equal(disposed.length,2);assert.equal(h.hostRevoked.length,2);
  for(const media of h.hostMedia){assert.equal(media.paused,true);assert.equal(media.src,'');assert.equal(media.count(),0);}
  await assert.rejects(one.play());await assert.rejects(two.getState());h.destroy();
});

test('independent creation discarded after account change never constructs host media',async()=>{
  let finish,signal;const h=harness({loadAudio:(id,provided)=>{signal=provided;return new Promise(resolve=>{finish=resolve;});}});const controller=h.start();await flush();
  const pending=h.api.createPlayer('song-one');const rejected=assert.rejects(pending,error=>error.code==='audio_session_closed');await flush();
  h.setOwner(2);controller.refresh();await rejected;assert.equal(signal.aborted,true);
  finish({track:track('song-one'),blob:new Blob(['ID3stale'])});await flush();assert.equal(h.hostMedia.length,0);assert.equal(h.hostURLs.length,0);h.destroy();
});

test('independent handles are bound to exact session, frame and owner with replay protection',async()=>{
 const h=harness();h.start();await flush();const player=await h.api.createPlayer('song-one');await player.seek(17);
 const request=h.messages.find(row=>row.direction==='child'&&row.data.method==='playerSeek').data;
 for(const event of [{source:{},origin:'null',data:{...request,id:'r-100'}},
   {source:h.frame.contentWindow,origin:'https://attacker.invalid',data:{...request,id:'r-100'}},
   ...['nonce','session','documentId'].map(key=>({source:h.frame.contentWindow,origin:'null',data:{...request,id:'r-100',[key]:'0'.repeat(32),args:[request.args[0],29]}})),
   {source:h.frame.contentWindow,origin:'null',data:request}])h.host.emit('message',event);
 await flush();assert.equal(h.hostMedia[0].currentTime,17);await player.seek(18);assert.equal(h.hostMedia[0].currentTime,18);
 h.destroy();await flush();assert.equal(h.hostMedia[0].count(),0);
});


test('disposing a host player settles an outstanding native play promise before its late resolution',async()=>{
 const h=harness();h.start();await flush();const player=await h.api.createPlayer('song-one');
 let resolve;h.hostMedia[0].pendingPlay=new Promise(done=>{resolve=done});
 const play=player.play();const rejection=assert.rejects(play,error=>error.code==='audio_player_disposed');await flush();
 await player.dispose();await rejection;resolve();await flush();
 assert.equal(h.hostMedia[0].paused,true);assert.equal(h.hostMedia[0].count(),0);assert.equal(h.hostRevoked.length,1);h.destroy();
});

test('independent player resource cap includes concurrent creations and disposal releases capacity',async()=>{
 const h=harness();h.start();await flush();
 const results=await Promise.allSettled(Array.from({length:40},(_,index)=>h.api.createPlayer('song-'+index)));
 const live=results.filter(row=>row.status==='fulfilled').map(row=>row.value);
 assert.equal(live.length,32);assert.equal(results.filter(row=>row.status==='rejected'&&row.reason.code==='audio_busy').length,8);
 assert.equal(h.hostMedia.length,32);assert.ok(h.hostMedia.every(media=>media.paused));
 await live[0].dispose();const replacement=await h.api.createPlayer('replacement');assert.equal((await replacement.getState()).trackId,'replacement');
 h.destroy();await flush();assert.equal(h.hostRevoked.length,33);assert.ok(h.hostMedia.every(media=>media.count()===0));
});


test('disposing during the fresh scope check aborts its read and settles play without waiting for late metadata',async()=>{
 let held=false,signal,finish;
 const h=harness({loadCatalog:async(filters,provided)=>{
  if(held){signal=provided;return new Promise(resolve=>{finish=resolve;});}
  return {tracks:[track(filters.track_id)],releases:[]};
 }});h.start();await flush();const player=await h.api.createPlayer('song-one');held=true;
 const pending=player.play(),rejected=assert.rejects(pending,error=>error.code==='audio_player_disposed');await flush();
 await player.dispose();await rejected;assert.equal(signal.aborted,true);
 finish({tracks:[track('song-one')],releases:[]});await flush();assert.equal(h.hostMedia[0].paused,true);assert.equal(h.hostRevoked.length,1);h.destroy();
});

test('pausing interrupts pending independent plays and leaves a later loop start intact', async () => {
  let holdCatalog = false;
  const pendingCatalog = [];
  const h = harness({ loadCatalog: (filters, signal) => {
    const result = { tracks: [track(filters.track_id)], releases: [] };
    if (!holdCatalog) return Promise.resolve(result);
    return new Promise(resolve => pendingCatalog.push({ signal, finish: () => resolve(result) }));
  } });
  h.start(); await flush();
  const player = await h.api.createPlayer('song-one'), media = h.hostMedia[0];
  const nativePlay = media.play.bind(media);
  let nativeStarts = 0;
  media.play = () => { nativeStarts++; return nativePlay(); };
  await player.setLoop(2, 3);

  holdCatalog = true;
  const interrupted = [player.play(), player.play()].map(pending => assert.rejects(pending, { code: 'audio_playback_interrupted' }));
  await flush();
  assert.equal(pendingCatalog.length, 2);
  assert.equal((await player.pause()).playing, false);
  await Promise.all(interrupted);
  assert.ok(pendingCatalog.every(read => read.signal.aborted));
  assert.equal(nativeStarts, 0);
  assert.equal(h.timers.size, 0);

  holdCatalog = false;
  assert.equal((await player.play()).playing, true);
  pendingCatalog.forEach(read => read.finish());
  await flush();
  assert.equal(nativeStarts, 1);
  assert.equal(h.timers.size, 1);
  await player.pause();

  let resolveNative;
  media.pendingPlay = new Promise(resolve => { resolveNative = resolve; });
  const nativeInterrupted = assert.rejects(player.play(), { code: 'audio_playback_interrupted' });
  await flush();
  assert.equal(nativeStarts, 2);
  await player.pause();
  await nativeInterrupted;
  resolveNative();
  await flush();
  assert.equal((await player.getState()).playing, false);
  assert.equal(h.timers.size, 0);
  h.destroy();
});

const orderedPlaylist = () => ({ref:'artist-17',source:'artist',title:'Side sequence',description:'An ordered resource collection',revision_hash:'a'.repeat(64),
 items:[
  {item_id:3,position:0,track_id:'song-one',title:'First occurrence',duration_seconds:120,available:true,reason:'available'},
  {item_id:8,position:2,track_id:null,title:'Unavailable track',duration_seconds:0,available:false,reason:'unavailable'},
  {item_id:11,position:4,track_id:'song-one',title:'Repeated occurrence',duration_seconds:120,available:true,reason:'available'},
  {item_id:12,position:7,track_id:'song-remote',title:'Public non-hosted song',duration_seconds:100,available:false,reason:'source_unavailable'}
 ]});

test('catalog preserves ordered playlist entries and repeated songs separately from deduplicated MP3 nodes',async()=>{
 const playlist=orderedPlaylist();const h=harness({loadCatalog:async()=>({tracks:[track('song-one')],releases:[],playlists:[playlist]})});
 h.start();await flush();const result=await h.api.catalog();
 assert.deepEqual(JSON.parse(JSON.stringify(result.playlists)),[playlist]);assert.equal(result.tracks.length,1);
 assert.deepEqual(Array.from(result.playlists[0].items,item=>item.position),[0,2,4,7]);
 assert.deepEqual(Array.from(result.playlists[0].items,item=>item.track_id),['song-one',null,'song-one','song-remote']);
 assert.equal(h.calls.filter(call=>call[0]==='play').length,0);assert.equal(h.hostMedia.length,0);h.destroy();
});

test('playlist DTO projection strips private fields and forces unavailable entries to generic placeholders',async()=>{
 const playlist={...orderedPlaylist(),user_id:47,owner_email:'private-owner',storage_path:'/private/raw',items:orderedPlaylist().items.map(item=>({...item,raw_source:'/private/song'}))};
 playlist.items[1]={...playlist.items[1],track_id:'hidden-private-song',title:'Hidden private title',duration_seconds:800};
 const h=harness({loadCatalog:async()=>({tracks:[track('song-one')],releases:[],playlists:[playlist]})});h.start();await flush();
 const result=await h.api.catalog(),serialized=JSON.stringify(result.playlists);
 assert.doesNotMatch(serialized,/user_id|owner_email|storage_path|raw_source|private|Hidden|800/);
 assert.deepEqual(JSON.parse(JSON.stringify(result.playlists[0].items[1])),{item_id:8,position:2,track_id:null,title:'Unavailable track',duration_seconds:0,available:false,reason:'unavailable'});h.destroy();
});

test('legacy catalogs and explicit empty playlist resources remain usable without expanding audio scope',async()=>{
 for(const playlists of [undefined,[]]){
  const h=harness({loadCatalog:async()=>({tracks:[],releases:[],...(playlists===undefined?{}:{playlists})})});h.start();await flush();
  const result=await h.api.catalog();assert.equal(result.tracks.length,0);assert.equal(result.playlists.length,0);assert.equal(result.releases.length,0);h.destroy();
 }
});

test('malformed or authority-conflicting playlist responses fail closed before authored code receives them',async()=>{
 const invalid=[{...orderedPlaylist(),ref:'own-17'}, {...orderedPlaylist(),revision_hash:'unversioned'},
  {...orderedPlaylist(),items:[orderedPlaylist().items[0],orderedPlaylist().items[0]]},
  {...orderedPlaylist(),items:[{...orderedPlaylist().items[0],track_id:null}]},
  {...orderedPlaylist(),items:[{...orderedPlaylist().items[0],available:false}]}];
 for(const playlist of invalid){
  const h=harness({loadCatalog:async()=>({tracks:[],releases:[],playlists:[playlist]})});h.start();await flush();
  await assert.rejects(h.api.catalog(),error=>error.code==='invalid_catalog');assert.equal(h.calls.filter(call=>call[0]==='play').length,0);h.destroy();
 }
});

const observation = (temperature, changes = {}) => ({
  status: 'fresh', fetched_at: new Date(Date.now() - 1000).toISOString(),
  expires_at: new Date(Date.now() + 60000).toISOString(),
  source: 'National Weather Service', timezone: 'America/Phoenix',
  rows: [{ temperature_f: temperature }], ...changes
});

test('named data reads share the trusted sandbox, coalesce pending reads and reauthorize later requests', async () => {
  let permitted = true;
  const aliases = [];
  const h = harness({ dataAliases: ['phoenix', 'venue'], loadData: async alias => {
    aliases.push(alias);
    if (!permitted) throw new Error('Disabled');
    return alias === 'phoenix' ? observation(94) : observation(71);
  } });
  h.start();
  const first = h.data.get('phoenix');
  assert.equal(first, h.data.get('phoenix'));
  const [phoenix, venue] = await Promise.all([first, h.data.get('venue')]);
  assert.deepEqual(aliases, ['phoenix', 'venue']);
  assert.equal(phoenix.status, 'fresh'); assert.equal(phoenix.rows[0].temperature_f, 94);
  assert.equal(venue.rows[0].temperature_f, 71);
  assert.ok(Object.isFrozen(phoenix) && Object.isFrozen(phoenix.rows) && Object.isFrozen(phoenix.rows[0]));
  const message = h.messages.find(item => item.direction === 'child' && item.data.method === 'getData').data;
  h.host.emit('message', { source: {}, origin: 'null', data: { ...message, id: 'r-100' } });
  h.host.emit('message', { source: h.frame.contentWindow, origin: 'https://artist.example', data: { ...message, id: 'r-100' } });
  h.host.emit('message', { source: h.frame.contentWindow, origin: 'null', data: { ...message, documentId: '1'.repeat(32), id: 'r-100' } });
  h.host.emit('message', { source: h.frame.contentWindow, origin: 'null', data: message });
  await flush();
  assert.equal((await h.data.get('unbound')).status, 'unavailable');
  await assert.rejects(h.data.get('https://other.example/read'), error => error.code === 'invalid_data_source');
  assert.deepEqual(aliases, ['phoenix', 'venue']);
  permitted = false;
  assert.equal((await h.data.get('phoenix')).status, 'unavailable');
  assert.deepEqual(aliases, ['phoenix', 'venue', 'phoenix']);
  h.destroy();
});

test('data expiry, unsupported fields and detached host fallback cannot be treated as fresh', async () => {
  let result = observation(94, { expires_at: new Date(Date.now() - 1).toISOString() });
  const h = harness({ dataAliases: ['phoenix'], loadData: async () => result });
  h.start();
  assert.equal((await h.data.get('phoenix')).status, 'stale');
  for (const invalid of [observation(94, { expires_at: 'unknown' }), observation(94, { rows: [{ temperature_f: { value: 94 } }] })]) {
    result = invalid;
    const data = await h.data.get('phoenix');
    assert.equal(data.status, 'unavailable');
    assert.equal(data.rows.length, 0);
  }
  h.destroy();
  const preview = harness({ dataAliases: ['phoenix'] });
  preview.start();
  assert.equal((await preview.data.get('phoenix')).status, 'unavailable');
  preview.destroy();
});

test('data in flight cannot cross an account switch or canvas close', async () => {
  let finish, signal;
  const h = harness({ dataAliases: ['phoenix'], loadData: (_alias, requestSignal) => {
    signal = requestSignal;
    return new Promise(resolve => { finish = resolve; });
  } });
  const host = h.start();
  const read = h.data.get('phoenix');
  const rejected = assert.rejects(read, error => error.code === 'audio_session_closed');
  await flush();
  assert.equal(signal.aborted, false);
  h.setOwner(2);
  host.refresh();
  finish(observation(94));
  await rejected;
  assert.equal(signal.aborted, true);
  assert.equal(h.messages.filter(item => item.direction === 'host' && item.data.type === 'response').length, 0);
  h.destroy();
});

test('venue-scoped data reads coalesce by source and location and refuse scope widening', async () => {
  const reads = [];
  const h = harness({
    dataAliases: ['weather-nearby', 'fixed-weather', 'nearby'],
    dataLocationAliases: ['weather-nearby'],
    loadData: async (alias, _signal, locationId) => {
      reads.push([alias, locationId]);
      if (locationId === 'show-withdrawn') throw new Error('Venue withdrawn');
      return observation(locationId === 'show-north' ? 58 : 74);
    }
  });
  h.start();
  const north = h.data.get('weather-nearby', { location_id: 'show-north' });
  const east = h.data.get('weather-nearby', { location_id: 'show-east' });
  assert.equal(north, h.data.get('weather-nearby', { location_id: 'show-north' }));
  assert.notEqual(north, east);
  assert.equal((await north).rows[0].temperature_f, 58);
  assert.equal((await east).rows[0].temperature_f, 74);
  assert.deepEqual(reads, [['weather-nearby', 'show-north'], ['weather-nearby', 'show-east']]);

  assert.equal((await h.data.get('weather-nearby')).status, 'unavailable');
  assert.equal((await h.data.get('fixed-weather', { location_id: 'show-north' })).status, 'unavailable');
  assert.equal((await h.data.get('nearby', { location_id: 'show-north' })).status, 'unavailable');
  assert.equal((await h.data.get('missing', { location_id: 'show-north' })).status, 'unavailable');
  for (const options of [
    { location_id: 'city-0455000' }, { location_id: 'show-../other' }, { location_id: 'show-Upper' },
    { location_id: 'show-north', url: 'https://example.invalid/' }, { location_id: undefined }, null, []
  ]) {
    await assert.rejects(h.data.get('weather-nearby', options), error => error.code === 'invalid_data_source');
  }
  assert.equal(reads.length, 2);
  assert.equal((await h.data.get('fixed-weather', {})).status, 'fresh');
  assert.equal((await h.data.get('weather-nearby', { location_id: 'show-withdrawn' })).status, 'unavailable');
  assert.deepEqual(reads.slice(2), [['fixed-weather', undefined], ['weather-nearby', 'show-withdrawn']]);
  h.destroy();
});

const proximitySource = { alias: 'venue', product: 'location.proximity', location_id: 'point-33450000_-112070000_100' };
const proximityTarget = (changes = {}) => observation(null, {
  alias: proximitySource.alias, product: proximitySource.product,
  location: { id: proximitySource.location_id, kind: 'point', title: 'Public venue' },
  rows: [{ lat: 33.45, lon: -112.07, radius_m: 100 }], ...changes
});

test('proximity checks require a focused and activated frame and return only an approved status', async () => {
  const loads = [], checks = [];
  const h = harness({ dataAliases: ['venue', 'weather'], dataProximitySources: [proximitySource],
    loadData: async (alias, _signal, locationId) => {
      loads.push([alias, locationId]);
      h.host.navigator.userActivation.isActive = false;
      return proximityTarget();
    }
  });
  h.host.SFCanvasLocation = { check: async (point, signal, activated) => {
    checks.push({ point, signal, activated });
    return { status: 'inside', coords: { latitude: 20, longitude: 40 }, accuracy: 2 };
  } };
  h.start();
  assert.equal((await h.data.checkProximity('venue')).status, 'denied');
  h.host.navigator.userActivation.isActive = true;
  h.host.document.activeElement = {};
  assert.equal((await h.data.checkProximity('venue')).status, 'denied');
  h.host.document.activeElement = h.frame;
  assert.equal((await h.data.checkProximity('weather')).status, 'unavailable');
  assert.equal((await h.data.checkProximity('point-33450000_-112070000_100')).status, 'unavailable');
  assert.equal((await h.data.get('venue')).status, 'unavailable');
  assert.equal(loads.length, 0);

  const result = await h.data.checkProximity('venue');
  assert.equal(result.status, 'inside');
  assert.deepEqual(Object.keys(result), ['status']);
  assert.ok(Object.isFrozen(result));
  assert.deepEqual(loads, [['venue', undefined], ['venue', undefined]]);
  assert.equal(checks.length, 1);
  assert.equal(checks[0].activated, true);
  assert.equal(checks[0].signal.aborted, false);
  assert.deepEqual({ ...checks[0].point }, proximityTarget().rows[0]);
  const response = h.messages.filter(item => item.direction === 'host' && item.data.type === 'response').at(-1).data;
  assert.deepEqual({ ...response.value }, { status: 'inside' });

  const message = h.messages.filter(item => item.direction === 'child' && item.data.method === 'checkProximity').at(-1).data;
  h.host.navigator.userActivation.isActive = true;
  for (const event of [{ source: {} }, { origin: 'https://attacker.example' }, { isTrusted: false }]) {
    h.host.emit('message', { source: h.frame.contentWindow, origin: 'null', isTrusted: true,
      data: { ...message, id: 'r-100', activated: true }, ...event });
  }
  await flush();
  assert.equal(checks.length, 1);
  assert.equal(loads.length, 2);
  h.destroy();
});

test('proximity requires the exact attached public point and reauthorizes any location result', async () => {
  let data = proximityTarget({ status: 'stale' }), result = 'inside', replaceAfterCheck = null;
  let checks = 0;
  const h = harness({ dataAliases: ['ordinary'], dataProximitySources: [proximitySource,
    { ...proximitySource, alias: 'unbound-point', location_id: 'city-0455000' },
    { ...proximitySource, alias: 'wrong-product', product: 'geography.place' }],
  loadData: async () => data });
  h.host.navigator.userActivation.isActive = true;
  h.host.SFCanvasLocation = { check: async () => {
    checks++;
    if (replaceAfterCheck) data = replaceAfterCheck;
    return { status: result };
  } };
  h.start();
  assert.equal((await h.data.checkProximity('unbound-point')).status, 'unavailable');
  assert.equal((await h.data.checkProximity('wrong-product')).status, 'unavailable');
  assert.equal((await h.data.get('ordinary')).status, 'unavailable');
  for (const changes of [{ status: 'stale' }, { product: 'geography.place' }, { alias: 'wrong' },
    { expires_at: new Date(Date.now() - 1000).toISOString() }, { location: { kind: 'city', id: 'city-0455000' } },
    { rows: [{ lat: 33.45, lon: -112.07, radius_m: 200 }] }]) {
    data = proximityTarget(changes);
    assert.equal((await h.data.checkProximity('venue')).status, 'unavailable');
  }
  assert.equal(checks, 0);
  for (const status of ['inside', 'outside', 'uncertain', 'denied', 'unavailable', 'unsupported']) {
    data = proximityTarget(); result = status;
    assert.equal((await h.data.checkProximity('venue')).status, status === 'unsupported' ? 'unavailable' : status);
  }
  data = proximityTarget(); result = 'inside'; replaceAfterCheck = proximityTarget({ status: 'unavailable' });
  assert.equal((await h.data.checkProximity('venue')).status, 'unavailable');
  h.destroy();
});

test('a pending location check is isolated to one live canvas session and aborts on account or scene change', async () => {
  for (const reason of ['account', 'close', 'unload']) {
    let finish, signal, loads = 0;
    const h = harness({ dataProximitySources: [proximitySource], loadData: async () => { loads++; return proximityTarget(); } });
    h.host.navigator.userActivation.isActive = true;
    h.host.SFCanvasLocation = { check: async (_point, requestSignal) => {
      signal = requestSignal;
      return new Promise(resolve => { finish = resolve; });
    } };
    const host = h.start();
    const read = h.data.checkProximity('venue');
    await flush();
    assert.equal(signal.aborted, false);
    assert.equal((await h.data.checkProximity('venue')).status, 'unavailable');
    if (reason === 'account') { h.setOwner(2); host.refresh(); }
    if (reason === 'close') h.destroy();
    if (reason === 'unload') h.child.emit('pagehide');
    finish({ status: 'inside' });
    assert.equal((await read).status, 'unavailable');
    assert.equal(signal.aborted, true);
    assert.equal(loads, 1);
    h.destroy();
  }
});
