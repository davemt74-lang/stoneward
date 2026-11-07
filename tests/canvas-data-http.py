"""Real /data HTTP routing, saved binding authorization and policy, with no network weather."""
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
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('canvas_data_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(spec)
original_argv = sys.argv
try:
    sys.argv = [*sys.argv, '--http-only']
    spec.loader.exec_module(fixture)
finally:
    sys.argv = original_argv
PHP = os.environ.get('SF_TEST_PHP', 'php')
ENDPOINT = '/api/canvas-data.php'
PERMISSION = 'canvas.data.weather_nws'
PRODUCTS = {'weather.current', 'weather.hourly', 'weather.daily', 'weather.alerts'}


def query(action, **params):
    return ENDPOINT + '?' + urlencode({'action': action, **params})


def sign_in(base, email):
    client = fixture.HTTPClient(base)
    csrf = client.get('/api/auth.php').json()['csrf']
    result = client.post('/api/auth.php', {'action': 'login', 'email': email, 'password': 'fixture-only-password'},
                         {'X-CSRF-Token': csrf})
    assert result.status == 200, result.json()
    return client


with tempfile.TemporaryDirectory(prefix='stoneward-canvas-data-http-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    (site / 'api/canvas-weather-core.php').write_text("""<?php
declare(strict_types=1);
function sf_canvas_weather_read(float $lat,float $lon,string $product):array {
    file_put_contents(SF_ROOT.'/weather-calls.log',json_encode([$lat,$lon,$product],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    return ['status'=>'fresh','fetched_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+600),
        'source'=>'National Weather Service','timezone'=>'America/Phoenix',
        'rows'=>[['temperature_f'=>$lat>33?94:91,'condition'=>'LIVE_WEATHER_FIXTURE','observed_at'=>gmdate('c')]]];
}
""")
    shows = [
        {'id': 'fixture-weather-show', 'title': 'Weather at the stage', 'venue': 'Fixture Stage',
         'city': 'Tucson', 'region': 'AZ', 'country': 'US', 'date': '2099-01-01', 'status': 'scheduled',
         'public_visible': True, 'archive_notes': 'PRIVATE_SHOW_NOTES'},
        {'id': 'fixture-private-show', 'title': 'PRIVATE_SHOW_CANARY', 'venue': 'PRIVATE_VENUE_CANARY',
         'city': 'Phoenix', 'region': 'AZ', 'country': 'US', 'date': '2099-01-01', 'status': 'hidden',
         'public_visible': False},
    ]
    (site / 'data/shows.json').write_text(json.dumps(shows))
    seeded = json.loads(fixture.cli(site, """
        require_once SF_ROOT.'/api/agent-scene-core.php';require_once SF_ROOT.'/api/canvas-gallery-core.php';
        sf_db()->exec("UPDATE users SET role='admin' WHERE id=2");
        $scene=sf_agent_scene_history_save(1,[
            'canvas_kind'=>'custom','track_id'=>'','title'=>'Two locations','html'=>'<p>Fixture weather</p>','css'=>'',
            'artist_inputs'=>['data_sources'=>[
                ['alias'=>'phoenix','product'=>'weather.current','location_id'=>'city-0455000'],
                ['alias'=>'venue','product'=>'weather.hourly','location_id'=>'show-fixture-weather-show'],
            ]],
        ]);
        $owner=['id'=>1,'role'=>'user'];$admin=['id'=>2,'role'=>'admin'];
        $submission=['scene_id'=>$scene['id'],'title'=>'Fixture show weather','description'=>'Two selected public sources',
            'creator_label'=>'Fixture','publication_consent'=>true];
        $public=sf_canvas_gallery_submit($owner,$submission+['request_id'=>str_repeat('aa',16)]);
        $pending=sf_canvas_gallery_submit($owner,$submission+['request_id'=>str_repeat('bb',16)]);
        sf_canvas_gallery_moderate($admin,$public['id'],'approve',$public['snapshot_hash']);
        echo json_encode(['scene'=>$scene['id'],'gallery'=>$public['id'],'pending'=>$pending['id']]);
    """))

    def calls():
        log = site / 'weather-calls.log'
        return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []

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
            else:
                raise AssertionError('Fixture PHP HTTP server did not start')
            guest = fixture.HTTPClient(base)
            owner = sign_in(base, 'owner@example.invalid')
            other = sign_in(base, 'other@example.invalid')
            read = query('read', scene_id=seeded['scene'], alias='phoenix')
            public_read = query('read', gallery_id=seeded['gallery'], alias='venue')

            inventory = guest.get(query('inventory'))
            products = {row['id']: row for row in inventory.json()['products']}
            fixture.check(inventory.status == 200 and set(products) == PRODUCTS and
                          'temperature_f' in products['weather.current']['fields'] and
                          'no-store' in inventory.headers.get('cache-control', ''),
                          'Guests can discover the four weather product schemas without cacheable account data')
            city = guest.get(query('places', q='Phoenix, AZ')).json()['items']
            venue = guest.get(query('places', id='show-fixture-weather-show')).json()['items']
            fixture.check(any(row['id'] == 'city-0455000' for row in city) and
                          len(venue) == 1 and venue[0]['id'] == 'show-fixture-weather-show' and
                          all(not {'lat', 'lon', 'archive_notes'}.intersection(row) for row in city + venue),
                          'City search and exact public venue lookup only expose picker metadata')
            listed = guest.get(query('places', q='')).json()['items']
            fixture.check('show-fixture-private-show' not in {row['id'] for row in listed} and
                          guest.get(query('places', id='show-fixture-private-show')).json()['items'] == [],
                          'A hidden show never appears in search or by direct location ID')
            fixture.check(calls() == [], 'Inventory and location discovery make no provider requests')
            fixture.check(owner.post(query('inventory'), {}).status == 405,
                          'The read endpoint rejects write methods')
            fixture.check(guest.get(read).status == 404 and other.get(read).status == 404,
                          'Guests and other accounts cannot read an owner’s private weather alias')
            fixture.check(owner.get(query('read', scene_id=seeded['scene'], alias='unknown')).status == 404 and
                          guest.get(query('read', gallery_id=seeded['pending'], alias='phoenix')).status == 404,
                          'A forged alias or pending gallery does not resolve an approved binding')
            forged = owner.get(query('read', scene_id=seeded['scene'], alias='phoenix', lat='1', lon='1', product='weather.daily'))
            ambiguous = owner.get(query('read', scene_id=seeded['scene'], gallery_id=seeded['gallery'], alias='phoenix'))
            fixture.check(forged.status == 422 and ambiguous.status == 422 and calls() == [],
                          'Caller-supplied coordinates, product overrides and ambiguous context are rejected before upstream')

            value = owner.get(read)
            data = value.json()['data']
            fixture.check(value.status == 200 and data['status'] == 'fresh' and data['rows'][0]['temperature_f'] == 94 and
                          'no-store' in value.headers.get('cache-control', '') and
                          len(calls()) == 1 and abs(calls()[0][0]-33.5) < .4 and calls()[0][2] == 'current',
                          'Owner data reads use the approved city and product, with separate freshness metadata')
            value = guest.get(public_read)
            fixture.check(value.status == 200 and value.json()['data']['rows'][0]['temperature_f'] == 91 and
                          len(calls()) == 2 and abs(calls()[1][0]-32.2) < .4 and calls()[1][2] == 'hourly',
                          'An approved gallery guest resolves only its second, independently named venue binding')
            page = guest.get('/api/canvas-gallery.php?action=open&id=' + seeded['gallery']).json()
            fixture.check(PERMISSION in {entry['id'] for entry in page['item']['uses']} and
                          'LIVE_WEATHER_FIXTURE' not in json.dumps(page) and 'PRIVATE_SHOW_NOTES' not in json.dumps(page),
                          'Gallery metadata discloses weather while keeping fetched rows out of the published snapshot')

            fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-capabilities.php';
                sf_canvas_permission_save(['id'=>2,'role'=>'admin'],sf_canvas_permission_policy()['revision'],['canvas.data.weather_nws'=>false]);
            """)
            disabled = owner.get(read).json()['data']
            fixture.check(disabled['status'] == 'unavailable' and disabled['rows'] == [] and
                          guest.get(query('inventory')).json()['products'] == [] and len(calls()) == 2,
                          'The admin switch removes public discovery and withholds runtime weather before provider access')
            fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-capabilities.php';
                sf_canvas_permission_save(['id'=>2,'role'=>'admin'],sf_canvas_permission_policy()['revision'],['canvas.data.weather_nws'=>true]);
            """)
            shows[0]['public_visible'] = False
            (site / 'data/shows.json').write_text(json.dumps(shows))
            unavailable = guest.get(public_read).json()['data']
            fixture.check(unavailable['status'] == 'unavailable' and unavailable['rows'] == [] and len(calls()) == 2,
                          'A venue that becomes private never yields saved weather data')
            fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-gallery-core.php';
                $id=sf_db()->query("SELECT id FROM canvas_gallery_entries WHERE status='approved'")->fetchColumn();
                sf_canvas_gallery_withdraw(['id'=>1,'role'=>'user'],(string)$id);
            """)
            fixture.check(guest.get(public_read).status == 404 and len(calls()) == 2,
                          'Withdrawal revokes the public weather route without contacting the provider')
            print(f'{fixture.checks} real HTTP canvas data checks passed', flush=True)
        except Exception:
            log.seek(0)
            print(log.read(), file=sys.stderr)
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)
