"""Artist playlist publication UI against real PHP auth and revision-checked APIs.

Disposable SQLite and synthetic MP3s; isolated host harness, not full Admin E2E.
"""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('playlist_admin_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS ' + label, flush=True)


HTML = '''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="admin/assets/admin.css"><link rel="stylesheet" href="admin/assets/canvas-playlists.css">
<style>body{background:#101827;color:#eee;padding:16px}main{max-width:1240px;margin:auto}button{min-height:44px}</style></head>
<body><main id="adminCanvas" class="admin-canvas"></main><script src="admin/assets/canvas-playlists.js"></script><script>
window.csrf='';window.owner='guest';window.posts=[];window.reads=[];window.holdRead=false;window.holdWrite=false;
async function api(path,body){
 const options={credentials:'same-origin',cache:'no-store'};
 if(body!==undefined){options.method='POST';options.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};options.body=JSON.stringify(body);posts.push(structuredClone(body));}else reads.push(path);
 const response=await fetch('admin/api/'+path,options);const data=await response.json();
 if(!response.ok||data.ok!==true){const error=Error(data.message||data.error||'Request rejected');error.status=response.status;throw error;}
 if(data.csrf)csrf=data.csrf;
 if((body===undefined&&holdRead)||(body!==undefined&&holdWrite)){holdRead=false;holdWrite=false;await new Promise(resolve=>window.releaseHeld=resolve);}
 return data;
}
window.login=async()=>{const read=await fetch('api/auth.php').then(r=>r.json());csrf=read.csrf;const response=await fetch('api/auth.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'login',email:'other@example.invalid',password:'fixture-only-password'})});const data=await response.json();if(!response.ok)throw Error(data.message||'Login failed');csrf=data.csrf;owner=String(data.user.id);};
window.mount=()=>window.controller=SFCanvasPlaylistsAdmin.renderInto('adminCanvas',{api,getOwner:()=>owner});
window.boot=(async()=>{await login();mount();await controller.ready;})();
</script></body></html>'''


with tempfile.TemporaryDirectory(prefix='stoneward-playlist-admin-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    shutil.copytree(ROOT / 'admin', site / 'admin', dirs_exist_ok=True)
    runtime = site / 'api/agent-runtime.php'
    transport = runtime.read_text()
    begin, end = transport.index('function sf_http_json('), transport.index('function sf_http_binary(')
    runtime.write_text(transport[:begin] + "function sf_http_json(string $url,array $headers,array $payload,int $timeout=45):array{file_put_contents(SF_ROOT.'/provider-called','unexpected');throw new RuntimeException('Providers disabled in fixture');}\n" + transport[end:])
    seeded = json.loads(fixture.cli(site, '''
      require_once SF_ROOT.'/api/canvas-playlists-core.php';sf_db()->exec("UPDATE users SET role='admin' WHERE id=2");$out=[];
      foreach([390,1440] as $width){
        $source=sf_playlist_create(1,'Artist <img onerror=bad()> '.$width,'A <script>literal</script> description.','public','user',['song-1','song-1','hidden-song']);
        $bulk=sf_playlist_create(1,'Long ordered playlist '.$width,'Full membership.','public','user',['song-2']);
        $q=sf_db()->prepare('INSERT INTO user_playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)');for($i=1;$i<205;$i++)$q->execute([$bulk['id'],'song-2',$i,gmdate('c')]);
        $private=sf_playlist_create(1,'PRIVATE UNLISTED '.$width,'Private details.','private','user',['song-3']);
        $out[$width]=['source'=>$source['id'],'bulk'=>$bulk['id'],'private'=>$private['id']];
      }echo json_encode($out);
    '''))
    (site / 'playlist-admin.html').write_text(HTML)
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    with (site / 'php.log').open('w+') as log:
        server = subprocess.Popen([PHP, '-d', 'session.save_path=' + str(site / 'sessions'), '-S', '127.0.0.1:' + str(port), '-t', str(site)], stdout=log, stderr=log, start_new_session=True)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)
            guest = fixture.HTTPClient(base)
            check(guest.get('/admin/api/canvas-playlists.php').status == 401, 'Actual administrator playlist endpoint denies guest inventory access')
            ordinary = fixture.HTTPClient(base)
            csrf = ordinary.get('/api/auth.php').json()['csrf']
            logged_in = ordinary.post('/api/auth.php', {'action': 'login', 'email': 'owner@example.invalid', 'password': 'fixture-only-password'}, {'X-CSRF-Token': csrf}).json()
            check(ordinary.get('/admin/api/canvas-playlists.php').status == 403, 'Actual administrator playlist endpoint denies signed-in non-admin inventory access')
            check(ordinary.post('/admin/api/canvas-playlists.php', {'action': 'publish', 'playlist_id': seeded['390']['source'], 'revision_hash': 'a'*64}, {'X-CSRF-Token': logged_in['csrf']}).status == 403, 'Valid fan CSRF cannot authorize artist publication')
            with sync_playwright() as p:
                launch = {'headless': True, 'args': ['--no-sandbox']}
                chrome = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
                if chrome:
                    launch['executable_path'] = chrome
                browser = p.chromium.launch(**launch)
                for width in [390, 1440]:
                    ids = seeded[str(width)]
                    fixture.cli(site, "sf_db()->exec(\"UPDATE users SET role='admin' WHERE id=2\");")
                    context = browser.new_context(viewport={'width': width, 'height': 850}, has_touch=width < 720, reduced_motion='reduce')
                    page = context.new_page()
                    errors = []
                    page.on('pageerror', lambda error: errors.append(str(error)))
                    page.goto(base + '/playlist-admin.html')
                    page.evaluate('boot')
                    prefix = str(width) + 'px '
                    status = page.locator('.sf-playlist-admin-status')
                    expect(status).to_contain_text('playlists shown')
                    check(page.evaluate('posts.length===0') and page.locator('button[data-playlist-id="' + str(ids['private']) + '"]').count() == 0, prefix + 'real authenticated inventory hides private unsubmitted lists and makes no mutation on load')
                    search = page.get_by_role('searchbox', name='Search playlists')
                    search.fill('Artist <img onerror=bad()> ' + str(width))
                    entry = page.locator('button[data-playlist-id="' + str(ids['source']) + '"]')
                    (entry.tap if width < 720 else entry.click)()
                    expect(page.get_by_role('heading', name='Artist <img onerror=bad()> ' + str(width))).to_be_visible()
                    check(page.locator('.sf-playlist-admin-tracks li').count() == 3 and page.get_by_text('Unavailable song', exact=True).count() == 1, prefix + 'review preserves repeated entries and an unavailable-song position')
                    check(page.locator('.sf-admin-playlists img,.sf-admin-playlists script').count() == 0 and page.get_by_text('A <script>literal</script> description.', exact=True).is_visible(), prefix + 'playlist metadata remains plain text rather than executable markup')
                    check(page.evaluate('posts.length===0'), prefix + 'opening the exact revision preview does not publish or edit it')
                    page.get_by_text('Reviewed revision', exact=True).click()
                    expect(page.locator('.sf-playlist-admin-detail code')).to_be_visible()
                    reviewed_hash = page.locator('.sf-playlist-admin-detail code').inner_text()
                    publish = page.get_by_role('button', name='Publish this revision', exact=True)
                    (publish.tap if width < 720 else publish.click)()
                    expect(status).to_have_text('Artist playlist published.')
                    check(page.evaluate('(expected)=>posts.length===1&&posts[0].action==="publish"&&posts[0].revision_hash===expected', reviewed_hash), prefix + 'explicit publication sends the exact displayed hash through the real CSRF endpoint')
                    publication_filter = page.get_by_role('combobox', name='Publication status')
                    publication_filter.select_option('published')
                    check(page.locator('.sf-playlist-admin-list button').count() == 1, prefix + 'publication filter shows only the currently published revision')
                    publication_filter.select_option('all')
                    check(fixture.cli(site, 'require_once SF_ROOT.\'/api/canvas-playlists-core.php\';echo count(sf_canvas_playlists_inventory(0));').strip() == '1', prefix + 'explicit publication makes one exact artist playlist available to guests')

                    # A changed source cannot be withdrawn or republished through an old review.
                    fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET name=? WHERE id=?')->execute(['Changed reviewed source " + str(width) + "'," + str(ids['source']) + "]);")
                    withdraw = page.get_by_role('button', name='Withdraw artist playlist', exact=True)
                    (withdraw.tap if width < 720 else withdraw.click)()
                    expect(status).to_have_attribute('role', 'alert')
                    check('changed' in status.inner_text().lower() or 'review' in status.inner_text().lower(), prefix + 'stale revision rejection remains visible without a false successful decision')
                    page.get_by_role('button', name='Refresh playlists').click()
                    expect(page.get_by_role('heading', name='Changed reviewed source ' + str(width))).to_be_visible()
                    expect(page.locator('.sf-playlist-admin-state')).to_have_text('Needs review')
                    check(page.get_by_role('button', name='Publish this revision', exact=True).is_enabled(), prefix + 'refresh displays the changed source and requires publication of its new hash')
                    page.get_by_role('button', name='Publish this revision', exact=True).click()
                    expect(status).to_have_text('Artist playlist published.')

                    # Current foreign-private text is not disclosed by a previous public publication.
                    fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET visibility=?,name=?,description=? WHERE id=?')->execute(['private','SECRET AFTER PUBLICATION','PRIVATE DESCRIPTION'," + str(ids['source']) + "]);")
                    page.get_by_role('button', name='Refresh playlists').click()
                    expect(page.get_by_role('heading', name='Unavailable playlist', exact=True)).to_be_visible()
                    check('SECRET AFTER PUBLICATION' not in page.locator('body').inner_text() and 'PRIVATE DESCRIPTION' not in page.locator('body').inner_text() and page.locator('.sf-playlist-admin-tracks li').count() == 0, prefix + 'withdrawal review redacts newly private source title, description and entries')
                    check(page.get_by_role('button', name='Publish this revision', exact=True).count() == 0 and page.get_by_role('button', name='Withdraw artist playlist', exact=True).is_enabled(), prefix + 'unavailable source permits withdrawal while blocking publication')
                    page.get_by_role('button', name='Withdraw artist playlist', exact=True).click()
                    expect(status).to_have_text('Artist playlist withdrawn.')

                    if width < 720:
                        page.get_by_role('button', name='Back to playlists').tap()
                    search.fill('Long ordered playlist ' + str(width))
                    bulk = page.locator('button[data-playlist-id="' + str(ids['bulk']) + '"]')
                    (bulk.tap if width < 720 else bulk.click)()
                    check(page.locator('.sf-playlist-admin-tracks li').count() == 205, prefix + 'review loads all 205 ordered entries without the legacy creation cap')
                    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'), prefix + 'publication UI fits the viewport without horizontal overflow')
                    count_before = fixture.cli(site, 'echo sf_db()->query("SELECT COUNT(*) FROM user_playlist_tracks")->fetchColumn();')
                    page.get_by_role('button', name='Publish this revision', exact=True).click()
                    expect(status).to_have_text('Artist playlist published.')
                    check(fixture.cli(site, 'echo sf_db()->query("SELECT COUNT(*) FROM user_playlist_tracks")->fetchColumn();') == count_before, prefix + 'publishing changes availability without mutating playlist membership')
                    page.get_by_role('button', name='Withdraw artist playlist', exact=True).click()
                    expect(status).to_have_text('Artist playlist withdrawn.')

                    # A previously authenticated UI cannot retain publication authority after role loss.
                    fixture.cli(site, "sf_db()->exec(\"UPDATE users SET role='user' WHERE id=2\");")
                    page.get_by_role('button', name='Publish this revision', exact=True).click()
                    expect(status).to_have_attribute('role', 'alert')
                    check(page.get_by_role('button', name='Publish this revision', exact=True).is_enabled() and fixture.cli(site, 'echo sf_db()->query("SELECT status FROM canvas_artist_playlists WHERE playlist_id=' + str(ids['bulk']) + '")->fetchColumn();').strip() == 'withdrawn', prefix + 'revoked administrator role denies the write and leaves publication unchanged')
                    fixture.cli(site, "sf_db()->exec(\"UPDATE users SET role='admin' WHERE id=2\");")

                    # A response belonging to an old mounted account cannot repaint a new view.
                    page.evaluate('holdRead=true;mount()')
                    page.wait_for_function('typeof releaseHeld==="function"')
                    page.evaluate('async()=>{owner="different-account";document.getElementById("adminCanvas").replaceChildren(document.createTextNode("Replacement account view"));releaseHeld();await controller.ready;}')
                    expect(page.locator('#adminCanvas')).to_have_text('Replacement account view')
                    check(page.locator('.sf-admin-playlists').count() == 0, prefix + 'late inventory for an unmounted account cannot replace the current view')
                    check(not errors, prefix + 'production admin module has no browser exceptions')
                    context.close()
                browser.close()
            check(not (site / 'provider-called').exists(), 'Admin playlist review, publication and withdrawal invoke no model or image provider')
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)
print(str(len(checks)) + ' real PHP artist-playlist admin UI checks passed')
