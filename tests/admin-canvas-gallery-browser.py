"""Explicit admin gallery review with the production opaque sandbox renderer."""
import base64
import json
import os
from pathlib import Path
import shutil
import sys

sys.dont_write_bytecode = True
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
checks = []
PIXEL = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


SETUP = r'''async() => {
 const copy=value=>JSON.parse(JSON.stringify(value));
 window.fixture={reads:[],posts:[],failList:true,failDetail:false,holdDetail:false,holdMutation:false};
 window.ids={a:'1'.repeat(32),b:'2'.repeat(32),c:'3'.repeat(32),d:'4'.repeat(32),e:'5'.repeat(32),f:'6'.repeat(32)};
 const item=(id,title,status)=>({id,title,status,description:'Submitted <em>description</em>',creator_label:'Creator <b>name</b>',
  snapshot_hash:'f'.repeat(64),submitted_at:'2026-11-06 10:00:00',feedback:''});
 fixture.items=[item(ids.a,'<b>Daylight canvas</b>','pending'),item(ids.b,'Second submission','pending'),
  item(ids.c,'Approved submission','approved'),item(ids.d,'Returned submission','returned'),item(ids.e,'Declined submission','declined'),item(ids.f,'Withdrawn submission','withdrawn'),
  ...Array.from({length:27},(_,index)=>item((index+100).toString(16).padStart(32,'0'),'Canvas '+(index+100),'pending'))];
 const js="document.querySelector('.title').textContent='Explicit preview ran';";
 const hash=btoa(String.fromCharCode(...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(js)))));
 fixture.scene={title:'Immutable submitted scene',canvas_kind:'custom',html:'<section><h1 class="title">Saved heading</h1><div class="sf-image-'+ 'ab'.repeat(16)+'"></div></section>',
  css:'section{padding:20px;background:navy;color:linen}h1{font-size:24px}',js,js_hash:hash,
  image_assets:[{id:'ab'.repeat(16),title:'Canvas image 1',width:1,height:1,url:'/api/canvas-gallery-image.php?asset=one'}]};
 window.galleryApi=async(path,payload)=>{
  if(!path.startsWith('canvas-gallery.php'))throw Error('Unexpected API call');
  if(payload!==undefined){
   fixture.posts.push(copy(payload));
   if(fixture.holdMutation){fixture.holdMutation=false;await new Promise(resolve=>window.releaseMutation=resolve)}
   const current=fixture.items.find(value=>value.id===payload.id);
   if(payload.snapshot_hash!==current.snapshot_hash)throw Error('Snapshot changed');
   current.status={approve:'approved',return:'returned',decline:'declined',unpublish:'returned'}[payload.action];current.feedback=payload.feedback;
   return {ok:true,item:copy(current)};
  }
  fixture.reads.push(path);const query=new URL(path,'https://admin-gallery.test/').searchParams;
  if(query.has('id')){
   const id=query.get('id'),result={ok:true,item:copy(fixture.items.find(value=>value.id===id)),scene:copy(fixture.scene)};
   if(fixture.failDetail){fixture.failDetail=false;throw Error('Submission temporarily unavailable')}
   if(fixture.holdDetail){fixture.holdDetail=false;await new Promise(resolve=>window.releaseDetail=()=>resolve(result));return result}
   return result;
  }
  if(fixture.failList){fixture.failList=false;throw Error('List temporarily unavailable')}
  const status=query.get('status')||'pending',q=(query.get('q')||'').toLowerCase(),page=Number(query.get('page')||1),limit=24;
  const values=fixture.items.filter(value=>(status==='all'||value.status===status)&&(!q||[value.title,value.description,value.creator_label].join(' ').toLowerCase().includes(q)));
  return {ok:true,items:copy(values.slice((page-1)*limit,page*limit)),page,limit,total:values.length,has_more:page*limit<values.length};
 };
 window.controller=SFCanvasGalleryAdmin.renderInto('adminCanvas',{api:galleryApi});
 await controller.ready;
}'''

with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        launch['executable_path'] = executable
    browser = playwright.chromium.launch(**launch)
    for width in (375, 1440):
        context = browser.new_context(viewport={'width': width, 'height': 900}, reduced_motion='reduce', has_touch=width == 375)
        page = context.new_page()
        errors, requests = [], []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('request', lambda request: requests.append(request.url))
        page.route('https://admin-gallery.test/**', lambda route: route.fulfill(status=200,
                   content_type='image/png' if '/api/canvas-gallery-image.php' in route.request.url else 'text/html',
                   body=PIXEL if '/api/canvas-gallery-image.php' in route.request.url else '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main id="adminCanvas" class="admin-canvas"></main></body></html>'))
        page.goto('https://admin-gallery.test/admin/')
        for filename in ['admin/assets/admin.css', 'admin/assets/canvas-gallery.css']:
            page.add_style_tag(content=(ROOT / filename).read_text())
        for filename in ['assets/js/canvas-audio-sdk.js', 'assets/js/vendor/acorn.js', 'assets/js/canvas-preview-guard.js',
                         'assets/js/public-agent-canvas.js', 'admin/assets/canvas-gallery.js']:
            page.add_script_tag(content=(ROOT / filename).read_text())
        page.evaluate(SETUP)
        prefix = f'chromium/{width}: '
        root = page.locator('.sf-admin-canvas-gallery')
        index = page.get_by_role('region', name='Canvas submissions', exact=True)
        detail = page.get_by_role('region', name='Canvas submission detail', exact=True)
        expect(root.get_by_role('button', name='Retry list', exact=True)).to_be_visible()
        check(page.evaluate('fixture.posts.length===0') and page.locator('iframe').count() == 0,
              prefix + 'failed list reads display Retry without review writes or preview execution')
        root.get_by_role('button', name='Retry list', exact=True).click()
        expect(index.locator('li')).to_have_count(24)
        root.get_by_role('button', name='Next page', exact=True).click()
        expect(index.get_by_text('Page 2', exact=True)).to_be_visible()
        expect(index.locator('li')).to_have_count(5)
        root.get_by_role('button', name='Previous page', exact=True).click()
        expect(index.locator('li')).to_have_count(24)
        check(page.evaluate('fixture.posts.length===0'), prefix + 'server pagination reads the full queue without starting a model or changing submissions')
        page.evaluate('fixture.failDetail=true')
        index.get_by_role('button', name='<b>Daylight canvas</b>', exact=True).click()
        expect(detail.get_by_role('button', name='Retry submission', exact=True)).to_be_visible()
        detail.get_by_role('button', name='Retry submission', exact=True).click()
        expect(detail.get_by_role('heading', name='<b>Daylight canvas</b>', exact=True)).to_be_visible()
        check(detail.locator('h2 b,em,img').count() == 0 and 'Creator <b>name</b>' in detail.inner_text() and
              page.locator('iframe').count() == 0 and not any('/api/canvas-gallery-image.php' in url for url in requests),
              prefix + 'detail retry shows immutable title, description and attribution as text without running code or loading assets')
        detail.get_by_text('HTML', exact=True).click()
        expect(detail.locator('pre').first).to_have_text(page.evaluate('fixture.scene.html'))
        detail.get_by_text('Asset manifest', exact=True).click()
        check('Canvas image 1' in detail.locator('pre').last.inner_text() and 'ffffffffffffffff' in detail.inner_text(),
              prefix + 'review exposes exact submitted source, copied asset manifest and snapshot identity without editing them')
        detail.get_by_role('button', name='Preview submitted version', exact=True).click()
        frame = page.frame_locator('.sf-canvas-gallery-preview-host iframe')
        expect(frame.get_by_role('heading', name='Explicit preview ran', exact=True)).to_be_visible()
        frame.locator('img').wait_for()
        page.wait_for_function('''()=>{const frame=document.querySelector('.sf-canvas-gallery-preview-host iframe');return frame&&frame.contentDocument===null}''')
        check(frame.locator('img').evaluate('image=>image.complete&&image.naturalWidth===1') and
              page.locator('iframe').get_attribute('sandbox') == 'allow-scripts' and
              'microphone \'none\'' in page.locator('iframe').get_attribute('allow') and
              detail.get_by_text('Visual preview; personal data and playback are not tested.', exact=True).is_visible() and page.evaluate('fixture.posts.length===0'),
              prefix + 'explicit Preview runs the exact guarded production sandbox and copied-image bridge with no personal capability or review write')
        detail.get_by_role('button', name='Close preview', exact=True).click()
        expect(page.locator('iframe')).to_have_count(0)
        expect(detail.get_by_role('button', name='Preview submitted version', exact=True)).to_be_focused()
        check(True, prefix + 'closing preview removes the frame and restores keyboard focus')
        feedback = detail.get_by_role('textbox', name='Feedback to creator', exact=True)
        feedback.fill('Strong concept')
        page.evaluate('fixture.holdMutation=true')
        detail.get_by_role('button', name='Approve and publish', exact=True).click()
        page.wait_for_function('typeof releaseMutation==="function"')
        expect(detail.get_by_role('button', name='Approve and publish', exact=True)).to_be_disabled()
        check(page.evaluate('fixture.posts.length===1&&fixture.posts[0].action==="approve"&&fixture.posts[0].snapshot_hash==="f".repeat(64)'),
              prefix + 'publishing requires a direct action bound to the exact snapshot and disables duplicate decisions')
        page.evaluate('releaseMutation();delete window.releaseMutation')
        expect(detail.get_by_role('button', name='Unpublish', exact=True)).to_be_enabled()
        detail.get_by_role('textbox', name='Feedback to creator', exact=True).fill('Withdraw while the creator revises it.')
        detail.get_by_role('button', name='Unpublish', exact=True).click()
        expect(detail.get_by_text('This submission has no available review actions.', exact=True)).to_be_visible()
        check(page.evaluate('fixture.items.find(value=>value.id===ids.a).status==="returned"&&fixture.posts.at(-1).action==="unpublish"'),
              prefix + 'Unpublish is a separate direct decision with creator feedback')
        detail.get_by_role('button', name='Back to submissions', exact=True).click()
        index.get_by_role('button', name='Second submission', exact=True).click()
        detail.get_by_role('button', name='Return for changes', exact=True).click()
        expect(detail.get_by_text('Add feedback explaining the changes the creator should make.', exact=True)).to_be_visible()
        detail.get_by_role('textbox', name='Feedback to creator', exact=True).fill('✨' * 667)
        detail.get_by_role('button', name='Return for changes', exact=True).click()
        expect(detail.get_by_text('Please shorten the feedback to 2,000 UTF-8 bytes.', exact=True)).to_be_visible()
        check(page.evaluate('fixture.posts.length===2'), prefix + 'return requires useful feedback and rejects oversized UTF-8 text before any write')
        detail.get_by_role('textbox', name='Feedback to creator', exact=True).fill('Please improve the text contrast.')
        detail.get_by_role('button', name='Return for changes', exact=True).click()
        expect(detail.get_by_text('This submission has no available review actions.', exact=True)).to_be_visible()
        detail.get_by_role('button', name='Back to submissions', exact=True).click()
        index.get_by_role('button', name='Canvas 100', exact=True).click()
        detail.get_by_role('button', name='Decline', exact=True).click()
        expect(detail.get_by_text('This submission has no available review actions.', exact=True)).to_be_visible()
        check(page.evaluate('fixture.posts.map(value=>value.action).join(",")==="approve,unpublish,return,decline"&&fixture.posts.every(value=>Object.keys(value).sort().join(",")==="action,feedback,id,snapshot_hash")'),
              prefix + 'all four review actions change only status and feedback for their immutable snapshot')
        for status in ['pending', 'approved', 'returned', 'declined', 'withdrawn']:
            root.get_by_label('Submission status', exact=True).select_option(status)
            page.wait_for_function('status=>fixture.reads.at(-1).includes("status="+status)', arg=status)
        expect(index.get_by_role('button', name='Withdrawn submission', exact=True)).to_be_visible()
        root.get_by_role('searchbox', name='Search submissions', exact=True).fill('no matches')
        root.get_by_role('button', name='Search', exact=True).click()
        expect(index.get_by_text('No submissions match this filter.', exact=True)).to_be_visible()
        check(page.evaluate('fixture.posts.length===4'), prefix + 'every moderation state and the search empty state use reads only')
        root.get_by_role('searchbox', name='Search submissions', exact=True).fill('')
        root.get_by_label('Submission status', exact=True).select_option('all')
        page.evaluate('fixture.holdDetail=true;void controller.open(ids.b)')
        page.wait_for_function('typeof releaseDetail==="function"')
        root.get_by_label('Submission status', exact=True).select_option('withdrawn')
        page.evaluate('releaseDetail();delete window.releaseDetail')
        expect(detail.get_by_text('Choose a submission to review its immutable source, assets and creator attribution.', exact=True)).to_be_visible()
        check(page.locator('iframe').count() == 0 and 'Second submission' not in detail.inner_text(), prefix + 'a late detail response cannot restore a submission after its filter changes')
        root.get_by_label('Submission status', exact=True).select_option('all')
        page.evaluate('void controller.open(ids.a)')
        expect(detail.get_by_role('button', name='Preview submitted version', exact=True)).to_be_visible()
        detail.get_by_role('button', name='Preview submitted version', exact=True).click()
        expect(frame.get_by_role('heading', name='Explicit preview ran', exact=True)).to_be_visible()
        geometry = root.evaluate('''root=>({fits:root.scrollWidth<=root.clientWidth,
          scrolls:[root,...root.querySelectorAll('*')].filter(element=>['auto','scroll'].includes(getComputedStyle(element).overflowY)&&element.scrollHeight>element.clientHeight).length})''')
        check(geometry['fits'] and geometry['scrolls'] == 0, prefix + 'responsive review uses one page scroll without clipped source or nested panels')
        page.screenshot(path=f'/tmp/sf293-admin-canvas-gallery-{width}.png')
        page.evaluate('SFCanvasGalleryAdmin.destroy()')
        check(page.locator('iframe').count() == 0 and not root.count() and not errors and
              not any('openai' in url or 'anthropic' in url for url in requests),
              prefix + 'route cleanup removes the sandbox with no browser errors or provider calls: ' + repr(errors))
        context.close()
    browser.close()

print(json.dumps({'ok': True, 'checks': len(checks)}))
