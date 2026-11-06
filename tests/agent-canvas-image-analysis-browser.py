"""Exercise private photo analysis and profile review using the production UI."""
import json
import os
from pathlib import Path

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/stonefellow/'
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


FIXTURE = '''() => {
  const uploaded={id:'ab'.repeat(16),title:'My photo <b>fixture</b>',source_kind:'uploaded_photo',is_own_photo:true};
  const generated={id:'cd'.repeat(16),title:'Generated artwork',source_kind:'generated',is_own_photo:false};
  const analysis={description:'A guitar beside a window.',visible_text:'STONEFELLOW',objects:['guitar','window'],
    scene:'An indoor music space',style:'A natural-light photograph',colors:['blue','cream'],tags:['music'],
    uncertainties:['The small label cannot be read clearly.']};
  const state=image=>({ok:true,image,image_analysis:null,analysis_id:null,analysis_status:null,revision:0,profile_suggestions:[]});
  window.fixture={uploaded,generated,analysis,states:{[uploaded.id]:state(uploaded),[generated.id]:state(generated)},
    records:[],calls:[],providerCalls:0,saved:[],preferencesSaved:[],personalization:false};
  const copy=value=>JSON.parse(JSON.stringify(value));
  window.fixtureApi=async(path,body,signal)=>{
    const f=fixture;
    f.calls.push({path,body:body?copy(body):null,signal});
    if(path.startsWith('agent-canvas-image-analysis.php?id=')){
      if(f.holdRead){f.holdRead=false;await new Promise(resolve=>f.releaseRead=resolve);}
      return copy(f.states[path.split('=')[1]]);
    }
    if(path==='image-profile-actions.php'){
      if(!body)return {ok:true,suggestions:copy(f.records.filter(item=>['pending','accepted'].includes(item.status))),personalization_enabled:f.personalization};
      const record=f.records.find(item=>item.id===body.id);
      record.status={accept:'accepted',dismiss:'dismissed',remove:'removed'}[body.action];
      record.uncertain=record.status!=='accepted';
      return {ok:true,suggestion:copy(record)};
    }
    if(path!=='agent-canvas-image-analysis.php')throw Error('Unexpected API path');
    const current=f.states[body.id];
    if(body.action==='analyze'){
      if(current.analysis_id!==body.request_id){
        f.providerCalls++;
        Object.assign(current,{analysis_id:body.request_id,analysis_status:'draft',revision:1,image_analysis:copy(analysis)});
        if(body.suggest_profile){
          const record={id:1,source_id:body.id,category:'interest',value:'Acoustic music',evidence:'An acoustic guitar is visible.',status:'pending',uncertain:true};
          f.records=[record];current.profile_suggestions=[copy(record)];
        }
      }
      if(f.failAnalyze){f.failAnalyze=false;throw Error('The connection closed. Retry to check this request.');}
      return copy(current);
    }
    if(body.action==='save'){
      if(f.conflictSave){f.conflictSave=false;current.revision++;throw Object.assign(Error('This analysis changed. Reload the latest version.'),{status:409});}
      if(body.revision!==current.revision)throw Error('The revision was not current.');
      current.image_analysis=copy(body.analysis);current.analysis_status='approved';current.revision++;
      return copy(current);
    }
    if(body.action==='reset'){
      current.analysis_status='canceled';current.revision++;
      return copy(current);
    }
    throw Error('Unexpected image action');
  };
  window.openImage=(image,signal)=>{
    fixture.result='pending';
    SFCanvasImageAnalysis.open(image,{api:fixtureApi,signal,onSaved:value=>fixture.saved.push(value)}).then(value=>fixture.result=value);
  };
}'''


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('CHROMIUM_BIN'), args=['--no-sandbox'])
    for width in (375, 1440):
        page = browser.new_page(viewport={'width': width, 'height': 900}, has_touch=width < 720)
        page.set_default_timeout(5000)
        errors, requests = [], []
        page.on('pageerror', lambda error: errors.append(str(error)))

        def respond(route):
            requests.append(route.request.url)
            if route.request.resource_type == 'image':
                route.fulfill(content_type='image/svg+xml', body='''<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600">
                  <rect width="800" height="600" fill="#152a3f"/><circle cx="400" cy="300" r="180" fill="#aacddd"/></svg>''')
            else:
                route.fulfill(content_type='text/html', body='<!doctype html><html><body><button id="opener">My photos</button></body></html>')

        page.route('**/*', respond)
        page.goto(SITE)
        page.add_style_tag(content=(ROOT / 'assets/css/canvas-image-analysis.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-image-analysis.js').read_text())
        page.evaluate(FIXTURE)
        page.locator('#opener').focus()
        page.evaluate('openImage(fixture.generated)')
        dialog = page.get_by_role('dialog', name='Analyze image', exact=True)
        analyze = dialog.get_by_role('button', name='Analyze image', exact=True)
        save = dialog.get_by_role('button', name='Save reviewed analysis', exact=True)
        expect(analyze).to_be_enabled()
        check(dialog.get_by_role('checkbox').count() == 0 and save.is_disabled() and
              page.evaluate('fixture.providerCalls===0&&fixture.calls.length===1'),
              f'{width} opening generated artwork reads its status and offers no photo profile opt-in')
        page.keyboard.press('Escape')
        expect(page.locator('#opener')).to_be_focused()

        page.evaluate('fixture.failAnalyze=true;openImage(fixture.uploaded)')
        expect(analyze).to_be_enabled()
        profile = dialog.get_by_role('checkbox', name='Suggest profile details from this photo', exact=True)
        expect(profile).not_to_be_checked()
        profile.check()
        analyze.click()
        expect(dialog.locator('.sf-image-analysis-status')).to_contain_text('The connection closed')
        expect(profile).to_be_disabled()
        analyze.click()
        description = dialog.get_by_label('Detailed description', exact=True)
        expect(description).to_have_value('A guitar beside a window.')
        expect(save).to_be_enabled()
        check(page.evaluate('''() => {
              const calls=fixture.calls.filter(call=>call.body?.action==='analyze');
              return fixture.providerCalls===1&&calls.length===2&&calls[0].body.request_id===calls[1].body.request_id&&
                calls.every(call=>call.body.suggest_profile===true)&&fixture.saved.length===0;
            }'''), f'{width} explicit analysis retries its original request without another provider call or automatic approval')
        expect(dialog.get_by_text('Personalization is off.', exact=False)).to_be_visible()
        description.fill('🙂' * 1100)
        save.click()
        check(description.evaluate('input=>!input.checkValidity()') and page.evaluate('fixture.saved.length===0'),
              f'{width} oversized Unicode details are kept local for correction')
        dialog.get_by_role('button', name='Reload analysis', exact=True).click()
        expect(description).to_have_value('A guitar beside a window.')
        assert description.evaluate('input=>input.checkValidity()')
        description.fill('My reviewed caption')
        dialog.get_by_label('Text in the image', exact=True).fill('<b>Lyrics & lights</b>\nA second line')
        dialog.get_by_label('Tags', exact=True).fill('music\nwindow light')
        save.click()
        expect(dialog.locator('.sf-image-analysis-status')).to_have_text('Reviewed analysis saved.')
        check(page.evaluate('''expectedText => {
              const saved=fixture.saved[0],call=fixture.calls.find(call=>call.body?.action==='save').body;
              return fixture.saved.length===1&&saved.image_analysis.description==='My reviewed caption'&&
                saved.image_analysis.visible_text===expectedText&&call.revision===1&&
                call.analysis_id===fixture.states[fixture.uploaded.id].analysis_id&&
                JSON.stringify(call.analysis.tags)==='["music","window light"]'&&fixture.providerCalls===1;
            }''', '<b>Lyrics & lights</b>\nA second line') and
              dialog.get_by_role('button', name='Reset analysis', exact=True, include_hidden=True).is_disabled(),
              f'{width} reviewed fields save with the exact analysis revision and update the caller only after approval')
        check(dialog.locator('b').count() == 0 and dialog.evaluate('node=>node.scrollWidth<=node.clientWidth'),
              f'{width} image titles and visible text stay plain and the review form fits the viewport')
        description.fill('Unsaved local draft')
        page.evaluate('fixture.conflictSave=true')
        save.click()
        expect(dialog.locator('.sf-image-analysis-status')).to_contain_text('changed')
        expect(description).to_have_value('Unsaved local draft')
        check(page.evaluate('fixture.saved.length===1&&fixture.providerCalls===1'),
              f'{width} a revision conflict preserves local edits and creates no new analysis request')
        dialog.get_by_role('button', name='Reload analysis', exact=True).click()
        expect(description).to_have_value('My reviewed caption')

        pending_card = dialog.locator('.sf-image-profile-card').filter(has_text='Acoustic music')
        pending_card.get_by_role('button', name='Accept', exact=True).click()
        expect(pending_card).to_contain_text('Confirmed by you')
        check(page.evaluate('fixture.records[0].status==="accepted"&&!fixture.personalization') and
              dialog.get_by_text('Personalization is off.', exact=False).is_visible(),
              f'{width} a user can confirm a photo preference while personalization stays inactive')
        pending_card.get_by_role('button', name='Remove', exact=True).click()
        expect(pending_card).to_have_count(0)
        check(page.evaluate('fixture.records[0].status==="removed"'),
              f'{width} confirmed photo preferences can be removed')
        page.screenshot(path=f'/tmp/sf280-image-analysis-{width}.png')
        dialog.get_by_role('button', name='Close', exact=True).click()

        page.evaluate('''() => {
          Object.assign(fixture.states[fixture.uploaded.id],{analysis_id:'e1'.repeat(16),analysis_status:'processing',image_analysis:null,revision:4});
          openImage(fixture.uploaded);
        }''')
        reset = dialog.get_by_role('button', name='Reset analysis', exact=True)
        expect(reset).to_be_visible()
        expect(analyze).to_be_disabled()
        page.evaluate('fixture.states[fixture.uploaded.id].analysis_status="unknown"')
        dialog.get_by_role('button', name='Reload analysis', exact=True).click()
        expect(dialog.locator('.sf-image-analysis-state')).to_contain_text('provider outcome is uncertain')
        reset.click()
        expect(analyze).to_be_enabled()
        check(page.evaluate('fixture.providerCalls===1&&fixture.states[fixture.uploaded.id].analysis_status==="canceled"'),
              f'{width} an uncertain request can be reset without starting another provider request')
        analyze.click()
        expect(save).to_be_enabled()
        check(page.evaluate('''() => {
              const call=fixture.calls.filter(call=>call.body?.action==='analyze').at(-1).body;
              return fixture.providerCalls===2&&call.request_id!=='e1'.repeat(16)&&!call.suggest_profile;
            }'''), f'{width} analysis after reset starts only on an explicit click with a fresh request ID')
        dialog.get_by_role('button', name='Close', exact=True).click()

        page.evaluate('''() => {
          fixture.records=[{id:2,source_id:fixture.uploaded.id,category:'interest',value:'Gardening',evidence:'Plants are visible.',status:'pending',uncertain:true},
            {id:3,source_id:fixture.uploaded.id,category:'style',value:'Warm colors',evidence:'Confirmed palette preference.',status:'accepted',uncertain:false}];
          SFCanvasImageAnalysis.openPreferences({api:fixtureApi,onSaved:value=>fixture.preferencesSaved.push(value)});
        }''')
        preferences = page.get_by_role('dialog', name='Photo preferences', exact=True)
        gardening = preferences.locator('.sf-image-profile-card').filter(has_text='Gardening')
        expect(gardening).to_contain_text('Photo evidence')
        expect(gardening).to_contain_text('needs your confirmation')
        gardening.get_by_role('button', name='Dismiss', exact=True).click()
        expect(gardening).to_have_count(0)
        warm = preferences.locator('.sf-image-profile-card').filter(has_text='Warm colors')
        warm.get_by_role('button', name='Remove', exact=True).click()
        expect(warm).to_have_count(0)
        check(page.evaluate('fixture.preferencesSaved.length===2&&fixture.records[0].status==="dismissed"&&fixture.records[1].status==="removed"'),
              f'{width} Photo preferences displays evidence and supports dismissal and removal')
        preferences.get_by_role('button', name='Close', exact=True).click()

        page.evaluate('''() => {
          fixture.holdRead=true;window.ownerLifetime=new AbortController();openImage(fixture.uploaded,ownerLifetime.signal);
        }''')
        page.wait_for_function('typeof fixture.releaseRead==="function"')
        page.evaluate('ownerLifetime.abort();fixture.releaseRead()')
        expect(dialog).not_to_be_visible()
        check(page.evaluate('fixture.result===null&&fixture.saved.length===1') and not errors and
              all(url.startswith(SITE) for url in requests),
              f'{width} owner cancellation removes the modal and ignores late results without external image requests')
        page.close()
    browser.close()

print(json.dumps({'checks': len(checks), 'widths': [375, 1440], 'provider': 'synthetic'}))
