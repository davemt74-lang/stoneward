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

test('typed canvas app alerts open their exact approved canvas route', async () => {
  const canvasLink = `index.php?view=canvas-gallery&id=${id}&open=1`;
  const shownUrl = scope + canvasLink;
  let focused = 0;
  const detail = {url: shownUrl.replace('&open=1', ''), focus: async () => assert.fail('catalog detail tab')};
  const exact = {url: shownUrl, focus: async () => { focused++; }};
  const app = worker([detail, exact]);
  await app.dispatch('push', {data: {json: () => ({type: 'canvas-app', title: 'Stonefellow', body: 'Canvas update', url: canvasLink, tag: 'ignored'})}});
  const [title, options] = app.shown[0];
  assert.equal(title, 'Stonefellow');
  assert.equal(options.tag, `stonefellow-canvas-app:${id}`);
  assert.equal(options.data.url, shownUrl);
  assert.equal(options.data.type, 'canvas-app');
  const notification = {data: options.data, close: () => {}};
  await app.dispatch('notificationclick', {notification});
  assert.equal(focused, 1);
  assert.deepEqual(app.opened, []);
  const missing = worker([detail]);
  await missing.dispatch('notificationclick', {notification});
  assert.deepEqual(missing.opened, [shownUrl]);
});

test('canvas app push routes reject missing type, altered action, and out-of-scope paths', async () => {
  const canvasLink = `index.php?view=canvas-gallery&id=${id}&open=1`;
  const app = worker();
  const blocked = [
    {url: canvasLink},
    {type: 'unknown', url: canvasLink},
    {type: 'canvas-app', url: canvasLink.replace('&open=1', '')},
    {type: 'canvas-app', url: canvasLink + '&open=1'},
    {type: 'canvas-app', url: canvasLink.replace('view=canvas-gallery', 'view=account')},
    {type: 'canvas-app', url: '/' + canvasLink},
    {type: 'canvas-app', url: 'https://other.test/music-site/' + canvasLink},
    {type: 'canvas-app', url: link},
  ];
  for (const data of blocked) {
    await app.dispatch('push', {data: {json: () => ({title: 'Stonefellow', body: 'Canvas update', ...data})}});
    await app.dispatch('notificationclick', {notification: {data, close: () => assert.fail('invalid canvas route')}});
  }
  assert.deepEqual(app.shown, []);
  assert.deepEqual(app.opened, []);
});
