"""Exercise the production Listening Room stage with synthetic API responses."""
import io
import json
import math
import os
import re
import shutil
import struct
import wave
import zlib
from pathlib import Path

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'assets/js/app.js').read_text()
SHELL = (ROOT / 'stonefellow-v120.php').read_text()
SHOW_AGENT_BUTTON = re.search(r'<button\b[^>]*\bid="menuShowAgent"[^>]*>[^<]*</button>', SHELL).group(0)
CODING_MODEL_FIELD = re.search(r'<details id="menuCodingModelField"[\s\S]*?</details>', SHELL).group(0)
CHAT_POPUP = re.search(r'<section id="agentChatPopup"[\s\S]*?</section>\s*</section>', SHELL).group(0)
IMAGE_EDIT_DRAFT = re.search(r'<div id="canvasImageEditDraft"[\s\S]*?</div>', SHELL).group(0)
PLAYER_MARKUP = re.search(r'<div id="footerPlayer"[\s\S]*?</audio>\s*</div>', SHELL).group(0)
SOCIAL_LINKS = re.search(r'<div class="social-links"[\s\S]*?</div>', SHELL).group(0)
SITE = 'https://fixture.test/stonefellow/'
TONE_URL = SITE + 'fixture-tone.wav'
SECOND_TONE_URL = SITE + 'second-fixture-tone.wav'
SCENE_IMAGE_ID = 'da' * 16
SCENE_IMAGE_URL = SITE + 'api/agent-canvas-image.php?id=' + SCENE_IMAGE_ID
checks = []
widths = (375, 900, 1440)


def block(start, end):
    at = APP.index(start)
    return APP[at:APP.index(end, at)]


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


def fixture_tone(harmonics=((11000, 1), (4500, 8), (3300, 32))):
    pcm = io.BytesIO()
    with wave.open(pcm, 'wb') as tone:
        rate = 16000
        tone.setparams((1, 2, rate, 0, 'NONE', 'not compressed'))
        cycle = [int(sum(amplitude * math.sin(2 * math.pi * frame * harmonic / 100)
                         for amplitude, harmonic in harmonics))
                 for frame in range(100)]
        tone.writeframes(struct.pack('<100h', *cycle) * (rate // 100 * 12))
    return pcm.getvalue()


def respond_tone(route, body):
    headers = {'accept-ranges': 'bytes'}
    requested = re.fullmatch(r'bytes=(\d+)-(\d*)', route.request.headers.get('range', ''))
    if requested:
        start = int(requested[1])
        end = min(int(requested[2]), len(body) - 1) if requested[2] else len(body) - 1
        if start > end:
            route.fulfill(status=416, headers={**headers, 'content-range': f'bytes */{len(body)}'})
            return
        headers['content-range'] = f'bytes {start}-{end}/{len(body)}'
        route.fulfill(status=206, content_type='audio/wav', headers=headers, body=body[start:end + 1])
        return
    route.fulfill(content_type='audio/wav', headers=headers, body=body)


def fixture_png(width=1, height=1):
    def chunk(name, body):
        return struct.pack('>I', len(body)) + name + body + struct.pack('>I', zlib.crc32(name + body))
    rows = (b'\x00' + b'\x22\x66\xaa' * width) * height
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b''))


FIXTURE = '''() => {
  window.$=(selector,root=document)=>root.querySelector(selector);
  window.$$=(selector,root=document)=>Array.from(root.querySelectorAll(selector));
  window.audio=document.querySelector('audio');
  window.input=document.querySelector('#composer-input');
  window.form=input.form;
  window.menu=document.querySelector('#menuPanel');
  window.chatFooter=document.querySelector('.chat-footer');
  window.chatPopup=document.querySelector('#agentChatPopup');
  window.chatToggle=document.querySelector('#agentChatToggle');
  window.orb=null;window.popupHistoryLoaded=true;
  window.footerPlayer=document.querySelector('#footerPlayer');
  window.starters=document.querySelector('#starters');
  window.mainCanvas=document.querySelector('#target');
  window.cfg={apiBase:'api'};
  window.listeningRoom=null;window.listeningRoomRequest=null;window.listeningRoomOwner=null;window.canvasSelection=null;
  window.accountPhotoPreferencesRequest=null;
  window.st={auth:{authenticated:false,user:null},track:null,playing:false,conversationOn:false,queue:{open:false}};
  window.observedPlayer=()=>({track:st.track,playing:st.playing});
  window.catalog=[{id:'track-1',title:'Testing song',audio:'fixture-tone.wav'},
    {id:'other-track',title:'Another song',audio:'second-fixture-tone.wav'}];
  window.trackById=id=>catalog.find(t=>t.id===id);
  window.songCaptureSkill=window.songListeningSkill=window.vocalStudio=null;
  window.songMoments=null;
  window.iosVoiceNeedsGesture=false;
  window.speechPilotActive=false;
  window.voiceRepliesEnabled=false;window.voicePlaybackPending=false;
  window.voiceTurnBusy=false;window.voiceInteractionStarted=false;
  window.notificationDrawer=null;window.closeMenu=()=>{menu.open=false};window.closeQuickMenu=()=>{};
  window.posts=[];window.gets=[];window.played=[];window.navigations=[];window.popupEntries=[];window.spoken=[];
  window.trackDataReads=[];
  window.imageInventoryReads=[];
  window.modelReads=[];window.fixtureCodingModels=[{provider:'openai',model:'gpt-6-luna'},
    {provider:'anthropic',model:'claude-sonnet-4-6'}];
  window.deletes=[];window.buildStops=[];window.fixtureHistory=[{id:1,title:'<b>Saved room</b>',created_at:'2026-01-01T01:00:00Z'},
    {id:99,title:'Spare canvas',created_at:'2026-01-01T02:00:00Z'}];
  window.voiceClicks=0;window.voiceStops=0;
  window.beginWelcome=()=>{};
  window.handleVoiceClick=()=>{voiceClicks++};
  window.stopConversation=()=>{st.conversationOn=false;voiceStops++;SFPublicAgentCanvas.syncVoice()};
  window.stopAgentSpeaking=()=>{voicePlaybackPending=false};
  window.agentSpeak=async(text,localOnly,signal)=>spoken.push({text,localOnly,signal});
  window.loadAuth=async()=>{};window.syncWelcomeActions=()=>{};
  window.isTemporaryPlaylist=()=>false;
  window.popupEntry=(role,text)=>popupEntries.push({role,text});
  window.agent=(mode,text)=>SFPublicAgentCanvas.setState(mode,text);
  window.play=(track,seconds,source)=>{
    played.push([track.id,seconds,source]);
    if(window.playFixtureTone){
      st.track=track;
      footerPlayer.hidden=false;
      document.querySelector('#playerTitle').textContent=track.title;
      audio.src=track.audio;
      SFPublicAgentCanvas.syncPlayer();
      void audio.play();
    }
  };
  window.scenePlaybackActions=[];
  window.toggle=()=>{
    scenePlaybackActions.push(['toggle',st.track?.id]);
    if(audio.paused)void audio.play();else audio.pause();
  };
  const playerToggle=document.querySelector('#playerToggle');
  playerToggle.onclick=toggle;
  function updatePlayerToggle(){
    playerToggle.textContent=audio.paused?'▶':'❚❚';
    playerToggle.setAttribute('aria-label',audio.paused?'Play':'Pause');
  }
  audio.addEventListener('play',updatePlayerToggle);
  audio.addEventListener('pause',updatePlayerToggle);
  audio.addEventListener('loadedmetadata',()=>{
    const seconds=Math.floor(audio.duration);
    document.querySelector('#playerDuration').textContent=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');
  });
  window.seekPlayback=fraction=>{
    scenePlaybackActions.push(['seek',st.track?.id,fraction]);
    audio.currentTime=fraction*audio.duration;
  };
  window.navigate=(view,params)=>navigations.push([view,params]);
  window.norm=value=>String(value||'').trim().toLowerCase();
  window.scene={id:1,track_id:'track-1',title:'Testing song',
    html:'<section class="room"><h1>Room one</h1><p>Approved track fixture</p></section>',
    css:'.room {color:rgb(230, 173, 115)}',
    story:{id:7,at_ms:61000,title:'Published story',story_slug:'studio-note'}};
  window.catalogScene={id:4,canvas_kind:'custom',track_id:'',title:'Catalog canvas',
    html:'<h1>Catalog panorama</h1>',css:'h1{color:linen}',
    catalog_blocks:[
      {ref:'media-release-one',type:'album',title:'Fixture <b>Album</b>',album_id:'release-one',
        artwork:'assets/images/approved-cover.webp',items:[
          {id:'wrong-track',track_id:'track-1',title:'Album track',duration_seconds:203}
        ]},
      {ref:'merch-hoodies',type:'merch',title:'Merch fixture',items:[
        {id:'merch-one',slug:'linen-shirt',title:'Linen shirt',artwork:'https://egress.test/unapproved.jpg',
          available:true,price_cents:3200,currency:'USD',variants:[{title:'Large',available:true},{title:'Sold out color',available:false}]}
      ]},
      {ref:'show-locations',type:'shows',title:'Shows fixture',items:[
        {id:'show-one',title:'Live at the studio',date:'2027-05-12',venue:'The Studio',location:'Hudson'}
      ]},
      {ref:'price-ticket',type:'tickets',title:'Tickets fixture',items:[
        {id:'offer-one',show_id:'show-one',title:'Studio admission',show_title:'Studio show',date:'2027-05-12',
          venue:'The Studio',location:'Hudson',offer_type:'General admission',price_cents:1800,currency:'USD',available:false},
        {id:'offer-two',show_id:'show-two',title:'Theatre admission',show_title:'Theatre show',date:'2027-06-14',
          location:'Beacon',price_cents:null,currency:'USD'},
        {id:'offer-three',show_id:'show-three',title:'Park admission',show_title:'Park show',date:'2027-07-16',
          location:'Catskill',price_cents:0,currency:'USD'}
      ]},
      {ref:'media-albums',type:'albums',title:'More albums',items:[{id:'release-two',title:'Second release'}]}
    ]};
  window.secureJsonPost=async(path,body,unused,signal,onCode,onConcept,onRetry)=>{
    if(path==='agent-scene.php'&&body.action==='image_preflight')return {ok:true,preflight:{requested_count:null}};
    if(path==='agent-scene.php'&&body.action==='delete'){
      deletes.push(body.id);
      await new Promise(resolve=>window.releaseCanvasDelete=resolve);
      if(window.failNextCanvasDelete){window.failNextCanvasDelete=false;throw Error('The canvas could not be deleted.');}
      fixtureHistory=fixtureHistory.filter(item=>item.id!==body.id);
      return {ok:true,deleted_id:body.id};
    }
    if(path!=='agent-scene.php'||!(signal instanceof AbortSignal)||!(/^[a-f0-9]{32}$/.test(body.request_id)))throw Error('Unexpected write');
    if(body.action==='cancel'){
      buildStops.push(body.request_id);
      await new Promise(resolve=>window.releaseBuildStop=resolve);
      return {ok:true,state:'canceled'};
    }
    if(window.holdNextBuild){
      window.holdNextBuild=false;
      window.heldBuild={requestId:body.request_id,signal};
      onCode('{"html":"<section>');
      return new Promise((_,reject)=>signal.addEventListener('abort',()=>reject(new Error('Build stream closed')),{once:true}));
    }
    posts.push(body);
    if(posts.length===1){
      await new Promise(resolve=>window.releaseSceneConcept=resolve);
      onConcept('a moonlit listening room');
      await new Promise(resolve=>window.releaseSceneFirstDelta=resolve);
      onCode('{"html":"<section>');
      await new Promise(resolve=>window.releaseSceneSecondDelta=resolve);
      onCode('<img src=https://egress.test/live></section>"');
      await new Promise(resolve=>window.releaseSceneResponse=resolve);
      throw Object.assign(new Error('Bad Gateway'),{status:502});
    }
    if(window.interruptNextBuild){
      window.interruptNextBuild=false;
      window.interruptedBuildRef=body.request_id;
      onConcept('another audio-reactive design');
      onCode('{"html":"<section>');
      onRetry('invalid_markup');
      onConcept('a smaller audio-reactive design');
      throw Object.assign(new Error('The model connection was interrupted. Internal diagnostic fixture.'),{
        status:422,payload:{error:'scene_model_unavailable',reason:'stream_incomplete',request_id:body.request_id,
          private_data:'Private listening data fixture',csrf:'CSRF fixture',api_key:'API key fixture'}
      });
    }
    if(posts.length===3)return {ok:true,scene:{...scene,id:3,html:'<h1>Built-in room</h1>'},active_tokens_used:0,design_source:'standard'};
    if(body.canvas_kind==='custom'&&posts.length===5)return {ok:true,
      scene:{...catalogScene,id:5,html:'<h1>Built-in record store</h1>'},active_tokens_used:0,design_source:'standard'};
    if(body.canvas_kind==='custom')return {ok:true,scene:catalogScene,active_tokens_used:12,design_source:'model'};
    return {ok:true,scene:{...scene,id:2,html:'<h1>Room two</h1>'},active_tokens_used:12,design_source:'model'};
  };
  window.api=async(path,body,signal)=>{
    if(body!==undefined||!(signal instanceof AbortSignal))throw Error('Unexpected read');
    if(path.startsWith('agent-canvas-image.php')){
      const id='c1'.repeat(16);
      imageInventoryReads.push(path);
      if(window.holdImageInventory){
        window.holdImageInventory=false;
        window.imageInventorySignal=signal;
        await new Promise(resolve=>window.releaseImageInventory=resolve);
      }
      if(window.usePickerInventory){
        const image=(key,title)=>({id:key.repeat(16),title});
        if(path==='agent-canvas-image.php')return {ok:true,images:[image('c1','<b>Private artwork</b>'),
          image('c2','Older artwork'),image('c3','Evening light')],next_cursor:'c3'.repeat(16)};
        if(path==='agent-canvas-image.php?before='+'c3'.repeat(16))return {ok:true,
          images:[image('c4','Blue hour'),image('c5','Fifth artwork')],next_cursor:null};
      }
      if(path==='agent-canvas-image.php')return {ok:true,images:[{id,title:'<b>Private artwork</b>'}],next_cursor:id};
      if(path==='agent-canvas-image.php?before='+id)return {ok:true,images:[{id:'c2'.repeat(16),title:'Older artwork'}],next_cursor:null};
      throw Error('Unexpected image inventory request');
    }
    const trackDataPrefix='agent-scene.php?id=12&current_track_id=';
    if(path.startsWith(trackDataPrefix)){
      const trackId=decodeURIComponent(path.slice(trackDataPrefix.length));
      trackDataReads.push({trackId,signal});
      if(window.holdTrackDataRead){
        window.holdTrackDataRead=false;
        await new Promise(resolve=>window.releaseTrackDataRead=resolve);
      }
      return {ok:true,scene:trackDatasetFor(trackId)};
    }
    if(path==='agent-scene.php?models=1'){
      modelReads.push(st.auth.user?.id);
      const models=fixtureCodingModels;
      if(window.holdCodingModelRead){
        window.holdCodingModelRead=false;
        window.preloadedModelSignal=signal;
        await new Promise(resolve=>window.releaseCodingModels=resolve);
      }
      return {ok:true,models};
    }
    gets.push(path);
    if(path==='agent-scene.php?request_id='+posts[0]?.request_id)
      return gets.length===1 ? {ok:true,state:'pending'} :
        {ok:true,state:'ready',scene,active_tokens_used:12,design_source:'model'};
    if(path==='agent-scene.php')return {ok:true,scenes:fixtureHistory};
    if(path==='agent-scene.php?id=1')return {ok:true,scene};
    if(path==='agent-scene.php?id=4')return {ok:true,scene:{...catalogScene,catalog_blocks:[
      {type:'merch',title:'Updated merch',items:[{id:'new-product',title:'Fresh public item'}]}
    ]}};
    throw Error('Unexpected read');
  };
}'''


with sync_playwright() as p:
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    launch = {'headless': True, 'args': ['--no-sandbox']}
    if executable:
        launch['executable_path'] = executable
    browser = p.chromium.launch(**launch)
    tone = fixture_tone()
    second_tone = fixture_tone(((11000, 5), (4400, 20)))
    sidebar_png = fixture_png(960, 540)
    for width in widths:
        prefix = f'Chromium/{width} '
        context = browser.new_context(
            viewport={'width': width, 'height': 850},
            reduced_motion='reduce' if width < 720 else 'no-preference', has_touch=width < 720,
            permissions=['clipboard-read', 'clipboard-write'])
        page = context.new_page()
        page.set_default_timeout(5000)
        requests, errors = [], []
        sidebar_image_url = None
        sidebar_batch_image_urls = set()
        page.on('pageerror', lambda error: errors.append(str(error)))

        def respond(route):
            requests.append(route.request.url)
            if route.request.url == SITE:
                route.fulfill(content_type='text/html', body='''<!doctype html><html lang="en"><head>
                <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
                <header class="topbar"><button type="button" class="wordmark">Stonefellow</button>
                <div class="header-actions">
                <details id="menuPanel" class="menu-details">
                <summary id="menuButton" class="menu-button" aria-label="Open menu">•••</summary>
                <div class="menu-sheet"><button id="menuClose" type="button">Close menu</button>
                <nav>''' + SHOW_AGENT_BUTTON + CODING_MODEL_FIELD + '''
                <a id="menuAgentChat" href="?view=agent-chat">My Agent Chat</a></nav></div></details></div></header>
                <main class="stage"><div id="target" class="dynamic-canvas"></div><div id="starters"></div></main>
                <footer id="footer" class="chat-footer">''' + PLAYER_MARKUP + '''
                <div class="chat-composer-shell"><div id="voiceTranscript" hidden></div>''' + CHAT_POPUP + IMAGE_EDIT_DRAFT + '''
                <form class="chatbar"><span></span><input id="composer-input" aria-label="Chat with Stonefellow" maxlength="320">
                <button id="agentChatToggle" type="button" title="Open Agent Chat">▤</button>
                <button id="voiceButton" class="chat-action mic" type="button" aria-label="Start Voice Chat">◉</button>
                </form></div>''' + SOCIAL_LINKS + '''</footer>
                <button id="chatFooterRestore" class="sf-chat-footer-restore">Show chat footer</button>
                </body></html>''')
            elif route.request.url == TONE_URL:
                respond_tone(route, tone)
            elif route.request.url == SECOND_TONE_URL:
                respond_tone(route, second_tone)
            elif route.request.url in {SITE + 'api/agent-canvas-image.php?id=' + image_id * 16 for image_id in ('c1', 'c2', 'c3', 'c4', 'c5')}:
                route.fulfill(content_type='image/png', body=bytes.fromhex(
                    '89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da6364f80f00010501012718e3660000000049454e44ae426082'))
            elif route.request.url == SCENE_IMAGE_URL:
                route.fulfill(content_type='image/png', body=fixture_png())
            elif route.request.url == sidebar_image_url or route.request.url in sidebar_batch_image_urls:
                route.fulfill(content_type='image/png', body=sidebar_png)
            else:
                route.fulfill(status=204)

        context.route('**/*', respond)
        page.goto(SITE)
        for name in ('assets/css/site.css', 'assets/css/public-agent-canvas.css', 'assets/css/canvas-image-review.css'):
            page.add_style_tag(content=(ROOT / name).read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/public-agent-canvas.js').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-image-review.js').read_text())
        page.evaluate(FIXTURE)
        page.add_script_tag(content='\n'.join((
            block('  function syncAgentDockLayout(){', '  // Armed microphone'),
            block('  function hide(){', '  function persist(){'),
            block('  async function openChatPopup(){', '  function say('),
            block('  function syncAuthMenu(){', '  async function loadAuth('),
            block('  function canvasDesignBrief(brief){', '  async function liveAgent(text){'),
            block('  function sceneImagePath(id){', '  async function renderHome(){'),
            block('  async function command(raw){', '  function closeQuickMenu('),
            block('  function setChatFooterHidden(hidden){', '  // The server renders'),
            block('  bindCanvasCodingModelMenu();', "  menu?.addEventListener('click',event=>{"),
            'window.productionScenePost=(()=>{' +
            block('  async function readSceneResponseStream(', '  function applyQueue(') +
            ';return secureJsonPost;})()',
        )))
        page.add_script_tag(content=(ROOT / 'assets/js/accessibility.js').read_text())
        page.evaluate('bindAgentVisibilityMenu();bindCanvasDebug();bindChatImagePicker();form.onsubmit=submitAgentChat;renderAgentCanvas()')
        page.evaluate('SFPublicAgentCanvas.setAtmosphere("lunar")')
        history_button = page.locator('[data-agent-action="scene-history"]')
        stage = page.locator('.sf-agent-scene')
        canvas_stage = page.locator('div.sf-agent-scene-backdrop')
        footer_actions = page.locator('.chat-footer .social-links .sf-agent-scene-footer-actions')
        coding = page.locator('.sf-agent-coding')
        coding_status = coding.locator('.sf-agent-coding-status')
        stop_agent = coding.locator('[data-agent-action="stop-scene-build"]')
        source = coding.locator('.sf-agent-coding-source')
        frame = page.frame_locator('.sf-agent-scene-backdrop iframe')
        backdrop = page.locator('.sf-agent-scene-backdrop iframe')
        mesh = page.locator('.sf-agent-mesh')
        guide = page.get_by_role('dialog', name='Stonefellow guided tour')
        guide_title = guide.locator('[data-guide-title]')
        guide_eq = page.locator('.sf-agent-guide-eq-preview')

        def open_guide_eq():
            page.evaluate('SFPublicAgentCanvas.startGuide()')
            for _ in range(12):
                if guide_title.inner_text() == 'Space EQ':
                    return
                guide.locator('[data-guide-next]').click()
            raise AssertionError(prefix + 'Space EQ step not found in Guided Tour')

        check(not history_button.is_visible() and not canvas_stage.is_visible(), prefix + 'guest stage controls hidden')
        page.locator('#menuButton').click()
        expect(page.locator('#menuButton')).to_have_attribute('aria-expanded', 'true')
        check(page.locator('#menuCodingModelField').is_hidden() and page.evaluate('modelReads.length===0'),
              prefix + 'guest header menu keeps coding models private')
        page.evaluate('closeMenu()')
        page.evaluate('handleListeningRoomCommand(directListeningRoomCommand("Make a listening room for Testing song"))')
        check(page.evaluate('posts.length===0 && popupEntries.at(-1).text.includes("Sign in")'), prefix + 'guest does not invoke scene API')
        open_guide_eq()
        check(guide_eq.is_visible() and not canvas_stage.is_visible() and
              guide_eq.evaluate('node=>node.clientWidth===innerWidth&&node.clientHeight===innerHeight') and
              page.evaluate('posts.length===0&&gets.length===0&&audio.paused&&!audio.currentSrc&&!SFPublicAgentCanvas.sceneActive()'),
              prefix + 'guest Guided Tour shows a full-viewport Space EQ preview without playback or a saved scene')
        guide_still = guide_eq.evaluate('node=>node.toDataURL()')
        guide.locator('[data-guide-next]').click()
        check(guide_eq.count() == 0 and guide_title.inner_text() == 'Merch & Tickets' and mesh.is_visible(),
              prefix + 'leaving the Space EQ stop returns to the original backdrop')
        guide.locator('[data-guide-prev]').click()
        check(guide_eq.is_visible() and guide_title.inner_text() == 'Space EQ', prefix + 'the Space EQ stop can be revisited')
        page.keyboard.press('Escape')
        check(not guide.is_visible() and guide_eq.count() == 0 and mesh.is_visible(),
              prefix + 'closing the tour removes the preview without changing the background')
        page.evaluate('window.holdCodingModelRead=true;st.auth={authenticated:true,user:{id:4}};syncAuthMenu()')
        page.wait_for_function('typeof window.releaseCodingModels==="function"')
        check(page.evaluate('modelReads.length===1&&modelReads[0]===4&&!menu.open'),
              prefix + 'sign-in preloads models while the account menu is closed')
        page.locator('#menuButton').click()
        expect(page.locator('#menuButton')).to_have_attribute('aria-expanded', 'true')
        check(page.evaluate('modelReads.length===1&&!preloadedModelSignal.aborted'),
              prefix + 'opening the account menu shares the pending preload')
        page.locator('.wordmark').click()
        expect(page.locator('#menuButton')).to_have_attribute('aria-expanded', 'false')
        page.evaluate('releaseCodingModels()')
        page.wait_for_function('document.querySelectorAll("#menuCodingModelOptions input").length===3')
        check(page.evaluate('!menu.open&&!preloadedModelSignal.aborted&&modelReads.length===1'),
              prefix + 'the preload finishes while the closed menu stays closed')
        check(history_button.is_visible(), prefix + 'signed-in history action available')
        check(history_button.get_attribute('data-orbit-action') == 'scene-history' and
              history_button.get_attribute('aria-label') == 'Canvas History' and
              page.locator('.sf-agent-orbit-tools [data-agent-action="scene-history"]').count() == 1 and
              page.locator('[data-agent-action="scene-history"]').count() == 1,
              prefix + 'history is a single labeled ring action')
        check(page.locator('.sf-agent-orbit-tools button:not([hidden])').evaluate_all('''buttons=>{
            const visible=buttons.filter(button=>button.offsetWidth&&button.offsetHeight);
            const boxes=visible.map(button=>button.getBoundingClientRect());
            return visible.every(button=>button.offsetWidth>=44&&button.offsetHeight>=44)&&
              boxes.every((box,i)=>box.left>=-1&&box.right<=window.innerWidth+1&&
              boxes.slice(0,i).every(other=>Math.hypot(box.x-other.x,box.y-other.y)>=44));
        }'''), prefix + 'ring targets fit and have 44px center separation')
        page.evaluate('void handleListeningRoomCommand(directListeningRoomCommand("Make a listening room for Testing song"))')
        page.wait_for_function('typeof releaseSceneConcept==="function"')
        check(coding.is_visible() and coding.get_attribute('data-state') == 'generating' and
              not source.is_visible() and not canvas_stage.is_visible() and
              coding_status.inner_text() == 'Planning your canvas…' and page.evaluate('spoken.length===0'),
              prefix + 'Coding Agent shows planning before a build concept arrives')
        page.evaluate('releaseSceneConcept()')
        page.wait_for_function('typeof releaseSceneFirstDelta==="function"')
        announcement = 'I’m building a moonlit listening room.'
        check(coding_status.inner_text() == announcement and not source.is_visible() and
              page.evaluate('''announcement=>spoken.length===1 && spoken[0].text===announcement &&
                            spoken[0].localOnly===true && spoken[0].signal instanceof AbortSignal''', announcement),
              prefix + 'the build concept is announced using the device voice before source arrives')
        page.evaluate('releaseSceneFirstDelta()')
        page.wait_for_function('typeof releaseSceneSecondDelta==="function"')
        check(coding.get_attribute('data-state') == 'streaming' and coding_status.inner_text() == announcement and
              coding.locator('.sf-agent-coding-raw').text_content() == '{"html":"<section>' and
              backdrop.count() == 0 and mesh.is_visible(),
              prefix + 'first real model delta is displayed before the background changes')
        source.locator('summary').focus()
        page.evaluate('handleAgentVisibilityCommand("/hide-agent")')
        check(not page.locator('.sf-agent-sphere').is_visible() and coding.is_visible() and
              coding.get_attribute('data-state') == 'streaming' and
              source.locator('summary').evaluate('node=>node===document.activeElement'),
              prefix + '/hide-agent keeps the active Coding Agent and source focus')
        page.evaluate('releaseSceneSecondDelta()')
        page.wait_for_function('typeof releaseSceneResponse==="function"')
        check(coding.is_visible() and
              coding.locator('.sf-agent-coding-raw').text_content() == '{"html":"<section><img src=https://egress.test/live></section>"' and
              coding.locator('.sf-agent-coding-live img').count() == 0 and backdrop.count() == 0,
              prefix + 'live deltas continue as text with the agent hidden')
        page.evaluate('releaseSceneResponse()')
        page.wait_for_function('gets.length===1')
        check('Checking whether your canvas was saved' in coding_status.inner_text() and
              not canvas_stage.is_visible(), prefix + 'unreadable 502 switches to a read-only recovery status')
        frame.get_by_role('heading', name='Room one').wait_for()
        check(footer_actions.count() == 1 and stage.locator('.sf-agent-scene-footer-actions').count() == 0,
              prefix + 'canvas controls live in the social-links row below the chatbar')
        check(footer_actions.evaluate('''node=>{
          const link=getComputedStyle(node.parentElement.querySelector('a'));
          const close=getComputedStyle(node.querySelector('[data-agent-action="close-scene"]'));
          return close.fontSize===link.fontSize&&parseFloat(close.fontSize)<=13&&close.borderTopWidth==='0px'&&
            close.backgroundImage==='none'&&close.textTransform===link.textTransform;
        }'''), prefix + 'Close canvas matches the small borderless social-link typography')
        check(not page.locator('.sf-agent-sphere').is_visible() and coding.is_visible() and
              coding.get_attribute('data-state') == 'ready' and not stop_agent.is_visible() and
              coding.locator('.sf-agent-coding-html').text_content() == page.evaluate('scene.html'),
              prefix + 'the Coding Agent reaches ready with validated source while the agent is hidden')
        page.evaluate('handleAgentVisibilityCommand("/show-agent")')
        if width >= 821:
            rightmost_ring_edge = page.locator('.sf-agent-orbit-tools button:not([hidden])').evaluate_all(
                'nodes=>Math.max(...nodes.map(node=>node.getBoundingClientRect().right))')
            check(source.get_attribute('open') is not None and coding.bounding_box()['x'] > rightmost_ring_edge,
                  prefix + 'Coding Agent sits open on the right side of the desktop canvas')
        else:
            check(coding.get_attribute('data-state') == 'ready' and source.get_attribute('open') is None,
                  prefix + 'reduced-motion mobile shows source immediately in a collapsed disclosure')
            source.locator('summary').tap()
            check(source.get_attribute('open') is not None and
                  source.locator('.sf-agent-coding-code').evaluate('node=>getComputedStyle(node).overflowY') == 'visible',
                  prefix + 'mobile source uses the page scroll')
        page.wait_for_function('document.querySelector(".sf-agent-coding").dataset.state==="ready"')
        check(coding.locator('.sf-agent-coding-html').text_content() == page.evaluate('scene.html') and
              coding.locator('.sf-agent-coding-css').text_content() == page.evaluate('scene.css') and
              coding.locator('.sf-agent-coding-raw').text_content() == '' and
              coding.locator('code section').count() == 0,
              prefix + 'validated HTML and CSS replace raw streaming text immediately')
        check(coding.evaluate('''node=>{
          const panel=getComputedStyle(node),source=getComputedStyle(node.querySelector('details'));
          return panel.borderStyle==='none'&&panel.backgroundImage==='none'&&
            panel.backgroundColor==='rgba(0, 0, 0, 0)'&&panel.backdropFilter==='none'&&source.borderTopStyle==='none';
        }'''), prefix + 'Coding Agent source sits directly on the canvas without a frame or divider')
        if width < 720:
            source.locator('summary').tap()
        check(canvas_stage.is_visible() and frame.get_by_role('heading', name='Room one').is_visible(), prefix + 'authored room renders')
        check(frame.locator('.room').evaluate('(node)=>getComputedStyle(node).color') == 'rgb(230, 173, 115)', prefix + 'authored CSS applies inside the frame')
        check(backdrop.get_attribute('sandbox') == '' and
              backdrop.evaluate('(node)=>node.contentDocument===null'),
              prefix + 'opaque sandbox cannot share host document')
        check(backdrop.evaluate('''node=>{
          const rect=node.getBoundingClientRect(),style=getComputedStyle(node);
          return rect.x===0&&rect.y===0&&rect.width===innerWidth&&rect.height===innerHeight&&
            style.borderStyle==='none'&&style.pointerEvents==='none'&&node.tabIndex===-1;
        }''') and not mesh.is_visible() and
              stage.locator('h2').inner_text() == 'Canvas 1 · Listening Room',
              prefix + 'authored background fills the viewport and replaces the mesh without stealing focus')
        check(page.evaluate('posts.length===1 && posts[0].track_id==="track-1" && posts[0].previous_id===undefined &&'
                            'gets.length===2 && gets.every(path=>path==="agent-scene.php?request_id="+posts[0].request_id)'),
              prefix + 'creation uses canonical track ID and recovery sends only reads for its exact ID')
        check(page.locator('.sf-agent-sphere').is_visible() and
              page.locator('#sfAgentPlayerDock #footerPlayer').count() == 1 and
              page.locator('.sf-agent-live-transcript-slot #voiceTranscript').count() == 1,
              prefix + 'existing orb, player and transcript nodes retained')
        box = stage.bounding_box()
        check(box and box['x'] >= 0 and box['x'] + box['width'] <= width + 1,
              prefix + 'compact room controls fit viewport')
        voice = page.locator('[data-agent-action="start-chat"]')
        (voice.tap if width < 720 else voice.click)()
        page.locator('#composer-input').click()
        check(page.evaluate('voiceClicks===1&&document.activeElement.id==="composer-input"'),
              prefix + 'voice chat and the text composer remain clickable above the background')
        for selector in ('play-scene-track', 'open-scene-story'):
            button = page.locator(f'[data-agent-action="{selector}"]')
            box = button.bounding_box()
            check(box and box['height'] >= 44, prefix + selector + ' has a 44px target')
            (button.tap if width < 720 else button.click)()
        check(page.evaluate('played.length===1 && played[0][0]==="track-1" && played[0][2]==="player" &&'
                            'navigations.length===1 && navigations[0][0]==="story" && navigations[0][1].slug==="studio-note"'),
              prefix + 'trusted native playback and story callbacks receive verified references')
        check(page.evaluate('''() => {
          const mounted=document.querySelector('.sf-agent-scene-backdrop iframe');
          const attempts=[{...scene,html:'<script>top.__bad=true</script>'},
            {...scene,html:'<img src="https://egress.test/pixel">'},
            {...scene,html:'<p onclick="top.__bad=true">Click</p>'},
            {...scene,css:'</style><script>top.__bad=true</script>'}];
          return attempts.every(candidate=>!SFPublicAgentCanvas.showScene(candidate))&&
            mounted===document.querySelector('.sf-agent-scene-backdrop iframe')&&!window.__bad;
        }'''), prefix + 'malicious HTML, attributes and CSS breakout rejected without replacing the room')
        check(page.evaluate('''SFPublicAgentCanvas.showScene({...scene,
          html:'<p class="probe">CSS network probe</p>',
          css:'.probe {background-image:url(https://egress.test/pixel),url(/stonefellow/api/egress-probe);color:orange}'})'''),
              prefix + 'direct CSS network test rendered at sandbox defense layer')
        frame.get_by_text('CSS network probe').wait_for()
        check('https://egress.test/pixel' in frame.locator('.probe').evaluate('(node)=>getComputedStyle(node).backgroundImage'),
              prefix + 'probe reaches browser style processing')
        page.evaluate('() => new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
        check(not any('egress.test' in url or 'egress-probe' in url for url in requests),
              prefix + 'CSP prevents external and same-origin CSS requests')
        page.evaluate('''window.firstFrame=document.querySelector('.sf-agent-scene-backdrop iframe');
          handleListeningRoomCommand(directListeningRoomCommand('Revise the listening room: make it minimal'))''')
        frame.get_by_role('heading', name='Room two').wait_for()
        check(page.locator('.sf-agent-scene-backdrop iframe').count() == 1 and
              page.evaluate('!firstFrame.isConnected&&posts[1].previous_id===1&&posts[1].brief==="make it minimal"'),
              prefix + 'revision replaces the frame and preserves the prior scene ID')
        history_button.click()
        rail = page.locator('.sf-agent-page-rail[data-view="scene-history"]')
        rail.locator('.sf-agent-page-viewport').wait_for()
        page.wait_for_function('document.querySelector(".sf-agent-page-rail[data-view=scene-history]").classList.contains("open")')
        check(rail.locator('.sf-agent-scene-history').inner_text().find('<b>Saved room</b>') >= 0 and
              rail.locator('.sf-agent-scene-history b').count() == 0, prefix + 'history is a native rail with escaped text')
        image_folder = rail.locator('.sf-agent-scene-image-folder')
        check(image_folder.is_visible() and page.evaluate('imageInventoryReads.length===0'),
              prefix + 'Canvas History includes a collapsed and lazy generated image folder')
        image_folder.get_by_text('Generated Images', exact=True).click()
        image_copy = image_folder.get_by_role('button', name='Copy reference for <b>Private artwork</b>')
        expect(image_copy).to_be_visible()
        check(image_folder.locator('b').count() == 0 and
              image_folder.locator('img').first.get_attribute('src') == 'api/agent-canvas-image.php?id=' + 'c1' * 16,
              prefix + 'generated image names are plain text and the preview uses the same-origin private route')
        page.wait_for_function('document.querySelector(".sf-agent-scene-image-folder img").naturalWidth===1')
        image_copy.click()
        expect(image_folder.get_by_role('status')).to_have_text('Image reference copied.')
        check(page.evaluate('navigator.clipboard.readText()') == '{image-' + 'c1' * 16 + '}',
              prefix + 'the private generated image folder copies a stable reuse reference')
        image_folder.get_by_role('button', name='Load older images').click()
        expect(image_folder.get_by_text('Older artwork', exact=True)).to_be_visible()
        check(page.evaluate('imageInventoryReads.length===2') and
              not image_folder.get_by_role('button', name='Load older images').is_visible(),
              prefix + 'the folder loads the next owner-scoped image page without replacing earlier entries')
        rail.get_by_role('button', name='Open <b>Saved room</b>').click()
        frame.get_by_role('heading', name='Room one').wait_for()
        check(not rail.is_visible() and page.evaluate('posts.length===2&&gets.slice(2).join(",")==="agent-scene.php,agent-scene.php?id=1"'),
              prefix + 'restoring from history is read-only and closes the rail')
        check(coding.get_attribute('data-state') == 'ready' and
              coding.locator('.sf-agent-coding-html').text_content() == page.evaluate('scene.html'),
              prefix + 'reopened source is available immediately')
        history_button.click()
        delete_spare = rail.get_by_role('button', name='Delete Spare canvas')
        expect(delete_spare).to_be_visible()
        delete_spare.click()
        cancel_delete = rail.get_by_role('button', name='Cancel', exact=True)
        expect(cancel_delete).to_be_focused()
        check(delete_spare.bounding_box()['height'] >= 44 and page.evaluate('deletes.length===0'),
              prefix + 'canvas deletion has a 44px target and opens a focused confirmation before posting')
        page.keyboard.press('Escape')
        expect(delete_spare).to_be_focused()
        check(rail.is_visible() and not cancel_delete.is_visible() and page.evaluate('deletes.length===0'),
              prefix + 'Escape cancels deletion and keeps Canvas History open')
        delete_spare.click()
        page.evaluate('window.failNextCanvasDelete=true')
        rail.get_by_role('button', name='Delete canvas', exact=True).click()
        expect(rail.get_by_role('button', name='Deleting…', exact=True)).to_be_disabled()
        page.evaluate('releaseCanvasDelete();delete window.releaseCanvasDelete')
        expect(rail.get_by_role('status')).to_have_text('The canvas could not be deleted.')
        check(delete_spare.is_visible() and page.evaluate('deletes.join(",")==="99"&&listeningRoom.id===1'),
              prefix + 'a failed delete preserves both the saved row and the active canvas')
        rail.get_by_role('button', name='Delete canvas', exact=True).click()
        page.wait_for_function('typeof releaseCanvasDelete==="function"')
        page.evaluate('releaseCanvasDelete();delete window.releaseCanvasDelete')
        expect(rail.get_by_role('status')).to_have_text('Canvas deleted.')
        expect(rail.get_by_role('button', name='Open <b>Saved room</b>')).to_be_focused()
        check(not delete_spare.count() and page.evaluate('deletes.join(",")==="99,99"&&listeningRoom.id===1'),
              prefix + 'confirmed delete removes only the chosen saved canvas and focuses the remaining row')
        page.evaluate('SFPublicAgentCanvas.closeSceneHistory()')
        page.locator('[data-agent-action="close-scene"]').click()
        check(not canvas_stage.is_visible() and backdrop.count() == 0 and mesh.is_visible() and
              not coding.is_visible() and coding.locator('.sf-agent-coding-html').text_content() == '' and
              page.evaluate('!listeningRoom&&!SFPublicAgentCanvas.sceneActive()&&SFPublicAgentCanvas.getAtmosphere()==="lunar"&&'
                            'document.body.dataset.sfAtmosphere==="lunar"&&document.activeElement.classList.contains("sf-agent-sphere")'),
              prefix + 'close clears room and source, restores the previous atmosphere, and returns focus')
        page.evaluate('handleListeningRoomCommand(directListeningRoomCommand("Make a listening room for Testing song"))')
        frame.get_by_role('heading', name='Built-in room').wait_for()
        check(coding.get_attribute('data-state') == 'ready' and
              coding_status.inner_text() == 'Built-in layout source. No Active Tokens were used.' and
              stage.locator('h2').inner_text() == 'Canvas 1 · Listening Room' and
              stage.locator('.sf-agent-scene-signal').inner_text() == 'Built-in layout' and
              page.locator('.sf-agent-scene-backdrop iframe').get_attribute('title') == 'Built-in listening room background' and
              page.evaluate('popupEntries.at(-1).text==="I opened a listening room using the built-in layout. No Active Tokens were used."'),
              prefix + 'built-in layout is immediate and attributed without a charge')
        page.evaluate('''SFPublicAgentCanvas.showScene({...scene,song_title:null,
          artwork:'assets/images/approved-cover.webp'})''')
        frame.get_by_role('heading', name='Room one').wait_for()
        check(frame.get_by_role('heading', name='Room one').is_visible() and
              not stage.get_by_role('button', name='Play song').is_visible() and
              not stage.locator('[data-agent-action="open-scene-story"]').is_visible() and
              not stage.locator('img').is_visible() and
              stage.locator('.sf-agent-scene-signal').inner_text() == 'Source song unavailable',
              prefix + 'a saved room can load after its inspiration song becomes unavailable')
        page.evaluate('''handleListeningRoomCommand({kind:'create',canvasKind:'custom',
          brief:'Build a Stonefellow catalog with {media-release-one}, {merch-hoodies}, {show-locations} and {price-ticket}.'})''')
        frame.get_by_role('heading', name='Catalog panorama').wait_for()
        catalog_panel = page.get_by_role('complementary', name='From the Stonefellow catalog')
        check(catalog_panel.is_visible() and catalog_panel.locator('details').count() == 5 and
              catalog_panel.locator('b').count() == 0 and '<b>Album</b>' in catalog_panel.inner_text() and
              catalog_panel.locator('img').count() == 1 and 'approved-cover.webp' in catalog_panel.locator('img').get_attribute('src') and
              frame.get_by_role('button').count() == 0 and not stage.locator('.sf-agent-scene-signal').is_visible() and
              backdrop.get_attribute('title') == 'Custom canvas background',
              prefix + 'catalog shortcodes render trusted artwork, escaped text and controls outside the sandbox')
        panel_box = catalog_panel.bounding_box()
        check(panel_box and panel_box['x'] >= 0 and panel_box['x'] + panel_box['width'] <= width + 1 and
              catalog_panel.evaluate('node=>getComputedStyle(node).overflowY==="visible"'),
              prefix + 'native catalog fits the viewport and uses the outer page scroll')

        def activate(control):
            (control.tap if width < 720 else control.click)()

        activate(catalog_panel.get_by_role('button', name='Play Album track'))
        activate(catalog_panel.get_by_role('button', name='View album Fixture <b>Album</b>'))
        for name, action in (
            ('Merch fixture', 'View item Linen shirt'),
            ('Shows fixture', 'View show Live at the studio'),
            ('Tickets fixture', 'View tickets Studio admission'),
            ('More albums', 'View album Second release'),
        ):
            activate(catalog_panel.locator('summary').filter(has_text=name))
            activate(catalog_panel.get_by_role('button', name=action))
        check(page.evaluate('''played.at(-1).join(",")==="track-1,0,player" &&
              JSON.stringify(navigations.slice(-5))===JSON.stringify([
                ['release',{id:'release-one'}],['store',{slug:'linen-shirt'}],['show',{id:'show-one'}],
                ['tickets',{id:'show-one'}],['release',{id:'release-two'}]
              ])''') and 'Options: Large' in catalog_panel.inner_text() and 'Unavailable' in catalog_panel.inner_text() and
              'Sold out color' not in catalog_panel.inner_text() and not any('unapproved.jpg' in url for url in requests),
              prefix + 'catalog actions use canonical song, album, merch and event identifiers')
        studio_ticket = catalog_panel.get_by_role('listitem').filter(has_text='Studio admission').inner_text()
        theatre_ticket = catalog_panel.get_by_role('listitem').filter(has_text='Theatre admission').inner_text()
        park_ticket = catalog_panel.get_by_role('listitem').filter(has_text='Park admission').inner_text()
        check(all(detail in studio_ticket for detail in ('Studio show', '2027-05-12', 'Hudson', 'General admission', '$18.00')) and
              all(detail in theatre_ticket for detail in ('Theatre show', '2027-06-14', 'Beacon', 'See ticket provider for price')) and
              '$0.00' not in theatre_ticket and 'Park show' in park_ticket and '$0.00' in park_ticket,
              prefix + 'ticket rows identify their shows and distinguish an unknown provider price from free admission')
        page.evaluate('handleListeningRoomCommand({kind:"restore",id:4})')
        check(catalog_panel.get_by_text('Fresh public item').is_visible() and
              not catalog_panel.get_by_text('Linen shirt').count() and
              page.evaluate('posts.length===4&&gets.at(-1)==="agent-scene.php?id=4"'),
              prefix + 'reopening a saved canvas replaces catalog cards with fresh read-only data')
        page.evaluate('''handleListeningRoomCommand({kind:'create',canvasKind:'custom',
          brief:'Build a Stonefellow record store with {media-albums} and {merch-hoodies}.'})''')
        frame.get_by_role('heading', name='Built-in record store').wait_for()
        check(catalog_panel.is_visible() and stage.locator('.sf-agent-scene-signal').inner_text() == 'Built-in catalog layout' and
              backdrop.get_attribute('title') == 'Built-in catalog canvas background' and
              coding_status.inner_text() == 'Built-in catalog layout source. No Active Tokens were used.',
              prefix + 'fallback catalog canvas preserves native cards and identifies its built-in source')
        page.evaluate('''window.playFixtureTone=true;listeningRoom=scene;scrollTo(0,0);
          SFPublicAgentCanvas.showScene({...scene,canvas_kind:'space_eq',song_title:scene.title,
            artwork:'assets/images/approved-cover.webp'},{standard:true})''')
        eq = page.locator('.sf-agent-scene-backdrop canvas.sf-agent-space-eq')
        signal = stage.locator('.sf-agent-scene-signal')
        play_source = stage.get_by_role('button', name='Play song')
        open_source_story = stage.locator('[data-agent-action="open-scene-story"]')
        check(eq.is_visible() and backdrop.count() == 0 and not coding.is_visible() and not catalog_panel.is_visible() and
              stage.locator('h2').inner_text() == 'Space EQ' and
              not play_source.is_visible() and not open_source_story.is_visible() and not stage.locator('img').is_visible() and
              eq.evaluate('node=>node.clientWidth===innerWidth&&node.clientHeight===innerHeight'),
              prefix + 'Space EQ uses a full-viewport canvas with no source song controls')
        check(eq.evaluate('''node=>{
          const ctx=node.getContext('2d'),compact=node.clientWidth<720;
          const at=(x,y)=>ctx.getImageData(Math.round(node.width*x),Math.round(node.height*y),1,1).data;
          const left=at(.21,compact?.19:.34),right=at(.79,compact?.21:.31),moon=at(.72,compact?.60:.56);
          return [left,right,moon].every(pixel=>pixel[3]===255)&&left[0]>left[2]&&right[2]>right[0];
        }'''), prefix + 'Space EQ draws distinct procedural planet bodies')
        still = eq.evaluate('node=>node.toDataURL()')
        page.evaluate('play(trackById("track-1"),0,"player")')
        page.wait_for_function('!audio.paused && audio.currentTime > 0')
        if width < 720:
            check(signal.inner_text() == 'Still view' and eq.evaluate('node=>node.toDataURL()') == still,
                  prefix + 'Space EQ stays still with reduced motion enabled')
        else:
            page.wait_for_function("document.querySelector('.sf-agent-scene-signal').textContent==='Reacting to playback'")
            page.wait_for_function("still=>document.querySelector('.sf-agent-space-eq').toDataURL()!==still", arg=still)
            check(page.evaluate('!audio.paused && !audio.muted'), prefix + 'real playback samples change Space EQ without interrupting audio')
        page.evaluate('''window.spaceLayer=document.querySelector('.sf-agent-space-eq');audio.pause()''')
        if width >= 720:
            page.wait_for_function("document.querySelector('.sf-agent-scene-signal').textContent==='Play music to activate'")
            check(eq.evaluate('node=>node.toDataURL()') == still, prefix + 'paused Space EQ returns to its baseline')
        page.evaluate('play(trackById("other-track"),0,"player")')
        page.wait_for_function('!audio.paused && audio.currentTime > 0 && audio.currentSrc.endsWith("second-fixture-tone.wav")')
        if width >= 720:
            page.wait_for_function("document.querySelector('.sf-agent-scene-signal').textContent==='Reacting to playback'")
            page.wait_for_function("still=>document.querySelector('.sf-agent-space-eq').toDataURL()!==still", arg=still)
        check(eq.is_visible() and eq.evaluate('node=>node===window.spaceLayer') and
              page.evaluate('st.track.id==="other-track"&&!audio.paused&&!audio.muted'),
              prefix + 'same Space EQ canvas follows a different song on the player')
        check(page.evaluate('SFPublicAgentCanvas.showScene({...scene,track_id:"",canvas_kind:"space_eq"},{standard:true})') and
              eq.is_visible(), prefix + 'a trackless Space EQ can open over the current player')
        page.evaluate('''window.datasetScene={...scene,canvas_kind:'custom',track_id:'',title:'Dataset canvas',
          html:'<section><ol class="sf-dataset-personal_listening_stats"><li class="sf-data-row">'+
            '<strong class="sf-field-title"></strong><small class="sf-field-listens"></small></li>'+
            '<li class="sf-data-empty">No listening data</li></ol><div class="sf-dataset-published_catalog">'+
            '<article class="sf-data-row"><h2 class="sf-field-title"></h2></article></div></section>',
          css:'.sf-dataset-personal_listening_stats li{--layout-size:calc(10px + var(--sf-value-listens,0) * 2px)}'};
          window.privateDatasetTitle='<img src="https://egress.test/data-probe">';
          SFPublicAgentCanvas.showScene({...datasetScene,datasets:{
            personal_listening_stats:{fields:{title:'text',listens:'number'},rows:[
              {title:privateDatasetTitle,listens:0},{title:'Listened track',listens:7}]},
            published_catalog:{fields:{title:'text'},rows:[{title:'Published track'}]}}})''')
        listening_rows = frame.locator('.sf-dataset-personal_listening_stats .sf-data-row')
        expect(listening_rows).to_have_count(2)
        check(listening_rows.locator('.sf-field-title').all_text_contents() ==
              [page.evaluate('privateDatasetTitle'), 'Listened track'] and
              listening_rows.locator('.sf-field-listens').all_text_contents() == ['0', '7'] and
              listening_rows.last.evaluate('node=>["--sf-value-listens","--sf-row-index","--sf-row-count"]'
                                           '.map(name=>node.style.getPropertyValue(name))') == ['7', '1', '2'] and
              frame.get_by_role('heading', name='Published track').count() == 1,
              prefix + 'one host binding fills two model-authored dataset layouts and supplies numeric CSS values')
        check(frame.locator('img,script,.sf-data-empty').count() == 0 and
              backdrop.get_attribute('sandbox') == '' and backdrop.evaluate('node=>node.contentDocument===null') and
              coding.locator('.sf-agent-coding-html').text_content() == page.evaluate('datasetScene.html') and
              not any('data-probe' in url for url in requests),
              prefix + 'dataset values stay escaped inside the sandbox and out of the coding source')
        page.evaluate('SFPublicAgentCanvas.showScene(datasetScene)')
        expect(frame.locator('.sf-data-empty')).to_have_text('No listening data')
        check(frame.locator('.sf-data-row').count() == 0 and frame.get_by_text('Listened track').count() == 0,
              prefix + 'a scene without authorized data removes row templates and earlier account values')
        page.evaluate('''imageId=>{window.trackDatasetScene={...datasetScene,id:12,title:'Track-aware canvas',
          html:'<section class="generated-backdrop"><figure class="sf-image-'+imageId+'"></figure>'+
            '<figure class="sf-image-'+('e'.repeat(32))+'"></figure>'+
            '<ol class="sf-dataset-personal_listening_stats sf-current-track">'+
            '<li class="sf-data-row"><strong class="sf-field-title"></strong><small class="sf-field-listens"></small></li>'+
            '<li class="sf-data-empty">No stats for the current song</li></ol>'+
            '<ol class="sf-dataset-personal_listening_stats sf-filter-track_id--track-1">'+
            '<li class="sf-data-row"><strong class="sf-field-title"></strong></li></ol></section>',
          css:'.generated-backdrop{background-image:var(--sf-image-'+imageId+',none)}'+
            '.sf-current-track{background-image:url(https://egress.test/generated-image-probe)}',
          image_assets:[{id:imageId,title:'Private generated fixture',url:'https://egress.test/image-metadata-probe'}]};
          window.trackDatasetFor=trackId=>({...trackDatasetScene,dataset_track_id:trackId,datasets:{
            personal_listening_stats:{fields:{track_id:'text',title:'text',listens:'number'},filters:['track_id'],
              rows:[{track_id:'track-1',title:'Pinned track'},{track_id:'other-track',title:'General stats for another track'}],
              current_rows:[{track_id:trackId,title:'Current stats for '+trackId,listens:0},
                {track_id:'different-track',title:'Unexpected foreign row',listens:9}]}}});
          SFPublicAgentCanvas.showScene(trackDatasetFor('other-track'));
          window.trackDataFrame=document.querySelector('.sf-agent-scene-backdrop iframe');}''', SCENE_IMAGE_ID)
        current_stats = frame.locator('.sf-current-track .sf-data-row')
        pinned_stats = frame.locator('.sf-filter-track_id--track-1 .sf-data-row .sf-field-title')
        no_stats = frame.locator('.sf-current-track .sf-data-empty')
        expect(current_stats.locator('.sf-field-title')).to_have_text('Current stats for other-track')
        image = frame.get_by_alt_text('Private generated fixture')
        expect(image).to_have_js_property('naturalWidth', 1)
        check(frame.locator('img').count() == 1 and image.get_attribute('src').startswith('blob:null/') and
              'blob:null/' in frame.locator('.generated-backdrop').evaluate('node=>getComputedStyle(node).backgroundImage') and
              backdrop.get_attribute('sandbox') == 'allow-scripts' and backdrop.evaluate('node=>node.contentDocument===null') and
              requests.count(SCENE_IMAGE_URL) == 1 and not any('image-probe' in url or 'image-metadata-probe' in url for url in requests),
              prefix + 'one owner-approved raster supplies a generated background and figure inside an opaque no-egress frame')
        check(current_stats.locator('.sf-field-listens').text_content() == '0' and pinned_stats.text_content() == 'Pinned track' and
              frame.get_by_text('Unexpected foreign row').count() == 0 and page.evaluate('trackDataReads.length===0'),
              prefix + 'the initial current-track dataset shows only matching scoped rows beside ordinary exact filters')
        page.evaluate('''window.holdTrackDataRead=true;st.track=trackById('track-1');SFPublicAgentCanvas.syncPlayer();''')
        expect(no_stats).to_have_text('No stats for the current song')
        check(current_stats.count() == 0 and pinned_stats.text_content() == 'Pinned track' and
              page.evaluate('trackDataReads.map(read=>read.trackId).join(",")==="track-1"'),
              prefix + 'switching tracks hides earlier stats while fetching the selected track through the host')
        page.evaluate('''st.track=null;SFPublicAgentCanvas.syncPlayer();releaseTrackDataRead();''')
        page.wait_for_function('trackDataReads[0].signal.aborted')
        expect(no_stats).to_have_text('No stats for the current song')
        check(current_stats.count() == 0 and pinned_stats.text_content() == 'Pinned track' and
              page.evaluate('trackDataReads.length===1'),
              prefix + 'clearing the player aborts the fetch and a late result cannot repopulate the track binding')
        page.evaluate('''st.track=trackById('track-1');SFPublicAgentCanvas.syncPlayer();''')
        expect(current_stats.locator('.sf-field-title')).to_have_text('Current stats for track-1')
        expect(image).to_have_js_property('naturalWidth', 1)
        check(pinned_stats.text_content() == 'Pinned track' and
              backdrop.evaluate('node=>node===window.trackDataFrame') and
              page.evaluate('!audio.paused&&trackDataReads.map(read=>read.trackId).join(",")==="track-1,track-1"') and
              requests.count(SCENE_IMAGE_URL) == 1,
              prefix + 'current-track data refreshes in the same frame while audio, fixed filters and image stay active')
        page.evaluate('''st.track=trackById('other-track');SFPublicAgentCanvas.syncPlayer();''')
        expect(current_stats.locator('.sf-field-title')).to_have_text('Current stats for other-track')
        page.evaluate('''window.ticketDataset={fields:{title:'text',show_id:'text',offer_type:'text',price_cents:'number'},
          filters:['show_id','offer_type'],rows:[
            {title:'Free entry',show_id:'Night',offer_type:'rsvp',price_cents:0},
            {title:'Venue pricing',show_id:'Night',offer_type:'ticket',price_cents:null},
            {title:'Balcony',show_id:'Night',offer_type:'ticket',price_cents:3200},
            {title:'Another show',show_id:'Day',offer_type:'ticket',price_cents:800},
            {title:'Different show ID',show_id:'night',offer_type:'ticket',price_cents:1200}]};
          window.ticketGrid={...datasetScene,title:'Offer gallery',
            html:'<section class="offer-grid sf-dataset-published_ticket_offers"><article class="sf-data-row">'+
              '<h2 class="sf-field-title"></h2><p class="sf-field-price_cents"></p></article>'+
              '<p class="sf-data-empty">No offers</p><p class="sf-data-unavailable">Offers unavailable</p></section>',
            css:'.offer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}'};
          SFPublicAgentCanvas.showScene({...ticketGrid,datasets:{published_ticket_offers:ticketDataset}})''')
        offers = frame.locator('.offer-grid .sf-data-row')
        expect(offers).to_have_count(5)
        check(frame.locator('.offer-grid').evaluate('node=>getComputedStyle(node).display') == 'grid' and
              offers.locator('.sf-field-price_cents').all_text_contents()[:3] == ['0', '', '3200'] and
              offers.nth(0).evaluate('node=>node.style.getPropertyValue("--sf-value-price_cents")') == '0' and
              offers.nth(1).evaluate('node=>node.style.getPropertyValue("--sf-value-price_cents")') == '' and
              frame.locator('.sf-data-empty,.sf-data-unavailable').count() == 0,
              prefix + 'the authored gallery distinguishes free entry from an unknown price')
        page.evaluate('''SFPublicAgentCanvas.showScene({...datasetScene,title:'Offer index',
          html:'<section><ol class="sf-dataset-published_ticket_offers sf-filter-show_id--Night sf-filter-offer_type--ticket">'+
            '<li class="sf-data-row"><strong class="sf-field-title"></strong></li></ol>'+
            '<ol class="sf-dataset-published_ticket_offers sf-filter-title--Balcony">'+
            '<li class="sf-data-row"><strong class="sf-field-title"></strong></li>'+
            '<li class="sf-data-empty">No authorized match</li></ol></section>',
          css:'ol{list-style:decimal}',datasets:{published_ticket_offers:ticketDataset}})''')
        filtered_offers = frame.locator('ol.sf-filter-show_id--Night .sf-data-row')
        expect(filtered_offers).to_have_count(2)
        check(filtered_offers.locator('.sf-field-title').all_text_contents() == ['Venue pricing', 'Balcony'] and
              frame.locator('ol.sf-filter-title--Balcony .sf-data-row').count() == 0 and
              frame.get_by_text('No authorized match').count() == 1,
              prefix + 'the same data fills an authored list with exact combined filters and rejects unlisted fields')
        page.evaluate('''SFPublicAgentCanvas.showScene({...ticketGrid,datasets:{published_ticket_offers:{
          ...ticketDataset,state:'unavailable',rows:[]}}})''')
        expect(frame.locator('.sf-data-unavailable')).to_have_text('Offers unavailable')
        check(frame.locator('.sf-data-empty,.sf-data-row').count() == 0,
              prefix + 'a declared unavailable state uses the authored failure message')
        page.evaluate('''SFPublicAgentCanvas.showScene({...ticketGrid,datasets:{published_ticket_offers:{
          ...ticketDataset,rows:[]}}})''')
        expect(frame.locator('.sf-data-empty')).to_have_text('No offers')
        check(frame.locator('.sf-data-unavailable,.sf-data-row').count() == 0,
              prefix + 'an available empty dataset shows the authored empty message')
        page.evaluate('''SFPublicAgentCanvas.showScene({canvas_kind:'custom',track_id:'',
          title:'Silent geometry',
          html:'<section class="geometry"><h1>Silent geometry</h1><details class="reveal"><summary>Open the sketch</summary><p>Private sketch content</p></details></section>',
          css:'.geometry{padding:90px 16px}.reveal{width:170px}h1{color:lime}@keyframes quiet{from{opacity:.8}to{opacity:1}}'})''')
        frame.get_by_role('heading', name='Silent geometry').wait_for()
        check(not play_source.is_visible() and not open_source_story.is_visible() and
              not eq.is_visible() and page.evaluate('!audio.paused'),
              prefix + 'trackless custom canvases hide song controls and leave existing audio alone')
        disclosure = frame.locator('summary')
        (disclosure.tap if width < 720 else disclosure.click)(position={'x': 8, 'y': 10})
        check(frame.get_by_text('Private sketch content').is_visible() and backdrop.get_attribute('tabindex') == '0' and
              backdrop.get_attribute('sandbox') == '',
              prefix + 'custom canvas supports native disclosure inside the opaque sandbox')
        disclosure.press('Space')
        page.locator('#composer-input').click()
        check(not frame.get_by_text('Private sketch content').is_visible() and
              page.evaluate('document.activeElement.id==="composer-input"'),
              prefix + 'keyboard disclosure and native host controls both work with an interactive canvas')
        page.evaluate('play(trackById("track-1"),0,"player")')
        page.wait_for_function('!audio.paused && audio.currentTime > 0 && audio.currentSrc.endsWith("/fixture-tone.wav")')
        page.evaluate('''SFPublicAgentCanvas.showScene({...scene,canvas_kind:'custom',artwork:'assets/images/approved-cover.webp',
          lyric_cue:{start_ms:4000,end_ms:8000}});
          window.cueLayer=document.querySelector('.sf-agent-scene-backdrop iframe');
          audio.currentTime=5''')
        page.wait_for_function('document.querySelector(".sf-agent-landing").classList.contains("has-scene-lyric-cue")')
        check(not play_source.is_visible() and not open_source_story.is_visible() and not stage.locator('img').is_visible(),
              prefix + 'a custom canvas treats its source song as inspiration')
        page.evaluate('audio.currentTime=1')
        page.wait_for_function('!document.querySelector(".sf-agent-landing").classList.contains("has-scene-lyric-cue")')
        page.evaluate('play(trackById("other-track"),0,"player")')
        page.wait_for_function('!audio.paused && audio.currentSrc.endsWith("second-fixture-tone.wav")')
        page.evaluate('audio.currentTime=6')
        page.wait_for_function('!audio.seeking')
        check(not page.locator('.sf-agent-landing.has-scene-lyric-cue').count() and
              backdrop.is_visible() and backdrop.evaluate('node=>node===window.cueLayer') and
              frame.get_by_role('heading', name='Room one').is_visible(),
              prefix + 'custom backdrop survives playback changes and its lyric cue follows only its source song')
        page.evaluate('''selectCanvasId('4',scene.id);
          window.tourStart={posts:posts.length,gets:gets.length,src:audio.currentSrc,selected:selectedCanvasId('4')}''')
        open_guide_eq()
        if width >= 720:
            page.wait_for_function("still=>document.querySelector('.sf-agent-guide-eq-preview').toDataURL()!==still", arg=guide_still)
        else:
            check(guide_eq.evaluate('node=>node.toDataURL()') == guide_still,
                  prefix + 'the guided preview respects reduced motion during playback')
        guide.locator('[data-guide-close]').click()
        check(guide_eq.count() == 0 and backdrop.is_visible() and backdrop.evaluate('node=>node===window.cueLayer') and
              page.evaluate('!audio.paused&&audio.currentSrc===tourStart.src&&posts.length===tourStart.posts&&gets.length===tourStart.gets&&'
                            'tourStart.selected===scene.id&&selectedCanvasId("4")===tourStart.selected'),
              prefix + 'closing the active Space EQ tour restores the identical canvas and keeps playback and selection')
        check(page.evaluate('''() => {
          audio.pause();
          return SFPublicAgentCanvas.showScene({...scene,canvas_kind:'custom',track_id:'',title:'Reactive canvas',
            html:'<section class="pulse"><h1>Reactive canvas</h1></section>',
            css:'.pulse{opacity:calc(.1 + .9 * var(--sf-audio-level, 0));--fixture-bass:var(--sf-audio-bass, 0);'+
              '--fixture-mid:var(--sf-audio-mid, 0);--fixture-treble:var(--sf-audio-treble, 0);'+
              'background-image:url(https://egress.test/audio-probe),url(/stonefellow/api/audio-egress-probe)}'});
        }'''), prefix + 'a generated custom canvas opts into bounded player audio values through CSS')
        pulse = frame.locator('.pulse')
        expect(pulse).to_have_css('opacity', '0.1')
        motion_reduced = width < 720
        check(backdrop.get_attribute('sandbox') == ('' if motion_reduced else 'allow-scripts') and
              backdrop.evaluate('node=>node.contentDocument===null') and frame.locator('script').count() == int(not motion_reduced) and
              pulse.evaluate('''node=>{
                const script=document.createElement('script');
                script.textContent='document.body.dataset.cspProbe="ran"';
                document.head.append(script);
                return !document.body.dataset.cspProbe && getComputedStyle(node).backgroundImage.includes('audio-probe');
              }'''), prefix + 'reactive sandbox keeps its origin opaque and blocks unapproved scripts')
        page.evaluate('() => new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
        check(not any('audio-probe' in url or 'audio-egress-probe' in url for url in requests),
              prefix + 'reactive sandbox CSP prevents external and same-origin CSS requests')
        page.evaluate('''window.reactiveFrame=document.querySelector('.sf-agent-scene-backdrop iframe');
          play(trackById('track-1'),0,'player')''')
        page.wait_for_function('!audio.paused&&audio.currentTime>.15&&audio.currentSrc.endsWith("/fixture-tone.wav")')
        if width < 720:
            check(pulse.evaluate('node=>getComputedStyle(node).opacity') == '0.1',
                  prefix + 'generated reactive CSS stays at its baseline with reduced motion')
        else:
            reactive_opacity = re.compile(r'^(?:0\.(?!1$)\d+|1)$')
            expect(pulse).to_have_css('opacity', reactive_opacity)
            check(pulse.evaluate('''node=>{
                const style=getComputedStyle(node);
                return ['bass','mid','treble'].every(name=>{
                  const value=Number(style.getPropertyValue('--fixture-'+name));
                  return value>0&&value<=1;
                });
              }''') and page.evaluate('!audio.paused&&!audio.muted'),
                  prefix + 'real playback drives all three generated CSS bands without interrupting audio')
        page.evaluate('audio.pause()')
        expect(pulse).to_have_css('opacity', '0.1')
        page.evaluate('play(trackById("other-track"),0,"player")')
        page.wait_for_function('!audio.paused&&audio.currentTime>.15&&audio.currentSrc.endsWith("second-fixture-tone.wav")')
        expect(pulse).to_have_css('opacity', '0.1' if width < 720 else reactive_opacity)
        check(backdrop.evaluate('node=>node===window.reactiveFrame') and page.evaluate('!audio.paused&&!audio.muted'),
              prefix + 'generated audio CSS resets on pause and follows another song in the same iframe')
        debug_panel = page.locator('#agentChatPopup #canvasDebugPanel')
        debug_report = debug_panel.locator('#canvasDebugReport')
        debug_copy = debug_panel.get_by_role('button', name='Copy debug report')
        debug_composer = page.locator('#composer-input')
        page.evaluate('''window.beforeDebug={entries:popupEntries.length,builds:posts.length};''')
        debug_composer.fill('')
        for _ in range(3):
            debug_composer.press('d')
        expect(debug_composer).to_have_value('ddd')
        expect(debug_panel).not_to_be_visible()
        debug_composer.press('Enter')
        expect(debug_panel).to_be_visible()
        check(debug_composer.input_value() == '' and
              page.evaluate('popupEntries.length===beforeDebug.entries&&posts.length===beforeDebug.builds'),
              prefix + 'ddd stays ordinary keystrokes in the composer until submitted as a local debug command')
        page.evaluate('document.activeElement.blur()')
        for _ in range(3):
            page.keyboard.press('d')
        expect(debug_panel).not_to_be_visible()
        for _ in range(3):
            page.keyboard.press('d')
        expect(debug_panel).to_be_visible()
        check(debug_report.get_attribute('readonly') is not None and debug_copy.is_visible(),
              prefix + 'three quick D presses toggle the diagnostics panel within chat')
        page.evaluate('window.beforeRetryAnnouncements=popupEntries.length')
        page.evaluate('''window.interruptNextBuild=true;handleListeningRoomCommand({kind:'create',canvasKind:'custom',
          brief:'Let the next Stonefellow design respond to music.'})''')
        build_ref = page.evaluate('interruptedBuildRef')
        report = debug_report.input_value()
        build_events = {}
        for line in report.splitlines():
            parts = line.split(' ', 3)
            if len(parts) >= 3 and parts[1] == build_ref:
                build_events[parts[2]] = json.loads(parts[3]) if len(parts) == 4 else {}
        check(build_events.get('provider_retry') == {'reason': 'invalid_markup'} and
              build_events.get('build_failed') == {'code': 'scene_model_unavailable', 'status': 422, 'reason': 'stream_incomplete'} and
              all(secret not in report for secret in ('Internal diagnostic fixture', 'Private listening data fixture',
                  'CSRF fixture', 'API key fixture', 'Let the next Stonefellow design', '<section>')) and
              page.evaluate('popupEntries.slice(beforeRetryAnnouncements).filter(entry=>entry.role==="assistant").length===1'),
              prefix + 'debug report records the build reference, retry and failure without private content or a second announcement')
        activate(debug_copy)
        expect(debug_panel.get_by_role('status')).to_have_text('Debug report copied.')
        check(page.evaluate('navigator.clipboard.readText()') == report,
              prefix + 'Copy debug report writes exactly the displayed diagnostics')
        page.evaluate('closeChatPopup()')
        expect(debug_panel).not_to_be_visible()
        if not coding.is_visible():
            activate(footer_actions.get_by_role('button', name='Code', exact=True))
        if source.get_attribute('open') is None:
            activate(source.locator('summary'))
        check(coding.get_attribute('data-state') == 'error' and coding.locator('.sf-agent-coding-live').is_visible() and
              'Unfinished model output' in coding.locator('.sf-agent-coding-live').inner_text() and
              coding.locator('.sf-agent-coding-raw').text_content() == '{"html":"<section>' and
              backdrop.evaluate('node=>node===window.reactiveFrame') and pulse.is_visible(),
              prefix + 'a retry with no code leaves first-attempt source visible and the previous canvas active on failure')
        page.evaluate('''handleAgentVisibilityCommand('/hide-agent');window.holdNextBuild=true;
          void handleListeningRoomCommand({kind:'create',canvasKind:'custom',brief:'Build another Stonefellow scene.'})''')
        expect(stop_agent).to_be_visible()
        check(stop_agent.inner_text() == 'Stop Agent' and stop_agent.bounding_box()['height'] >= 44 and
              not page.locator('.sf-agent-sphere').is_visible() and coding.get_attribute('data-state') == 'streaming',
              prefix + 'Stop Agent stays accessible during a stream with the orb hidden')
        activate(stop_agent)
        page.wait_for_function('typeof releaseBuildStop==="function"')
        check(stop_agent.get_attribute('aria-disabled') == 'true' and
              page.evaluate('buildStops.length===1&&buildStops[0]===heldBuild.requestId&&!heldBuild.signal.aborted'),
              prefix + 'Stop Agent sends one cancellation for the active build and waits for acknowledgement')
        page.evaluate('releaseBuildStop()')
        expect(stop_agent).not_to_be_visible()
        check(coding_status.inner_text() == 'Canvas build stopped.' and
              backdrop.evaluate('node=>node===window.reactiveFrame') and
              page.evaluate('heldBuild.signal.aborted&&!listeningRoomRequest'),
              prefix + 'confirmed cancellation closes the stream and leaves the prior canvas open')
        page.evaluate('handleAgentVisibilityCommand("/show-agent")')
        page.evaluate('''() => {
          const originalFetch=window.fetch,fixturePost=window.secureJsonPost,fixtureApi=window.api;
          const fixture=window.imageBuildFixture={title:'Private artwork <b>fixture</b>'};
          fixture.emit=event=>fixture.controller.enqueue(new TextEncoder().encode(JSON.stringify(event)+'\\n'));
          fixture.finish=(scene,receipt={})=>{
            fixture.emit({type:'result',status:201,data:{ok:true,scene,design_source:'model',...receipt}});
            fixture.controller.close();
          };
          fixture.restore=()=>{window.fetch=originalFetch;window.secureJsonPost=fixturePost;window.api=fixtureApi};
          window.fetch=(path,options)=>{
            if(path!=='api/agent-scene.php'||options?.method!=='POST')return originalFetch(path,options);
            fixture.payload=JSON.parse(options.body);
            if(fixture.payload.action==='image_preflight')return Promise.resolve(new Response(
              JSON.stringify({ok:true,preflight:{requested_count:null}}),{headers:{'content-type':'application/json'}}));
            const body=new ReadableStream({start(controller){fixture.controller=controller;}});
            return Promise.resolve(new Response(body,{headers:{'content-type':'application/x-ndjson'}}));
          };
          window.secureJsonPost=window.productionScenePost;
          st.auth.csrf='fixture';
          void handleListeningRoomCommand({kind:'create',canvasKind:'custom',brief:'Create artwork for a private scene.'});
        }''')
        page.wait_for_function('!!window.imageBuildFixture?.controller')
        sidebar_image_id = page.evaluate('imageBuildFixture.payload.request_id')
        sidebar_image_url = SITE + 'api/agent-canvas-image.php?id=' + sidebar_image_id
        page.evaluate('''() => {
          const fixture=imageBuildFixture;
          fixture.emit({type:'delta',text:'{"html":"<section>'});
          fixture.emit({type:'image',state:'generating'});
          fixture.emit({type:'image',state:'working'});
        }''')
        image_section = coding.locator('.sf-agent-coding-image')
        image_progress = image_section.locator('.sf-agent-coding-image-progress')
        image_status = image_section.locator('.sf-agent-coding-image-status')
        image_preview = image_section.get_by_role('button', name='Open image preview')
        expect(image_status).to_have_text('Creating image…')
        expect(image_progress).to_be_visible()
        check(image_section.get_by_role('heading', name='Create Image', exact=True).is_visible() and
              image_section.evaluate('''node=>Boolean(node.compareDocumentPosition(
                node.parentElement.querySelector('.sf-agent-coding-source')) & Node.DOCUMENT_POSITION_FOLLOWING)''') and
              image_progress.get_attribute('value') is None and not image_preview.is_visible() and
              sidebar_image_url not in requests,
              prefix + 'Create Image sits above Scene source and shows indeterminate generation progress')
        page.evaluate('''() => {
          const fixture=imageBuildFixture;
          fixture.asset={id:fixture.payload.request_id,title:fixture.title,width:960,height:540,
            url:'https://egress.test/sidebar-preview-probe'};
          fixture.emit({type:'image',state:'saved',image:fixture.asset});
        }''')
        expect(image_preview).to_be_visible()
        thumbnail = image_preview.locator('img')
        expect(thumbnail).to_have_js_property('naturalWidth', 960)
        check(not image_progress.is_visible() and sidebar_image_url in requests and
              not any('sidebar-preview-probe' in url for url in requests) and
              backdrop.evaluate('node=>node===window.reactiveFrame'),
              prefix + 'the sidebar shows the private saved image before the final canvas and ignores external metadata URLs')
        thumbnail_width = thumbnail.bounding_box()['width']
        activate(image_preview)
        image_dialog = page.get_by_role('dialog', name='Generated image preview')
        large_image = image_dialog.get_by_alt_text('Private artwork <b>fixture</b>', exact=True)
        close_preview = image_dialog.get_by_role('button', name='Close preview')
        expect(image_dialog).to_be_visible()
        expect(large_image).to_have_js_property('naturalWidth', 960)
        image_box = large_image.bounding_box()
        check(image_box['width'] > thumbnail_width and image_box['x'] >= 0 and
              image_box['x'] + image_box['width'] <= width and image_dialog.locator('b').count() == 0,
              prefix + 'the modal opens the larger decoded image and keeps its title as plain text')
        page.keyboard.press('Escape')
        expect(image_dialog).not_to_be_visible()
        expect(image_preview).to_be_focused()
        activate(image_preview)
        activate(close_preview)
        expect(image_dialog).not_to_be_visible()
        check(image_preview.evaluate('node=>node===document.activeElement'),
              prefix + 'Escape and the modal close button return focus to the thumbnail')
        page.evaluate('''() => {
          const fixture=imageBuildFixture;
          fixture.scene={id:43,canvas_kind:'custom',track_id:fixture.payload.track_id,title:'Image fixture',
            html:'<h1>Image fixture</h1><figure class="sf-image-'+fixture.asset.id+'"></figure>',
            css:'h1{color:tan}',image_assets:[fixture.asset]};
          fixture.finish(fixture.scene);
        }''')
        authored_image = frame.get_by_alt_text('Private artwork <b>fixture</b>', exact=True)
        expect(authored_image).to_have_js_property('naturalWidth', 960)
        check(image_preview.is_visible() and thumbnail.evaluate('image=>image.naturalWidth===960') and
              requests.count(sidebar_image_url) == 1,
              prefix + 'the finished canvas and sidebar share a single private image download')
        page.evaluate('''() => {
          const fixture=imageBuildFixture;
          fixture.controller=null;
          void handleListeningRoomCommand({kind:'create',canvasKind:'custom',brief:'Create a scene with the saved artwork.'});
        }''')
        page.wait_for_function('!!imageBuildFixture.controller')
        expect(image_section).not_to_be_visible()
        page.evaluate('''() => {
          const fixture=imageBuildFixture;
          fixture.finish({...fixture.scene,id:44,image_assets:[fixture.asset]});
        }''')
        page.wait_for_function('!listeningRoomRequest&&listeningRoom.id===44')
        expect(authored_image).to_have_js_property('naturalWidth', 960)
        check(not image_section.is_visible(), prefix + 'reused imagery in a later build does not show as a newly created image')
        page.evaluate('imageBuildFixture.controller=null')
        batch_composer = page.locator('#composer-input')
        batch_composer.fill('Create 3 images of a garden in a gallery')
        batch_composer.press('Enter')
        page.wait_for_function('!!imageBuildFixture.controller')
        check('3 images of a garden in a gallery' in page.evaluate('imageBuildFixture.payload.brief'),
              prefix + 'an ordinary create-three-images prompt enters the canvas generation flow')
        batch_image_ids = [page.evaluate('imageBuildFixture.payload.request_id'), 'e2' * 16, 'e3' * 16]
        batch_titles = ['First garden', 'Second garden', 'Third garden']
        sidebar_batch_image_urls.update(SITE + 'api/agent-canvas-image.php?id=' + image_id for image_id in batch_image_ids)
        page.evaluate('''assets=>{
          const fixture=imageBuildFixture;
          fixture.batchAssets=assets;
          fixture.imageStep=(state,index)=>fixture.emit({type:'image',state,index,total:3,
            ...(state==='saved'?{image:assets[index-1]}:{})});
          fixture.imageStep('generating',1);
        }''', [{'id': image_id, 'title': title} for image_id, title in zip(batch_image_ids, batch_titles)])
        expect(image_status).to_have_text('Creating image 1 of 3…')
        expect(image_progress).to_be_visible()
        page.evaluate('''imageBuildFixture.imageStep('saved',1);imageBuildFixture.imageStep('generating',2);
          imageBuildFixture.imageStep('working',2)''')
        expect(image_status).to_have_text('Creating image 2 of 3…')
        batch_previews = [image_section.get_by_role('button', name='Open image preview: ' + title, exact=True)
                          for title in batch_titles]
        expect(batch_previews[0].locator('img')).to_have_js_property('naturalWidth', 960)
        check(image_progress.is_visible() and not batch_previews[1].is_visible(),
              prefix + 'the first thumbnail stays visible while the second image generates')
        page.evaluate('''imageBuildFixture.imageStep('saved',2);imageBuildFixture.imageStep('generating',3);
          imageBuildFixture.imageStep('saved',3)''')
        expect(image_status).to_have_text('3 images ready.')
        for preview, title in zip(batch_previews, batch_titles):
            expect(preview.locator('img')).to_have_js_property('naturalWidth', 960)
            activate(preview)
            expect(image_dialog.get_by_alt_text(title, exact=True)).to_have_js_property('naturalWidth', 960)
            activate(close_preview)
            expect(preview).to_be_focused()
        check(not image_progress.is_visible(), prefix + 'all generated thumbnails open their own full-size preview')
        page.evaluate('''() => {
          const fixture=imageBuildFixture;
          const assets=fixture.batchAssets;
          fixture.gallery={...fixture.scene,id:45,title:'Garden images',html:'<h1>Garden images</h1><section class="garden-gallery">'+
            assets.map((asset,index)=>'<figure class="garden-slot-'+(index+1)+' sf-image-'+asset.id+'"></figure>').join('')+'</section>',
            css:'.garden-gallery{position:absolute;left:10px;top:145px;width:180px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px}'+
              '.garden-gallery figure{margin:0}.garden-slot-2{background-image:var(--sf-image-'+assets[1].id+',none)}',image_assets:assets};
          fixture.finish(fixture.gallery,{generated_images:assets.map((asset,index)=>({...asset,index:index+1})),image_total:3});
        }''')
        for title in batch_titles:
            expect(frame.get_by_alt_text(title, exact=True)).to_have_js_property('naturalWidth', 960)
        check(all(requests.count(url) == 1 for url in sidebar_batch_image_urls) and
              all(preview.is_visible() for preview in batch_previews),
              prefix + 'the gallery and sidebar preserve all three assets and share each private image download')

        edit_draft = page.locator('#canvasImageEditDraft')
        second_garden = frame.locator('.garden-slot-2').get_by_alt_text(batch_titles[1], exact=True)
        second_garden.evaluate('image=>image.click()')
        page.evaluate('() => new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
        expect(edit_draft).not_to_be_visible()
        check(second_garden.evaluate('image=>!image.hasAttribute("role")&&!image.hasAttribute("tabindex")') and
              not page.locator('.sf-agent-scene-backdrop iframe').evaluate('frame=>frame.classList.contains("sf-agent-scene-interactive")'),
              prefix + 'ordinary image placement has no automatic edit action or interactive frame')
        image_picker = page.locator('.sf-agent-scene-image-picker')
        activate(image_picker.locator('summary'))
        activate(image_picker.get_by_role('button', name='Edit ' + batch_titles[1], exact=True))
        expect(edit_draft).to_contain_text(batch_titles[1])
        expect(batch_composer).to_be_focused()
        activate(edit_draft.locator('[data-canvas-image-edit-cancel]'))
        expect(edit_draft).not_to_be_visible()
        check(page.evaluate('imageBuildFixture.payload.action!=="edit_image"&&!listeningRoomRequest'),
              prefix + 'the footer image menu deliberately selects an image and selection can be canceled before sending')

        activate(batch_previews[1])
        activate(image_dialog.get_by_role('button', name='Edit image', exact=True))
        expect(image_dialog).not_to_be_visible()
        expect(edit_draft).to_contain_text(batch_titles[1])
        expect(batch_composer).to_be_focused()
        page.evaluate('imageBuildFixture.controller=null')
        edit_instruction = 'Make this one warmer and leave more room for a title.'
        batch_composer.fill(edit_instruction)
        batch_composer.press('Enter')
        page.wait_for_function('!!imageBuildFixture.controller')
        payload = page.evaluate('imageBuildFixture.payload')
        check(payload['action'] == 'edit_image' and payload['previous_id'] == 45 and
              payload['image_id'] == batch_image_ids[1] and payload['brief'] == edit_instruction and
              payload['canvas_kind'] == 'custom' and 'coding_model' not in payload,
              prefix + 'a plain chat instruction targets the selected image and its active canvas')
        replacement_title = 'Second garden, warmer'
        sidebar_batch_image_urls.add(SITE + 'api/agent-canvas-image.php?id=' + payload['request_id'])
        page.evaluate('''imageBuildFixture.emit({type:'image',state:'generating',index:1,total:1})''')
        expect(image_progress).to_be_visible()
        expect(image_status).to_have_text('Editing image…')
        expect(second_garden).to_be_visible()
        page.evaluate('''title=>{
          const fixture=imageBuildFixture;
          const original=fixture.gallery;
          const previous=original.image_assets[1];
          const image={id:fixture.payload.request_id,title,index:1};
          fixture.editedGallery={...original,id:46,previous_id:original.id,
            html:original.html.replaceAll(previous.id,image.id),css:original.css.replaceAll(previous.id,image.id),
            image_assets:[original.image_assets[0],image,original.image_assets[2]]};
          fixture.emit({type:'image',state:'saved',image,index:1,total:1});
          fixture.finish(fixture.editedGallery,{generated_images:[image],image_total:1});
        }''', replacement_title)
        replacement = frame.locator('.garden-slot-2').get_by_alt_text(replacement_title, exact=True)
        expect(replacement).to_have_js_property('naturalWidth', 960)
        for index in (1, 3):
            expect(frame.locator('.garden-slot-' + str(index)).get_by_alt_text(
                batch_titles[index - 1], exact=True)).to_have_js_property('naturalWidth', 960)
        expect(edit_draft).not_to_be_visible()
        check(second_garden.count() == 0 and page.evaluate('''() => {
              const fixture=imageBuildFixture,from=listeningRoom.image_assets[1].id,to=fixture.batchAssets[1].id;
              return listeningRoom.id===46&&listeningRoom.previous_id===45&&
                ['html','css'].every(key=>listeningRoom[key].replaceAll(from,to)===fixture.gallery[key]);
            }'''),
              prefix + 'an image edit replaces only its slot and preserves the gallery and other images')

        edit_controls = footer_actions.locator('[data-agent-action="edit-scene-images"]')
        expect(edit_controls).to_have_text('Edit images')
        check(edit_controls.evaluate('''node=>{
          const link=getComputedStyle(node.closest('.social-links').querySelector('a')),style=getComputedStyle(node);
          return style.fontSize===link.fontSize&&style.borderTopWidth==='0px'&&style.backgroundImage==='none';
        }'''), prefix + 'Edit images uses the same small borderless styling as the social links')
        activate(edit_controls)
        page.wait_for_function('''() => {const node=document.querySelector('.sf-agent-scene-image-picker[open] > ul');
          if(!node)return false;const box=node.getBoundingClientRect();return box.left>=0&&box.right<=innerWidth+1;}''')
        check(footer_actions.locator('.sf-agent-scene-image-picker ul').evaluate('''node=>{
          const box=node.getBoundingClientRect();return box.left>=0&&box.right<=innerWidth+1;
        }'''), prefix + 'the footer image menu fits within the viewport')
        activate(footer_actions.get_by_role('button', name='Edit ' + batch_titles[2], exact=True))
        expect(edit_draft).to_contain_text(batch_titles[2])
        page.evaluate('imageBuildFixture.controller=null')
        batch_composer.fill('Try a different color palette.')
        batch_composer.press('Enter')
        page.wait_for_function('!!imageBuildFixture.controller')
        page.evaluate('''() => {
          const fixture=imageBuildFixture;
          fixture.emit({type:'error',status:422,data:{ok:false,error:'scene_image_unavailable',message:'The image edit failed.'}});
          fixture.controller.close();
        }''')
        expect(coding).to_have_attribute('data-state', 'error')
        expect(replacement).to_have_js_property('naturalWidth', 960)
        check(page.evaluate('imageBuildFixture.payload.previous_id===46&&listeningRoom.id===46') and
              frame.locator('.garden-slot-3').get_by_alt_text(batch_titles[2], exact=True).is_visible(),
              prefix + 'a failed edit leaves the current revision and its images on screen')

        page.evaluate('''() => {
          const fixture=imageBuildFixture,read=window.api;
          window.api=(path,body,signal)=>{
            if(path==='agent-scene.php')return Promise.resolve({ok:true,scenes:[fixture.editedGallery,fixture.gallery]});
            if(path==='agent-scene.php?id=45')return Promise.resolve({ok:true,scene:fixture.gallery});
            if(path==='agent-canvas-image.php')return Promise.resolve({ok:true,images:[
              fixture.editedGallery.image_assets[1],fixture.gallery.image_assets[1]],next_cursor:null});
            return read(path,body,signal);
          };
        }''')
        activate(history_button)
        activate(image_folder.get_by_text('Generated Images', exact=True))
        expect(image_folder.get_by_role('button', name='Copy reference for ' + batch_titles[1], exact=True)).to_be_visible()
        expect(image_folder.get_by_role('button', name='Copy reference for ' + replacement_title, exact=True)).to_be_visible()
        activate(rail.locator('[data-agent-action="restore-scene"][data-scene-id="45"]'))
        expect(second_garden).to_have_js_property('naturalWidth', 960)
        check(replacement.count() == 0 and page.evaluate('listeningRoom.id===45'),
              prefix + 'both versions remain available and the original canvas can still be reopened')
        page.evaluate('imageBuildFixture.restore()')
        page.evaluate('''window.controlsScene={canvas_kind:'custom',track_id:'',title:'Audio fixture',
          html:'<div class="fixture-transport"><div class="sf-host-playback"></div></div>',
          css:'.fixture-transport{position:absolute;left:14px;top:100px;padding:0;width:145px}' +
              '.sf-host-playback input{width:75px}'};
          audio.pause();st.track=null;audio.removeAttribute('src');audio.load();scenePlaybackActions.length=0;
          SFPublicAgentCanvas.showScene(controlsScene);scrollTo(0,0)''')
        check(frame.locator('.sf-host-playback, .fixture-transport, button, input').count() == 0 and
              backdrop.get_attribute('sandbox') == '' and page.evaluate('audio.paused&&!audio.hasAttribute("src")'),
              prefix + 'legacy playback slot and its empty wrapper disappear without loading or autoplaying audio')
        page.evaluate('SFPublicAgentCanvas.showScene({...controlsScene,player_controls:true},{focus:false})')
        check(frame.locator('.sf-host-playback, .fixture-transport, button, input').count() == 0 and
              backdrop.get_attribute('sandbox') == '' and not coding.is_visible() and
              footer_actions.get_by_role('button', name='Code', exact=True).get_attribute('aria-expanded') == 'false',
              prefix + 'saved legacy transport flags no longer inject controls and restored source stays behind Code')
        page.evaluate('play(trackById("other-track"),0,"player")')
        page.wait_for_function('!audio.paused&&audio.currentTime>0&&audio.currentSrc.endsWith("second-fixture-tone.wav")')
        orb = page.locator('.sf-agent-sphere')
        close_scene = footer_actions.get_by_role('button', name='Close canvas', exact=True)
        composer = page.locator('#composer-input')
        composer_mic = page.locator('#voiceButton')
        popup = page.locator('#agentChatPopup')
        reveal_chat = page.locator('#chatFooterRestore')
        dock = page.locator('#sfAgentPlayerDock')
        compact_player = page.locator('.chat-footer > #footerPlayer')
        player_toggle = page.locator('#playerToggle')
        composer.fill('Draft before hiding')
        page.evaluate('''document.querySelector('#agentChatPopup').hidden=false;
          document.querySelector('#sfAgentPlayerDock').classList.add('is-collapsed');
          window.playerBeforeHide={node:footerPlayer,audio,src:audio.currentSrc};
          st.conversationOn=true;window.voiceClicksBeforeHide=voiceClicks;SFPublicAgentCanvas.syncVoice();
          SFPublicAgentCanvas.showScene({...scene,canvas_kind:'custom',agent_design:'hidden'},{focus:false});
          document.querySelector('[data-agent-action="start-chat"]').click();''')
        check(not orb.is_visible() and not history_button.is_visible() and not composer.is_visible() and
              not popup.is_visible() and not reveal_chat.is_visible() and not voice.is_visible() and
              not page.locator('#menuAgentChat').is_visible() and not page.locator('.sf-agent-live-feed').is_visible() and
              not coding.is_visible() and coding.get_attribute('data-state') == 'ready' and
              coding.locator('.sf-agent-coding-html').text_content() == page.evaluate('scene.html') and
              footer_actions.get_by_role('button', name='Code', exact=True).get_attribute('aria-expanded') == 'false' and
              close_scene.is_visible() and not play_source.is_visible() and
              compact_player.is_visible() and not dock.is_visible() and
              page.evaluate('document.activeElement.id==="sfAgentSceneTitle" && !audio.paused && st.conversationOn &&'
                            'voiceStops===0 && voiceClicks===voiceClicksBeforeHide'),
              prefix + 'saved hidden-agent design keeps source behind Code and preserves playback and active voice')
        compact_box = compact_player.bounding_box()
        compact_title = compact_player.locator('#playerTitle')
        compact_duration = compact_player.locator('#playerDuration')
        check(page.locator('#footerPlayer').count() == 1 and page.locator('#persistentAudio').count() == 1 and
              compact_box and 44 <= compact_box['height'] <= 64 and compact_box['x'] >= 0 and
              compact_box['x'] + compact_box['width'] <= width + 1 and
              compact_title.is_visible() and compact_title.inner_text() == 'Another song' and
              compact_duration.is_visible() and compact_duration.inner_text() == '0:12' and
              player_toggle.bounding_box()['height'] >= 44 and
              not compact_player.locator('#playerPrev').is_visible() and
              page.evaluate('footerPlayer===playerBeforeHide.node&&audio===playerBeforeHide.audio&&'
                            'audio.currentSrc===playerBeforeHide.src'),
              prefix + 'hidden scene puts one thin player with title, duration and play control in the footer')
        expect(player_toggle).to_have_accessible_name('Pause')
        activate(player_toggle)
        expect(player_toggle).to_have_accessible_name('Play')
        check(page.evaluate('audio.paused'), prefix + 'compact player pauses the same audio element')
        activate(player_toggle)
        expect(player_toggle).to_have_accessible_name('Pause')
        check(page.evaluate('!audio.paused&&audio===playerBeforeHide.audio'),
              prefix + 'compact player resumes playback on the same audio element')
        activate(footer_actions.get_by_role('button', name='Code', exact=True))
        expect(coding).to_be_visible()
        activate(coding.get_by_role('button', name='Hide Coding Agent'))
        check(not coding.is_visible() and not orb.is_visible() and canvas_stage.is_visible() and
              page.evaluate('!audio.paused'),
              prefix + 'Coding Agent can be dismissed independently while the agent is hidden')
        page.evaluate('document.querySelector(".wordmark").focus()')
        page.keyboard.press('Escape')
        check(not canvas_stage.is_visible() and orb.is_visible() and not voice.is_visible() and composer.is_visible() and
              popup.is_visible() and composer.input_value() == 'Draft before hiding' and
              not compact_player.count() and dock.locator('#footerPlayer').count() == 1 and
              page.evaluate('st.conversationOn && voiceStops===0 && voiceClicks===voiceClicksBeforeHide'),
              prefix + 'Escape restores the previous chat and draft while voice continues')
        page.evaluate('''document.querySelector('#agentChatPopup').hidden=true;
          document.body.classList.add('sf-agent-chatbar-collapsed');voicePlaybackPending=true;
          SFPublicAgentCanvas.showScene({...scene,canvas_kind:'custom',agent_design:'hidden'});''')
        check(not reveal_chat.is_visible() and not composer.is_visible() and compact_player.is_visible() and
              page.evaluate('voicePlaybackPending&&st.conversationOn&&!audio.paused'),
              prefix + 'hidden scene leaves compact playback visible and voice active with the chat collapsed')
        page.evaluate('SFPublicAgentCanvas.showScene({...scene,canvas_kind:"custom",agent_design:"square"})')
        check(orb.is_visible() and orb.evaluate('node=>getComputedStyle(node).borderRadius==="0px"') and
              not history_button.is_visible() and not page.locator('.sf-agent-ring').is_visible() and
              canvas_stage.is_visible() and close_scene.is_visible() and reveal_chat.is_visible(),
              prefix + 'square agent replaces the orb shape and restores the collapsed chat preference')
        page.evaluate('document.body.classList.remove("sf-agent-chatbar-collapsed")')
        activate(close_scene)
        check(orb.is_visible() and history_button.is_visible() and
              orb.evaluate('node=>getComputedStyle(node).borderRadius==="50%" && node===document.activeElement') and
              not page.locator('.sf-agent-landing[data-scene-agent-design]').count(),
              prefix + 'closing the canvas restores the orb, navigation and focus')
        show_agent = page.locator('#menuShowAgent')
        menu_button = page.locator('#menuButton')
        page.evaluate('''window.visibilityBefore={posts:posts.length,gets:gets.length,voiceStops,src:audio.currentSrc};
          st.conversationOn=true;SFPublicAgentCanvas.syncVoice()''')
        composer.fill('/hide-agent')
        composer.press('Enter')
        check(not orb.is_visible() and composer.is_visible() and not composer_mic.is_visible() and not voice.is_visible() and
              not canvas_stage.is_visible() and mesh.is_visible() and show_agent.get_attribute('hidden') is None and
              compact_player.is_visible() and not dock.is_visible() and
              page.evaluate('document.activeElement.id==="composer-input"&&'
                            'st.conversationOn&&voiceStops===visibilityBefore.voiceStops&&'
                            '!audio.paused&&audio.currentSrc===visibilityBefore.src'),
              prefix + 'typed /hide-agent keeps the chat bar focused, music playing and voice active')
        check(compact_player.evaluate('node=>node===node.parentElement.firstElementChild&&'
                                      'node.getBoundingClientRect().bottom<='
                                      'document.querySelector(".chat-composer-shell").getBoundingClientRect().top') and
              page.evaluate('footerPlayer===playerBeforeHide.node&&audio===playerBeforeHide.audio'),
              prefix + 'manually hidden agent places the existing player above the visible chat composer')
        page.evaluate('document.body.classList.add("sf-agent-chatbar-collapsed")')
        page.wait_for_function('parseFloat(getComputedStyle(document.documentElement).getPropertyValue("--sf-agent-dock-bottom"))'
                               '===Math.ceil(chatFooter.getBoundingClientRect().height)+18')
        check(compact_player.is_visible() and not composer.is_visible() and reveal_chat.is_visible() and
              reveal_chat.evaluate('node=>node.getBoundingClientRect().bottom<='
                                   'document.querySelector(".chat-footer > #footerPlayer").getBoundingClientRect().top') and
              page.evaluate('!audio.paused'),
              prefix + 'manually hidden agent keeps compact playback and the restore button with collapsed chat')
        page.evaluate('document.body.classList.remove("sf-agent-chatbar-collapsed")')
        page.evaluate('''SFPublicAgentCanvas.setAtmosphere('mesh');SFPublicAgentCanvas.unmount()''')
        page.evaluate('renderAgentCanvas()')
        check(mesh.is_visible() and composer.is_visible() and not orb.is_visible(),
              prefix + 'manual hiding keeps chat available after a different atmosphere and a canvas remount')
        page.evaluate('''SFPublicAgentCanvas.showScene({...scene,canvas_kind:'custom'});
          window.visibilityFrame=document.querySelector('.sf-agent-scene-backdrop iframe')''')
        expect(backdrop).to_be_visible()
        check(not orb.is_visible() and not voice.is_visible() and composer.is_visible(),
              prefix + 'manual hiding keeps chat available on a custom background')
        composer.fill('/show-agent')
        composer.press('Enter')
        check(orb.is_visible() and not voice.is_visible() and composer.is_visible() and composer_mic.is_visible() and
              not compact_player.count() and dock.locator('#footerPlayer').count() == 1 and
              backdrop.evaluate('node=>node===window.visibilityFrame') and
              page.evaluate('document.activeElement.id==="composer-input"&&st.conversationOn&&!audio.paused'),
              prefix + 'typing /show-agent in the visible chat bar restores the agent on the same canvas')
        composer.fill('/hide-agent')
        composer.press('Enter')
        activate(menu_button)
        activate(show_agent)
        check(orb.is_visible() and not voice.is_visible() and composer.is_visible() and
              backdrop.evaluate('node=>node===window.visibilityFrame') and show_agent.get_attribute('hidden') is not None and
              page.evaluate('document.activeElement.id==="menuButton"&&st.conversationOn&&!audio.paused'),
              prefix + 'Show Agent in the site menu works by touch or click and keeps the same canvas and playback')
        composer.fill('/hide-agent')
        composer.press('Enter')
        page.keyboard.press('Escape')
        check(orb.is_visible() and composer.is_visible() and backdrop.evaluate('node=>node===window.visibilityFrame') and
              page.evaluate('posts.length===visibilityBefore.posts&&gets.length===visibilityBefore.gets&&'
                            'st.conversationOn&&voiceStops===visibilityBefore.voiceStops'),
              prefix + 'Escape reveals a manually hidden agent without closing the canvas or making a request')
        page.evaluate('''window.visibleScene=scene;window.scene={...scene,agent_design:'hidden',canvas_kind:'custom'};
          handleListeningRoomCommand({kind:'restore',id:scene.id})''')
        check(not orb.is_visible() and not composer.is_visible() and canvas_stage.is_visible(),
              prefix + 'opening a saved canvas still applies its built-in hide-agent design')
        page.evaluate('''window.visibilityFrame=document.querySelector('.sf-agent-scene-backdrop iframe');
          input.value='/show-agent';form.requestSubmit()''')
        check(orb.is_visible() and not voice.is_visible() and composer.is_visible() and
              backdrop.evaluate('node=>node===window.visibilityFrame') and
              page.evaluate('selectedCanvasId("4")===scene.id&&posts.length===visibilityBefore.posts&&'
                            'gets.length===visibilityBefore.gets+1&&st.conversationOn'),
              prefix + '/show-agent reveals a saved hidden-agent canvas without editing or rebuilding it')
        page.evaluate('handleListeningRoomCommand({kind:"restore",id:scene.id})')
        check(not composer.is_visible() and not orb.is_visible() and canvas_stage.is_visible(),
              prefix + 'reopening the saved canvas honors its own hidden design again')
        activate(close_scene)
        page.evaluate('window.scene=visibleScene')
        page.evaluate('audio.pause()')
        page.evaluate('''SFPublicAgentCanvas.showScene({...scene,agent_design:'hidden'});SFPublicAgentCanvas.showSceneHistory([]);
          st.auth={authenticated:false,user:null};SFPublicAgentCanvas.syncSceneAccess()''')
        check(not canvas_stage.is_visible() and not coding.is_visible() and not rail.is_visible() and not history_button.is_visible() and
              orb.is_visible() and composer.is_visible() and not page.locator('.sf-agent-landing[data-scene-agent-design]').count(),
              prefix + 'auth loss closes room and history')
        page.evaluate('''st.auth={authenticated:true,user:{id:4}};listeningRoom=scene;
          SFPublicAgentCanvas.syncSceneAccess();
          document.querySelector('#composer-input').focus();SFPublicAgentCanvas.showScene(scene,{focus:false})''')
        check(canvas_stage.is_visible() and page.evaluate('document.activeElement.id==="composer-input"'),
              prefix + 'restoring a saved backdrop can leave focus in the composer')
        page.evaluate('listeningRoomOwner="4";selectCanvasId("4",scene.id)')
        history_button.click()
        rail.get_by_role('button', name='Delete <b>Saved room</b>').click()
        rail.get_by_role('button', name='Delete canvas', exact=True).click()
        page.wait_for_function('typeof releaseCanvasDelete==="function"')
        page.evaluate('releaseCanvasDelete();delete window.releaseCanvasDelete')
        expect(rail.get_by_text('No saved canvases yet.')).to_be_visible()
        check(not canvas_stage.is_visible() and page.evaluate('!listeningRoom&&!selectedCanvasId("4")'),
              prefix + 'deleting the active canvas clears its backdrop and saved selection')
        page.evaluate('SFPublicAgentCanvas.closeSceneHistory();listeningRoom=scene')
        page.evaluate('SFPublicAgentCanvas.showScene({...scene,agent_design:"hidden"});SFPublicAgentCanvas.unmount()')
        check(page.locator('#footer #footerPlayer').count() == 1 and page.locator('#footer #voiceTranscript').count() == 1 and
              page.locator('.sf-agent-mesh, .sf-agent-modal-layer, .sf-agent-scene-backdrop iframe, .sf-agent-scene-footer-actions').count() == 0 and
              page.locator('#footer .social-links a').count() == 4 and
              composer.is_visible() and page.evaluate('listeningRoom===scene') and not errors,
              prefix + 'unmount restores original shell nodes without clearing the selected canvas')
        page.evaluate('''document.body.classList.remove('sf-agent-canvas-active');document.body.classList.add('has-player');
          play(trackById('track-1'),0,'player')''')
        page.wait_for_function('!audio.paused')
        composer.fill('/hide-agent')
        composer.press('Enter')
        check(composer.is_visible() and page.locator('#footer #footerPlayer').is_visible() and
              show_agent.get_attribute('hidden') is None and
              page.evaluate('document.activeElement.id==="composer-input"&&!audio.paused'),
              prefix + 'the commands leave chat focused and the player available outside the main canvas')
        activate(menu_button)
        activate(show_agent)
        check(composer.is_visible() and page.evaluate('!audio.paused') and not errors,
              prefix + 'the site menu also restores the agent outside the main canvas')

        # Mount a fresh player because Chromium can retain currentSrc after clearing a used element.
        page.evaluate('''audio.pause();
          const emptyAudio=document.createElement('audio');emptyAudio.id=audio.id;audio.replaceWith(emptyAudio);window.audio=emptyAudio;
          footerPlayer.hidden=true;
          st.track=null;st.playing=false;
          window.tracklessPosts=[];window.tracklessPlayCount=played.length;
          window.tracklessModelReads=modelReads.length;
          secureJsonPost=async(path,body,unused,signal)=>{
            if(path!=='agent-scene.php'||!(signal instanceof AbortSignal))throw Error('Unexpected trackless build request');
            if(body.action==='image_preflight')return {ok:true,preflight:{requested_count:null}};
            tracklessPosts.push(body);
            const index=tracklessPosts.length;
            if(window.rejectNextCodingModel){
              window.rejectNextCodingModel=false;
              const message='That coding model is no longer available.';
              throw Object.assign(new Error(message),{status:422,payload:{error:'scene_coding_model_unavailable',message}});
            }
            if(body.canvas_kind==='space_eq')return {ok:true,scene:{id:100+index,canvas_kind:'space_eq',track_id:'',
              title:'Space EQ'},active_tokens_used:0,design_source:'standard'};
            return {ok:true,scene:{id:100+index,canvas_kind:'custom',track_id:'',title:'Garden canvas',
              html:'<article><h1>Garden '+index+'</h1></article>',css:'article{color:linen}'},
              active_tokens_used:12,design_source:'model'};
          };
          renderAgentCanvas()''')
        for index, prompt in enumerate(('Build a moonlit garden', 'Let’s build a moonlit garden'), start=1):
            composer.fill(prompt)
            composer.press('Enter')
            frame.get_by_role('heading', name=f'Garden {index}').wait_for()
            state = page.evaluate('''() => ({posts:tracklessPosts,track:st.track,paused:audio.paused,
              source:audio.currentSrc,playCount:played.length-tracklessPlayCount})''')
            check(len(state['posts']) == index and all(
                body['canvas_kind'] == 'custom' and body['track_id'] == '' and 'context_track_id' not in body and
                'coding_model' not in body and body['brief'] == 'a moonlit garden' for body in state['posts']) and
                state['track'] is None and state['paused'] and not state['source'] and state['playCount'] == 0 and not errors,
                  prefix + ('natural composer request builds with an empty player' if index == 1 else
                            'legacy build shortcut still works with an empty player'))
        check(page.evaluate('modelReads.length===tracklessModelReads'),
              prefix + 'default builds need no model discovery or override')

        model_picker = page.locator('#menuCodingModel')
        model_group = page.get_by_role('group', name='Coding Model', exact=True)
        model_status = page.locator('#menuCodingModelStatus')
        model_current = page.locator('#menuCodingModelCurrent')
        default_choice = model_group.get_by_role('radio', name='Use default')
        openai_choice = model_group.get_by_role('radio', name='OpenAI · gpt-6-luna')
        claude_choice = model_group.get_by_role('radio', name='Claude · claude-sonnet-4-6')

        def choose_model(name):
            # Click or tap the actual option, including the label's full hit area.
            activate(model_group.locator('label').filter(has_text=name))
            expect(model_group.get_by_role('radio', name=name)).to_be_checked()
            expect(model_current).to_have_text(name)

        def open_coding_models():
            previous_reads = page.evaluate('modelReads.length')
            activate(menu_button)
            expect(menu_button).to_have_attribute('aria-expanded', 'true')
            if model_group.is_hidden():
                activate(model_picker)
            expect(model_group).to_be_visible()
            expect(default_choice).to_be_enabled()
            assert page.evaluate('previous=>modelReads.length===previous', previous_reads), prefix + 'opening the menu refetched preloaded models'

        open_coding_models()
        check(model_group.get_by_role('radio').count() == 3 and default_choice.is_checked() and
              openai_choice.is_visible() and claude_choice.is_visible() and page.evaluate('modelReads.length===1'),
              prefix + 'signed-in menu immediately shows the default and the preloaded coding models')
        choose_model('Claude · claude-sonnet-4-6')
        check(page.evaluate('menu.open') and model_group.is_visible(),
              prefix + 'choosing a coding model keeps the account menu and options open')
        claude_choice.focus()
        page.keyboard.press('ArrowLeft')
        expect(openai_choice).to_be_checked()
        page.keyboard.press('ArrowRight')
        expect(claude_choice).to_be_checked()
        check(model_current.inner_text() == 'Claude · claude-sonnet-4-6' and page.evaluate('menu.open'),
              prefix + 'arrow keys select models without closing the account menu')
        page.keyboard.press('Escape')
        check(page.evaluate('menu.open') and model_group.is_hidden() and
              page.evaluate('document.activeElement.id==="menuCodingModel"'),
              prefix + 'first Escape closes only the model choices and restores focus')
        page.keyboard.press('Escape')
        check(not page.evaluate('menu.open') and page.evaluate('document.activeElement.id==="menuButton"'),
              prefix + 'second Escape closes the account menu and restores focus')
        composer.fill('Build a comet diagram')
        composer.press('Enter')
        frame.get_by_role('heading', name='Garden 3').wait_for()
        check(page.evaluate('''tracklessPosts[2].coding_model.provider==='anthropic'&&
              tracklessPosts[2].coding_model.model==='claude-sonnet-4-6'&&
              tracklessPosts[2].brief==='a comet diagram' '''),
              prefix + 'canvas generation carries the exact chosen provider and model')

        page.evaluate('''window.chatTurns=[];window.welcomeWaitingForName=false;
          window.findTitle=()=>null;window.directSongListeningCommand=()=>null;
          window.directRandomPlaylistCommand=()=>false;window.liveAgent=async text=>chatTurns.push(text);
          window.beforeOrdinaryChat={models:modelReads.length,builds:tracklessPosts.length};''')
        composer.fill('Who is the artist?')
        composer.press('Enter')
        page.wait_for_function('chatTurns.length===1')
        check(page.evaluate('''chatTurns[0]==='Who is the artist?'&&modelReads.length===beforeOrdinaryChat.models&&
              tracklessPosts.length===beforeOrdinaryChat.builds'''),
              prefix + 'ordinary chat keeps its normal route with a coding model selected')

        page.evaluate('st.auth.user={id:5};syncAuthMenu()')
        page.wait_for_function('modelReads.at(-1)===5&&document.querySelectorAll("#menuCodingModelOptions input").length===3')
        open_coding_models()
        check(default_choice.is_checked() and page.evaluate('modelReads.join(",")==="4,5"'),
              prefix + 'another signed-in account preloads models with its own preference')
        choose_model('OpenAI · gpt-6-luna')
        page.evaluate('closeMenu();st.auth.user={id:4};syncAuthMenu()')
        page.wait_for_function('modelReads.join(",")==="4,5,4"&&document.querySelectorAll("#menuCodingModelOptions input").length===3')
        open_coding_models()
        check(claude_choice.is_checked() and model_current.inner_text() == 'Claude · claude-sonnet-4-6',
              prefix + 'switching back restores the first account’s exact model before opening choices')
        page.evaluate('closeMenu()')

        composer.fill('Let’s build the Space EQ example')
        composer.press('Enter')
        expect(eq).to_be_visible()
        check(page.evaluate('''tracklessPosts[3].canvas_kind==='space_eq'&&
              !Object.hasOwn(tracklessPosts[3],'coding_model')'''),
              prefix + 'built-in canvas uses no coding model override')

        open_coding_models()
        choose_model('Use default')
        page.evaluate('closeMenu()')
        composer.fill('Create a garden canvas')
        composer.press('Enter')
        frame.get_by_role('heading', name='Garden 5').wait_for()
        check(page.evaluate('!Object.hasOwn(tracklessPosts[4],"coding_model")') and not errors,
              prefix + 'returning to the default removes the override from the next build')

        open_coding_models()
        choose_model('Claude · claude-sonnet-4-6')
        page.evaluate('''closeMenu();window.rejectNextCodingModel=true;
          window.fixtureCodingModels=[{provider:'openai',model:'gpt-'+'long-model-name-'.repeat(7)}]''')
        composer.fill('Build a comet diagram')
        composer.press('Enter')
        page.wait_for_function('modelReads.length===4&&document.querySelectorAll("#menuCodingModelOptions input").length===2')
        open_coding_models()
        check(default_choice.is_checked() and model_current.inner_text() == 'Use default' and
              claude_choice.count() == 0 and 'no longer available' in model_status.inner_text() and
              page.evaluate('tracklessPosts.length===6&&tracklessPosts[5].coding_model.model==="claude-sonnet-4-6"'),
              prefix + 'a removed model refreshes the menu and resets the choice without resending the build')
        check(model_group.evaluate('node=>node.scrollWidth<=node.clientWidth+1') and not errors,
              prefix + 'long model IDs stay within the options panel')
        page.evaluate('''delete window.releaseCodingModels;window.holdCodingModelRead=true;
          st.auth.user={id:6};syncAuthMenu()''')
        page.wait_for_function('typeof window.releaseCodingModels==="function"')
        page.evaluate('''st.auth={authenticated:false,user:null};syncAuthMenu();releaseCodingModels()''')
        check(page.locator('#menuCodingModelField').is_hidden() and
              page.evaluate('preloadedModelSignal.aborted&&modelReads.join(",")==="4,5,4,4,6"&&'
                            'document.querySelectorAll("#menuCodingModelOptions input").length===1'),
              prefix + 'sign-out aborts preload and a late response cannot expose the prior account’s models')
        check(build_ref not in debug_report.input_value() and 'No canvas builds captured' in debug_report.input_value(),
              prefix + 'account changes clear the public debug report')
        page.evaluate('''st.auth={authenticated:true,user:{id:4}};syncAuthMenu();window.holdImageInventory=true;
          SFPublicAgentCanvas.showSceneHistory([])''')
        image_folder.get_by_text('Generated Images', exact=True).click()
        page.wait_for_function('typeof window.releaseImageInventory==="function"')
        page.evaluate('''st.auth={authenticated:true,user:{id:5}};syncAuthMenu();releaseImageInventory()''')
        check(not rail.is_visible() and page.evaluate('imageInventorySignal.aborted') and
              page.locator('.sf-agent-scene-image-folder').count() == 0,
              prefix + 'switching accounts closes the private image folder and aborts its pending inventory request')

        page.evaluate('''window.usePickerInventory=true;
          window.beforePickerPosts=posts.length+tracklessPosts.length;
          window.beforePickerReads=imageInventoryReads.length;''')
        first_draft = "Let's build a gallery with "
        suffix = ' and keep the dark header'
        composer.fill(first_draft + suffix)
        composer.evaluate('(input,at)=>input.setSelectionRange(at,at)', len(first_draft))
        composer.press_sequentially('/images')
        picker = page.get_by_role('dialog', name='Choose saved images')
        expect(picker).to_be_visible()
        first_choice = picker.get_by_role('checkbox', name='<b>Private artwork</b>', exact=True)
        expect(first_choice).to_be_visible()
        check(picker.locator('b').count() == 0 and page.evaluate('''imageInventoryReads.length===beforePickerReads+1&&
          imageInventoryReads.at(-1)==='agent-canvas-image.php'&&posts.length+tracklessPosts.length===beforePickerPosts'''),
              prefix + 'typing /images inside an unsent build draft opens the private picker without sending chat')
        first_choice.check()
        activate(picker.get_by_role('button', name='Load more'))
        blue_choice = picker.get_by_role('checkbox', name='Blue hour', exact=True)
        blue_choice.check()
        expect(first_choice).to_be_checked()
        activate(picker.get_by_role('button', name='Add images'))
        expect(picker).not_to_be_visible()
        expect(composer).to_be_focused()
        c1_ref, c4_ref = ('{image-' + key * 16 + '}' for key in ('c1', 'c4'))
        check(' '.join(composer.input_value().split()) == ' '.join((first_draft + c1_ref + ' ' + c4_ref + suffix).split()) and
              page.evaluate('posts.length+tracklessPosts.length===beforePickerPosts'),
              prefix + 'picking across pages inserts both references at the cursor and preserves the rest of the build draft')
        composer.press('Enter')
        page.wait_for_function('posts.length+tracklessPosts.length===beforePickerPosts+1&&!listeningRoomRequest')
        submitted = page.evaluate('tracklessPosts.at(-1).brief')
        check(c1_ref in submitted and c4_ref in submitted and suffix.strip() in submitted,
              prefix + 'the submitted build receives exactly the selected references and the rest of the draft')
        page.evaluate('beforePickerPosts++')

        edit_draft = '/edit Keep the layout and add '
        composer.fill(edit_draft)
        composer.press('End')
        composer.press_sequentially('/images')
        expect(picker).to_be_visible()
        expect(first_choice).not_to_be_checked()
        activate(picker.get_by_role('button', name='Load more'))
        for title in ('<b>Private artwork</b>', 'Older artwork', 'Evening light', 'Blue hour'):
            picker.get_by_role('checkbox', name=title, exact=True).check()
        expect(picker.get_by_role('checkbox', name='Fifth artwork', exact=True)).to_be_disabled()
        page.keyboard.press('Escape')
        expect(picker).not_to_be_visible()
        expect(composer).to_be_focused()
        check(composer.input_value().startswith(edit_draft) and '{image-' not in composer.input_value() and
              page.evaluate('posts.length+tracklessPosts.length===beforePickerPosts') and not errors,
              prefix + 'an edit draft can choose up to four images and Escape discards the selection without sending it')
        if width in (375, 1440):
            page.evaluate('''() => {
              handleAgentVisibilityCommand('/show-agent');closeMenu();input.value='';
              SFPublicAgentCanvas.closeSceneHistory();
              const originalPost=window.secureJsonPost,originalRead=window.api;
              const fixture=window.imageActionsFixture={calls:[],builds:[],reads:[],
                reference:{id:'c2'.repeat(16),title:'Reference artwork'},
                replacement:{id:'c5'.repeat(16),title:'Saved replacement'}};
              fixture.original={id:400,canvas_kind:'custom',track_id:'',title:'Current artwork',
                html:'<figure class="sf-image-'+('c1'.repeat(16))+'"></figure>',
                css:'figure{position:absolute;left:14px;top:130px;width:150px;margin:0}',
                image_assets:[{id:'c1'.repeat(16),title:'Current image'}]};
              listeningRoom=fixture.original;SFPublicAgentCanvas.showScene(fixture.original);
              fixture.originalFrame=document.querySelector('.sf-agent-scene-backdrop iframe');
              window.api=async(path,body,signal)=>{
                fixture.reads.push(path);
                if(path==='agent-scene.php?request_id='+fixture.requestId)return fixture.review;
                if(path.startsWith('agent-canvas-image.php')){
                  const scope=new URLSearchParams(path.split('?')[1]||'').get('scope');
                  return {ok:true,images:scope==='reference'?[]:[fixture.reference,fixture.replacement],next_cursor:null};
                }
                return originalRead(path,body,signal);
              };
              window.secureJsonPost=async(path,body,unused,signal)=>{
                if(path!=='agent-scene.php'||!(signal instanceof AbortSignal))throw Error('Unexpected image action request');
                fixture.calls.push({...body});
                if(body.action==='image_preflight')return {ok:true,preflight:{requested_count:3,
                  today_remaining:4,daily_cap:5,today_attempts:1,generation_enabled:true}};
                if(body.action==='choose_image_variation'){
                  const asset=fixture.review.generated_images.find(image=>image.id===body.image_id);
                  fixture.chosen={...fixture.original,id:401,previous_id:400,title:'Chosen artwork',
                    html:'<figure class="sf-image-'+asset.id+'"></figure>',image_assets:[asset]};
                  return {ok:true,state:'ready',scene:fixture.chosen,design_source:'image-choice'};
                }
                if(body.action==='swap_image'){
                  fixture.swapped={...fixture.chosen,id:402,previous_id:401,title:'Swapped artwork',
                    html:fixture.chosen.html.replaceAll(body.image_id,body.replacement_id),
                    image_assets:[fixture.replacement]};
                  return {ok:true,state:'ready',scene:fixture.swapped,design_source:'image-swap'};
                }
                fixture.builds.push(body);fixture.requestId=body.request_id;
                await new Promise(resolve=>fixture.releaseGeneration=resolve);
                fixture.review={ok:true,state:'review',generated_images:[
                  {id:body.request_id,title:'Variation one',index:1},
                  {id:'c4'.repeat(16),title:'Variation two',index:2}],image_total:2};
                return fixture.review;
              };
              fixture.restore=()=>{window.api=originalRead;window.secureJsonPost=originalPost;};
            }''')
            expect(frame.get_by_alt_text('Current image', exact=True)).to_have_js_property('naturalWidth', 1)
            composer.fill('/edit Create 3 variations of this artwork using /images')
            expect(picker).to_be_visible()
            activate(picker.get_by_role('button', name='Use as visual reference', exact=True))
            picker.get_by_role('checkbox', name='Reference artwork', exact=True).check()
            activate(picker.get_by_role('button', name='Add references', exact=True))
            check('{reference-image-' + 'c2' * 16 + '}' in composer.input_value(),
                  prefix + 'saved artwork can be selected as an explicit visual reference for a revision')
            composer.press('Enter')
            generation_confirm = page.get_by_role('dialog', name='Create canvas images', exact=True)
            expect(generation_confirm).to_be_visible()
            count_choice = generation_confirm.get_by_label('Number of images', exact=True)
            expect(count_choice).to_have_value('3')
            check(count_choice.locator('option').count() == 4 and
                  generation_confirm.get_by_text('4 image attempts remaining today.', exact=False).is_visible() and
                  page.evaluate('imageActionsFixture.builds.length===0&&listeningRoom.id===400&&'
                                'document.querySelector(".sf-agent-scene-backdrop iframe")===imageActionsFixture.originalFrame'),
                  prefix + 'a known three-image request confirms the count and available quota before any generation')
            count_choice.select_option('2')
            generation_confirm.get_by_role('checkbox', name='Choose one variation before applying', exact=True).check()
            activate(generation_confirm.get_by_role('button', name='Continue', exact=True))
            page.wait_for_function('typeof imageActionsFixture.releaseGeneration==="function"')
            approved = page.evaluate('imageActionsFixture.builds[0]')
            check(approved['image_count'] == 2 and approved['review_images'] is True and
                  approved['image_reference_ids'] == ['c2' * 16] and approved['previous_id'] == 400,
                  prefix + 'the approved count, comparison choice and selected visual references reach the build payload')
            sidebar_batch_image_urls.add(SITE + 'api/agent-canvas-image.php?id=' + approved['request_id'])
            page.evaluate('imageActionsFixture.releaseGeneration()')
            variation_review = page.get_by_role('dialog', name='Choose an image for your canvas', exact=True)
            expect(variation_review).to_be_visible()
            expect(variation_review.get_by_role('radio').nth(1)).to_be_enabled()
            check(page.evaluate('listeningRoom.id===400&&document.querySelector(".sf-agent-scene-backdrop iframe")==='
                                'imageActionsFixture.originalFrame') and
                  variation_review.get_by_role('button', name='Use selected image').is_disabled(),
                  prefix + 'completed variations open for comparison while the previous canvas stays active')
            activate(variation_review.get_by_role('button', name='Cancel', exact=True))
            page.wait_for_function('!listeningRoomRequest')
            check(page.evaluate('listeningRoom.id===400&&pendingCanvasImageReview(canvasOwner())==='
                                'imageActionsFixture.requestId&&imageActionsFixture.builds.length===1'),
                  prefix + 'dismissing comparison preserves the old canvas and a recoverable image review')
            composer.fill('/review-images')
            composer.press('Enter')
            expect(variation_review).to_be_visible()
            variation_review.get_by_role('radio').nth(1).check()
            activate(variation_review.get_by_role('button', name='Use selected image', exact=True))
            page.wait_for_function('!listeningRoomRequest&&listeningRoom.id===401')
            expect(frame.get_by_alt_text('Variation two', exact=True)).to_have_js_property('naturalWidth', 1)
            check(page.evaluate('''() => {
                  const fixture=imageActionsFixture,choices=fixture.calls.filter(call=>call.action==='choose_image_variation');
                  return choices.length===1&&choices[0].request_id===fixture.requestId&&choices[0].image_id==='c4'.repeat(16)&&
                    fixture.reads.includes('agent-scene.php?request_id='+fixture.requestId)&&
                    pendingCanvasImageReview(canvasOwner())===null&&fixture.builds.length===1;
                }'''), prefix + '/review-images recovers the saved choices and applies one through the selection endpoint')
            activate(footer_actions.locator('[data-agent-action="edit-scene-images"]'))
            activate(footer_actions.get_by_role('button', name='Replace Variation two', exact=True))
            replace_review = page.get_by_role('dialog', name='Replace this canvas image', exact=True)
            expect(replace_review).to_be_visible()
            replace_review.get_by_role('radio').nth(1).check()
            activate(replace_review.get_by_role('button', name='Use selected image', exact=True))
            page.wait_for_function('!listeningRoomRequest&&listeningRoom.id===402')
            expect(frame.get_by_alt_text('Saved replacement', exact=True)).to_have_js_property('naturalWidth', 1)
            check(page.evaluate('''() => {
                  const fixture=imageActionsFixture,swaps=fixture.calls.filter(call=>call.action==='swap_image');
                  return swaps.length===1&&swaps[0].previous_id===401&&swaps[0].image_id==='c4'.repeat(16)&&
                    swaps[0].replacement_id===fixture.replacement.id&&fixture.builds.length===1&&
                    fixture.calls.filter(call=>call.action==='image_preflight').length===1&&listeningRoom.previous_id===401;
                }''') and not errors,
                  prefix + 'the replace-image control swaps a saved image without another generation request')
            page.evaluate('imageActionsFixture.restore()')
        context.close()
    browser.close()

print(json.dumps({'checks': len(checks), 'browser': 'chromium', 'widths': widths,
                  'scope': 'Production stage and command wiring with synthetic API responses'}))
