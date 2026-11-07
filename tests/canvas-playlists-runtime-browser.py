"""Actual PHP authorization and native audio through production host callbacks.

This is an isolated host fixture, not full application E2E. No provider is used.
"""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
spec = importlib.util.spec_from_file_location('playlist_runtime_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)
APP = (ROOT / 'assets/js/app.js').read_text()
checks = []


def block(start, end):
    offset = APP.index(start)
    return APP[offset:APP.index(end, offset)]


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


SETUP = '''
window.audio=document.getElementById('canonical');window.footerPlayer=document.getElementById('player');
window.st={auth:{authenticated:false,user:null,csrf:''},track:null,playing:false,playlistPlayback:{}};
window.cfg={apiBase:'api'};window.pendingNetworkRecovery=null;window.playbackWantsAudio=false;
window.songCaptureSkill=null;window.songListeningSkill=null;window.fixtureOwner='';window.listeningRoom=fixtureScene;
window.playbackLifecycle=StonefellowPlaybackLifecycle.create();window.results={};window.target='song-1';
window.canvasOwner=()=>fixtureOwner;window.canvasSceneOwner=()=>fixtureOwner;window.isGalleryScene=()=>false;
window.trackById=id=>fixtureTracks.find(track=>track.id===id);window.refreshSiteData=async()=>{};window.requirePublishedCanvas=async()=>{};
window.playerVolume=()=>audio.volume;window.savedPlaybackPosition=()=>audio.currentTime||0;
window.cancelNetworkRecovery=()=>{pendingNetworkRecovery=null;};window.clearPlaybackBufferWatch=()=>{};
window.setPlaybackStatus=()=>{};window.clearPlaylistPlayback=()=>{};window.updateQueueUi=()=>{};window.updateFooterPlayer=()=>{};
window.rememberPlaybackPosition=()=>{};window.agent=()=>{};window.playbackProblem=()=>{};window.invalidatePlayback=()=>{};
window.login=async()=>{
 const initial=await fetch('api/auth.php').then(r=>r.json());
 const response=await fetch('api/auth.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':initial.csrf},
  body:JSON.stringify({action:'login',email:'owner@example.invalid',password:'fixture-only-password'})});
 const result=await response.json();if(!response.ok)throw Error('Fixture login failed');
 st.auth={authenticated:true,user:result.user,csrf:result.csrf};fixtureOwner=String(result.user.id);SFListeningTelemetry.setOwner();
};
'''

with tempfile.TemporaryDirectory(prefix='stoneward-playlist-runtime-ui-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    seeded = json.loads(fixture.cli(site, '''
        require_once SF_ROOT.'/api/agent-scene-core.php';require_once SF_ROOT.'/api/canvas-playlists-core.php';
        sf_privacy_ensure_schema();sf_ops_ensure_schema();
        $playlist=sf_playlist_create(1,'Runtime private sequence','Private description','private','user',['song-1','song-2','song-1']);
        $item=sf_canvas_playlists_inventory(1)[0];$inputs=['items'=>[['ref'=>$item['ref'],'version'=>$item['revision_hash']]]];
        $manifest=sf_canvas_playlists_compile(1,$inputs)['manifest'];
        $scene=['canvas_kind'=>'custom','track_id'=>'','title'=>'Runtime musical forms','html'=>'<section class="forms"></section>','css'=>'.forms{color:#abc}',
            'music_inputs'=>sf_canvas_playlists_effective_music(null,$manifest),'direct_music_inputs'=>null,'playlist_inputs'=>$inputs,'playlist_manifest'=>$manifest];
        $saved=sf_agent_scene_history_save(1,$scene);echo json_encode(['playlist_id'=>$playlist['id'],'scene'=>sf_agent_scene_saved(1,$saved['id'])]);
    '''))
    tracks = [{'id': 'song-' + str(index), 'title': 'Song ' + str(index), 'duration': 6, 'audio': ''} for index in (1, 2)]
    html = '<!doctype html><meta charset="utf-8"><style>body{font:16px system-ui}button{min-height:44px;margin:8px}</style><button id="catalog">Read catalog</button><button id="source">Load MP3</button><button id="play">Play song</button><div id="player"><audio id="canonical"></audio></div>'
    html += '<script>window.fixtureScene=' + json.dumps(seeded['scene']).replace('<', '\\u003c') + ';window.fixtureTracks=' + json.dumps(tracks) + ';</script>'
    for name in ('player-playback-lifecycle.js', 'listening-telemetry.js'):
        html += '<script src="assets/js/' + name + '"></script>'
    api_start = APP.index('  async function api(')
    api = APP[api_start:APP.index('\n', api_start)]
    html += '<script>' + SETUP + api + block('  function ensureListenSession(){', '  function fmtWhen(') + block('  function canvasAudioState(){', '  async function renderAgentCanvas(){') + block('  function playbackEndedEarly(){', '  function rememberPlaybackPosition(){') + block('  function play(t,', '  function seekPlayback(') + '''
    listeningTelemetryMain();
    const run=async(kind,operation)=>{results[kind]='pending';try{results[kind]={ok:true,value:await operation()};}catch(error){results[kind]={ok:false,code:error.code||'rejected'};}};
    document.getElementById('catalog').onclick=()=>run('catalog',()=>loadCanvasAudioCatalog({scene_id:999,gallery_id:'f'.repeat(32)}));
    document.getElementById('source').onclick=()=>run('source',async()=>{const value=await loadCanvasAudioSource(target);return {bytes:value.blob.size,track:value.track.track_id};});
    document.getElementById('play').onclick=()=>run('play',()=>playCanvasAudio(target));
    </script>'''
    (site / 'playlist-runtime.html').write_text(html)
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)

    def restore():
        fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET name=?,user_id=? WHERE id=?')->execute(['Runtime private sequence',1," + str(seeded['playlist_id']) + "]); ")

    def mutate(kind):
        if kind == 'version':
            fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET name=? WHERE id=?')->execute(['Changed source revision'," + str(seeded['playlist_id']) + "]); ")
        else:
            fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET user_id=? WHERE id=?')->execute([2," + str(seeded['playlist_id']) + "]); ")

    with (site / 'php.log').open('w+') as log:
        server = subprocess.Popen([PHP, '-d', 'session.save_path=' + str(site / 'sessions'), '-S', '127.0.0.1:' + str(port), '-t', str(site)], stdout=log, stderr=log, start_new_session=True)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)
            with sync_playwright() as playwright:
                options = {'headless': True, 'args': ['--no-sandbox', '--autoplay-policy=no-user-gesture-required']}
                executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
                if executable:
                    options['executable_path'] = executable
                browser = playwright.chromium.launch(**options)
                for width in (390, 1440):
                    restore()
                    context = browser.new_context(viewport={'width': width, 'height': 850}, has_touch=width == 390)
                    page = context.new_page()
                    requests, reports, errors = [], [], []
                    source_race = {'kind': None}

                    def capture(request):
                        if '/api/canvas-audio.php' in request.url:
                            requests.append(request.url)
                        if '/api/listening.php' in request.url and request.method == 'POST':
                            reports.append(request.post_data_json)

                    def source_route(route):
                        if source_race['kind']:
                            mutate(source_race['kind'])
                            source_race['kind'] = None
                        route.continue_()

                    page.on('request', capture)
                    page.on('pageerror', lambda error: errors.append(str(error)))
                    page.route('**/api/canvas-audio.php?action=source*', source_route)
                    page.goto(base + '/playlist-runtime.html')
                    page.evaluate('login()')
                    prefix = f'chromium/{width}: '

                    def run(kind):
                        control = page.locator('#' + kind)
                        control.tap() if width == 390 else control.click()
                        page.wait_for_function('kind=>results[kind]!=="pending"', arg=kind)
                        return page.evaluate('kind=>results[kind]', kind)

                    catalog = run('catalog')
                    check(catalog['ok'] and [item['track_id'] for item in catalog['value']['tracks']] == ['song-1', 'song-2'] and len(catalog['value']['playlists'][0]['items']) == 3, prefix + 'real host catalog returns server-authorized tracks and ordered duplicate playlist entries')
                    check(run('source')['ok'], prefix + 'context-bound host MP3 source loads through actual authenticated PHP')
                    with page.expect_request(lambda request:
                        '/api/listening.php' in request.url and request.method == 'POST'
                        and request.post_data_json.get('action') == 'begin'
                        and request.post_data_json.get('track_id') == 'song-1'
                    ):
                        check(run('play')['ok'], prefix + 'production canonical player starts the authorized contextual MP3')
                        page.wait_for_function('!audio.paused&&audio.currentTime>.2')
                    page.evaluate('audio.pause()')
                    start_reports = [row for row in reports if row.get('action') == 'begin']
                    starts = len(start_reports)
                    check(starts == 1 and start_reports[0].get('context') == {'scene_id': seeded['scene']['id']},
                          prefix + 'one native authorized play creates exactly one verified start attributed to its scene')
                    for kind in ('version', 'owner'):
                        restore()
                        mutate(kind)
                        page.evaluate("target='song-2'")
                        before = page.evaluate('({src:audio.getAttribute("src"),track:st.track?.id})')
                        check(not run('catalog')['ok'] and not run('source')['ok'] and not run('play')['ok'] and page.evaluate('({src:audio.getAttribute("src"),track:st.track?.id})') == before, prefix + kind + ' drift rejects fresh catalog, MP3 and play without using cached private manifest')
                    restore()
                    source_race['kind'] = 'owner'
                    check(not run('source')['ok'], prefix + 'source endpoint revalidates ownership after a successful metadata read')
                    restore()
                    source_race['kind'] = 'version'
                    check(not run('play')['ok'], prefix + 'native main-player URL revalidates the source revision before delivering new audio bytes')
                    page.wait_for_timeout(100)
                    check(len([row for row in reports if row.get('action') == 'begin']) == starts, prefix + 'all rejected reads and native starts add no verified plays')
                    check(requests and all(('scene_id=' + str(seeded['scene']['id'])) in url and 'gallery_id=' not in url and 'scene_id=999' not in url for url in requests), prefix + 'every catalog and source request carries only immutable host scene authority')
                    check(not errors, prefix + 'real callback fixture has no JavaScript exceptions: ' + str(errors))
                    context.close()
                browser.close()
        except BaseException:
            log.seek(0)
            print(log.read()[-7000:])
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)

print(f'PASS {len(checks)} actual PHP/native runtime playlist checks; no paid calls', flush=True)
