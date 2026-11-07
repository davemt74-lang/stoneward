"""Canvas permission policy through real PHP authentication, CSRF and HTTP.

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
ENDPOINT = '/admin/api/canvas-permissions.php'
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


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-permissions-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    shutil.copytree(ROOT / 'admin', site / 'admin', dirs_exist_ok=True)
    runtime = site / 'api/agent-runtime.php'
    transport = runtime.read_text()
    begin, end = transport.index('function sf_http_json('), transport.index('function sf_http_binary(')
    runtime.write_text(transport[:begin] + """function sf_http_json(string $url,array $headers,array $payload,int $timeout=45): array {
        file_put_contents(SF_ROOT.'/unexpected-provider.log',"provider\\n",FILE_APPEND);
        throw new RuntimeException('Provider transport is disabled in this HTTP fixture');
    }
""" + transport[end:])
    fixture.cli(site, "sf_db()->exec(\"UPDATE users SET role='admin' WHERE id=2\");")
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
            admin, headers = sign_in(base, 'other@example.invalid')
            change = {'revision': 1, 'changes': {'canvas.data.my_purchases': False}}
            check(guest.get(ENDPOINT).status == 401, 'Guest cannot read administrator permission policy')
            check(owner.get(ENDPOINT).status == 403, 'Signed-in non-admin cannot read administrator policy')
            check(owner.post(ENDPOINT, change, owner_headers).status == 403,
                  'Signed-in non-admin cannot write policy even with a valid account CSRF token')
            initial = admin.get(ENDPOINT)
            data = initial.json()
            check(initial.status == 200 and data['policy']['revision'] == 1 and len(data['capabilities']) == 9,
                  'Authenticated admin loads all nine dataset permissions over HTTP')
            check('no-store' in initial.headers.get('cache-control', ''), 'Policy responses disallow caching')
            check(admin.post(ENDPOINT, change).status == 403, 'Administrator POST rejects missing CSRF token')
            check(admin.post(ENDPOINT, change, {'X-CSRF-Token': 'not-a-valid-token'}).status == 403,
                  'Administrator POST rejects forged CSRF token')
            check(admin.post(ENDPOINT, change, owner_headers).status == 403,
                  'Another account CSRF token cannot authorize administrator changes')
            check(admin.request('PUT', ENDPOINT, headers, change).status == 405,
                  'Policy endpoint rejects unsupported HTTP methods')
            for invalid in [
                {'revision': '1', 'changes': {'canvas.data.my_purchases': False}},
                {'revision': 1, 'changes': {'authentication': False}},
                {'revision': 1, 'changes': {'canvas.data.my_purchases': 'false'}},
                {'revision': 1, 'changes': []},
            ]:
                check(admin.post(ENDPOINT, invalid, headers).status == 422,
                      'Invalid revision/permission/value shapes reject without mutation: ' + json.dumps(invalid))
            saved = admin.post(ENDPOINT, change, headers)
            data = saved.json()
            check(saved.status == 200 and data['policy']['revision'] == 2 and
                  next(c for c in data['capabilities'] if c['id'] == 'my_purchases')['enabled'] is False,
                  'Explicit administrator save persists disablement through HTTP')
            check(len(data['history']) == 1 and data['history'][0]['updated_by'] == 2 and
                  data['history'][0]['changes']['canvas.data.my_purchases'] == {'before': True, 'after': False},
                  'Saved policy records authenticated actor and exact before/after change')
            stale = admin.post(ENDPOINT, {'revision': 1, 'changes': {'canvas.data.my_purchases': True}}, headers)
            check(stale.status == 409 and stale.json()['error'] == 'policy_conflict',
                  'Stale policy revision rejects instead of overwriting another admin save')
            unchanged = admin.post(ENDPOINT, {'revision': 2, 'changes': {'canvas.data.my_purchases': False}}, headers).json()
            check(unchanged['policy']['revision'] == 2 and len(unchanged['history']) == 1,
                  'HTTP no-op retry creates no duplicate audit or revision')
            fixture.cli(site, "sf_db()->exec(\"UPDATE users SET role='user' WHERE id=2\");")
            check(admin.get(ENDPOINT).status == 403 and admin.post(ENDPOINT, {'revision': 2, 'changes': {'canvas.data.my_purchases': True}}, headers).status == 403,
                  'Revoked administrator role blocks subsequent reads and writes using an existing session')
            persisted = json.loads(fixture.cli(site, "require SF_ROOT.'/api/canvas-capabilities.php';echo json_encode(sf_canvas_permission_policy());"))
            check(persisted['revision'] == 2 and persisted['disabled'] == ['canvas.data.my_purchases'],
                  'Rejected auth/CSRF/stale calls leave persisted policy unchanged')
            check(not (site / 'unexpected-provider.log').exists(), 'Policy flows do not invoke model providers')
            print(f'{len(checks)} real HTTP permission checks passed', flush=True)
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)
