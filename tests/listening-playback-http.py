"""Protocol2 listening through real PHP auth, cookies, CSRF and privacy endpoints.

Disposable copied site and SQLite only; no provider calls or deployed databases.
"""
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
import uuid

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('listening_http_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv
PHP = os.environ.get('SF_TEST_PHP', 'php')
ENDPOINT = '/api/listening.php'
checks = []


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


def sign_in(base, email, client=None):
    client = client or fixture.HTTPClient(base)
    csrf = client.get('/api/auth.php').json()['csrf']
    response = client.post('/api/auth.php', {'action':'login', 'email':email, 'password':'fixture-only-password'}, {'X-CSRF-Token':csrf})
    assert response.status == 200, response.data
    return client


def protocol(client):
    response = client.get(ENDPOINT+'?protocol=2')
    assert response.status == 200, response.data
    return response.json()


def request(auth, action='begin', play=None, event=None, **extra):
    body = {'protocol':2, 'action':action, 'session_token':auth['session_token'], 'privacy_epoch':auth['privacy_epoch'],
            'play_id':play or uuid.uuid4().hex, 'event_id':event or uuid.uuid4().hex,
            'event_type':'start' if action=='begin' else 'pause', 'position_seconds':0,
            'duration_seconds':6, 'elapsed_played_seconds':0, 'playback_rate':1}
    if action=='begin':body.update(track_id='song-1', context={'app':True}, playback_method='main')
    return {**body, **extra}


def post(client, auth, body, headers=None):
    return client.post(ENDPOINT, body, {'X-CSRF-Token':auth['csrf']} if headers is None else headers)


def rows(site, query, parameters=()):
    with sqlite3.connect(site/'fixture.sqlite') as db:
        db.row_factory = sqlite3.Row
        return [dict(row) for row in db.execute(query, parameters)]


def play_row(site, play):
    found = rows(site, 'SELECT * FROM listening_playbacks WHERE play_id=?', (play,))
    return found[0] if found else None


def event_count(site):
    return rows(site, 'SELECT COUNT(*) AS count FROM listening_events')[0]['count']


with tempfile.TemporaryDirectory(prefix='stonefellow-listening-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    catalog=json.loads((site/'data/catalog.json').read_text())
    catalog.append({'id':'catalog-hidden','title':'Hidden public flag','state':'published','public_visible':False,'audio':'assets/audio/fixture.mp3'})
    (site/'data/catalog.json').write_text(json.dumps(catalog))
    shutil.copytree(ROOT/'admin', site/'admin', dirs_exist_ok=True)
    seeded = json.loads(fixture.cli(site, r'''
        require_once SF_ROOT.'/api/migrations.php';sf_migration_run_all(1,'');
        require_once SF_ROOT.'/api/agent-scene-core.php';require_once SF_ROOT.'/api/canvas-gallery-core.php';require_once SF_ROOT.'/api/listening-playback-core.php';
        sf_db()->exec("UPDATE users SET role='admin' WHERE id=2");
        $template=['canvas_kind'=>'custom','track_id'=>'','title'=>'HTTP source one','html'=>'<section class="one"><p>One</p></section>','css'=>'.one{color:#abc}'];
        $scene=sf_agent_scene_history_save(1,$template);
        $revision=sf_agent_scene_history_save(1,array_replace($template,['title'=>'HTTP source two','html'=>'<section class="two"><p>Two</p></section>']),$scene['id']);
        $foreign=sf_agent_scene_history_save(2,$template);
        $entry=sf_canvas_gallery_submit(['id'=>1,'role'=>'user'],['scene_id'=>$scene['id'],'request_id'=>str_repeat('a1',16),'title'=>'HTTP approved source','description'=>'Synthetic published canvas','creator_label'=>'Fixture','publication_consent'=>true]);
        sf_canvas_gallery_moderate(['id'=>2,'role'=>'admin'],$entry['id'],'approve',$entry['snapshot_hash']);
        $pending=sf_canvas_gallery_submit(['id'=>1,'role'=>'user'],['scene_id'=>$revision['id'],'request_id'=>str_repeat('b1',16),'title'=>'HTTP pending source','description'=>'Synthetic pending canvas','creator_label'=>'Fixture','publication_consent'=>true]);
        $stored=sf_agent_scene_history_get(1,$scene['id']);$storedRevision=sf_agent_scene_history_get(1,$revision['id']);
        $gallery=json_decode(sf_canvas_gallery_row($entry['id'])['snapshot_json'],true,16,JSON_THROW_ON_ERROR);
        $hash=static fn(array $s):string=>hash('sha256',json_encode([$s['html'],$s['css'],$s['js']??''],JSON_THROW_ON_ERROR));
        sf_listening_playback_schema();
        echo json_encode(['scene'=>$scene['id'],'revision'=>$revision['id'],'foreign'=>$foreign['id'],'gallery'=>$entry['id'],'pending'=>$pending['id'],'scene_hash'=>$hash($stored),'revision_hash'=>$hash($storedRevision),'gallery_hash'=>$hash($gallery)]);
    '''))
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0));port = reservation.getsockname()[1]
    base = 'http://127.0.0.1:'+str(port)
    with (site/'php.log').open('w+') as log:
        server = subprocess.Popen([PHP, '-d', 'session.save_path='+str(site/'sessions'), '-S', '127.0.0.1:'+str(port), '-t', str(site)],
                                  stdout=log, stderr=log, start_new_session=True)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port), timeout=.1):break
                except OSError:time.sleep(.02)
            guest = fixture.HTTPClient(base)
            guest_auth = protocol(guest)
            check(guest_auth['user_id']==0 and type(guest_auth['user_id']) is int and guest_auth['privacy_epoch']==0 and isinstance(guest_auth['history_enabled'],bool),
                  'Guest protocol exposes numeric zero identity, epoch and explicit history state')
            check('no-store' in guest.get(ENDPOINT+'?protocol=2').headers.get('cache-control',''), 'Listening credentials and summaries are never cached')
            check(guest.head(ENDPOINT).status==405 and guest.get(ENDPOINT).json()['analytics'] is None, 'Unsupported method rejects and guest has no private analytics')
            guest_begin = request(guest_auth)
            check(post(guest,guest_auth,guest_begin,{}).status==403, 'Protocol2 guest writes require CSRF')
            check(post(guest,guest_auth,guest_begin,{'X-CSRF-Token':'forged'}).status==403, 'Forged CSRF cannot create a listening instance')
            check(post(guest,guest_auth,guest_begin).status==200 and play_row(site,guest_begin['play_id'])['user_id'] is None,
                  'Valid guest native observation contributes an anonymous canonical play')
            guest2 = fixture.HTTPClient(base);guest2_auth=protocol(guest2)
            check(guest2_auth['session_token']!=guest_auth['session_token'] and post(guest2,guest2_auth,guest_begin).status==409,
                  'Another guest cookie cannot replay a different session token')
            hijack=request(guest2_auth,'event',guest_begin['play_id'])
            check(post(guest2,guest2_auth,hijack).status==409, 'Valid guest credentials cannot take over another actor play identity')

            owner=sign_in(base,'owner@example.invalid');auth=protocol(owner)
            other=sign_in(base,'other@example.invalid');other_auth=protocol(other)
            owner2=sign_in(base,'owner@example.invalid');owner2_auth=protocol(owner2)
            check(auth['user_id']==1 and other_auth['user_id']==2 and owner2_auth['session_token']!=auth['session_token'],
                  'Protocol identity and random credentials bind to the actual account and browser session')
            body=request(auth, context={'scene_id':seeded['scene']}, playback_method='independent', user_id=2,
                         source='forged-label', attribution={'scene_id':seeded['foreign'],'code_hash':'f'*64})
            check(post(owner,auth,body,{'X-CSRF-Token':other_auth['csrf']}).status==403, 'Another account CSRF token cannot authorize a listening write')
            before=event_count(site)
            response=post(owner,auth,body)
            check(response.status==200 and not response.json()['replayed'] and event_count(site)==before+1,
                  'Authenticated begin atomically contributes one canonical play')
            saved=play_row(site,body['play_id']);attribution=json.loads(saved['attribution_json'])
            check(saved['user_id']==1 and saved['playback_method']=='independent' and attribution=={'kind':'scene','scene_id':seeded['scene'],'code_hash':seeded['scene_hash']},
                  'Server binds real owner, method and exact stored canvas source; caller labels cannot replace them')
            check(post(owner,auth,dict(reversed(list(body.items())))).json()['replayed'] and event_count(site)==before+1,
                  'Identical retry with reordered JSON keys replays without another canonical start')
            check(post(owner,auth,{**body,'position_seconds':1}).status==409 and event_count(site)==before+1,
                  'Reusing an event identity for changed measurements rejects without a ledger mutation')
            check(post(owner2,owner2_auth,body).status==409 and post(other,other_auth,body).status==409,
                  'Session token from another browser or account fails before event processing')
            for client,credential in ((owner2,owner2_auth),(other,other_auth)):
                check(post(client,credential,request(credential,'event',body['play_id'])).status==409,
                      'Current credentials cannot claim another actor instance even with its play ID')
            bad=request(auth, privacy_epoch=auth['privacy_epoch']+1)
            check(post(owner,auth,bad).status==409 and post(owner,auth,request(auth,session_token='f'*32)).status==409,
                  'Forged epoch or session token rejects before any observation is written')
            for override in ({'track_id':'unknown-track'},{'track_id':'hidden-song'},{'track_id':'catalog-hidden'},
                             {'track_id':'https://example.invalid/track.mp3'},{'playback_method':'claimed-local'},
                             {'elapsed_played_seconds':1},{'playback_rate':0},{'play_id':'not-a-play'}):
                snapshot=event_count(site);rejected=post(owner,auth,request(auth,**override))
                check(rejected.status==422 and event_count(site)==snapshot,
                      'Malformed or non-public playback input rejects atomically: '+next(iter(override)))
            for context in ({'scene_id':seeded['foreign']},{'scene_id':999999},{'gallery_id':seeded['pending']},{'gallery_id':'f'*32}):
                snapshot=event_count(site);rejected=post(owner,auth,request(auth,context=context))
                check(rejected.status==404 and event_count(site)==snapshot, 'Unavailable private or unpublished context reveals no code and records no play: '+str(context))
            check(post(guest,guest_auth,request(guest_auth,context={'scene_id':seeded['scene']})).status==404,
                  'Guest cannot attribute playback to a private account canvas')
            for context in ({'scene_id':seeded['scene'],'gallery_id':seeded['gallery']},{'scene_id':seeded['scene'],'code_hash':'f'*64},{'app':True,'source':'claimed'}):
                check(post(owner,auth,request(auth,context=context)).status==422, 'Ambiguous or caller-forged context fields reject')
            progress=request(auth,'event',body['play_id'],event_type='progress',position_seconds=1,elapsed_played_seconds=1,
                             context={'scene_id':seeded['revision']},track_id='song-2',playback_method='main')
            response=post(owner,auth,progress)
            check(response.status==200 and 0<=response.json()['listened_seconds']<=1 and json.loads(play_row(site,body['play_id'])['attribution_json'])==attribution,
                  'Event timing stays wall bounded and later authored fields cannot change the captured source or track')
            check(post(owner,auth,progress).json()['replayed'], 'Progress replay is idempotent through the actual endpoint')
            revised=request(auth,context={'scene_id':seeded['revision']})
            check(post(owner,auth,revised).status==200 and json.loads(play_row(site,revised['play_id'])['attribution_json'])['code_hash']==seeded['revision_hash'] and seeded['revision_hash']!=seeded['scene_hash'],
                  'A revised canvas gets its own exact source identity without rewriting the prior instance')
            gallery=request(guest_auth,context={'gallery_id':seeded['gallery']},playback_method='independent')
            check(post(guest,guest_auth,gallery).status==200 and json.loads(play_row(site,gallery['play_id'])['attribution_json'])=={'kind':'gallery','gallery_id':seeded['gallery'],'code_hash':seeded['gallery_hash']},
                  'Anonymous public gallery playback binds the approved stored snapshot, not the creator private revision')
            # Account changes within one browser invalidate old protocol credentials despite current valid CSRF.
            switched=sign_in(base,'owner@example.invalid');old=protocol(switched);old_begin=request(old)
            check(post(switched,old,old_begin).status==200, 'Account-switch fixture begins with a real signed-in observation')
            sign_in(base,'other@example.invalid',switched);new=protocol(switched)
            stale={**request(old,'event',old_begin['play_id']),'session_token':old['session_token']}
            check(new['user_id']==2 and new['session_token']!=old['session_token'] and post(switched,new,stale).status==409,
                  'Same browser changing account cannot submit former account credentials with fresh CSRF')
            check(post(switched,new,request(new,'event',old_begin['play_id'])).status==409,
                  'New account cannot take over the former account play after credential refresh')

            other_begin=request(other_auth,context={'scene_id':seeded['foreign']})
            check(post(other,other_auth,other_begin).status==200, 'Another account has an independent valid private listening instance')
            history_before=event_count(site)
            opted=owner.post('/api/privacy.php',{'action':'save_profile','profile':{'listening_history_enabled':False,'personalization_enabled':True}}, {'X-CSRF-Token':auth['csrf']})
            check(opted.status==200 and opted.json()['profile']['listening_history_enabled'] is False, 'Actual privacy endpoint disables personal listening history')
            disabled=protocol(owner)
            check(disabled['privacy_epoch']>auth['privacy_epoch'] and disabled['session_token']!=auth['session_token'] and not disabled['history_enabled'],
                  'Opt-out rotates the privacy epoch and invalidates outstanding playback credentials')
            check(post(owner,disabled,request(auth,'event',body['play_id'])).status==409 and post(owner,disabled,body).status==409,
                  'Late progress and begin replay cannot restore account-linked history after opt-out')
            check(not rows(site,'SELECT * FROM listening_playbacks WHERE user_id=1') and not rows(site,'SELECT * FROM listening_events WHERE user_id=1') and play_row(site,other_begin['play_id'])['user_id']==2,
                  'Opt-out erases personal instance/source links while leaving another listener untouched')
            check(event_count(site)==history_before, 'Opt-out preserves only anonymized canonical aggregate counts')
            anonymous=request(disabled,context={'scene_id':seeded['scene']},playback_method='independent')
            check(post(owner,disabled,anonymous).status==200 and play_row(site,anonymous['play_id'])['user_id'] is None and json.loads(play_row(site,anonymous['play_id'])['attribution_json'])=={'kind':'anonymous'},
                  'Opted-out native listening remains anonymous without private canvas/source attribution')
            enabled_response=owner.post('/api/privacy.php',{'action':'save_profile','profile':{'listening_history_enabled':True,'personalization_enabled':True}}, {'X-CSRF-Token':disabled['csrf']})
            enabled=protocol(owner)
            check(enabled_response.status==200 and enabled['history_enabled'] and enabled['privacy_epoch']>disabled['privacy_epoch'],
                  'Re-enabling personal history issues a fresh privacy generation')
            current=request(enabled,context={'scene_id':seeded['scene']})
            check(post(owner,enabled,current).status==200, 'Fresh observation after privacy refresh can create current account history')
            export=owner.post('/api/privacy.php',{'action':'export','confirmed':True}, {'X-CSRF-Token':enabled['csrf']})
            exported=export.json().get('export',{}).get('listening_playbacks',[])
            check(export.status==200 and len(exported)==1 and exported[0]['play_id']==current['play_id'] and not {'actor_hash','session_key','session_token'}&set(exported[0]),
                  'Actual account export contains only current owner playbacks and omits session/actor credentials')
            cleared=owner.post('/api/privacy.php',{'action':'clear_history','confirmed':True}, {'X-CSRF-Token':enabled['csrf']})
            fresh=protocol(owner)
            check(cleared.status==200 and fresh['privacy_epoch']>enabled['privacy_epoch'] and not rows(site,'SELECT * FROM listening_playbacks WHERE user_id=1'),
                  'Actual clear-history endpoint purges instances and advances privacy epoch')
            check(post(owner,fresh,request(enabled,'event',current['play_id'])).status==409 and post(owner,fresh,current).status==409,
                  'Old begin and late events cannot recreate cleared account data')
            check(play_row(site,other_begin['play_id'])['user_id']==2 and not rows(site,'SELECT * FROM listening_playback_receipts WHERE play_id=?',(current['play_id'],)),
                  'Clear-history removes matching receipts and preserves other accounts')
            check(post(owner,fresh,request(fresh,context={'scene_id':seeded['scene']})).status==200,
                  'The same browser can deliberately start a new observation after privacy refresh')
            print(f'{len(checks)} real HTTP listening protocol checks passed',flush=True)
        except BaseException:
            log.seek(0);print(log.read()[-6000:]);raise
        finally:
            os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
