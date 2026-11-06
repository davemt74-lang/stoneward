"""Public gallery, exact-version submission and account transitions in the actual UI module."""
import os
from pathlib import Path
import shutil
import sys

sys.dont_write_bytecode = True
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/gallery/'
checks = []


def check(value, message):
    assert value, message
    checks.append(message)
    print('PASS', message, flush=True)


FIXTURE = r'''() => {
  window.owner='';window.calls=[];window.opened=[];window.remixed=[];window.signIns=0;window.copied=[];
  window.failList=true;window.failSubmit=true;window.deferMine=false;window.deferList=false;window.pendingMine=null;window.pendingList=null;
  window.publicItems=Array.from({length:26},(_,index)=>({id:(index+1).toString(16).padStart(32,'0'),
    title:index===0?'<img src=x onerror=alert(1)>':'Published canvas '+(index+1),
    description:'An independently designed Stonefellow experience.',creator_label:index===0?'<script>Creator</script>':'Community creator',
    uses:[{id:'canvas.data.personal_listening_stats',label:'Personal listening statistics',personal:true,enabled:true},{id:'canvas.data.published_catalog',label:'Published songs',personal:false,enabled:false}],
    preview_url:index===0?'https://untrusted.invalid/preview.png':index===1?'/api/canvas-gallery-preview.php?id=2':null,
    ...(index===2?{attribution:{source:{id:'c'.repeat(32),title:'Original geometry',creator_label:'Original creator'},ancestors:[{id:'b'.repeat(32),title:'Earlier geometry',creator_label:'Earlier creator'}]}}:{})}));
  window.mine=[{...publicItems[3],status:'pending',source_scene_id:11},
    {...publicItems[4],status:'returned',source_scene_id:12,feedback:'Please improve the contrast before resubmitting.'},
    {...publicItems[5],status:'declined',source_scene_id:12,feedback:'This submission was declined.'}];
  window.sources=[{id:11,title:'Exact private version',design_brief:'Do not turn this original prompt into public description.'},
    {id:12,title:'Another saved version',gallery_attribution:{title:'Original geometry',creator_label:'Original creator'}}];
  const imageId='abcdef0123456789abcdef0123456789';
  const listing=(rows,query)=>{const selected=rows.filter(row=>(row.title+' '+row.creator_label).toLowerCase().includes(query.q.toLowerCase()));
    return {ok:true,items:selected.slice((query.page-1)*query.limit,query.page*query.limit),page:query.page,total:selected.length,has_more:query.page*query.limit<selected.length};};
  window.api={
    list:async(query,signal)=>{calls.push({action:'list',query,signal});
      if(deferList)return new Promise(resolve=>{pendingList={resolve,signal};});
      if(failList){failList=false;throw new Error('Gallery is temporarily unavailable.');}return listing(publicItems,query);},
    detail:async(id,signal)=>{calls.push({action:'detail',id,signal});const item=publicItems.find(row=>row.id===id);if(!item)throw new Error('Canvas unavailable.');return {ok:true,item};},
    mine:async(query,signal)=>{calls.push({action:'mine',query,signal});if(deferMine)return new Promise(resolve=>{pendingMine={resolve,signal};});return listing(mine,query);},
    sources:async(signal)=>{calls.push({action:'sources',signal});return {scenes:sources};},
    source:async(id,signal)=>{calls.push({action:'source',id,signal});return {scene:{...sources.find(row=>row.id===id),image_assets:id===11?[{id:imageId,title:'Existing canvas artwork'}]:[]}};},
    submit:async(payload,signal)=>{calls.push({action:'submit',payload,signal});if(failSubmit){failSubmit=false;throw new Error('Submission connection interrupted. Try again.');}
      const item={...payload,id:'f'.repeat(32),status:'pending',source_scene_id:payload.scene_id};mine.unshift(item);return {ok:true,item};},
    withdraw:async(id,signal)=>{calls.push({action:'withdraw',id,signal});const index=mine.findIndex(row=>row.id===id);mine[index]={...mine[index],status:'withdrawn'};return {ok:true,item:mine[index]};}
  };
  Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async(value)=>{if(window.clipboardDenied)throw new DOMException('Denied','NotAllowedError');copied.push(value);}}});
  window.gallery=SFCanvasGallery.create({api,getOwner:()=>owner,getCreatorLabel:()=> 'Listener creator',
    getPreviewUrl:id=>'/api/private-canvas-image.php?id='+id,
    getItemUrl:item=>location.origin+'/?view=canvas-gallery&id='+item.id,
    onOpen:async(item,signal)=>{opened.push({id:item.id,signal});},
    onRemix:async(item,signal)=>{remixed.push({id:item.id,signal});},onSignIn:()=>{signIns++;}});
  gallery.mount(document.querySelector('#target'));
}'''


with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        launch['executable_path'] = executable
    browser = playwright.chromium.launch(**launch)
    for width in (390, 1440):
        prefix = f'Chromium/{width} '
        context = browser.new_context(viewport={'width': width, 'height': 900}, reduced_motion='reduce', has_touch=width == 390)
        page = context.new_page()
        page.set_default_timeout(6000)
        errors, requests = [], []
        page.on('pageerror', lambda error: errors.append(str(error)))

        def respond(route):
            requests.append(route.request.url)
            if route.request.url == SITE:
                route.fulfill(content_type='text/html', body='''<!doctype html><html lang="en"><head>
                <meta name="viewport" content="width=device-width,initial-scale=1"></head>
                <body style="margin:0;background:#0c1421;color:#f1f0e9"><div id="live">Existing canvas</div><main id="target"></main></body></html>''')
            elif '/api/' in route.request.url:
                route.fulfill(content_type='image/svg+xml', body='<svg xmlns="http://www.w3.org/2000/svg" width="400" height="240"><rect width="400" height="240" fill="#263d55"/><circle cx="220" cy="120" r="65" fill="#76abc1"/></svg>')
            else:
                route.abort()

        page.route('**/*', respond)
        page.goto(SITE)
        page.add_style_tag(content=(ROOT / 'assets/css/canvas-gallery.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-gallery.js').read_text())
        page.evaluate(FIXTURE)
        expect(page.get_by_role('alert')).to_contain_text('temporarily unavailable')
        page.get_by_role('button', name='Try again', exact=True).click()
        expect(page.locator('.sf-gallery-card')).to_have_count(24)
        page.locator('.sf-gallery-card').first.get_by_text('What this canvas uses', exact=True).click()
        check(page.locator('.sf-gallery-card').first.get_by_text('Personal listening statistics · Your data; permission required', exact=True).is_visible() and page.locator('.sf-gallery-card').first.get_by_text('Published songs · Public artist data · Disabled by administrator', exact=True).is_visible(), prefix + 'catalog cards disclose requested personal data and disabled public capabilities')
        check(True, prefix + 'catalog failures expose retry and load the first real page')
        check(page.locator('.sf-gallery-card script').count() == 0 and page.locator('.sf-gallery-card').first.locator('h3').inner_text() == '<img src=x onerror=alert(1)>',
              prefix + 'public titles and creator labels remain text instead of executable markup')
        check(not any('untrusted.invalid' in url for url in requests), prefix + 'gallery preview images stay on the authorized origin')
        page.get_by_role('button', name='More canvases', exact=True).click()
        expect(page.locator('.sf-gallery-card')).to_have_count(26)
        check(page.evaluate('calls.filter(row=>row.action==="list").map(row=>row.query.page).join(",")') == '1,1,2',
              prefix + 'pagination reaches every returned entry without silently stopping at the first page')
        search = page.get_by_label('Search shared canvases')
        search.fill('Published canvas 26')
        page.get_by_role('button', name='Search', exact=True).click()
        expect(page.locator('.sf-gallery-card')).to_have_count(1)
        check(page.locator('.sf-gallery-card h3').inner_text() == 'Published canvas 26', prefix + 'search queries the catalog across pages')
        page.get_by_role('button', name='Open Published canvas 26', exact=True).click()
        check(page.evaluate('opened.length===1&&document.querySelector("#live").textContent==="Existing canvas"'),
              prefix + 'Open is an explicit host callback and the component preserves the active canvas')
        page.get_by_role('button', name='Remix Published canvas 26', exact=True).click()
        check(page.evaluate('signIns===1&&remixed.length===0'), prefix + 'guest remix requests sign-in without creating a copy')
        page.get_by_role('button', name='Copy link to Published canvas 26', exact=True).click()
        expect(page.locator('.sf-gallery-card-state')).to_contain_text('Link copied')
        check(page.evaluate('copied.length===1&&copied[0].endsWith(publicItems[25].id)'), prefix + 'Copy link uses the approved entry identity')
        page.evaluate('window.clipboardDenied=true')
        page.get_by_role('button', name='Copy link to Published canvas 26', exact=True).click()
        share = page.get_by_label('Share link', exact=True)
        expect(share).to_be_visible()
        check(share.input_value().endswith(page.evaluate('publicItems[25].id')) and share.get_attribute('readonly') is not None,
              prefix + 'clipboard denial reveals a selectable read-only link without an extra permission flow')
        page.evaluate('owner="owner-one";gallery.setOwner()')
        expect(page.locator('.sf-gallery-card')).to_have_count(24)
        page.get_by_role('button', name='Remix Published canvas 2', exact=True).click()
        check(page.evaluate('remixed.length===1'), prefix + 'signed-in remix delegates only the selected published entry')

        page.get_by_role('button', name='My submissions', exact=True).click()
        expect(page.locator('.sf-gallery-card')).to_have_count(3)
        check(page.get_by_text('Awaiting review', exact=True).count() == 1 and page.get_by_text('Please improve the contrast before resubmitting.', exact=True).count() == 1,
              prefix + 'owners see actual submission status and review feedback')
        check(page.get_by_text('Returned for changes', exact=True).count() == 1 and page.get_by_text('Declined', exact=True).count() == 1,
              prefix + 'returned and declined moderation states retain their distinct labels')
        page.get_by_role('button', name='Withdraw Published canvas 4', exact=True).click()
        expect(page.get_by_text('Withdrawn', exact=True)).to_be_visible()
        check(page.get_by_role('button', name='Withdraw Published canvas 4', exact=True).count() == 0,
              prefix + 'withdrawal updates the owned submission and removes its repeat action')

        page.evaluate('void gallery.show("submit",{sceneId:11})')
        expect(page.get_by_label('Saved canvas version')).to_have_value('11')
        expect(page.get_by_label('Preview image (optional)')).to_have_count(1)
        page.wait_for_function('document.querySelector("select[name=preview_asset_id]").options.length===2')
        check(page.get_by_label('Public title').input_value() == 'Exact private version' and page.get_by_label('Public description').input_value() == '',
              prefix + 'submission selects an immutable saved version without publishing its original prompt as description')
        page.get_by_label('Preview image (optional)').select_option('abcdef0123456789abcdef0123456789')
        expect(page.locator('.sf-gallery-submit-preview')).to_be_visible()
        page.get_by_label('Saved canvas version').select_option('12')
        page.wait_for_function('document.querySelector("select[name=preview_asset_id]").options.length===1')
        check(page.get_by_text('Remixed from Original geometry by Original creator', exact=True).count() == 1 and not page.locator('.sf-gallery-submit-preview').is_visible(),
              prefix + 'switching versions refreshes its attribution and clears an unrelated preview image')
        page.get_by_label('Saved canvas version').select_option('11')
        page.wait_for_function('document.querySelector("select[name=preview_asset_id]").options.length===2')
        page.get_by_label('Preview image (optional)').select_option('abcdef0123456789abcdef0123456789')
        page.get_by_label('Public title').fill('My exact public version')
        page.get_by_label('Public description').fill('A public description written for this submission.')
        page.get_by_role('button', name='Submit for review', exact=True).click()
        check(page.evaluate('calls.filter(row=>row.action==="submit").length===0'), prefix + 'publication requires explicit code and image consent')
        page.get_by_label('I agree to publish this saved version’s code and image assets, including the selected preview.').check()
        page.get_by_role('button', name='Submit for review', exact=True).click()
        expect(page.locator('.sf-gallery-form-state')).to_contain_text('connection interrupted')
        page.get_by_role('button', name='Submit for review', exact=True).click()
        expect(page.locator('.sf-gallery-badge').first).to_have_text('Awaiting review')
        check(page.evaluate('''(()=>{const rows=calls.filter(row=>row.action==='submit');return rows.length===2&&
          rows[0].payload.request_id===rows[1].payload.request_id&&rows[1].payload.scene_id===11&&rows[1].payload.publication_consent===true&&
          rows[1].payload.preview_asset_id==='abcdef0123456789abcdef0123456789'&&!('design_brief' in rows[1].payload)&&!('html' in rows[1].payload);})()'''),
              prefix + 'submission retries preserve request identity, exact source version and chosen authorized preview')

        page.evaluate('deferMine=true;void gallery.show("mine")')
        page.wait_for_function('window.pendingMine!==null')
        page.evaluate('owner="";gallery.setOwner();pendingMine.resolve({ok:true,items:[{id:"e".repeat(32),title:"Private stale submission",status:"pending"}],has_more:false})')
        expect(page.get_by_text('Sign in to manage and submit your canvases.', exact=True)).to_be_visible()
        check(page.evaluate('pendingMine.signal.aborted') and page.get_by_text('Private stale submission', exact=True).count() == 0,
              prefix + 'account changes abort private reads and suppress late owner data')

        page.evaluate('''()=>{window.beforeOpenCount=opened.length;void gallery.show('public',{itemId:publicItems[2].id});}''')
        expect(page.locator('.sf-gallery-card')).to_have_count(1)
        check(page.evaluate('opened.length===beforeOpenCount&&calls.at(-1).action==="detail"') and page.get_by_text('Remixed from Original geometry by Original creator', exact=True).count() == 1,
              prefix + 'a shared entry link shows approved details and attribution without executing the canvas')
        check(page.get_by_text('Remixed from Earlier geometry by Earlier creator', exact=True).count() == 1,
              prefix + 'the public attribution DTO retains both direct source and earlier creators')
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), prefix + 'the gallery fits the viewport width')
        check(page.locator('.sf-canvas-gallery').evaluate("root=>![...root.querySelectorAll('*')].some(node=>!['TEXTAREA','INPUT','SELECT'].includes(node.tagName)&&['auto','scroll'].includes(getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+2)"),
              prefix + 'the component uses the page scroll without nested scrolling containers')
        buttons = page.locator('.sf-gallery-card-actions button')
        buttons.first.focus()
        page.keyboard.press('Tab')
        check(page.evaluate('document.activeElement.textContent') == 'Remix' and buttons.evaluate_all('(rows)=>rows.every(row=>row.getBoundingClientRect().height>=44)'),
              prefix + 'card controls follow native keyboard order and retain touch targets')
        page.screenshot(path=f'/tmp/canvas-gallery-{width}.png')
        page.evaluate('deferList=true;void gallery.show("public")')
        page.wait_for_function('window.pendingList!==null')
        page.evaluate('gallery.destroy();pendingList.resolve({ok:true,items:publicItems,page:1,total:26,has_more:true})')
        check(page.evaluate('pendingList.signal.aborted&&document.querySelector("#target").children.length===0'),
              prefix + 'unmount aborts in-flight catalog reads and removes the component')
        check(not errors, prefix + 'the production gallery module reports no browser exceptions')
        context.close()
    browser.close()
print(f'Canvas gallery: {len(checks)} browser checks passed', flush=True)
