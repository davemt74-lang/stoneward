"""Playlist project approval, generation, source drift and private history over actual PHP HTTP.

Only the disposable provider transport is replaced; no external AI calls occur.
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

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('playlist_project_http_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv

checks = []
PROJECTS = '/api/canvas-projects.php'
SCENES = '/api/agent-scene.php'


def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


def login(base, email):
    client = fixture.HTTPClient(base)
    csrf = client.get('/api/auth.php').json()['csrf']
    response = client.post('/api/auth.php', {'action': 'login', 'email': email, 'password': 'fixture-only-password'}, {'X-CSRF-Token': csrf})
    assert response.status == 200, response.json()
    return client, {'X-CSRF-Token': response.json()['csrf']}


with tempfile.TemporaryDirectory(prefix='stoneward-playlist-project-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    seeded = json.loads(fixture.cli(site, """
        require_once SF_ROOT.'/api/canvas-playlists-core.php';
        $playlist=sf_playlist_create(1,'PRIVATE_OWNER_SEQUENCE','Private playlist description','private','user',['song-1','song-2','song-1']);
        sf_canvas_playlists_ensure_schema();sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'fixture']);sf_enroll_user(1,null);
        sf_token_adjust(1,100000,'admin_grant','playlist-http');
        echo json_encode(['id'=>$playlist['id']]);
    """))
    runtime = site / 'api/agent-runtime.php'
    source = runtime.read_text()
    begin, end = source.index('function sf_http_json('), source.index('function sf_http_binary(')
    runtime.write_text(source[:begin] + '''function sf_http_json(string $url,array $headers,array $payload,int $timeout=45): array {
        if($url!=='https://api.openai.com/v1/responses')throw new RuntimeException('Unexpected provider in fixture');
        file_put_contents(SF_ROOT.'/provider-calls.jsonl',json_encode($payload)."\\n",FILE_APPEND);
        return ['output_text'=>json_encode(['title'=>'Authored sequence','html'=>'<section class="composition"></section>','css'=>'.composition{color:#abc}']),
            'usage'=>['input_tokens'=>11,'output_tokens'=>19]];
    }
''' + source[end:])

    def calls():
        return (site / 'provider-calls.jsonl').read_text().splitlines() if (site / 'provider-calls.jsonl').exists() else []

    def balance():
        return int(fixture.cli(site, "echo sf_token_balance(1);"))

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
            owner, headers = login(base, 'owner@example.invalid')
            other, other_headers = login(base, 'other@example.invalid')
            item = next(item for item in owner.get('/api/canvas-resources.php?kind=playlists').json()['items'] if item['ref'] == 'own-' + str(seeded['id']))
            inputs = {'items': [{'ref': item['ref'], 'version': item['revision_hash']}]}
            direct = {'mode': 'selected', 'track_ids': ['song-2', 'song-3']}
            project = {'canvas_kind': 'custom', 'brief': 'Make independent musical forms', 'playlist_inputs': inputs, 'music_inputs': direct}
            request = {'action': 'propose', 'project': project}
            check(guest.post(PROJECTS, request).status == 401, 'Anonymous callers cannot create private playlist projects')
            check(owner.post(PROJECTS, request).status == 403 and owner.post(PROJECTS, request, {'X-CSRF-Token': 'forged'}).status == 403,
                  'Project proposals reject missing and forged CSRF before resolving private resources')
            check(other.post(PROJECTS, request, other_headers).status == 422, 'Another account cannot propose someone else\'s owned playlist')
            proposed = owner.post(PROJECTS, request, headers)
            assert proposed.status == 201, proposed.json()
            proposal = proposed.json()['proposal']
            manifest = proposal['project']['playlist_manifest']
            check(proposal['status'] == 'awaiting_approval' and proposal['project']['music_inputs'] == direct and len(manifest['playlists'][0]['items']) == 3,
                  'HTTP proposal freezes ordered membership and distinct direct music resources')
            check(not calls(), 'Resolving and proposing playlists performs no model call')
            check(other.get(PROJECTS + '?id=' + str(proposal['id'])).status == 404, 'Proposal contents stay private to their account')
            build = dict(proposal['project'], project_managed=True, proposal_id=proposal['id'], request_id='d1' * 16)
            check(owner.post(SCENES, build, headers).status == 409 and not calls(), 'Unapproved playlist generation fails before paid inference')
            check(owner.post(PROJECTS, {'action': 'approve', 'id': proposal['id']}, headers).status == 200, 'Explicit approval accepts the exact frozen playlist project')
            tampered = dict(build, music_inputs={'mode': 'all'})
            check(owner.post(SCENES, tampered, headers).status == 409 and not calls(), 'Changing the direct scope cannot consume an existing playlist approval')
            before = balance()
            response = owner.post(SCENES, build, headers)
            assert response.status == 201, response.json()
            scene = response.json()['scene']
            check(scene['playlist_manifest'] == manifest and scene['direct_music_inputs'] == direct and scene['music_inputs']['track_ids'] == ['song-2', 'song-3', 'song-1'],
                  'Real HTTP generation saves provenance and the exact deduplicated effective MP3 set')
            check(len(calls()) == 1 and balance() == before - 30, 'Successful generation settles exactly the fixture model usage once')
            model = json.loads(calls()[0])
            message = json.loads(model['input'][-1]['content'][0]['text'])
            check(message['selected_playlists'] == manifest and 'not widgets' in model['instructions'], 'The actual provider payload treats ordered playlists as authored-code inventory')
            saved = owner.get(SCENES + '?id=' + str(scene['id']))
            check(saved.status == 200 and saved.json()['scene']['direct_music_inputs'] == direct and saved.json()['scene']['playlist_manifest'] == manifest,
                  'A fresh PHP history request restores full playlist provenance')
            check(other.get(SCENES + '?id=' + str(scene['id'])).status == 404, 'A different account cannot load private canvas playlist metadata')
            context = '&scene_id=' + str(scene['id'])
            scoped_path = '/api/canvas-audio.php?action=catalog' + context
            scoped = owner.get(scoped_path)
            check(scoped.status == 200 and scoped.json()['playlists'] == manifest['playlists'] and [track['track_id'] for track in scoped.json()['tracks']] == ['song-2', 'song-3', 'song-1'],
                  'Fresh runtime catalog resolves the trusted saved scene scope and ordered playlist manifest on the server')
            scoped_source = '/api/canvas-audio.php?action=source&track_id=song-1' + context
            check(owner.get(scoped_source).status == 200 and owner.request('HEAD', scoped_source).status == 200 and owner.request('GET', scoped_source, headers={'Range': 'bytes=0-31'}).status == 206,
                  'Scoped GET HEAD and byte ranges independently authorize a current source')
            check(all('scene_id=' + str(scene['id']) in track['source_url'] for track in scoped.json()['tracks']),
                  'Catalog source references retain trusted context for canonical native playback')
            check(guest.get(scoped_path).status == 404 and other.get(scoped_path).status == 404 and other.get(scoped_source).status == 404,
                  'Foreign and guest requests cannot use private runtime scene context')
            check(owner.get('/api/canvas-audio.php?action=source&track_id=song-4' + context).status == 404 and owner.get(scoped_path + '&track_id=song-4').json()['tracks'] == [],
                  'A scene cannot use an unrelated public MP3 outside its effective authorization')
            check(owner.get(scoped_path + '&gallery_id=' + 'a' * 32).status == 400 and owner.get('/api/canvas-audio.php?scene_id[]=1').status == 400,
                  'Ambiguous and malformed runtime identities cannot override trusted context selection')
            replay = owner.post(SCENES, build, headers)
            check(replay.status == 200 and replay.json()['scene']['id'] == scene['id'] and len(calls()) == 1, 'Network retry returns the same saved canvas without another charge')
            clear = owner.post(SCENES, {'canvas_kind': 'custom', 'previous_id': scene['id'], 'brief': 'Rearrange {playlists-clear}', 'request_id': 'd2' * 16}, headers)
            assert clear.status == 201, clear.json()
            check(clear.json()['scene']['music_inputs'] == direct and clear.json()['scene']['playlist_manifest'] == {'version': 1, 'playlists': []},
                  'Clearing playlist resources through HTTP retains independently selected overlapping songs')
            direct_clear = owner.post(SCENES, {'canvas_kind': 'custom', 'previous_id': scene['id'], 'brief': 'Rearrange {music-clear}', 'request_id': 'd3' * 16}, headers)
            assert direct_clear.status == 201, direct_clear.json()
            check(direct_clear.json()['scene']['direct_music_inputs'] == {'mode': 'selected', 'track_ids': []} and direct_clear.json()['scene']['music_inputs']['track_ids'] == ['song-1', 'song-2'],
                  'Clearing direct music through HTTP preserves independent playlist songs')
            stale = owner.post(PROJECTS, request, headers).json()['proposal']
            owner.post(PROJECTS, {'action': 'approve', 'id': stale['id']}, headers)
            fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET name=? WHERE id=?')->execute(['Changed private membership version'," + str(seeded['id']) + "]); ")
            before, count = balance(), len(calls())
            stale_build = dict(stale['project'], project_managed=True, proposal_id=stale['id'], request_id='d4' * 16)
            check(owner.post(SCENES, stale_build, headers).status == 422 and balance() == before and len(calls()) == count,
                  'Membership version drift fails before provider work and leaves tokens untouched')
            check(owner.post(PROJECTS, {'action': 'approve', 'id': stale['id']}, headers).status == 422,
                  'A stale membership snapshot cannot receive another approval')
            rejected = owner.get(scoped_path)
            check(rejected.status == 404 and 'PRIVATE_OWNER_SEQUENCE' not in rejected.body().decode() and owner.get(scoped_source).status == 404 and owner.request('HEAD', scoped_source).status == 404 and owner.request('GET', scoped_source, headers={'Range': 'bytes=0-31'}).status == 404,
                  'A changed private playlist blocks every new metadata and source delivery without cached private details')
            replay = owner.post(SCENES, build, headers)
            check(replay.status == 200 and replay.json()['scene']['playlist_manifest'] == manifest and len(calls()) == count,
                  'Completed request recovery retains its exact frozen membership after a later source edit')
            check('no-store' in saved.headers.get('cache-control', ''), 'Private playlist-bearing history is never cached')
            runtime_sources = json.loads(fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-gallery-core.php';
                $own=sf_playlist_create(1,'Transferable private list','','private','user',['song-1']);
                $inventory=sf_canvas_playlists_inventory(1);$item=null;foreach($inventory as $entry)if($entry['ref']==='own-'.$own['id'])$item=$entry;
                $inputs=['items'=>[['ref'=>$item['ref'],'version'=>$item['revision_hash']]]];$compiled=sf_canvas_playlists_compile(1,$inputs);
                $ownScene=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Private runtime','html'=>'<section></section>','css'=>'',
                    'playlist_inputs'=>$inputs,'playlist_manifest'=>$compiled['manifest'],'direct_music_inputs'=>null,'music_inputs'=>sf_canvas_playlists_effective_music(null,$compiled['manifest'])]);
                $emptyScene=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Empty music scope','html'=>'<section></section>','css'=>'',
                    'playlist_inputs'=>['items'=>[]],'playlist_manifest'=>['version'=>1,'playlists'=>[]],'direct_music_inputs'=>null,'music_inputs'=>['mode'=>'selected','track_ids'=>[]]]);
                sf_db()->exec("INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) SELECT 9,'admin@example.invalid','Admin',password_hash,'admin','active',created_at,updated_at FROM users WHERE id=1");
                $artist=sf_playlist_create(2,'Public artist order','','public','user',['song-2','song-1']);$admin=['id'=>9,'role'=>'admin'];
                foreach(sf_canvas_playlists_admin_inventory($admin) as $entry)if($entry['ref']==='artist-'.$artist['id'])$review=$entry;
                sf_canvas_playlists_moderate($admin,$artist['id'],'publish',$review['revision_hash']);
                foreach(sf_canvas_playlists_inventory(0) as $entry)if($entry['ref']==='artist-'.$artist['id'])$item=$entry;
                $artistInputs=['items'=>[['ref'=>$item['ref'],'version'=>$item['revision_hash']]]];$compiled=sf_canvas_playlists_compile(0,$artistInputs);
                $publicScene=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Artist runtime','html'=>'<section></section>','css'=>'',
                    'playlist_inputs'=>$artistInputs,'playlist_manifest'=>$compiled['manifest'],'direct_music_inputs'=>null,'music_inputs'=>sf_canvas_playlists_effective_music(null,$compiled['manifest'])]);
                $pending=sf_canvas_gallery_submit(['id'=>1,'role'=>'user'],['scene_id'=>$publicScene['id'],'request_id'=>str_repeat('b1',16),'title'=>'Public runtime','description'=>'','creator_label'=>'Fixture maker','publication_consent'=>true]);
                sf_canvas_gallery_moderate($admin,$pending['id'],'approve',$pending['snapshot_hash']);
                $unapproved=sf_canvas_gallery_submit(['id'=>1,'role'=>'user'],['scene_id'=>$publicScene['id'],'request_id'=>str_repeat('b2',16),'title'=>'Unapproved runtime','description'=>'','creator_label'=>'Fixture maker','publication_consent'=>true]);
                echo json_encode(['own'=>$own['id'],'own_scene'=>$ownScene['id'],'empty_scene'=>$emptyScene['id'],'artist'=>$artist['id'],'artist_version'=>$item['revision_hash'],'artist_scene'=>$publicScene['id'],'gallery'=>$pending['id'],'pending'=>$unapproved['id']]);
            """))
            empty_path = '/api/canvas-audio.php?action=catalog&scene_id=' + str(runtime_sources['empty_scene'])
            empty = owner.get(empty_path)
            check(empty.status == 200 and empty.json()['tracks'] == [] and empty.json()['playlists'] == [] and owner.get('/api/canvas-audio.php?action=source&track_id=song-1&scene_id=' + str(runtime_sources['empty_scene'])).status == 404,
                  'An explicitly cleared runtime scope supplies no MP3s and cannot fall back to the full catalog')
            transfer_path = '/api/canvas-audio.php?action=catalog&scene_id=' + str(runtime_sources['own_scene'])
            transfer_source = '/api/canvas-audio.php?action=source&track_id=song-1&scene_id=' + str(runtime_sources['own_scene'])
            check(owner.get(transfer_path).status == 200, 'A fresh playlist-backed runtime scene starts with current owner access')
            fixture.cli(site, "sf_db()->prepare('UPDATE user_playlists SET user_id=2 WHERE id=?')->execute([" + str(runtime_sources['own']) + "]); ")
            check(owner.get(transfer_path).status == 404 and other.get(transfer_path).status == 404 and owner.get(transfer_source).status == 404 and other.get(transfer_source).status == 404,
                  'Playlist ownership transfer revokes old scene delivery without transferring the creator\'s scene to the new owner')
            gallery_context = '&gallery_id=' + runtime_sources['gallery']
            gallery_catalog = '/api/canvas-audio.php?action=catalog' + gallery_context
            gallery_source = '/api/canvas-audio.php?action=source&track_id=song-1' + gallery_context
            public_catalog = guest.get(gallery_catalog)
            check(public_catalog.status == 200 and public_catalog.json()['playlists'][0]['source'] == 'artist' and 'own-' not in public_catalog.body().decode() and guest.get(gallery_source).status == 200,
                  'Guest runtime access resolves an approved artist-only gallery snapshot without creator-private origins')
            check(guest.get('/api/canvas-audio.php?action=catalog&gallery_id=' + runtime_sources['pending']).status == 404 and owner.get('/api/canvas-audio.php?action=source&track_id=song-1&gallery_id=' + runtime_sources['pending']).status == 404,
                  'Pending gallery snapshots grant no runtime metadata or MP3 access even to the creator')
            fixture.cli(site, "require_once SF_ROOT.'/api/canvas-playlists-core.php';sf_canvas_playlists_moderate(['id'=>9]," + str(runtime_sources['artist']) + ",'withdraw','" + runtime_sources['artist_version'] + "');")
            check(guest.get(gallery_catalog).status == 404 and guest.get(gallery_source).status == 404 and guest.request('HEAD', gallery_source).status == 404 and guest.request('GET', gallery_source, headers={'Range': 'bytes=0-31'}).status == 404,
                  'Artist withdrawal closes every new gallery catalog GET HEAD and ranged source request')
            check(owner.get('/api/canvas-audio.php?action=catalog&scene_id=' + str(runtime_sources['artist_scene'])).status == 404,
                  'Private saved scenes also revalidate artist publication instead of replaying cached unions')
            fixture.cli(site, "require_once SF_ROOT.'/api/canvas-playlists-core.php';foreach(sf_canvas_playlists_admin_inventory(['id'=>9]) as $entry)if($entry['ref']==='artist-" + str(runtime_sources['artist']) + "')sf_canvas_playlists_moderate(['id'=>9]," + str(runtime_sources['artist']) + ",'publish',$entry['revision_hash']);")
            check(guest.get(gallery_catalog).status == 404 and guest.get(gallery_source).status == 404,
                  'Republishing identical songs with a new publication epoch cannot resurrect old runtime references')
            fixture.cli(site, "sf_privacy_clear_agent_history(1);")
            check(owner.get('/api/canvas-audio.php?action=catalog&scene_id=' + str(scene['id'])).status == 404 and owner.get(scoped_source).status == 404,
                  'Privacy history clearing invalidates old runtime scene and source identities')
            check(guest.get('/api/canvas-audio.php?action=source&track_id=song-1').status == 200 and len(guest.get('/api/canvas-audio.php?action=catalog').json()['tracks']) > 12,
                  'Ordinary unscoped public MP3 and full-catalog contracts remain compatible')
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=10)

print('Playlist projects actual HTTP:', len(checks), 'checks passed', flush=True)
