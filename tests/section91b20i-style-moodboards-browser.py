"""Real Chromium render/interaction checks with synthetic Admin API receipts; no provider calls."""
from pathlib import Path
import json, os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
checks=[]

def check(ok,label):
    if not ok: raise AssertionError(label)
    checks.append(label)
    print("PASS "+label,flush=True)

SETUP=r'''() => {
  window.calls=[];window.notice=[];window.injected=false;window.failNext=false;
  const refs=Array.from({length:10},(_,i)=>({id:i+1,uuid:'M'+(i+1),title:i===0?'<img src=x onerror="window.injected=true">':'Reference '+(i+1),original_name:'test.png',category:'image',mime_type:'image/png',width:1024,height:1024,size_bytes:500}));
  const style={id:1,name:'Test visual identity',mood:'Cinematic',guidance:'Private artistic direction with no external upload.',primary_hex:'#112233',secondary_hex:'#445566',accent_hex:'#778899',is_builtin:false,moodboard:[]};
  const fixture={settings:{enabled:false,model:'test-model',daily_cap:5},openai:{enabled:false,key_configured:false},jobs:[],models:['test-model'],presets:[{id:1,slug:'album_cover',name:'Cover',category:'Music',guidance:'Cinematic cover',default_size:'1024x1024',default_quality:'medium',is_builtin:true}],styles:[style],references:refs,dashboard:{counts:{}}};
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  window.STONEFELLOW_ADMIN_CONTEXT={canvas:document.getElementById('adminCanvas'),app:{csrf:'synthetic'},esc,say:s=>notice.push(s),openView:()=>{},head:(k,t,p,a='')=>'<header><h1>'+esc(t)+'</h1>'+a+'</header>',api:async(path,body)=>{
    if(!body)return structuredClone(fixture);
    calls.push(structuredClone(body));await new Promise(r=>setTimeout(r,40));
    if(window.failNext){window.failNext=false;throw new Error('Synthetic failure');}
    if(body.action==='board_add'){
      if(style.moodboard.length>=8)throw new Error('Eight image cap');
      const a=refs.find(x=>x.id===body.asset_id);
      style.moodboard.push({id:100+body.asset_id,asset_id:a.id,uuid:a.uuid,title:a.title,caption:body.caption,available:true});
    }else if(body.action==='board_move'){
      const i=style.moodboard.findIndex(x=>x.id===body.item_id),n=i+(body.direction==='up'?-1:1);
      if(n>=0&&n<style.moodboard.length)[style.moodboard[i],style.moodboard[n]]=[style.moodboard[n],style.moodboard[i]];
    }else if(body.action==='board_remove'){
      style.moodboard=style.moodboard.filter(x=>x.id!==body.item_id);
    }else throw new Error('Unexpected action: '+body.action);
    return {ok:true,items:structuredClone(style.moodboard)};
  }};
}'''
with sync_playwright() as p:
    launch={'headless':True,'args':['--no-sandbox']}
    if os.environ.get('CHROMIUM_PATH'):launch['executable_path']=os.environ['CHROMIUM_PATH']
    browser=p.chromium.launch(**launch)
    for width in [1440,375]:
        page=browser.new_page(viewport={'width':width,'height':900},device_scale_factor=1)
        page.set_default_timeout(5000)
        errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.route('**/*',lambda r:r.fulfill(status=200,content_type='text/html',body='<html><body><main id="adminCanvas"></main></body></html>') if r.request.resource_type=='document' else r.abort())
        page.set_content('<html><body><main id="adminCanvas"></main></body></html>')
        for name in ['admin.css','image-generation.css']:
            page.add_style_tag(path=str(ROOT/'admin/assets'/name))
        page.add_style_tag(content='body{margin:0;padding:12px;box-sizing:border-box}main{min-width:0;width:100%}*,*:before,*:after{box-sizing:border-box}')
        page.evaluate(SETUP)
        page.add_script_tag(path=str(ROOT/'admin/assets/image-moodboards.js'))
        page.add_script_tag(path=str(ROOT/'admin/assets/image-generation.js'))
        page.evaluate('SFImageGenerationAdmin.open()')
        check(page.locator('[data-moodboard-root]').count()==1,f'{width}: full Image Studio renders without selector crash')
        page.locator('[data-imagegen-style]').click()
        check(page.locator('#imagegenStyleSelect').input_value()=='1',f'{width}: style card selects the composer style')
        page.locator('[data-imagegen-preset]').click()
        check(page.locator('#imagegenDraftForm [name="size"]').input_value()=='1024x1024',f'{width}: preset controls remain functional')
        page.locator('#imagegenDraftForm [name="prompt"]').fill('Preserve this existing edited prompt exactly.')
        page.locator('[data-board-style]').select_option('1')
        add=page.locator('[data-board-add]')
        add.locator('select').select_option('1');add.locator('input').fill('<script>window.injected=true</script>')
        page.evaluate("document.querySelector('[data-board-add]').dispatchEvent(new Event('submit',{cancelable:true}));document.querySelector('[data-board-add]').dispatchEvent(new Event('submit',{cancelable:true}));")
        page.wait_for_function("document.querySelectorAll('.imagegen-board-item').length===1 && document.querySelector('[data-moodboard-root]').getAttribute('aria-busy')==='false'")
        check(page.evaluate("calls.filter(x=>x.action==='board_add').length")==1,f'{width}: simultaneous submit is deduplicated in browser')
        check(not page.evaluate('injected'),f'{width}: captions and titles cannot inject HTML')
        check(not add.locator('button').is_disabled(),f'{width}: Add is enabled again after save')
        add.locator('select').select_option('2');add.locator('button').click()
        page.wait_for_function("document.querySelectorAll('.imagegen-board-item').length===2")
        page.locator('[data-board-action="down"]').first.click()
        page.wait_for_function("document.querySelector('.imagegen-board-item strong').textContent==='Reference 2'")
        check(True,f'{width}: reorder follows the saved receipt')
        page.locator('#imagegenReferenceSelect').select_option('3');page.locator('#imagegenReferenceConsent').check()
        page.locator('#imagegenMaskSelect').select_option('4');page.locator('#imagegenMaskConsent').check()
        page.locator('[data-board-action="use"]').first.click()
        check(page.locator('#imagegenReferenceSelect').input_value()=='2',f'{width}: selected board image reaches the established reference picker')
        check(not page.locator('#imagegenReferenceConsent').is_checked() and not page.locator('#imagegenMaskConsent').is_checked() and page.locator('#imagegenMaskSelect').input_value()=='',f'{width}: switching source resets reference and mask permission and clears old mask')
        check(page.locator('#imagegenDraftForm [name="prompt"]').input_value()=='Preserve this existing edited prompt exactly.',f'{width}: prompt survives board operations and source handoff')
        page.once('dialog',lambda d:d.dismiss());page.locator('[data-board-action="remove"]').first.click()
        check(page.locator('.imagegen-board-item').count()==2,f'{width}: cancel remove leaves the board intact')
        page.once('dialog',lambda d:d.accept());page.locator('[data-board-action="remove"]').first.click()
        page.wait_for_function("document.querySelectorAll('.imagegen-board-item').length===1")
        check(True,f'{width}: confirmed remove updates board without deleting source media')
        page.evaluate('failNext=true');add.locator('select').select_option('3');add.locator('button').click()
        page.wait_for_function("document.querySelector('[data-board-status]').textContent.includes('Synthetic failure')")
        check(not add.locator('button').is_disabled(),f'{width}: failed save restores interactive controls')
        for i in range(2,9):
            add.locator('select').select_option(str(i));add.locator('button').click()
            page.wait_for_function('(n)=>document.querySelectorAll(".imagegen-board-item").length===n',arg=i)
        check(add.locator('button').is_disabled(),f'{width}: UI enforces the eight-reference limit')
        check(page.evaluate("calls.every(x=>['board_add','board_move','board_remove'].includes(x.action))"),f'{width}: board operations never request generation, token charges or publication')
        check(not errors,f'{width}: no browser runtime exceptions: {errors}')
        board=page.locator('[data-moodboard-root]');board.scroll_into_view_if_needed()
        check(page.evaluate("document.querySelector('[data-moodboard-root]').scrollWidth<=document.querySelector('[data-moodboard-root]').clientWidth+1"),f'{width}: mood board has no horizontal overflow')
        out=Path(os.environ.get('SF_BROWSER_OUTPUT','/tmp/stoneward-moodboards'));out.mkdir(parents=True,exist_ok=True)
        board.screenshot(path=str(out/f'board-{width}.png'))
        page.close()
    browser.close()
print(json.dumps({'passed':len(checks),'checks':checks},indent=2))
