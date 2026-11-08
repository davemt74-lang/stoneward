'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { Body, Common } = require('../assets/vendor/canvas/matter.js');

test('Matter ignores prototype keys in copied physics options', () => {
  const options = JSON.parse('{"label":"Bottle","plugin":{"__proto__":{"sfMatterPolluted":true},"mass":2},"constructor":{"prototype":{"sfMatterPolluted":true}}}');
  const body = Body.create(options);
  assert.equal(body.label, 'Bottle');
  assert.equal(body.plugin.mass, 2);
  assert.equal(Object.hasOwn(body, 'constructor'), false);
  assert.equal(Object.hasOwn(body.plugin, '__proto__'), false);
  assert.equal(Object.hasOwn(Object.prototype, 'sfMatterPolluted'), false);
});

test('Matter merges own options without modifying inherited target values', () => {
  const shared = { count: 1 };
  const target = Object.create({ config: shared });
  const source = Object.assign(Object.create({ inherited: true }), { config: { value: 2 } });
  Common.extend(target, source);
  assert.deepEqual(target.config, { value: 2 });
  assert.deepEqual(shared, { count: 1 });
  assert.equal(Object.hasOwn(target, 'inherited'), false);
});

test('Matter rejects unsafe property paths and still sets ordinary nested values', () => {
  const target = { config: {} };
  for (const path of ['__proto__.sfMatterPolluted', 'constructor.prototype.sfMatterPolluted', 'config.prototype']) {
    assert.throws(() => Common.set(target, path, true), TypeError);
  }

  const shared = { value: 1 };
  assert.throws(() => Common.set(Object.create({ config: shared }), 'config.value', 2), TypeError);
  assert.equal(shared.value, 1);
  assert.equal(Object.hasOwn(Object.prototype, 'sfMatterPolluted'), false);

  Common.set(target, 'config.value', 3);
  assert.deepEqual(target, { config: { value: 3 } });
});
