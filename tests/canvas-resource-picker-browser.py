"""Native resource selection contracts, with no build or provider requests."""
import os
from pathlib import Path
import shutil
import sys

sys.dont_write_bytecode = True
from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


SETUP = r'''() => {
 window.inventory={canvas:Array.from({length:64},(_,index)=>({id:String(index+1),title:index===1?'<b>Saved canvas</b>':'Canvas '+(index+1),
  description:index===1?'An <img src=x onerror=alert(1)> description':'Saved concept '+(index+1)})),
  merch:[{id:'hoodie-a',title:'Tour hoodie',description:'Organic cotton',category:'Clothing',price_text:'$45.00',image_url:'/api/media.php?id=8'},
   {id:'record-b',title:'Record sleeve',description:'Signed edition',price_text:'$20.00',image_url:'https://untrusted.test/collect'},
   {id:'bad-url',title:'URL check',image_url:'javascript:alert(1)'}],
  tickets:[{id:'offer-1',title:'General admission',show_title:'Summer show',show_date:'2027-06-05',venue:'The Hall',location:'London',price_text:'£18',availability_label:'Available'},
   {id:'offer-2',title:'Balcony',description:'Seated ticket',availability_label:'Sold out'}]};
 inventory.canvas.push({id:'2',title:'Duplicate must not appear'},{id:'not-an-id',title:'Invalid canvas ID'});
 window.results={};window.requests=[];window.sequence=0;window.owner='one';
 window.startPicker=(kind,selectedIds=[],behavior='ok')=>{
  const key=++sequence,controller=new AbortController();window.activeRequest={key,controller};
  results[key]='pending';
  void SFCanvasResourcePicker.open({kind,selectedIds,signal:controller.signal,load:async signal=>{
   requests.push({kind,signal,key});
   if(behavior==='fail'){behavior='ok';throw Error('Inventory <b>temporarily unavailable</b>')}
   if(behavior==='hold'){behavior='ok';await new Promise(resolve=>window.releaseInventory=resolve)}
   if(behavior==='malformed')return {ok:false,items:[]};
   return {ok:true,items:JSON.parse(JSON.stringify(inventory[kind]))};
  }}).then(result=>results[key]=result);
  return key;
 };
}'''

with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        launch['executable_path'] = executable
    browser = playwright.chromium.launch(**launch)
    for width in (375, 1440):
        context = browser.new_context(viewport={'width': width, 'height': 780}, has_touch=width == 375)
        page = context.new_page()
        errors = []
        requests = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('request', lambda request: requests.append(request.url))
        page.route('https://picker.test/**', lambda route: route.fulfill(
            status=200,
            content_type='image/svg+xml' if '/api/media.php' in route.request.url else 'text/html',
            body='<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30"><rect width="30" height="30" fill="navy"/></svg>'
            if '/api/media.php' in route.request.url else '<!doctype html><html><body><label>Prompt<textarea id="composer">Keep this draft</textarea></label><button id="outside">Outside</button></body></html>'))
        page.goto('https://picker.test/')
        page.add_style_tag(content='body{margin:0;min-height:1500px;font:16px system-ui}textarea{font:inherit} ' +
                           (ROOT / 'assets/css/canvas-resource-picker.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-resource-picker.js').read_text())
        page.evaluate(SETUP)
        prefix = f'chromium/{width}: '
        composer = page.locator('#composer')
        composer.focus()
        check(page.evaluate('''async()=>{
          const request=new AbortController();request.abort();let called=false;
          const load=()=>{called=true;return {items:[]}};
          const values=await Promise.all([SFCanvasResourcePicker.open({kind:'__proto__',load}),SFCanvasResourcePicker.open({kind:'canvas',load,signal:request.signal})]);
          return values.every(value=>value===null)&&!called&&!document.querySelector('dialog');
        }'''), prefix + 'invalid categories and already-canceled requests never load inventory or open a dialog')
        key = page.evaluate("startPicker('canvas',['2','2','missing'])")
        picker = page.get_by_role('dialog', name='Choose canvases', exact=True)
        expect(picker).to_be_visible()
        search = picker.get_by_label('Search canvases', exact=True)
        expect(search).to_be_focused()
        expect(picker.get_by_role('checkbox', name='<b>Saved canvas</b>', exact=True)).to_be_checked()
        check(picker.get_by_role('checkbox').count() == 64 and picker.locator('b,img').count() == 0,
              prefix + 'all saved canvases load uncapped with unique canonical IDs and plain text metadata')
        expect(picker.locator('.sf-canvas-resource-count')).to_have_text('1 selected')
        geometry = picker.evaluate('''dialog=>{
          const body=dialog.querySelector('.sf-canvas-resource-picker-body'),footer=dialog.querySelector('footer');
          return {bodyScroll:body.scrollHeight>body.clientHeight,dialogFits:dialog.scrollWidth<=dialog.clientWidth&&dialog.scrollHeight<=dialog.clientHeight,
            footerFits:footer.getBoundingClientRect().bottom<=innerHeight-10,
            scrollers:[dialog,...dialog.querySelectorAll('*')].filter(el=>['auto','scroll'].includes(getComputedStyle(el).overflowY)&&el.scrollHeight>el.clientHeight).length};
        }''')
        check(geometry['bodyScroll'] and geometry['dialogFits'] and geometry['footerFits'] and geometry['scrollers'] == 1,
              prefix + 'a long inventory has one body scroll while the search and footer remain inside the viewport')
        search.fill('Saved canvas')
        check(picker.get_by_role('checkbox').count() == 1, prefix + 'search filters title and description without changing prior selections')
        search.fill('nothing-matches')
        expect(picker.get_by_text('No items match your search.', exact=True)).to_be_visible()
        search.fill('')
        for number in (1, 3, 4, 5, 6, 64):
            picker.get_by_role('checkbox', name=f'Canvas {number}', exact=True).check()
        expect(picker.locator('.sf-canvas-resource-count')).to_have_text('7 selected')
        picker.get_by_role('button', name='Add selections', exact=True).click()
        page.wait_for_function('key=>results[key]!=="pending"', arg=key)
        check(page.evaluate('key=>results[key].ids', key) == ['2', '1', '3', '4', '5', '6', '64'] and composer.input_value() == 'Keep this draft',
              prefix + 'apply returns every selected ID in stable order and leaves the composer and build untouched')
        expect(composer).to_be_focused()

        key = page.evaluate("startPicker('merch',['hoodie-a'])")
        picker = page.get_by_role('dialog', name='Choose merch', exact=True)
        expect(picker.get_by_role('checkbox', name='Tour hoodie', exact=True)).to_be_checked()
        check(picker.locator('img').count() == 1 and picker.get_by_text('$45.00', exact=True).is_visible(),
              prefix + 'merch displays server prices and managed same-origin images only')
        search = picker.get_by_label('Search merch', exact=True)
        search.fill('Clothing')
        expect(picker.get_by_role('checkbox')).to_have_count(1)
        picker.get_by_role('checkbox', name='Tour hoodie', exact=True).uncheck()
        picker.get_by_role('button', name='Add selections', exact=True).click()
        check(page.evaluate('key=>results[key].ids.length===0', key), prefix + 'an empty successful selection can clear existing resource bindings')

        key = page.evaluate("startPicker('tickets')")
        picker = page.get_by_role('dialog', name='Choose ticket offers', exact=True)
        search = picker.get_by_label('Search ticket offers', exact=True)
        search.fill('London')
        expect(picker.get_by_role('checkbox', name='General admission', exact=True)).to_be_visible()
        check(picker.get_by_role('checkbox').count() == 1 and '2027-06-05' in picker.inner_text() and '£18' in picker.inner_text(),
              prefix + 'ticket metadata supports search and selection independently of purchase availability')
        search.fill('')
        picker.get_by_role('checkbox', name='Balcony', exact=True).check()
        picker.press('Escape')
        check(page.evaluate('key=>results[key]===null', key), prefix + 'native Escape cancels instead of applying changed selections')
        expect(composer).to_be_focused()

        key = page.evaluate("startPicker('merch',[],'fail')")
        picker = page.get_by_role('dialog', name='Choose merch', exact=True)
        expect(picker.get_by_role('button', name='Try again', exact=True)).to_be_visible()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_disabled()
        check(picker.locator('b').count() == 0, prefix + 'failed inventory cannot apply an empty clearing selection and error text stays plain')
        picker.get_by_role('button', name='Try again', exact=True).click()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_enabled()
        picker.get_by_role('button', name='Cancel', exact=True).click()
        expect(composer).to_be_focused()

        key = page.evaluate("startPicker('merch',[],'malformed')")
        picker = page.get_by_role('dialog', name='Choose merch', exact=True)
        expect(picker.get_by_role('button', name='Try again', exact=True)).to_be_visible()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_disabled()
        picker.get_by_role('button', name='Cancel', exact=True).click()
        check(page.evaluate('key=>results[key]===null', key), prefix + 'an explicit API error cannot be mistaken for a valid empty inventory')

        key = page.evaluate("startPicker('canvas',[],'hold')")
        picker = page.get_by_role('dialog', name='Choose canvases', exact=True)
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_disabled()
        page.wait_for_function('typeof releaseInventory==="function"')
        page.evaluate('activeRequest.controller.abort();releaseInventory()')
        expect(picker).not_to_be_visible()
        check(page.evaluate('key=>results[key]===null&&requests.find(item=>item.key===key).signal.aborted', key),
              prefix + 'account cancellation aborts loading and ignores a late inventory response')
        expect(composer).to_be_focused()

        old = page.evaluate("startPicker('merch',[],'hold')")
        page.wait_for_function('typeof releaseInventory==="function"')
        replacement = page.evaluate("startPicker('tickets')")
        picker = page.get_by_role('dialog', name='Choose ticket offers', exact=True)
        expect(picker).to_be_visible()
        page.evaluate('releaseInventory()')
        check(page.evaluate('key=>results[key]===null&&requests.find(item=>item.key===key).signal.aborted', old) and page.get_by_role('dialog').count() == 1,
              prefix + 'opening another resource category cancels the prior request without reviving its dialog')
        picker.get_by_role('button', name='Cancel', exact=True).click()
        expect(composer).to_be_focused()

        page.evaluate('inventory.canvas=[]')
        key = page.evaluate("startPicker('canvas',['2'])")
        picker = page.get_by_role('dialog', name='Choose canvases', exact=True)
        expect(picker.get_by_text('No saved canvases are available yet.', exact=True)).to_be_visible()
        expect(picker.get_by_role('button', name='Add selections', exact=True)).to_be_enabled()
        picker.get_by_role('button', name='Add selections', exact=True).click()
        check(page.evaluate('key=>results[key].ids.length===0', key), prefix + 'a genuinely empty inventory drops stale preselection and remains clearable')
        check(not errors and not any('untrusted.test' in url for url in requests) and
              page.evaluate('requests.every(item=>item.signal.aborted)') and composer.input_value() == 'Keep this draft',
              prefix + 'all requests clean up without outside images, script errors, composer changes or build calls')
        context.close()
    browser.close()

print(f'PASS {len(checks)} resource picker browser checks', flush=True)
