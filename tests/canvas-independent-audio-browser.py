"""Native host Audio + production sandbox/telemetry; no paid calls or authored claims.

Serve real loopback HTTP so browser Blob loading is never request-intercepted.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
import shutil
import subprocess
import tempfile

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
BROWSER = os.environ.get('SF_AUDIO_BROWSER', 'chromium')
if BROWSER not in ('chromium', 'webkit'):
    raise ValueError('SF_AUDIO_BROWSER must be chromium or webkit')
PROGRAM = r'''(async()=>{
 const api=StonefellowAudio, controls=document.querySelector('.buttons'), status=document.querySelector('.status');
 let player=null;
 const add=(label,run)=>{const button=document.createElement('button');button.textContent=label;button.onclick=async()=>{
  try{await run();status.textContent=label+' done';}catch(error){status.textContent=error.code||error.name;}};controls.append(button);};
 add('Get raw',async()=>{const asset=await api.getAudio('song-one');window.rawAsset=asset;
  window.rawAssetInfo={type:asset.blob.type,size:asset.blob.size};window.rawAudio=new Audio(asset.url);});
 add('Decode raw',async()=>{const context=new AudioContext();try{
  const bytes=await rawAsset.blob.arrayBuffer();window.rawAssetInfo.prefix=Array.from(new Uint8Array(bytes).slice(0,16));
  const decoded=await context.decodeAudioData(bytes);window.rawDecodedDuration=decoded.duration;
 }finally{await context.close();}});
 add('Play raw',async()=>{await rawAudio.play();});
 add('Pause raw',async()=>{rawAudio.pause();});
 add('Create independent',async()=>{
  player=await api.createPlayer({track_id:'song-one',id:'song-one'});window.player=player;window.loopWraps=0;
  player.subscribe(state=>{
   if(state.playing&&state.loop&&window.independentState?.currentTime-state.currentTime>.2)window.loopWraps++;
   window.independentState=state;
  });
 });
 add('Play independent',async()=>{await player.play();});
 add('Pause independent',async()=>{await player.pause();});
 add('Set practice loop',async()=>{await player.setLoop(.5,1.3);await player.setPlaybackRate(1.5);});
 add('Clear practice loop',async()=>{await player.clearLoop();});
 add('Finish independent',async()=>{await player.seek(5.4);await player.play();});
 add('Dispose independent',async()=>{await player.dispose();});
 add('Play synchronized',async()=>{await api.play('song-one');});
 add('Pause synchronized',async()=>{await api.pause();});
 status.textContent='ready';
})();'''

SETUP = r'''({program,hash})=>{
 window.owner='1';window.allowed=true;window.catalogAllowed=true;window.reports=[];window.diagnostics=[];
 window.sourceReads=0;window.catalogReads=0;window.observed=[];window.revoked=[];window.sceneLoaded=false;
 const revoke=URL.revokeObjectURL.bind(URL);URL.revokeObjectURL=url=>{revoked.push(url);return revoke(url);};
 window.canonical=document.querySelector('audio');window.canonicalCalls=0;
 window.track={track_id:'song-one',id:'song-one',title:'Synthetic audio',duration_seconds:6,source_available:true,release_ids:[]};
 SFListeningTelemetry.create({getOwner:()=>owner,readProtocol:async()=>({ok:true,user_id:Number(owner),csrf:'synthetic-csrf',session_token:'a'.repeat(32),privacy_epoch:0}),
  post:async body=>{reports.push(structuredClone(body));return {ok:true};},onDiagnostic:detail=>diagnostics.push(detail),retryDelayMs:0});
 window.mainObserver=SFListeningTelemetry.observe(canonical,{getTrack:()=>track,getContext:()=>({scene_id:71}),method:'main'});
 window.fixture={id:71,canvas_kind:'custom',title:'Arbitrary independent controls',agent_design:'hidden',
  html:'<article class="test"><div class="buttons"></div><p class="status">Loading</p></article>',
  css:'.test{padding:20px}button{display:block;margin:4px;padding:7px}.buttons{position:relative;z-index:2}',js:program,js_hash:hash};
 SFPublicAgentCanvas.mount(document.querySelector('#target'),{audio:canonical,player:document.querySelector('#player'),
  getSceneOwner:()=>owner,canUseScenes:()=>allowed,getTrackId:()=>track.track_id,
  onCanvasAudioCatalog:async filters=>{catalogReads++;return {tracks:catalogAllowed?[track]:[],releases:[]};},
  onCanvasAudioSource:async(id,signal)=>{sourceReads++;const response=await fetch('/fixture.mp3',{signal});return {track,blob:await response.blob()};},
  onCanvasAudioPlay:async()=>{canonicalCalls++;if(!canonical.src)canonical.src='/fixture.mp3';await canonical.play();},
  onCanvasAudioPause:()=>canonical.pause(),onCanvasAudioSeek:seconds=>{canonical.currentTime=seconds;},onCanvasAudioVolume:value=>{canonical.volume=value;},
  onCanvasAudioState:()=>({trackId:track.track_id,title:track.title}),
  onCanvasIndependentAudioObserve:(media,item,context,settings)=>{
   observed.push({media,context,settings});
   return SFListeningTelemetry.observe(media,{getTrack:()=>item,getContext:()=>context,method:'independent',...settings});
  },onCanvasAudioDiagnostic:detail=>diagnostics.push(detail)});
 window.show=()=>{sceneLoaded=false;const shown=SFPublicAgentCanvas.showScene(fixture,{focus:false});
  document.querySelector('.sf-agent-scene-backdrop iframe').addEventListener('load',()=>{sceneLoaded=true;},{once:true});return shown;};window.show();
}'''

checks = 0

def check(value, label):
    global checks
    assert value, label
    checks += 1
    print('PASS', label, flush=True)


def run(page,mp3_bytes):
    page.evaluate(SETUP, {'program': PROGRAM, 'hash': base64.b64encode(hashlib.sha256(PROGRAM.encode()).digest()).decode()})
    frame = page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame()
    expect(frame.locator('.status')).to_have_text('ready')
    page.wait_for_function('sceneLoaded&&diagnostics.some(detail=>detail.event==="session_ready")')

    def click(label, result=None):
        control=frame.get_by_role('button', name=label, exact=True)
        control.tap() if page.viewport_size['width']<500 else control.click()
        expect(frame.locator('.status')).to_have_text(result or label+' done')

    click('Get raw')
    asset=frame.evaluate('()=>rawAssetInfo')
    check(asset=={'type':'audio/mpeg','size':len(mp3_bytes)},
          'authorized MP3 size and explicit MPEG MIME survive the opaque sandbox handoff')
    # Exercise the original Blob URL before reading/materializing its bytes or
    # opening an AudioContext, so decoding cannot conceal a media URL failure.
    click('Play raw')
    frame.wait_for_function('()=>rawAudio.currentTime>.35')
    click('Pause raw')
    click('Decode raw')
    check(frame.evaluate('()=>rawDecodedDuration>5&&rawDecodedDuration<7') and frame.evaluate('()=>rawAssetInfo.prefix')==list(mp3_bytes[:16]),
          'opaque sandbox can read and decode the unchanged MP3 bytes for custom audio processing')
    check(page.evaluate('reports.length===0&&observed.length===0'), 'raw authorized MP3 playback/decoding has no host listening claims')
    click('Create independent')
    check(page.evaluate('observed.length===1&&reports.length===0&&observed[0].media.paused'), 'host metadata preload creates no play/start telemetry')
    check(page.evaluate('Object.isFrozen(observed[0].context)&&observed[0].context.scene_id===71'), 'independent observer receives immutable scene context from the renderer')
    click('Play independent')
    page.wait_for_function('reports.filter(row=>row.action==="begin").length===1')
    frame.wait_for_function('()=>independentState.currentTime>.4')
    click('Pause independent')
    page.wait_for_function('reports.some(row=>row.event_type==="pause")')
    report = page.evaluate('reports')
    check(report[0]['playback_method']=='independent' and report[0]['context']=={'scene_id':71} and report[0]['track_id']=='song-one', 'native independent playing begins exactly one attributed play')
    check(any(row.get('event_type')=='pause' and row.get('elapsed_played_seconds',0)>.25 for row in report), 'native progress measures actual played time before pause')
    click('Play independent')
    click('Finish independent')
    page.wait_for_function('reports.some(row=>row.event_type==="complete")')
    report = page.evaluate('reports')
    check(sum(row['action']=='begin' for row in report)==1 and len({row['play_id'] for row in report})==1, 'resume, seek and native end retain the same independent play identity')
    check(page.evaluate('canonicalCalls===0&&canonical.paused'), 'independent controls never start or manipulate the synchronized main player')
    check(any(row.get('event_type')=='complete' and row['elapsed_played_seconds']<2 for row in report), 'seeking near the end cannot invent the skipped listening time')
    click('Dispose independent')
    check(page.evaluate('observed[0].media.paused&&!observed[0].media.getAttribute("src")&&revoked.length===1'), 'explicit disposal stops the host element and releases its Blob source')
    click('Play synchronized')
    page.wait_for_function('reports.filter(row=>row.action==="begin").length===2')
    click('Pause synchronized')
    click('Play synchronized')
    click('Pause synchronized')
    check(page.evaluate('reports.filter(row=>row.action==="begin").length===2&&reports.filter(row=>row.action==="begin")[1].playback_method==="main"'), 'existing main/footer/SDK synchronization records a single canonical play')
    click('Create independent')
    page.evaluate('catalogAllowed=false')
    click('Play independent', 'track_unavailable')
    check(page.evaluate('observed[1].media.paused&&reports.filter(row=>row.action==="begin").length===2'), 'a withdrawn selected track cannot start an existing independent handle')
    page.evaluate('catalogAllowed=true')
    click('Play independent')
    page.wait_for_function('reports.filter(row=>row.action==="begin").length===3')
    # Collapsing UI controls must not tear down the audio controller.
    page.evaluate('document.querySelector("#target").classList.add("is-orb-ui-collapsed")')
    frame.wait_for_function('()=>independentState.currentTime>.25')
    check(page.evaluate('!observed[1].media.paused'), 'hiding agent controls leaves native independent playback active')
    # Replacing a canvas disposes both playing and paused independent instances.
    old = page.evaluate('observed.length')
    page.evaluate('show()')
    check(page.evaluate('(count)=>observed.slice(0,count).every(item=>item.media.paused&&!item.media.getAttribute("src"))', old), 'canvas replacement releases independent media from the prior session')
    frame = page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame()
    expect(frame.locator('.status')).to_have_text('ready')
    page.wait_for_function('sceneLoaded')
    click('Create independent')
    frame.wait_for_function('()=>independentState.seekable')
    click('Set practice loop')
    report_offset = page.evaluate('reports.length')
    click('Play independent')
    frame.wait_for_function('()=>loopWraps>=2&&independentState.playing&&independentState.playbackRate===1.5')
    check(page.evaluate('canonical.paused&&observed.at(-1).media.playbackRate===1.5'),
          'the independent practice range repeats at native 1.5× speed without starting the main player')
    check(frame.evaluate('()=>independentState.preservesPitch'), 'practice playback enables native pitch preservation')
    click('Pause independent')
    page.wait_for_function('(offset)=>reports.slice(offset).some(row=>row.event_type==="pause")', arg=report_offset)
    check(page.evaluate('(offset)=>reports.slice(offset).some(row=>row.event_type==="seek")&&reports.slice(offset).every(row=>row.event_type!=="complete")', report_offset),
          'range wraps are observed as seeks without reporting a track completion')
    click('Clear practice loop')
    check(frame.evaluate('()=>independentState.loop===null'), 'clearing a paused practice loop restores unrestricted playback')
    click('Play independent')
    page.evaluate('owner="2";SFPublicAgentCanvas.syncPlayer()')
    check(page.evaluate('observed.at(-1).media.paused&&!observed.at(-1).media.getAttribute("src")'), 'account change revokes old-session host audio and its source')
    check(page.evaluate('diagnostics.every(row=>!JSON.stringify(row).includes("blob:"))'), 'copyable diagnostics never expose host source URLs')


class AudioFixtureHTTP(BaseHTTPRequestHandler):
    sources={}

    def log_message(self,*args):
        pass

    def do_GET(self):
        value=self.sources.get(self.path)
        if value is None:
            self.send_error(404)
            return
        mime,body=value
        if isinstance(body,str):body=body.encode()
        self.send_response(200)
        self.send_header('Content-Type',mime)
        self.send_header('Content-Length',str(len(body)))
        self.send_header('Cache-Control','no-store')
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if self.path!='/autoplay-result':
            self.send_error(404)
            return
        # This result is observed by Playwright's native request listener before
        # the test sends any script evaluation or user input to the document.
        self.rfile.read(int(self.headers.get('Content-Length','0')))
        self.send_response(200)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length','2')
        self.end_headers()
        self.wfile.write(b'{}')


with tempfile.TemporaryDirectory(prefix='stonefellow-independent-audio-') as temp:
    mp3 = Path(temp)/'native.mp3'
    subprocess.run(['ffmpeg','-hide_banner','-loglevel','error','-f','lavfi','-i','sine=frequency=440:duration=6','-codec:a','libmp3lame','-q:a','6',str(mp3)],check=True)
    mp3_bytes=mp3.read_bytes()
    AudioFixtureHTTP.sources={'/fixture.mp3':('audio/mpeg',mp3_bytes),
        '/independent/':('text/html','<!doctype html><html><body><audio></audio><div id="player"></div><div id="target"></div></body></html>')}
    server=ThreadingHTTPServer(('127.0.0.1',0),AudioFixtureHTTP)
    thread=Thread(target=server.serve_forever,daemon=True);thread.start()
    base='http://127.0.0.1:'+str(server.server_port)
    site=base+'/independent/'
    try:
      with sync_playwright() as pw:
        launch = {'headless':True}
        chrome = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
        if BROWSER=='chromium':
            launch['args']=['--no-sandbox','--autoplay-policy=no-user-gesture-required']
            if chrome: launch['executable_path']=chrome
        browser = getattr(pw,BROWSER).launch(**launch)
        for width in (375, 1280):
            context = browser.new_context(viewport={'width':width,'height':850},reduced_motion='reduce',has_touch=width<500)
            page = context.new_page()
            page.set_default_timeout(8000)
            errors=[]
            page.on('pageerror',lambda error: errors.append(str(error)))
            page.goto(site)
            for name in ('assets/css/site.css','assets/css/public-agent-canvas.css'):
                page.add_style_tag(content=(ROOT/name).read_text())
            for name in ('assets/js/canvas-audio-sdk.js','assets/js/listening-telemetry.js','assets/js/public-agent-canvas.js'):
                page.add_script_tag(content=(ROOT/name).read_text())
            try: run(page,mp3_bytes)
            except BaseException:
                print(page.evaluate('({reports,diagnostics,observed:observed.map(row=>({paused:row.media.paused,time:row.media.currentTime,active:row.settings.isActive(),context:row.context})),errors:[]})'))
                print('Raw asset',page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame().evaluate('''async()=>{
                  const result={info:window.rawAssetInfo,decoded:window.rawDecodedDuration,mediaError:window.rawAudio?.error?.code};
                  if(window.rawAsset){const context=new AudioContext();try{const bytes=await rawAsset.blob.arrayBuffer();
                    result.prefix=Array.from(new Uint8Array(bytes).slice(0,16));result.decodeProbe=(await context.decodeAudioData(bytes)).duration;
                  }catch(error){result.decodeError=error.name;}finally{await context.close();}}return result;
                }'''))
                raise
            check(not errors, BROWSER+'/'+str(width)+' production sandbox runs without uncaught errors: '+str(errors))
            context.close()
        browser.close()
        # Normal browser policy: a genuine no-gesture call must fail without creating a play.
        normal={'headless':True}
        if BROWSER=='chromium':
            normal['args']=['--no-sandbox']
            if chrome: normal['executable_path']=chrome
        browser=getattr(pw,BROWSER).launch(**normal)
        context=browser.new_context(viewport={'width':375,'height':850},reduced_motion='reduce',has_touch=True)
        page=context.new_page()
        auto_program=PROGRAM+r"""
(async()=>{
 const player=await StonefellowAudio.createPlayer('song-one');
 try{await player.play();parent.postMessage({independentAutoplay:{ok:true}},'*');}
 catch(error){parent.postMessage({independentAutoplay:{ok:false,code:error.code}},'*');}
 const button=document.createElement('button');button.textContent='User play';
 button.onclick=async()=>{try{await player.play();document.querySelector('.status').textContent='user played';}
 catch(error){document.querySelector('.status').textContent=error.code;}};document.querySelector('.buttons').append(button);
})();"""
        # The document reports its own result over a fixture-only request. Even a
        # Playwright DOM read can mark browser activation, so observe network only
        # until the native no-gesture attempt has settled.
        initial="""addEventListener('message',event=>{
          if(!event.data?.independentAutoplay)return;
          const result={...event.data.independentAutoplay,activation:!!navigator.userActivation?.hasBeenActive,
            begins:reports.filter(row=>row.action==='begin').length,paused:observed[0].media.paused};
          fetch('/autoplay-result',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(result)}).catch(()=>{});
        });("""+SETUP+")("+json.dumps({'program':auto_program,'hash':base64.b64encode(hashlib.sha256(auto_program.encode()).digest()).decode()})+");"
        sources={'/sdk.js':(ROOT/'assets/js/canvas-audio-sdk.js').read_text(),'/telemetry.js':(ROOT/'assets/js/listening-telemetry.js').read_text(),
                 '/renderer.js':(ROOT/'assets/js/public-agent-canvas.js').read_text(),'/init.js':initial}
        AudioFixtureHTTP.sources.update({name:('text/javascript',source) for name,source in sources.items()})
        AudioFixtureHTTP.sources['/autoplay/']=('text/html','<!doctype html><html><body><audio></audio><div id="player"></div><div id="target"></div>'+''.join('<script src="'+name+'"></script>' for name in sources)+'</body></html>')
        with page.expect_request(lambda request:request.url==base+'/autoplay-result',timeout=8000) as attempt:
            page.goto(base+'/autoplay/')
        result=attempt.value.post_data_json
        check(result=={'ok':False,'code':'audio_gesture_required','activation':False,'begins':0,'paused':True},
              'normal '+BROWSER+' policy rejects independent autoplay without any gesture or verified play: '+json.dumps(result))
        frame=page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame()
        frame.get_by_role('button',name='User play',exact=True).tap()
        expect(frame.locator('.status')).to_have_text('user played')
        check(page.evaluate('reports.filter(row=>row.action==="begin").length===1&&!observed[0].media.paused'),
              'a real user tap starts independent host playback after native autoplay rejection')
        context.close();browser.close()
    finally:
        server.shutdown();server.server_close();thread.join(timeout=5)
print('PASS',checks,'native independent/synchronized audio checks; no paid calls')
