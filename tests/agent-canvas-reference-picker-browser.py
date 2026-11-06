"""Exercise production /images selection with synthetic owner and artist inventories."""
import base64
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'assets/js/app.js').read_text()
PICKER = APP[APP.index('  function chatDraftImageIds('):APP.index('  function submitAgentChat(')]
PATH_HELPER = APP[APP.index('  function sceneImagePath('):APP.index('  async function renderAgentCanvas(')]
SITE = 'https://stonefellow.test/'
PIXEL = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNe0AAAAASUVORK5CYII=')
CHECKS = []


def check(condition, label):
    assert condition, label
    CHECKS.append(label)


FIXTURE = r'''
const input=document.querySelector('#composer');
const cfg={apiBase:'api'};
const $=(selector,root=document)=>root.querySelector(selector);
const $$=(selector,root=document)=>Array.from(root.querySelectorAll(selector));
let chatImagePicker=null,chatImageDraftLimit=-1;
const chatImageReferenceLimit=4;
const chatImageId=/^(?:ref-)?[a-f0-9]{32}$/;
window.fixtureOwner=4;
window.inventoryReads=[];
window.analysisCalls=[];window.analysisWrites=[];window.preferenceCalls=0;window.batchCalls=[];
async function secureJsonPost(path,body,retry,signal){analysisWrites.push({path,body,aborted:signal?.aborted});return {ok:true};}
async function uploadCanvasPhoto(file,{signal}){
  window.uploadedPhoto={id:'c6'.repeat(16),title:file.name,source_kind:'uploaded_photo'};
  return window.uploadedPhoto;
}
window.SFCanvasImageAnalysis={
  open(image,options){
    analysisCalls.push({image,signal:options.signal});
    window.lastAnalysisOptions=options;
    options.onSaved({...image,image_analysis:{description:'A reviewed blue stage',tags:['reviewed']}});
  },
  openPreferences(options){window.preferenceCalls++;window.preferenceSignal=options.signal;},
  openBatch(options){batchCalls.push(options);}
};
function canvasOwner(){return window.fixtureOwner;}
function setCanvasImageEditDraft(){}
function directListeningRoomCommand(draft){
  return /^(?:let['’]?s build|\/edit)\b/i.test(draft)?{kind:'create'}:null;
}
async function api(path,body,signal){
  inventoryReads.push(path);
  const params=new URL(path,'https://stonefellow.test/api/').searchParams;
  const artist=params.get('scope')==='reference';
  if(window.holdInventory){
    window.holdInventory=false;
    window.inventorySignal=signal;
    await new Promise(resolve=>window.releaseInventory=resolve);
  }
  const image=(key,title)=>({id:(artist?'ref-':'')+key.repeat(16),title,
    image_analysis:{description:'Blue lights <b>safe</b>',visible_text:'EARTH GIRL',tags:['music','night']}});
  if(params.get('q')==='blue stage')return {ok:true,images:[image(artist?'d2':'c3',artist?'Band on stage':'My third image')],next_cursor:null};
  if(artist)return {ok:true,images:[image('d1','Artist portrait <b>safe</b>'),image('d2','Band on stage')],next_cursor:null};
  if(params.has('before'))return {ok:true,images:[image('c4','Fourth image'),image('c5','Fifth image')],next_cursor:null};
  return {ok:true,images:[...(window.uploadedPhoto?[uploadedPhoto]:[]),image('c1','My first image'),image('c2','My second image'),image('c3','My third image')],next_cursor:'c3'.repeat(16)};
}
'''

with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox']}
    if os.environ.get('CHROMIUM_BIN'):
        launch['executable_path'] = os.environ['CHROMIUM_BIN']
    browser = playwright.chromium.launch(**launch)
    for width in (390, 1280):
        context = browser.new_context(viewport={'width': width, 'height': 850}, has_touch=width < 720)
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.route('**/*', lambda route: route.fulfill(
            content_type='text/html' if route.request.url == SITE else 'image/png',
            body='<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><input id="composer" aria-label="Chat" maxlength="320"></body></html>'
            if route.request.url == SITE else PIXEL))
        page.goto(SITE)
        page.add_style_tag(content=(ROOT / 'assets/css/public-agent-canvas.css').read_text())
        page.add_script_tag(content=FIXTURE + PICKER + PATH_HELPER + '\nbindChatImagePicker();')
        composer = page.get_by_role('textbox', name='Chat', exact=True)
        picker = page.get_by_role('dialog', name='Choose saved images')
        prefix = f'{width}px '

        composer.fill("Let's build a photo wall with /images")
        expect(picker).to_be_visible()
        first = picker.get_by_role('checkbox', name='My first image', exact=True)
        first.check()
        picker.get_by_role('button', name='Load more', exact=True).click()
        picker.get_by_role('checkbox', name='Fourth image', exact=True).check()
        picker.get_by_role('button', name='Artist references', exact=True).click()
        artist = picker.get_by_role('checkbox', name='Artist portrait <b>safe</b>', exact=True)
        expect(artist).to_be_visible()
        artist.check()
        check(picker.locator('b').count() == 0, prefix + 'artist titles render as text')
        picker.get_by_role('button', name='My images', exact=True).click()
        expect(first).to_be_checked()
        picker.get_by_role('checkbox', name='My second image', exact=True).check()
        expect(picker.get_by_role('checkbox', name='My third image', exact=True)).to_be_disabled()
        picker.get_by_role('button', name='Add images', exact=True).click()
        expect(picker).not_to_be_visible()
        value = composer.input_value()
        check(all('{image-' + key * 16 + '}' in value for key in ('c1', 'c4', 'c2')) and
              '{image-ref-' + 'd1' * 16 + '}' in value,
              prefix + 'private pagination and artist selection share the four-placement limit')
        expect(composer).to_be_focused()

        # Four placed images leave all four visual-reference slots available.
        composer.fill('/edit Keep the layout ' + value[value.index('{image-'):] + ' /images')
        expect(picker).to_be_visible()
        expect(picker.get_by_role('checkbox', name='My third image', exact=True)).to_be_disabled()
        picker.get_by_role('button', name='Use as visual reference', exact=True).click()
        picker.get_by_role('checkbox', name='My third image', exact=True).check()
        picker.get_by_role('button', name='Artist references', exact=True).click()
        picker.get_by_role('checkbox', name='Band on stage', exact=True).check()
        picker.get_by_role('button', name='Add references', exact=True).click()
        value = composer.input_value()
        check('{reference-image-' + 'c3' * 16 + '}' in value and
              '{reference-image-ref-' + 'd2' * 16 + '}' in value and
              page.evaluate('chatDraftImageIds(input.value).size===4&&chatDraftVisualReferenceIds(input.value).length===2'),
              prefix + 'visual references stay independent of canvas placements')
        check(page.evaluate('input.value.length<=input.maxLength&&input.maxLength>320'),
              prefix + 'binding tokens retain the composer description allowance')

        composer.fill("Let's build a collage /images")
        expect(picker).to_be_visible()
        picker.get_by_role('checkbox', name='My first image', exact=True).check()
        search = picker.get_by_role('searchbox', name='Search images', exact=True)
        search.fill('blue stage')
        picker.get_by_role('button', name='Search', exact=True).click()
        expect(picker.get_by_role('checkbox', name='My third image', exact=True)).to_be_visible()
        check(picker.get_by_role('checkbox').count() == 1 and page.evaluate('inventoryReads.at(-1)==="agent-canvas-image.php?q=blue+stage"'),
              prefix + 'search queries the image inventory while preserving the draft selection')
        private_row = picker.locator('li').first
        private_row.get_by_text('Image details', exact=True).click()
        expect(private_row.get_by_text('EARTH GIRL', exact=True)).to_be_visible()
        check(private_row.locator('b').count() == 0, prefix + 'description and OCR display as safe text')
        private_row.get_by_role('button', name='Analyze image My third image', exact=True).click()
        expect(private_row.get_by_text('A reviewed blue stage', exact=True).first).to_be_visible()
        page.evaluate('lastAnalysisOptions.api("agent-canvas-image-analysis.php",{action:"save"},lastAnalysisOptions.signal)')
        picker.get_by_role('button', name='Photo preferences', exact=True).click()
        check(page.evaluate('analysisCalls.length===1&&analysisWrites.length===1&&preferenceCalls===1'),
              prefix + 'private analysis and photo preferences use the modal and authenticated write adapter')
        picker.get_by_role('button', name='Artist references', exact=True).click()
        expect(picker.get_by_role('checkbox', name='Band on stage', exact=True)).to_be_visible()
        check(page.evaluate('inventoryReads.at(-1)==="agent-canvas-image.php?scope=reference&q=blue+stage"') and
              picker.get_by_role('button', name='Analyze image', exact=True).count() == 0,
              prefix + 'artist search shares the query and exposes approved metadata')
        page.keyboard.press('Escape')
        check(page.evaluate('analysisCalls[0].signal.aborted&&preferenceSignal.aborted'),
              prefix + 'closing the picker cancels its analysis and preference context')

        composer.fill('/images')
        expect(picker).to_be_visible()
        picker.locator('[data-image-picker-photo]').set_input_files({'name':'my-photo.png','mimeType':'image/png','buffer':PIXEL})
        expect(picker.get_by_role('checkbox', name='my-photo.png', exact=True)).to_be_visible()
        check(page.evaluate('analysisCalls.at(-1).image.source_kind==="uploaded_photo"&&analysisCalls.length===2'),
              prefix + 'photo upload refreshes the private inventory and offers analysis with upload provenance')
        page.keyboard.press('Escape')

        # Analysis selection has its own capacity and never inserts image tokens.
        composer.fill('/images')
        expect(picker).to_be_visible()
        picker.get_by_role('button', name='Select for analysis', exact=True).click()
        for name in ('My first image', 'My second image', 'My third image'):
            picker.get_by_role('checkbox', name=name, exact=True).check()
        picker.get_by_role('button', name='Load more', exact=True).click()
        for name in ('Fourth image', 'Fifth image'):
            picker.get_by_role('checkbox', name=name, exact=True).check()
        picker.get_by_role('button', name='Analyze selected', exact=True).click()
        check(page.evaluate('batchCalls.at(-1).selectedIds.length===5&&batchCalls.at(-1).selectedIds.every(id=>!id.startsWith("ref-"))') and
              composer.input_value() == '/images',
              prefix + 'batch analysis selects more than four owned images without changing the composer')
        picker.get_by_role('button', name='Batch analysis', exact=True).click()
        check(page.evaluate('batchCalls.at(-1).selectedIds.length===0'),
              prefix + 'saved batch and missing-analysis controls open independently of selected images')
        picker.get_by_role('button', name='Place in canvas', exact=True).click()
        check(page.evaluate('Array.from(document.querySelectorAll("#chatImagePicker input[type=checkbox]")).filter(node=>node.checked).length===4'),
              prefix + 'switching back to placement restores the four-image limit')
        picker.get_by_role('button', name='Select for analysis', exact=True).click()
        picker.get_by_role('button', name='Artist references', exact=True).click()
        expect(picker.get_by_role('button', name='Select for analysis', exact=True)).not_to_be_visible()
        expect(picker.get_by_role('button', name='Add images', exact=True)).to_be_disabled()
        page.keyboard.press('Escape')
        check(page.evaluate('batchCalls.every(call=>call.signal.aborted)'),
              prefix + 'closing the picker cancels its batch UI context')

        # Guests can browse published artist references without reading private inventory.
        page.evaluate('fixtureOwner=null;window.beforeGuestReads=inventoryReads.length')
        composer.fill('/images')
        expect(picker).to_be_visible()
        expect(picker.get_by_role('button', name='My images', exact=True)).to_be_disabled()
        expect(picker.get_by_role('checkbox', name='Band on stage', exact=True)).to_be_visible()
        check(page.evaluate('inventoryReads.slice(beforeGuestReads).join(",")==="agent-canvas-image.php?scope=reference"'),
              prefix + 'guest inventory requests only approved artist references')
        panel = picker.locator('.sf-chat-image-picker-panel')
        check(panel.evaluate('node=>node.scrollWidth<=node.clientWidth+1') and
              picker.evaluate('node=>node.getBoundingClientRect().width<=innerWidth'),
              prefix + 'source and use controls fit the viewport')
        page.keyboard.press('Escape')
        expect(picker).not_to_be_visible()
        expect(composer).to_have_value('/images')

        # A late inventory response cannot populate another account's picker.
        page.evaluate('fixtureOwner=4;window.holdInventory=true')
        composer.fill('/edit use /images')
        expect(picker).to_be_visible()
        page.wait_for_function('typeof releaseInventory==="function"')
        page.evaluate('fixtureOwner=5;releaseInventory()')
        page.wait_for_timeout(20)
        check(picker.get_by_role('checkbox').count() == 0,
              prefix + 'late image responses are ignored after an account change')
        page.keyboard.press('Escape')
        check(not errors, prefix + 'no browser errors')
        context.close()
    browser.close()
print(json.dumps({'checks': len(CHECKS), 'browser': 'chromium', 'widths': [390, 1280]}))
