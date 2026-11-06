import asyncio, json, os
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
async def main():
 async with async_playwright() as p:
  browser=await p.chromium.launch(executable_path=os.environ['CHROMIUM_BIN'],headless=True,args=['--no-sandbox'])
  for width in [390,1440]:
   page=await browser.new_page(viewport={'width':width,'height':850})
   await page.set_content('<main id="host"></main>')
   await page.evaluate('''() => {window.fixture={policy:{revision:1},capabilities:[{permission_id:'canvas.data.personal_listening_stats',label:'Personal listening statistics',description:'Your own listening activity.',requires_sign_in:true,enabled:true},{permission_id:'canvas.data.published_catalog',label:'Published catalog',description:'Public songs.',requires_sign_in:false,enabled:true}],history:[]};window.calls=[];window.STONEFELLOW_ADMIN_CONTEXT={api:async(path,body)=>{window.calls.push({path,body});if(body){if(body.revision!==window.fixture.policy.revision)throw Error('Permissions changed. Refresh and review before saving.');for(const c of window.fixture.capabilities)if(c.permission_id in body.changes)c.enabled=body.changes[c.permission_id];window.fixture.policy.revision++;window.fixture.history=[{revision:2,updated_by:7,updated_at:'fixture',changes:{'canvas.data.personal_listening_stats':{after:false}}}];}return structuredClone(window.fixture);}};}''')
   await page.add_script_tag(path=str(ROOT/'admin/assets/canvas-permissions.js'))
   await page.evaluate("SFCanvasPermissionsAdmin.renderInto(document.getElementById('host'))")
   await page.get_by_role('status').filter(has_text='Policy revision 1').wait_for()
   box=page.get_by_role('checkbox').first
   assert await box.is_enabled()
   assert await box.is_checked()
   await box.uncheck()
   assert await page.get_by_role('button',name='Save permission changes').is_enabled()
   assert await page.evaluate('calls.length')==1
   await page.get_by_role('button',name='Save permission changes').click()
   await page.get_by_role('status').filter(has_text='Policy revision 2').wait_for()
   assert not await page.get_by_role('checkbox').first.is_checked()
   assert await page.get_by_role('button',name='Save permission changes').is_disabled()
   await page.get_by_role('searchbox').fill('Published')
   assert await page.get_by_role('checkbox').count()==1
   assert await page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   await page.get_by_role('searchbox').fill('')
   await page.evaluate('fixture.policy.revision=3')
   await page.get_by_role('checkbox').first.check()
   await page.get_by_role('button',name='Save permission changes').click()
   await page.get_by_role('status').filter(has_text='Permissions changed').wait_for()
   assert await page.get_by_role('checkbox').first.is_enabled()
   await page.get_by_role('button',name='Refresh').click()
   await page.get_by_role('status').filter(has_text='Policy revision 3').wait_for()
   await page.evaluate('SFCanvasPermissionsAdmin.destroy()')
   await page.close()
   print(f'PASS permissions UI: load, explicit save, search, conflict, refresh, width {width}')
  await browser.close()
asyncio.run(main())
