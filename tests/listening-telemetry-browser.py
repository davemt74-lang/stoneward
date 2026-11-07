"""Real native MP3 events through production app play/footer/SDK callbacks.

Disposable PHP host, actual protocol2 endpoint and canonical analytics; isolated
host harness rather than full application/physical-device acceptance.
"""
import base64,importlib.util,json,os,shutil,signal,socket,subprocess,sys,tempfile,time
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1];sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('audio_fixture',ROOT/'tests/canvas-audio-integration-browser.py');fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
BROWSER=os.environ.get('SF_AUDIO_BROWSER','chromium')
if BROWSER not in ('chromium','webkit'):raise ValueError('SF_AUDIO_BROWSER must be chromium or webkit')
PHP=os.environ.get('SF_TEST_PHP','php');APP=(ROOT/'assets/js/app.js').read_text();checks=[]
def block(a,b):return APP[APP.index(a):APP.index(b,APP.index(a))]
def check(value,label):
 assert value,label
 checks.append(label);print('PASS '+label,flush=True)
PROGRAM="""window.canvasClicks=0;window.canvasPointerDowns=0;
const play=document.createElement('button');play.textContent='Play synchronized song';document.querySelector('.controls').append(play);
play.addEventListener('pointerdown',()=>window.canvasPointerDowns++);
play.onclick=()=>{window.canvasClicks++;document.querySelector('p').textContent='starting';
StonefellowAudio.play('song-2').then(()=>{document.querySelector('p').textContent='playing';})
.catch(error=>{document.querySelector('p').textContent='failed '+error.code;});};
document.querySelector('.controls').dataset.audioReady='true';"""
SETUP='''window.audio=document.getElementById('canonical');window.footerPlayer=document.getElementById('player');window.st={auth:{authenticated:false,user:null,csrf:''},track:null,playing:false,playlistPlayback:{}};
window.cfg={apiBase:'api'};window.pendingNetworkRecovery=null;window.playbackWantsAudio=false;window.songCaptureSkill=null;window.songListeningSkill=null;
window.playbackLifecycle=StonefellowPlaybackLifecycle.create();window.listeningRoom=null;window.fixtureOwner='';window.audioDiagnostics=[];window.sceneLoaded=false;
window.canvasOwner=()=>fixtureOwner;window.canvasSceneOwner=()=>fixtureOwner;window.isGalleryScene=()=>false;
window.trackById=id=>fixtureTracks.find(track=>track.id===id);window.refreshSiteData=async()=>{};window.requirePublishedCanvas=async()=>{};
window.playerVolume=()=>audio.volume;window.setPlayerVolume=value=>{audio.volume=value;return value;};window.savedPlaybackPosition=()=>audio.currentTime||0;
window.cancelNetworkRecovery=()=>{pendingNetworkRecovery=null;};window.clearPlaybackBufferWatch=()=>{};window.setPlaybackStatus=()=>{};window.clearPlaylistPlayback=()=>{};window.updateQueueUi=()=>{};window.updateFooterPlayer=()=>{};window.rememberPlaybackPosition=()=>{};window.agent=()=>{};window.playbackProblem=()=>{};window.invalidatePlayback=()=>{};
window.login=async()=>{const initial=await fetch('api/auth.php').then(r=>r.json());const response=await fetch('api/auth.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':initial.csrf},body:JSON.stringify({action:'login',email:'owner@example.invalid',password:'fixture-only-password'})});const data=await response.json();st.auth={authenticated:true,user:data.user,csrf:data.csrf};fixtureOwner=String(data.user.id);SFListeningTelemetry.setOwner();};
'''
with tempfile.TemporaryDirectory(prefix='stoneward-native-listening-') as temporary:
 site=Path(temporary);fixture.seed(site)
 saved=json.loads(fixture.cli(site,"""
 require_once SF_ROOT.'/api/agent-scene-core.php';sf_privacy_ensure_schema();sf_ops_ensure_schema();
 $scene=['canvas_kind'=>'custom','track_id'=>'','title'=>'Observed song controls','agent_design'=>'hidden','html'=>'<article><div class="controls"></div><p>ready</p></article>','css'=>'article{padding:100px 20px}button{min-height:44px;background:#233;color:white}p{color:white}',
 'js'=>base64_decode('"""+base64.b64encode(PROGRAM.encode()).decode()+"""'),'music_inputs'=>['mode'=>'all']];
 $saved=sf_agent_scene_history_save(1,$scene);echo json_encode(sf_agent_scene_saved(1,$saved['id']));
 """))
 tracks=[{'id':'song-'+str(i),'title':'Song '+str(i),'duration':6,'audio':'api/canvas-audio.php?action=source&track_id=song-'+str(i)} for i in [1,2,3]]
 page_html='''<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="assets/css/public-agent-canvas.css"><style>body{background:#101821;color:white;margin:0}#actions{position:fixed;top:0;left:0;z-index:5000;padding:8px;background:#111}#actions button{min-height:44px}#target{min-height:820px}</style><div id="actions"><button id="main">Main play</button><button id="footer">Footer pause/resume</button><button id="next">Normal next song</button><button id="bad">Unavailable song</button></div><div id="target"></div><div id="player"><audio id="canonical"></audio></div>'''
 page_html+='<script>window.fixtureTracks='+json.dumps(tracks)+';window.fixtureScene='+json.dumps(saved).replace('<','\\u003c')+';</script>'
 for script in ['player-playback-lifecycle.js','listening-telemetry.js','canvas-audio-sdk.js','public-agent-canvas.js']:page_html+='<script src="assets/js/'+script+'"></script>'
 api_start=APP.index('  async function api(');api_source=APP[api_start:APP.index('\n',api_start)]
 page_html+='<script>'+SETUP+api_source+block('  function ensureListenSession(){','  function fmtWhen(')+block('  function canvasAudioState(){','  async function renderAgentCanvas(){')+block('  function playbackEndedEarly(){','  function rememberPlaybackPosition(){')+block('  function play(t,','  function seekPlayback(')+'''
 listeningTelemetryMain();
 document.getElementById('main').onclick=()=>play(fixtureTracks[0]);document.getElementById('footer').onclick=toggle;document.getElementById('next').onclick=()=>play(fixtureTracks[2]);document.getElementById('bad').onclick=()=>play({id:'song-3',title:'Unavailable',duration:6,audio:'missing.mp3'});
 window.mountScene=()=>{sceneLoaded=false;listeningRoom=fixtureScene;SFPublicAgentCanvas.mount(document.getElementById('target'),{audio,player:footerPlayer,getSceneOwner:canvasSceneOwner,canUseScenes:()=>!!fixtureOwner,getTrackId:()=>st.track?.id,
 onCanvasAudioCatalog:loadCanvasAudioCatalog,onCanvasAudioSource:loadCanvasAudioSource,onCanvasAudioPlay:playCanvasAudio,onCanvasAudioPause:pauseCanvasAudio,onCanvasAudioSeek:seekCanvasAudio,onCanvasAudioVolume:setCanvasAudioVolume,onCanvasAudioState:canvasAudioState,onCanvasAudioDiagnostic:detail=>audioDiagnostics.push(detail)});SFPublicAgentCanvas.showScene(fixtureScene,{focus:false});
 document.querySelector('.sf-agent-scene-backdrop iframe').addEventListener('load',()=>{sceneLoaded=true;},{once:true});};
 </script>'''
 (site/'telemetry.html').write_text(page_html)
 with socket.socket() as reservation:reservation.bind(('127.0.0.1',0));port=reservation.getsockname()[1]
 base='http://127.0.0.1:'+str(port)
 with (site/'php.log').open('w+') as log:
  server=subprocess.Popen([PHP,'-d','session.save_path='+str(site/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(site)],stdout=log,stderr=log,start_new_session=True)
  try:
   for _ in range(100):
    try:
     with socket.create_connection(('127.0.0.1',port),timeout=.1):break
    except OSError:time.sleep(.02)
   with sync_playwright() as p:
    launch={'headless':True}
    if BROWSER=='chromium':
     # Chromium's new headless mode can drop native pointer input in OOPIFs.
     if os.environ.get('SF_AUDIO_HEADED') == '1':
      launch['headless'] = False
     launch['args']=['--no-sandbox','--autoplay-policy=no-user-gesture-required']
     executable=os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
     if executable:launch['executable_path']=executable
    browser=getattr(p,BROWSER).launch(**launch)
    for width in [390,1440]:
     context=browser.new_context(viewport={'width':width,'height':850},has_touch=width<500);page=context.new_page();reports=[];errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
     def capture(request):
      if '/api/listening.php' in request.url and request.method=='POST':reports.append(request.post_data_json)
     def activate(control):
      control.tap() if width<500 else control.click()
     page.on('request',capture);page.goto(base+'/telemetry.html');page.wait_for_function('typeof listeningTelemetryMain.current?.prepare === "function"')
     activate(page.locator('#main'));page.wait_for_function('audio.currentTime>.4&&!audio.paused');activate(page.locator('#footer'));page.wait_for_function('audio.paused');page.wait_for_timeout(80)
     starts=[row for row in reports if row.get('action')=='begin'];check(len(starts)==1 and starts[0]['context']=={'app':True} and starts[0]['event_type']=='start',f'{BROWSER}/{width}px actual guest main audio creates one canonical start')
     activate(page.locator('#footer'));page.wait_for_function('!audio.paused');page.wait_for_function('audio.ended',timeout=12000);page.wait_for_timeout(100)
     check(len([row for row in reports if row.get('action')=='begin'])==1 and len([row for row in reports if row.get('event_type')=='resume'])==1 and len([row for row in reports if row.get('event_type')=='complete'])==1,f'{BROWSER}/{width}px footer resume shares play identity and natural end reports one complete')
     check(all(row.get('protocol')==2 for row in reports),f'{BROWSER}/{width}px no legacy writer runs alongside native protocol2 observer')
     page.evaluate('login()');page.evaluate('mountScene()');frame=page.frame_locator('.sf-agent-scene-backdrop iframe')
     # A parsed button can exist before the srcdoc document is loaded and the
     # authenticated SDK session has completed its handshake. Interact only
     # after both production readiness events, using native input throughout.
     page.wait_for_function('sceneLoaded&&audioDiagnostics.some(detail=>detail.event==="session_ready")')
     expect(frame.locator('.controls')).to_have_attribute('data-audio-ready','true')
     expect(frame.locator('p')).to_have_text('ready')
     try:
      with page.expect_request(lambda request: '/api/listening.php' in request.url and request.method=='POST' and request.post_data_json.get('action')=='begin' and request.post_data_json.get('track_id')=='song-2'):
       activate(frame.get_by_role('button',name='Play synchronized song'))
       expect(frame.locator('p')).to_have_text('playing',timeout=5000)
     except BaseException:
      print('Native canvas playback failure',json.dumps({'browser':BROWSER,'width':width,
       'parent':page.evaluate('({sceneLoaded,owner:fixtureOwner,track:st.track?.id,paused:audio.paused,ended:audio.ended,mediaError:audio.error?.code,diagnostics:audioDiagnostics})'),
       'canvas':frame.locator('p').text_content(),
       'events':page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame().evaluate('()=>({clicks:window.canvasClicks,pointerdowns:window.canvasPointerDowns,ready:document.readyState})'),
       'reports':[{key:row.get(key) for key in ['action','event_type','track_id']} for row in reports],'errors':errors}),flush=True)
      raise
     page.wait_for_function('st.track?.id==="song-2"&&!audio.paused')
     sdk=[row for row in reports if row.get('action')=='begin' and row.get('track_id')=='song-2'];check(len(sdk)==1 and sdk[0]['context']=={'scene_id':saved['id']} and sdk[0]['playback_method']=='main',f'{BROWSER}/{width}px production sandbox SDK uses canonical player with captured canvas attribution')
     activate(frame.get_by_role('button',name='Play synchronized song'));page.wait_for_timeout(70);check(len([row for row in reports if row.get('action')=='begin' and row.get('track_id')=='song-2'])==1,f'{BROWSER}/{width}px repeated synchronized play request does not duplicate counts')
     page.evaluate('audio.currentTime=5.4');page.wait_for_function('audio.ended',timeout=5000);page.wait_for_timeout(100)
     completed=[row for row in reports if row.get('event_type')=='complete' and row.get('play_id')==sdk[0]['play_id']];check(len(completed)==1 and completed[0]['elapsed_played_seconds']<2,f'{BROWSER}/{width}px actual seek skips media without adding seek distance to listening seconds')
     # Begin reporting awaits the authorized host response. Native playback can
     # start before that asynchronous POST; observe the report rather than a delay.
     with page.expect_request(lambda request: '/api/listening.php' in request.url and request.method=='POST' and request.post_data_json.get('action')=='begin' and request.post_data_json.get('track_id')=='song-3'):
      activate(page.locator('#next'))
      page.wait_for_function('st.track?.id==="song-3"&&!audio.paused')
     normal=[row for row in reports if row.get('action')=='begin' and row.get('track_id')=='song-3'];check(len(normal)==1 and normal[0]['context']=={'app':True},f'{BROWSER}/{width}px subsequent normal app play resets initiation context')
     activate(page.locator('#footer'));page.wait_for_timeout(50);before=len([row for row in reports if row.get('action')=='begin']);activate(page.locator('#bad'));page.wait_for_timeout(250)
     check(len([row for row in reports if row.get('action')=='begin'])==before,f'{BROWSER}/{width}px failed native play adds no play start')
     check(not errors,f'{BROWSER}/{width}px native production playback/telemetry harness has no browser exceptions: '+str(errors))
     context.close()
    browser.close()
   stats=json.loads(fixture.cli(site,"echo json_encode(sf_db()->query(\"SELECT event_type,COUNT(*) AS total FROM listening_events GROUP BY event_type\")->fetchAll(PDO::FETCH_ASSOC));"))
   check(any(row['event_type']=='complete' and int(row['total'])>=4 for row in stats),'Actual protocol reports contribute completions to the canonical listening_events totals')
  finally:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
print(str(len(checks))+' native playback listening checks passed')
