"""Real playlist resource and publication authorization through disposable PHP HTTP."""
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
PHP = os.environ.get('SF_TEST_PHP', 'php')
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('playlist_http_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv

ADMIN = '/admin/api/canvas-playlists.php'
PUBLIC = '/api/canvas-resources.php?kind=playlists'
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


def sign_in(base, email):
    client = fixture.HTTPClient(base)
    csrf = client.get('/api/auth.php').json()['csrf']
    response = client.post('/api/auth.php', {'action': 'login', 'email': email, 'password': 'fixture-only-password'}, {'X-CSRF-Token': csrf})
    assert response.status == 200, response.json()
    return client, {'X-CSRF-Token': response.json()['csrf']}


def item(items, ref):
    return next((entry for entry in items if entry['id'] == ref), None)


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-playlists-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    shutil.copytree(ROOT / 'admin', site / 'admin', dirs_exist_ok=True)
    seeded = json.loads(fixture.cli(site, """
        require_once SF_ROOT.'/api/canvas-playlists-core.php';
        sf_db()->exec("INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) SELECT 9,'admin@example.invalid','Admin',password_hash,'admin','active',created_at,updated_at FROM users WHERE id=1");
        $own=sf_playlist_create(1,'Owner private playlist','Owner private description','private','user',['song-1','song-2','song-1']);
        $other=sf_playlist_create(2,'Other private playlist','Other private description','private','user',['song-3']);
        $candidate=sf_playlist_create(2,'Artist candidate','Published source description','public','user',['song-2','song-1']);
        $insert=sf_db()->prepare('INSERT INTO user_playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)');
        for($i=3;$i<264;$i++)$insert->execute([$own['id'],$i===263?'hidden-song':'song-1',$i,gmdate('c')]);
        sf_canvas_playlists_ensure_schema();
        echo json_encode(['own'=>$own['id'],'other'=>$other['id'],'candidate'=>$candidate['id']]);
    """))
    runtime = site / 'api/agent-runtime.php'
    code = runtime.read_text()
    begin, end = code.index('function sf_http_json('), code.index('function sf_http_binary(')
    runtime.write_text(code[:begin] + """function sf_http_json(string $url,array $headers,array $payload,int $timeout=45): array {
        file_put_contents(SF_ROOT.'/unexpected-provider.log',"provider\\n",FILE_APPEND);
        throw new RuntimeException('Provider transport is disabled in this HTTP fixture');
    }
""" + code[end:])
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
            owner, owner_headers = sign_in(base, 'owner@example.invalid')
            other, _ = sign_in(base, 'other@example.invalid')
            admin, admin_headers = sign_in(base, 'admin@example.invalid')
            check(guest.get(ADMIN).status == 401 and owner.get(ADMIN).status == 403, 'Artist publication management requires a real administrator account')
            initial = admin.get(ADMIN)
            candidates = initial.json()['items']
            candidate = item(candidates, 'artist-' + str(seeded['candidate']))
            check(initial.status == 200 and candidate['can_publish'] and not candidate['published'], 'Administrator sees eligible public source with exact current revision')
            check(item(candidates, 'artist-' + str(seeded['other'])) is None and 'Other private description' not in initial.body().decode(), 'Unsubmitted foreign private playlists are excluded from administrator publication discovery')
            check('no-store' in initial.headers.get('cache-control', ''), 'Administrator publication inventory is not cached')
            request = {'action': 'publish', 'playlist_id': seeded['candidate'], 'revision_hash': candidate['revision_hash']}
            check(admin.post(ADMIN, request).status == 403 and admin.post(ADMIN, request, {'X-CSRF-Token': 'forged'}).status == 403, 'Publication rejects missing or forged CSRF tokens')
            check(owner.post(ADMIN, request, owner_headers).status == 403, 'A non-admin cannot publish with a valid personal CSRF token')
            check(admin.request('DELETE', ADMIN).status == 405, 'Publication endpoint supports only read and explicit POST actions')
            check(admin.post(ADMIN, dict(request, action='delete'), admin_headers).status == 422, 'Unknown actions cannot delete or modify source playlists')
            check(admin.post(ADMIN, dict(request, user_id=2), admin_headers).status == 422, 'Publication requests reject caller-supplied ownership fields')
            check(admin.post(ADMIN, dict(request, revision_hash='0' * 64), admin_headers).status == 422, 'Publication requires the exact reviewed source revision')
            before = guest.get(PUBLIC)
            check(before.status == 200 and before.json()['items'] == [], 'Guests can discover artist inventory but ordinary public user playlists are not artist publications')
            check(guest.get('/api/canvas-resources.php?kind=canvas').status == 401, 'Guest artist access does not open private saved-canvas discovery')
            own = owner.get(PUBLIC)
            own_item = item(own.json()['items'], 'own-' + str(seeded['own']))
            check(own.status == 200 and len(own.json()['items']) == 1 and own_item['track_count'] == 264, 'Current owner receives every playlist entry beyond legacy creation limits')
            check(own_item['items'][0]['track_id'] == own_item['items'][2]['track_id'] == 'song-1' and own_item['items'][0]['item_id'] != own_item['items'][2]['item_id'], 'Repeated songs retain distinct ordered playlist entry identities through HTTP')
            unavailable = own_item['items'][263]
            check(unavailable['track_id'] is None and unavailable['title'] == 'Unavailable track' and not unavailable['available'] and 'hidden-song' not in own.body().decode(), 'Hidden songs remain generic unavailable positions without leaking hidden IDs')
            forged_owner = owner.get(PUBLIC + '&user_id=2').json()['items']
            check(item(forged_owner, 'own-' + str(seeded['other'])) is None and item(other.get(PUBLIC).json()['items'], 'own-' + str(seeded['other'])) is not None, 'Caller account parameters cannot select another person\'s playlist inventory')
            published = admin.post(ADMIN, request, admin_headers)
            artist_ref = 'artist-' + str(seeded['candidate'])
            check(published.status == 200 and published.json()['publication']['status'] == 'published' and item(published.json()['items'], artist_ref)['published'], 'Explicit administrator approval publishes the exact reviewed source')
            public = guest.get(PUBLIC)
            artist = item(public.json()['items'], artist_ref)
            check(artist is not None and [entry['track_id'] for entry in artist['items']] == ['song-2', 'song-1'] and all(entry['source'] == 'artist' for entry in public.json()['items']), 'Guest catalog contains only approved artist lists with their full original order')
            check('Owner private' not in public.body().decode() and 'Other private' not in public.body().decode(), 'Artist discovery contains no signed-in private playlist metadata')
            check('no-store' in public.headers.get('cache-control', ''), 'Public artist selection inventory revalidates rather than using cached membership')
            fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET name=? WHERE id=?')->execute(['Changed after review'," + str(seeded['candidate']) + "]); ")
            check(item(guest.get(PUBLIC).json()['items'], artist_ref) is None, 'Source changes immediately remove a stale artist publication from public discovery')
            check(admin.post(ADMIN, request, admin_headers).status == 422, 'A stale review token cannot approve a changed source')
            fresh = item(admin.get(ADMIN).json()['items'], artist_ref)
            request['revision_hash'] = fresh['revision_hash']
            check(admin.post(ADMIN, request, admin_headers).status == 200, 'Administrator can review and republish a changed exact revision')
            fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET visibility=?,name=?,description=? WHERE id=?')->execute(['private','New private title','New private description'," + str(seeded['candidate']) + "]); ")
            private_review = item(admin.get(ADMIN).json()['items'], artist_ref)
            check(private_review['title'] == 'Unavailable playlist' and private_review['description'] == '' and private_review['items'] == [] and not private_review['source_available'], 'A source made foreign private leaves a generic withdrawal control without its new private contents')
            check(item(guest.get(PUBLIC).json()['items'], artist_ref) is None, 'Source privacy revocation removes approved artist inventory')
            withdrawal = {'action': 'withdraw', 'playlist_id': seeded['candidate'], 'revision_hash': private_review['revision_hash']}
            check(admin.post(ADMIN, withdrawal, admin_headers).json()['publication']['status'] == 'withdrawn', 'Administrator can explicitly withdraw a source that became unavailable')
            fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET visibility=? WHERE id=?')->execute(['public'," + str(seeded['candidate']) + "]); ")
            check(item(guest.get(PUBLIC).json()['items'], artist_ref) is None, 'Visibility changes do not undo an explicit artist withdrawal')
            fixture.cli(site, "sf_db()->exec(\"UPDATE users SET role='user' WHERE id=9\");")
            check(admin.get(ADMIN).status == 403 and admin.post(ADMIN, withdrawal, admin_headers).status == 403, 'Revoked admin sessions cannot continue reading or changing publication')
            check(not (site / 'unexpected-provider.log').exists(), 'Resource discovery and publication make no provider call')
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)

print('Canvas playlists HTTP:', len(checks), 'checks passed', flush=True)
