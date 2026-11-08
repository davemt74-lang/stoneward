"""Canvas Gallery publication, review, public reads and private remix over real PHP HTTP."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('canvas_gallery_http_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv
PHP = os.environ.get('SF_TEST_PHP', 'php')
PUBLIC = '/api/canvas-gallery.php'
ADMIN = '/admin/api/canvas-gallery.php'
APP = '/api/canvas-app.php'
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


def sign_in(base, email):
    client = fixture.HTTPClient(base)
    csrf = client.get('/api/auth.php').json()['csrf']
    response = client.post('/api/auth.php', {'action': 'login', 'email': email, 'password': 'fixture-only-password'},
                           {'X-CSRF-Token': csrf})
    assert response.status == 200, response.json()
    return client, {'X-CSRF-Token': response.json()['csrf']}


def public_item(item):
    return set(item).issubset({'id', 'title', 'description', 'creator_label', 'preview_url', 'submitted_at', 'published_at', 'attribution', 'uses', 'app_id'}) and isinstance(item.get('uses'), list) and all(
        set(permission) == {'id', 'label', 'personal', 'enabled'} and isinstance(permission['id'], str) and
        isinstance(permission['label'], str) and isinstance(permission['personal'], bool) and isinstance(permission['enabled'], bool)
        for permission in item['uses'])


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-gallery-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    shutil.copytree(ROOT / 'admin', site / 'admin', dirs_exist_ok=True)
    runtime = site / 'api/agent-runtime.php'
    source = runtime.read_text()
    begin, end = source.index('function sf_http_json('), source.index('function sf_http_binary(')
    runtime.write_text(source[:begin] + '''function sf_http_json(string $url,array $headers,array $payload,int $timeout=45): array {
        file_put_contents(SF_ROOT.'/unexpected-provider.log',"provider\\n",FILE_APPEND);
        throw new RuntimeException('Provider transport is disabled in this HTTP fixture');
    }
''' + source[end:])
    seeded = json.loads(fixture.cli(site, '''
        require_once SF_ROOT.'/api/canvas-gallery-core.php';
        sf_db()->exec("UPDATE users SET role='admin' WHERE id=2");
        $source=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'PRIVATE_SOURCE_TITLE',
            'html'=>'<section class="paper"><p>Shared paper shape</p><span class="sf-action-app-notifications">Updates</span></section>','css'=>'.paper{color:#ead9b6}',
            'design_brief'=>'PRIVATE_ORIGINAL_PROMPT do not publish this request',
            'music_inputs'=>['mode'=>'selected','track_ids'=>['song-2','song-1']]]);
        $foreign=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'ADMIN_PRIVATE_SOURCE',
            'html'=>'<section></section>','css'=>'','design_brief'=>'ADMIN_PRIVATE_PROMPT']);
        echo json_encode(['source'=>$source,'foreign'=>$foreign]);
    '''))
    saved = seeded['source']
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    with (site / 'php.log').open('w+') as log:
        server = subprocess.Popen([PHP, '-d', 'session.save_path=' + str(site / 'sessions'), '-S',
                                   '127.0.0.1:' + str(port), '-t', str(site)],
                                  stdout=log, stderr=log, start_new_session=True)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)
            guest = fixture.HTTPClient(base)
            owner, owner_headers = sign_in(base, 'owner@example.invalid')
            admin, admin_headers = sign_in(base, 'other@example.invalid')
            check(guest.get(PUBLIC).status == 200 and guest.get(PUBLIC).json()['items'] == [],
                  'public visitors can browse an empty gallery without an account')
            check(guest.get(PUBLIC + '?action=mine').status == 401 and guest.get(ADMIN).status == 401 and owner.get(ADMIN).status == 403,
                  'submission management requires an account and review requires the administrator role')
            check(owner.request('DELETE', PUBLIC, owner_headers).status == 405 and admin.request('DELETE', ADMIN, admin_headers).status == 405,
                  'public and administrator gallery endpoints reject unsupported methods')
            request = {'action': 'submit', 'scene_id': saved['id'], 'request_id': '1' * 32, 'title': 'Paper orbit',
                       'description': 'A shared Stonefellow composition.', 'creator_label': 'Stonefellow Fan', 'publication_consent': True}
            check(guest.post(PUBLIC, request).status == 401 and owner.post(PUBLIC, request).status == 403,
                  'publication requires both authentication and an account CSRF token')
            check(owner.post(PUBLIC, {**request, 'publication_consent': False}, owner_headers).status == 422,
                  'publishing a saved snapshot requires explicit code and asset consent')
            foreign = owner.post(PUBLIC, {**request, 'scene_id': seeded['foreign']['id']}, owner_headers)
            check(foreign.status == 422 and 'ADMIN_PRIVATE' not in foreign.body().decode(),
                  'a listener cannot submit another account private saved canvas')
            submitted = owner.post(PUBLIC, request, owner_headers)
            assert submitted.status == 201, submitted.json()
            item = submitted.json()['item']
            check(item['status'] == 'pending' and item['source_scene_id'] == saved['id'] and len(item['snapshot_hash']) == 64,
                  'publication freezes a private reviewable submission pending administrator approval')
            check(guest.get(APP + '?gallery_id=' + item['id']).status == 404 and
                  owner.post(APP, {'action': 'install', 'gallery_id': item['id']}, owner_headers).status == 404,
                  'pending publication cannot expose or install a canvas app')
            replay = owner.post(PUBLIC, request, owner_headers)
            check(replay.status == 201 and replay.json()['item']['id'] == item['id'] and
                  len(owner.get(PUBLIC + '?action=mine').json()['items']) == 1,
                  'replaying a submission returns its original public identity without creating duplicates')
            check(guest.get(PUBLIC).json()['items'] == [] and guest.get(PUBLIC + '?id=' + item['id']).status == 404 and
                  guest.get(PUBLIC + '?action=open&id=' + item['id']).status == 404,
                  'pending code and submission details stay unavailable to public visitors')
            mine = owner.get(PUBLIC + '?action=mine&id=' + item['id'])
            check(mine.status == 200 and mine.json()['item']['status'] == 'pending' and
                  admin.get(PUBLIC + '?action=mine&id=' + item['id']).status == 404 and
                  admin.get(PUBLIC + '?action=open&id=' + item['id']).status == 404,
                  'private My Submissions reads stay owner-scoped even for an administrator using the public endpoint')
            review = admin.get(ADMIN)
            check(review.status == 200 and review.json()['csrf'] == admin_headers['X-CSRF-Token'] and
                  [row['id'] for row in review.json()['items']] == [item['id']],
                  'administrator review lists pending snapshots using the existing account session and CSRF')
            review_detail = admin.get(ADMIN + '?id=' + item['id'])
            check(review_detail.status == 200 and review_detail.json()['scene']['html'] == saved['html'] and
                  'PRIVATE_ORIGINAL_PROMPT' not in review_detail.body().decode(),
                  'administrator preview opens the frozen submitted code without copying the original private prompt')
            approve = {'action': 'approve', 'id': item['id'], 'snapshot_hash': item['snapshot_hash']}
            check(admin.post(ADMIN, approve).status == 403 and owner.post(ADMIN, approve, owner_headers).status == 403,
                  'administrator review writes require the administrator role and its CSRF token')
            wrong_hash = admin.post(ADMIN, {**approve, 'snapshot_hash': 'f' * 64}, admin_headers)
            check(wrong_hash.status == 409 and guest.get(PUBLIC).json()['items'] == [],
                  'approval must match the exact snapshot that was reviewed')
            approved = admin.post(ADMIN, approve, admin_headers)
            again = admin.post(ADMIN, approve, admin_headers)
            check(approved.status == again.status == 200 and approved.json()['item']['status'] == again.json()['item']['status'] == 'approved',
                  'approving an unchanged reviewed snapshot is idempotent')
            catalog = guest.get(PUBLIC)
            detail = guest.get(PUBLIC + '?id=' + item['id'])
            opened = guest.get(PUBLIC + '?action=open&id=' + item['id'])
            assert opened.status == 200, opened.json()
            check(catalog.status == detail.status == 200 and [row['id'] for row in catalog.json()['items']] == [item['id']] and
                  public_item(detail.json()['item']) and public_item(opened.json()['item']) and
                  'no-store' in opened.headers.get('cache-control', ''),
                  'approved public catalog and detail DTOs contain only intended publication metadata')
            public_scene = opened.json()['scene']
            app_id = opened.json()['app']['id']
            app_detail = APP + '?gallery_id=' + item['id']
            install = {'action': 'install', 'gallery_id': item['id']}
            toggle = {'action': 'set_notifications', 'app_id': app_id, 'enabled': True, 'expected_gallery_id': item['id']}
            check(detail.json()['item']['app_id'] == app_id and public_scene['app_notifications'] is True and
                  guest.get(app_detail).json()['app'] == {'id': app_id, 'gallery_id': item['id'], 'title': request['title'],
                                                        'installed': False, 'enabled': False, 'supports_alerts': True},
                  'approved canvas exposes only public app metadata and a host-authorized notification control')
            check(guest.get(APP + '?action=installed').status == 401 and guest.post(APP, install).status == 401 and
                  owner.post(APP, install).status == 403 and owner.post(APP, install, admin_headers).status == 403,
                  'canvas installation requires sign-in and the current account CSRF token')
            check(owner.post(APP, toggle, owner_headers).status == 422 and
                  owner.post(APP, install, owner_headers).json()['app']['enabled'] is False and
                  owner.post(APP, {**toggle, 'enabled': 'true'}, owner_headers).status == 422 and
                  owner.post(APP, {k: v for k, v in toggle.items() if k != 'expected_gallery_id'}, owner_headers).status == 422,
                  'installation leaves notifications off and enabling requires installation, a JSON boolean and reviewed gallery ID')
            check(owner.post(APP, toggle, owner_headers).json()['app']['enabled'] is True and
                  owner.post(APP, install, owner_headers).json()['app']['enabled'] is True and
                  owner.get(APP + '?action=installed').json()['items'] == [owner.get(app_detail).json()['app']] and
                  admin.get(APP + '?action=installed').json()['items'] == [] and
                  admin.get(app_detail).json()['app']['installed'] is False,
                  'installation and notification settings are idempotent and isolated to the current account')
            check(public_scene['html'] == saved['html'] and public_scene['title'] == request['title'] and
                  public_scene['music_inputs'] == saved['music_inputs'] and 'id' not in public_scene and
                  public_scene['gallery_attribution']['source'] == {'id': item['id'], 'title': 'Paper orbit', 'creator_label': 'Stonefellow Fan'},
                  'public opening preserves code and independent music nodes with public creator attribution')
            public_text = ''.join(response.body().decode() for response in [catalog, detail, opened])
            check(all(value not in public_text for value in ['PRIVATE_SOURCE_TITLE', 'PRIVATE_ORIGINAL_PROMPT', 'source_scene_id',
                                                           'snapshot_hash', 'request_fingerprint', 'user_id', str(site), 'storage/']),
                  'public views omit private source identities, original prompts and internal paths')
            fixture.cli(site, "sf_db()->prepare('UPDATE agent_scene_history SET html=? WHERE id=?')->execute(['<p>CHANGED_PRIVATE_SOURCE</p>'," + str(saved['id']) + "]);")
            check(guest.get(PUBLIC + '?action=open&id=' + item['id']).json()['scene']['html'] == saved['html'],
                  'editing the private source cannot change an already reviewed public snapshot')
            check(len(guest.get(PUBLIC + '?q=Paper&limit=1').json()['items']) == 1 and
                  guest.get(PUBLIC + '?page=0').status == 422 and guest.get(PUBLIC + '?action[]=open').status == 422,
                  'public gallery search and pagination retain validated read-only request shapes')
            remix_request = {'action': 'remix', 'id': item['id'], 'request_id': '2' * 32}
            check(guest.post(PUBLIC, remix_request).status == 401 and owner.post(PUBLIC, remix_request).status == 403,
                  'creating a private remix requires an authenticated account CSRF token')
            remixed = owner.post(PUBLIC, remix_request, owner_headers)
            assert remixed.status == 200, remixed.json()
            duplicate = owner.post(PUBLIC, remix_request, owner_headers)
            remix = remixed.json()['scene']
            check(duplicate.status == 200 and duplicate.json()['scene']['id'] == remix['id'] and remix['id'] != saved['id'] and
                  remix['html'] == saved['html'] and remix['gallery_attribution']['source']['id'] == item['id'],
                  'a private remix copies the frozen version once and retains its public creator credit')
            admin_remix = admin.post(PUBLIC, {**remix_request, 'request_id': '3' * 32}, admin_headers)
            assert admin_remix.status == 200, admin_remix.json()
            check(owner.get('/api/agent-scene.php?id=' + str(admin_remix.json()['scene']['id'])).status == 404 and
                  admin.get('/api/agent-scene.php?id=' + str(remix['id'])).status == 404,
                  'remixed private histories remain isolated between account owners including administrators')
            check(admin.post(PUBLIC, {'action': 'withdraw', 'id': item['id']}, admin_headers).status == 404 and
                  owner.post(PUBLIC, {'action': 'withdraw', 'id': item['id']}).status == 403,
                  'the public withdrawal action remains an owner-authorized CSRF write')
            withdrawn = owner.post(PUBLIC, {'action': 'withdraw', 'id': item['id']}, owner_headers)
            check(withdrawn.status == 200 and withdrawn.json()['item']['status'] == 'withdrawn' and
                  guest.get(PUBLIC).json()['items'] == [] and guest.get(PUBLIC + '?id=' + item['id']).status == 404 and
                  guest.get(PUBLIC + '?action=open&id=' + item['id']).status == 404,
                  'owner withdrawal immediately removes the publication from public browsing and opening')
            stable_app = APP + '?app_id=' + app_id
            unavailable_app = owner.get(stable_app).json()['app']
            check(guest.get(stable_app).status == 404 and unavailable_app['gallery_id'] is None and
                  unavailable_app['installed'] is True and unavailable_app['enabled'] is False and
                  owner.post(APP, toggle, owner_headers).status == 422 and
                  owner.post(APP, {'action': 'uninstall', 'app_id': app_id}, owner_headers).json()['app']['installed'] is False and
                  owner.get(APP + '?action=installed').json()['items'] == [],
                  'withdrawal revokes delivery consent and an installer can remove the unavailable app')
            check(owner.post(PUBLIC, {**remix_request, 'request_id': '4' * 32}, owner_headers).status == 404 and
                  owner.get('/api/agent-scene.php?id=' + str(remix['id'])).status == 200 and
                  admin.get('/api/agent-scene.php?id=' + str(admin_remix.json()['scene']['id'])).status == 200,
                  'withdrawal blocks new remixes while preserving previously imported private copies')
            revision = json.loads(fixture.cli(site, '''
                echo json_encode(sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Paper orbit revision',
                    'html'=>'<section><span class="sf-action-app-notifications">Updates</span></section>','css'=>''],''' + str(saved['id']) + '''));
            '''))
            revised = owner.post(PUBLIC, {**request, 'scene_id': revision['id'], 'request_id': '5' * 32}, owner_headers).json()['item']
            approved_revision = admin.post(ADMIN, {**approve, 'id': revised['id'], 'snapshot_hash': revised['snapshot_hash']}, admin_headers)
            assert approved_revision.status == 200, approved_revision.json()
            check(approved_revision.json()['item']['app_id'] == app_id and
                  owner.post(APP, {**install, 'gallery_id': revised['id'], 'expected_gallery_id': item['id']}, owner_headers).status == 409 and
                  owner.post(APP, {'action': 'install', 'app_id': app_id}, owner_headers).json()['app']['enabled'] is False and
                  owner.post(APP, toggle, owner_headers).status == 409 and
                  owner.get(stable_app).json()['app']['enabled'] is False and
                  owner.post(APP, {**toggle, 'expected_gallery_id': revised['id']}, owner_headers).json()['app']['enabled'] is True and
                  owner.post(APP, {**toggle, 'enabled': False}, owner_headers).json()['app']['enabled'] is False,
                  'a new review preserves identity, rejects stale install and opt-in confirmation, and allows disabling across revisions')
            with sqlite3.connect(site / 'fixture.sqlite') as connection:
                publications = connection.execute('SELECT COUNT(*) FROM canvas_gallery_entries').fetchone()[0]
                remixes = connection.execute('SELECT COUNT(*) FROM canvas_gallery_remixes').fetchone()[0]
            check(publications == 2 and remixes == 2 and not (site / 'unexpected-provider.log').exists(),
                  'gallery lifecycle operations create only expected records and never invoke a paid provider')
            log.flush()
            log.seek(0)
            logs = log.read()
            check(all(value not in logs for value in ['PRIVATE_SOURCE_TITLE', 'PRIVATE_ORIGINAL_PROMPT', 'ADMIN_PRIVATE_SOURCE', 'CHANGED_PRIVATE_SOURCE']),
                  'HTTP logs retain no private source title, prompt or unpublished source code')
        except BaseException:
            log.seek(0)
            print(log.read()[-6000:])
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait()

print(json.dumps({'ok': True, 'checks': len(checks)}))
