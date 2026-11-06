"""Exercise the production Admin batch selection, progress, control and review UI."""
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
window.batchPosts=[];window.batchReads=[];window.batchJob=null;window.savedJobs=[];
window.analysisFields={description:'A singer <b>safe</b>',visible_text:'EARTH\nGIRL',objects:['microphone'],scene:'Night stage',style:'Concert photo',colors:['blue'],tags:['music'],uncertainties:[]};
window.assets=[17,18,19].map(id=>({id,uuid:'image-'+id,category:'image',mime_type:'image/jpeg',title:'Image '+id,original_name:'photo-'+id+'.jpg',size_bytes:100,width:32,height:32}));
assets.push({id:20,uuid:'song-20',category:'audio',title:'Song audio',original_name:'song.mp3',extension:'mp3',size_bytes:100});
window.holdNextStep=true;window.activeSteps=0;window.peakSteps=0;
const clone=value=>structuredClone(value);
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
function project(job){
  job.counts={total:job.items.length,pending:0,processing:0,completed:0,failed:0,skipped:0,reviewed:0};
  for(const item of job.items)job.counts[item.status]++;
  job.usage={input_tokens:job.items.filter(item=>item.analysis_id).length*100,output_tokens:job.items.filter(item=>item.analysis_id).length*20,active_tokens_used:job.items.filter(item=>item.analysis_id).length*120};
  if(job.status==='running'&&!job.counts.pending&&!job.counts.processing)job.status='completed';
  return clone(job);
}
async function api(path,payload){
  if(path==='media.php?mode=dashboard')return {ok:true,completeness:{},store_products:[]};
  if(path.startsWith('media.php?asset_id=')){
    const id=Number(path.split('=').at(-1)),asset=clone(assets.find(item=>item.id===id)),item=batchJob?.items.find(item=>item.image_id===id);
    if(item?.analysis_id)asset.image_analysis={...item.analysis,id:item.analysis_id,version:item.version,status:item.analysis_status,provider:'openai',model:'vision',analyzed_at:'2026-11-05T12:00:00Z'};
    return {ok:true,asset,usage:[]};
  }
  if(path.startsWith('media.php')&&!payload)return {ok:true,assets:clone(assets),capabilities:{},completeness:{}};
  if(path==='media.php'&&payload?.action==='save_image_analysis'){
    const item=batchJob.items.find(item=>item.image_id===payload.id);
    if(item.analysis_id!==payload.analysis_id||item.version!==payload.expected_version)throw Error('Review conflict');
    item.analysis=clone(payload.analysis);item.version++;item.analysis_status='approved';item.status='reviewed';
    return {ok:true,analysis:{...item.analysis,id:item.analysis_id,version:item.version,status:'approved'}};
  }
  if(path.startsWith('image-analysis-batch.php')&&!payload){
    batchReads.push(path);
    const params=new URL(path,'https://stonefellow.test/admin/api/').searchParams;
    const job=params.has('id')?savedJobs.find(item=>item.id===params.get('id')):batchJob;
    return {ok:true,jobs:savedJobs.map(project),job:job?project(job):null};
  }
  if(path!=='image-analysis-batch.php')throw Error('Unexpected API request '+path);
  batchPosts.push(clone(payload));
  if(payload.action==='create'){
    const ids=payload.mode==='missing'?[17,18,19]:payload.ids;
    batchJob={id:payload.request_id,scope:'media',status:'running',created_at:'2026-11-05T12:00:00Z',remaining_count:payload.mode==='missing'?4:0,
      items:ids.map(id=>({image_id:id,request_id:id.toString(16).padStart(32,'0'),title:'Image '+id,image_url:'api/media.php?id=image-'+id,status:'pending',analysis:null,analysis_id:null,analysis_status:null,version:0}))};
    savedJobs.unshift(batchJob);return {ok:true,job:project(batchJob)};
  }
  const job=savedJobs.find(item=>item.id===payload.id);if(!job)throw Error('Batch not found');
  if(payload.action==='step'){
    const item=job.items.find(item=>item.request_id===payload.request_id);
    if(!item||item.status!=='pending')throw Error('Unexpected or repeated step');
    item.status='processing';activeSteps++;peakSteps=Math.max(peakSteps,activeSteps);
    if(window.holdNextStep){window.holdNextStep=false;await new Promise(resolve=>window.releaseStep=resolve);}
    if(window.failStep){window.failStep=false;item.status='failed';item.error='The provider outcome needs review';activeSteps--;project(job);throw Error('The connection was interrupted');}
    item.status='completed';item.analysis=clone(analysisFields);item.analysis_id=item.request_id;item.analysis_status='pending_review';item.version=1;activeSteps--;
    const response=project(job);
    if(window.staleStepResponse){window.staleStepResponse=false;response.status='running';}
    return {ok:true,job:response};
  }
  if(payload.action==='pause'){if(job.status==='running')job.status='paused';return {ok:true,job:project(job)};}
  if(payload.action==='resume'){if(job.status==='paused')job.status='running';return {ok:true,job:project(job)};}
  if(payload.action==='cancel'){job.status='canceled';for(const item of job.items)if(item.status==='pending')item.status='skipped';return {ok:true,job:project(job)};}
  if(payload.action==='review'){
    const results=[];
    for(const draft of payload.items){
      const item=job.items.find(item=>item.image_id===draft.image_id);
      const ok=item?.analysis_id===draft.analysis_id&&item.version===draft.version;
      if(ok){item.analysis=clone(draft.analysis);item.version++;item.status='reviewed';item.analysis_status='approved';}
      results.push({image_id:draft.image_id,ok});
    }
    return {ok:true,job:project(job),review_results:results};
  }
  throw Error('Unexpected batch action');
}
window.STONEFELLOW_ADMIN_CONTEXT={app:{view:'media',catalog:[]},api,canvas:document.querySelector('main'),head:(kicker,title,description,actions)=>'<header><h1>'+esc(title)+'</h1>'+actions+'</header>',say(){},esc,
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
        page.evaluate('SFMediaAdmin.renderMediaLibrary()')
        prefix = f'{width}px '
        check(page.get_by_role('checkbox', name='Select Song audio for batch analysis', exact=True).count() == 0,
              prefix + 'batch selection applies to managed image cards')
        page.get_by_role('checkbox', name='Select visible images', exact=True).check()
        page.get_by_role('checkbox', name='Select Image 19 for batch analysis', exact=True).uncheck()
        page.get_by_role('button', name='Analyze selected', exact=True).click()
        batch = page.get_by_role('dialog', name='Batch image analysis', exact=True)
        expect(batch).to_be_visible()
        page.wait_for_function('typeof releaseStep==="function"&&activeSteps===1')
        check(page.evaluate('batchPosts[0].mode==="selected"&&batchPosts[0].ids.join(",")==="17,18"&&batchPosts[1].request_id===batchJob.items[0].request_id'),
              prefix + 'selected batch snapshots exact image IDs and binds its first analysis request')
        batch.get_by_role('button', name='Pause', exact=True).click()
        page.wait_for_function('batchPosts.some(post=>post.action==="pause")')
        check(page.evaluate('activeSteps===1&&batchJob.status==="paused"'),
              prefix + 'Pause persists while the current analysis is still in flight')
        page.evaluate('window.staleStepResponse=true;releaseStep()')
        page.wait_for_function('activeSteps===0&&batchReads.some(path=>path.includes("?id="))')
        expect(batch.locator('.media-image-batch-status')).to_contain_text('Batch paused')
        check(page.evaluate('batchPosts.filter(post=>post.action==="step").length===1'),
              prefix + 'canonical recovery ignores a stale running response and stops before the next image')
        batch.get_by_role('button', name='Resume', exact=True).click()
        page.wait_for_function('batchJob.status==="completed"')
        expect(batch.locator('.media-image-batch-status')).to_contain_text('Batch completed')
        check(page.evaluate('peakSteps===1&&batchPosts.filter(post=>post.action==="step").length===2') and
              'Active Tokens charged 240' in batch.inner_text(), prefix + 'Resume runs one image at a time and displays accumulated usage')
        check(batch.locator('b').count() == 0, prefix + 'draft descriptions and OCR are rendered as text')
        batch.get_by_role('button', name='Select all drafts', exact=True).click()
        batch.get_by_role('button', name='Edit analysis', exact=True).first.click()
        expect(batch).not_to_be_visible()
        editor = page.locator('.media-modal')
        expect(editor).to_be_visible()
        editor.get_by_label('Visible description', exact=True).fill('Edited description for Image 17')
        editor.get_by_role('button', name='Save reviewed analysis', exact=True).click()
        expect(editor.get_by_text('Reviewed analysis saved.', exact=True)).to_be_visible()
        editor.get_by_role('button', name='Close', exact=True).click()
        expect(batch).to_be_visible()
        expect(batch.get_by_role('checkbox', name='Approve analysis for Image 18', exact=True)).to_be_checked()
        check(batch.get_by_role('checkbox', name='Approve analysis for Image 17', exact=True).count() == 0 and
              'Edited description for Image 17' in batch.inner_text(),
              prefix + 'individual editing refreshes canonical versions and removes already approved selections')
        batch.get_by_role('button', name='Approve selected drafts', exact=True).click()
        page.wait_for_function('batchJob.counts.reviewed===2')
        check(page.evaluate('''const review=batchPosts.find(post=>post.action==='review');
          review.items.length===1&&review.items[0].image_id===18&&review.items[0].version===1&&
          review.items[0].analysis_id===batchJob.items[1].request_id&&Object.keys(review.items[0].analysis).length===8'''),
              prefix + 'batch approval sends only explicitly selected exact analysis versions and fields')
        check(batch.locator('.media-image-batch-panel').evaluate('node=>node.scrollWidth<=node.clientWidth+1'),
              prefix + 'progress, draft details and controls fit the viewport')
        batch.get_by_role('button', name='Close', exact=True).click()

        # Closing a batch saves Pause immediately while its active image finishes.
        page.evaluate('window.holdNextStep=true;delete window.releaseStep')
        page.get_by_role('button', name='Analyze missing images', exact=True).click()
        expect(batch).to_be_visible()
        page.wait_for_function('typeof releaseStep==="function"&&activeSteps===1')
        check('4 more images' in batch.inner_text(), prefix + 'missing-image batches disclose remaining inventory beyond the snapshot')
        batch.get_by_role('button', name='Close', exact=True).click()
        expect(batch).not_to_be_visible()
        page.wait_for_function('batchJob.status==="paused"')
        check(page.evaluate('activeSteps===1'), prefix + 'closing persists pause without canceling the active analysis')
        page.evaluate('releaseStep()')
        page.wait_for_function('activeSteps===0')
        page.get_by_role('button', name='Open saved batch', exact=True).click()
        expect(batch).to_be_visible()
        expect(batch.locator('.media-image-batch-status')).to_contain_text('Batch paused')
        check(page.evaluate('batchJob.counts.pending===2&&batchJob.counts.completed===1'),
              prefix + 'reopening recovers durable progress without starting more analyses')
        batch.get_by_role('button', name='Close', exact=True).click()

        # An uncertain step stops the driver and recovers through reads only.
        page.evaluate('window.failStep=true;window.holdNextStep=false;window.beforeFailedSteps=batchPosts.filter(post=>post.action==="step").length')
        page.get_by_role('button', name='Analyze missing images', exact=True).click()
        expect(batch).to_be_visible()
        page.wait_for_function('batchJob.counts.failed===1&&batchReads.at(-1).includes(batchJob.id)')
        expect(batch.get_by_role('status')).to_contain_text('connection was interrupted')
        check(page.evaluate('batchPosts.filter(post=>post.action==="step").length===beforeFailedSteps+1&&batchJob.counts.pending===2'),
              prefix + 'uncertain results trigger GET recovery and require explicit Resume')
        batch.get_by_role('button', name='Close', exact=True).click()

        # Cancel also persists independently from a held provider call.
        page.evaluate('window.holdNextStep=true;delete window.releaseStep')
        page.get_by_role('button', name='Analyze missing images', exact=True).click()
        expect(batch).to_be_visible()
        page.wait_for_function('typeof releaseStep==="function"&&activeSteps===1')
        batch.get_by_role('button', name='Cancel batch', exact=True).click()
        page.wait_for_function('batchJob.status==="canceled"')
        check(page.evaluate('activeSteps===1&&batchJob.counts.skipped===2'),
              prefix + 'Cancel persists immediately and skips the pending images')
        page.evaluate('window.staleStepResponse=true;releaseStep()')
        page.wait_for_function('activeSteps===0')
        expect(batch.locator('.media-image-batch-status')).to_contain_text('Batch canceled')
        page.keyboard.press('Escape')
        expect(batch).not_to_be_visible()
        check(not errors, prefix + 'no browser errors')
        context.close()
    browser.close()
print(json.dumps({'checks': len(CHECKS), 'browser': 'chromium', 'widths': [390, 1280]}))
