"""Production dropdown meter + real authenticated PHP token snapshots.

Isolated host harness; no AI provider or full-application E2E claim.
"""
import importlib.util,json,os,signal,socket,subprocess,sys,tempfile,time
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1];sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('audio_fixture',ROOT/'tests/canvas-audio-integration-browser.py');fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
PHP=os.environ.get('SF_TEST_PHP','php');checks=[]
def check(value,label):
 assert value,label
 checks.append(label);print('PASS '+label,flush=True)
shell=(ROOT/'stonefellow-v120.php').read_text();start=shell.index('        <section id="menuTokenMeter"');end=shell.index('        </section>',start)+len('        </section>');meter_shell=shell[start:end]
HTML='''<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="assets/css/account-token-meter.css"><style>body{margin:20px;background:#111b29;color:#eee;font:16px system-ui}#menuPanel{max-width:330px}.model{min-height:44px}</style><details id="menuPanel"><summary>Account menu</summary>'''+meter_shell+'''<details id="models"><summary>Coding Model</summary><label class="model"><input type="radio" name="model" value="default">Default</label><label class="model"><input type="radio" name="model" value="configured">Configured model</label></details></details><script src="assets/js/account-token-meter.js"></script><script>
window.auth={user:null,csrf:''};window.meterReads=0;
async function json(path,body){const response=await fetch('api/'+path,{credentials:'same-origin',cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':auth.csrf},body:JSON.stringify(body)}:{})});const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Request rejected');if(data.csrf)auth.csrf=data.csrf;return data;}
window.login=async(email)=>{await json('auth.php');const data=await json('auth.php',{action:'login',email,password:'fixture-only-password'});auth.user=data.user;SFTokenMeter.setOwner();};
SFTokenMeter.create({getOwner:()=>auth.user?String(auth.user.id):'',read:async signal=>{meterReads++;const response=await fetch('api/token-meter.php',{credentials:'same-origin',cache:'no-store',signal});const data=await response.json();if(!response.ok)throw Error('Unavailable');return data;}});
document.getElementById('menuPanel').addEventListener('toggle',event=>{if(event.target.id==='menuPanel')SFTokenMeter.setOpen(event.target.open);});
</script>'''
with tempfile.TemporaryDirectory(prefix='stoneward-token-ui-') as temporary:
 site=Path(temporary);fixture.seed(site)
 seeded=json.loads(fixture.cli(site,"""
 require_once SF_ROOT.'/api/ai-token-reservations.php';sf_entitlements_ensure_schema();sf_billing_ensure_schema();
 $package=sf_packages(true)[0];sf_db()->prepare('UPDATE subscription_packages SET trial_days=7,trial_tokens=1000,monthly_tokens=2000 WHERE id=?')->execute([$package['id']]);sf_enroll_user(1,(int)$package['id']);sf_token_adjust(2,42,'admin_grant','other-meter');$held=sf_ai_reserve_tokens(['id'=>1,'role'=>'user'],200,'meter-ui');echo json_encode(['held'=>$held]);
 """))
 (site/'meter.html').write_text(HTML)
 with socket.socket() as reservation:reservation.bind(('127.0.0.1',0));port=reservation.getsockname()[1]
 base='http://127.0.0.1:'+str(port)
 with (site/'php.log').open('w+') as log:
  server=subprocess.Popen([PHP,'-d','session.save_path='+str(site/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(site)],stdout=log,stderr=log,start_new_session=True)
  try:
   for _ in range(100):
    try:
     with socket.create_connection(('127.0.0.1',port),timeout=.1):break
    except OSError:time.sleep(.02)
   with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.environ['CHROMIUM_BIN'],headless=True,args=['--no-sandbox'])
    for width in [390,1440]:
     if width==1440:
      # Begin a new independent hold so both viewports exercise net availability.
      fixture.cli(site,"sf_db()->exec('DELETE FROM user_token_balances WHERE user_id=1');sf_db()->exec('DELETE FROM token_ledger WHERE user_id=1');sf_token_adjust(1,1000,'trial_grant','meter-ui-1440');require_once SF_ROOT.'/api/ai-token-reservations.php';sf_ai_reserve_tokens(['id'=>1,'role'=>'user'],200,'meter-ui-1440');")
     context=browser.new_context(viewport={'width':width,'height':820});page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)));page.goto(base+'/meter.html')
     page.get_by_text('Account menu',exact=True).click();page.wait_for_timeout(20);check(page.evaluate('meterReads')==0,f'{width}px guest menu causes no token request')
     page.evaluate("login('owner@example.invalid')");expect(page.get_by_text('800 available',exact=True)).to_be_visible();expect(page.get_by_text('80% remaining',exact=True)).to_be_visible()
     check(page.get_by_text('200 held for current work (already excluded from available)',exact=True).is_visible(),f'{width}px actual escrow is shown separately without double subtraction')
     page.get_by_text('Coding Model',exact=True).click();page.locator('input[value=configured]').check()
     if os.environ.get('SF_TOKEN_SCREENSHOTS'):page.screenshot(path='/tmp/sf-token-meter-'+str(width)+'.png',full_page=True)
     fixture.cli(site,"require_once SF_ROOT.'/api/ai-token-reservations.php';$q=sf_db()->query(\"SELECT request_key,source FROM ai_token_reservations WHERE user_id=1 AND status='held'\");$r=$q->fetch();sf_ai_finish_reservation(['id'=>1,'role'=>'user'],$r['request_key'],50,[],$r['source'],true);")
     page.evaluate('SFTokenMeter.notifyUsage()');expect(page.get_by_text('950 available',exact=True)).to_be_visible();expect(page.get_by_text('95% remaining',exact=True)).to_be_visible()
     check(page.locator('#menuPanel').evaluate('node=>node.open') and page.locator('#models').evaluate('node=>node.open') and page.locator('input[value=configured]').is_checked(),f'{width}px confirmed settlement refresh preserves model selection and open dropdown')
     page.get_by_text('Account menu',exact=True).click();before=page.evaluate('meterReads');fixture.cli(site,"sf_token_adjust(1,1050,'admin_grant','meter-extra-"+str(width)+"');")
     page.evaluate('SFTokenMeter.notifyUsage()');page.wait_for_timeout(40);check(page.evaluate('meterReads')==before,f'{width}px closed menu does not poll after balance changes')
     page.get_by_text('Account menu',exact=True).click();expect(page.get_by_text('2,000 available',exact=True)).to_be_visible();expect(page.get_by_text('200% remaining',exact=True)).to_be_visible()
     check(page.locator('progress').get_attribute('value')=='100',f'{width}px numeric top-up percentage exceeds100 while progress bar stays bounded')
     second=browser.new_context(viewport={'width':width,'height':820});other=second.new_page();other.goto(base+'/meter.html');other.get_by_text('Account menu',exact=True).click();other.evaluate("login('other@example.invalid')");expect(other.get_by_text('42 available',exact=True)).to_be_visible();expect(other.get_by_text('Percentage unavailable',exact=True)).to_be_visible()
     check(not other.locator('progress').is_visible() and '2,000' not in other.locator('#menuTokenMeter').inner_text(),f'{width}px second signed-in viewer sees only own balance with no invented allowance')
     check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}px account meter fits viewport without a nested meter scroller')
     check(not errors,f'{width}px production token module reports no browser exceptions')
     second.close();context.close()
    browser.close()
  finally:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
print(str(len(checks))+' actual PHP token-meter browser checks passed')
