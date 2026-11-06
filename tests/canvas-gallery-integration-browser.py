"""Real gallery endpoints, copied pixels and MP3s through the production app adapters and renderer."""
import base64
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import struct
import subprocess
import sys
import tempfile
import time
import zlib

sys.dont_write_bytecode = True
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'assets/js/app.js').read_text()
COMMANDS = APP[APP.index('  function canvasDesignBrief('):APP.index('  async function liveAgent(')]
RENDER = APP[APP.index('  function sceneImagePath('):APP.index('  async function renderHome(')]
AUTH = APP[APP.index('  function syncAuthMenu(){'):APP.index('  async function authPost(')]
spec = importlib.util.spec_from_file_location('canvas_audio_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)
checks = []


def check(value, message):
    assert value, message
    checks.append(message)
    print('PASS', message, flush=True)


PROGRAM = r'''(async()=>{
  const catalog=await StonefellowAudio.catalog();
  window.galleryCatalogIds=catalog.tracks.map(row=>row.track_id);
  const asset=await StonefellowAudio.getAudio('song-1');window.galleryAudioBytes=asset.blob.size;
  for(const id of ['song-1','song-2']){
    const button=document.createElement('button');button.textContent='Play '+id;
    button.onclick=()=>StonefellowAudio.play(id).catch(()=>{document.querySelector('.sdk-status').textContent='play error';});
    document.querySelector('.controls').append(button);
  }
  document.querySelector('.sdk-status').textContent='ready';
})().catch(()=>{document.querySelector('.sdk-status').textContent='sdk error';});'''


SETUP = r'''
window.mainCanvas=document.querySelector('#target');window.canvas=mainCanvas;
window.footerPlayer=document.querySelector('#canonicalPlayer');window.audio=document.querySelector('#canonical');
window.starters=document.querySelector('#starters');window.input=document.querySelector('#composer');
window.st={auth:{authenticated:false,user:null,csrf:null},track:null,playing:false,queue:{open:false},conversationOn:false};
window.cfg={apiBase:'api'};window.catalog=fixtureTracks;window.manualAgentHidden=false;window.popupEntries=[];
window.songCaptureSkill=null;window.songListeningSkill=null;window.vocalStudio=null;window.songMoments=null;
window.iosVoiceNeedsGesture=false;window.accountPhotoPreferencesRequest=null;window.chatImagePicker=null;
window.chatMusicPicker=null;window.chatResourcePicker=null;window.canvasImageEditDraft=null;
window.chatImageId=/^(?:ref-)?[a-f0-9]{32}$/;window.notices=[];window.posts=[];window.playCalls=[];window.routes=[];
window.$=(selector,host=document)=>host?.querySelector(selector)||null;
window.$$=(selector,host=document)=>Array.from(host?.querySelectorAll(selector)||[]);
window.norm=value=>String(value||'').trim().toLowerCase();window.observedPlayer=()=>({track:st.track,playing:!audio.paused});
window.trackById=id=>catalog.find(track=>track.id===id);window.playerVolume=()=>audio.volume;
window.savedPlaybackPosition=()=>audio.currentTime||0;window.refreshSiteData=async()=>{};
window.setPlayerVolume=value=>{audio.volume=value;return value};window.seekPlayback=fraction=>{audio.currentTime=audio.duration*fraction;return true};
window.toggle=()=>{if(audio.paused)return audio.play();audio.pause()};
window.play=async(track,position,source,retried,onFailure)=>{
  playCalls.push(track.id);st.track=track;audio.src=track.audio;audio.load();
  try{await audio.play();st.playing=true;SFPublicAgentCanvas.syncPlayer();return true}catch(error){onFailure?.(error);return false}
};
window.api=async(path,body,signal)=>{
  const response=await fetch('api/'+path,{signal,credentials:'same-origin',cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{})});
  const result=await response.json();if(!response.ok||result.ok===false){const error=new Error(result.message||result.error||'API failed');error.status=response.status;error.payload=result;throw error;}return result;
};
window.secureJsonPost=async(path,payload,unused,signal)=>{
  posts.push({path,payload});
  const response=await fetch('api/'+path,{method:'POST',signal,credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':st.auth.csrf||''},body:JSON.stringify(payload)});
  const result=await response.json();if(!response.ok||result.ok===false){const error=new Error(result.message||result.error||'POST failed');error.status=response.status;error.payload=result;throw error;}return result;
};
window.popupEntry=(role,message)=>{notices.push({role,message});};window.agent=()=>{};
window.setUrl=(view,params)=>{routes.push({view,params});if(view==='home')document.querySelector('#galleryHost').hidden=true;};
window.navigate=async(view,params)=>routes.push({view,params});
window.setCanvasImageEditDraft=()=>{};window.selectCanvasImageForEdit=()=>{};window.syncAgentVisibility=()=>{};
window.syncWelcomeActions=()=>{};window.isTemporaryPlaylist=()=>false;window.closeChatPopup=()=>{};
window.canvasProjects=()=>null;canvasProjects.current=null;
let listeningRoom=null,listeningRoomRequest=null,listeningRoomOwner=null,canvasSelection=null;
'''


def png_bytes():
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    pixels = b''.join(b'\0' + bytes((row, 100, 220)) * 256 for row in range(128))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 256, 128, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(pixels)) + chunk(b'IEND', b'')


def seed_gallery(site):
    mp3, _ = fixture.seed(site)
    png = png_bytes()
    (site / 'fixture.png').write_bytes(png)
    image_id = '1234567890abcdef1234567890abcdef'
    scene = {'canvas_kind': 'custom', 'track_id': '', 'title': 'Private native source',
             'html': '<section class="layout"><figure class="hero sf-image-' + image_id + '"></figure><section class="controls"></section><p class="sdk-status"></p><section class="current sf-dataset-published_catalog sf-current-track"><p class="sf-data-row"><span class="sf-field-title"></span></p><p class="sf-data-empty">No current song</p></section><section class="personal sf-dataset-my_purchases"><p class="sf-data-row"><span class="sf-field-item_label"></span></p><p class="sf-data-empty">No personal purchases</p></section></section>',
             'css': '.layout{padding:90px 24px 160px}.hero{width:256px;min-height:128px;margin:0}.hero img{width:256px;height:128px;object-fit:cover}.controls{display:flex;gap:12px}button{min-height:44px;padding:8px 14px}',
             'js': PROGRAM, 'music_inputs': {'mode': 'selected', 'track_ids': ['song-1', 'song-2']},
             'design_brief': 'Private original prompt must remain private.'}
    encoded = base64.b64encode(json.dumps(scene).encode()).decode()
    source = "require_once SF_ROOT.'/api/canvas-gallery-core.php';require_once SF_ROOT.'/api/agent-canvas-images.php';"
    source += "sf_agent_canvas_image_store(1,'" + image_id + "','Private original artwork',['bytes'=>file_get_contents(SF_ROOT.'/fixture.png')]);"
    source += "$source=sf_agent_scene_history_save(1,json_decode(base64_decode('" + encoded + "'),true));"
    source += "$request=['scene_id'=>$source['id'],'title'=>'Shared native canvas','description'=>'Published for native integration.','creator_label'=>'Native creator','publication_consent'=>true,'preview_asset_id'=>'" + image_id + "','request_id'=>str_repeat('f3',16)];"
    source += "$entry=sf_canvas_gallery_submit(['id'=>1,'role'=>'user'],$request);sf_db()->exec(\"UPDATE users SET role='admin' WHERE id=2\");"
    source += "$approved=sf_canvas_gallery_moderate(['id'=>2,'role'=>'admin'],$entry['id'],'approve',$entry['snapshot_hash']);"
    source += "echo json_encode(['item'=>$approved,'source_id'=>$source['id'],'balance'=>sf_token_balance(2),'count'=>count(sf_agent_scene_history_list(2))]);"
    saved = json.loads(fixture.cli(site, source))
    tracks = [{'id': row['id'], 'title': row['title'], 'duration': row.get('duration', 6), 'audio': 'api/canvas-audio.php?action=source&track_id=' + row['id']}
              for row in json.loads((site / 'data/catalog.json').read_text()) if row.get('state') == 'published']
    page = '''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/public-agent-canvas.css"><link rel="stylesheet" href="assets/css/canvas-gallery.css">
    <style>body{margin:0;background:#111;color:white}#target{min-height:90vh}.chat-footer{position:fixed;bottom:0;left:0;right:0}#galleryHost{position:relative;z-index:100;background:#111}</style>
    </head><body><div id="starters"></div><div id="target"></div><div id="galleryHost"></div><footer class="chat-footer"><div id="canonicalPlayer"><audio id="canonical"></audio></div><input id="composer"></footer>'''
    page += '<script>window.fixtureTracks=' + json.dumps(tracks).replace('<', '\\u003c') + ';window.galleryItem=' + json.dumps(saved['item']).replace('<', '\\u003c') + ';</script>'
    for script in ['canvas-audio-sdk.js', 'public-agent-canvas.js', 'canvas-gallery.js']:
        page += '<script src="assets/js/' + script + '"></script>'
    page += '<script>' + SETUP + COMMANDS + RENDER + AUTH + '''
    syncCanvasCodingModelMenu=()=>{};
    window.fixtureReady=renderAgentCanvas().then(async()=>{canvas=document.querySelector('#galleryHost');await renderCanvasGallery({id:galleryItem.id});});
    </script></body></html>'''
    (site / 'gallery-fixture.html').write_text(page)
    return saved, png, mp3, image_id


def main():
    with tempfile.TemporaryDirectory(prefix='stonefellow-gallery-native-') as directory:
        site = Path(directory)
        saved, png, mp3, original_image = seed_gallery(site)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = 'http://127.0.0.1:' + str(port)
        with (site / 'php.log').open('w+') as log:
            server = subprocess.Popen([fixture.PHP, '-d', 'session.save_path=' + str(site / 'sessions'), '-S', '127.0.0.1:' + str(port), '-t', str(site)], stdout=log, stderr=log, start_new_session=True)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.02)
                with sync_playwright() as playwright:
                    launch = {'headless': True, 'args': ['--no-sandbox', '--autoplay-policy=no-user-gesture-required']}
                    if os.environ.get('CHROMIUM_BIN'):
                        launch['executable_path'] = os.environ['CHROMIUM_BIN']
                    browser = playwright.chromium.launch(**launch)
                    try:
                        page = browser.new_page(viewport={'width': 1280, 'height': 950}, reduced_motion='reduce')
                        page.set_default_timeout(10000)
                        errors, requests = [], []
                        page.on('pageerror', lambda error: errors.append(str(error)))
                        page.on('request', lambda request: requests.append((request.method, request.url)))
                        page.goto(base + '/gallery-fixture.html')
                        expect(page.locator('#galleryHost .sf-gallery-card')).to_have_count(1)
                        check(page.locator('.sf-agent-scene-backdrop iframe').count() == 0,
                              'A real approved detail entry loads through the app adapter without executing its canvas')
                        page.get_by_role('button', name='Open Shared native canvas', exact=True).click()
                        iframe = page.locator('.sf-agent-scene-backdrop iframe')
                        expect(iframe).to_have_count(1)
                        frame = iframe.element_handle().content_frame()
                        expect(frame.locator('.sdk-status')).to_have_text('ready')
                        check(page.evaluate('isGalleryScene()&&!st.auth.authenticated&&listeningRoom.gallery_id===galleryItem.id&&canvasSceneOwner()==="gallery-guest"'),
                              'Guest Open uses the actual app identity and replaces the main background canvas')
                        check(frame.evaluate('galleryCatalogIds') == ['song-1', 'song-2'] and frame.evaluate('galleryAudioBytes') == len(mp3),
                              'The production SDK and actual guest app callbacks deliver the selected published catalog and exact MP3 bytes')
                        expect(frame.locator('.hero img')).to_have_count(1)
                        frame.wait_for_function('document.querySelector(".hero img")?.naturalWidth===256')
                        public_asset = page.evaluate('listeningRoom.image_assets[0]')
                        check(page.request.get(base + '/' + public_asset['url']).body() == png and public_asset['id'] != original_image,
                              'Copied gallery pixels load through their public manifest identity and retain the actual PNG bytes')
                        check(page.request.get(base + '/api/agent-canvas-image.php?id=' + original_image).status == 401 and
                              page.evaluate('!listeningRoom.datasets?.my_purchases') and 'Private original prompt' not in json.dumps(page.evaluate('listeningRoom')),
                              'Guest delivery exposes neither the source private image route nor author purchase data or prompt')
                        frame.get_by_role('button', name='Play song-1', exact=True).click()
                        page.wait_for_function('st.track?.id==="song-1"&&!audio.paused')
                        frame = iframe.element_handle().content_frame()
                        expect(frame.locator('.current')).to_have_text('Song 1')
                        expect(frame.locator('.sdk-status')).to_have_text('ready')
                        frame.get_by_role('button', name='Play song-2', exact=True).click()
                        page.wait_for_function('st.track?.id==="song-2"&&!audio.paused')
                        frame = iframe.element_handle().content_frame()
                        expect(frame.locator('.current')).to_have_text('Song 2')
                        check(page.evaluate('playCalls.join(",")==="song-1,song-2"&&audio.currentSrc.includes("canvas-audio.php?action=source&track_id=song-2")') and
                              any('current_track_id=song-2' in url for _, url in requests),
                              'Guest song selection routes to the canonical player and refreshes current-track data through the real gallery endpoint')

                        fixture.login(page, base, 'other@example.invalid')
                        page.evaluate('async()=>{await loadAuth(true)}')
                        check(page.locator('.sf-agent-scene-backdrop iframe').count() == 0,
                              'An account transition destroys the former guest frame before viewer data changes')
                        page.evaluate('async()=>{document.querySelector("#galleryHost").hidden=false;canvas=document.querySelector("#galleryHost");await renderCanvasGallery({id:galleryItem.id});}')
                        page.get_by_role('button', name='Open Shared native canvas', exact=True).click()
                        expect(page.locator('.sf-agent-scene-backdrop iframe')).to_have_count(1)
                        before_posts = page.evaluate('posts.length')
                        page.evaluate('async()=>handleListeningRoomCommand({kind:"revise",brief:"Revise this shared design"})')
                        check(page.evaluate('posts.length') == before_posts and page.evaluate('notices.at(-1).message.includes("Choose Remix")') and
                              page.evaluate('isGalleryScene()'),
                              'The actual edit command asks for a private remix before changing a public snapshot')
                        page.evaluate('async()=>{document.querySelector("#galleryHost").hidden=false;canvas=document.querySelector("#galleryHost");await renderCanvasGallery({id:galleryItem.id});}')
                        page.get_by_role('button', name='Remix Shared native canvas', exact=True).click()
                        page.wait_for_function('Number.isSafeInteger(listeningRoom?.id)&&!isGalleryScene()')
                        frame = page.locator('.sf-agent-scene-backdrop iframe').element_handle().content_frame()
                        expect(frame.locator('.sdk-status')).to_have_text('ready')
                        expect(frame.locator('.hero img')).to_have_count(1)
                        frame.wait_for_function('document.querySelector(".hero img")?.naturalWidth===256')
                        copied = page.evaluate('({id:listeningRoom.id,assets:listeningRoom.image_assets,attribution:listeningRoom.gallery_attribution,owner:listeningRoomOwner})')
                        check(copied['owner'] == '2' and copied['id'] != saved['source_id'] and copied['attribution']['source']['id'] == saved['item']['id'] and
                              len(page.evaluate('posts')) == 1 and page.evaluate('posts[0].payload.action') == 'remix',
                              'Signed-in Remix uses one real free API copy and the normal private history restore with source attribution')
                        private_asset = copied['assets'][0]
                        check(private_asset['id'] not in [original_image, public_asset['id']] and page.request.get(base + '/' + private_asset['url']).body() == png,
                              'Public-to-private restore displays an independent owned image copy through the exact-scene image callback')
                        counts = json.loads(fixture.cli(site, "echo json_encode(['balance'=>sf_token_balance(2),'count'=>count(sf_agent_scene_history_list(2))]);"))
                        check(counts['balance'] == saved['balance'] and counts['count'] == saved['count'] + 1 and
                              not any(method == 'POST' and 'agent-scene.php' in url for method, url in requests),
                              'Catalog Open, guest playback and private Remix make no model request or token charge')
                        check(not errors, 'Actual app callbacks and the production renderer complete without browser exceptions')
                    finally:
                        browser.close()
            except BaseException:
                log.seek(0);print(log.read()[-6500:]);raise
            finally:
                os.killpg(server.pid, signal.SIGTERM);server.wait()
    print(f'Canvas gallery integration: {len(checks)} native API and browser checks passed', flush=True)


if __name__ == '__main__':
    main()
