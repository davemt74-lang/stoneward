"""Production consent UI with real PHP auth, consent and dataset endpoints.

Disposable SQLite site; isolated host harness, not a full application E2E test.
"""
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import tempfile
import time
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[1]
sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('canvas_fixture',ROOT/'tests/canvas-audio-integration-browser.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
PHP=os.environ.get('SF_TEST_PHP','php')
checks=[]
def check(value,label):
 assert value,label
 checks.append(label);print('PASS '+label,flush=True)
HTML='''<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="assets/css/canvas-access.css"><style>body{background:#101827;color:#eee;font:16px system-ui;margin:16px}button{min-height:44px}pre{white-space:pre-wrap;overflow-wrap:anywhere}</style><h1>Consent integration fixture</h1><button id="access">Canvas access</button><button id="manage">Saved permissions</button><p id="load-state"></p><pre id="data"></pre><script src="assets/js/canvas-access.js"></script><script>
window.owner='';window.scene=null;window.csrf='';window.reads=[];window.posts=[];
async function request(path,body,signal){const options={credentials:'same-origin',cache:'no-store',signal};if(body){options.method='POST';options.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};options.body=JSON.stringify(body);posts.push({path,body});}else reads.push(path);const response=await fetch(path,options);const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||data.error||'Request rejected');if(data.csrf)csrf=data.csrf;return data;}
function paint(){document.getElementById('data').textContent=JSON.stringify(scene?.datasets?.personal_listening_stats||{});document.getElementById('load-state').textContent=scene?'Loaded '+scene.id:'No scene';}
window.review=SFCanvasAccess.create({getOwner:()=>owner,getScene:()=>scene,isBusy:()=>false,
 read:(context,signal)=>request('api/canvas-access.php?'+new URLSearchParams(context),null,signal),
 readMine:signal=>request('api/canvas-access.php?mine=1',null,signal),write:(body,signal)=>request('api/canvas-access.php',body,signal),
 onRefresh:async(current,signal)=>{if(!current)return;const data=await request('api/agent-scene.php?id='+current.id,null,signal);if(scene!==current)return;scene=data.scene;paint();review.sceneMounted(scene);}});
window.loadScene=async(id)=>{const data=await request('api/agent-scene.php?id='+id);scene=data.scene;paint();review.sceneMounted(scene);};
window.login=async(email)=>{await request('api/auth.php');const data=await request('api/auth.php',{action:'login',email,password:'fixture-only-password'});owner=String(data.user.id);review.setOwner();};
document.getElementById('access').onclick=()=>review.show();document.getElementById('manage').onclick=()=>review.manage();
window.boot=(async()=>{const data=await request('api/auth.php');owner=data.user?String(data.user.id):'';review.setOwner();if(owner)await loadScene(Number(new URLSearchParams(location.search).get('scene_id')));})();
</script>'''
with tempfile.TemporaryDirectory(prefix='stoneward-consent-browser-') as temporary:
 site=Path(temporary);fixture.seed(site)
 seeded=json.loads(fixture.cli(site,"""
 require_once SF_ROOT.'/api/agent-scene-core.php';sf_ops_ensure_schema();sf_privacy_ensure_schema();
 $template=['canvas_kind'=>'custom','track_id'=>'','title'=>'Listening lens','html'=>'<section class="sf-dataset-personal_listening_stats"></section>','css'=>''];$ids=[];
 foreach([390,1440] as $width){$ids[$width]=['owner'=>sf_agent_scene_history_save(1,$template)['id'],'other'=>sf_agent_scene_history_save(2,$template)['id']];}
 $q=sf_db()->prepare('INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at) VALUES(?,?,?,?,?,?,?,?)');
 $q->execute([1,'owner-private','song-1','start',0,6,'fixture',gmdate('c')]);$q->execute([2,'other-private','song-2','start',0,6,'fixture',gmdate('c')]);echo json_encode($ids);
 """))
 (site/'consent.html').write_text(HTML)
 runtime=site/'api/agent-runtime.php';transport=runtime.read_text();begin,end=transport.index('function sf_http_json('),transport.index('function sf_http_binary(')
 runtime.write_text(transport[:begin]+"function sf_http_json(string $url,array $headers,array $payload,int $timeout=45):array{file_put_contents(SF_ROOT.'/provider-called','unexpected');throw new RuntimeException('Providers disabled in fixture');}\n"+transport[end:])
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
     ids=seeded[str(width)];context=browser.new_context(viewport={'width':width,'height':820});page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
     page.goto(base+'/consent.html?scene_id='+str(ids['owner']));page.evaluate('boot');page.evaluate("login('owner@example.invalid')");page.evaluate('loadScene('+str(ids['owner'])+')')
     expect(page.get_by_role('dialog',name='What this canvas uses')).to_be_visible()
     check(page.evaluate("scene.datasets.personal_listening_stats.state==='consent_required'&&scene.datasets.personal_listening_stats.rows.length===0"),f'{width}px real scene endpoint withholds data before UI consent')
     check(page.evaluate("posts.filter(p=>p.path==='api/canvas-access.php').length===0"),f'{width}px opening review performs no implicit grant')
     expect(page.get_by_role('button',name='Allow selected access')).to_be_enabled()
     if os.environ.get('SF_CONSENT_SCREENSHOTS'):
      page.screenshot(path='/tmp/sf-consent-'+('mobile' if width==390 else 'desktop')+'.png',full_page=True)
     page.get_by_role('button',name='Allow selected access').click();expect(page.get_by_role('dialog')).to_have_count(0)
     check(page.evaluate("scene.datasets.personal_listening_stats.rows.length===1&&scene.datasets.personal_listening_stats.rows[0].track_id==='song-1'"),f'{width}px UI grant through actual CSRF endpoint loads only current owner rows')
     page.reload();page.evaluate('boot');expect(page.locator('#load-state')).to_have_text('Loaded '+str(ids['owner']))
     check(page.evaluate("scene.canvas_access.requires_consent===false&&scene.datasets.personal_listening_stats.rows[0].track_id==='song-1'"),f'{width}px page reload reuses server-remembered consent')
     expect(page.get_by_role('dialog')).to_have_count(0)
     page.get_by_role('button',name='Canvas access',exact=True).click();page.get_by_role('button',name='Revoke Personal listening statistics').click();expect(page.get_by_role('dialog')).to_have_count(0)
     check(page.evaluate("scene.datasets.personal_listening_stats.rows.length===0&&scene.canvas_access.requires_consent"),f'{width}px UI revoke withholds subsequent actual dataset delivery')
     # Revoke must not immediately reopen an Allow dialog for the same request.
     page.wait_for_timeout(40);expect(page.get_by_role('dialog')).to_have_count(0)
     page.get_by_role('button',name='Canvas access',exact=True).click();page.get_by_role('button',name='Allow selected access').click();expect(page.get_by_role('dialog')).to_have_count(0)
     page.get_by_role('button',name='Saved permissions').click();page.get_by_role('button',name='Revoke Personal listening statistics').click();expect(page.get_by_text('Saved permission revoked.',exact=True)).to_be_visible()
     check(page.evaluate('scene.datasets.personal_listening_stats.rows.length===0'),f'{width}px saved-grant manager revokes and refreshes real current scene')
     page.get_by_role('button',name='Done',exact=True).click()
     second=browser.new_context(viewport={'width':width,'height':820});other=second.new_page();other.goto(base+'/consent.html?scene_id='+str(ids['other']));other.evaluate('boot');other.evaluate("login('other@example.invalid')")
     denied=other.evaluate('(id)=>fetch("api/agent-scene.php?id="+id).then(r=>r.status)',ids['owner'])
     check(denied==404,f'{width}px separate signed-in browser cannot read owner canvas')
     other.evaluate('loadScene('+str(ids['other'])+')');expect(other.get_by_role('dialog')).to_be_visible();other.get_by_role('button',name='Allow selected access').click();expect(other.get_by_role('dialog')).to_have_count(0)
     check(other.evaluate("scene.datasets.personal_listening_stats.rows.length===1&&scene.datasets.personal_listening_stats.rows[0].track_id==='song-2'"),f'{width}px second viewer receives only their independently granted listening data')
     check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}px actual consent host fits viewport')
     check(not errors,f'{width}px production consent UI has no browser exceptions')
     second.close();context.close()
    browser.close()
   check(not (site/'provider-called').exists(),'Consent review, grant, reload and revoke invoke no AI provider')
  finally:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
print(str(len(checks))+' real PHP consent UI checks passed')
