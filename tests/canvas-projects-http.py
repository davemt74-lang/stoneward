"""Owner-scoped projects, resource inventories and preferences over real PHP HTTP.

Uses isolated SQLite/account data and never executes an approved creative build.
"""
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
spec = importlib.util.spec_from_file_location('canvas_audio_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
fixture_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = fixture_argv
PHP = os.environ.get('SF_TEST_PHP', 'php')
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
    return client, response.json()['csrf']


def mock_text_provider(site):
    """Replace only the disposable provider transport, retaining the real APIs."""
    path = site / 'api/agent-runtime.php'
    source = path.read_text()
    start = source.index('function sf_http_json(')
    end = source.index('function sf_http_binary(', start)
    transport = '''function sf_http_json(string $url,array $headers,array $payload,int $timeout=45): array {
        if($url!=='https://api.openai.com/v1/responses')throw new RuntimeException('Unexpected fixture provider');
        $routing=str_contains((string)($payload['instructions']??''),'intent orchestrator');
        file_put_contents(SF_ROOT.'/provider-calls.log',($routing?'route':'discuss')."\\n",FILE_APPEND);
        if($routing)file_put_contents(SF_ROOT.'/route-inputs.jsonl',json_encode($payload['input'])."\\n",FILE_APPEND);
        $reply=$routing?['route'=>'canvas_discuss']:['reply'=>'The two designs can work together. Which part should lead?','proposal'=>null];
        return ['output_text'=>json_encode($reply),'usage'=>['input_tokens'=>20,'output_tokens'=>12]];
    }
'''
    path.write_text(source[:start] + transport + source[end:])
    fixture.cli(site, "sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'isolated-fixture-key','model'=>'fixture']);"
                "sf_token_adjust(1,100000,'admin_grant','isolated-http-discussion');")


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-projects-') as temporary:
    site = Path(temporary)
    _, seeded_scene = fixture.seed(site)
    (site / 'data/shows.json').write_text(json.dumps([
        {'id': 'public-show', 'title': 'Public show', 'date': '2040-01-01', 'venue': 'Public Hall'},
        {'id': 'hidden-show', 'title': 'Private show', 'date': '2040-01-02', 'public_visible': False},
    ]))
    seeded_resources = json.loads(fixture.cli(site, '''
        require_once SF_ROOT.'/api/agent-scene-history.php';
        $foreign=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'Other owner private canvas',
            'html'=>'<section></section>','css'=>'','design_brief'=>'Other owner private design']);
        sf_media_ensure_schema();sf_campaign_ensure_schema();sf_entitlements_ensure_schema();
        sf_commerce_save_product(['id'=>'shirt','title'=>'Published shirt','description'=>'Public artist shirt',
            'status'=>'active','base_price_cents'=>1200,'metadata'=>['private_supplier'=>'Hidden supplier']],0);
        sf_commerce_save_product(['id'=>'draft-product','title'=>'Secret draft merchandise','status'=>'draft'],0);
        $ticket=sf_ticket_offer_save(['show_id'=>'public-show','title'=>'Public admission','offer_type'=>'ticket',
            'status'=>'published','price_cents'=>2400,'fulfillment_type'=>'external',
            'external_url'=>'https://example.invalid/checkout'],0);
        sf_ticket_offer_save(['show_id'=>'public-show','title'=>'Secret draft offer','offer_type'=>'ticket','status'=>'draft'],0);
        sf_ticket_offer_save(['show_id'=>'hidden-show','title'=>'Secret show offer','offer_type'=>'ticket','status'=>'published'],0);
        echo json_encode(['foreign_scene_id'=>$foreign['id'],'ticket_id'=>$ticket['id']]);
    '''))
    mock_text_provider(site)
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
            check(guest.get('/api/canvas-projects.php').status == 401,
                  'project preferences and proposals require an authenticated account')
            check(guest.get('/api/canvas-resources.php?kind=canvas').status == 401,
                  'private resource inventory requires a signed-in account')
            owner, csrf = sign_in(base, 'owner@example.invalid')
            other, other_csrf = sign_in(base, 'other@example.invalid')
            headers, other_headers = {'X-CSRF-Token': csrf}, {'X-CSRF-Token': other_csrf}
            own_resources = owner.get('/api/canvas-resources.php?kind=canvas&user_id=2')
            other_resources = other.get('/api/canvas-resources.php?kind=canvas')
            check(own_resources.status == 200 and
                  [row['id'] for row in own_resources.json()['items']] == [str(seeded_scene['id'])] and
                  [row['id'] for row in other_resources.json()['items']] == [str(seeded_resources['foreign_scene_id'])],
                  'canvas inventory uses the cookie owner and ignores a supplied account ID')
            check('Other owner private' not in own_resources.body().decode() and
                  'no-store' in own_resources.headers.get('cache-control', ''),
                  'canvas inventory excludes other owners and disables response caching')
            merchandise = owner.get('/api/canvas-resources.php?kind=merch')
            tickets = owner.get('/api/canvas-resources.php?kind=tickets')
            check(merchandise.status == 200 and [row['id'] for row in merchandise.json()['items']] == ['shirt'] and
                  tickets.status == 200 and [row['id'] for row in tickets.json()['items']] == [str(seeded_resources['ticket_id'])],
                  'resource HTTP lists expose only published merchandise and ticket offers on public shows')
            check('Secret' not in merchandise.body().decode() + tickets.body().decode() and
                  'Hidden supplier' not in merchandise.body().decode(),
                  'resource HTTP metadata omits private products, shows, offers and supplier details')
            check(owner.get('/api/canvas-resources.php?kind=purchases').status == 422 and
                  owner.get('/api/canvas-resources.php?kind[]=canvas').status == 422 and
                  owner.post('/api/canvas-resources.php', {'kind': 'canvas'}, headers).status == 405,
                  'resource endpoint restricts inventory kinds and read-only HTTP methods')
            fixture.cli(site, "sf_db()->exec(\"UPDATE store_products SET status='hidden' WHERE id='shirt'\");")
            shows = json.loads((site / 'data/shows.json').read_text())
            shows[0]['public_visible'] = False
            (site / 'data/shows.json').write_text(json.dumps(shows))
            check(owner.get('/api/canvas-resources.php?kind=merch').json()['items'] == [] and
                  owner.get('/api/canvas-resources.php?kind=tickets').json()['items'] == [],
                  'subsequent resource requests immediately reflect withdrawn merchandise and shows')
            initial = owner.get('/api/canvas-projects.php').json()
            check(initial['ok'] and initial['preferences'] == {'ideas_enabled': True, 'auto_approve': False},
                  'new account defaults enable ideas and require project approval')
            denied = owner.post('/api/canvas-projects.php', {'action': 'preferences', 'auto_approve': True})
            check(denied.status == 403, 'changing project authorization requires the account CSRF token')
            saved = owner.post('/api/canvas-projects.php',
                               {'action': 'preferences', 'ideas_enabled': False, 'auto_approve': True}, headers)
            check(saved.status == 200 and saved.json()['preferences'] == {'ideas_enabled': False, 'auto_approve': True},
                  'explicit preference changes are stored for the current account')
            check(owner.get('/api/canvas-projects.php').json()['preferences'] == saved.json()['preferences'],
                  'project preferences persist across subsequent HTTP reads')
            check(other.get('/api/canvas-projects.php').json()['preferences'] == {'ideas_enabled': True, 'auto_approve': False},
                  'another account retains its independent default preferences')
            reset = owner.post('/api/canvas-projects.php',
                               {'action': 'preferences', 'ideas_enabled': True, 'auto_approve': False}, headers)
            assert reset.status == 200, reset.json()
            project = {'canvas_kind': 'custom', 'track_id': '', 'brief': 'A paper landscape that responds to dragging',
                       'music_inputs': {'mode': 'selected', 'track_ids': ['song-9', 'song-2']}}
            prepared = owner.post('/api/canvas-projects.php',
                                  {'action': 'propose', 'project': project, 'title': 'Paper landscape',
                                   'concept': 'Layered paper follows the listener’s gestures.'}, headers)
            assert prepared.status == 201, prepared.json()
            proposal = prepared.json()['proposal']
            check(proposal['status'] == 'awaiting_approval' and
                  all(proposal['project'].get(key) == value for key, value in project.items()),
                  'a new project remains an exact reviewable snapshot before approval')
            blocked_build = owner.post('/api/agent-scene.php', {**proposal['project'], 'project_managed': True,
                                       'proposal_id': proposal['id'], 'request_id': '9d' * 16}, headers)
            check(blocked_build.status == 409 and blocked_build.json()['error'] == 'canvas_project_approval_required',
                  'managed build execution is rejected by the server before project approval')
            check(any(row['id'] == proposal['id'] for row in owner.get('/api/canvas-projects.php').json()['proposals']) and
                  not any(row['id'] == proposal['id'] for row in other.get('/api/canvas-projects.php').json()['proposals']),
                  'proposal history is restricted to its owning account')
            foreign = other.post('/api/canvas-projects.php', {'action': 'approve', 'id': proposal['id']}, other_headers)
            check(foreign.status == 404, 'another account cannot approve a private project by guessing its ID')
            approved = owner.post('/api/canvas-projects.php', {'action': 'approve', 'id': proposal['id']}, headers)
            assert approved.status == 200, approved.json()
            check(approved.json()['proposal']['status'] == 'approved' and approved.json()['proposal']['project'] == proposal['project'],
                  'approval preserves the exact reviewed project scope')
            again = owner.post('/api/canvas-projects.php', {'action': 'approve', 'id': proposal['id']}, headers)
            check(again.status == 200 and again.json()['proposal']['id'] == proposal['id'] and
                  len(owner.get('/api/canvas-projects.php').json()['proposals']) == 1,
                  'repeated approval is idempotent and creates no duplicate project')
            canceled = owner.post('/api/canvas-projects.php', {'action': 'cancel', 'id': proposal['id']}, headers)
            check(canceled.status == 200 and canceled.json()['proposal']['status'] == 'canceled',
                  'an approved project can be canceled before any build starts')
            stale = owner.post('/api/canvas-projects.php', {'action': 'approve', 'id': proposal['id']}, headers)
            check(stale.status in (400, 409, 422), 'canceled approval cannot be reused')
            foreign_cancel = other.post('/api/canvas-projects.php', {'action': 'cancel', 'id': proposal['id']}, other_headers)
            check(foreign_cancel.status == 404, 'another account cannot cancel or alter the owner’s proposal')
            check(owner.get('/api/canvas-projects.php').json()['feedback'] == [],
                  'canceling a project never records a dislike or inferred design preference')
            feedback = {'action': 'feedback', 'scene_id': seeded_scene['id'], 'preference': 'like', 'reason': 'Clear motion'}
            check(guest.post('/api/canvas-projects.php', feedback).status == 401 and
                  owner.post('/api/canvas-projects.php', feedback).status == 403,
                  'canvas feedback requires both an authenticated owner and valid CSRF')
            check(other.post('/api/canvas-projects.php', feedback, other_headers).status == 422,
                  'another account cannot rate a scene by guessing its ID')
            rated = owner.post('/api/canvas-projects.php', feedback, headers)
            check(rated.status == 200 and rated.json()['feedback']['preference'] == 'like' and
                  rated.json()['feedback']['scene_id'] == seeded_scene['id'],
                  'an explicit Like stores only the selected owned scene and reason')
            long_reason = owner.post('/api/canvas-projects.php', {**feedback, 'reason': 'x' * 800}, headers)
            check(long_reason.status == 200 and len(long_reason.json()['feedback']['reason']) == 800 and
                  owner.post('/api/canvas-projects.php', {**feedback, 'reason': 'x' * 801}, headers).status == 422,
                  'feedback reason bounds match the composer without silently truncating text')
            project_feedback = {'action': 'feedback', 'proposal_id': proposal['id'], 'preference': 'dislike',
                                'reason': 'I want a slower composition'}
            disliked = owner.post('/api/canvas-projects.php', project_feedback, headers)
            check(disliked.status == 200 and disliked.json()['feedback']['proposal_id'] == proposal['id'] and
                  disliked.json()['feedback']['preference'] == 'dislike',
                  'an explicit Dislike records a project preference independently of cancellation')
            check(other.get('/api/canvas-projects.php').json()['feedback'] == [] and
                  other.post('/api/canvas-projects.php', project_feedback, other_headers).status == 422,
                  'feedback reads and writes preserve proposal ownership')
            cleared = owner.post('/api/canvas-projects.php', {**feedback, 'preference': 'clear'}, headers)
            remaining_feedback = owner.get('/api/canvas-projects.php').json()['feedback']
            check(cleared.status == 200 and cleared.json()['feedback'] is None and
                  len(remaining_feedback) == 1 and remaining_feedback[0]['proposal_id'] == proposal['id'],
                  'clearing a scene rating preserves independently saved project feedback')

            discussion = {'action': 'discuss', 'request_id': 'ab' * 16,
                          'brief': 'Could these designs fit together?', 'current_canvas_id': seeded_scene['id'],
                          'source_scene_ids': [seeded_scene['id']]}
            check(guest.post('/api/canvas-projects.php', discussion).status == 401 and
                  owner.post('/api/canvas-projects.php', discussion).status == 403,
                  'canvas discussion requires authentication and account CSRF')
            cross_owner = other.post('/api/canvas-projects.php', discussion, other_headers)
            check(cross_owner.status == 422 and not (site / 'provider-calls.log').exists(),
                  'foreign canvas context is refused before the text provider is reached')
            discussed = owner.post('/api/canvas-projects.php', discussion, headers)
            check(discussed.status == 200 and discussed.json()['state'] == 'ready' and
                  discussed.json()['reply'].endswith('Which part should lead?') and discussed.json().get('proposal') is None,
                  'the real discussion controller returns a conversational answer without preparing or executing a build')
            replay = owner.post('/api/canvas-projects.php', discussion, headers)
            check(replay.status == 200 and replay.json() == discussed.json() and
                  (site / 'provider-calls.log').read_text().splitlines() == ['discuss'],
                  'replaying a completed discussion reuses its stored reply without another provider request')
            message = 'Could we reconsider the pacing of {canvas:' + str(seeded_scene['id']) + '}?'
            routed = owner.post('/api/agent-chat.php', {'message': message, 'client_state': {'public_canvas_enabled': True}}, headers)
            check(routed.status == 200 and routed.json()['decision'] == 'canvas_discuss' and
                  routed.json()['action'] == {'type': 'canvas_discuss', 'brief': message} and
                  routed.json()['tool_contract']['skill_id'] == 'customer_canvas_discussion' and
                  (site / 'provider-calls.log').read_text().splitlines() == ['discuss', 'route'],
                  'normal Agent Chat routes a natural request through its model once and preserves the original canvas reference')
            natural_brief = 'Could we combine every selected design into a slower experience?'
            large_message = ' '.join('{canvas:' + str(index) + '}' for index in range(1, 1201)) + ' ' + natural_brief
            large_route = owner.post('/api/agent-chat.php', {'message': large_message,
                                     'client_state': {'public_canvas_enabled': True}}, headers)
            route_input = json.loads((site / 'route-inputs.jsonl').read_text().splitlines()[-1])
            route_input_text = json.dumps(route_input)
            check(large_route.status == 200 and large_route.json()['decision'] == 'canvas_discuss' and
                  large_route.json()['action'] == {'type': 'canvas_discuss', 'brief': natural_brief} and
                  natural_brief in route_input_text and '\\"canvas\\":1200' in route_input_text and
                  '{canvas:' not in route_input_text and
                  (site / 'provider-calls.log').read_text().splitlines() == ['discuss', 'route', 'route'],
                  'large selected-resource lists preserve the full natural request and count without sending or truncating identities in intent routing')
            with sqlite3.connect(site / 'fixture.sqlite') as connection:
                exists = connection.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='agent_scene_requests'").fetchone()
                count = connection.execute('SELECT COUNT(*) FROM agent_scene_requests').fetchone()[0] if exists else 0
            check(count == 0,
                  'reading preferences and proposing, approving or canceling never dispatches a creative build')
        except BaseException:
            log.seek(0)
            print(log.read()[-6000:])
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait()

print(json.dumps({'ok': True, 'checks': len(checks)}))
