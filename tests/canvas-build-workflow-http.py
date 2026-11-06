"""Real PHP workflow HTTP authorization and lifecycle checks without model calls."""
import importlib.util
import json
import os
from pathlib import Path
import signal
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('canvas_workflow_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv
PHP = os.environ.get('SF_TEST_PHP', 'php')
ENDPOINT = '/api/canvas-build-workflow.php'
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


SEED_HELPERS = r'''<?php
function workflowFixturePending(int $uid,string $requestId,string $label): array {
    sf_canvas_build_workflow_ensure_schema();$user=['id'=>$uid,'role'=>'user'];
    $proposal=sf_canvas_project_propose($user,['title'=>$label.' project','concept'=>$label.' concept',
        'project'=>['canvas_kind'=>'custom','brief'=>$label.' design request']]);
    sf_canvas_project_approve($uid,$proposal['id']);
    sf_canvas_project_begin($user,$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>$requestId]);
    sf_agent_scene_request_claim($uid,$requestId,hash('sha256',$label),sf_agent_scene_history_epoch($uid));
    return ['request_id'=>$requestId,'proposal_id'=>$proposal['id'],'label'=>$label];
}
function workflowFixtureFinish(int $uid,array $pending): array {
    sf_canvas_build_workflow_ensure_schema();$db=sf_db();$db->beginTransaction();
    $scene=sf_agent_scene_history_save($uid,['canvas_kind'=>'custom','track_id'=>'','title'=>$pending['label'].' canvas',
        'html'=>'<p class="private">'.$pending['label'].'_SOURCE</p>','css'=>'','design_brief'=>$pending['label'].' original request'],null,sf_agent_scene_history_epoch($uid));
    sf_agent_scene_request_finish($uid,$pending['request_id'],$scene['id'],7,993,'model');$db->commit();
    sf_canvas_project_finish($uid,$pending['request_id'],['scene'=>$scene]);
    return $pending+['scene_id'=>$scene['id']];
}
'''


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-workflow-http-') as temporary:
    site = Path(temporary)
    _, unrelated_scene = fixture.seed(site)
    # No test lifecycle operation may reach an external paid provider.
    runtime = site / 'api/agent-runtime.php'
    source = runtime.read_text()
    begin, end = source.index('function sf_http_json('), source.index('function sf_http_binary(')
    runtime.write_text(source[:begin] + '''function sf_http_json(string $url,array $headers,array $payload,int $timeout=45): array {
        file_put_contents(SF_ROOT.'/unexpected-provider.log',"provider\\n",FILE_APPEND);
        throw new RuntimeException('Provider transport is disabled in this HTTP fixture');
    }
''' + source[end:])
    (site / 'workflow-fixture.php').write_text(SEED_HELPERS)
    seeded = json.loads(fixture.cli(site, '''
        require_once SF_ROOT.'/api/canvas-build-workflow-core.php';require SF_ROOT.'/workflow-fixture.php';
        $own=workflowFixtureFinish(1,workflowFixturePending(1,str_repeat('1',32),'OWNER_PRIVATE'));
        $other=workflowFixtureFinish(2,workflowFixturePending(2,str_repeat('2',32),'OTHER_PRIVATE'));
        $pending=workflowFixturePending(1,str_repeat('3',32),'STOP_PRIVATE');
        file_put_contents(SF_ROOT.'/pending-workflow.json',json_encode($pending));
        echo json_encode(['own'=>$own,'other'=>$other,'pending'=>$pending]);
    '''))
    own, foreign, pending = seeded['own'], seeded['other'], seeded['pending']
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
            check(guest.get(ENDPOINT + '?request_id=' + own['request_id']).status == 401 and
                  guest.post(ENDPOINT, {'action': 'start', **own}).status == 401,
                  'workflow reads and writes require an authenticated account')
            owner, headers = sign_in(base, 'owner@example.invalid')
            other, other_headers = sign_in(base, 'other@example.invalid')
            check(owner.request('DELETE', ENDPOINT, headers).status == 405,
                  'workflow endpoint rejects unsupported HTTP methods')
            check(owner.post(ENDPOINT, {'action': 'start', 'request_id': own['request_id'], 'scene_id': own['scene_id']}).status == 403 and
                  owner.post(ENDPOINT, {'action': 'status', 'request_id': own['request_id']}).status == 403,
                  'every workflow POST requires the current account CSRF token')
            check(owner.get(ENDPOINT + '?request_id=' + own['request_id']).status == 404,
                  'an approved original build has no workflow until its owner starts one')
            started = owner.post(ENDPOINT, {'action': 'start', 'request_id': own['request_id'], 'scene_id': own['scene_id']}, headers)
            assert started.status == 200, started.json()
            workflow = started.json()['workflow']
            check(workflow['state'] == 'testing' and workflow['candidate_id'] == own['scene_id'] and
                  [step['role'] for step in workflow['steps']] == ['coding'] and workflow['total_active_tokens_used'] == 7 and
                  started.json()['scene']['id'] == own['scene_id'],
                  'owner start links only its approved ready result and original coding receipt')
            check('no-store' in started.headers.get('cache-control', '') and 'OTHER_PRIVATE' not in started.body().decode(),
                  'private workflow responses disable caching and exclude another owner content')
            repeated = owner.post(ENDPOINT, {'action': 'start', 'request_id': own['request_id'], 'scene_id': own['scene_id']}, headers)
            check(repeated.status == 200 and repeated.json()['workflow'] == workflow,
                  'repeated start is idempotent and does not duplicate coding steps or charges')
            status = owner.get(ENDPOINT + '?request_id=' + own['request_id'])
            posted_status = owner.post(ENDPOINT, {'action': 'status', 'request_id': own['request_id']}, headers)
            check(status.status == posted_status.status == 200 and status.json()['workflow'] == posted_status.json()['workflow'],
                  'GET and authenticated POST status return the same owned workflow')
            blocked_apply = owner.post(ENDPOINT, {'action': 'apply', 'request_id': own['request_id'], 'scene_id': own['scene_id']}, headers)
            check(blocked_apply.status == 409 and blocked_apply.json()['error'] == 'canvas_workflow_conflict',
                  'an untested candidate cannot be marked applied')
            hidden = [other.get(ENDPOINT + '?request_id=' + own['request_id']),
                      other.post(ENDPOINT, {'action': 'status', 'request_id': own['request_id']}, other_headers),
                      other.post(ENDPOINT, {'action': 'start', 'request_id': own['request_id'], 'scene_id': own['scene_id']}, other_headers)]
            check([response.status for response in hidden] == [404, 404, 422],
                  'another account cannot read or start the owner approved workflow')
            foreign_apply = other.post(ENDPOINT, {'action': 'apply', 'request_id': own['request_id'], 'scene_id': own['scene_id']}, other_headers)
            foreign_cancel = other.post(ENDPOINT, {'action': 'cancel', 'request_id': own['request_id']}, other_headers)
            check(foreign_apply.status == 409 and foreign_cancel.status == 200 and
                  foreign_cancel.json()['workflow']['candidate_id'] is None and foreign_cancel.json()['workflow']['steps'] == [] and
                  'scene' not in foreign_cancel.json() and
                  owner.get(ENDPOINT + '?request_id=' + own['request_id']).json()['workflow']['state'] == 'testing',
                  'foreign apply is refused and idempotent foreign Stop reveals nothing or changes owner state')
            private_errors = ''.join(response.body().decode() for response in [*hidden, foreign_apply, foreign_cancel])
            check(all(value not in private_errors for value in ['OWNER_PRIVATE', '_SOURCE', 'original request', 'reservation_key', 'fingerprint', str(site)]),
                  'ownership failures expose no private scene, project, worker key or filesystem details')
            own_other = other.post(ENDPOINT, {'action': 'start', 'request_id': foreign['request_id'], 'scene_id': foreign['scene_id']}, other_headers)
            canceled = other.post(ENDPOINT, {'action': 'cancel', 'request_id': foreign['request_id']}, other_headers)
            canceled_again = other.post(ENDPOINT, {'action': 'cancel', 'request_id': foreign['request_id']}, other_headers)
            check(own_other.status == canceled.status == canceled_again.status == 200 and
                  canceled.json()['workflow']['state'] == canceled_again.json()['workflow']['state'] == 'canceled' and
                  len(canceled_again.json()['workflow']['steps']) == 1 and
                  owner.get(ENDPOINT + '?request_id=' + foreign['request_id']).status == 404,
                  'each account can stop its own workflow repeatedly without leaking it to another account')
            stopped_pending = owner.post(ENDPOINT, {'action': 'cancel', 'request_id': pending['request_id']}, headers)
            repeated_pending = owner.post(ENDPOINT, {'action': 'cancel', 'request_id': pending['request_id']}, headers)
            check(stopped_pending.status == repeated_pending.status == 200 and
                  repeated_pending.json()['workflow']['state'] == 'canceled' and
                  repeated_pending.json()['workflow']['candidate_id'] is None,
                  'Stop received before workflow start persists an owner-scoped cancellation tombstone')
            completed_late = json.loads(fixture.cli(site, '''
                require_once SF_ROOT.'/api/canvas-build-workflow-core.php';require SF_ROOT.'/workflow-fixture.php';
                echo json_encode(workflowFixtureFinish(1,json_decode(file_get_contents(SF_ROOT.'/pending-workflow.json'),true)));
            '''))
            late_start = owner.post(ENDPOINT, {'action': 'start', 'request_id': pending['request_id'], 'scene_id': completed_late['scene_id']}, headers)
            check(late_start.status == 200 and late_start.json()['workflow']['state'] == 'canceled' and
                  late_start.json()['workflow']['candidate_id'] == completed_late['scene_id'] and
                  [step['role'] for step in late_start.json()['workflow']['steps']] == ['coding'],
                  'late original completion remains saved but cannot restart a stopped agent workflow')
            fixture.cli(site, '''
                require_once SF_ROOT.'/api/canvas-build-workflow-core.php';
                $row=sf_canvas_build_workflow_row(1,str_repeat('1',32));$stepId=str_repeat('4',32);
                $claim=sf_canvas_build_workflow_claim(['id'=>1,'role'=>'user'],str_repeat('1',32),$stepId,'testing',
                    ['candidate_scene_id'=>(int)$row['candidate_scene_id'],'evidence'=>['note'=>'Owned candidate observation']]);
                sf_db()->beginTransaction();
                sf_canvas_build_workflow_record_review(1,str_repeat('1',32),$stepId,
                    ['verdict'=>'pass','summary'=>'OWNER_PRIVATE_ASSESSMENT'],['provider'=>'fixture','model'=>'fixture','input_tokens'=>1,'output_tokens'=>2],3,990,
                    (int)$row['history_epoch'],(int)$row['candidate_scene_id']);
                sf_db()->commit();
            ''')
            ready = owner.get(ENDPOINT + '?request_id=' + own['request_id'])
            check(ready.status == 200 and ready.json()['workflow']['state'] == 'ready' and
                  ready.json()['workflow']['total_active_tokens_used'] == 10 and len(ready.json()['workflow']['steps']) == 2,
                  'a settled Testing assessment makes only its current candidate ready with additive usage')
            wrong_apply = owner.post(ENDPOINT, {'action': 'apply', 'request_id': own['request_id'], 'scene_id': unrelated_scene['id']}, headers)
            check(wrong_apply.status == 409,
                  'a ready assessment cannot authorize applying another saved candidate')
            applied = owner.post(ENDPOINT, {'action': 'apply', 'request_id': own['request_id'], 'scene_id': own['scene_id']}, headers)
            applied_again = owner.post(ENDPOINT, {'action': 'apply', 'request_id': own['request_id'], 'scene_id': own['scene_id']}, headers)
            check(applied.status == applied_again.status == 200 and applied.json()['workflow']['state'] == 'applied' and
                  applied.json()['scene']['id'] == own['scene_id'] and applied_again.json()['workflow']['total_active_tokens_used'] == 10 and
                  len(applied_again.json()['workflow']['steps']) == 2,
                  'only the exact tested candidate applies and repeating apply creates no work or charges')
            check(other.get(ENDPOINT + '?request_id=' + own['request_id']).status == 404 and
                  owner.get(ENDPOINT + '?request_id[]=bad').status == 422 and
                  owner.post(ENDPOINT, {'action': 'unknown', 'request_id': own['request_id']}, headers).status == 422,
                  'applied reports stay owner-scoped and malformed requests remain validation errors')
            with sqlite3.connect(site / 'fixture.sqlite') as connection:
                counts = connection.execute('SELECT user_id,request_id,COUNT(*) FROM canvas_build_steps GROUP BY user_id,request_id').fetchall()
            check(sorted(row[2] for row in counts) == [1, 1, 2] and not (site / 'unexpected-provider.log').exists(),
                  'HTTP lifecycle operations create only expected rows and never invoke a paid provider')
            log.flush()
            log.seek(0)
            logs = log.read()
            check(all(value not in logs for value in ['OWNER_PRIVATE', 'OTHER_PRIVATE', '_SOURCE', 'OWNER_PRIVATE_ASSESSMENT', 'reservation_key']),
                  'HTTP server logs retain no private scene code, project brief or assessment content')
        except BaseException:
            log.seek(0)
            print(log.read()[-6000:])
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait()

print(json.dumps({'ok': True, 'checks': len(checks)}))
