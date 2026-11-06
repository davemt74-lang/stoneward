'use strict';
// Exercise the production composer helpers without generating or posting a project.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const start = source.indexOf('  let chatMusicPicker=null,chatMusicCommandTimer=null;');
const end = source.indexOf('  function chatDraftMusicInputs(', start);
assert(start >= 0 && end > start, 'Production resource helper block is present');
const block = source.slice(start, end);
let checks = 0;
const check = (value, label) => { assert(value, label); checks++; console.log('PASS ' + label); };
const equal = (actual, expected, label) => check(JSON.stringify(actual) === JSON.stringify(expected), label);
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return {promise, resolve}; };
const tick = () => new Promise(resolve => setImmediate(resolve));

function fixture() {
  const ctx = {
    AbortController, setTimeout, clearTimeout, console,
    owner: '1', chatImagePicker: null, notices: [], opens: [], loads: [], syncs: 0,
    auth: Promise.resolve(), outcome: {ids: []},
    input: {value: '', selectionStart: 0, selectionEnd: 0,
      setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }, addEventListener() {}},
    listeningRoom: {artist_inputs: {merch_ids: ['saved-hoodie'], ticket_ids: ['saved-offer']}},
    window: {}
  };
  ctx.loadAuth = () => ctx.auth;
  ctx.canvasOwner = () => ctx.owner;
  ctx.popupEntry = (kind, text) => ctx.notices.push({kind, text});
  ctx.directListeningRoomCommand = draft => /^\/edit\b/i.test(draft) ? {kind: 'revise'} : null;
  ctx.syncChatImageDraftLimit = () => ctx.syncs++;
  ctx.api = async (url, body, signal) => { ctx.loads.push({url, body, signal}); return {items: []}; };
  ctx.window.SFCanvasResourcePicker = {open: async options => {
    ctx.opens.push(options);
    await options.load(options.signal);
    return ctx.outcome;
  }};
  vm.runInNewContext(block + `\n globalThis.helpers={chatDraftResourceInputs,chatResourceCommandRange,applyChatResourceSelection,openChatResourcePicker,
    active:()=>chatResourcePicker,setOtherPicker:value=>{chatImagePicker=value;}};`, ctx);
  return ctx;
}

(async () => {
  let ctx = fixture(), helpers = ctx.helpers;
  equal(helpers.chatDraftResourceInputs('{canvas:12} {canvas:3} {canvas:12} {canvas:01} {canvas:9007199254740992} {merch:hoodie} {ticket:offer-a} {merch:hoodie}'),
    {source_scene_ids: [12, 3], artist_inputs: {merch_ids: ['hoodie'], ticket_ids: ['offer-a']}},
    'Canonical source IDs preserve order, deduplicate and refuse unsafe canvas numbers');
  equal(helpers.chatDraftResourceInputs('{merch-clear} {tickets-clear}'), {artist_inputs: {merch_ids: [], ticket_ids: []}},
    'Explicit empty bindings clear each commerce type independently');
  equal(helpers.chatDraftResourceInputs('A plain design discussion'), {}, 'No resource tags leave inheritance untouched');
  equal(helpers.chatResourceCommandRange('Build (/Tickets).'), {kind: 'tickets', start: 7, end: 15, standalone: false},
    'Inline source commands keep exact replacement ranges');
  check(!helpers.chatResourceCommandRange('/canvas-all') && !helpers.chatResourceCommandRange('example/merch') &&
    !helpers.chatResourceCommandRange('/tickets', 3), 'Partial words and a moved caret do not open a source picker');

  ctx.input.value = '/edit {canvas:9} {merch:old} {ticket:offer-a} {music-track:song-2} {reference-image-' + 'ab'.repeat(16) + '} /merch';
  helpers.applyChatResourceSelection(helpers.chatResourceCommandRange(ctx.input.value), ['hoodie-b', 'record-a']);
  check(!ctx.input.value.includes('{merch:old}') && ctx.input.value.includes('{merch:hoodie-b} {merch:record-a}') &&
    ctx.input.value.includes('{canvas:9}') && ctx.input.value.includes('{ticket:offer-a}') && ctx.input.value.includes('{music-track:song-2}') &&
    ctx.input.value.includes('{reference-image-') && ctx.syncs === 1,
    'Replacing merch bindings preserves independent canvas, ticket, music and image selections');
  ctx.input.value += ' /tickets';
  helpers.applyChatResourceSelection(helpers.chatResourceCommandRange(ctx.input.value), []);
  check(!ctx.input.value.includes('{ticket:offer-a}') && ctx.input.value.includes('{tickets-clear}') && ctx.input.value.includes('{merch:hoodie-b}'),
    'Clearing tickets keeps merch and writes an explicit empty inheritance marker');
  ctx.input.value += ' /canvas';
  helpers.applyChatResourceSelection(helpers.chatResourceCommandRange(ctx.input.value), []);
  check(!ctx.input.value.includes('{canvas:9}') && !ctx.input.value.includes('{canvas-clear}') && ctx.input.value.includes('{tickets-clear}'),
    'Clearing canvas context removes only canvas tags without inventing a clear-source widget');

  ctx = fixture();helpers = ctx.helpers;
  ctx.input.value = '/edit /merch';ctx.outcome = {ids: ['new-hoodie']};
  await helpers.openChatResourcePicker(helpers.chatResourceCommandRange(ctx.input.value));
  equal(ctx.opens[0].selectedIds, ['saved-hoodie'], 'An edit without explicit merch bindings preselects only saved merch');
  check(ctx.input.value === '/edit {merch:new-hoodie}' && ctx.loads[0].url === 'canvas-resources.php?kind=merch' &&
    ctx.loads[0].body === undefined && ctx.loads[0].signal.aborted && !helpers.active(),
    'Picker apply updates the draft through one inventory GET and cleans up without submitting a project');
  ctx.input.value = '/edit {merch-clear} /merch';
  await helpers.openChatResourcePicker(helpers.chatResourceCommandRange(ctx.input.value));
  equal(ctx.opens[1].selectedIds, [], 'An explicit cleared merch selection does not reselect saved inherited merch');

  ctx = fixture();helpers = ctx.helpers;
  const auth = deferred();ctx.auth = auth.promise;ctx.input.value = '/merch';
  const opening = helpers.openChatResourcePicker(helpers.chatResourceCommandRange(ctx.input.value));
  ctx.input.value = 'A changed prompt';auth.resolve();await opening;
  check(ctx.opens.length === 0 && ctx.input.value === 'A changed prompt', 'A draft change during authentication invalidates the original command range');

  ctx = fixture();helpers = ctx.helpers;
  let result = deferred();ctx.outcome = result.promise;ctx.input.value = '/tickets';
  const changedDraft = helpers.openChatResourcePicker(helpers.chatResourceCommandRange(ctx.input.value));
  await tick();ctx.input.value = 'Preserve my newer text';result.resolve({ids: ['offer-1']});await changedDraft;
  check(ctx.input.value === 'Preserve my newer text' && ctx.opens[0].signal.aborted && !helpers.active(),
    'A late picker selection cannot replace a newer composer draft');

  ctx = fixture();helpers = ctx.helpers;
  result = deferred();ctx.outcome = result.promise;ctx.input.value = '/canvas';
  const changedOwner = helpers.openChatResourcePicker(helpers.chatResourceCommandRange(ctx.input.value));
  await tick();ctx.owner = '2';result.resolve({ids: ['88']});await changedOwner;
  check(ctx.input.value === '/canvas' && ctx.opens[0].signal.aborted && ctx.syncs === 0,
    'A late selection from the previous owner never attaches their canvas IDs');

  ctx = fixture();helpers = ctx.helpers;ctx.input.value = '/merch';ctx.outcome = null;
  await helpers.openChatResourcePicker(helpers.chatResourceCommandRange(ctx.input.value));
  check(ctx.input.value === '/merch' && ctx.syncs === 0 && !helpers.active(), 'Cancel leaves the unsent command and other bindings unchanged');
  ctx.owner = '';ctx.input.value = '/canvas';
  await helpers.openChatResourcePicker(helpers.chatResourceCommandRange(ctx.input.value));
  check(ctx.opens.length === 1 && ctx.notices.length === 1, 'Signed-out source selection prompts authentication without opening private inventory');
  console.log(`PASS ${checks} resource composer checks`);
})().catch(error => { console.error(error); process.exitCode = 1; });
