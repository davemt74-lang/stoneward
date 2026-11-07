"""Exercise the native lyrics picker with the production draft handlers."""
import json
import os
from pathlib import Path
import shutil

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'assets/js/app.js').read_text()


def block(start, end):
    offset = APP.index(start)
    return APP[offset:APP.index(end, offset)]


COMPOSER = block('  let chatMusicPicker=null,chatMusicCommandTimer=null;', '  function chatImageCommandRange(')
SUBMIT = block('  function submitAgentChat(', '  function canvasBriefInChat(')
DESIGN = block('  function canvasDesignBrief(', '  let manualAgentHidden=')
HTML = ('<!doctype html><html><body><form id="chat">'
        '<label>Prompt<input id="composer" maxlength="2000"></label>'
        '<button type="submit">Send</button></form></body></html>')
INVENTORY = [
    {'id': 'track-Timed', 'title': '<b>Timed song</b>', 'release': 'Live recordings', 'timed': True},
    {'id': 'track-Plain', 'title': 'Plain song', 'timed': False},
    {'id': 'track-Timed', 'title': 'Duplicate'},
    {'id': 'invalid id', 'title': 'Invalid ID'},
]
SETUP = """() => {
  window.input=document.querySelector('#composer');
  window.owner='';window.canvasOwner=()=>owner;window.loadAuth=async()=>{};
  window.chatImagePicker=null;window.chatImageDraftLimit=2000;window.chatImageReferenceLimit=4;
  window.listeningRoom=null;
  window.popupEntry=(_kind,text)=>{throw Error(text)};
  window.command=()=>{throw Error('Picker submitted a build')};
  window.api=async(path,body,signal)=>{
    if(body!==undefined)throw Error('Expected a read-only request');
    const response=await fetch('/api/'+path,{signal});
    return response.json();
  };
}"""


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

        def route(route):
            request = route.request
            requests.append((request.method, request.url))
            if request.url == 'https://lyrics.test/':
                route.fulfill(content_type='text/html', body=HTML)
            elif request.url == 'https://lyrics.test/api/canvas-resources.php?kind=lyrics':
                route.fulfill(content_type='application/json', body=json.dumps({'ok': True, 'items': INVENTORY}))
            else:
                route.abort()

        page.route('**/*', route)
        page.goto('https://lyrics.test/')
        page.add_style_tag(content=(ROOT / 'assets/css/canvas-resource-picker.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-resource-picker.js').read_text())
        page.evaluate(SETUP)
        page.add_script_tag(content=COMPOSER + SUBMIT + DESIGN +
                            "bindChatResourcePicker();document.querySelector('#chat').onsubmit=submitAgentChat;")

        composer = page.get_by_label('Prompt')
        picker = page.get_by_role('dialog', name='Choose lyrics', exact=True)
        timed = picker.get_by_role('checkbox', name='<b>Timed song</b>', exact=True)
        plain = picker.get_by_role('checkbox', name='Plain song', exact=True)
        search = picker.get_by_role('searchbox', name='Search songs with lyrics')
        add = picker.get_by_role('button', name='Add selections')

        def activate(control):
            control.tap() if width == 390 else control.click()

        def open_picker(draft):
            composer.fill(draft)
            composer.press('Enter')
            expect(picker).to_be_visible()
            expect(search).to_be_focused()
            expect(plain).to_be_visible()

        open_picker('/lyrics')
        assert not picker.locator('b').count() and not picker.get_by_text('Invalid ID').count()
        assert not picker.get_by_text('Duplicate').count()
        expect(timed).to_have_accessible_description('Live recordings · Timed lyrics available')
        expect(plain).to_have_accessible_description('Plain lyrics')
        search.fill('live recordings')
        expect(plain).not_to_be_visible()
        activate(timed)
        search.fill('plain song')
        activate(plain)
        activate(add)
        expect(composer).to_be_focused()
        expect(composer).to_have_value('{lyrics:track-Timed} {lyrics:track-Plain}')
        assert page.evaluate('chatDraftResourceInputs(input.value)') == {
            'artist_inputs': {'lyrics_ids': ['track-Timed', 'track-Plain']}}
        assert page.evaluate('canvasDesignBrief(input.value)===""&&input.maxLength>2000')

        page.evaluate("owner='1'; listeningRoom={id:4,artist_inputs:{lyrics_ids:['track-Timed']}}")
        open_picker('/edit Recolor the lyrics {music-track:track-Timed} {merch:hoodie} /lyrics')
        expect(timed).to_be_checked()
        activate(timed)
        activate(plain)
        activate(add)
        expect(composer).to_have_value('/edit Recolor the lyrics {music-track:track-Timed} {merch:hoodie} {lyrics:track-Plain}')

        page.evaluate("listeningRoom.artist_inputs.lyrics_ids=['withdrawn-track']")
        open_picker('/edit /lyrics')
        unavailable = picker.get_by_role('checkbox', name='Unavailable selected lyrics', exact=True)
        expect(unavailable).to_be_checked()
        expect(add).to_be_disabled()
        activate(unavailable)
        activate(add)
        expect(composer).to_have_value('/edit {lyrics-clear}')
        assert page.evaluate('chatDraftResourceInputs(input.value)') == {'artist_inputs': {'lyrics_ids': []}}
        open_picker('/edit {lyrics-clear} /lyrics')
        expect(unavailable).not_to_be_visible()
        search.press('Escape')
        expect(composer).to_have_value('/edit {lyrics-clear} /lyrics')
        expect(composer).to_be_focused()

        open_picker('/edit /lyrics')
        activate(unavailable)
        activate(plain)
        page.evaluate('listeningRoom={id:5}')
        activate(add)
        expect(composer).to_have_value('/edit /lyrics')
        assert not errors and requests == [
            ('GET', 'https://lyrics.test/'),
            *[('GET', 'https://lyrics.test/api/canvas-resources.php?kind=lyrics')] * 5,
        ]
        print(f'PASS chromium/{width}: lyric selection, independent bindings, clearing, and stale scene protection', flush=True)
        context.close()
    browser.close()
