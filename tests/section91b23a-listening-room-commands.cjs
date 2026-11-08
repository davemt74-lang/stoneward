'use strict';
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(new URL('../assets/js/app.js', `file://${__filename}`), 'utf8');
const workflowSource = readFileSync(new URL('../assets/js/canvas-build-workflow.js', `file://${__filename}`), 'utf8');
const commands = source.slice(source.indexOf('  function canvasDesignBrief('), source.indexOf('  async function liveAgent('));
const audioContext = source.slice(source.indexOf('  function canvasAudioContext('), source.indexOf('  function scopeCanvasMusicCatalog('));
const transport = source.slice(source.indexOf('  async function readSceneResponseStream('), source.indexOf('  function applyQueue('));
assert.ok(commands.includes('handleListeningRoomCommand'));

function harness(authenticated = true, storage = new Map()) {
  const posts = [], preflights = [], reads = [], messages = [], entries = [], displayed = [], histories = [], coding = [], spoken = [], deltas = [], focus = [];
  const workflowPosts = [], workflowSignals = [], workflowUpdates = [], workflowCandidates = [], workflowRuns = [];
  let voiceSignal;
  let current = null;
  let sceneId = 0;
  let requestId = 0;
  let proposalId = 100;
  const approvedProjects = [], ideaRequests = [], completedProjects = [];
  const stage = {
    active: () => true,
    sceneActive: () => Boolean(current),
    showScene: (scene, options) => { current = scene; displayed.push({ scene, standard: options.standard }); focus.push(options.focus); return true; },
    closeScene: () => { current = null; },
    showSceneHistory: rows => { histories.push(rows); return true; },
    setSceneCoding: (state, message) => { coding.push({ state, message }); },
    appendSceneCode: (kind, text) => { deltas.push({ kind, text }); },
    setSceneWorkflow: update => { workflowUpdates.push(update); },
    setSceneCandidateCode: candidate => { workflowCandidates.push(candidate); },
    prepareSceneTest: () => ({ frame: {}, script: {}, imageAssets: new Map() }),
  };
  const scene = (id, trackId = 'desert') => ({ id, track_id: trackId, title: 'Desert Lights', html: '<h2>Desert Lights</h2>', css: 'h2{color:tan}' });
  const scope = {
    AbortController, DOMException, URL, URLSearchParams, cfg: {}, document: { baseURI: 'https://stonefellow.test/demo/', getElementById: () => null },
    localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
    window: {
      SFPublicAgentCanvas: stage,
      crypto: { getRandomValues: bytes => bytes.fill(++requestId) },
    },
    setTimeout: (callback, delay) => setTimeout(callback, delay >= 10000 ? 1000 : 1),
    clearTimeout,
    st: { auth: { authenticated, user: authenticated ? { id: 7, role: 'fan' } : null }, track: { id: 'desert' } },
    observedPlayer: () => ({ track: scope.st.track, playing: scope.st.playing === true }),
    canvasAudioState: () => ({}),
    scopeCanvasMusicCatalog: value => value,
    sceneImagePath: id => 'api/agent-canvas-image.php?id=' + encodeURIComponent(id),
    catalog: [{ id: 'desert', title: 'Desert Lights' }, { id: 'static', title: 'Static Sky' }],
    norm: value => String(value).toLowerCase().replace(/[^\w ]/g, '').trim(),
    loadAuth: async () => {}, agent: () => {}, closeChatPopup: () => {},
    speechPilotActive: false,
    agentSpeak: async (message, deviceOnly, signal) => { spoken.push({ message, deviceOnly }); voiceSignal = signal; },
    popupEntry: (kind, message, options) => { messages.push(message); entries.push({ kind, message, options }); },
    imagePreflight: async () => ({ preflight: { requested_count: null } }),
    secureJsonPost: async (path, payload) => {
      posts.push({ path, ...JSON.parse(JSON.stringify(payload)) });
      const procedural = payload.canvas_kind === 'space_eq';
      return {
        scene: { ...scene(++sceneId, payload.track_id), ...(payload.canvas_kind ? { canvas_kind: payload.canvas_kind } : {}),
          ...(payload.project_managed===true&&payload.canvas_kind==='custom'?{workflow_required:true}:{}) },
        active_tokens_used: procedural ? 0 : 243,
        design_source: procedural ? 'standard' : 'model',
      };
    },
    api: async path => {
      reads.push(path);
      return path.includes('?id=') ? { scene: scene(31) } : { scenes: [scene(31)] };
    },
  };
  // These legacy tests own command/transport behavior after approval. The real
  // proposal controller and its approval UI are covered by the focused native
  // project tests; this explicit adapter supplies already-approved snapshots.
  scope.window.SFCanvasProjects = {
    create: () => ({
      setOwner() {}, attach() {}, handleCommand: () => false,
      prepare: (project, { signal, reuseId } = {}) => {
        assert.equal(signal?.aborted, false);
        const proposal = { id: reuseId || ++proposalId, status: 'approved', approval_mode: 'manual',
          project: JSON.parse(JSON.stringify(project)) };
        approvedProjects.push(proposal);
        return proposal;
      },
      suggest: async details => {
        assert.equal(details.signal.aborted, false);
        ideaRequests.push(details);
        const proposal = scope.ideaProposal || { id: 800, status: 'awaiting_approval',
          title: 'Fresh history concept', concept: 'A fresh concept combining elements from your saved canvases.' };
        scope.popupEntry('assistant', proposal.concept);
        return proposal;
      },
      complete: (id, state = 'built') => { completedProjects.push({ id, state }); },
    }),
  };
  // The command fixture starts after explicit project approval. The real
  // coordinator has its own cancellation, review and repair tests; this adapter
  // returns only the checked initial candidate so transport assertions stay local.
  scope.window.SFCanvasBuildWorkflow = { create: options => ({
    run: async initial => { workflowRuns.push({ options, initial }); return {...initial,verification:{verified:true}}; },
    cancel: () => options.post({action:'cancel',request_id:options.requestId}),
  }) };
  scope.window.SFCanvasSceneTester = { test: async () => ({ state:'complete',checks:[],manual:[] }) };
  scope.workflowPost = async (_path,payload) => ({workflow:{request_id:payload.request_id,state:'canceled',steps:[]}});
  // Build transport overrides share a separate preflight fixture so they still receive the build payload.
  let postHandler = scope.secureJsonPost;
  const dispatchPost = (path, payload, ...args) => {
    if(path==='canvas-build-workflow.php'){
      workflowPosts.push({path,...JSON.parse(JSON.stringify(payload))});workflowSignals.push(args[1]);
      return scope.workflowPost(path,payload,...args);
    }
    if (payload.action === 'image_preflight') {
      preflights.push({ path, ...JSON.parse(JSON.stringify(payload)) });
      return scope.imagePreflight(path, payload, ...args);
    }
    return postHandler(path, payload, ...args);
  };
  Object.defineProperty(scope, 'secureJsonPost', { get: () => dispatchPost, set: handler => { postHandler = handler; } });
  vm.runInNewContext(`let listeningRoom=null,listeningRoomRequest=null,listeningRoomOwner=null,canvasSelection=null;${commands}${audioContext}`, scope);
  return { scope, posts, preflights, reads, messages, entries, displayed, histories, coding, spoken, deltas, scene, focus,
    approvedProjects, ideaRequests, completedProjects, workflowPosts, workflowSignals, workflowUpdates, workflowCandidates, workflowRuns, voiceSignal: () => voiceSignal };
}

test('a regular signed-in fan creates a scene, revises by saved ID, and sees a token receipt', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Stonefellow, build a listening room for this song.');
  assert.equal(h.posts[0].track_id, 'desert');
  assert.equal(h.posts[0].request_id, '01'.repeat(16));
  assert.deepEqual(h.spoken, []);
  assert.deepEqual(h.coding, []);
  assert.equal(h.approvedProjects[0].status, 'approved');
  assert.equal(h.posts[0].project_managed, true);
  assert.equal(h.posts[0].proposal_id, h.approvedProjects[0].id);
  assert.equal(h.displayed[0].standard, false);
  assert.match(h.messages.at(-1), /243 Active Tokens/);
  await run('Revise the listening room: make it more minimal');
  assert.deepEqual(h.posts[1], { path: 'agent-scene.php', track_id: 'desert', previous_id: 1, brief: 'make it more minimal',
    project_managed: true, proposal_id: 102, request_id: '02'.repeat(16) });
  await run('Build a listening room for Static Sky');
  assert.equal(h.posts[2].track_id, 'static');
  assert.equal(h.scope.directListeningRoomCommand('Change the listening roomy'), null);
});

test('the actual app coordinator tests, repairs and retests a custom candidate before replacing the old canvas', async () => {
  const h=harness();
  await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
  h.scope.crypto=h.scope.window.crypto;
  vm.runInNewContext(workflowSource,h.scope);
  const probes=[],previews=[];
  h.scope.window.SFPublicAgentCanvas.prepareSceneTest=(candidate,options)=>{previews.push({candidate,options});return {frame:{candidate:candidate.id}};};
  h.scope.window.SFCanvasSceneTester.test=async(candidate,options)=>{
    probes.push(candidate.id);
    assert.equal(h.displayed.at(-1).scene.id,31);
    assert.equal(options.canUse(),true);
    assert.equal(options.prepareScene(candidate,'actual-probe','preview-program').frame.candidate,candidate.id);
    options.onEvent({type:'check',id:'binding',label:'Selected binding',status:candidate.id===1?'fail':'pass',detail:'Observed '+candidate.id});
    return {state:'complete',checks:[{id:'binding',label:'Selected binding',status:candidate.id===1?'fail':'pass'}]};
  };
  let reviews=0;
  const workflow=(payload,state,fields={})=>({workflow:{request_id:payload.request_id,state,candidate_scene_id:1,steps:[],total_active_tokens_used:243,...fields}});
  h.scope.workflowPost=async(_path,payload,_retry,signal,onDelta)=>{
    assert.equal(signal?.aborted,false);
    if(payload.action==='start')return workflow(payload,'testing');
    if(payload.action==='review'){
      reviews++;
      assert.equal(payload.evidence.checks[0].status,reviews===1?'fail':'pass');
      return workflow(payload,reviews===1?'needs_repair':'ready',{review_id:reviews,candidate_scene_id:reviews===1?1:2,total_active_tokens_used:reviews===1?260:280});
    }
    if(payload.action==='repair'){
      assert.equal(h.displayed.at(-1).scene.id,31);
      onDelta('actual repair delta');
      return workflow(payload,'testing',{review_id:1,candidate_scene_id:2,total_active_tokens_used:275});
    }
    if(payload.action==='apply')return workflow(payload,'applied',{candidate_scene_id:2,total_active_tokens_used:280});
    throw Error('Unexpected workflow action');
  };
  h.scope.api=async path=>{
    h.reads.push(path);assert.equal(path,'agent-scene.php?id=2&current_track_id=desert');
    return {scene:{...h.scene(2),canvas_kind:'custom',html:'<h2>Repaired selected source</h2>'}};
  };
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build a custom paper scene for Desert Lights'));
  assert.deepEqual(probes,[1,2]);
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31,2]);
  assert.deepEqual(h.workflowPosts.map(row=>row.action),['start','review','repair','review','apply']);
  assert.deepEqual(h.workflowCandidates.map(candidate=>candidate.id),[1,2,2]);
  assert.equal(previews[0].options.probe,'actual-probe');
  assert.equal(previews[0].options.previewProgram,'preview-program');
  assert.equal(previews[0].options.currentTrackId,'desert');
  assert.equal(h.posts.length,1);
  assert.ok(h.deltas.some(delta=>delta.text==='actual repair delta'));
  assert.equal(h.workflowUpdates.at(-1).phase,'applied');
  assert.match(h.messages.at(-1),/280 Active Tokens/);
});

test('actual app workflow manual attention preserves the old canvas and exposes evidence without applying', async () => {
  const h=harness();
  await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
  h.scope.crypto=h.scope.window.crypto;vm.runInNewContext(workflowSource,h.scope);
  h.scope.workflowPost=async(_path,payload)=>({workflow:{request_id:payload.request_id,state:payload.action==='start'?'testing':'needs_attention',candidate_scene_id:1,steps:[]}});
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build a custom light installation'));
  assert.deepEqual(h.workflowPosts.map(row=>row.action),['start','review']);
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
  assert.equal(h.completedProjects.at(-1).state,'needs_attention');
  assert.match(h.messages.at(-1),/current canvas is still open/);
});

async function checkedGateHarness(){
  const h=harness();
  await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
  h.scope.window.SFCanvasBuildWorkflow.create=options=>({
    run:async initial=>({...initial,state:'ready',workflow:{request_id:options.requestId,state:'ready',candidate_scene_id:initial.scene.id,steps:[]}}),
    cancel:()=>options.post({action:'cancel',request_id:options.requestId}),
  });
  h.scope.workflowPost=async(_path,payload)=>({workflow:{request_id:payload.request_id,state:payload.action==='apply'?'applied':'canceled',candidate_scene_id:payload.scene_id||1,steps:[]}});
  return h;
}

test('the apply acknowledgement is awaited before the checked candidate can replace the current canvas', async()=>{
  const h=await checkedGateHarness();let resolveApply,startApply;
  const started=new Promise(resolve=>{startApply=resolve;}),acknowledgement=new Promise(resolve=>{resolveApply=resolve;});
  h.scope.workflowPost=(_path,payload,_retry,signal)=>{
    assert.equal(payload.action,'apply');assert.equal(signal.aborted,false);startApply(payload);return acknowledgement;
  };
  const building=h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build an approved paper board'));
  const payload=await started;
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
  assert.deepEqual(h.workflowPosts[0],{path:'canvas-build-workflow.php',action:'apply',request_id:h.posts[0].request_id,scene_id:1});
  resolveApply({workflow:{request_id:payload.request_id,state:'applied',candidate_scene_id:1,steps:[]}});await building;
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31,1]);
});

test('a required canvas library must resolve before the app applies a checked candidate',async()=>{
  const h=await checkedGateHarness();
  let startPreparation,rejectPreparation;
  const started=new Promise(resolve=>{startPreparation=resolve;}),preparation=new Promise((_,reject)=>{rejectPreparation=reject;});
  h.scope.window.SFPublicAgentCanvas.prepareSceneLibraries=(scene,{signal})=>{
    assert.equal(scene.id,1);assert.equal(signal.aborted,false);startPreparation();return preparation;
  };
  const building=h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build an approved paper board'));
  await started;
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
  assert.deepEqual(h.workflowPosts,[]);
  rejectPreparation(Object.assign(new Error('An approved library required by this canvas is unavailable.'),{code:'canvas_library_unavailable'}));
  await building;
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
  assert.deepEqual(h.workflowPosts,[]);
  assert.match(h.messages.at(-1),/approved library required by this canvas is unavailable/);
});

test('a conflicting or mismatched apply acknowledgement keeps the old canvas', async()=>{
  for(const mode of ['conflict','wrong-candidate','not-applied']){
    const h=await checkedGateHarness();
    h.scope.workflowPost=async(_path,payload)=>{
      if(mode==='conflict')throw Object.assign(new Error('The project was canceled.'),{status:409});
      return {workflow:{request_id:payload.request_id,state:mode==='not-applied'?'ready':'applied',candidate_scene_id:mode==='wrong-candidate'?999:1,steps:[]}};
    };
    await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build an approved paper board'));
    assert.deepEqual(h.displayed.map(row=>row.scene.id),[31],mode);
    assert.equal(h.workflowPosts.length,1);
    assert.equal(h.completedProjects.at(-1).state,'failed');
  }
});

test('Stop before the apply acknowledgement preserves the old canvas even if a delayed acknowledgement says applied', async()=>{
  const h=await checkedGateHarness();let resolveApply,startApply;
  const started=new Promise(resolve=>{startApply=resolve;}),acknowledgement=new Promise(resolve=>{resolveApply=resolve;});
  h.scope.workflowPost=(_path,payload)=>{
    if(payload.action==='apply'){startApply(payload);return acknowledgement;}
    return {workflow:{request_id:payload.request_id,state:'canceled',candidate_scene_id:1,steps:[]}};
  };
  const building=h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build an approved paper board'));
  const payload=await started;await h.scope.stopListeningRoomBuild();
  resolveApply({workflow:{request_id:payload.request_id,state:'applied',candidate_scene_id:1,steps:[]}});await building;
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
  assert.deepEqual(h.workflowPosts.map(row=>row.action),['apply','cancel']);
});

test('a Stop requested immediately after acknowledgement still prevents local application', async()=>{
  const h=await checkedGateHarness();let resolveApply,startApply;
  const started=new Promise(resolve=>{startApply=resolve;}),acknowledgement=new Promise(resolve=>{resolveApply=resolve;});
  h.scope.workflowPost=(_path,payload)=>{
    if(payload.action==='apply'){startApply(payload);return acknowledgement;}
    return {workflow:{request_id:payload.request_id,state:'applied',candidate_scene_id:1,steps:[]}};
  };
  const building=h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build an approved paper board'));
  const payload=await started;
  resolveApply({workflow:{request_id:payload.request_id,state:'applied',candidate_scene_id:1,steps:[]}});
  const stopping=h.scope.stopListeningRoomBuild();
  await Promise.all([building,stopping]);
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
  assert.deepEqual(h.workflowPosts.map(row=>row.action),['apply','cancel']);
});

test('saved workflow candidates open source and role logs unless the exact candidate is ready or applied', async()=>{
  for(const state of ['testing','reviewing','needs_repair','repairing','needs_attention','canceled','failed','ready-wrong-candidate']){
    const h=harness();await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
    const workflow={request_id:'ab'.repeat(16),state:state==='ready-wrong-candidate'?'ready':state,candidate_scene_id:state==='ready-wrong-candidate'?43:42,
      steps:[{id:'review',role:'testing',state:'complete',summary:'Saved reviewer finding'}]};
    h.scope.api=async()=>({scene:{...h.scene(42),canvas_kind:'custom',workflow_required:true},workflow});
    await h.scope.handleListeningRoomCommand({kind:'restore',id:42});
    assert.deepEqual(h.displayed.map(row=>row.scene.id),[31],state);
    assert.equal(h.workflowCandidates.at(-1).id,42);
    assert.equal(h.workflowUpdates.at(-1).steps[0].summary,'Saved reviewer finding');
    assert.equal(h.workflowPosts.length,0);
    assert.match(h.messages.at(-1),/opened its code and agent activity/);
  }
});

test('a workflow-required candidate saved before job creation opens source only after recovery', async()=>{
  const h=harness();await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
  h.scope.api=async()=>({scene:{...h.scene(42),canvas_kind:'custom',workflow_required:true,design_brief:'Approved saved brief'}});
  await h.scope.handleListeningRoomCommand({kind:'restore',id:42});
  assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
  assert.equal(h.workflowCandidates.at(-1).id,42);
  assert.equal(h.workflowUpdates.at(-1).phase,'needs_attention');
  assert.equal(h.workflowUpdates.find(update=>update?.project)?.project.brief,'Approved saved brief');
  assert.equal(h.workflowPosts.length,0);
  assert.match(h.messages.at(-1),/not completed testing/);
});

test('verified current history candidates require apply authorization while legacy scenes still reopen unchanged', async()=>{
  for(const state of ['ready','applied']){
    const h=harness();await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
    const workflow={request_id:'ab'.repeat(16),state,candidate_scene_id:42,steps:[]};
    h.scope.api=async()=>({scene:{...h.scene(42),canvas_kind:'custom',workflow_required:true},workflow});
    h.scope.workflowPost=async()=>{
      assert.deepEqual(h.displayed.map(row=>row.scene.id),[31]);
      return {workflow:{...workflow,state:'applied'}};
    };
    await h.scope.handleListeningRoomCommand({kind:'restore',id:42});
    assert.deepEqual(h.displayed.map(row=>row.scene.id),[31,42]);
    assert.deepEqual(h.workflowPosts,[{path:'canvas-build-workflow.php',action:'apply',request_id:workflow.request_id,scene_id:42}]);
  }
  const legacy=harness();await legacy.scope.handleListeningRoomCommand({kind:'restore',id:31});
  assert.deepEqual(legacy.displayed.map(row=>row.scene.id),[31]);
  assert.equal(legacy.workflowPosts.length,0);
  assert.equal(legacy.workflowCandidates.length,0);
});

test('/edit revises the open canvas and keeps its previous ID and original song', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('/edit make it pulse');
  assert.equal(h.posts.length, 0);
  assert.match(h.messages.at(-1), /Open a canvas/);
  await run('Let’s build a light garden for Static Sky `{hide-agent}`');
  assert.equal(h.posts[0].track_id, 'static');
  assert.match(h.posts[0].brief, /\{hide-agent\}/);
  await run('/edit');
  assert.equal(h.posts.length, 1);
  assert.match(h.messages.at(-1), /followed by what you want/);
  await run('/edit Add silver rings.\n{design-agnet}');
  assert.equal(h.posts[1].previous_id, 1);
  assert.equal(h.posts[1].track_id, 'static');
  assert.equal(h.posts[1].canvas_kind, 'custom');
  assert.equal(h.posts[1].brief, 'Add silver rings.\n{design-agnet}');
  assert.equal(h.scope.directListeningRoomCommand('/editor settings'), null);
});

test('image generation and explicit image recall reach the same general canvas builder without music', async () => {
  const h = harness();
  h.scope.st.track = null;
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  const ref = '{image-' + '9'.repeat(32) + '}';
  await run('Generate an image of a botanical studio');
  await run('Create 3 images of a botanical studio');
  await run('Show me ' + ref);
  assert.deepEqual(h.posts.map(post => [post.canvas_kind, post.track_id, post.brief]), [
    ['custom', '', 'Generate an image of a botanical studio'], ['custom', '', 'Create 3 images of a botanical studio'], ['custom', '', 'Show me ' + ref],
  ]);
});

test('image preflight waits for a reduced count and applies the reviewed variation', async () => {
  const h = harness(), steps = [];
  const requestId = '01'.repeat(16), selectedId = '9'.repeat(32);
  const images = [{ id: requestId, title: 'First', index: 1 }, { id: selectedId, title: 'Second', index: 2 }];
  const preflight = { requested_count: 4, allowed_count: 4, remaining: 5 };
  h.scope.imagePreflight = async () => ({ preflight });
  h.scope.window.SFCanvasImageReview = {
    confirmGeneration: async (details, options) => {
      steps.push('confirm');
      assert.equal(details, preflight);
      assert.equal(options.signal.aborted, false);
      assert.deepEqual(h.posts, []);
      return { count: 2, review: true };
    },
    choose: async (assets, options) => {
      steps.push('choose');
      assert.equal(assets, images);
      assert.equal(options.title, 'Choose an image for your canvas');
      assert.equal(options.imageUrl(assets[1]), 'api/agent-canvas-image.php?id=' + selectedId);
      assert.equal(h.displayed.length, 0);
      return selectedId;
    },
  };
  h.scope.secureJsonPost = async (path, payload) => {
    h.posts.push({ path, ...JSON.parse(JSON.stringify(payload)) });
    if (payload.action === 'choose_image_variation') {
      steps.push('apply');
      return { state: 'ready', scene: { ...h.scene(44, ''), canvas_kind: 'custom', image_assets: [images[1]] } };
    }
    steps.push('build');
    return { state: 'review', generated_images: images, image_total: 2 };
  };
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Create 4 images of a botanical studio'));
  assert.deepEqual(steps, ['confirm', 'build', 'choose', 'apply']);
  assert.deepEqual(h.preflights, [{ path: 'agent-scene.php', action: 'image_preflight', brief: 'Create 4 images of a botanical studio', editing: false }]);
  assert.equal(h.posts[0].image_count, 2);
  assert.equal(h.posts[0].review_images, true);
  assert.deepEqual(h.posts[1], { path: 'agent-scene.php', action: 'choose_image_variation', request_id: requestId, image_id: selectedId });
  assert.equal(h.displayed[0].scene.id, 44);
  assert.equal(h.messages.at(-1), 'I applied your chosen image.');
});

test('canceling image confirmation starts no build and releases the next request', async () => {
  const h = harness();
  h.scope.imagePreflight = async () => ({ preflight: { requested_count: 3, allowed_count: 3 } });
  h.scope.window.SFCanvasImageReview = { confirmGeneration: async () => null };
  const run = () => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Create 3 images of a garden'));
  await run();
  assert.equal(h.preflights.length, 1);
  assert.deepEqual(h.posts, []);
  assert.deepEqual(h.coding, []);
  assert.deepEqual(h.displayed, []);
  h.scope.window.SFCanvasImageReview.confirmGeneration = async () => ({ count: 1, review: false });
  await run();
  assert.equal(h.preflights.length, 2);
  assert.equal(h.posts.length, 1);
  assert.equal(h.posts[0].request_id, '01'.repeat(16));
  assert.equal(h.posts[0].image_count, 1);
  assert.equal(h.posts[0].review_images, false);
});

test('selected visual references are distinct from placed images and deduplicated in the build payload', async () => {
  const h = harness();
  const placed = 'a'.repeat(32), reference = 'ref-' + 'b'.repeat(32);
  const brief = `Create a canvas with {image-${placed}} inspired by {reference-image-${reference}} and {reference-image-${reference.toUpperCase()}}`;
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(brief));
  assert.equal(h.posts.length, 1);
  assert.deepEqual(h.posts[0].image_reference_ids, [reference]);
  assert.equal(h.posts[0].brief, brief.slice('Create '.length));
});

test('deferred image review survives reload and applies a choice using a status read and selection only', async () => {
  const storage = new Map(), h = harness(true, storage);
  const requestId = '01'.repeat(16), selectedId = '9'.repeat(32);
  const images = [{ id: requestId, title: 'First', index: 1 }, { id: selectedId, title: 'Second', index: 2 }];
  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: 31 });
  h.scope.imagePreflight = async () => ({ preflight: { requested_count: 2, allowed_count: 2 } });
  h.scope.window.SFCanvasImageReview = {
    confirmGeneration: async () => ({ count: 2, review: true }),
    choose: async () => null,
  };
  h.scope.secureJsonPost = async (path, payload) => {
    h.posts.push({ path, ...JSON.parse(JSON.stringify(payload)) });
    return { state: 'review', generated_images: images, image_total: 2 };
  };
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Create 2 images of a garden'));
  assert.equal(h.posts.length, 1);
  assert.equal(h.displayed.length, 1);
  assert.equal(h.displayed[0].scene.id, 31);
  assert.equal(storage.get('stonefellow.canvas-image-review.7'), requestId);
  assert.equal(h.entries.at(-1).options.imageReview, requestId);

  const reloaded = harness(true, storage);
  reloaded.scope.api = async path => {
    reloaded.reads.push(path);
    return { state: 'review', generated_images: images, image_total: 2 };
  };
  reloaded.scope.window.SFCanvasImageReview = { choose: async assets => { assert.equal(assets, images); return selectedId; } };
  reloaded.scope.secureJsonPost = async (path, payload) => {
    reloaded.posts.push({ path, ...JSON.parse(JSON.stringify(payload)) });
    return { state: 'ready', scene: { ...reloaded.scene(45, ''), canvas_kind: 'custom', image_assets: [images[1]] } };
  };
  await reloaded.scope.handleListeningRoomCommand(reloaded.scope.directListeningRoomCommand('/review-images'));
  assert.deepEqual(reloaded.reads, ['agent-scene.php?request_id=' + requestId]);
  assert.deepEqual(reloaded.preflights, []);
  assert.deepEqual(reloaded.posts, [{ path: 'agent-scene.php', action: 'choose_image_variation', request_id: requestId, image_id: selectedId }]);
  assert.equal(storage.has('stonefellow.canvas-image-review.7'), false);
  assert.equal(reloaded.displayed[0].scene.id, 45);
  assert.equal(reloaded.messages.at(-1), 'I applied your chosen image.');
});

test('presentation shortcodes keep an example intact and creative edits fork it as custom', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Let’s build the Space EQ example for Static Sky {hide-agent}');
  assert.equal(h.posts[0].canvas_kind, 'space_eq');
  assert.equal(h.posts[0].track_id, 'static');
  await run('/edit {design-agent}.');
  assert.equal(h.posts[1].canvas_kind, 'space_eq');
  assert.equal(h.posts[1].previous_id, 1);
  assert.equal(h.posts[1].brief, '{design-agent}.');
  await run('/edit change the design to blue ocean waves');
  assert.equal(h.posts[2].canvas_kind, 'custom');
  assert.equal(h.posts[2].previous_id, 2);
  assert.equal(h.posts[2].track_id, 'static');
});

test('image edits on existing listening rooms build custom revisions for the same track', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Build a listening room for Static Sky');
  await run('/edit Use {image-' + '9'.repeat(32) + '} above the title');
  await run('Build a listening room for Static Sky');
  await run('/edit Create 3 images for the cards');
  assert.deepEqual([h.posts[1], h.posts[3]].map(post => [post.canvas_kind, post.track_id, post.previous_id]), [
    ['custom', 'static', 1], ['custom', 'static', 3],
  ]);
});

test('history and reopening use reads only; guests cannot request a scene', async () => {
  const h = harness();
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Show me my canvas history'));
  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: 31 });
  assert.equal(h.histories[0][0].id, 31);
  assert.equal(h.displayed[0].scene.id, 31);
  assert.deepEqual(h.deltas, []);
  assert.deepEqual(h.spoken, []);
  assert.deepEqual(h.reads, ['agent-scene.php', 'agent-scene.php?id=31']);
  assert.equal(h.posts.length, 0);
  const guest = harness(false);
  await guest.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  assert.match(guest.messages[0], /Sign in/);
  assert.equal(guest.posts.length, 0);
});

test('Let’s Build requests a fresh history-based proposal without starting a build', async () => {
  const h = harness();
  h.scope.ideaProposal = { id: 801, status: 'awaiting_approval', title: 'Lunar currents',
    concept: 'Combine the light and movement from your saved Lunar Garden and Quiet Currents canvases.' };
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Stonefellow, let’s build.'));
  assert.deepEqual(h.reads, []);
  assert.deepEqual(h.posts, []);
  assert.equal(h.ideaRequests.length, 1);
  assert.equal(h.ideaRequests[0].currentCanvasId, undefined);
  assert.equal(h.ideaRequests[0].brief, '');
  assert.equal(h.messages[0], h.scope.ideaProposal.concept);
  assert.doesNotMatch(h.messages[0], /Start with a Stonefellow, artist, or song reference/);
  assert.deepEqual(h.approvedProjects, []);
  assert.deepEqual(h.coding, []);
  assert.deepEqual(h.spoken, []);
});

test('natural build requests and the legacy shortcut work with a truly empty player', async () => {
  const h = harness();
  h.scope.st.track = null;
  h.scope.st.playing = false;
  const prompts = [
    ['Build a moonlit garden', 'a moonlit garden'],
    ['Create a moonlit garden canvas', 'a moonlit garden canvas'],
    ['Can you design a canvas with a moonlit garden?', 'a canvas with a moonlit garden'],
    ['Design a merch dashboard', 'a merch dashboard'],
    ['Let’s build a moonlit garden', 'a moonlit garden'],
  ];
  for (const [index, [prompt, brief]] of prompts.entries()) {
    const action = h.scope.directListeningRoomCommand(prompt);
    assert.equal(action?.kind, 'create', prompt);
    assert.equal(h.scope.canvasBriefInChat(prompt), true, prompt);
    await h.scope.handleListeningRoomCommand(action);
    assert.deepEqual(h.posts[index], {
      path: 'agent-scene.php', canvas_kind: 'custom', track_id: '', brief,
      project_managed: true, proposal_id: 101 + index,
      request_id: `0${index + 1}`.repeat(16),
    });
    assert.equal(h.displayed[index].scene.track_id, '');
  }
});

test('unrelated questions, browsing, playback and native creation stay out of the canvas builder', () => {
  const h = harness();
  for (const text of [
    'How do I build a canvas?', 'Show me my purchases', 'Show tickets', 'Play Static Sky',
    'Build a playlist with Stonefellow songs', 'Build a custom vinyl record', 'Create an account',
    'Can you add the current song to my queue?',
  ]) {
    assert.equal(h.scope.directListeningRoomCommand(text), null, text);
    assert.equal(h.scope.canvasBriefInChat(text), false, text);
  }
});

test('a bare listening room can build empty while explicit song references still use a real song', async () => {
  const h = harness();
  h.scope.st.track = null;
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Build a listening room');
  await run('Let’s build a listening room');
  assert.deepEqual(h.posts.map(post => [post.canvas_kind, post.track_id, post.context_track_id]), [
    ['custom', '', undefined], ['custom', '', undefined],
  ]);
  assert.ok(h.posts.every(post => /listening room/i.test(post.brief)));
  await run('Build a listening room for this song');
  assert.equal(h.posts.length, 2);
  assert.match(h.messages.at(-1), /song/i);
  await run('Build a listening room for Static Sky');
  assert.equal(h.posts[2].track_id, 'static');
  h.scope.st.track = { id: 'desert' };
  await run('Build a listening room');
  assert.equal(h.posts[3].track_id, 'desert');
});

test('Let’s Build selects Canvas 1, procedural EQ, standalone custom and published lyric context', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Lets build Canvas 1 for Static Sky');
  assert.equal(h.posts[0].canvas_kind, 'listening_room');
  assert.equal(h.posts[0].track_id, 'static');
  await run('Let’s build the Space EQ example');
  assert.equal(h.posts[1].canvas_kind, 'space_eq');
  assert.equal(h.posts[1].track_id, '');
  assert.match(h.messages.at(-1), /Space EQ.*No Active Tokens/);
  await run("Let's build: A moonlit garden");
  assert.equal(h.posts[2].canvas_kind, 'custom');
  assert.equal(h.posts[2].track_id, '');
  await run('Let’s build a canvas around the chorus of Static Sky');
  assert.equal(h.posts[3].lyric_section, 'chorus');
  assert.equal(h.posts[3].track_id, 'static');
  await run('Let’s build a canvas from the chorus of a nonexistent song');
  assert.equal(h.posts.length, 4);
  assert.match(h.messages.at(-1), /exact catalog title/);
  await run('Let’s build a canvas for Static Sky using its chorus');
  assert.equal(h.posts[4].lyric_section, 'chorus');
  assert.equal(h.posts[4].track_id, 'static');
  await run('Revise this canvas: use the chorus of Desert Lights');
  assert.equal(h.posts.length, 5);
  assert.match(h.messages.at(-1), /revision keeps the original song/);
  await run('Let’s build a bridge over a river');
  assert.equal(h.posts[5].canvas_kind, 'custom');
  assert.equal(h.posts[5].track_id, '');
  assert.equal(h.posts[5].lyric_section, undefined);
  await run('Let’s build a Space EQ with silver rings');
  assert.equal(h.posts[6].canvas_kind, 'custom');
  assert.equal(h.posts[6].brief, 'a Space EQ with silver rings');
});

test('picked published lyrics build and revise independently of the player and legacy lyric routing', async () => {
  const h = harness();
  h.scope.st.track = null;
  h.scope.st.playing = false;
  h.scope.catalog.push({ id: 'other-static', title: 'Static Sky' });
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Build a typography scene from the lyrics of Static Sky {lyrics:static} {lyrics:desert}');
  assert.equal(h.posts[0].track_id, '');
  assert.equal(h.posts[0].lyric_section, undefined);
  assert.deepEqual(h.posts[0].artist_inputs, { lyrics_ids: ['static', 'desert'] });
  assert.deepEqual(h.approvedProjects[0].project.artist_inputs, h.posts[0].artist_inputs);
  await run('/edit Make the chorus larger {lyrics:desert}');
  assert.equal(h.posts[1].previous_id, 1);
  assert.equal(h.posts[1].lyric_section, undefined);
  assert.deepEqual(h.posts[1].artist_inputs, { lyrics_ids: ['desert'] });

  h.scope.api = async () => ({ scene: { ...h.scene(31, ''), canvas_kind: 'custom', artist_inputs: { lyrics_ids: ['desert'] } } });
  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: 31 });
  await run('/edit Recolor the lyrics');
  assert.equal(h.posts[2].previous_id, 31);
  assert.equal(h.posts[2].lyric_section, undefined);
  assert.equal(h.posts[2].artist_inputs, undefined);
  await run('/edit Remove the lyrics {lyrics-clear}');
  assert.equal(h.posts[3].lyric_section, undefined);
  assert.deepEqual(h.posts[3].artist_inputs, { lyrics_ids: [] });
});

test('account data selections reach the approved custom build as scope IDs with the player empty', async () => {
  const h = harness();
  h.scope.st.track = null;
  h.scope.st.playing = false;
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  const brief = 'Build a list of my own events {me:MY_LISTENING_HISTORY} {me:my_tickets} {me:my_listening_history}';
  await run(brief);
  assert.equal(h.posts[0].track_id, '');
  assert.equal(h.posts[0].canvas_kind, 'custom');
  assert.equal(h.scope.canvasDesignBrief(brief), 'Build a list of my own events');
  assert.deepEqual(h.posts[0].artist_inputs, { me_tools: ['my_listening_history', 'my_tickets'] });
  assert.deepEqual(h.approvedProjects[0].project.artist_inputs, h.posts[0].artist_inputs);
  await run('/edit Add more space');
  assert.equal(h.posts[1].previous_id, 1);
  assert.equal(h.posts[1].artist_inputs, undefined);
  await run('/edit Remove account data {me-clear}');
  assert.equal(h.posts[2].previous_id, 2);
  assert.deepEqual(h.posts[2].artist_inputs, { me_tools: [] });
});

test('the selected canvas restores privately after a reload and stays selected when the song changes', async () => {
  const storage = new Map();
  const first = harness(true, storage);
  await first.scope.handleListeningRoomCommand(first.scope.directListeningRoomCommand('Let’s build a star field for Desert Lights'));
  const saved = first.displayed[0].scene;
  first.scope.closeListeningRoom(false);
  assert.equal(storage.get('stonefellow.activeCanvas./demo/api:7'), String(saved.id));

  const resumed = harness(true, storage);
  resumed.scope.st.track = { id: 'static' };
  resumed.scope.api = async path => { resumed.reads.push(path); return { scene: saved,verification:{verified:true} }; };
  await resumed.scope.restoreActiveCanvas();
  assert.deepEqual(resumed.reads, ['agent-scene.php?id=' + saved.id]);
  assert.equal(resumed.displayed[0].scene, saved);
  assert.deepEqual(resumed.focus, [false]);
  assert.equal(resumed.scope.st.track.id, 'static');
  assert.deepEqual(resumed.posts, []);

  const other = harness(true, storage);
  other.scope.st.auth.user.id = 9;
  await other.scope.restoreActiveCanvas();
  assert.deepEqual(other.reads, []);
  resumed.scope.closeListeningRoom();
  assert.equal(storage.size, 0);
});

test('closing during a quiet restore keeps an old response from reopening the canvas', async () => {
  const h = harness();
  h.scope.selectCanvasId('7', 31);
  let release;
  h.scope.api = () => new Promise(resolve => { release = resolve; });
  const pending = h.scope.restoreActiveCanvas();
  h.scope.closeListeningRoom();
  release({ scene: h.scene(31) });
  await pending;
  assert.deepEqual(h.displayed, []);
});

test('closing during library preparation keeps a quiet restore from reopening the canvas',async()=>{
  const h=harness();h.scope.selectCanvasId('7',31);
  let startPreparation,release;
  const started=new Promise(resolve=>{startPreparation=resolve;}),preparation=new Promise(resolve=>{release=resolve;});
  h.scope.window.SFPublicAgentCanvas.prepareSceneLibraries=()=>{startPreparation();return preparation;};
  const pending=h.scope.restoreActiveCanvas();
  await started;
  h.scope.closeListeningRoom();
  release();await pending;
  assert.deepEqual(h.displayed,[]);
});

test('startup restore refuses unverified new candidates and unsafe existing workflow states without starting models',async()=>{
  for(const mode of ['required-no-job','wrong-candidate','testing','needs_attention','failed']){
    const h=harness();h.scope.selectCanvasId('7',42);
    const response={scene:{...h.scene(42),canvas_kind:'custom',workflow_required:true}};
    if(mode!=='required-no-job')response.workflow={request_id:'ab'.repeat(16),state:mode==='wrong-candidate'?'ready':mode,candidate_scene_id:mode==='wrong-candidate'?43:42,steps:[]};
    h.scope.api=async()=>response;
    await h.scope.restoreActiveCanvas();
    assert.deepEqual(h.displayed,[],mode);
    assert.deepEqual(h.posts,[]);assert.deepEqual(h.workflowPosts,[]);assert.deepEqual(h.workflowRuns,[]);
    assert.deepEqual(h.workflowCandidates,[]);assert.deepEqual(h.workflowUpdates,[]);
    assert.equal(h.scope.selectedCanvasId('7'),null);
    assert.match(h.messages.at(-1),/has not completed its checks/);
  }
});

test('verified derivatives and free or legacy results skip additional coordinator and tester calls',async()=>{
  for(const mode of ['verified-lineage','legacy-model-result','free-result']){
    const h=harness();h.scope.api=async()=>({scene:{...h.scene(31),canvas_kind:'custom'}});
    await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
    h.scope.window.SFCanvasBuildWorkflow.create=()=>{throw Error('No additional model coordinator is allowed');};
    h.scope.window.SFCanvasSceneTester.test=()=>{throw Error('No additional browser probe is required');};
    h.scope.secureJsonPost=async(path,payload)=>{
      h.posts.push({path,...payload});
      return {scene:{...h.scene(42),canvas_kind:'custom',...(mode==='verified-lineage'?{workflow_required:true}:{})},
        design_source:mode==='free-result'?'standard':'model',active_tokens_used:0,...(mode==='verified-lineage'?{verification:{verified:true}}:{})};
    };
    await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('/edit use a linen palette'));
    assert.deepEqual(h.displayed.map(row=>row.scene.id),[31,42],mode);
    assert.equal(h.posts.length,1);assert.deepEqual(h.workflowPosts,[]);
    assert.deepEqual(h.workflowRuns,[]);assert.ok(h.workflowUpdates.some(update=>update===null));
  }
});

test('startup restore awaits exact apply authorization, rechecks local selection and restores workflow panels silently',async()=>{
  for(const closeBeforeAck of [false,true]){
    const h=harness();h.scope.selectCanvasId('7',42);
    const workflow={request_id:'ab'.repeat(16),state:'ready',candidate_scene_id:42,steps:[{id:'review',role:'testing',state:'complete'}]};
    h.scope.api=async()=>({scene:{...h.scene(42),canvas_kind:'custom',workflow_required:true},workflow});
    let started,ack;
    const applied=new Promise(resolve=>{ack=resolve;}),applyStarted=new Promise(resolve=>{started=resolve;});
    h.scope.workflowPost=(_path,payload)=>{started(payload);return applied;};
    const restoring=h.scope.restoreActiveCanvas(),payload=await applyStarted;
    assert.deepEqual(h.displayed,[]);assert.equal(payload.action,'apply');
    if(closeBeforeAck)h.scope.closeListeningRoom();
    ack({workflow:{...workflow,state:'applied'}});await restoring;
    assert.deepEqual(h.displayed.map(row=>row.scene.id),closeBeforeAck?[]:[42]);
    assert.deepEqual(h.posts,[]);assert.equal(h.workflowPosts.length,1);assert.deepEqual(h.workflowRuns,[]);
    if(!closeBeforeAck){
      assert.deepEqual(h.focus,[false]);assert.equal(h.workflowCandidates.at(-1).id,42);
      assert.ok(h.workflowUpdates.every(update=>update.silent===true));
      assert.equal(h.workflowUpdates.at(-1).phase,'applied');
    }
  }
});

test('deleting from Canvas History only clears the selected backdrop when its removal is confirmed', async () => {
  const h = harness();
  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: 31 });
  const stage = h.scope.window.SFPublicAgentCanvas;
  stage.closeSceneHistory = () => { throw new Error('Leave Canvas History open'); };
  h.scope.secureJsonPost = async (path, payload) => {
    h.posts.push([path, payload.action, payload.id]);
    return { ok: true, deleted_id: payload.id === 31 ? 30 : payload.id };
  };
  await h.scope.deleteSavedCanvas(42);
  assert.equal(stage.sceneActive(), true);
  assert.equal(h.scope.selectedCanvasId('7'), 31);
  await assert.rejects(h.scope.deleteSavedCanvas(31), /could not confirm/);
  assert.equal(stage.sceneActive(), true);
  h.scope.secureJsonPost = async (path, payload) => {
    h.posts.push([path, payload.action, payload.id]);
    throw Object.assign(new Error('Canvas not found'), { status: 404, payload: { error: 'scene_not_found' } });
  };
  await h.scope.deleteSavedCanvas(31);
  assert.equal(stage.sceneActive(), false);
  assert.equal(h.scope.selectedCanvasId('7'), null);
  assert.deepEqual(h.posts, [['agent-scene.php', 'delete', 42], ['agent-scene.php', 'delete', 31], ['agent-scene.php', 'delete', 31]]);
});

test('the app edit page restores the exact saved version and prepares chat without submitting a build', async () => {
  const h = harness();
  const stage = h.scope.window.SFPublicAgentCanvas;
  let editing = true;
  let footerHidden = true;
  let focused = false;
  h.scope.input = {
    value: 'Use a warmer palette', focus: () => { focused = true; }, setSelectionRange() {},
  };
  h.scope.setChatFooterHidden = hidden => { footerHidden = hidden; };
  h.scope.closeQuickMenu = () => {};
  h.scope.syncChatImageDraftLimit = () => {};
  stage.closePage = () => { editing = false; };
  h.scope.api = async path => {
    h.reads.push(path);
    return { scene: { ...h.scene(42), canvas_kind: 'custom' } };
  };
  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: 42, editActive: () => editing });
  assert.deepEqual(h.reads, ['agent-scene.php?id=42']);
  assert.equal(h.scope.selectedCanvasId('7'), 42);
  assert.equal(footerHidden, false);
  assert.equal(focused, true);
  assert.equal(h.scope.input.value, '/edit Use a warmer palette');
  assert.deepEqual(h.posts, []);
});

test('a closed app editor or mismatched scene response cannot change the active canvas or draft', async () => {
  for (const cancel of [false, true]) {
    const h = harness();
    await h.scope.handleListeningRoomCommand({ kind: 'restore', id: 31 });
    let editing = true;
    let started;
    let complete;
    const readStarted = new Promise(resolve => { started = resolve; });
    h.scope.api = () => new Promise(resolve => { complete = resolve; started(); });
    h.scope.input = { value: 'Keep this draft' };
    const request = h.scope.handleListeningRoomCommand({ kind: 'restore', id: 42, editActive: () => editing });
    await readStarted;
    if (cancel) editing = false;
    complete({ scene: { ...h.scene(cancel ? 42 : 43), canvas_kind: 'custom' } });
    await request;
    assert.equal(h.scope.selectedCanvasId('7'), 31);
    assert.equal(h.scope.input.value, 'Keep this draft');
    assert.deepEqual(h.displayed.map(({ scene }) => scene.id), [31]);
    assert.deepEqual(h.posts, []);
  }
});

test('the Space EQ sample stays in history instead of reopening automatically', async () => {
  const storage = new Map();
  const h = harness(true, storage);
  const sample = { ...h.scene(31), canvas_kind: 'space_eq' };
  h.scope.api = async () => ({ scene: sample, design_source: 'standard' });
  h.scope.selectCanvasId('7', sample.id);
  await h.scope.restoreActiveCanvas();
  assert.deepEqual(h.displayed, []);
  assert.equal(storage.size, 0);
  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: sample.id });
  assert.equal(h.displayed[0].scene, sample);
  assert.equal(storage.size, 0);
});

test('building on a private history entry can reopen it or fork a revision', async () => {
  const h = harness();
  const saved = { ...h.scene(31, ''), title: 'Lunar Garden', canvas_kind: 'custom' };
  h.scope.api = async path => {
    h.reads.push(path);
    return path.includes('?id=') ? { scene: saved } : { scenes: [saved] };
  };
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Let’s build on my Lunar Garden');
  assert.deepEqual(h.reads, ['agent-scene.php', 'agent-scene.php?id=31']);
  assert.equal(h.displayed[0].scene.id, 31);
  assert.equal(h.posts.length, 0);
  await run('Let’s build on my Lunar Garden: add a quiet horizon');
  assert.equal(h.posts[0].previous_id, 31);
  assert.equal(h.posts[0].canvas_kind, 'custom');
  assert.equal(h.posts[0].track_id, '');
  assert.equal(h.posts[0].brief, 'add a quiet horizon');
});

test('actual playback is optional inspiration and paused music leaves a custom brief open-ended', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  h.scope.st.playing = true;
  await run('Build a river crossing with bridges that unfold');
  assert.equal(h.posts[0].canvas_kind, 'custom');
  assert.equal(h.posts[0].track_id, '');
  assert.equal(h.posts[0].context_track_id, 'desert');
  assert.equal(h.posts[0].lyric_section, undefined);
  h.scope.st.playing = false;
  await run('Create a quiet observatory canvas');
  assert.equal(h.posts[1].track_id, '');
  assert.equal(h.posts[1].context_track_id, undefined);
});

test('catalog shortcodes reach the custom builder without interpreting the reference as a song title', async () => {
  const h = harness();
  const command = 'Let’s build a listening room for {media-fixture-album} with {merch-hoodies}';
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(command));
  assert.equal(h.posts[0].canvas_kind, 'custom');
  assert.equal(h.posts[0].track_id, '');
  assert.equal(h.posts[0].brief, 'a listening room for {media-fixture-album} with {merch-hoodies}');
});

test('the pasted record-store prompt keeps both refs and reports the free catalog fallback', async () => {
  const h = harness();
  h.scope.secureJsonPost = async (path, payload) => {
    h.posts.push({ path, ...payload });
    return { scene: { ...h.scene(51, ''), canvas_kind: 'custom', catalog_refs: ['media-albums', 'merch-hoodies'] },
      design_source: 'standard', active_tokens_used: 0 };
  };
  const prompt = 'Let’s build a Stonefellow record-store window with `{media-albums}` and `{merch-hoodies}`.';
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(prompt));
  assert.equal(h.posts[0].brief, 'a Stonefellow record-store window with `{media-albums}` and `{merch-hoodies}`');
  assert.equal(h.displayed[0].standard, true);
  assert.match(h.messages.at(-1), /live catalog in a built-in layout.*No Active Tokens/);
});

test('custom EQ briefs reach the Coding Agent with the current or named song', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Let’s build an EQ for the song.');
  await run('Let’s build: create an EQ for the currently playing song.');
  await run('Let’s build a Space EQ for Static Sky.');
  await run('Create an EQ for the song.');
  assert.deepEqual(h.posts.map(post => [post.canvas_kind, post.track_id, post.brief]), [
    ['custom', 'desert', 'an EQ for the song'],
    ['custom', 'desert', 'create an EQ for the currently playing song'],
    ['custom', 'static', 'a Space EQ for Static Sky'],
    ['custom', 'desert', 'an EQ for the song'],
  ]);
  assert.equal(h.scope.directListeningRoomCommand('How do equalizers work?'), null);
});

test('a named song stays bound when more design instructions follow the title', async () => {
  const h = harness();
  h.scope.catalog.push({ id: 'earth', title: 'Earth Girl' }, { id: 'blue', title: 'Mr. Blue Sky' });
  h.scope.st.playing = true;
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  const brief = 'a star cluster EQ background for earth girl.  the star clusters should pulse different colors';
  await run(`lets build ${brief}`);
  assert.equal(h.posts[0].canvas_kind, 'custom');
  assert.equal(h.posts[0].track_id, 'earth');
  assert.equal(h.posts[0].context_track_id, undefined);
  assert.equal(h.posts[0].brief, brief);
  await run('Let’s build a background for Mr. Blue Sky. Add constellations');
  await run('Let’s build a background for the currently playing song\nAdd constellations');
  await run('Let’s build from the chorus of Static Sky. Add constellations');
  assert.deepEqual(h.posts.slice(1).map(post => post.track_id), ['blue', 'desert', 'static']);
  assert.equal(h.posts[3].lyric_section, 'chorus');
});

test('closing during generation discards a late result and blocks concurrent requests', async () => {
  const h = harness();
  let resolve;
  let signal;
  let startStream;
  const streamStarted = new Promise(accept => { startStream = accept; });
  h.scope.secureJsonPost = (_path, _payload, _retry, requestSignal, _onDelta, onConcept) => {
    signal = requestSignal;
    onConcept('a listening room');
    startStream();
    return new Promise(accept => { resolve = accept; });
  };
  const pending = h.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  await streamStarted;
  await h.scope.handleListeningRoomCommand({ kind: 'history' });
  assert.equal(h.reads.length, 0);
  assert.match(h.messages.at(-1), /already in progress/);
  await h.scope.handleListeningRoomCommand({ kind: 'close' });
  assert.equal(signal.aborted, true);
  assert.equal(h.voiceSignal(), signal);
  assert.equal(h.coding.at(-1).state, 'idle');
  resolve({ scene: h.scene(1) });
  await pending;
  assert.equal(h.displayed.length, 0);
});

test('an unreadable success response points to history before another paid generation', async () => {
  const h = harness();
  h.scope.secureJsonPost = async (_path, _payload, _retry, _signal, _onDelta, onConcept) => {
    assert.deepEqual(h.coding, []);
    onConcept('a listening room');
    throw Object.assign(new Error('The server returned an invalid response.'), { status: 201 });
  };
  await h.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  assert.equal(h.reads.length, 12);
  assert.ok(h.reads.every(path => path === 'agent-scene.php?request_id=' + '01'.repeat(16)));
  assert.match(h.messages.at(-1), /HTTP 201.*Check Canvas History/);
  assert.equal(h.voiceSignal().aborted, true);
  assert.deepEqual(h.coding.at(-1), { state: 'error', message: h.messages.at(-1) });
  assert.deepEqual(h.displayed, []);
});

test('ambiguous responses and a pending receipt recover the exact result by read-only status checks', async () => {
  for (const result of [
    Object.assign(new Error('Bad Gateway'), { status: 502 }),
    Object.assign(new Error('Encoding failed'), { status: 500, payload: { error: 'response_encoding_failed' } }),
    new TypeError('Failed to fetch'),
    { ok: true },
    { ok: true, state: 'pending' },
  ]) {
    const h = harness();
    h.scope.secureJsonPost = async (path, payload) => {
      h.posts.push({ path, ...payload });
      if (result instanceof Error) throw result;
      return result;
    };
    h.scope.api = async (path, body, signal) => {
      assert.equal(path, 'agent-scene.php?request_id=' + h.posts[0].request_id);
      assert.equal(body, undefined);
      assert.equal(signal.aborted, false);
      h.reads.push(path);
      if (h.reads.length === 1) throw Object.assign(new Error('Not found'), { status: 404 });
      if (h.reads.length === 2) return { ok: true, state: 'pending' };
      return { ok: true, state: 'ready', scene: h.scene(3), active_tokens_used: 243, design_source: 'model' };
    };
    await h.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
    assert.equal(h.posts.length, 1);
    assert.equal(h.reads.length, 3);
    assert.equal(h.displayed[0].scene.id, 3);
    assert.deepEqual(h.deltas, []);
    assert.ok(h.coding.some(entry => /Checking whether/.test(entry.message)));
    assert.match(h.messages.at(-1), /243 Active Tokens/);
  }
});

test('built-in rooms render without model claims or a token charge', async () => {
  const h = harness();
  h.scope.secureJsonPost = async () => ({ scene: h.scene(3), active_tokens_used: 0, design_source: 'standard' });
  await h.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  assert.equal(h.displayed[0].standard, true);
  assert.deepEqual(h.deltas, []);
  assert.equal(h.messages.at(-1), 'I opened a listening room using the built-in layout. No Active Tokens were used.');
});

test('the command announces the model concept before forwarding live code', async () => {
  const h = harness();
  let finish, push, concept;
  h.scope.secureJsonPost = (_path, _payload, _retry, _signal, onDelta, onConcept) => {
    push = onDelta;
    concept = onConcept;
    return new Promise(resolve => { finish = resolve; });
  };
  const command = h.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(h.spoken, []);
  concept('a moonlit listening room');
  concept('a second announcement');
  const announcement = 'I’m building a moonlit listening room.';
  assert.equal(h.messages[0], announcement);
  assert.deepEqual(h.spoken, [{ message: announcement, deviceOnly: true }]);
  push('{"html":"<h1>');
  push('Desert Lights</h1>"}');
  assert.equal(h.coding.at(-1).state, 'streaming');
  assert.deepEqual(h.deltas, [
    { kind: 'raw', text: '{"html":"<h1>' },
    { kind: 'raw', text: 'Desert Lights</h1>"}' },
  ]);
  assert.equal(h.displayed.length, 0);
  finish({ scene: h.scene(1), design_source: 'model' });
  await command;
  assert.equal(h.displayed[0].scene.id, 1);
  push('Late bytes');
  assert.equal(h.deltas.length, 2);
});

test('custom canvas announcements and receipts avoid echoing the brief or a legacy title', async () => {
  const h = harness();
  const brief = 'a space inspired EQ with star clusters that pulse to the live audio';
  const legacy = { ...h.scene(31, ''), canvas_kind: 'custom', title: brief };
  h.scope.secureJsonPost = async (path, payload, _retry, _signal, _onDelta, onConcept) => {
    h.posts.push({ path, ...payload });
    onConcept(payload.brief);
    return { scene: { ...legacy, id: h.posts.length }, active_tokens_used: 12, design_source: 'model' };
  };
  h.scope.api = async path => {
    h.reads.push(path);
    return { scene: legacy, design_source: 'model' };
  };
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));

  await run(`Let's build ${brief}`);
  assert.equal(h.posts[0].brief, brief);
  assert.equal(h.spoken[0].message, 'I’m building your canvas.');
  assert.equal(h.messages.at(-1), 'Your canvas is ready. Used 12 Active Tokens.');

  await run('/edit Make the stars brighter');
  assert.equal(h.posts[1].previous_id, 1);
  assert.equal(h.spoken[1].message, 'I’m revising your canvas.');
  assert.equal(h.messages.at(-1), 'I updated your canvas. Used 12 Active Tokens.');

  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: legacy.id });
  assert.deepEqual(h.reads, ['agent-scene.php?id=31']);
  assert.equal(h.displayed.at(-1).scene, legacy);
  assert.equal(h.messages.at(-1), 'I reopened your canvas.');
  assert.ok(h.messages.every(message => !message.includes(brief)));
  assert.ok(h.coding.every(entry => !entry.message?.includes(brief)));
});

test('canvas instructions stay in the chat transcript while ordinary chat can appear on the stage', () => {
  const h = harness();
  const transcript = [], stage = [];
  h.scope.popupEntries = [];
  h.scope.voiceTranscript = { dataset: {} };
  h.scope.voiceTranscriptText = {};
  h.scope.renderPopupEntries = () => transcript.splice(0, transcript.length, ...h.scope.popupEntries.map(entry => entry.text));
  h.scope.window.SFPublicAgentCanvas.showConversation = (role, text) => stage.push({ role, text });
  const popup = source.slice(source.indexOf('  function popupEntry('), source.indexOf('  async function openChatPopup('));
  const voice = source.slice(source.indexOf('  function showTranscript('), source.indexOf('  function syncAgentDockLayout('));
  vm.runInNewContext(popup + voice, h.scope);

  const build = "Let's build a space inspired EQ with star clusters";
  const revise = '/edit Make the stars brighter';
  const message = 'Play Desert Lights';
  for (const text of [build, revise, message]) h.scope.popupEntry('user', text);
  assert.deepEqual(transcript, [build, revise, message]);
  assert.deepEqual(stage, [{ role: 'user', text: message }]);
  h.scope.showTranscript(`You: ${build}`, 'heard');
  assert.equal(h.scope.voiceTranscriptText.textContent, 'Canvas brief received.');
  h.scope.showTranscript(`You: ${message}`, 'heard');
  assert.equal(h.scope.voiceTranscriptText.textContent, `You: ${message}`);
});

function scenePost(fetch, api) {
  const scope = { fetch, api, DOMException, ReadableStream, TextDecoder, cfg: {}, st: { auth: { csrf: 'fixture' } } };
  vm.runInNewContext(transport, scope);
  return scope.secureJsonPost;
}

test('NDJSON delivers split multibyte source before the terminal result', async () => {
  let controller, receive;
  const concepts = [];
  const source = 'Crépuscule';
  const stream = new ReadableStream({ start(value) { controller = value; } });
  const post = scenePost(async (_url, options) => {
    assert.equal(options.headers.Accept, 'application/x-ndjson');
    return new Response(stream, { headers: { 'Content-Type': 'application/x-ndjson; charset=utf-8' } });
  });
  const firstDelta = new Promise(resolve => { receive = resolve; });
  let completed = false;
  const result = post('agent-scene.php', {}, false, undefined, receive, concept => concepts.push(concept)).then(value => {
    completed = true;
    return value;
  });
  const delta = Buffer.from(JSON.stringify({ type: 'delta', text: source }) + '\n');
  const split = delta.indexOf(Buffer.from('é')) + 1;
  controller.enqueue(Buffer.from('{"type":"concept","text":"a twilight skyline"}\n'));
  controller.enqueue(delta.subarray(0, split));
  controller.enqueue(delta.subarray(split));
  assert.equal(await firstDelta, source);
  assert.deepEqual(concepts, ['a twilight skyline']);
  assert.equal(completed, false);
  controller.enqueue(Buffer.from('{"type":"result","status":201,"data":{"ok":true,"scene":{"id":11}}}\n'));
  controller.close();
  assert.equal((await result).scene.id, 11);
});

test('truncated streams are recoverable while terminal errors preserve their status', async () => {
  const makePost = body => scenePost(async () => new Response(body, { headers: { 'Content-Type': 'application/x-ndjson' } }));
  const partial = '{"type":"delta","text":"{\\"html\\": "}\n';
  await assert.rejects(makePost(partial)('agent-scene.php', {}, false, undefined, () => {}),
    error => error.status === 200 && error.payload === undefined);
  const failed = '{"type":"error","status":422,"data":{"ok":false,"error":"invalid_scene_output"}}\n';
  await assert.rejects(makePost(partial + failed)('agent-scene.php', {}, false, undefined, () => {}),
    error => error.status === 422 && error.payload.error === 'invalid_scene_output');
});

function deferredCanvasResponse(scene) {
  const data = { ok: true, scene, datasets_deferred: true, active_tokens_used: 20, generated_images: [{ id: 'a'.repeat(32) }] };
  return new Response(JSON.stringify({ type: 'result', status: 201, data }) + '\n',
    { headers: { 'Content-Type': 'application/x-ndjson' } });
}

test('oversized streamed lyric results hydrate exact saved builds and repairs using the original track and signal', async () => {
  const original = { id: 42, title: 'Saved title', track_id: '', canvas_kind: 'custom' };
  const datasets = { published_lyrics: { rows: [{ text: 'cue\n'.repeat(300_000) }] } };
  const trackId = 'song-Exact';
  for (const [path, payload] of [
    ['agent-scene.php', { context_track_id: trackId }],
    ['canvas-build-workflow.php', { action: 'repair', current_track_id: trackId }],
  ]) {
    const { signal } = new AbortController();
    const requests = [];
    const post = scenePost(async (url, options) => {
      requests.push(url);
      assert.equal(options.signal, signal);
      return deferredCanvasResponse(original);
    }, async (url, body, readSignal) => {
      requests.push(url);
      assert.equal(body, undefined);
      assert.equal(readSignal, signal);
      return { scene: { ...original, title: 'Different GET metadata', datasets, dataset_track_id: trackId } };
    });
    const result = await post(path, payload, false, signal, () => {});
    assert.deepEqual(requests, ['api/' + path, 'agent-scene.php?id=42&current_track_id=song-Exact']);
    assert.equal(result.datasets_deferred, undefined);
    assert.equal(result.active_tokens_used, 20);
    assert.equal(result.generated_images[0].id, 'a'.repeat(32));
    assert.equal(result.scene.title, original.title);
    assert.equal(result.scene.datasets, datasets);
    assert.equal(result.scene.dataset_track_id, trackId);
  }
});

test('deferred lyric hydration rejects mismatched scene or track context and discards canceled reads', async () => {
  const scene = { id: 42, track_id: '', canvas_kind: 'custom' };
  for (const wrong of [{ ...scene, id: 43 }, { ...scene, dataset_track_id: 'wrong-track' }]) {
    const post = scenePost(async () => deferredCanvasResponse(scene), async () => ({ scene: wrong }));
    await assert.rejects(post('agent-scene.php', {}, false, undefined, () => {}),
      error => error.payload?.error === 'scene_data_unavailable');
  }
  const controller = new AbortController();
  let finish, release;
  const started = new Promise(resolve => { finish = resolve; });
  const post = scenePost(async () => deferredCanvasResponse(scene), async () => new Promise(resolve => {
    release = resolve; finish();
  }));
  const request = post('agent-scene.php', {}, false, controller.signal, () => {});
  await started;
  controller.abort();
  release({ scene });
  await assert.rejects(request, { name: 'AbortError' });
});

test('a deferred lyric read uses current access and ignores markers on unrelated responses', async () => {
  const scene = { id: 42, track_id: '', canvas_kind: 'custom' };
  let reads = 0;
  const post = scenePost(async () => deferredCanvasResponse(scene), async () => { reads++; return { scene }; });
  const restored = await post('agent-scene.php', {}, false, undefined, () => {});
  assert.equal(restored.scene.datasets, undefined);
  assert.equal(restored.datasets_deferred, undefined);
  await post('unrelated.php', {}, false, undefined, () => {});
  assert.equal(reads, 1);
});

test('a failed deferred lyric read keeps the previous canvas and reports that the candidate was saved', async () => {
  const h = harness();
  await h.scope.handleListeningRoomCommand({ kind: 'restore', id: 31 });
  let postCount = 0;
  h.scope.api = async url => {
    h.reads.push(url);
    throw new TypeError('Read interrupted.');
  };
  h.scope.secureJsonPost = scenePost(async () => {
    postCount++;
    return deferredCanvasResponse({ id: 42, track_id: '', canvas_kind: 'custom' });
  }, h.scope.api);
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Build a lyric canvas {lyrics:static}'));
  assert.equal(postCount, 1);
  assert.deepEqual(h.reads, ['agent-scene.php?id=31', 'agent-scene.php?id=42&current_track_id=']);
  assert.deepEqual(h.displayed.map(entry => entry.scene.id), [31]);
  assert.match(h.messages.at(-1), /^Your canvas was saved.*Canvas History/);
  assert.equal(h.completedProjects.at(-1).state, 'needs_attention');
});

test('image progress and keepalives share the coding stream without adding source text', async () => {
  const image = { id: '9'.repeat(32), title: 'Botanical study' };
  const body = [
    { type: 'delta', text: 'design code' }, { type: 'image', state: 'generating' },
    { type: 'image', state: 'working' }, { type: 'image', state: 'saved', image },
    { type: 'result', status: 201, data: { ok: true, scene: { id: 12 } } },
  ].map(event => JSON.stringify(event)).join('\n') + '\n';
  const post = scenePost(async () => new Response(body, { headers: { 'Content-Type': 'application/x-ndjson' } }));
  const phases = [], code = [];
  let receivedImage;
  const result = await post('agent-scene.php', {}, false, undefined, text => code.push(text), undefined, undefined,
    (event, data) => { phases.push(event); if (event === 'image_saved') receivedImage = data.image; });
  assert.deepEqual(phases, ['response_headers', 'image_generating', 'image_saved', 'response_result']);
  assert.equal(receivedImage.id, image.id);
  assert.equal(receivedImage.title, image.title);
  assert.deepEqual(code, ['design code']);
  assert.equal(result.scene.id, 12);
});

test('only the current build image reaches the sidebar and its title stays out of debug', async () => {
  const h = harness();
  const updates = [], debug = [];
  h.scope.window.SFPublicAgentCanvas.setSceneImage = (state, asset) => updates.push([state, asset?.id]);
  h.scope.recordCanvasDebug = (_id, event, data) => debug.push([event, data]);
  h.scope.secureJsonPost = async (_path, payload, _retry, _signal, _delta, _concept, _onRetry, onTrace) => {
    const reused = { id: '9'.repeat(32), title: 'Previous image' };
    const generated = { id: payload.request_id, title: 'Private title' };
    onTrace('image_generating', {});
    onTrace('image_saved', { image: reused });
    onTrace('image_saved', { image: generated });
    assert.deepEqual(updates, [['clear', undefined], ['generating', undefined], ['saved', generated.id]]);
    return { scene: { ...h.scene(1, payload.track_id), canvas_kind: 'custom', image_assets: [reused, generated] } };
  };
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Generate an image of a garden'));
  assert.deepEqual(updates.at(-1), ['saved', '01'.repeat(16)]);
  assert.equal(JSON.stringify(debug).includes('Private title'), false);
  assert.equal(JSON.stringify(debug).includes('Previous image'), false);
});

test('batch image progress and partial receipts preserve every saved image', async () => {
  const h = harness();
  const updates = [];
  h.scope.window.SFPublicAgentCanvas.setSceneImage = (state, asset, position) =>
    updates.push([state, asset?.id, position?.index, position?.total]);
  h.scope.secureJsonPost = async (_path, payload, _retry, _signal, _delta, _concept, _onRetry, onTrace) => {
    const total = 3;
    const first = { id: payload.request_id, title: 'First', index: 1 };
    const second = { id: '9'.repeat(32), title: 'Second', index: 2 };
    onTrace('image_generating', { index: 1, total });
    onTrace('image_saved', { index: 1, total, image: first });
    onTrace('image_generating', { index: 2, total });
    onTrace('image_saved', { index: 2, total, image: second });
    onTrace('image_generating', { index: 3, total });
    throw Object.assign(new Error('Image generation failed.'), { status: 422,
      payload: { error: 'scene_image_unavailable', generated_images: [first, second], image_total: total } });
  };
  await h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Create 3 images for a garden'));
  const firstId = '01'.repeat(16), secondId = '9'.repeat(32);
  assert.deepEqual(updates, [
    ['clear', undefined, undefined, undefined], ['generating', undefined, 1, 3], ['saved', firstId, 1, 3],
    ['generating', undefined, 2, 3], ['saved', secondId, 2, 3], ['generating', undefined, 3, 3],
    ['saved', firstId, 1, 3], ['saved', secondId, 2, 3], ['failed', undefined, undefined, undefined],
  ]);
  assert.equal(h.displayed.length, 0);
});

test('a provider retry clears partial source and can announce the recovered design', async () => {
  const h = harness();
  const source = [];
  h.scope.window.SFPublicAgentCanvas.setSceneCoding = (state, message) => {
    h.coding.push({ state, message });
    if (state === 'generating') source.length = 0;
  };
  h.scope.window.SFPublicAgentCanvas.appendSceneCode = (_kind, text) => source.push(text);
  h.scope.secureJsonPost = async (_path, payload, retry, signal, onDelta, onConcept, onRetry) => {
    const events = [
      { type: 'concept', text: 'a star cluster EQ background' },
      { type: 'delta', text: 'incomplete code' },
      { type: 'retry' },
      { type: 'concept', text: 'a star cluster EQ background' },
      { type: 'delta', text: 'recovered code' },
      { type: 'result', status: 201, data: { scene: { ...h.scene(11), canvas_kind: 'custom' } } },
    ];
    const post = scenePost(async () => new Response(events.map(event => JSON.stringify(event) + '\n').join(''), {
      headers: { 'Content-Type': 'application/x-ndjson' },
    }));
    return post('agent-scene.php', payload, retry, signal, onDelta, onConcept, () => {
      onRetry();
      assert.deepEqual(source, ['incomplete code']);
    });
  };
  const command = h.scope.directListeningRoomCommand('Let’s build a star cluster EQ for Desert Lights');
  await h.scope.handleListeningRoomCommand(command);
  assert.deepEqual(source, ['recovered code']);
  assert.equal(h.spoken.length, 1);
  assert.ok(h.coding.some(entry => entry.message === 'Retrying the same canvas brief…'));
  assert.equal(h.displayed[0].scene.id, 11);
});

function timedCanvasRecovery() {
  const h = harness();
  let elapsed = 0, deadline = 0, expire;
  h.scope.Date = { now: () => elapsed };
  h.scope.setTimeout = (callback, delay) => {
    if (delay >= 10000) {
      deadline = elapsed + delay;
      expire = callback;
      return 0;
    }
    return setTimeout(() => {
      elapsed += delay;
      if (elapsed >= deadline) expire();
      else callback();
    }, 0);
  };
  h.scope.secureJsonPost = async (path, payload) => {
    h.posts.push({ path, ...payload });
    throw Object.assign(new Error('The gateway dropped the stream.'), { status: 502 });
  };
  const recover = () => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build a cosmic background for Desert Lights'));
  return { h, recover, elapsed: () => elapsed };
}

test('a pending custom canvas stays recoverable after a gateway drops the stream', async () => {
  const { h, recover, elapsed } = timedCanvasRecovery();
  h.scope.api = async path => {
    h.reads.push(path);
    if (elapsed() < 121000) return { state: 'pending' };
    return { state: 'ready', scene: { ...h.scene(15), canvas_kind: 'custom' } };
  };
  await recover();
  assert.equal(h.posts.length, 1);
  assert.deepEqual([...new Set(h.reads)], [`agent-scene.php?request_id=${h.posts[0].request_id}`]);
  assert.ok(elapsed() >= 121000);
  assert.equal(h.displayed[0].scene.id, 15);
});

test('Stop Agent waits for server cancellation, keeps the previous canvas and allows the next build', async () => {
  const h = harness();
  const run = text => h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand(text));
  await run('Let’s build a cosmic background for Desert Lights');
  const parent = h.displayed[0].scene;
  let startStream, acknowledgeStop, streamSignal, cancelCount = 0;
  const streamStarted = new Promise(resolve => { startStream = resolve; });
  const cancelResponse = new Promise(resolve => { acknowledgeStop = resolve; });
  h.scope.secureJsonPost = async (path, payload, _retried, signal) => {
    h.posts.push({ path, ...payload });
    if (payload.action === 'cancel') {
      assert.equal(signal,undefined);
      assert.equal(h.workflowPosts.at(-1).request_id,payload.request_id);
      cancelCount++;
      if (cancelCount === 1) throw new Error('The connection dropped.');
      return cancelResponse;
    }
    if (streamSignal) return { scene: { ...parent, id: 9 }, design_source: 'model' };
    streamSignal = signal;
    startStream();
    return new Promise((_, reject) => signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true }));
  };
  const building = run('/edit add stars');
  await streamStarted;
  assert.match(await h.scope.stopListeningRoomBuild(), /stopped locally, but server cancellation could not be confirmed/);
  assert.equal(streamSignal.aborted, false);
  const stopping = h.scope.stopListeningRoomBuild();
  await h.scope.stopListeningRoomBuild();
  assert.equal(cancelCount, 2);
  assert.equal(h.workflowPosts.filter(post=>post.action==='cancel').length,2);
  assert.ok(h.workflowSignals.every(signal=>signal===undefined));
  assert.equal(streamSignal.aborted, false);
  assert.equal(h.posts.at(-1).request_id, h.posts[1].request_id);
  acknowledgeStop({ ok: true, state: 'canceled' });
  await Promise.all([building, stopping]);
  assert.equal(streamSignal.aborted, true);
  assert.equal(h.displayed.length, 1);
  assert.equal(h.coding.at(-1).state, 'cancelled');
  assert.equal(h.messages.at(-1), 'Canvas build stopped.');
  await run('/edit make it amber');
  assert.equal(h.posts.at(-1).previous_id, parent.id);
  assert.equal(h.displayed.at(-1).scene.id, 9);
});

test('managed Stop Agent keeps the previous canvas even if creative generation finished before cancellation', async () => {
  const h = harness();
  await h.scope.handleListeningRoomCommand({kind:'restore',id:31});
  let startStream, streamSignal;
  const streamStarted = new Promise(resolve => { startStream = resolve; });
  h.scope.secureJsonPost = async (_path, payload, _retried, signal) => {
    if (payload.action === 'cancel') {
      assert.equal(signal,undefined);
      assert.equal(h.workflowPosts.at(-1).request_id,payload.request_id);
      return { ok: true, state: 'ready', scene: { ...h.scene(19), canvas_kind: 'custom' }, design_source: 'model' };
    }
    streamSignal = signal;
    startStream();
    return new Promise((_, reject) => signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true }));
  };
  const building = h.scope.handleListeningRoomCommand(h.scope.directListeningRoomCommand('Let’s build a cosmic background for Desert Lights'));
  await streamStarted;
  await h.scope.stopListeningRoomBuild();
  await building;
  assert.equal(streamSignal.aborted, true);
  assert.equal(h.displayed.length, 1);
  assert.equal(h.displayed[0].scene.id, 31);
  assert.equal(h.workflowPosts.at(-1).action,'cancel');
  assert.equal(h.workflowSignals.at(-1),undefined);
  assert.equal(h.messages.at(-1), 'Canvas build stopped.');
});

test('custom canvas recovery stops when the server cannot confirm the original request', async () => {
  const { h, recover, elapsed } = timedCanvasRecovery();
  h.scope.api = async path => {
    h.reads.push(path);
    throw Object.assign(new Error('Request not found.'), { status: 404 });
  };
  await recover();
  assert.ok(elapsed() >= 90000 && elapsed() < 93500);
  assert.equal(h.posts.length, 1);
  assert.equal(h.displayed.length, 0);
  assert.match(h.messages.at(-1), /unreadable response/);
});

test('structured provider errors are shown immediately and closing aborts an in-progress recovery', async () => {
  const rejected = harness();
  rejected.scope.secureJsonPost = async () => {
    throw Object.assign(new Error('More Active Tokens are required.'), {
      status: 402, payload: { error: 'active_tokens_required' },
    });
  };
  await rejected.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  assert.match(rejected.messages.at(-1), /More Active Tokens/);
  assert.equal(rejected.reads.length, 0);
  const requestId = 'ab'.repeat(16);
  rejected.scope.secureJsonPost = async () => {
    throw Object.assign(new Error('The text model timed out before it finished the canvas.'), {
      status: 422, payload: { error: 'scene_model_unavailable', reason: 'timeout', request_id: requestId },
    });
  };
  await rejected.scope.handleListeningRoomCommand(rejected.scope.directListeningRoomCommand('Let’s build a cosmic background for Desert Lights'));
  assert.equal(rejected.messages.at(-1), `Canvas could not be created. The text model timed out before it finished the canvas. Build reference ${requestId}.`);
  assert.equal(rejected.reads.length, 0);
  rejected.scope.secureJsonPost = async () => ({ state: 'failed' });
  await rejected.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  assert.match(rejected.messages.at(-1), /did not finish/);
  assert.equal(rejected.reads.length, 0);
  const h = harness();
  let resolveRead, readSignal;
  h.scope.secureJsonPost = async () => ({ state: 'pending' });
  h.scope.api = (_path, _body, signal) => {
    readSignal = signal;
    return new Promise(resolve => { resolveRead = resolve; });
  };
  const request = h.scope.handleListeningRoomCommand({ kind: 'create', subject: '' });
  await new Promise(resolve => setImmediate(resolve));
  await h.scope.handleListeningRoomCommand({ kind: 'close' });
  assert.equal(readSignal.aborted, true);
  resolveRead({ state: 'ready', scene: h.scene(1) });
  await request;
  assert.equal(h.displayed.length, 0);
});
