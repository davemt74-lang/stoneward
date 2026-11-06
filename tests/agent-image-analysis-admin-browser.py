"""Check Admin image analysis review against the production Media Library module."""
import base64
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://stonefellow.test/admin/'
PIXEL = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNe0AAAAASUVORK5CYII=')
CHECKS = []


def check(condition, label):
    assert condition, label
    CHECKS.append(label)


FIXTURE = r'''
window.analysisPosts=[];window.savedMessages=[];
window.asset={id:17,uuid:'test-media-image',category:'image',mime_type:'image/jpeg',title:'Original title',caption:'Original caption',alt_text:'Original alt text',image_analysis:{
  id:'a1'.repeat(16),version:1,status:'pending_review',description:'A singer <b>safe</b>',visible_text:'EARTH\nGIRL',
  objects:['microphone'],scene:'Night stage',style:'Concert photograph',colors:['blue'],tags:['music'],uncertainties:['Location is unclear'],
  provider:'openai',model:'vision-model',analyzed_at:'2026-11-05T12:00:00Z'
}};
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
async function api(path,payload){
  if(!payload)return {ok:true,asset:structuredClone(asset),usage:[]};
  analysisPosts.push(structuredClone(payload));
  if(payload.action==='analyze_image'){
    if(window.failAnalysis){window.failAnalysis=false;throw Error('Connection interrupted. Check analysis to recover the result.');}
    asset.image_analysis={...asset.image_analysis,id:payload.request_id,version:1,status:'pending_review',description:'A new image description'};
    return {ok:true,asset_id:17,analysis:structuredClone(asset.image_analysis)};
  }
  if(payload.action==='save_image_analysis'){
    if(window.failSave){window.failSave=false;throw Error('The analysis was updated. Reopen the image before saving.');}
    asset.image_analysis={...asset.image_analysis,...payload.analysis,status:'approved',version:asset.image_analysis.version+1,reviewed_at:'2026-11-05T12:01:00Z'};
    return {ok:true,asset_id:17,analysis:structuredClone(asset.image_analysis)};
  }
  throw Error('Unexpected action');
}
window.STONEFELLOW_ADMIN_CONTEXT={app:{view:'media'},api,canvas:document.querySelector('main'),head:()=>'',say:message=>savedMessages.push(message),esc,
  openView(){},openCatalogTrack(){},reloadCore(){}};
'''

with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox']}
    if os.environ.get('CHROMIUM_BIN'):
        launch['executable_path'] = os.environ['CHROMIUM_BIN']
    browser = playwright.chromium.launch(**launch)
    for width in (390, 1280):
        context = browser.new_context(viewport={'width': width, 'height': 850})
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.route('**/*', lambda route: route.fulfill(
            content_type='text/html' if route.request.url == SITE else 'image/png',
            body='<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main></main></body></html>'
            if route.request.url == SITE else PIXEL))
        page.goto(SITE)
        for name in ('admin/assets/admin.css', 'admin/assets/media.css'):
            page.add_style_tag(content=(ROOT / name).read_text())
        page.evaluate(FIXTURE)
        page.add_script_tag(content=(ROOT / 'admin/assets/media.js').read_text())
        page.evaluate('SFMediaAdmin.openAsset(17)')
        panel = page.locator('[data-media-image-analysis]')
        expect(panel).to_be_visible()
        prefix = f'{width}px '
        description = panel.get_by_label('Visible description', exact=True)
        expect(description).to_have_value('A singer <b>safe</b>')
        expect(panel.get_by_label('Visible text / OCR', exact=True)).to_have_value('EARTH\nGIRL')
        check(panel.locator('b').count() == 0 and 'openai' in panel.inner_text() and
              '2026-11-05T12:00:00Z' in panel.inner_text(), prefix + 'review displays safe editable content and provider timestamp')
        description.fill('Reviewed singer and microphone')
        panel.get_by_label('Tags, one per line', exact=True).fill('music\nconcert')
        panel.get_by_role('button', name='Save reviewed analysis', exact=True).click()
        expect(panel.get_by_text('Reviewed analysis saved.', exact=True)).to_be_visible()
        check(page.evaluate('analysisPosts.length===1&&analysisPosts[0].analysis_id==="a1".repeat(16)&&analysisPosts[0].expected_version===1&&analysisPosts[0].analysis.tags.join(",")==="music,concert"&&asset.title==="Original title"&&asset.caption==="Original caption"&&asset.alt_text==="Original alt text"'),
              prefix + 'review save identifies the exact analysis version and preserves media metadata')
        expect(description).to_have_value('Reviewed singer and microphone')

        page.evaluate('window.failSave=true')
        description.fill('Keep this unsaved correction')
        panel.get_by_role('button', name='Save reviewed analysis', exact=True).click()
        expect(panel.get_by_role('status')).to_contain_text('The analysis was updated')
        expect(description).to_have_value('Keep this unsaved correction')
        expect(description).to_be_enabled()
        check(page.evaluate('analysisPosts.at(-1).expected_version===2'), prefix + 'review conflicts preserve edits and the expected version')

        page.evaluate('window.failAnalysis=true')
        panel.get_by_role('button', name='Analyze image again', exact=True).click()
        expect(panel.get_by_role('status')).to_contain_text('Connection interrupted')
        page.wait_for_function('analysisPosts.at(-1).action==="analyze_image"')
        panel.get_by_role('button', name='Check analysis', exact=True).click()
        expect(description).to_have_value('A new image description')
        check(page.evaluate('analysisPosts.at(-1).request_id===analysisPosts.at(-2).request_id&&/^[a-f0-9]{32}$/.test(analysisPosts.at(-1).request_id)&&analysisPosts.at(-1).action==="analyze_image"'),
              prefix + 'retry reuses the original analysis request instead of starting another analysis')
        check(panel.evaluate('node=>node.scrollWidth<=node.clientWidth+1') and not errors,
              prefix + 'review fields fit the mobile or desktop modal without browser errors')
        context.close()
    browser.close()
print(json.dumps({'checks': len(CHECKS), 'browser': 'chromium', 'widths': [390, 1280]}))
