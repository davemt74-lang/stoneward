'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/canvas-image-review.js'), 'utf8');
const imageId = 'ab'.repeat(16);
const secondId = 'cd'.repeat(16);

function fixture() {
  let document;
  class Element extends EventTarget {
    constructor(tag) {
      super();
      this.tagName = tag;
      this.children = [];
      this.disabled = false;
      this.isConnected = false;
      this.value = '';
    }
    append(...children) {
      for (const child of children) {
        child.parent = this;
        child.isConnected = this.isConnected;
        this.children.push(child);
      }
    }
    setAttribute(name, value) { this[name] = value; }
    querySelector(selector) {
      if (selector === 'header p') return this.querySelector('header')?.querySelector('p');
      return this.children.find(child => selector.startsWith('.')
        ? child.className === selector.slice(1) : child.tagName === selector) ||
        this.children.map(child => child.querySelector(selector)).find(Boolean) || null;
    }
    set innerHTML(value) {
      this.html = value;
      const form = new Element('form');
      const header = new Element('header');
      header.append(new Element('h2'), new Element('p'));
      const body = new Element('div');
      body.className = 'sf-canvas-image-review-body';
      form.append(header, body, new Element('footer'));
      this.append(form);
    }
    focus() { document.activeElement = this; }
    showModal() { this.open = true; }
    close() {
      this.open = false;
      queueMicrotask(() => this.dispatchEvent(new Event('close')));
    }
    remove() {
      this.isConnected = false;
      this.parent.children = this.parent.children.filter(child => child !== this);
    }
  }
  const body = new Element('body');
  body.isConnected = true;
  const opener = new Element('button');
  body.append(opener);
  document = {body, activeElement: opener, baseURI: 'https://fixture.test/stonefellow/', createElement: tag => new Element(tag)};
  const window = new EventTarget();
  window.location = {origin: 'https://fixture.test'};
  vm.runInNewContext(source, {window, document, URL});
  const findAll = (root, tag) => root.children.flatMap(child => [
    ...(child.tagName === tag ? [child] : []), ...findAll(child, tag)
  ]);
  const dialog = () => findAll(body, 'dialog')[0];
  const press = element => element.dispatchEvent(new Event('click'));
  const submit = () => dialog().querySelector('form').dispatchEvent(new Event('submit', {cancelable: true}));
  return {api: window.SFCanvasImageReview, body, document, opener, dialog, findAll, press, submit};
}

test('Image review waits for previews, selects one result and restores focus', async () => {
  const f = fixture();
  const result = f.api.choose([{id: imageId, title: 'First option'}, {id: secondId, title: 'Second option'}]);
  const dialog = f.dialog();
  const [first, second] = f.findAll(dialog, 'input');
  const [cancel, apply] = f.findAll(dialog, 'button');
  assert.equal(apply.disabled, true);
  assert.equal(second.disabled, true);
  f.findAll(dialog, 'img')[1].dispatchEvent(new Event('load'));
  assert.equal(second.disabled, false);
  assert.equal(first.disabled, true);
  second.dispatchEvent(new Event('change'));
  assert.equal(apply.disabled, false);
  assert.equal(f.document.activeElement, cancel);
  f.submit();
  assert.equal(await result, secondId);
  assert.equal(f.dialog(), undefined);
  assert.equal(f.document.activeElement, f.opener);
});

test('Failed previews cannot remain selected', async () => {
  const f = fixture();
  const result = f.api.choose([{id: imageId}]);
  const image = f.findAll(f.dialog(), 'img')[0];
  image.dispatchEvent(new Event('load'));
  f.findAll(f.dialog(), 'input')[0].dispatchEvent(new Event('change'));
  image.dispatchEvent(new Event('error'));
  assert.equal(f.findAll(f.dialog(), 'button')[1].disabled, true);
  f.press(f.findAll(f.dialog(), 'button')[0]);
  assert.equal(await result, null);
});

test('Only valid unique image IDs and same-origin URLs are offered', async () => {
  const f = fixture();
  const refId = 'ref-' + secondId;
  const result = f.api.choose([{id: imageId}, {id: imageId}, {id: refId}, {id: 'invalid'}, {id: secondId}], {
    imageUrl: asset => asset.id === secondId ? 'https://other.test/image' : '/image?id=' + asset.id
  });
  assert.deepEqual(f.findAll(f.dialog(), 'input').map(input => input.value), [imageId, refId]);
  f.dialog().close();
  assert.equal(await result, null);
  assert.equal(await f.api.choose([{id: 'invalid'}]), null);
});

test('Abort, Escape and replacement each settle their own dialog safely', async () => {
  const f = fixture();
  const controller = new AbortController();
  controller.abort();
  assert.equal(await f.api.choose([{id: imageId}], {signal: controller.signal}), null);
  assert.equal(f.dialog(), undefined);
  const first = f.api.choose([{id: imageId}]);
  const secondController = new AbortController();
  const second = f.api.choose([{id: secondId}], {signal: secondController.signal});
  assert.equal(await first, null);
  assert.equal(f.findAll(f.dialog(), 'input')[0].value, secondId);
  secondController.abort();
  assert.equal(await second, null);
  const third = f.api.choose([{id: imageId}]);
  const cancel = new Event('cancel', {cancelable: true});
  f.dialog().dispatchEvent(cancel);
  assert.equal(cancel.defaultPrevented, true);
  assert.equal(await third, null);
  assert.equal(f.document.activeElement, f.opener);
});

test('Generation confirmation supports unlimited accounts and explicit variation review', async () => {
  const f = fixture();
  const result = f.api.confirmGeneration({requested_count: 4, today_remaining: null, daily_cap: null, generation_enabled: true, review_images: true});
  const count = f.dialog().querySelector('select');
  assert.equal(count.value, '4');
  assert.equal(count.children.length, 4);
  assert.equal(f.findAll(f.dialog(), 'input')[0].checked, true);
  f.submit();
  assert.deepEqual(JSON.parse(JSON.stringify(await result)), {count: 4, review: true});
});

test('Finite remaining capacity bounds the approved image count', async () => {
  const f = fixture();
  const result = f.api.confirmGeneration({requested_count: 4, today_remaining: 2, daily_cap: 5, generation_enabled: true, review_images: true});
  const count = f.dialog().querySelector('select');
  const review = f.findAll(f.dialog(), 'input')[0];
  assert.equal(count.children.length, 2);
  assert.equal(count.value, '2');
  count.value = '1';
  count.dispatchEvent(new Event('change'));
  assert.equal(review.checked, false);
  assert.equal(review.disabled, true);
  f.submit();
  assert.deepEqual(JSON.parse(JSON.stringify(await result)), {count: 1, review: false});
});

test('Single-image operations hide comparison and approve only one image', async () => {
  const f = fixture();
  const result = f.api.confirmGeneration({requested_count: 1, max_count: 1, today_remaining: null,
    daily_cap: null, generation_enabled: true, review_images: true});
  const count = f.dialog().querySelector('select');
  const review = f.findAll(f.dialog(), 'input')[0];
  assert.equal(count.children.length, 1);
  assert.equal(count.value, '1');
  assert.equal(review.checked, false);
  assert.equal(review.disabled, true);
  assert.equal(review.parent.hidden, true);
  f.submit();
  assert.deepEqual(JSON.parse(JSON.stringify(await result)), {count: 1, review: false});
});

test('Disabled or exhausted generation preserves the saved-image choice', async () => {
  for (const preflight of [
    {generation_enabled: false, today_remaining: null, daily_cap: null},
    {generation_enabled: true, today_remaining: 0, daily_cap: 5},
    {generation_enabled: true, today_remaining: null, daily_cap: 0}
  ]) {
    const f = fixture();
    const result = f.api.confirmGeneration(preflight);
    const [cancel, saved, proceed] = f.findAll(f.dialog(), 'button');
    assert.equal(proceed.disabled, true);
    assert.equal(f.dialog().querySelector('select').disabled, true);
    assert.equal(cancel.disabled, false);
    f.press(saved);
    assert.deepEqual(JSON.parse(JSON.stringify(await result)), {use_saved: true});
  }
});
