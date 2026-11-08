"""Real MP3 routes and the production sandbox, SDK and app audio callbacks in Chromium."""
import base64
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import sys
import urllib.error
import urllib.request

HTTP_ONLY='--http-only' in sys.argv
AUTOPLAY_ONLY='--autoplay-only' in sys.argv
MUSIC_ID_ONLY='--music-id-only' in sys.argv
MATTER_ONLY='--matter-only' in sys.argv
MEYDA_ONLY='--meyda-only' in sys.argv
if not HTTP_ONLY:
    from playwright.sync_api import expect, sync_playwright

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('SF_TEST_PHP','php')
CHROME=os.environ.get('CHROMIUM_BIN')
APP=(ROOT/'assets/js/app.js').read_text()
CALLBACKS=APP[APP.index('  function canvasAudioState(){'):APP.index('  async function renderAgentCanvas(){')]
checks=0


class HTTPResponse:
    def __init__(self,response):
        with response:
            self.status=response.status;self.headers={key.lower():value for key,value in response.headers.items()};self.data=response.read()
    def body(self):return self.data
    def json(self):return json.loads(self.data)


class HTTPClient:
    def __init__(self,base):
        self.base=base
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(self,method,path,headers=None,data=None):
        if data is not None:
            data=json.dumps(data).encode();headers={'Content-Type':'application/json',**(headers or {})}
        request=urllib.request.Request(self.base+path,method=method,headers=headers or {},data=data)
        try:response=self.opener.open(request,timeout=5)
        except urllib.error.HTTPError as error:response=error
        return HTTPResponse(response)
    def get(self,path,headers=None):return self.request('GET',path,headers)
    def head(self,path):return self.request('HEAD',path)
    def post(self,path,data=None,headers=None):return self.request('POST',path,headers,data)


def check(value,label):
    global checks
    assert value,label
    checks+=1
    print('PASS',label,flush=True)


def cli(site,source):
    result=subprocess.run([PHP,'-r',"define('SF_ROOT',$argv[1]);require SF_ROOT.'/api/bootstrap.php';"+source,str(site)],capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    return result.stdout


CODE=r'''(async()=>{
 const api=window.StonefellowAudio;
 const literal='<!--<script></script> 音楽';
 const out=document.querySelector('.output');
 const catalog=await api.catalog({release_id:'album-one'});
 let blocked=false;try{await fetch('https://outside.invalid/network-probe')}catch(error){blocked=true}
 window.canvasResult={ids:catalog.tracks.map(track=>track.track_id),literal,blocked};
 const longTrack=await api.getTrack('l'.repeat(180));window.canvasResult.longId=longTrack.track_id;
 const item=await api.getTrack('song-1');
 const asset=await api.getAudio(item.track_id);
 const context=new AudioContext();
 const decoded=await context.decodeAudioData(await asset.blob.arrayBuffer());
 await context.close();
 const local=new Audio(asset.url);local.preload='auto';local.load();
 window.localAudio=local;
 window.canvasResult={...window.canvasResult,bytes:asset.blob.size,mime:asset.blob.type,url:asset.url,decoded:decoded.duration};
 api.subscribe(state=>{window.lastAudioState=state;document.querySelector('.state').textContent=JSON.stringify(state)});
 for(const [label,run] of [['Play',()=>api.play('song-1')],['Pause',()=>api.pause()],['Seek',()=>api.seek(2)],['Volume',()=>api.setVolume(.25)],
  ['Local audio',async()=>{await local.play();local.pause();document.querySelector('.local').textContent='played'}],
  ['Host URL',async()=>{const detached=new Audio('api/canvas-audio.php?action=source&track_id=song-1&private_marker=canvas-private-source');
    window.detachedHostAudio=detached;await detached.play().catch(()=>{});document.querySelector('.local').textContent='blocked host reference'}],
  ['Release',async()=>{await api.releaseAudio('song-1');document.querySelector('.local').textContent='released'}]]){
  const button=document.createElement('button');button.textContent=label;
  button.onclick=async()=>{try{await run()}catch(error){out.textContent=error.message;}};document.querySelector('.controls').append(button);
 }
 let tick=0;setInterval(()=>{document.querySelector('.ticks').textContent=String(++tick)},40);
 out.textContent='ready';
})().catch(error=>{document.querySelector('.output').textContent='error '+error.message});'''


SETUP=r'''
window.audio=document.querySelector('#canonical');
window.st={auth:{authenticated:true,user:{id:1}},track:null,playing:false};
window.listeningRoom={id:1};window.fixtureOwner='1';window.playCalls=[];window.builderCalls=0;window.held=[];
window.audioDiagnostics=[];window.audioDebugEvents=[];
window.recordCanvasDebug=(id,phase,detail)=>audioDebugEvents.push({id,phase,detail});
window.cfg={apiBase:'api'};
window.catalog=window.fixtureTracks;
window.trackById=id=>catalog.find(track=>track.id===id);
window.canvasOwner=()=>fixtureOwner;
// This fixture mounts an owned private canvas, not a gallery entry.
window.canvasSceneOwner=()=>fixtureOwner;window.isGalleryScene=()=>false;window.requirePublishedCanvas=async()=>{};
window.playerVolume=()=>audio.volume;
window.savedPlaybackPosition=()=>audio.currentTime||0;
window.refreshSiteData=async()=>{};
window.setPlayerVolume=value=>{audio.volume=value;return value};
window.seekPlayback=fraction=>{audio.currentTime=audio.duration*fraction;return true};
window.toggle=()=>{if(audio.paused)return audio.play();audio.pause()};
window.play=async(track,position,source,retried,onFailure)=>{
 playCalls.push(track.id);st.track=track;audio.src=track.audio;audio.load();
 try{await audio.play();if(position)audio.currentTime=position;st.playing=true;return true}
 catch(error){onFailure?.(error);return false}
};
window.fixtureTrackDataset=(id,trackId)=>({id,dataset_track_id:trackId,datasets:{published_catalog:{fields:{track_id:'text',title:'text'},filters:['track_id'],rows:[],current_rows:[{track_id:trackId,title:'Loaded '+trackId}]}}});
window.api=async(path,body,signal)=>{
 if(window.holdNextCatalog&&path.startsWith('canvas-audio.php?')){
  window.holdNextCatalog=false;
  await new Promise(resolve=>{held.push({resolve,signal});});
 }
 const response=await fetch('api/'+path,{signal,credentials:'same-origin',cache:'no-store'});
 const result=await response.json();if(!response.ok||!result.ok)throw new Error('API rejected request');return result;
};
'''


MOUNT=r'''
window.mountFixture=()=>SFPublicAgentCanvas.mount(document.querySelector('#target'),{
 audio,player:document.querySelector('#canonicalPlayer'),getSceneOwner:canvasOwner,canUseScenes:()=>st.auth.authenticated,
 onSceneTrackData:async(id,trackId)=>fixtureTrackDataset(id,trackId),
 getTrackId:()=>st.track?.id,getPlayerState:()=>({track:st.track,playing:!audio.paused}),
 onCanvasAudioCatalog:loadCanvasAudioCatalog,onCanvasAudioSource:loadCanvasAudioSource,onCanvasAudioPlay:playCanvasAudio,
 onCanvasAudioPause:pauseCanvasAudio,onCanvasAudioSeek:seekCanvasAudio,onCanvasAudioVolume:setCanvasAudioVolume,onCanvasAudioState:canvasAudioState,
 onCanvasAudioDiagnostic:detail=>{audioDiagnostics.push(detail);recordCanvasAudioDiagnostic(detail)},
 onOpenRecordBuilder:()=>builderCalls++
});
mountFixture();
window.showFixture=(scene=fixtureScene)=>{listeningRoom=scene;return SFPublicAgentCanvas.showScene(scene,{focus:false})};
if(!new URLSearchParams(location.search).has('music'))showFixture();
'''


def seed(site):
    for folder in ('api','assets/js','assets/css','config'):
        shutil.copytree(ROOT/folder,site/folder,dirs_exist_ok=True)
    for folder in ('storage','data','sessions'):(site/folder).mkdir(parents=True,exist_ok=True)
    db=site/'fixture.sqlite'
    (site/'config/database.php').write_text("<?php return ['driver'=>'sqlite','path'=>'"+str(db)+"'];")
    (site/'data/shows.json').write_text('[]')
    (site/'assets/audio').mkdir()
    mp3=site/'assets/audio/fixture.mp3'
    subprocess.run(['ffmpeg','-v','error','-f','lavfi','-i','sine=frequency=320:sample_rate=22050','-t','6','-ac','1','-b:a','24k',str(mp3)],check=True)
    tracks=[{'id':'song-'+str(index),'title':'Song '+str(index),'duration':6,'state':'published','release_id':'album-one','audio':'assets/audio/fixture.mp3'} for index in range(1,13)]
    tracks += [{'id':'l'*180,'title':'Long catalog identifier','state':'published','audio':'assets/audio/fixture.mp3'}]
    tracks += [{'id':'bulk-'+str(index),'title':'Bulk song '+str(index),'duration':6,'state':'published','release_id':'album-bulk','audio':'assets/audio/fixture.mp3'} for index in range(1,131)]
    tracks += [
        {'id':'hidden-song','title':'Hidden','state':'private','audio':'assets/audio/fixture.mp3'},
        {'id':'hidden-title-song','title':'Title-only hidden album song','state':'published','release':'Secret album','audio':'assets/audio/fixture.mp3'},
        {'id':'hidden-album-song','title':'Hidden album song','state':'published','release_id':'hidden-album','audio':'assets/audio/fixture.mp3'},
        {'id':'remote-song','title':'Remote song','state':'published','audio':'https://example.invalid/remote.mp3'},
        {'id':'master-song','title':'Master song','state':'published','audio':'assets/audio/master.mp3','private_master_audio':'assets/audio/master.mp3'},
    ]
    shutil.copy2(mp3,site/'assets/audio/master.mp3')
    releases=[{'id':'album-one','title':'Public album','state':'published','track_ids':['song-2','song-1']+['song-'+str(index) for index in range(3,13)]},
              {'id':'album-bulk','title':'Full catalog album','state':'published','track_ids':['bulk-'+str(index) for index in range(1,131)]},
              {'id':'hidden-album','title':'Secret album','state':'draft','track_ids':['hidden-album-song']}]
    (site/'data/catalog.json').write_text(json.dumps(tracks));(site/'data/releases.json').write_text(json.dumps(releases))
    with sqlite3.connect(db) as connection:
        connection.execute('CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)')
        connection.execute('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)')
        connection.execute("INSERT INTO users VALUES(1,'owner@example.invalid','Owner','','user','active','now','now',NULL)")
        connection.execute("INSERT INTO users VALUES(2,'other@example.invalid','Other','','user','active','now','now',NULL)")
    cli(site,"sf_customer_lifecycle_ensure_schema();sf_db()->prepare('UPDATE users SET password_hash=?')->execute([password_hash('fixture-only-password',PASSWORD_DEFAULT)]);")
    managed=json.loads(cli(site,"sf_media_ensure_schema();@mkdir(SF_ROOT.'/storage/media',0700,true);copy(SF_ROOT.'/assets/audio/fixture.mp3',SF_ROOT.'/storage/media/fixture.mp3');$asset=sf_media_register_existing_file('storage/media/fixture.mp3','fixture.mp3',1);sf_media_attach((int)$asset['id'],'track','managed-song','primary_audio',['public_visible'=>true]);echo json_encode(['id'=>(int)$asset['id'],'uuid'=>$asset['uuid']]);"))
    tracks.append({'id':'managed-song','title':'Managed song','state':'published','audio':'api/media.php?id='+managed['uuid']})
    (site/'data/catalog.json').write_text(json.dumps(tracks));(site/'managed.json').write_text(json.dumps(managed))
    scene={'canvas_kind':'custom','track_id':'','title':'Audio project','html':'<section class="controls"></section><p class="output"></p><p class="state"></p><p class="local"></p><p class="ticks"></p>',
           'css':'main{padding:90px 20px 160px}.controls{max-width:480px}button{min-width:70px;min-height:44px;margin:5px}','js':CODE,'catalog_refs':[],'lyric_section':None}
    encoded=base64.b64encode(json.dumps(scene).encode()).decode()
    saved=json.loads(cli(site,"require_once SF_ROOT.'/api/agent-scene-core.php';$scene=json_decode(base64_decode('"+encoded+"'),true);echo json_encode(sf_agent_scene_history_save(1,$scene));"))
    scene.update(saved)
    fixture_tracks=[{'id':track['id'],'title':track['title'],'duration':track.get('duration',6),'audio':'api/canvas-audio.php?action=source&track_id='+track['id']} for track in tracks if track.get('state')=='published']
    page='''<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/public-agent-canvas.css"><style>body{margin:0;background:#111;color:white}#target{min-height:90vh}.chat-footer{position:fixed;bottom:0;left:0;right:0}.social-links{min-height:45px}</style><div id="target"></div><footer class="chat-footer"><div id="canonicalPlayer"><audio id="canonical"></audio></div><div class="social-links"></div></footer>'''
    page+='<script>window.fixtureScene='+json.dumps(scene).replace('<','\\u003c')+';window.fixtureTracks='+json.dumps(fixture_tracks)+'</script>'
    page+='<script src="assets/js/canvas-library-runtime.js"></script><script src="assets/js/canvas-audio-sdk.js"></script><script src="assets/js/public-agent-canvas.js"></script>'
    page+='<script>'+SETUP+CALLBACKS+MOUNT+'</script>'
    (site/'fixture.html').write_text(page)
    return mp3.read_bytes(),scene


def save_fixture_scene(site,draft):
    draft={key:value for key,value in draft.items() if key not in ('id','previous_id','created_at','js_hash')}
    encoded=base64.b64encode(json.dumps(draft).encode()).decode()
    saved=json.loads(cli(site,"require_once SF_ROOT.'/api/agent-scene-core.php';$scene=json_decode(base64_decode('"+encoded+"'),true);echo json_encode(sf_agent_scene_history_save(1,$scene));"))
    return {**draft,**saved}


def run_http(guest,site,mp3):
    response=guest.get('/api/canvas-audio.php?action=catalog&release_id=album-one');catalog=response.json()
    check(response.status==200 and len(catalog['tracks'])==12 and [track['track_id'] for track in catalog['tracks']][:2]==['song-2','song-1'],'the public audio catalog supplies the complete album in release order')
    check(not any('/storage/' in str(track) or 'assets/audio' in str(track) for track in catalog['tracks']),'catalog metadata exposes authorized wrapper URLs without filesystem or raw source paths')
    endpoint='/api/canvas-audio.php?action=source&track_id=song-1'
    for value,start,end in [('bytes=0-15',0,15),('bytes=64-127',64,127),('bytes=-21',len(mp3)-21,len(mp3)-1)]:
        response=guest.get(endpoint,headers={'Range':value})
        check(response.status==206 and response.body()==mp3[start:end+1] and response.headers['content-type']=='audio/mpeg','an MP3 byte range is exact and retains the MPEG media type')
    head=guest.head(endpoint)
    check(head.status==200 and head.body()==b'' and int(head.headers['content-length'])==len(mp3),'HEAD returns source metadata without MP3 bytes')
    check(guest.get(endpoint,headers={'Range':'bytes='+str(len(mp3))+'-'}).status==416,'an out-of-bounds MP3 range is rejected')
    for track in ('hidden-song','hidden-album-song','hidden-title-song','master-song','remote-song','missing-song'):
        check(guest.get('/api/canvas-audio.php?action=source&track_id='+track).status==404,'unpublished, protected or unsupported song bytes stay unavailable · '+track)
    check(guest.get('/api/canvas-audio.php?action=source&track_id[]=song-1').status==400,'array-shaped source identifiers are rejected')
    managed_endpoint='/api/canvas-audio.php?action=source&track_id=managed-song'
    check(guest.get(managed_endpoint).body()==mp3,'an explicitly published managed MP3 link supplies the authorized bytes')
    managed=json.loads((site/'managed.json').read_text())
    cli(site,"sf_media_ensure_schema();sf_db()->prepare(\"UPDATE media_links SET public_visible=0 WHERE asset_id=? AND entity_type='track' AND entity_id='managed-song' AND role='primary_audio'\")->execute(["+str(managed['id'])+"]);")
    check(guest.get(managed_endpoint).status==404,'revoking a managed primary-audio link immediately revokes its canvas audio URL')
    hidden=guest.get('/api/canvas-audio.php?action=catalog&track_id=managed-song').json()['tracks'][0]
    check(hidden['audio_url'] is None and hidden['source_available'] is False,'source metadata also reflects live managed-audio revocation')



def run_browser(pw,site,base,mp3,scene):
    options={'args':['--no-sandbox','--autoplay-policy=no-user-gesture-required']}
    if CHROME:options['executable_path']=CHROME
    browser=pw.chromium.launch(**options)
    try:
        page=browser.new_page(viewport={'width':1280,'height':950},reduced_motion='reduce')
        errors=[];external=[];page.on('pageerror',lambda error:errors.append(str(error)))
        page.route('https://outside.invalid/**',lambda route:(external.append(route.request.url),route.fulfill(content_type='text/html',body='blocked probe reached transport')))
        page.add_init_script('addEventListener("message",event=>{if(event.data==="navigation-probe")window.navigationProbe=true})')
        login(page,base,'owner@example.invalid');page.goto(base+'/fixture.html')
        page.wait_for_function("Array.from(document.querySelectorAll('iframe')).some(frame=>frame.title==='Custom canvas background')")
        frame=next(frame for frame in page.frames if frame!=page.main_frame)
        expect(frame.locator('.output')).to_have_text('ready',timeout=12000)
        check(frame.evaluate('canvasResult.literal')=='<!--<script></script> 音楽','script tag and comment literals retain exact Unicode source and execute safely')
        check(frame.evaluate('canvasResult.blocked') and external==[],'custom JavaScript cannot send external network fetches')
        check(frame.evaluate('canvasResult.ids.length')==12,'custom JavaScript reads the real catalog through the production SDK')
        check(frame.evaluate('canvasResult.longId')=='l'*180,'the full supported 180-character track ID resolves through the API and SDK')
        check(frame.evaluate('canvasResult.bytes')==len(mp3) and frame.evaluate('canvasResult.url.startsWith("blob:")') and frame.evaluate('canvasResult.decoded')>5,'the sandbox receives an MP3 Blob usable by local Audio and Web Audio decoding')
        expect(page.locator('#sfAgentCodingRail')).to_be_hidden()
        before=int(frame.locator('.ticks').text_content());page.wait_for_timeout(120)
        check(int(frame.locator('.ticks').text_content())>before,'custom code keeps running with the Coding Agent rail closed')
        check(frame.locator('.sf-host-playback').count()==0 and page.locator('.sf-agent-scene-playback').count()==0,'the host injects no default playback widget into the custom design')
        before_local_errors=page.evaluate('audioDiagnostics.filter(row=>/error|violation|rejection/.test(row.event)).length')
        frame.get_by_role('button',name='Local audio',exact=True).click();expect(frame.locator('.local')).to_have_text('played')
        check(page.evaluate('audio.paused && st.track===null'),'local audio processing leaves canonical playback untouched')
        check(page.evaluate('audioDiagnostics.filter(row=>/error|violation|rejection/.test(row.event)).length')==before_local_errors,'valid local MP3 Blob playback reports no false failure diagnostic')
        frame.get_by_role('button',name='Host URL',exact=True).click();expect(frame.locator('.local')).to_have_text('blocked host reference')
        page.wait_for_function('audioDiagnostics.some(row=>row.event==="media_csp_violation")&&audioDiagnostics.some(row=>row.event==="local_playback_error")')
        check(frame.evaluate('!document.querySelector("audio")&&detachedHostAudio instanceof HTMLAudioElement'),'the failing host-reference media is detached and its play rejection is handled by authored code')
        check(page.evaluate('audioDiagnostics.some(row=>row.event==="media_csp_violation"&&row.source_kind==="host_reference")&&audioDiagnostics.some(row=>row.event==="local_playback_error"&&row.source_kind==="host_reference"&&row.name==="NotSupportedError")'),'opaque-frame detached media reports CSP and swallowed local-playback failure classifications')
        check(page.evaluate('audioDebugEvents.some(row=>/^[a-f0-9]{32}$/.test(row.id)&&row.phase==="canvas_audio"&&row.detail.scene_id===1&&row.detail.event==="local_playback_error")'),'real audio diagnostics reach the app helper and debug event sink for the current scene')
        check(page.evaluate('!JSON.stringify({audioDiagnostics,audioDebugEvents}).includes("canvas-private-source")&&!JSON.stringify({audioDiagnostics,audioDebugEvents}).includes("canvas-audio.php")&&audioDiagnostics.every(row=>Object.keys(row).every(key=>["event","method","code","name","media_code","source_kind"].includes(key)))'),'diagnostic events contain allowlisted fields with no source URL, query value or error message')
        frame.get_by_role('button',name='Play',exact=True).click()
        page.wait_for_function('!audio.paused && st.track?.id==="song-1"')
        frame.wait_for_function('() => lastAudioState?.playing===true && lastAudioState.trackId==="song-1"')
        check(page.evaluate('document.querySelectorAll("audio").length===1 && playCalls.join(",")==="song-1"'),'SDK playback uses the same canonical audio element')
        frame.get_by_role('button',name='Seek',exact=True).click();page.wait_for_function('audio.currentTime>=1.9')
        frame.get_by_role('button',name='Volume',exact=True).click();page.wait_for_function('Math.abs(audio.volume-.25)<.001')
        frame.get_by_role('button',name='Pause',exact=True).click();page.wait_for_function('audio.paused')
        frame.wait_for_function('() => lastAudioState?.playing===false && Math.abs(lastAudioState.volume-.25)<.001')
        check(True,'play, pause, seek and volume stay synchronized with SDK state subscriptions')
        frame.get_by_role('button',name='Play',exact=True).click();page.wait_for_function('!audio.paused && Number.isFinite(audio.duration) && audio.duration>1')
        page.evaluate('audio.currentTime=audio.duration-.06')
        frame.wait_for_function('() => lastAudioState?.ended===true && lastAudioState.playing===false')
        check(True,'native end-of-track events reach the same SDK state subscription')
        frame.get_by_role('button',name='Release',exact=True).click();expect(frame.locator('.local')).to_have_text('released')
        check(True,'the custom scene can release its local MP3 object URL')
        page.locator('[data-agent-action="toggle-scene-code"]').click();expect(page.locator('#sfAgentCodingRail')).to_be_visible()
        check(page.locator('.sf-agent-coding-js').text_content()==CODE,'the reopened Code rail shows the exact model JavaScript as text')
        page.locator('[data-agent-action="vinyl-builder"]').click()
        check(page.evaluate('builderCalls')==1,'the vinyl action invokes the native record builder callback')
        page.locator('[data-agent-action="toggle-scene-code"]').click();expect(page.locator('#sfAgentCodingRail')).to_be_hidden()
        prior_plays=page.evaluate('playCalls.length')
        page.evaluate("()=>{holdNextCatalog=true;window.stalePlay=playCanvasAudio('song-2').then(()=> 'played',error=>error.code)}")
        page.wait_for_function('held.length===1')
        page.evaluate("fixtureOwner='2';st.auth.user.id=2;listeningRoom={id:2};SFPublicAgentCanvas.syncSceneAccess();held[0].resolve()")
        page.wait_for_function('!SFPublicAgentCanvas.sceneActive()')
        check(page.evaluate('playCalls.length')==prior_plays and page.evaluate('stalePlay')=='audio_session_closed','an old-owner metadata response cannot start playback in the new scene session')
        check(errors==[],'the normal SDK and canvas flow produces no JavaScript errors')
        page.evaluate("()=>{fixtureOwner='1';st.auth.user.id=1;showFixture()}")
        frame=next(frame for frame in page.frames if frame!=page.main_frame)
        expect(frame.locator('.output')).to_have_text('ready')
        page.evaluate('holdNextCatalog=true')
        frame.evaluate("()=>{window.pendingCatalog=StonefellowAudio.catalog({release_id:'album-one'}).catch(error=>error.code)}")
        page.wait_for_function('held.length===2')
        page.evaluate('scene=>showFixture(scene)',save_fixture_scene(site,scene))
        check(page.evaluate('held[1].signal.aborted'),'replacing a scene aborts its outstanding catalog loader')
        page.evaluate('held[1].resolve()')
        frame=next(frame for frame in page.frames if frame!=page.main_frame)
        expect(frame.locator('.output')).to_have_text('ready')

        dynamic=save_fixture_scene(site,{**scene,'html':scene['html']+'<section class="sf-dataset-published_catalog sf-current-track"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>'})
        page.evaluate("scene=>{const data=fixtureTrackDataset(scene.id,st.track.id);showFixture({...scene,...data});window.dynamicElement=document.querySelector('.sf-agent-scene-backdrop iframe')}",dynamic)
        frame=next(frame for frame in page.frames if frame!=page.main_frame)
        expect(frame.locator('.output')).to_have_text('ready')
        page.evaluate('holdNextCatalog=true')
        frame.evaluate("()=>{window.oldDynamicRequest=StonefellowAudio.catalog().catch(error=>error.code)}")
        page.wait_for_function('held.length===3')
        page.evaluate("()=>{st.track=trackById('song-2');SFPublicAgentCanvas.syncPlayer()}")
        page.wait_for_function('held[2].signal.aborted')
        page.evaluate('held[2].resolve()')
        expect(frame.locator('.sf-current-track .sf-field-title')).to_have_text('Loaded song-2')
        expect(frame.locator('.output')).to_have_text('ready')
        check(page.evaluate("dynamicElement===document.querySelector('.sf-agent-scene-backdrop iframe')"),'current-track data reload preserves the same iframe element')
        check(frame.evaluate('canvasResult.ids.length')==12 and frame.evaluate('!!window.StonefellowAudio'),'the new iframe document has a fresh working SDK session after the old request was aborted')
        altered={**scene,'id':4,'js':'window.untrustedHashExecuted=true;'}
        page.evaluate('scene=>showFixture(scene)',altered)
        frame=next(frame for frame in page.frames if frame!=page.main_frame)
        page.wait_for_timeout(120)
        check(frame.evaluate('window.untrustedHashExecuted===undefined'),'changed code cannot execute under the original server-derived script hash')
        navigation='parent.postMessage("navigation-probe","*");location.href="https://outside.invalid/navigation-probe";'
        navigation_scene={**scene,'id':5,'js':navigation,'js_hash':base64.b64encode(hashlib.sha256(navigation.encode()).digest()).decode()}
        page.evaluate('scene=>showFixture(scene)',navigation_scene)
        page.wait_for_function('window.navigationProbe===true')
        page.wait_for_timeout(120)
        check(external==[],'the host frame policy blocks sandbox self-navigation before an external request is sent')
        check(page.locator('meta[data-sf-canvas-frame-policy]').get_attribute('content')=="frame-src 'none'",'alternate hosts install the same frame navigation policy before opening the canvas')

    finally:browser.close()


MUSIC_CODE=r'''(async()=>{
 const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
 svg.setAttribute('viewBox','0 0 80 80');svg.classList.add('arbitrary-orbit');
 const shape=document.createElementNS('http://www.w3.org/2000/svg','path');
 shape.setAttribute('d','M5 40 Q40 5 75 40 Q40 75 5 40');svg.append(shape);
 document.querySelector('.music-shape').append(svg);
 const catalog=await StonefellowAudio.catalog();
 window.musicCatalog=catalog;
 const song=catalog.tracks[0];
 const play=document.createElement('button');play.textContent='Play saved catalog id';
 play.onclick=async()=>{
  try{await StonefellowAudio.play(song.id);document.querySelector('.music-output').textContent='played '+song.id}
  catch(error){document.querySelector('.music-output').textContent='error '+error.code}
 };
 document.querySelector('.music-shape').append(play);
 document.querySelector('.music-output').textContent='ready';
})().catch(error=>{document.querySelector('.music-output').textContent='error '+error.message});'''


GESTURE_CODE=r'''(async()=>{
 const api=StonefellowAudio;
 const out=document.querySelector('.event-output');
 const catalog=await api.catalog();
 window.eventFixture={catalog:catalog.tracks.map(row=>row.track_id),events:[]};
 window.eventPlay=async id=>{
  const activation={active:navigator.userActivation.isActive,ever:navigator.userActivation.hasBeenActive};
  try{
   const state=await api.play(id);
   const event={id,ok:true,activation,state};eventFixture.events.push(event);
   parent.postMessage({stonefellowGestureFixture:event},'*');out.textContent='played '+id;
  }catch(error){
   const event={id,ok:false,activation,code:error.code,message:error.message};eventFixture.events.push(event);
   parent.postMessage({stonefellowGestureFixture:event},'*');
   out.textContent='failed '+id+' '+error.code;
  }
 };
 const start=document.createElement('button');start.textContent='Start event';
 start.onclick=()=>{
  const detached=new Audio('api/canvas-audio.php?action=source&track_id=song-2&private_marker=canvas-private-source');
  window.detachedEventAudio=detached;detached.play().catch(()=>{});
  out.textContent='event armed';setTimeout(()=>eventPlay('song-2'),6200);
 };
 document.querySelector('.event-controls').append(start);
 await eventPlay('song-1');
 out.textContent='ready';
})().catch(error=>{document.querySelector('.event-output').textContent='error '+error.message});'''


def run_gesture_browser(pw,site,base,scene):
    """Exercise delayed canvas event playback under Chromium's normal policy."""
    options={'args':['--no-sandbox']}
    if CHROME:options['executable_path']=CHROME
    browser=pw.chromium.launch(**options)
    event_scene=save_fixture_scene(site,{**scene,'title':'Generic delayed audio event',
        'html':'<section class="event-controls"></section><p class="event-output"></p>',
        'css':'main{padding:90px 20px 160px}button{min-height:44px;min-width:100px}',
        'js':GESTURE_CODE})
    # This fixture-only legacy callback reproduces the old source choice while
    # preserving today's SDK, catalog route and browser policy. It does not
    # depend on Git history, so the regression remains runnable after release.
    old_start=CALLBACKS.index('  async function playCanvasAudio(trackId){')
    old_end=CALLBACKS.index('  function pauseCanvasAudio(){',old_start)
    legacy_callbacks=CALLBACKS[:old_start]+'''  async function playCanvasAudio(trackId){
    const owner=canvasOwner(),scene=listeningRoom,id=trackId??(st.track?String(st.track.id):'');
    if(!id)throw new Error('Choose a song before starting playback.');
    const catalog=await loadCanvasAudioCatalog({track_id:id});
    if(!catalog.tracks.some(track=>track.track_id===id))throw new Error('This song is no longer in the published catalog.');
    let track=trackById(id);
    if(!track){await refreshSiteData();track=trackById(id);}
    requireCanvasAudioSession(owner,scene);
    if(!track)throw new Error('This song is unavailable.');
    if(String(st.track?.id)===id&&!audio.paused&&!audio.ended)return canvasAudioState();
    const position=String(st.track?.id)===id?savedPlaybackPosition():0;
    if(!await play(track,position,'player'))throw new Error('The player could not start this song.');
    requireCanvasAudioSession(owner,scene);
    return canvasAudioState();
  }
'''+CALLBACKS[old_end:]
    (site/'fixture-legacy-audio.html').write_text((site/'fixture.html').read_text().replace(CALLBACKS,legacy_callbacks))
    try:
        for width,legacy in ((375,True),(375,False),(1440,False)):
            context=browser.new_context(viewport={'width':width,'height':900},reduced_motion='reduce',has_touch=width==375)
            page=context.new_page();errors=[];requests=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.on('request',lambda request:requests.append(request.url))
            code=GESTURE_CODE.replace('6200','50') if legacy else GESTURE_CODE
            case_scene={**event_scene,'js':code,'js_hash':base64.b64encode(hashlib.sha256(code.encode()).digest()).decode()}
            page.add_init_script('''if(window===window.top){window.fixtureEvents=[];
              addEventListener('message',event=>{if(event.data?.stonefellowGestureFixture)fixtureEvents.push(event.data.stonefellowGestureFixture)});
              addEventListener('DOMContentLoaded',()=>{
                window.playActivations=[];
                const originalPlay=window.play;
                window.play=async(...args)=>{
                  playActivations.push({active:navigator.userActivation.isActive,ever:navigator.userActivation.hasBeenActive});
                  return originalPlay(...args);
                };
                trackById('song-2').audio='missing-cached-audio.mp3';
                showFixture('''+json.dumps(case_scene)+''');
              });}''')
            cdp=context.new_cdp_session(page)
            def inspect(expression):
                result=cdp.send('Runtime.evaluate',{'expression':expression,'returnByValue':True,'awaitPromise':True,'userGesture':False})
                assert not result.get('exceptionDetails'),result
                return result['result'].get('value')
            login(page,base,'owner@example.invalid');page.goto(base+('/fixture-legacy-audio.html?music=1' if legacy else '/fixture.html?music=1'))
            frame=next(frame for frame in page.frames if frame!=page.main_frame)
            deadline=time.monotonic()+12
            while time.monotonic()<deadline and inspect('fixtureEvents.length')<1:time.sleep(.05)
            prefix=('prior source regression/' if legacy else 'default autoplay/')+str(width)+': '
            blocked=inspect('fixtureEvents[0]')
            check(blocked and not blocked['activation']['ever'] and inspect('!navigator.userActivation.hasBeenActive'),prefix+'fresh host and opaque sandbox have no prior user activation')
            check(blocked['ok'] is False and inspect('audio.paused'),prefix+'playback without any gesture is genuinely blocked under the default browser policy: '+json.dumps(blocked))
            if not legacy:
                check(inspect('audioDiagnostics.some(row=>row.event==="rpc_error"&&row.method==="play"&&row.name==="NotAllowedError"&&row.code==="audio_gesture_required")'),prefix+'main-player gesture rejection is captured as an actionable sanitized RPC diagnostic')
                check(inspect('audioDebugEvents.some(row=>row.phase==="canvas_audio"&&row.detail.scene_id==='+str(event_scene['id'])+'&&row.detail.code==="audio_gesture_required")'),prefix+'main-player rejection reaches the scene debug sink')
            button=frame.get_by_role('button',name='Start event',exact=True)
            if width==375:button.tap()
            else:button.click()
            deadline=time.monotonic()+14
            while time.monotonic()<deadline and inspect('fixtureEvents.length')<2:time.sleep(.05)
            event=inspect('fixtureEvents[1]')
            if legacy:
                check(event and event['ok'] is False and event['activation']['ever'] and
                      any('missing-cached-audio.mp3' in request for request in requests),prefix+'prior callback reproduces the dead cached URL failure despite real interaction and fresh catalog validation')
                check(inspect('audio.paused'),prefix+'prior callback leaves playback stopped')
                context.close()
                continue
            check(event['ok'] is True and not event['activation']['active'] and event['activation']['ever'],prefix+'generic delayed event starts playback after transient activation has expired')
            check(inspect('st.track?.id==="song-2"&&!audio.paused&&audio.currentTime>=0'),prefix+'event uses the existing canonical audio element')
            check(inspect('playActivations.at(-1).ever&&!playActivations.at(-1).active'),prefix+'host playback retains prior interaction across the asynchronous SDK bridge')
            check(inspect('audioDiagnostics.some(row=>row.event==="media_csp_violation"&&row.source_kind==="host_reference")&&audioDiagnostics.some(row=>row.event==="local_playback_error"&&row.source_kind==="host_reference"&&row.name==="NotSupportedError")'),prefix+'trusted sandbox interaction reports detached local host-reference CSP and swallowed playback errors under the normal browser policy')
            check(inspect('!JSON.stringify({audioDiagnostics,audioDebugEvents}).includes("canvas-private-source")&&!JSON.stringify({audioDiagnostics,audioDebugEvents}).includes("canvas-audio.php")'),prefix+'normal-policy diagnostics omit private source URL and query data')
            check(inspect('trackById("song-2").audio==="missing-cached-audio.mp3"&&audio.currentSrc.includes("canvas-audio.php?action=source&track_id=song-2")') and
                  not any('missing-cached-audio.mp3' in request for request in requests),prefix+'fresh authorized MP3 source replaces a dead cached catalog URL without mutating the catalog')
            check(not errors,prefix+'no unhandled browser errors: '+repr(errors))
            context.close()
    finally:browser.close()


def save_music_scenes(site,scene):
    draft={**scene,'title':'A user-coded music experiment','html':'<div class="music-shape"></div><p class="music-output"></p>',
           'css':'main{padding:90px 20px 160px}.arbitrary-orbit{width:140px;fill:rebeccapurple;stroke:gold}',
           'js':MUSIC_CODE}
    for field in ('id','previous_id','created_at','js_hash'):draft.pop(field,None)
    encoded=base64.b64encode(json.dumps(draft).encode()).decode()
    return json.loads(cli(site,"require_once SF_ROOT.'/api/agent-scene-core.php';$scene=json_decode(base64_decode('"+encoded+"'),true);"
        "$selected=$scene+['music_inputs'=>['mode'=>'selected','track_ids'=>['song-9','song-2','song-5']]];"
        "$first=sf_agent_scene_history_save(1,$selected);"
        "$edited=sf_agent_scene_history_save(1,array_replace($selected,['music_inputs'=>['mode'=>'selected','track_ids'=>['song-3']]]),(int)$first['id']);"
        "$other=sf_agent_scene_history_save(2,$scene+['music_inputs'=>['mode'=>'selected','track_ids'=>['song-1','song-7']]]);"
        "$all=sf_agent_scene_history_save(1,$scene+['music_inputs'=>['mode'=>'all']]);"
        "echo json_encode(['selected'=>$first,'edited'=>$edited,'other'=>$other,'all'=>$all]);"))


def login(page,base,email):
    csrf=page.request.get(base+'/api/auth.php').json()['csrf']
    response=page.request.post(base+'/api/auth.php',data={'action':'login','email':email,'password':'fixture-only-password'},headers={'X-CSRF-Token':csrf})
    assert response.status==200,response.text()


MATTER_CODE=r'''(async()=>{
 const output=document.querySelector('.matter-output');
 const canvas=document.createElement('canvas');
 canvas.width=200;canvas.height=80;canvas.className='matter-stage';
 const controls=document.querySelector('.matter-controls');
 controls.append(canvas);
 const context=canvas.getContext('2d');
 const engine=Matter.Engine.create({gravity:{x:0,y:0}});
 const bottle=Matter.Bodies.rectangle(25,40,12,30,{frictionAir:0});
 const target=Matter.Bodies.rectangle(170,40,12,50,{isStatic:true});
 Matter.Composite.add(engine.world,[bottle,target]);
 function draw(){
  context.clearRect(0,0,canvas.width,canvas.height);
  for(const body of [bottle,target]){
   context.beginPath();context.moveTo(body.vertices[0].x,body.vertices[0].y);
   for(const point of body.vertices.slice(1))context.lineTo(point.x,point.y);
   context.closePath();context.fillStyle=body===bottle?'#468a72':'#e8b35a';context.fill();
  }
 }
 const {tracks}=await StonefellowAudio.catalog();
 if(tracks.length!==1)throw new Error('Unexpected song selection');
 const selected=tracks[0];
 let hit=false,frames=0;
 Matter.Events.on(engine,'collisionStart',({pairs})=>{
  if(hit||!pairs.some(({bodyA,bodyB})=>(bodyA===bottle&&bodyB===target)||(bodyA===target&&bodyB===bottle)))return;
  hit=true;
  output.dataset.hitAfter=String(frames);
  StonefellowAudio.play(selected.track_id).then(
   state=>{output.textContent=state.playing?'played '+state.trackId:'playback did not start'},
   error=>{output.textContent='playback failed '+error.code}
  );
 });
 function advance(){
  frames++;
  Matter.Engine.update(engine,1000/60);
  draw();
  if(hit)return;
  if(frames>=120){output.textContent='missed';return}
  requestAnimationFrame(advance);
 }
 const launch=document.createElement('button');launch.type='button';launch.textContent='Throw bottle';
 launch.addEventListener('click',event=>{
  if(!event.isTrusted)return;
  launch.disabled=true;
  output.dataset.input=event.pointerType||'mouse';
  Matter.Body.setVelocity(bottle,{x:8,y:0});
  requestAnimationFrame(advance);
 });
 controls.prepend(launch);draw();
 output.textContent='ready '+selected.track_id;
 parent.postMessage({stonefellowMatterReady:{track:selected.track_id,activated:navigator.userActivation.hasBeenActive}},'*');
})().catch(error=>{document.querySelector('.matter-output').textContent='fixture error '+error.message});'''


def run_matter_browser(pw,site,base,scene):
    """Exercise a pinned library, trusted input, collision and authorized MP3 playback."""
    path=Path('assets/vendor/canvas/matter.js')
    (site/path).parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(ROOT/path,site/path)
    inventory=HTTPClient(base).get('/api/canvas-libraries.php').json()
    pin=next((card for card in inventory['libraries'] if card['id']=='matter'),None)
    assert pin is not None,'The registered Matter.js pin is unavailable in the fixture'
    track='song-2'
    saved=save_fixture_scene(site,{**scene,'title':'Physics-triggered playback',
        'html':'<section class="matter-controls"></section><p class="matter-output"></p>',
        'css':'.matter-controls{padding:90px 20px 0}.matter-stage{display:block;max-width:100%;background:#222}button{min-height:44px;touch-action:manipulation}',
        'music_inputs':{'mode':'selected','track_ids':[track]},
        'js':'// sf-libraries: '+pin['lock']+'\n'+MATTER_CODE})
    # No autoplay override. An actual click or tap starts the authored throw.
    options={'args':['--no-sandbox']}
    if CHROME:options['executable_path']=CHROME
    browser=pw.chromium.launch(**options)
    try:
        for width,touch in ((1280,False),(390,True)):
            context=browser.new_context(viewport={'width':width,'height':850},is_mobile=touch,has_touch=touch)
            page=context.new_page()
            errors=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            scene_id=saved['id']
            page.add_init_script('''if(window===window.top){
                addEventListener('message',event=>{
                    if(event.data?.stonefellowMatterReady)window.matterReady=event.data.stonefellowMatterReady;
                });
                addEventListener('DOMContentLoaded',async()=>{
                    const {scene}=await api('agent-scene.php?id='''+str(scene_id)+'''');
                    await SFPublicAgentCanvas.prepareSceneLibraries(scene);
                    showFixture(scene);
                });
            }''')
            login(page,base,'owner@example.invalid')
            page.goto(base+'/fixture.html?music=1')
            # A Playwright locator assertion can grant activation while the sandbox starts.
            cdp=context.new_cdp_session(page)
            ready=None
            deadline=time.monotonic()+12
            while time.monotonic()<deadline and not ready:
                response=cdp.send('Runtime.evaluate',{'expression':
                    'window.matterReady&&{...matterReady,hostActivated:navigator.userActivation.hasBeenActive,calls:playCalls.length}',
                    'returnByValue':True,'userGesture':False})
                assert not response.get('exceptionDetails'),response
                ready=response['result'].get('value')
                if not ready:time.sleep(.05)
            assert ready=={'track':track,'activated':False,'hostActivated':False,'calls':0},(ready,errors)
            frame=page.frame_locator('.sf-agent-scene-backdrop iframe')
            output=frame.locator('.matter-output')
            button=frame.get_by_role('button',name='Throw bottle')
            if touch:button.tap()
            else:button.click()
            expect(output).to_have_text('played '+track)
            expect(output).to_have_attribute('data-input','touch' if touch else 'mouse')
            page.wait_for_function('audio.currentTime>0')
            check(int(output.get_attribute('data-hit-after'))>1 and
                page.evaluate('''({track,sceneId})=>{
                    const source=new URL(audio.currentSrc);
                    return typeof window.Matter==='undefined'&&playCalls.length===1&&playCalls[0]===track&&st.track?.id===track&&!audio.paused&&
                        source.pathname==='/api/canvas-audio.php'&&source.searchParams.get('action')==='source'&&
                        source.searchParams.get('track_id')===track&&source.searchParams.get('scene_id')===String(sceneId);
                }''',{'track':track,'sceneId':scene_id}),
                f'Chromium/{width}: a Matter collision starts the one selected MP3 on the canonical player')
            denied=frame.locator('body').evaluate("async()=>StonefellowAudio.play('song-1').then(()=>null,error=>error.code)")
            check(denied=='track_unavailable' and page.evaluate('playCalls.length===1') and not errors,
                f'Chromium/{width}: the same physics scene cannot start unselected audio and runs without browser errors')
            context.close()
    finally:browser.close()


MEYDA_CODE=r'''(async()=>{
 const controls=document.querySelector('.meyda-controls');
 const output=document.querySelector('.meyda-output');
 const meter=document.querySelector('.meyda-meter');
 const {tracks}=await StonefellowAudio.catalog();
 if(tracks.length!==1)throw new Error('Expected one authorized song');
 const track=tracks[0];
 const frameSize=512;
 let buffer=null,off=null,closed=false,lastSample=-1,frames=0;
 function dispose(){
  closed=true;
  const subscribed=typeof off==='function';
  const hadBuffer=buffer!==null;
  off?.();
  off=null;
  buffer=null;
  return {released:hadBuffer&&buffer===null&&off===null,subscribed,frames};
 }
 function draw(state){
  if(closed)return;
  if(state.trackId!==track.track_id){
   dispose();
   meter.style.width='0%';
   output.textContent='song changed';
   return;
  }
  if(!buffer)return;
  if(!state.playing){
   meter.style.width='0%';
   output.textContent='paused';
   return;
  }
  const index=Math.floor(state.currentTime*buffer.sampleRate);
  if(index===lastSample||index+frameSize>buffer.length)return;
  lastSample=index;
  const input=buffer.getChannelData(0).slice(index,index+frameSize);
  const {rms,amplitudeSpectrum}=Meyda.extract(['rms','amplitudeSpectrum'],input);
  let peak=0;
  for(let bin=1;bin<amplitudeSpectrum.length;bin++){
   if(amplitudeSpectrum[bin]>amplitudeSpectrum[peak])peak=bin;
  }
  output.dataset.peak=String(Math.round(peak*buffer.sampleRate/frameSize));
  output.dataset.frames=String(++frames);
  meter.style.width=Math.round(Math.min(1,rms*10)*100)+'%';
  output.textContent='playing '+state.trackId;
 }
 addEventListener('pagehide',()=>{
  parent.postMessage({stonefellowMeydaCleanup:dispose()},'*');
 },{once:true});
 function button(label,action){
  const element=document.createElement('button');
  element.type='button';element.textContent=label;
  element.addEventListener('click',event=>{
   if(event.isTrusted)action(element).catch(error=>{if(!closed)output.textContent='analysis error '+(error.code||error.message)});
  });
  controls.append(element);
 }
 button('Play and analyze',async(element)=>{
  element.disabled=true;
  const initial=await StonefellowAudio.play(track.track_id);
  if(closed)return;
  if(initial.trackId!==track.track_id||[track.duration_seconds,initial.duration].some(value=>!(value>0&&value<=15))){
   throw new Error('Fixture duration exceeds its decode bound');
  }
  let watch=false;
  off=StonefellowAudio.subscribe(state=>{if(watch)draw(state)});
  watch=true;
  try{
   const {blob}=await StonefellowAudio.getAudio(track.track_id);
   if(closed)return;
   if(blob.size>1024*1024)throw new Error('Fixture audio exceeds its decode bound');
   const bytes=await blob.arrayBuffer();
   if(closed)return;
   const decoder=new OfflineAudioContext(1,1,22050);
   const decoded=await decoder.decodeAudioData(bytes);
   if(closed)return;
   if(decoded.duration>15)throw new Error('Decoded fixture audio exceeds its duration bound');
   const state=await StonefellowAudio.getState();
   if(closed||state.trackId!==track.track_id)return;
   buffer=decoded;
   Meyda.bufferSize=frameSize;
   Meyda.sampleRate=decoded.sampleRate;
   draw(state);
  }catch(error){
   if(!closed){
    dispose();
    output.textContent='analysis error '+(error.code||error.message);
   }
  }finally{
   await StonefellowAudio.releaseAudio(track.track_id);
  }
 });
 button('Seek to second tone',async()=>{await StonefellowAudio.seek(4)});
 button('Pause',async()=>{await StonefellowAudio.pause()});
 output.textContent='ready';
})().catch(error=>{document.querySelector('.meyda-output').textContent='fixture error '+error.message});'''


def run_meyda_browser(pw,site,base,scene):
    """Draw actual audio features at the authorized player's current position."""
    vendor=Path('assets/vendor/canvas/meyda.js')
    (site/vendor).parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(ROOT/vendor,site/vendor)
    inventory=HTTPClient(base).get('/api/canvas-libraries.php').json()
    pin=next(card for card in inventory['libraries'] if card['id']=='meyda')
    track='song-2'
    subprocess.run(['ffmpeg','-v','error','-f','lavfi','-i','sine=frequency=320:sample_rate=22050:duration=3',
                    '-f','lavfi','-i','sine=frequency=960:sample_rate=22050:duration=9',
                    '-filter_complex','[0:a]volume=0.25[quiet];[quiet][1:a]concat=n=2:v=0:a=1',
                    '-ac','1','-b:a','32k',str(site/'assets/audio/analysis.mp3')],check=True)
    catalog_path=site/'data/catalog.json'
    tracks=json.loads(catalog_path.read_text())
    next(row for row in tracks if row['id']==track).update(audio='assets/audio/analysis.mp3',duration=12)
    catalog_path.write_text(json.dumps(tracks))
    saved=save_fixture_scene(site,{**scene,'title':'Authorized playback spectrum',
        'html':'<section class="meyda-controls"></section><p class="meyda-output"></p><div class="meyda-meter"></div>',
        'css':'.meyda-controls{padding:75px 20px 0;max-width:260px}button{min-height:44px;touch-action:manipulation;display:block}.meyda-meter{width:0;height:12px;background:#60da90}',
        'music_inputs':{'mode':'selected','track_ids':[track]},
        'js':'// sf-libraries: '+pin['lock']+'\n'+MEYDA_CODE})
    options={'args':['--no-sandbox']}
    if CHROME:options['executable_path']=CHROME
    browser=pw.chromium.launch(**options)
    try:
        for width,touch in ((1280,False),(390,True)):
            context=browser.new_context(viewport={'width':width,'height':850},is_mobile=touch,has_touch=touch)
            page=context.new_page()
            errors=[]
            requests=[]
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.on('request',lambda request:requests.append(request.url))
            page.add_init_script('''if(window===window.top){
                addEventListener('message',event=>{
                    if(event.data?.stonefellowMeydaCleanup)window.meydaCleanup=event.data.stonefellowMeydaCleanup;
                });
                addEventListener('DOMContentLoaded',async()=>{
                    const {scene}=await api('agent-scene.php?id='''+str(saved['id'])+'''');
                    await SFPublicAgentCanvas.prepareSceneLibraries(scene);
                    showFixture(scene);
                });
            }''')
            login(page,base,'owner@example.invalid')
            page.goto(base+'/fixture.html?music=1')
            frame=page.frame_locator('.sf-agent-scene-backdrop iframe')
            output=frame.locator('.meyda-output')
            meter=frame.locator('.meyda-meter')
            expect(output).to_have_text('ready',timeout=12000)

            def click(label):
                control=frame.get_by_role('button',name=label)
                if touch:control.tap()
                else:control.click()

            click('Play and analyze')
            expect(output).to_have_text('playing '+track,timeout=12000)
            first_peak=int(output.get_attribute('data-peak'))
            first_width=int(meter.evaluate('element=>parseInt(element.style.width,10)'))
            check(275<=first_peak<=365 and first_width>0 and page.evaluate(
                'typeof window.Meyda==="undefined"&&playCalls.length===1&&st.track?.id==="song-2"&&!audio.paused'),
                f'Chromium/{width}: Meyda draws the actual low tone from the authorized canonical playback')
            click('Seek to second tone')
            expect(output).to_have_attribute('data-peak','947')
            check(int(meter.evaluate('element=>parseInt(element.style.width,10)'))>first_width,
                f'Chromium/{width}: seeking moves analysis to the loud, higher-frequency tone')
            denied=output.evaluate("async()=>StonefellowAudio.getAudio('song-1').then(()=>null,error=>error.code)")
            check(denied=='audio_unavailable' and not any('action=source&track_id=song-1&' in url for url in requests),
                f'Chromium/{width}: the analysis canvas cannot load unselected audio')
            click('Pause')
            expect(output).to_have_text('paused')
            expect(meter).to_have_attribute('style','width: 0%;')
            frames=output.get_attribute('data-frames')
            page.wait_for_timeout(300)
            check(output.get_attribute('data-frames')==frames and page.evaluate('audio.paused'),
                f'Chromium/{width}: pausing stops the meter updates and the host player')
            # Check authored pagehide cleanup while the child can still report it.
            # Removing the iframe can discard messages queued by that document.
            layer=page.locator('.sf-agent-scene-backdrop iframe')
            child=layer.element_handle().content_frame()
            assert child is not None
            child.evaluate("dispatchEvent(new PageTransitionEvent('pagehide'))")
            page.wait_for_function('window.meydaCleanup !== undefined')
            assert page.evaluate('window.meydaCleanup')=={'released':True,'subscribed':True,'frames':int(frames)}
            assert output.get_attribute('data-frames')==frames
            assert page.evaluate('SFPublicAgentCanvas.closeScene(false)')
            expect(layer).to_have_count(0)
            check(child.is_detached() and not errors,
                f'Chromium/{width}: pagehide releases analysis resources and host close detaches the frame')
            context.close()
    finally:browser.close()


def run_music_http(client,site,scene):
    saved=save_music_scenes(site,scene)
    route='/api/agent-scene.php';selected=saved['selected'];other=saved['other']
    check(client.get(route+'?id='+str(selected['id'])).status==401,'canvas music history is private before authentication')
    csrf=client.get('/api/auth.php').json()['csrf']
    auth=client.post('/api/auth.php',data={'action':'login','email':'owner@example.invalid','password':'fixture-only-password'},headers={'X-CSRF-Token':csrf})
    assert auth.status==200,auth.json()
    csrf=auth.json()['csrf']
    read=client.get(route+'?id='+str(selected['id']))
    check(read.status==200 and read.json()['scene']['music_inputs']==selected['music_inputs'],'authenticated history preserves the exact selected music order')
    edit=client.get(route+'?id='+str(saved['edited']['id'])).json()['scene']
    check(edit['music_inputs']['track_ids']==['song-3'] and edit['previous_id']==selected['id'],'canvas revisions persist their changed music inputs independently')
    check(client.get(route+'?id='+str(other['id'])).status==404,'another account’s saved canvas music selection cannot be read')
    check(client.get(route+'?id='+str(saved['all']['id'])).json()['scene']['music_inputs']=={'mode':'all'},'all-music mode survives authenticated history reopening')
    exported=json.loads(cli(site,"require_once SF_ROOT.'/api/agent-scene-core.php';echo json_encode(sf_agent_scene_history_export(1));"))
    check(any(row['id']==selected['id'] and row.get('music_inputs')==selected['music_inputs'] for row in exported) and all(row['id']!=other['id'] for row in exported),'account export retains music inputs only for its own saved canvases')

    # Seed a settled request without a model: any accidental paid redispatch would fail this fixture.
    request_id='d'*32;brief='Shape this music into an orbit'
    selection=base64.b64encode(json.dumps(selected['music_inputs']).encode()).decode()
    cli(site,"require_once SF_ROOT.'/api/agent-scene-core.php';sf_privacy_ensure_schema();$music=json_decode(base64_decode('"+selection+"'),true);"
        "$input=['','"+brief+"',null,'custom',null,['music_inputs'=>$music]];"
        "sf_agent_scene_request_claim(1,'"+request_id+"',hash('sha256',serialize($input)),sf_agent_scene_history_epoch(1));"
        "sf_db()->beginTransaction();sf_agent_scene_request_finish(1,'"+request_id+"',"+str(selected['id'])+",0,100,'model');sf_db()->commit();")
    body={'canvas_kind':'custom','brief':brief,'request_id':request_id,'music_inputs':selected['music_inputs']}
    replay=client.post(route,data=body,headers={'X-CSRF-Token':csrf})
    check(replay.status==200 and replay.json()['state']=='ready' and replay.json()['scene']['id']==selected['id'],'an exact settled music request replay returns its saved canvas without a provider call')
    changed=client.post(route,data={**body,'music_inputs':{'mode':'selected','track_ids':['song-3']}},headers={'X-CSRF-Token':csrf})
    check(changed.status==422 and changed.json()['error']=='invalid_scene_request','reusing a build request ID with a different music selection is rejected before generation')
    path=site/'data/catalog.json';catalog=json.loads(path.read_text())
    for row in catalog:
        if row['id']=='song-9':row['state']='private'
    path.write_text(json.dumps(catalog))
    replay=client.post(route,data=body,headers={'X-CSRF-Token':csrf})
    check(replay.status==200 and replay.json()['scene']['id']==selected['id'],'a settled request remains safely recoverable after a selected source is withdrawn')


def run_music_browser(pw,site,base,mp3,scene):
    saved=save_music_scenes(site,scene)
    options={'args':['--no-sandbox','--autoplay-policy=no-user-gesture-required']}
    if CHROME:options['executable_path']=CHROME
    browser=pw.chromium.launch(**options)
    def load(page,name,owner=1):
        response=page.request.get(base+'/api/agent-scene.php?id='+str(saved[name]['id']))
        assert response.status==200,response.text()
        found=response.json()['scene']
        page.evaluate('(value)=>{fixtureOwner=String(value.owner);st.auth.user.id=value.owner;showFixture(value.scene)}',{'owner':owner,'scene':found})
        frame=next(frame for frame in page.frames if frame!=page.main_frame)
        expect(frame.locator('.music-output')).to_have_text('ready',timeout=15000)
        return frame,found
    try:
        page=browser.new_page(viewport={'width':1280,'height':950})
        check(page.request.get(base+'/api/agent-scene.php?id='+str(saved['selected']['id'])).status==401,'saved canvas music selections require the owning account session')
        login(page,base,'owner@example.invalid');page.goto(base+'/fixture.html?music=1')
        frame,found=load(page,'selected')
        selected=['song-9','song-2','song-5']
        check(found['music_inputs']=={'mode':'selected','track_ids':selected},'selected music IDs survive authenticated canvas history readback')
        check(frame.evaluate('musicCatalog.tracks.map(row=>row.track_id)')==selected,'the host gives the SDK only the three chosen tracks in selection order')
        filtered=frame.evaluate("async()=> (await StonefellowAudio.catalog({release_id:'album-one'})).tracks.map(row=>row.track_id)")
        check(filtered==selected,'album-filtered SDK reads retain the same selected music scope')
        check(frame.evaluate('musicCatalog.releases.flatMap(row=>row.track_ids).sort()')==sorted(selected),'album metadata exposes no unselected track memberships')
        before=page.evaluate('({src:audio.getAttribute("src"),track:st.track?.id,calls:playCalls.length})')
        denied=frame.evaluate("async()=>Promise.allSettled([StonefellowAudio.getTrack('song-1'),StonefellowAudio.getAudio('song-1'),StonefellowAudio.play('song-1')]).then(values=>values.map(value=>value.status))")
        check(denied==['rejected']*3 and page.evaluate('({src:audio.getAttribute("src"),track:st.track?.id,calls:playCalls.length})')==before,'unselected metadata, MP3 and play requests reject without changing canonical audio')
        asset=frame.evaluate("async()=>{const value=await StonefellowAudio.getAudio('song-2');return {id:value.track.track_id,bytes:value.blob.size}}")
        check(asset=={'id':'song-2','bytes':len(mp3)},'a selected track still supplies the real authorized MP3 Blob')
        frame.evaluate("async()=>await StonefellowAudio.play('song-2')")
        check(page.evaluate('st.track.id==="song-2" && !audio.paused && playCalls.length===1'),'selected music controls use the same canonical player')
        check(frame.evaluate('musicCatalog.tracks.every(row=>row.id===row.track_id)&&musicCatalog.releases.every(row=>row.id===row.release_id)'),
              'live SDK catalog exposes matching id aliases for selected songs and albums')
        frame.get_by_role('button',name='Play saved catalog id',exact=True).click()
        expect(frame.locator('.music-output')).to_have_text('played song-9')
        check(page.evaluate('st.track.id==="song-9"&&!audio.paused&&playCalls.length===2'),
              'saved authored source reading song.id plays the selected song instead of resuming a different current song')
        before_missing=page.evaluate('({id:st.track.id,calls:playCalls.length,src:audio.currentSrc})')
        missing=frame.evaluate('async()=>Promise.allSettled([StonefellowAudio.play(undefined),StonefellowAudio.play(null)]).then(rows=>rows.map(row=>({status:row.status,code:row.reason?.code})))')
        check(missing==[{'status':'rejected','code':'invalid_track'}]*2 and
              page.evaluate('({id:st.track.id,calls:playCalls.length,src:audio.currentSrc})')==before_missing,
              'explicit missing song IDs reject without silently resuming or changing canonical playback')
        check(frame.locator('svg.arbitrary-orbit path').count()==1 and not page.locator('.sf-agent-scene-playback').count() and not frame.locator('.sf-host-playback').count(),'arbitrary coded shapes remain intact without host music cards or playback presets')
        if MUSIC_ID_ONLY:
            return
        frame,edited=load(page,'edited')
        check(edited['previous_id']==found['id'] and frame.evaluate('musicCatalog.tracks.map(row=>row.track_id)')==['song-3'],'a saved canvas revision retains its own updated selection')
        page.reload();frame,reopened=load(page,'selected')
        check(reopened['music_inputs']==found['music_inputs'] and frame.evaluate('musicCatalog.tracks.map(row=>row.track_id)')==selected,'reloading and reopening an older canvas restores its original selection')
        check(page.request.get(base+'/api/agent-scene.php?id='+str(saved['other']['id'])).status==404,'a user cannot read another account’s canvas or music selection')

        other=browser.new_page(viewport={'width':900,'height':900})
        # Each browser page has a separate context, and therefore a separate persistent login.
        login(other,base,'other@example.invalid');other.goto(base+'/fixture.html?music=1')
        other_frame,_=load(other,'other',2)
        check(other_frame.evaluate('musicCatalog.tracks.map(row=>row.track_id)')==['song-1','song-7'] and other.request.get(base+'/api/agent-scene.php?id='+str(saved['selected']['id'])).status==404,'a second account reopens only its independently saved music scope')
        other.close()

        frame,all_scene=load(page,'all')
        expected=['song-'+str(index) for index in range(1,13)]+['l'*180]+['bulk-'+str(index) for index in range(1,131)]
        check(all_scene['music_inputs']=={'mode':'all'} and frame.evaluate('musicCatalog.tracks.map(row=>row.track_id)')==expected and len(expected)>120,'all-music canvases receive every currently available published MP3 beyond 120 tracks')
        values=frame.evaluate('async()=>Promise.allSettled(musicCatalog.tracks.map(row=>StonefellowAudio.getTrack(row.track_id))).then(results=>({count:results.length,rejected:results.filter(row=>row.status!=="fulfilled").length}))')
        check(values=={'count':len(expected),'rejected':0},'concurrent SDK reads for the entire catalog drain with backpressure and no queue-limit failures')

        path=site/'data/catalog.json';catalog=json.loads(path.read_text())
        catalog.append({'id':'later-song','title':'Newly published song','duration':6,'state':'published','audio':'assets/audio/fixture.mp3'})
        path.write_text(json.dumps(catalog))
        later=frame.evaluate('async()=>{const catalog=await StonefellowAudio.catalog();return {count:catalog.tracks.length,last:catalog.tracks.at(-1).track_id}}')
        check(later=={'count':len(expected)+1,'last':'later-song'},'all-music mode dynamically includes a newly published MP3 without rebuilding the canvas')
        for row in catalog:
            if row['id']=='song-9':row['state']='private'
            if row['id']=='song-5':row['audio']='https://example.invalid/withdrawn.mp3'
        path.write_text(json.dumps(catalog))
        frame,_=load(page,'selected')
        check(frame.evaluate('musicCatalog.tracks.map(row=>row.track_id)')==['song-2'],'saved selections omit songs whose visibility or hosted MP3 availability is withdrawn')
        prior=page.evaluate('playCalls.length')
        withdrawn=frame.evaluate("async()=>Promise.allSettled([StonefellowAudio.getAudio('song-9'),StonefellowAudio.play('song-5')]).then(values=>values.map(value=>value.status))")
        check(withdrawn==['rejected']*2 and page.evaluate('playCalls.length')==prior,'withdrawn selected sources and play requests fail without playback side effects')
    finally:browser.close()


def main():
    with tempfile.TemporaryDirectory(prefix='stoneward-canvas-audio-') as temp:
        site=Path(temp);mp3,scene=seed(site)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base='http://127.0.0.1:'+str(port)
        with (site/'php.log').open('w+') as log:
            server=subprocess.Popen([PHP,'-d','session.save_path='+str(site/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(site)],stdout=log,stderr=log,start_new_session=True)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                    except OSError:time.sleep(.02)
                if HTTP_ONLY:
                    client=HTTPClient(base)
                    run_http(client,site,mp3)
                    run_music_http(client,site,scene)
                else:
                    with sync_playwright() as pw:
                        if AUTOPLAY_ONLY:
                            run_gesture_browser(pw,site,base,scene)
                        elif MUSIC_ID_ONLY:
                            run_music_browser(pw,site,base,mp3,scene)
                        elif MATTER_ONLY:
                            run_matter_browser(pw,site,base,scene)
                        elif MEYDA_ONLY:
                            run_meyda_browser(pw,site,base,scene)
                        else:
                            run_http(pw.request.new_context(base_url=base),site,mp3)
                            run_browser(pw,site,base,mp3,scene)
                            run_music_browser(pw,site,base,mp3,scene)
                            run_gesture_browser(pw,site,base,scene)
                            run_matter_browser(pw,site,base,scene)
                            run_meyda_browser(pw,site,base,scene)
                            run_music_http(HTTPClient(base),site,scene)
            except BaseException:
                log.seek(0);print(log.read()[-7000:]);raise
            finally:os.killpg(server.pid,signal.SIGTERM);server.wait()
    print('PASS',checks,'real canvas audio '+('HTTP' if HTTP_ONLY else 'HTTP and browser')+' checks; no paid calls')


if __name__=='__main__':main()
