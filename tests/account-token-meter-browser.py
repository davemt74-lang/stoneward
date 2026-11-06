"""Production account meter presentation and lifecycle with a synthetic transport."""
import asyncio,os
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
HTML='''<details id="menuPanel"><summary>Menu</summary><section id="menuTokenMeter" class="menu-token-meter" hidden><div class="menu-token-meter-head"><strong data-token-title>Active Tokens</strong><span class="menu-token-meter-count" data-token-count></span></div><progress data-token-bar max="100" hidden aria-label="Percentage of current token allowance remaining"></progress><span data-token-percentage></span><small data-token-basis></small><small data-token-held hidden></small><small data-token-status role="status"></small></section></details>'''
async def main():
 async with async_playwright() as p:
  browser=await p.chromium.launch(executable_path=os.environ['CHROMIUM_BIN'],headless=True,args=['--no-sandbox'])
  for width in [390,1440]:
   page=await browser.new_page(viewport={'width':width,'height':800})
   await page.set_content(HTML);await page.add_style_tag(path=str(ROOT/'assets/css/account-token-meter.css'));await page.add_script_tag(path=str(ROOT/'assets/js/account-token-meter.js'))
   await page.evaluate('''() => {
    window.owner='';window.calls=0;window.fail=false;window.pending=[];window.defer=false;
    window.value={user_id:1,unlimited:false,available_tokens:7500,held_tokens:2000,allowance_tokens:10000,allowance_basis:'plan',allowance_label:'Current credited plan allowance',percentage_remaining:75,bar_percentage:75,as_of:'fixture'};
    window.meter=SFTokenMeter.create({getOwner:()=>owner,read:async(signal)=>{calls++;if(defer)return new Promise(resolve=>pending.push({resolve,signal}));if(fail)throw Error('Unavailable');return {meter:structuredClone(value)};}});
   }''')
   await page.evaluate("document.getElementById('menuPanel').open=true;meter.setOpen(true)")
   assert await page.evaluate('calls')==0
   await page.evaluate("owner='1';meter.setOwner()")
   await page.get_by_text('7,500 available',exact=True).wait_for()
   assert await page.get_by_text('75% remaining',exact=True).is_visible()
   assert await page.get_by_text('2,000 held for current work (already excluded from available)',exact=True).is_visible()
   assert await page.locator('progress').get_attribute('value')=='75'
   assert await page.evaluate('calls')==1
   await page.evaluate('meter.setOpen(false);value.available_tokens=5000;value.percentage_remaining=50;value.bar_percentage=50;SFTokenMeter.notifyUsage()')
   await page.wait_for_timeout(20)
   assert await page.evaluate('calls')==1
   await page.evaluate('meter.setOpen(true)');await page.get_by_text('5,000 available',exact=True).wait_for()
   await page.evaluate('value.available_tokens=12500;value.percentage_remaining=125;value.bar_percentage=100;SFTokenMeter.notifyUsage()')
   await page.get_by_text('125% remaining',exact=True).wait_for()
   assert await page.locator('progress').get_attribute('value')=='100'
   assert '125%' in await page.locator('progress').get_attribute('aria-valuetext')
   await page.evaluate('value.allowance_tokens=null;value.percentage_remaining=null;value.bar_percentage=null;value.allowance_label="No current credited allowance";SFTokenMeter.notifyUsage()')
   await page.get_by_text('Percentage unavailable',exact=True).wait_for();assert not await page.locator('progress').is_visible()
   await page.evaluate('value.unlimited=true;value.available_tokens=null;value.held_tokens=0;value.allowance_label="Unlimited administrator access";SFTokenMeter.notifyUsage()')
   await page.get_by_text('Unlimited',exact=True).wait_for();assert not await page.locator('progress').is_visible();assert await page.locator('[data-token-percentage]').inner_text()==''
   await page.evaluate('fail=true;SFTokenMeter.notifyUsage()')
   await page.get_by_text('Token balance unavailable. Reopen the menu to retry.',exact=True).wait_for();assert await page.locator('[data-token-count]').inner_text()==''
   # Late responses and a stale account cookie must never leak another balance.
   await page.evaluate('fail=false;defer=true;SFTokenMeter.notifyUsage()')
   await page.evaluate("owner='2';meter.setOwner();pending[0].resolve({meter:{...value,user_id:1,unlimited:false,available_tokens:9999999}})")
   await page.wait_for_timeout(20);assert '9,999,999' not in await page.locator('#menuTokenMeter').inner_text()
   await page.evaluate("pending[1].resolve({meter:{...value,user_id:1}})")
   await page.get_by_text('Token balance unavailable. Reopen the menu to retry.',exact=True).wait_for()
   await page.evaluate("owner='';meter.setOwner()")
   assert not await page.locator('#menuTokenMeter').is_visible()
   assert await page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   await page.evaluate('meter.destroy()');await page.close()
   print(f'PASS token meter {width}px: counts,held,percentage,top-up,unlimited,unavailable,account races,no closed polling')
  await browser.close()
asyncio.run(main())
