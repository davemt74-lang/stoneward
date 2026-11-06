"""Native browser coverage of host-owned permission review (mock transport, no providers)."""
import asyncio, os
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
async def fixture(page):
 await page.set_content('<button id="launcher">Open access</button>')
 await page.add_style_tag(path=str(ROOT/'assets/css/canvas-access.css'))
 await page.add_script_tag(path=str(ROOT/'assets/js/canvas-access.js'))
 await page.evaluate('''() => {
  window.owner='owner1';window.writes=[];window.refreshes=0;window.busy=false;
  window.scene={id:42,canvas_kind:'custom'};
  window.access={context:{scene_id:42},fingerprint:'a'.repeat(64),title:'A kinetic ledger',signed_in:true,requires_consent:true,permissions:[
    {id:'canvas.data.published_catalog',label:'Published songs',description:'Public catalog.',personal:false,enabled:true,granted:false,version:'1'},
    {id:'canvas.data.personal_listening_stats',label:'My listening',description:'Own plays only.',personal:true,enabled:true,granted:false,version:'1'},
    {id:'canvas.data.my_purchases',label:'My purchases',description:'Own orders only.',personal:true,enabled:true,granted:false,version:'1'},
    {id:'canvas.data.my_tickets',label:'My tickets',description:'Own tickets.',personal:true,enabled:false,granted:false,version:'1'}]};
  scene.canvas_access=structuredClone(access);
  window.options={getOwner:()=>owner,getScene:()=>scene,isBusy:()=>busy,
    read:async()=>({access:structuredClone(access)}),
    write:async(payload)=>{writes.push(structuredClone(payload));for(const row of access.permissions)if(payload.permission_ids.includes(row.id))row.granted=payload.action==='grant';access.requires_consent=access.permissions.some(p=>p.personal&&p.enabled&&!p.granted);scene.canvas_access=structuredClone(access);return {access:structuredClone(access)};},
    onRefresh:async()=>{refreshes++;}};
  window.review=SFCanvasAccess.create(options);
 }''')
async def main():
 async with async_playwright() as p:
  browser=await p.chromium.launch(executable_path=os.environ['CHROMIUM_BIN'],headless=True,args=['--no-sandbox'])
  for width in [390,1440]:
   page=await browser.new_page(viewport={'width':width,'height':780})
   await fixture(page)
   await page.locator('#launcher').focus()
   await page.evaluate('review.show(scene)')
   modal=page.get_by_role('dialog',name='What this canvas uses')
   assert await modal.is_visible()
   assert await page.get_by_text('Disabled by administrator',exact=True).count()==1
   assert await page.get_by_role('checkbox').count()==2
   assert await page.evaluate('writes.length')==0
   assert await page.evaluate('document.getElementById("launcher").inert')
   await page.get_by_role('checkbox',name='Allow My purchases').uncheck()
   await page.get_by_role('button',name='Allow selected access').click()
   await page.get_by_text('Your choices are saved for this canvas.',exact=True).wait_for()
   assert await page.evaluate('writes[0].permission_ids')==['canvas.data.personal_listening_stats']
   assert await page.evaluate('writes[0].fingerprint')=='a'*64
   assert await page.evaluate('refreshes')==1
   await page.get_by_role('button',name='Revoke My listening').click()
   await page.get_by_text('Access revoked for this canvas.',exact=True).wait_for()
   assert await page.evaluate('writes[1].action')=='revoke'
   await page.keyboard.press('Escape')
   assert await page.get_by_role('dialog').count()==0
   assert not await page.evaluate('document.getElementById("launcher").inert')
   assert await page.locator('#launcher').evaluate('(node)=>node===document.activeElement')
   await page.evaluate('review.sceneMounted(scene)')
   await page.wait_for_timeout(30)
   assert await page.get_by_role('dialog').count()==0 # explicit review already remembered in this tab
   await page.evaluate("access.fingerprint='b'.repeat(64);scene.canvas_access=structuredClone(access);review.sceneMounted(scene)")
   await page.get_by_role('dialog').wait_for()
   assert await page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   await page.get_by_role('button',name='Continue without personal data').click()
   assert await page.evaluate('writes.length')==2
   await page.evaluate("owner='owner2';review.setOwner()")
   assert await page.get_by_role('dialog').count()==0
   # New owner must not see a previous owner's response arriving late.
   await page.evaluate("() => {options.read=()=>new Promise(resolve=>window.finishRead=resolve);window.pending=review.show(scene);}")
   await page.evaluate("owner='owner3';review.setOwner();finishRead({access})")
   await page.wait_for_timeout(20)
   assert await page.get_by_role('dialog').count()==0
   assert await page.evaluate('writes.length')==2
   # Unsupported/malformed contract is non-actionable.
   await page.evaluate("options.read=async()=>({access:{context:{scene_id:42},permissions:[]}});window.pending=review.show(scene)")
   await page.get_by_text('Canvas access details were incomplete. Reopen the review.',exact=True).wait_for()
   assert await page.get_by_role('button',name='Allow selected access').is_disabled()
   await page.keyboard.press('Escape')
   # A disabled administrator scope remains revocable, and removed canvases
   # remain manageable without resolving their current scene.
   await page.evaluate("""() => {options.read=async()=>({access:structuredClone(access)});access.permissions[3].granted=true;access.signed_in=true;owner='owner1';review.setOwner();}""")
   await page.evaluate('review.show(scene)')
   assert await page.get_by_role('button',name='Revoke My tickets').is_visible()
   await page.keyboard.press('Escape')
   await page.evaluate("""() => {window.saved=[{context_key:'owned:deleted-canvas',permission_id:'canvas.data.my_purchases',label:'My purchases',granted_at:'fixture time'}];options.readMine=async()=>({grants:structuredClone(saved)});options.write=async(payload)=>{writes.push(structuredClone(payload));saved=[];return {grants:[]};};scene=null;}""")
   await page.evaluate('review.manage()')
   await page.get_by_role('button',name='Revoke My purchases').click()
   await page.get_by_text('Saved permission revoked.',exact=True).wait_for()
   assert await page.get_by_text('No saved personal-data permissions.',exact=True).is_visible()
   assert await page.evaluate('writes.at(-1).action')=='revoke_saved'
   assert await page.evaluate('writes.at(-1).context_key')=='owned:deleted-canvas'
   await page.keyboard.press('Escape')
   await page.close()
   print(f'PASS access review at {width}px: scoped grant/revoke, no implicit spend, focus, fresh scope, account race, malformed response')
  await browser.close()
asyncio.run(main())
