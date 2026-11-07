"""Native playlist selection through the production composer and picker.

Inventory transport is deterministic here. PHP authorization/version compilation is
covered separately by canvas-playlists-http.py; this is not full application E2E.
"""
import json
import os
from pathlib import Path
import shutil
import sys

sys.dont_write_bytecode = True
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'assets/js/app.js').read_text()
start = APP.index('  let chatMusicPicker=null,chatMusicCommandTimer=null;')
COMPOSER = APP[start:APP.index('  function chatImageCommandRange(', start)]
start = APP.index('  function syncAuthMenu(){')
AUTH = APP[start:APP.index('  async function loadAuth(', start)]
A, B, C = 'a' * 64, 'b' * 64, 'c' * 64
OWN = {'id': 'own-17', 'source': 'own', 'title': 'My ordered sequence', 'revision_hash': A,
       'description': 'Private to my account', 'items': [
           {'item_id': 1, 'position': 0, 'track_id': 'song-a', 'title': 'Earth Girl', 'available': True, 'duration_seconds': 283},
           {'item_id': 2, 'position': 1, 'track_id': 'song-a', 'title': 'Earth Girl', 'available': True, 'duration_seconds': 283},
           {'item_id': 3, 'position': 2, 'track_id': None, 'title': None, 'available': False},
           {'item_id': 4, 'position': 3, 'track_id': 'song-b', 'title': 'Vacation Brain', 'available': True, 'duration_seconds': 233}]}
ARTIST = {'id': 'artist-42', 'source': 'artist', 'title': '<b>Published artist sequence</b>', 'revision_hash': B,
          'description': 'An <img src=x onerror=alert(1)> artist note', 'items': [
              {'item_id': 7, 'position': 0, 'track_id': 'song-c', 'title': 'Space Walk', 'available': True, 'duration_seconds': 321}]}
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


SETUP = r'''() => {
 window.owner='1';window.st={auth:{authenticated:true}};window.input=document.querySelector('#composer');
 window.chatImagePicker=null;window.chatImageReferenceLimit=4;window.chatImageDraftLimit=2000;
 window.listeningRoom={id:4,playlist_inputs:{items:[{ref:'own-17',version:'c'.repeat(64)}]}};
 window.notices=[];window.apiCalls=[];window.holdInventory=false;
 window.loadAuth=async()=>{};window.canvasOwner=()=>owner;window.canvasSceneOwner=()=>owner;
 window.popupEntry=(kind,text)=>notices.push({kind,text});
 window.directListeningRoomCommand=draft=>/^\/edit\b/i.test(draft)?{kind:'revise'}:/^let.s build/i.test(draft)?{kind:'create'}:null;
 window.api=async(url,body,signal)=>{
   const captured=owner;apiCalls.push({url,body,signal});
   if(holdInventory){holdInventory=false;await new Promise(resolve=>window.releaseInventory=resolve)}
   const response=await fetch('/api/'+url,{signal,headers:{'X-Fixture-Owner':captured}});
   const result=await response.json();if(!response.ok)throw Error(result.message||'Inventory failed');return result;
 };
 window.syncCanvasDebugOwner=()=>{};window.accountPhotoPreferencesRequest=null;window.stopListeningRoomBuild={};
 window.canvasImageEditDraft=null;window.gallerySceneRequest=null;window.isGalleryScene=()=>true;
 window.listeningRoomOwner=null;window.closeListeningRoom=()=>{};window.canvasGalleryView=null;
 window.canvasAccess={};window.syncCanvasCodingModelMenu=()=>{};window.canvasProjects=()=>null;
}'''

with sync_playwright() as playwright:
    options = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        options['executable_path'] = executable
    browser = playwright.chromium.launch(**options)
    for width in (390, 1440):
        context = browser.new_context(viewport={'width': width, 'height': 850}, has_touch=width == 390)
        page = context.new_page()
        errors, requests = [], []
        page.on('pageerror', lambda error: errors.append(str(error)))
        inventory = [dict(OWN), dict(ARTIST)]
        behavior = {'mode': 'ok'}

        def route(request_route):
            request = request_route.request
            requests.append({'url': request.url, 'method': request.method})
            if '/api/canvas-resources.php?kind=playlists' in request.url:
                mode = behavior['mode']
                behavior['mode'] = 'ok'
                if mode == 'fail':
                    request_route.fulfill(status=503, content_type='application/json', body=json.dumps({'message': 'Inventory temporarily unavailable'}))
                    return
                rows = inventory if request.headers.get('x-fixture-owner') else [row for row in inventory if row['source'] == 'artist']
                if mode == 'bad-version':
                    rows = [{**ARTIST, 'revision_hash': 'unverified'}]
                request_route.fulfill(status=200, content_type='application/json', body=json.dumps({'ok': True, 'items': rows}))
            elif '/api/canvas-audio.php?action=catalog' in request.url:
                request_route.fulfill(status=200, content_type='application/json', body=json.dumps({'ok': True, 'tracks': [
                    {'track_id': 'song-a', 'title': 'Earth Girl', 'duration_seconds': 283, 'source_available': True},
                    {'track_id': 'song-b', 'title': 'Vacation Brain', 'duration_seconds': 233, 'source_available': True}], 'releases': []}))
            elif request.url == 'https://playlists.test/':
                request_route.fulfill(status=200, content_type='text/html', body='<!doctype html><html><body><label>Prompt<textarea id="composer"></textarea></label><button id="outside">Outside</button></body></html>')
            else:
                request_route.abort()

        page.route('https://playlists.test/**', route)
        page.goto('https://playlists.test/')
        page.add_style_tag(content='body{margin:0;font:16px system-ui}textarea{font:inherit;min-height:80px}' + (ROOT / 'assets/css/canvas-resource-picker.css').read_text() + (ROOT / 'assets/css/canvas-music-picker.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-resource-picker.js').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-music-picker.js').read_text())
        page.evaluate(SETUP)
        page.add_script_tag(content=COMPOSER + AUTH + '\nbindChatResourcePicker();bindChatMusicPicker();')
        prefix = f'chromium/{width}: '
        composer = page.locator('#composer')

        def open_picker(draft='/playlists'):
            composer.fill(draft)
            picker = page.get_by_role('dialog', name='Choose playlists', exact=True)
            expect(picker).to_be_visible()
            return picker

        def activate(locator):
            locator.tap() if width == 390 else locator.click()

        picker = open_picker("Let's build a sequence {music-track:direct} /playlists")
        expect(picker.get_by_label('Search playlists and tracks', exact=True)).to_be_focused()
        own = picker.get_by_role('checkbox', name=OWN['title'], exact=True)
        artist = picker.get_by_role('checkbox', name=ARTIST['title'], exact=True)
        expect(own).not_to_be_checked()
        summary = picker.locator('summary').first
        activate(summary)
        expect(own).not_to_be_checked()
        members = picker.locator('details').first.locator('li').all_text_contents()
        check(members == ['Earth Girl · 4:43', 'Earth Girl · 4:43', 'Unavailable track · Not currently available', 'Vacation Brain · 3:53'], prefix + 'ordered membership retains repeated songs and generic unavailable positions')
        check(picker.locator('b,img,audio').count() == 0 and '3 of 4 tracks available' in picker.inner_text(), prefix + 'metadata stays plain text with availability counts and no player widget')
        if width == 390:
            activate(own)
        else:
            own.focus()
            own.press('Space')
        expect(own).to_be_checked()
        picker.get_by_label('Search playlists and tracks', exact=True).fill('Space Walk')
        expect(picker.get_by_role('checkbox')).to_have_count(1)
        activate(artist)
        picker.get_by_label('Search playlists and tracks', exact=True).fill('')
        expect(own).to_be_checked()
        activate(picker.get_by_role('button', name='Add selections', exact=True))
        expect(picker).not_to_be_visible()
        check(composer.input_value() == "Let's build a sequence {music-track:direct} {playlist:own-17@" + A + '} {playlist:artist-42@' + B + '}', prefix + 'native selection adds stable revision tokens and preserves independent music without starting a build')
        expect(composer).to_be_focused()

        page.evaluate("listeningRoom.direct_music_inputs={mode:'selected',track_ids:['song-a']};listeningRoom.music_inputs={mode:'selected',track_ids:['song-a','song-b']}")
        playlist_token = '{playlist:own-17@' + A + '}'
        composer.fill('/edit ' + playlist_token + ' /music')
        music_picker = page.get_by_role('dialog', name='Choose canvas music', exact=True)
        expect(music_picker).to_be_visible()
        expect(music_picker.get_by_role('checkbox', name='Earth Girl', exact=True)).to_be_checked()
        expect(music_picker.get_by_role('checkbox', name='Vacation Brain', exact=True)).not_to_be_checked()
        activate(music_picker.get_by_role('checkbox', name='Earth Girl', exact=True))
        activate(music_picker.get_by_role('button', name='Clear song selection', exact=True))
        check(composer.input_value() == '/edit ' + playlist_token + ' {music-clear}', prefix + 'native direct music removal emits explicit clear while retaining the playlist version')
        page.evaluate('listeningRoom.direct_music_inputs=null')
        composer.fill('/edit /music')
        expect(music_picker).to_be_visible()
        expect(music_picker.get_by_role('button', name='Add songs', exact=True)).to_be_disabled()
        activate(music_picker.get_by_role('checkbox', name='Earth Girl', exact=True))
        activate(music_picker.get_by_role('checkbox', name='Earth Girl', exact=True))
        expect(music_picker.get_by_role('button', name='Clear song selection', exact=True)).to_be_enabled()
        activate(music_picker.get_by_role('button', name='Cancel', exact=True))
        check(composer.input_value() == '/edit /music', prefix + 'untouched empty direct scope stays disabled; only a user selection change enables explicit clearing')

        picker = open_picker('/edit /playlists')
        expect(picker.get_by_role('checkbox', name=OWN['title'], exact=True)).to_be_checked()
        expect(picker.get_by_text('Updated since your selected version. Adding selections uses the version shown here.', exact=True)).to_be_visible()
        activate(picker.get_by_role('button', name='Cancel', exact=True))
        check(composer.input_value() == '/edit /playlists', prefix + 'revision changes are disclosed and cancel keeps the original draft and inherited version')

        page.evaluate("listeningRoom.playlist_inputs={items:[{ref:'own-99',version:'a'.repeat(64)}]}")
        picker = open_picker('/edit /playlists')
        missing = picker.get_by_role('checkbox', name='Unavailable selected playlist', exact=True)
        expect(missing).to_be_checked()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_disabled()
        activate(missing)
        activate(picker.get_by_role('button', name='Add selections', exact=True))
        check(composer.input_value() == '/edit {playlists-clear}', prefix + 'missing selected playlists require an explicit removal before applying empty scope')

        behavior['mode'] = 'fail'
        picker = open_picker()
        expect(picker.get_by_role('button', name='Try again', exact=True)).to_be_visible()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_disabled()
        activate(picker.get_by_role('button', name='Try again', exact=True))
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_enabled()
        picker.press('Escape')
        check(composer.input_value() == '/playlists', prefix + 'inventory failure cannot clear selections and native Escape cancels after retry')
        behavior['mode'] = 'bad-version'
        picker = open_picker()
        expect(picker.get_by_role('button', name='Try again', exact=True)).to_be_visible()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_disabled()
        activate(picker.get_by_role('button', name='Cancel', exact=True))
        check(composer.input_value() == '/playlists', prefix + 'unverified inventory revisions cannot be attached')

        page.evaluate("owner='';st.auth.authenticated=false;syncAuthMenu()")
        picker = open_picker()
        expect(picker.get_by_text('Sign in to choose your own playlists. Published artist playlists are available below. Selection does not play music or start a build.', exact=True)).to_be_visible()
        expect(picker.get_by_role('checkbox')).to_have_count(1)
        activate(picker.get_by_role('checkbox', name=ARTIST['title'], exact=True))
        activate(picker.get_by_role('button', name='Add selections', exact=True))
        check(composer.input_value() == '{playlist:artist-42@' + B + '}', prefix + 'signed-out selection shows approved artist playlists without exposing account playlists')

        page.evaluate("owner='1';st.auth.authenticated=true;holdInventory=true;syncAuthMenu()")
        picker = open_picker()
        page.wait_for_function('typeof releaseInventory==="function"')
        page.evaluate("owner='2';syncAuthMenu();releaseInventory()")
        expect(picker).not_to_be_visible()
        check(composer.input_value() == '/playlists' and page.evaluate('apiCalls.at(-1).signal.aborted'), prefix + 'production account change hook cancels the open picker and ignores late private inventory')

        inventory.extend({**ARTIST, 'id': 'artist-' + str(i), 'title': 'Artist sequence ' + str(i)} for i in range(100, 145))
        picker = open_picker()
        expect(picker.get_by_role('checkbox')).to_have_count(47)
        geometry = picker.evaluate('''dialog=>({fits:dialog.scrollWidth<=dialog.clientWidth&&dialog.scrollHeight<=dialog.clientHeight,
          footer:dialog.querySelector('footer').getBoundingClientRect().bottom<=innerHeight-10,
          scrollers:[dialog,...dialog.querySelectorAll('*')].filter(el=>['auto','scroll'].includes(getComputedStyle(el).overflowY)&&el.scrollHeight>el.clientHeight).length})''')
        check(geometry['fits'] and geometry['footer'] and geometry['scrollers'] == 1, prefix + 'long playlist inventories retain one scroller with accessible fixed search and footer')
        activate(picker.get_by_role('button', name='Cancel', exact=True))
        check(not errors and not page.locator('audio,iframe').count() and all(item['method'] == 'GET' and ('canvas-resources.php?kind=playlists' in item['url'] or 'canvas-audio.php?action=catalog' in item['url'] or item['url'] == 'https://playlists.test/') for item in requests), prefix + 'all selection paths make only inventory GET requests with no playback, build or paid transport')
        context.close()
    browser.close()

print(f'PASS {len(checks)} native playlist picker checks', flush=True)
