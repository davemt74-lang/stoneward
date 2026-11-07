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
GEO_PERMISSION = 'canvas.data.geography_public'
WEATHER_PRODUCTS = {'weather.current', 'weather.hourly', 'weather.daily', 'weather.alerts'}
GEO_PRODUCTS = {'geography.place', 'geography.shows'}
MAP_PRODUCT = 'map.boundaries'
PROXIMITY_PRODUCT = 'location.proximity'
SPATIAL_PRODUCTS = {MAP_PRODUCT, PROXIMITY_PRODUCT}
ALL_PRODUCTS = WEATHER_PRODUCTS | GEO_PRODUCTS | SPATIAL_PRODUCTS


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
        {'id': 'fixture-nearby-show', 'title': 'Closest stage', 'venue': 'Phoenix Stage',
         'city': 'Phoenix', 'region': 'AZ', 'country': 'US', 'date': '2099-01-02', 'status': 'scheduled',
         'public_visible': True, 'archive_notes': 'PRIVATE_SHOW_NOTES'},
        {'id': 'fixture-cancelled-show', 'title': 'Former show', 'venue': 'Former Stage',
         'city': 'Phoenix', 'region': 'AZ', 'country': 'US', 'date': '2099-01-01', 'status': 'canceled',
         'public_visible': True},
        {'id': 'fixture-past-show', 'title': 'Past show', 'venue': 'Past Stage',
         'city': 'Phoenix', 'region': 'AZ', 'country': 'US', 'date': '2000-01-01', 'status': 'scheduled',
         'public_visible': True},
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
        $composed=sf_agent_scene_history_save(1,[
            'canvas_kind'=>'custom','track_id'=>'','title'=>'Map with forecasts','html'=>'<p>Fixture geography</p>','css'=>'',
            'artist_inputs'=>['data_sources'=>[
                ['alias'=>'anchor','product'=>'geography.place','location_id'=>'city-0455000'],
                ['alias'=>'shows','product'=>'geography.shows','location_id'=>'city-0455000'],
                ['alias'=>'forecast','product'=>'weather.hourly','location_id'=>'published-shows'],
            ]],
        ]);
        $spatial=sf_agent_scene_history_save(1,[
            'canvas_kind'=>'custom','track_id'=>'','title'=>'Map and proximity','html'=>'<p>Fixture spatial data</p>','css'=>'',
            'artist_inputs'=>['data_sources'=>[
                ['alias'=>'outline','product'=>'map.boundaries','location_id'=>'city-0455000'],
                ['alias'=>'regions','product'=>'map.boundaries','location_id'=>'us-regions'],
                ['alias'=>'entrance','product'=>'location.proximity','location_id'=>'point-33448400_-112074000_500'],
            ]],
        ]);
        $owner=['id'=>1,'role'=>'user'];$admin=['id'=>2,'role'=>'admin'];
        $submission=['scene_id'=>$scene['id'],'title'=>'Fixture show weather','description'=>'Two selected public sources',
            'creator_label'=>'Fixture','publication_consent'=>true];
        $public=sf_canvas_gallery_submit($owner,$submission+['request_id'=>str_repeat('aa',16)]);
        $pending=sf_canvas_gallery_submit($owner,$submission+['request_id'=>str_repeat('bb',16)]);
        sf_canvas_gallery_moderate($admin,$public['id'],'approve',$public['snapshot_hash']);
        $spatialGallery=sf_canvas_gallery_submit($owner,[
            'scene_id'=>$spatial['id'],'title'=>'Fixture spatial canvas','description'=>'Selected geometry and public target',
            'creator_label'=>'Fixture','publication_consent'=>true,'request_id'=>str_repeat('cc',16),
        ]);
        sf_canvas_gallery_moderate($admin,$spatialGallery['id'],'approve',$spatialGallery['snapshot_hash']);
        echo json_encode(['scene'=>$scene['id'],'composed'=>$composed['id'],'spatial'=>$spatial['id'],
            'gallery'=>$public['id'],'pending'=>$pending['id'],'spatial_gallery'=>$spatialGallery['id']]);
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
            fixture.check(inventory.status == 200 and set(products) == ALL_PRODUCTS and
                          'temperature_f' in products['weather.current']['fields'] and
                          'distance_km' in products['geography.shows']['fields'] and
                          'coordinates' in products[MAP_PRODUCT]['fields'] and 'radius_m' in products[PROXIMITY_PRODUCT]['fields'] and
                          'no-store' in inventory.headers.get('cache-control', ''),
                          'Guests can discover separate weather, geography, map and proximity schemas without cacheable account data')
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
                          {item['id'] for item in guest.get(query('inventory')).json()['products']} == ALL_PRODUCTS - WEATHER_PRODUCTS and
                          len(calls()) == 2,
                          'The weather switch removes its discovery and runtime access while keeping geography enabled')
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
                $id=sf_db()->query("SELECT id FROM canvas_gallery_entries WHERE status='approved' AND title='Fixture show weather'")->fetchColumn();
                sf_canvas_gallery_withdraw(['id'=>1,'role'=>'user'],(string)$id);
            """)
            fixture.check(guest.get(public_read).status == 404 and len(calls()) == 2,
                          'Withdrawal revokes the public weather route without contacting the provider')

            shows[0]['public_visible'] = True
            (site / 'data/shows.json').write_text(json.dumps(shows))
            scene_id = seeded['composed']
            maps = query('read', scene_id=scene_id, alias='shows')
            place = owner.get(query('read', scene_id=scene_id, alias='anchor')).json()['data']
            venue_data = owner.get(maps).json()['data']
            row_ids = [row['location_id'] for row in venue_data['rows']]
            fixture.check(place['status'] == 'fresh' and place['rows'][0]['location_id'] == 'city-0455000' and
                          'precision' in place['rows'][0] and abs(place['rows'][0]['lat'] - 33.5) < .4 and
                          row_ids == ['show-fixture-nearby-show', 'show-fixture-weather-show'] and
                          'PRIVATE_SHOW_NOTES' not in json.dumps(venue_data) and len(calls()) == 2,
                          'Geography returns source-labeled coordinates and eligible nearby-first show IDs without upstream weather')
            fixture.check(guest.get(maps).status == 404 and other.get(maps).status == 404,
                          'Composition through a saved scene still requires scene ownership')
            weather_scope = query('read', scene_id=scene_id, alias='forecast')
            wrong_place = query('read', scene_id=scene_id, alias='forecast', location_id='city-0455000')
            override = query('read', scene_id=seeded['scene'], alias='phoenix', location_id=row_ids[0])
            bad_shape = weather_scope + '&location_id%5B%5D=' + row_ids[0]
            fixture.check(all(owner.get(path).status == 422 for path in [weather_scope, wrong_place, override, bad_shape]) and len(calls()) == 2,
                          'Show-scoped reads require a canonical show ID and exact bindings reject overrides')
            for hidden_id in ['show-fixture-private-show', 'show-fixture-cancelled-show', 'show-fixture-past-show', 'show-fixture-unknown']:
                unavailable = owner.get(query('read', scene_id=scene_id, alias='forecast', location_id=hidden_id)).json()['data']
                fixture.check(unavailable['status'] == 'unavailable' and unavailable['rows'] == [] and len(calls()) == 2,
                              'An ineligible show ID never reaches the weather provider')
            matched = owner.get(query('read', scene_id=scene_id, alias='forecast', location_id=row_ids[1]))
            matched_data = matched.json()['data']
            fixture.check(matched.status == 200 and matched_data['status'] == 'fresh' and
                          matched_data['location']['id'] == row_ids[1] and matched_data['rows'][0]['temperature_f'] == 91 and
                          len(calls()) == 3 and calls()[2][2] == 'hourly',
                          'A map result can supply the exact location for a separately approved weather source')
            fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-capabilities.php';
                sf_canvas_permission_save(['id'=>2,'role'=>'admin'],sf_canvas_permission_policy()['revision'],['canvas.data.geography_public'=>false]);
            """)
            disabled_map = owner.get(maps).json()['data']
            known_weather = owner.get(query('read', scene_id=scene_id, alias='forecast', location_id=row_ids[0])).json()['data']
            fixture.check(disabled_map['status'] == 'unavailable' and disabled_map['rows'] == [] and
                          {item['id'] for item in guest.get(query('inventory')).json()['products']} == ALL_PRODUCTS - GEO_PRODUCTS and
                          known_weather['status'] == 'fresh' and len(calls()) == 4,
                          'The geography policy is independent of authorized weather and its attached show scope')

            fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-capabilities.php';
                sf_canvas_permission_save(['id'=>2,'role'=>'admin'],sf_canvas_permission_policy()['revision'],['canvas.data.geography_public'=>true]);
            """)
            spatial_id = seeded['spatial']
            outline = query('read', scene_id=spatial_id, alias='outline')
            regions = query('read', scene_id=spatial_id, alias='regions')
            proximity = query('read', scene_id=spatial_id, alias='entrance')
            gallery_outline = query('read', gallery_id=seeded['spatial_gallery'], alias='outline')
            gallery_target = query('read', gallery_id=seeded['spatial_gallery'], alias='entrance')
            map_data = owner.get(outline).json()['data']
            national = owner.get(regions).json()['data']
            fixture.check(map_data['status'] == 'fresh' and {row['region'] for row in map_data['rows']} == {'AZ'} and
                          map_data['rows'][0]['coordinates'] and national['status'] == 'fresh' and
                          len({row['region'] for row in national['rows']}) >= 52 and len(calls()) == 4,
                          'Regional outlines and explicit national boundaries use local map data only')
            target = owner.get(proximity).json()['data']
            target_row = target['rows'][0]
            fixture.check(target['status'] == 'fresh' and len(target['rows']) == 1 and
                          abs(target_row['lat'] - 33.4484) < .000001 and abs(target_row['lon'] + 112.074) < .000001 and
                          target_row['radius_m'] == 500 and len(calls()) == 4,
                          'The host serves the saved public target without requesting a viewer position')
            fixture.check(guest.get(proximity).status == 404 and other.get(outline).status == 404,
                          'Public geometry and target data remain scoped to the saved private scene')
            fixture.check(guest.get(gallery_outline).json()['data']['rows'] == map_data['rows'] and
                          guest.get(gallery_target).json()['data']['rows'] == target['rows'] and len(calls()) == 4,
                          'Published gallery guests can read only the reviewed public geometry and target')
            override = query('read', scene_id=spatial_id, alias='outline', location_id='city-0477000')
            target_override = query('read', scene_id=spatial_id, alias='entrance', location_id='point-0_0_500')
            visitor_position = query('read', scene_id=spatial_id, alias='entrance', lat='0', lon='0', radius_m='500')
            fixture.check(all(owner.get(path).status == 422 for path in [override, target_override, visitor_position]) and
                          len(calls()) == 4,
                          'The read route rejects map and target overrides and never accepts visitor coordinates')
            fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-capabilities.php';
                sf_canvas_permission_save(['id'=>2,'role'=>'admin'],sf_canvas_permission_policy()['revision'],['canvas.data.map_public'=>false]);
            """)
            unavailable_map = owner.get(outline).json()['data']
            fixture.check(unavailable_map['status'] == 'unavailable' and unavailable_map['rows'] == [] and
                          {item['id'] for item in guest.get(query('inventory')).json()['products']} == ALL_PRODUCTS - {MAP_PRODUCT} and
                          owner.get(proximity).json()['data']['rows'] == target['rows'],
                          'The map switch disables only map outlines and keeps the attached proximity target usable')
            fixture.cli(site, """
                require_once SF_ROOT.'/api/canvas-capabilities.php';
                sf_canvas_permission_save(['id'=>2,'role'=>'admin'],sf_canvas_permission_policy()['revision'],[
                    'canvas.data.map_public'=>true,'canvas.data.location_proximity'=>false,
                ]);
            """)
            unavailable_target = guest.get(gallery_target).json()['data']
            fixture.check(unavailable_target['status'] == 'unavailable' and unavailable_target['rows'] == [] and
                          {item['id'] for item in guest.get(query('inventory')).json()['products']} == ALL_PRODUCTS - {PROXIMITY_PRODUCT} and
                          guest.get(gallery_outline).json()['data']['rows'] == map_data['rows'] and len(calls()) == 4,
                          'The proximity switch withholds its public target and leaves map delivery intact')
            print(f'{fixture.checks} real HTTP canvas data checks passed', flush=True)
        except Exception:
            log.seek(0)
            print(log.read(), file=sys.stderr)
            raise
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=5)
