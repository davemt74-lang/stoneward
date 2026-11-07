"""Native story selection through the production picker and composer.

The inventory response is a fixture. PHP publication authorization is tested
separately from this browser's draft selection and account lifecycle checks.
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
start = APP.index('  function canvasDesignBrief(')
DESIGN = APP[start:APP.index('  let manualAgentHidden=', start)]
start = APP.index('  function submitAgentChat(')
SUBMIT = APP[start:APP.index('  function canvasBriefInChat(', start)]
start = APP.index('  function syncAuthMenu(){')
AUTH = APP[start:APP.index('  async function loadAuth(', start)]
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


SETUP = r'''() => {
 window.owner='1';window.st={auth:{authenticated:true}};
 window.input=document.querySelector('#composer');
 window.chatImagePicker=null;window.chatImageReferenceLimit=4;window.chatImageDraftLimit=2000;
 window.listeningRoom={id:4,artist_inputs:{stories:{mode:'tag',tag:'behind-the-song'}}};
 window.notices=[];window.apiCalls=[];window.holdInventory=false;
 window.loadAuth=async()=>{};window.canvasOwner=()=>owner;window.canvasSceneOwner=()=>owner;
 window.popupEntry=(kind,text)=>notices.push({kind,text});
 window.api=async(url,body,signal)=>{
   apiCalls.push({url,body,signal});
   if(holdInventory){holdInventory=false;await new Promise(resolve=>window.releaseInventory=resolve)}
   const response=await fetch('/api/'+url,{signal});
   return response.json();
 };
 window.syncCanvasDebugOwner=()=>{};window.accountPhotoPreferencesRequest=null;window.stopListeningRoomBuild={};
 window.canvasImageEditDraft=null;window.gallerySceneRequest=null;window.isGalleryScene=()=>true;
 window.listeningRoomOwner=null;window.closeListeningRoom=()=>{};window.canvasGalleryView=null;
 window.canvasAccess={};window.syncCanvasCodingModelMenu=()=>{};window.canvasProjects=()=>null;
}'''

inventory = [
    {'id': '1', 'title': '<b>Recording Earth Girl</b>', 'description': '<img onerror=alert(1)> A published essay.',
     'category': 'Essay', 'tags': ['behind-the-song', 'studio'], 'image_url': 'https://external.test/image'},
    {'id': '2', 'title': 'First vinyl', 'description': 'An early release.', 'tags': ['behind-the-song-notes']},
    *[{'id': str(i), 'title': f'Story {i}', 'tags': ['behind-the-song'] if i == 60 else []} for i in range(3, 61)],
    {'id': '1', 'title': 'Duplicate'},
    {'id': '9223372036854775808', 'title': 'Invalid ID'},
]
html = ('<!doctype html><html><body><form id="chat">'
        '<label>Prompt<input id="composer" type="text" maxlength="2000"></label>'
        '<button type="submit">Send</button></form></body></html>')

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

        def route(request_route):
            request = request_route.request
            requests.append((request.method, request.url))
            if request.url == 'https://stories.test/':
                request_route.fulfill(content_type='text/html', body=html)
            elif request.url == 'https://stories.test/api/canvas-resources.php?kind=stories':
                request_route.fulfill(content_type='application/json', body=json.dumps({'ok': True, 'items': inventory}))
            else:
                request_route.abort()

        page.route('**/*', route)
        page.goto('https://stories.test/')
        page.add_style_tag(content='body{margin:0;font:16px system-ui}input{font:inherit}' +
                           (ROOT / 'assets/css/canvas-resource-picker.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-resource-picker.js').read_text())
        page.evaluate(SETUP)
        page.add_script_tag(content=COMPOSER + DESIGN + SUBMIT + AUTH +
                            "\nbindChatResourcePicker();document.querySelector('#chat').onsubmit=submitAgentChat;")
        composer = page.locator('#composer')
        prefix = f'chromium/{width}: '

        def open_picker(draft):
            composer.fill(draft)
            composer.press('Enter')
            picker = page.get_by_role('dialog', name='Choose stories', exact=True)
            expect(picker).to_be_visible()
            expect(picker.get_by_role('checkbox', name='All Stories', exact=True)).to_be_visible()
            return picker

        def activate(locator):
            locator.tap() if width == 390 else locator.click()

        def apply(picker, return_focus=composer):
            activate(picker.get_by_role('button', name='Add selections', exact=True))
            expect(picker).not_to_be_visible()
            expect(return_focus).to_be_focused()

        def selection():
            return page.evaluate('chatDraftResourceInputs(input.value).artist_inputs')

        for draft in ('/stories', '/stories-behind', '/stories-behind-the'):
            composer.fill(draft)
            assert page.evaluate('chatResourceCommandTimer===null && apiCalls.length===0 && !document.querySelector("dialog")')
        check(True, prefix + 'typing story command prefixes schedules no automatic picker or request')

        picker = open_picker("/edit {music-all} {merch:hoodie} {story:2} /stories")
        all_stories = picker.get_by_role('checkbox', name='All Stories', exact=True)
        search = picker.get_by_label('Search stories', exact=True)
        expect(search).to_be_focused()
        expect(picker.get_by_role('checkbox', name='First vinyl', exact=True)).to_be_checked()
        expect(picker.get_by_role('checkbox')).to_have_count(61)
        check(picker.locator('b,img,audio,iframe').count() == 0, prefix + 'inventory text stays literal and foreign preview URLs are ignored')
        geometry = picker.evaluate('''dialog=>({fits:dialog.scrollWidth<=dialog.clientWidth&&dialog.scrollHeight<=dialog.clientHeight,
          footer:dialog.querySelector('footer').getBoundingClientRect().bottom<=innerHeight-10,
          scrollers:[dialog,...dialog.querySelectorAll('*')].filter(el=>['auto','scroll'].includes(getComputedStyle(el).overflowY)&&el.scrollHeight>el.clientHeight).length})''')
        check(geometry['fits'] and geometry['footer'] and geometry['scrollers'] == 1, prefix + 'full inventory retains one scroller and a visible footer')
        search.fill('does not match')
        expect(picker.get_by_role('checkbox')).to_have_count(1)
        activate(all_stories)
        expect(all_stories).to_be_checked()
        search.fill('')
        expect(picker.get_by_role('checkbox', name='First vinyl', exact=True)).not_to_be_checked()
        apply(picker)
        check(selection() == {'stories': {'mode': 'all'}, 'merch_ids': ['hoodie']} and
              '{music-all}' in composer.input_value() and '{story:2}' not in composer.input_value() and
              composer.input_value().count('{stories-all}') == 1, prefix + 'global All Stories stays independent of search and other resource choices')

        picker = open_picker('/edit {stories-all} /stories')
        all_stories = picker.get_by_role('checkbox', name='All Stories', exact=True)
        expect(all_stories).to_be_checked()
        activate(picker.get_by_role('checkbox', name='Story 60', exact=True))
        expect(all_stories).not_to_be_checked()
        activate(picker.get_by_role('checkbox', name=inventory[0]['title'], exact=True))
        apply(picker)
        check(selection() == {'stories': {'mode': 'selected', 'story_ids': ['60', '1']}},
              prefix + 'individual picks replace the live scope and preserve selection order')
        check(page.evaluate("canvasDesignBrief(input.value)") == '/edit' and page.evaluate('input.maxLength') > 2000,
              prefix + 'story bindings stay out of design text and receive composer length allowance')

        page.evaluate("owner='';st.auth.authenticated=false;syncAuthMenu()")
        composer.fill('/STORIES-behind-the-song')
        send = page.get_by_role('button', name='Send', exact=True)
        send.click()
        picker = page.get_by_role('dialog', name='Choose stories', exact=True)
        expect(picker.get_by_text('Showing stories tagged “behind-the-song”', exact=True)).to_be_visible()
        expect(picker.get_by_role('button', name='Clear tag filter', exact=True)).not_to_be_visible()
        expect(picker.get_by_role('checkbox')).to_have_count(3)
        expect(picker.get_by_role('checkbox', name='First vinyl', exact=True)).not_to_be_visible()
        activate(picker.get_by_role('checkbox', name='All Stories', exact=True))
        apply(picker, return_focus=send)
        check(composer.input_value() == '{stories-tag:behind-the-song}' and
              selection() == {'stories': {'mode': 'tag', 'tag': 'behind-the-song'}},
              prefix + 'a guest can attach every story with one exact tag using the normal composer submit')

        page.evaluate("owner='1';st.auth.authenticated=true;syncAuthMenu()")
        picker = open_picker('/edit /stories')
        all_stories = picker.get_by_role('checkbox', name='All Stories', exact=True)
        expect(all_stories).to_be_checked()
        expect(picker.get_by_text('Showing stories tagged “behind-the-song”', exact=True)).to_be_visible()
        activate(picker.get_by_role('button', name='Clear tag filter', exact=True))
        expect(all_stories).not_to_be_checked()
        expect(picker.get_by_role('checkbox')).to_have_count(61)
        activate(all_stories)
        apply(picker)
        check(selection() == {'stories': {'mode': 'all'}}, prefix + 'reopening a tag retains its scope until the user clears the filter and chooses global All Stories')

        page.evaluate("listeningRoom.artist_inputs.stories={mode:'selected',story_ids:['999']}")
        picker = open_picker('/edit /stories')
        missing = picker.get_by_role('checkbox', name='Unavailable selected story', exact=True)
        expect(missing).to_be_checked()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_disabled()
        activate(missing)
        apply(picker)
        check(selection() == {'stories': {'mode': 'selected', 'story_ids': []}}, prefix + 'removing an archived selection emits an explicit clear')

        page.evaluate('holdInventory=true')
        composer.fill('/stories')
        composer.press('Enter')
        picker = page.get_by_role('dialog', name='Choose stories', exact=True)
        expect(picker).to_be_visible()
        page.wait_for_function('typeof releaseInventory==="function"')
        page.evaluate("owner='2';syncAuthMenu();releaseInventory()")
        expect(picker).not_to_be_visible()
        check(composer.input_value() == '/stories' and page.evaluate('apiCalls.at(-1).signal.aborted'),
              prefix + 'account changes close the picker and reject a late inventory')
        check(not errors and all(method == 'GET' and url in {'https://stories.test/',
              'https://stories.test/api/canvas-resources.php?kind=stories'} for method, url in requests),
              prefix + 'selection uses only read-only inventory requests')
        context.close()
    browser.close()

print(f'PASS {len(checks)} native story picker checks', flush=True)
