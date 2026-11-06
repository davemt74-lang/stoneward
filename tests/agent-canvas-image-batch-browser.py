"""Verify durable private batch UI with synthetic API responses and no provider calls."""
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
  const copy=value=>JSON.parse(JSON.stringify(value));
  const ids=['ab'.repeat(16),'cd'.repeat(16),'ef'.repeat(16)];
  const analysis={description:'A moonlit garden.',visible_text:'<b>STONEFELLOW</b>',objects:['trees'],
    scene:'An outdoor garden',style:'Night photography',colors:['blue'],tags:['moonlight'],uncertainties:['The distant object is unclear.']};
  window.f={ids,analysis,jobs:[],calls:[],steps:[],saved:[],activeSteps:0,maxActive:0};
  const snapshot=job=>{
    const counts={total:job.items.length,pending:0,processing:0,completed:0,failed:0,skipped:0,reviewed:0};
    const usage={active_tokens_used:0,input_tokens:0,output_tokens:0};
    for(const item of job.items){counts[item.status]++;for(const key of Object.keys(usage))usage[key]+=item.usage[key]||0;}
    if(job.status==='running'&&!counts.pending&&!counts.processing)job.status='completed';
    return copy({...job,counts,usage});
  };
  const latest=()=>f.jobs.at(-1);
  window.batchApi=async(path,body,signal)=>{
    f.calls.push({path,body:body?copy(body):null,signal});
    if(path.startsWith('agent-canvas-image-analysis.php')){
      const id=body?.id||path.split('=')[1];
      const item=f.jobs.flatMap(job=>job.items).find(item=>item.image_id===id&&item.analysis);
      if(body){
        if(body.action!=='save'||body.revision!==item.version)throw Error('Unexpected per-image mutation');
        item.analysis=copy(body.analysis);item.version++;item.analysis_status='approved';item.status='reviewed';
      }
      return {ok:true,image:{id,title:item.title,source_kind:'generated',is_own_photo:false},image_analysis:copy(item.analysis),
        analysis_id:item.analysis_id,analysis_status:item.analysis_status,revision:item.version,profile_suggestions:[]};
    }
    if(!path.startsWith('image-analysis-batch.php'))throw Error('Unexpected endpoint');
    if(!body){
      if(f.failRead){f.failRead=false;throw Error('Saved progress could not load.');}
      const id=new URL(path,'https://fixture.test/api/').searchParams.get('id');
      if(id)return {ok:true,job:snapshot(f.jobs.find(job=>job.id===id))};
      return {ok:true,job:latest()?snapshot(latest()):null,jobs:f.jobs.map(job=>{const result=snapshot(job);delete result.items;return result;})};
    }
    if(body.action==='create'){
      const prior=f.jobs.find(job=>job.id===body.request_id);
      if(prior)return {ok:true,job:snapshot(prior)};
      const chosen=body.mode==='selected'?body.ids:ids;
      const job={id:body.request_id,scope:'private',status:'running',mode:body.mode,remaining_count:body.mode==='missing'?7:0,
        created_at:'2027-01-01T12:00:00Z',updated_at:'2027-01-01T12:00:00Z',items:chosen.map((image_id,index)=>({
          image_id,title:index===0?'<b>Moonlit artwork</b>':'Artwork '+(index+1),request_id:crypto.randomUUID().replaceAll('-',''),
          status:'pending',image_url:'https://egress.test/untrusted-preview',analysis_id:null,analysis_status:null,
          version:0,analysis:null,error:'',usage:{active_tokens_used:0,input_tokens:0,output_tokens:0}}))};
      f.jobs.push(job);return {ok:true,job:snapshot(job)};
    }
    const job=f.jobs.find(job=>job.id===body.id);
    if(body.action==='step'){
      if(job.status!=='running')return {ok:true,job:snapshot(job)};
      const item=job.items.find(item=>item.request_id===body.request_id);
      if(f.noopStep){f.noopStep=false;f.noopCalls=(f.noopCalls||0)+1;return {ok:true,job:snapshot(job)};}
      if(item.status!=='pending')throw Error('A paid item was dispatched twice.');
      f.steps.push(body.request_id);f.activeSteps++;f.maxActive=Math.max(f.maxActive,f.activeSteps);item.status='processing';
      try{
        if(f.holdStep){f.holdStep=false;await new Promise(resolve=>f.releaseStep=resolve);}
        item.status='completed';item.analysis_id=item.request_id;item.analysis_status='draft';item.version=1;
        item.analysis=copy(analysis);item.usage={active_tokens_used:3,input_tokens:20,output_tokens:10};
        if(f.loseStep){f.loseStep=false;throw Error('The connection closed after this image.');}
        const result=snapshot(job);
        if(f.staleStep){f.staleStep=false;result.status='running';}
        return {ok:true,job:result};
      }finally{f.activeSteps--;}
    }
    if(['pause','resume','cancel'].includes(body.action)){
      job.status={pause:'paused',resume:'running',cancel:'canceled'}[body.action];
      if(body.action==='cancel')for(const item of job.items)if(item.status==='pending')item.status='skipped';
      return {ok:true,job:snapshot(job)};
    }
    if(body.action==='review'){
      const results=[];
      for(const approved of body.items){
        const item=job.items.find(item=>item.image_id===approved.image_id);
        if(f.conflictReview===item.image_id){item.version++;item.analysis.description='Changed in another session.';f.conflictReview=null;}
        if(item.version!==approved.version){results.push({image_id:item.image_id,ok:false,error:'Analysis changed.'});continue;}
        if(item.analysis_id!==approved.analysis_id)throw Error('Wrong analysis identity');
        item.analysis=copy(approved.analysis);item.status='reviewed';item.analysis_status='approved';item.version++;
        results.push({image_id:item.image_id,ok:true});
      }
      return {ok:true,job:snapshot(job),review_results:results};
    }
    throw Error('Unexpected batch action');
  };
  window.openBatch=selectedIds=>{
    window.ownerScope=new AbortController();
    f.result='pending';SFCanvasImageAnalysis.openBatch({selectedIds,api:batchApi,signal:ownerScope.signal,onSaved:value=>f.saved.push(value)})
      .then(value=>f.result=value);
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
                route.fulfill(content_type='image/svg+xml', body='<svg xmlns="http://www.w3.org/2000/svg" width="160" height="120"><rect width="160" height="120" fill="#a2cddd"/></svg>')
            else:
                route.fulfill(content_type='text/html', body='<!doctype html><body><button id="opener">My images</button>')

        page.route('**/*', respond)
        page.goto(SITE)
        page.add_style_tag(content=(ROOT / 'assets/css/canvas-image-analysis.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-image-analysis.js').read_text())
        page.evaluate(FIXTURE)
        page.locator('#opener').focus()
        page.evaluate('f.failRead=true;openBatch([...f.ids,f.ids[0],"ref-"+f.ids[0],"invalid"])')
        dialog = page.get_by_role('dialog', name='Batch image analysis', exact=True)
        status = dialog.locator('.sf-image-analysis-status')
        refresh = dialog.get_by_role('button', name='Refresh progress', exact=True)
        expect(status).to_have_text('Saved progress could not load.')
        expect(refresh).to_be_enabled()
        refresh.click()
        expect(status).to_contain_text('No saved')
        selected = dialog.get_by_role('button', name='Analyze 3 selected images', exact=True)
        expect(selected).to_be_enabled()
        check(page.evaluate('f.calls.every(call=>!call.body)&&f.steps.length===0'),
              f'{width} opening reads saved progress only and a failed read can be refreshed')
        page.evaluate('f.holdStep=true;f.staleStep=true')
        selected.click()
        page.wait_for_function('f.activeSteps===1&&typeof f.releaseStep==="function"')
        dialog.get_by_role('button', name='Pause batch', exact=True).click()
        page.wait_for_function('f.jobs[0].status==="paused"')
        check(page.evaluate('''() => {
              const create=f.calls.find(call=>call.body?.action==='create').body;
              return create.mode==='selected'&&create.ids.length===3&&f.maxActive===1&&f.steps.length===1&&f.activeSteps===1&&
                f.calls.some(call=>call.body?.action==='pause')&&!f.calls.some(call=>call.body?.suggest_profile);
            }'''), f'{width} selected images start one serial step and Pause is persisted while that image is in flight')
        page.evaluate('f.releaseStep()')
        page.wait_for_function('f.activeSteps===0')
        expect(dialog.get_by_role('button', name='Resume batch', exact=True)).to_be_enabled()
        expect(dialog.locator('.sf-image-batch-overview h3')).to_have_text('Batch paused')
        check(page.evaluate('f.steps.length===1&&f.jobs[0].items[1].status==="pending"'),
              f'{width} a stale step reply cannot restart a paused queue')
        dialog.get_by_role('button', name='Close', exact=True).click()
        page.evaluate('openBatch([])')
        expect(dialog.get_by_role('button', name='Resume batch', exact=True)).to_be_enabled()
        check(page.evaluate('f.steps.length===1'), f'{width} reopening a saved batch never starts provider work')
        dialog.get_by_role('button', name='Resume batch', exact=True).click()
        expect(status).to_contain_text('Batch analysis finished')
        check(page.evaluate('f.steps.length===3&&new Set(f.steps).size===3&&f.maxActive===1') and
              dialog.get_by_text('9 Active Tokens used', exact=False).is_visible(),
              f'{width} explicit Resume completes each remaining item once and reports recorded usage')
        dialog.get_by_role('button', name='Select reviewable drafts', exact=True).click()
        page.evaluate('f.conflictReview=f.ids[0]')
        dialog.get_by_role('button', name='Approve selected analyses', exact=True).click()
        expect(status).to_contain_text('2 analyses approved. 1 changed')
        check(page.evaluate('''() => {
              const review=f.calls.find(call=>call.body?.action==='review').body;
              return review.items.length===3&&review.items.every(item=>item.version===1&&item.analysis.visible_text==='<b>STONEFELLOW</b>')&&
                f.jobs[0].items.filter(item=>item.status==='reviewed').length===2&&f.saved.length===1;
            }'''), f'{width} batch approval submits the checked metadata versions and preserves a conflicting draft')
        card = dialog.locator('.sf-image-batch-item').filter(has_text='<b>Moonlit artwork</b>')
        card.locator('summary').click()
        expect(card).to_contain_text('Changed in another session.')
        check(dialog.locator('b').count() == 0 and dialog.evaluate('node=>node.scrollWidth<=node.clientWidth'),
              f'{width} descriptions and OCR remain plain text in a mobile-safe review list')
        card.get_by_role('button', name='Edit details', exact=True).click()
        editor = page.get_by_role('dialog', name='Analyze image', exact=True)
        expect(editor).to_be_visible()
        expect(editor.get_by_label('Detailed description', exact=True)).to_have_value('Changed in another session.')
        editor.get_by_label('Detailed description', exact=True).fill('Manually reviewed garden description.')
        editor.get_by_role('button', name='Save reviewed analysis', exact=True).click()
        expect(editor.locator('.sf-image-analysis-status')).to_have_text('Reviewed analysis saved.')
        editor.get_by_role('button', name='Close', exact=True).click()
        expect(dialog).to_be_visible()
        expect(dialog.get_by_role('checkbox')).to_have_count(0)
        expect(refresh).to_be_focused()
        check(page.evaluate('f.jobs[0].items[0].status==="reviewed"&&f.jobs[0].items[0].version===3&&f.steps.length===3'),
              f'{width} per-image editing preserves the parent batch and reloads its approved current version')
        page.screenshot(path=f'/tmp/sf281-private-batch-{width}.png')
        dialog.get_by_role('button', name='Close', exact=True).click()

        page.evaluate('openBatch([]);f.holdStep=true;f.loseStep=true')
        missing = dialog.get_by_role('button', name='Analyze missing images', exact=True)
        expect(missing).to_be_enabled()
        missing.click()
        page.wait_for_function('f.activeSteps===1')
        page.evaluate('f.releaseStep()')
        expect(status).to_contain_text('The batch stopped.')
        check(page.evaluate('f.steps.length===4&&f.jobs[1].items[1].status==="pending"') and
              dialog.get_by_text('7 additional images', exact=False).is_visible(),
              f'{width} a lost step response recovers with GET and leaves the next image waiting')
        page.evaluate('f.holdStep=true')
        dialog.get_by_role('button', name='Resume batch', exact=True).click()
        page.wait_for_function('f.activeSteps===1')
        dialog.get_by_role('button', name='Cancel batch', exact=True).click()
        page.wait_for_function('f.jobs[1].status==="canceled"')
        page.evaluate('f.releaseStep()')
        page.wait_for_function('f.activeSteps===0')
        expect(dialog.get_by_role('button', name='Resume batch', exact=True)).to_be_disabled()
        check(page.evaluate('f.jobs[1].items[2].status==="skipped"&&f.steps.length===5'),
              f'{width} Cancel persists immediately and leaves unstarted images skipped')
        dialog.get_by_role('button', name='Close', exact=True).click()

        page.evaluate('openBatch([]);f.holdStep=true')
        expect(missing).to_be_enabled()
        missing.click()
        page.wait_for_function('f.activeSteps===1')
        page.evaluate('ownerScope.abort();f.releaseStep()')
        expect(dialog).not_to_be_visible()
        page.wait_for_function('f.activeSteps===0')
        check(page.evaluate('f.result===null&&f.steps.length===6&&f.jobs[2].items[1].status==="pending"'),
              f'{width} owner abort clears the panel and prevents dispatch of the next paid item')
        page.evaluate('openBatch([])')
        expect(dialog.get_by_role('button', name='Resume batch', exact=True)).to_be_enabled()
        check(page.evaluate('f.steps.length===6') and not errors and all(url.startswith(SITE) for url in requests),
              f'{width} an interrupted batch restores durable progress without automatic work or external image URLs')
        page.evaluate('f.noopStep=true')
        dialog.get_by_role('button', name='Resume batch', exact=True).click()
        expect(status).to_contain_text('This image has not advanced.')
        check(page.evaluate('f.noopCalls===1&&f.steps.length===6'),
              f'{width} an unchanged pending item stops the driver after one request')
        page.keyboard.press('Escape')
        expect(page.locator('#opener')).to_be_focused()
        page.close()
    browser.close()

print(json.dumps({'checks': len(checks), 'widths': [375, 1440], 'provider': 'synthetic'}))
