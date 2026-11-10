'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets/js/jukebox-clock.js'), 'utf8');

function setup() {
  let now = 100;
  const window = {};
  vm.runInNewContext(source, {
    window, performance: {now: () => now}, Number, Math,
    Date: {now() { throw new Error('Sync estimates must not depend on the phone wall clock'); }}
  });
  return {
    clock: window.StonefellowJukeboxClock.create(),
    at(value) { now = value; }
  };
}

test('a fast clock sample measures a later slow return without a 500ms cap', () => {
  const h = setup();
  h.clock.observe(1_000_000, 100, 120);
  assert.equal(h.clock.serverAt(120), 1_000_010);
  // The later request spends only 20ms outbound, then waits 3480ms returning.
  // Treating its full RTT as symmetric would shift an already good clock.
  h.clock.observe(1_000_910, 1000, 4500);
  assert.equal(h.clock.serverAt(4500), 1_004_390);
  assert.equal(h.clock.transitMs(1_000_910, 1000, 4500), 3480);
});

test('clock estimates expire and a surviving recent sample takes over', () => {
  const h = setup();
  h.clock.observe(1_000_000, 100, 120);
  h.clock.observe(1_005_000, 5100, 5300);
  h.at(61_000);
  assert.equal(h.clock.serverAt(), 1_060_800);
  h.at(66_000);
  assert.equal(h.clock.serverAt(), null, 'an expired clock must not stamp new host samples');
});

test('invalid and backwards observations cannot poison a usable clock', () => {
  const h = setup();
  h.clock.observe(1_000_000, 100, 120);
  for (const sample of [
    [NaN, 200, 220], [Infinity, 200, 220], [0, 200, 220],
    [1_000_100, NaN, 220], [1_000_100, 200, Infinity], [1_000_100, 300, 250]
  ]) h.clock.observe(...sample);
  assert.equal(h.clock.serverAt(1000), 1_000_890);
  assert.equal(h.clock.serverAt(NaN), null);
});

test('transit fallback and clock-derived delays remain within the observed trip', () => {
  const h = setup();
  assert.equal(h.clock.serverAt(), null);
  assert.equal(h.clock.transitMs(1_000_000, 100, 4100), 2000);
  h.clock.observe(1_000_000, 100, 120);
  assert.equal(h.clock.transitMs(2_000_000, 1000, 2000), 0,
    'a server timestamp slightly ahead of the estimate cannot produce negative age');
  assert.equal(h.clock.transitMs(1, 1000, 2000), 1000,
    'a delayed response cannot invent more transit than its entire request');
});
