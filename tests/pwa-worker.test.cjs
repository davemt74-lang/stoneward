const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '..', 'service-worker.js'), 'utf8');
const scope = 'https://stonefellow.test/music-site/';
const id = 'a'.repeat(32);
const link = 'jukebox.php?id=' + id;

function worker(windows = []) {
  const handlers = new Map();
  const shown = [];
  const opened = [];
  const self = {
    addEventListener: (type, handler) => handlers.set(type, handler),
    registration: {scope, showNotification: async (...args) => shown.push(args)},
    clients: {matchAll: async () => windows, openWindow: async url => opened.push(url)}
  };
  vm.runInNewContext(source, {self, URL});
  async function dispatch(type, event) {
    let pending;
    handlers.get(type)({...event, waitUntil: result => { pending = result; }});
    await pending;
  }
  return {shown, opened, dispatch};
}

test('session alerts use stable, event-specific tags and a scoped guest link', async () => {
  const app = worker();
  const alert = event => ({json: () => ({title: 'Stonefellow', body: 'Session update', url: link, tag: `jukebox:${id}:${event}`})});
  await app.dispatch('push', {data: alert('reminder:123')});
  await app.dispatch('push', {data: alert('live')});
  assert.equal(app.shown[0][1].tag, `stonefellow-jukebox:${id}:reminder:123`);
  assert.equal(app.shown[1][1].tag, `stonefellow-jukebox:${id}:live`);
  assert.equal(app.shown[1][1].data.url, scope + link);
  assert.equal(app.shown[1][1].icon, scope + 'assets/icons/stonefellow-pwa-192.png');
});

test('push display and click reject external, out-of-scope, and host URLs', async () => {
  const app = worker();
  for (const url of ['https://other.test/music-site/' + link, '/' + link, link + '&host=1', link + '#preview']) {
    await app.dispatch('push', {data: {json: () => ({title: 'Stonefellow', body: '', url})}});
    await app.dispatch('notificationclick', {notification: {data: {url}, close: () => assert.fail('invalid notification')}});
  }
  assert.deepEqual(app.shown, []);
  assert.deepEqual(app.opened, []);
});

test('notification taps focus only the exact guest session or open it', async () => {
  let focused = 0;
  let closed = 0;
  const notification = {data: {url: scope + link}, close: () => { closed++; }};
  const matching = {url: scope + link, focus: async () => { focused++; }};
  const host = {url: scope + link + '&host=1', focus: async () => assert.fail('host tab')};
  const existing = worker([host, matching]);
  const missing = worker([host]);
  await existing.dispatch('notificationclick', {notification});
  await missing.dispatch('notificationclick', {notification});
  assert.equal(focused, 1);
  assert.equal(closed, 2);
  assert.deepEqual(existing.opened, []);
  assert.deepEqual(missing.opened, [scope + link]);
});
