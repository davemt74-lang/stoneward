"""Exercise public story and lyric pickers, editorial routes and governed media through PHP HTTP."""
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
spec = importlib.util.spec_from_file_location('story_http_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv

ADMIN = '/admin/api/stories.php'
PUBLIC = '/api/canvas-resources.php?kind=stories'
LYRICS = '/api/canvas-resources.php?kind=lyrics'
SONGBOOK = '/admin/api/songbook.php'
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


def story_media(story_id):
    return '/api/media-links.php?entity_type=story_entry&entity_id=' + str(story_id)


def media(asset_id):
    return '/api/media.php?id=' + asset_id


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-stories-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    shutil.copytree(ROOT / 'admin', site / 'admin', dirs_exist_ok=True)
    catalog_path = site / 'data/catalog.json'
    tracks = json.loads(catalog_path.read_text())
    lyric_fixture = {'song-1': 'PUBLIC_LYRIC_MARKER', 'song-2': 'DRAFT_LYRIC_MARKER',
                     'hidden-album-song': 'HIDDEN_ALBUM_LYRIC_MARKER'}
    for track in tracks:
        if track['id'] in lyric_fixture:
            track['lyrics'] = lyric_fixture[track['id']]
    catalog_path.write_text(json.dumps(tracks))
    seeded = json.loads(fixture.cli(site, """
        sf_db()->exec("INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) SELECT 9,'admin@example.invalid','Admin',password_hash,'admin','active',created_at,updated_at FROM users WHERE id=1");
        foreach(['song-1'=>'published','song-2'=>'draft','hidden-album-song'=>'draft'] as $trackId=>$status){
            sf_songbook_save(['track_id'=>$trackId,'status'=>$status,'sections'=>[['label'=>'Verse','start_line'=>1,'end_line'=>1]],
                'annotations'=>[['line'=>1,'note'=>'ANNOTATION_MARKER']],'notes'=>'ADMIN_LYRIC_NOTE_MARKER'],9);
        }
        sf_db()->exec("UPDATE songbooks SET status='published' WHERE track_id='hidden-album-song'");
        $published=sf_story_save(['entry_type'=>'track_story','entity_id'=>'song-1','title'=>'Public song story','summary'=>'Public catalog preview','body_text'=>'The song story narrative','status'=>'published','tags'=>['Behind the Song']],9);
        $draft=sf_story_save(['entry_type'=>'essay','title'=>'PRIVATE_DRAFT_MARKER','body_text'=>'Unpublished narrative','status'=>'draft','tags'=>['Private Marker']],9);
        $unready=sf_story_save(['entry_type'=>'track_story','entity_id'=>'hidden-album-song','title'=>'PRIVATE_RELATION_MARKER','body_text'=>'Track in a draft release','status'=>'draft'],9);
        sf_db()->prepare("UPDATE story_entries SET status='published' WHERE id=?")->execute([$unready['id']]);
        $assets=[];
        foreach(['only'=>'Story-only downloadable bytes','shared'=>'Independently public site bytes','draft'=>'Private draft attachment',
                 'unready'=>'Unavailable related story attachment','private'=>'Unpublished story attachment'] as $name=>$bytes){
            $path='storage/media/'.$name.'.txt';file_put_contents(SF_ROOT.'/'.$path,$bytes);
            $asset=sf_media_register_existing_file($path,$name.'.txt',9);
            $id=match($name){'draft'=>$draft['id'],'unready'=>$unready['id'],default=>$published['id']};
            sf_media_attach((int)$asset['id'],'story_entry',(string)$id,'inline',['public_visible'=>$name!=='private','download_allowed'=>$name!=='private']);
            if($name==='shared')sf_media_attach((int)$asset['id'],'site','public-story-companion','document',['public_visible'=>true,'download_allowed'=>false]);
            $assets[$name]=$asset['uuid'];
        }
        echo json_encode(['published'=>$published['id'],'draft'=>$draft['id'],'unready'=>$unready['id'],'assets'=>$assets],JSON_THROW_ON_ERROR);
    """))
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
                    if server.poll() is not None:
                        raise RuntimeError('PHP fixture stopped before accepting HTTP')
                    time.sleep(.02)
            else:
                raise RuntimeError('PHP fixture did not start')
            guest = fixture.HTTPClient(base)
            fan, fan_headers = sign_in(base, 'owner@example.invalid')
            admin, admin_headers = sign_in(base, 'admin@example.invalid')
            lyrics = guest.get(LYRICS)
            lyric_inventory = lyrics.json()
            check(lyrics.status == 200 and lyric_inventory['kind'] == 'lyrics' and
                  [row['id'] for row in lyric_inventory['items']] == ['song-1'] and lyric_inventory['items'][0]['timed'] is False,
                  'Guest lyric picker exposes only the canonical song with a published and available Songbook')
            check(lyric_inventory == fan.get(LYRICS).json() == admin.get(LYRICS).json() and
                  'no-store' in lyrics.headers.get('cache-control', '') and b'_MARKER' not in lyrics.body(),
                  'All viewers receive the same non-cacheable lyric metadata without text, draft data or editor notes')
            draft_lyrics = {'action': 'save', 'confirmed': True, 'songbook': {'track_id': 'song-1', 'status': 'draft',
                           'sections': [{'label': 'Verse', 'start_line': 1, 'end_line': 1}]}}
            check(fan.post(SONGBOOK, draft_lyrics, fan_headers).status == 403 and
                  admin.post(SONGBOOK, draft_lyrics, admin_headers).status == 200 and guest.get(LYRICS).json()['items'] == [],
                  'Withdrawing Songbook publication through the Admin API updates the public picker immediately')

            public = guest.get(PUBLIC)
            inventory = public.json()['items']
            check(public.status == 200 and [row['id'] for row in inventory] == [str(seeded['published'])] and inventory[0]['tags'] == ['behind-the-song'],
                  'Public story picker reads every ready published fixture story with canonical tags')
            check(inventory == fan.get(PUBLIC).json()['items'] == admin.get(PUBLIC).json()['items'] and 'no-store' in public.headers.get('cache-control', ''),
                  'Every viewer receives the same current published picker without privilege escalation or stale caching')
            check('PRIVATE_' not in public.body().decode() and 'created_by' not in public.body().decode() and 'storage/media' not in public.body().decode(),
                  'Public inventory omits draft markers, hidden relation titles and private columns')
            check(guest.get('/api/canvas-resources.php?kind=canvas').status == 401, 'Public story discovery preserves private canvas authentication')
            check(guest.get(ADMIN).status == 401 and fan.get(ADMIN).status == 403, 'Editing and publication require a real administrator')

            visible_links = guest.get(story_media(seeded['published']))
            check(visible_links.status == 200 and sorted(item['uuid'] for item in visible_links.json()['media']) == sorted(seeded['assets'][key] for key in ('only', 'shared')),
                  'Only the ready story’s public attachments appear in its metadata route')
            check(guest.get(story_media(seeded['draft'])).status == 404 and guest.get(story_media(seeded['unready'])).status == 404,
                  'Guessing a draft or an unready related story ID does not disclose attachment metadata')
            check(all(guest.get(media(seeded['assets'][key])).status == 404 for key in ('draft', 'unready', 'private')),
                  'Draft, unready and private attachment bytes are denied even when their UUIDs are known')
            check(admin.get(media(seeded['assets']['draft'])).status == 200 and 'no-store' in admin.get(media(seeded['assets']['draft'])).headers.get('cache-control', ''),
                  'Authenticated admin retains private preview without public caching')
            only_path = media(seeded['assets']['only'])
            shared_path = media(seeded['assets']['shared'])
            full = guest.get(only_path)
            part = guest.get(only_path, {'Range': 'bytes=0-4'})
            head = guest.head(only_path)
            check(full.status == 200 and full.body() == b'Story-only downloadable bytes' and 'no-store' in full.headers.get('cache-control', ''),
                  'A published story independently grants its full media with mandatory fresh authorization')
            check(part.status == 206 and part.body() == b'Story' and part.headers['content-range'] == f'bytes 0-4/{len(full.body())}' and 'no-store' in part.headers.get('cache-control', ''),
                  'Authorized story-only byte ranges use exact offsets and disable caching')
            check(head.status == 200 and head.body() == b'' and int(head.headers['content-length']) == len(full.body()) and 'no-store' in head.headers.get('cache-control', ''),
                  'Authorized story-only HEAD preserves length and freshness rules')
            check('public' in guest.get(shared_path).headers.get('cache-control', '') and 'no-store' in guest.get(shared_path + '&download=1').headers.get('cache-control', ''),
                  'A public viewing grant does not cache downloads authorized only by a revocable story')

            editable = next(row for row in admin.get(ADMIN).json()['stories'] if row['id'] == seeded['published'])
            change = {'action': 'save', 'story': dict(editable, tags=['Live Sessions'])}
            check(fan.post(ADMIN, change, fan_headers).status == 403 and admin.post(ADMIN, change).status == 403,
                  'Only a valid administrator session and CSRF token can edit tags')
            saved = admin.post(ADMIN, change, admin_headers)
            check(saved.status == 200 and saved.json()['story']['tags'] == ['live-sessions'] and guest.get(PUBLIC).json()['items'][0]['tags'] == ['live-sessions'],
                  'Existing Admin save route immediately publishes canonical tag edits on a published story')
            archive = {'action': 'save', 'story': dict(saved.json()['story'], status='archived')}
            check(admin.post(ADMIN, archive, admin_headers).status == 422, 'Archive still requires the existing explicit editor confirmation')
            archived = admin.post(ADMIN, dict(archive, confirmed=True), admin_headers)
            check(archived.status == 200 and guest.get(PUBLIC).json()['items'] == [] and guest.get(story_media(seeded['published'])).status == 404,
                  'Archiving through the editor withdraws picker and media metadata immediately')
            denied = [guest.get(only_path), guest.head(only_path), guest.get(only_path, {'Range': 'bytes=0-4'}), guest.get(only_path + '&download=1')]
            check(all(response.status == 404 and 'no-store' in response.headers.get('cache-control', '') and b'Story-only downloadable bytes' not in response.body() for response in denied),
                  'Archive revokes story-only GET, HEAD, Range and download without returning stale bytes')
            check(guest.get(shared_path).status == 200 and guest.get(shared_path + '&download=1').status == 403,
                  'An independent public attachment preserves its viewing grant but cannot borrow revoked story download rights')
            check(admin.get(only_path).status == 200 and 'no-store' in admin.get(only_path).headers.get('cache-control', ''),
                  'Admin can still preview archived story media privately')
        except BaseException:
            log.seek(0)
            print(log.read(), file=sys.stderr)
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)

print(f'Canvas public story and lyric HTTP: {len(checks)} checks passed')
