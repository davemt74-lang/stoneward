'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/canvas-build-workflow.js'), 'utf8');
const requestId = 'a'.repeat(32);
const clone = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const deferred = () => { let resolve, reject; const promise = new Promise((done, fail) => { resolve = done; reject = fail; }); return {promise, resolve, reject}; };
const tick = () => new Promise(resolve => setImmediate(resolve));
let checks = 0;
function check(value, label) { assert(value, label); checks++; console.log('PASS ' + label); }
async function until(predicate, label) { for (let i = 0; i < 60 && !predicate(); i++) await tick(); assert(predicate(), label); }
const sceneOne = {id: 10, html: '<main>Original candidate</main>', css: 'main{color:gold}', js: ''};
const sceneTwo = {id: 11, html: '<main>Repaired candidate</main>', css: 'main{color:linen}', js: ''};
const dto = (state, fields = {}) => ({workflow: {request_id: requestId, state, candidate_scene_id: 10, review_id: null, steps: [], total_active_tokens_used: 0, ...fields}});

function harness(handlers = {}) {
  const timers = new Map(); let nextTimer = 0;
  const ctx = {window: {}, crypto: webcrypto, AbortController, DOMException,
    setTimeout(fn) { const id = ++nextTimer; timers.set(id, fn); return id; },
    clearTimeout(id) { timers.delete(id); }};
  vm.runInNewContext(source, ctx, {filename: 'canvas-build-workflow.js'});
  const state = {owner: 'one', active: true, outer: new AbortController(), posts: [], gets: [], probes: [],
    sources: [], updates: [], deltas: [], retries: [], sceneReads: [], applied: []};
  const stage = {setSceneCandidateCode: scene => state.sources.push(clone(scene)), showScene: scene => state.applied.push(clone(scene))};
  const options = {
    requestId, project: {brief: 'An approved source experiment'}, signal: state.outer.signal,
    getOwner: () => state.owner, active: () => state.active,
    post: async (payload, meta) => { state.posts.push({payload: clone(payload), meta}); return handlers.post(payload, meta, state); },
    get: async signal => { state.gets.push(signal); return handlers.get ? handlers.get(signal, state) : dto('testing'); },
    test: async (scene, meta) => { state.probes.push({scene: clone(scene), meta}); return handlers.test(scene, meta, state); },
    getScene: async (id, signal) => { state.sceneReads.push({id, signal}); return handlers.getScene ? handlers.getScene(id, signal, state) : clone(sceneTwo); },
    onUpdate: update => state.updates.push(clone(update)), onCandidate: stage.setSceneCandidateCode,
    onCode: text => state.deltas.push(text), onRetry: reason => state.retries.push(reason), stage
  };
  const coordinator = ctx.window.SFCanvasBuildWorkflow.create(options);
  return {state, coordinator, timers, async advance() { const pending = [...timers.entries()]; for (const [id, callback] of pending) { if (timers.delete(id)) callback(); } await tick(); }};
}

(async () => {
  let reviews = 0;
  let h = harness({
    async post(payload, meta) {
      if (payload.action === 'start') return dto('testing', {steps: [{id: 'coding', role: 'coding', state: 'complete'}], total_active_tokens_used: 20});
      if (payload.action === 'review') {
        reviews++;
        return reviews === 1 ? dto('needs_repair', {review_id: 91, total_active_tokens_used: 30}) :
          dto('ready', {candidate_scene_id: 11, review_id: 92, total_active_tokens_used: 58,
            project:{brief:'Server-approved project snapshot'},message:'Checked candidate ready',steps:[
              {id:'review-first',role:'testing',candidate_id:10,evidence:{checks:[{label:'Observed binding',status:'fail'}],manual:[{code:'touch',message:'Touch behavior needs a manual check'}]},
                report:{verdict:'repair',summary:'The selected binding needs repair',findings:[{message:'Missing selected source'}]}},
              {id:'repair-one',role:'repair',candidate_id:10,state:'complete',report:{fixes:['Added the authorized source binding']}},
              {id:'review-final',role:'testing',candidate_id:11,report:{verdict:'pass',summary:'The repaired binding passed review'}}]});
      }
      if (payload.action === 'repair') {
        meta.onDelta('actual repaired source');
        return dto('testing', {candidate_scene_id: 11, review_id: 91, total_active_tokens_used: 45});
      }
      throw Error('Unexpected action ' + payload.action);
    },
    async test(scene, meta) {
      meta.onEvent({type: 'check', id: 'source', label: 'Saved source', status: scene.id === 10 ? 'fail' : 'pass', detail: 'Observed source ' + scene.id});
      return {state: 'complete', checks: [{id: 'source', status: scene.id === 10 ? 'fail' : 'pass'}]};
    }
  });
  let result = await h.coordinator.run({scene: clone(sceneOne), design_source: 'model'});
  check(result.state === 'ready' && result.scene.id === 11 && result.active_tokens_used === 58 && result.design_source === 'model',
    'A failed candidate is reviewed, repaired, retested and returned only after the repaired candidate passes review');
  check(h.state.posts.map(row => row.payload.action).join(',') === 'start,review,repair,review' && h.state.probes.map(row => row.scene.id).join(',') === '10,11',
    'The coordinator uses actual probe callbacks for both saved candidates and performs one repair');
  check(h.state.posts[1].payload.evidence.checks[0].status === 'fail' && h.state.posts[3].payload.evidence.checks[0].status === 'pass' &&
    h.state.updates.some(update => update.steps.some(step => step.checks?.some(evidence => evidence.detail === 'Observed source 10'))),
    'Actual probe events reach the UI and their full evidence reaches the matching reviewer step');
  const stepIds = h.state.posts.filter(row => row.payload.step_id).map(row => row.payload.step_id);
  check(stepIds.length === 3 && new Set(stepIds).size === 3 && stepIds.every(id => /^[a-f0-9]{32}$/.test(id)) && h.state.posts.every(row => row.payload.request_id === requestId),
    'Review and repair steps have distinct request identities while retaining the same approved project identity');
  check(h.state.deltas.join('') === 'actual repaired source' && h.state.sources.map(scene => scene.id).join(',') === '10,11,11' && h.state.applied.length === 0,
    'Repair source streams to code display and the coordinator never applies a candidate through showScene');
  const finalUpdate = h.state.updates.at(-1);
  check(finalUpdate.project.brief === 'Server-approved project snapshot' && finalUpdate.summary === 'Checked candidate ready' &&
    finalUpdate.steps[0].summary === 'The selected binding needs repair' && finalUpdate.steps[0].checks[1].status === 'manual' &&
    finalUpdate.steps[0].checks[1].label === 'Touch behavior needs a manual check' && finalUpdate.steps[1].findings[0].message === 'Missing selected source' &&
    finalUpdate.steps[1].fixes[0] === 'Added the authorized source binding',
    'Nested reviewer reports, manual evidence and prior repair findings flatten into actual sidebar metadata');
  check(h.timers.size === 0, 'Completed work leaves no polling timer behind');

  h = harness({post: async payload => payload.action === 'start' ? dto('testing') : dto('needs_attention', {review_id: 13}),
    test: async () => ({state: 'complete', checks: [{id: 'manual', status: 'needs_manual_check'}]})});
  result = await h.coordinator.run({scene: clone(sceneOne)});
  check(result.state === 'needs_attention' && !result.scene && h.state.posts.map(row => row.payload.action).join(',') === 'start,review' && !h.state.applied.length,
    'A manual-attention result preserves the existing canvas and does not repair or apply automatically');

  for (const stoppingAt of ['probe', 'review', 'repair']) {
    const held = deferred(), cancelResponse = deferred();
    let probeMeta, repairMeta;
    h = harness({
      async post(payload, meta) {
        if (payload.action === 'cancel') return cancelResponse.promise;
        if (payload.action === 'start') return dto('testing');
        if (payload.action === 'review') return stoppingAt === 'review' ? held.promise : dto('needs_repair', {review_id: 21});
        if (payload.action === 'repair') { repairMeta = meta; return held.promise; }
        throw Error('Unexpected action');
      },
      async test(scene, meta) { probeMeta = meta; return stoppingAt === 'probe' ? held.promise : {state: 'complete', checks: []}; }
    });
    const running = h.coordinator.run({scene: clone(sceneOne)});
    await until(() => stoppingAt === 'probe' ? !!probeMeta : h.state.posts.some(row => row.payload.action === stoppingAt), 'Reach ' + stoppingAt);
    const firstCancel = h.coordinator.cancel(), repeatedCancel = h.coordinator.cancel();
    check(h.state.posts.filter(row => row.payload.action === 'cancel').length === 1 && probeMeta.signal.aborted &&
      h.state.posts.find(row => row.payload.action === 'cancel').meta.signal === undefined,
      'Stop during ' + stoppingAt + ' aborts work but sends one cancellation with an independent request');
    const updatesBefore = h.state.updates.length;
    probeMeta.onEvent({type: 'check', id: 'too-late', label: 'Old event', status: 'pass'});
    repairMeta?.onDelta('ignored late source');repairMeta?.onRetry('ignored late retry');
    check(h.state.updates.length === updatesBefore && h.state.deltas.length === 0 && h.state.retries.length === 0,
      'Stop during ' + stoppingAt + ' blocks late probe, source and retry UI events');
    cancelResponse.resolve(dto('canceled'));await Promise.all([firstCancel, repeatedCancel]);
    held.resolve(stoppingAt === 'probe' ? {state: 'complete', checks: []} : dto(stoppingAt === 'review' ? 'needs_repair' : 'testing', {candidate_scene_id: 11, review_id: 21}));
    result = await running;
    const actions = h.state.posts.map(row => row.payload.action);
    check(result.state === 'canceled' && actions.at(-1) === 'cancel' && h.state.updates.at(-1).state === 'canceled' &&
      h.state.probes.length === 1 && h.state.applied.length === 0 && h.timers.size === 0,
      'Stop during ' + stoppingAt + ' cannot launch another review, repair, probe or applied canvas');
  }

  let recoveryReads = 0;
  h = harness({post: async payload => {
    if (payload.action === 'start') return dto('testing');
    const error = new Error('Unreadable upstream response');error.status = 502;throw error;
  }, get: async () => ++recoveryReads === 1 ? dto('reviewing', {review_id: 31}) : dto('ready', {review_id: 31}),
  test: async () => ({state: 'complete', checks: []})});
  const recovering = h.coordinator.run({scene: clone(sceneOne)});
  await until(() => recoveryReads === 1 && h.timers.size > 0, 'Wait for status recovery');
  await h.advance();result = await recovering;
  check(result.state === 'ready' && recoveryReads === 2 && h.state.posts.map(row => row.payload.action).join(',') === 'start,review',
    'An ambiguous provider response recovers through status-only polling without repeating paid work');

  h = harness({post: async payload => dto(payload.action === 'start' ? 'testing' : payload.action === 'cancel' ? 'canceled' : 'reviewing'),
    test: async () => ({state: 'complete', checks: []})});
  const pollingReview = h.coordinator.run({scene: clone(sceneOne)});
  await until(() => h.coordinator.getSnapshot()?.state === 'reviewing' && h.timers.size > 0, 'Reach review status polling');
  await h.coordinator.cancel();result = await pollingReview;
  check(result.state === 'canceled' && h.state.posts.map(row => row.payload.action).join(',') === 'start,review,cancel' &&
    h.state.gets.length === 0 && h.timers.size === 0 && h.state.updates.at(-1).state === 'canceled',
    'Stop also interrupts a pending review polling wait without another status request or paid step');

  const oldProbe = deferred();let oldMeta;
  h = harness({post: async () => dto('testing'), test: async (scene, meta) => { oldMeta = meta;return oldProbe.promise; }});
  const staleRun = h.coordinator.run({scene: clone(sceneOne)}).catch(error => error);
  await until(() => !!oldMeta, 'Reach old-owner probe');
  const oldUpdates = h.state.updates.length;h.state.owner = 'two';
  oldMeta.onEvent({type: 'check', id: 'private-old', label: 'Private result', status: 'pass'});oldProbe.resolve({state: 'complete'});
  result = await staleRun;
  check(result.name === 'AbortError' && h.state.updates.length === oldUpdates && h.state.posts.length === 1 && !h.state.applied.length,
    'A stale owner cannot publish old probe events, review the candidate or apply old UI');

  const outerProbe = deferred();let outerMeta;
  h = harness({post: async payload => dto(payload.action === 'cancel' ? 'canceled' : 'testing'),
    test: async (scene, meta) => { outerMeta = meta;return outerProbe.promise; }});
  const outerRun = h.coordinator.run({scene: clone(sceneOne)});
  await until(() => !!outerMeta, 'Reach outer-abort probe');
  h.state.outer.abort();await tick();const canceledUpdates = h.state.updates.length;
  outerMeta.onEvent({type: 'check', id: 'late-outer', status: 'pass'});outerProbe.resolve({state: 'complete'});result = await outerRun;
  check(result.state === 'canceled' && outerMeta.signal.aborted && h.state.posts.map(row => row.payload.action).join(',') === 'start,cancel' &&
    h.state.posts.at(-1).meta.signal === undefined && h.state.updates.length === canceledUpdates && !h.state.applied.length,
    'An outer abort cancels independently and prevents subsequent old work or UI');
  console.log(`PASS ${checks} build workflow coordinator checks`);
})().catch(error => { console.error(error);process.exitCode = 1; });
