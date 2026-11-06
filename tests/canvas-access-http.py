"""Remembered canvas consent through real PHP HTTP, ownership and gallery routes.

Uses a disposable site/database. No provider calls or real user records.
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

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('canvas_permissions_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv
PHP = os.environ.get('SF_TEST_PHP', 'php')
ENDPOINT = '/api/canvas-access.php'
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


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-access-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    shutil.copytree(ROOT / 'admin', site / 'admin', dirs_exist_ok=True)
    seeded = json.loads(fixture.cli(site, """
        require_once SF_ROOT.'/api/agent-scene-core.php';require_once SF_ROOT.'/api/canvas-gallery-core.php';
        sf_db()->exec("UPDATE users SET role='admin' WHERE id=2");
        $template=['canvas_kind'=>'custom','track_id'=>'','title'=>'Private listening lens',
            'html'=>'<section class="sf-dataset-personal_listening_stats"></section><section class="sf-dataset-published_catalog"></section>','css'=>''];
        $first=sf_agent_scene_history_save(1,$template);$revision=sf_agent_scene_history_save(1,$template,$first['id']);
        $independent=sf_agent_scene_history_save(1,$template);$foreign=sf_agent_scene_history_save(2,$template);
        $expanded=sf_agent_scene_history_save(1,array_replace($template,['html'=>$template['html'].'<section class="sf-dataset-my_tickets"></section>']),$revision['id']);
        sf_ops_ensure_schema();$q=sf_db()->prepare('INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at) VALUES(?,?,?,?,?,?,?,?)');
        $q->execute([1,'owner-private','song-1','start',0,6,'fixture',gmdate('c')]);
        $q->execute([2,'other-private','song-2','start',0,6,'fixture',gmdate('c')]);
        $entry=sf_canvas_gallery_submit(['id'=>1,'role'=>'user'],['scene_id'=>$first['id'],'request_id'=>str_repeat('a1',16),'title'=>'Public listening lens','description'=>'A user-specific listening view.','creator_label'=>'Fixture','publication_consent'=>true]);
        sf_canvas_gallery_moderate(['id'=>2,'role'=>'admin'],$entry['id'],'approve',$entry['snapshot_hash']);
        echo json_encode(['scene'=>$first['id'],'revision'=>$revision['id'],'independent'=>$independent['id'],'expanded'=>$expanded['id'],'foreign'=>$foreign['id'],'gallery'=>$entry['id']]);
    """))
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
            owner, headers = sign_in(base, 'owner@example.invalid')
            other, other_headers = sign_in(base, 'other@example.invalid')
            context = {'scene_id': seeded['scene']}
            query = ENDPOINT + '?scene_id=' + str(seeded['scene'])
            read = owner.get(query)
            current = read.json()['access']
            pid = 'canvas.data.personal_listening_stats'
            def change(access, action='grant', ids=None, scope=None):
                return {'action': action, 'context': scope or access['context'], 'fingerprint': access['fingerprint'], 'permission_ids': ids or [pid]}
            def rows(client, scene):
                return client.get('/api/agent-scene.php?id=' + str(scene)).json()['scene']['datasets']['personal_listening_stats']
            check(read.status == 200 and current['requires_consent'], 'Owner can inspect declared personal scopes without consent')
            check('no-store' in read.headers.get('cache-control',''), 'Consent summary is not cached')
            check(guest.get(query).status == 404 and other.get(query).status == 404, 'Guest and another account cannot inspect private canvas')
            check(guest.get(ENDPOINT+'?mine=1').status == 401, 'Saved account grant listing requires sign-in')
            check(guest.post(ENDPOINT, change(current)).status == 401, 'Anonymous callers cannot grant access')
            check(owner.post(ENDPOINT, change(current)).status == 403, 'Grant requires CSRF protection')
            check(owner.post(ENDPOINT, change(current), {'X-CSRF-Token':'forged'}).status == 403, 'Forged CSRF cannot grant access')
            check(owner.post(ENDPOINT, change(current), other_headers).status == 403, 'Cross-account CSRF cannot grant access')
            before = rows(owner, seeded['scene'])
            check(before['rows'] == [] and before['state'] == 'consent_required', 'Actual scene endpoint withholds private listening rows before consent')
            undeclared = owner.post(ENDPOINT, change(current, ids=['canvas.data.my_tickets']), headers)
            check(undeclared.status == 422,
                  'A canvas cannot obtain undeclared personal scopes')
            check(owner.post(ENDPOINT, change(current, ids=['canvas.data.published_catalog']), headers).status == 422,
                  'Public scopes are not treated as personal consent grants')
            allowed = owner.post(ENDPOINT, change(current), headers)
            granted = allowed.json()['access']
            check(allowed.status == 200 and not granted['requires_consent'], 'Explicit owner grant persists through actual endpoint')
            actual = rows(owner, seeded['scene'])['rows']
            check(len(actual) == 1 and actual[0]['track_id'] == 'song-1' and actual[0]['listens'] == 1,
                  'Granted scene receives current owner data and excludes another listener rows')
            check(not owner.get(ENDPOINT+'?scene_id='+str(seeded['revision'])).json()['access']['requires_consent'],
                  'Equal-scope trusted revision reuses remembered consent through HTTP')
            check(owner.get(ENDPOINT+'?scene_id='+str(seeded['independent'])).json()['access']['requires_consent'],
                  'Independent identical canvas does not inherit grants')
            expanded = owner.get(ENDPOINT+'?scene_id='+str(seeded['expanded'])).json()['access']
            perms = {p['id']:p for p in expanded['permissions']}
            check(expanded['requires_consent'] and perms[pid]['granted'] and not perms['canvas.data.my_tickets']['granted'],
                  'Expanded revision asks only for newly declared data category')
            mine = owner.get(ENDPOINT+'?mine=1').json()['grants']
            check(len(mine) == 1 and mine[0]['permission_id'] == pid and other.get(ENDPOINT+'?mine=1').json()['grants'] == [],
                  'Saved grants manager is account-scoped without leaking another account data')
            revoked = owner.post(ENDPOINT, change(granted, 'revoke'), headers).json()['access']
            check(revoked['requires_consent'] and rows(owner, seeded['scene'])['rows'] == [], 'Explicit revoke immediately withholds subsequent scene delivery')
            check(owner.post(ENDPOINT, change(current), headers).status == 409, 'Original Allow replay cannot restore access after revoke')
            public_query = ENDPOINT+'?gallery_id='+seeded['gallery']
            public_guest = guest.get(public_query)
            check(public_guest.status == 200 and public_guest.json()['access']['signed_in'] is False,
                  'Guest may inspect public gallery capability summary without identity access')
            public_owner = owner.get(public_query).json()['access']
            check(public_owner['requires_consent'], 'Public gallery has a separate grant context from creator private canvas')
            opened = guest.get('/api/canvas-gallery.php?action=open&id='+seeded['gallery']).json()['scene']
            check('personal_listening_stats' not in opened.get('datasets', {}), 'Guest gallery open contains no private listener rows')
            owner.post(ENDPOINT, change(public_owner), headers)
            public_other = other.get(public_query).json()['access']
            check(public_other['requires_consent'], 'Another public viewer must grant their own access independently')
            other.post(ENDPOINT, change(public_other), other_headers)
            other_rows = other.get('/api/canvas-gallery.php?action=open&id='+seeded['gallery']).json()['scene']['datasets']['personal_listening_stats']['rows']
            check(len(other_rows) == 1 and other_rows[0]['track_id'] == 'song-2', 'Gallery delivers the current viewer own data after that viewer consent')
            saved = owner.get(ENDPOINT+'?mine=1').json()['grants']
            gallery_grant = next(g for g in saved if g['context_key'].startswith('gallery:'))
            body = {'action':'revoke_saved','context_key':gallery_grant['context_key'],'permission_ids':[pid]}
            check(owner.post(ENDPOINT, body, headers).status == 200 and owner.get(public_query).json()['access']['requires_consent'],
                  'Account manager revoke_saved invalidates gallery access')
            check(not other.get(public_query).json()['access']['requires_consent'], 'Revoking one account grant never affects another viewer')
            check(owner.get(ENDPOINT+'?scene_id='+str(seeded['scene'])+'&gallery_id='+seeded['gallery']).status == 422,
                  'Ambiguous private/public contexts reject')
            print(f'{len(checks)} real HTTP canvas consent checks passed', flush=True)
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)
