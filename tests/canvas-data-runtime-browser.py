"""Exercise multi-source data reads and authored rules in the production canvas sandbox."""
import base64
import io
import json
import os
import shutil
import wave
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/data/'
SCENE_SELECTOR = '.sf-agent-scene-backdrop iframe'
APP = (ROOT / 'assets/js/app.js').read_text()


def app_function(start, end):
    return APP[APP.index(start):APP.index(end, APP.index(start))]


def media_fixture():
    stream = io.BytesIO()
    with wave.open(stream, 'wb') as media:
        media.setparams((1, 2, 8000, 0, 'NONE', 'not compressed'))
        media.writeframes(bytes(8000 * 2 * 8))
    return 'data:audio/wav;base64,' + base64.b64encode(stream.getvalue()).decode()


def weather(temperature, condition, state):
    now = datetime.now(timezone.utc)
    expires = now + timedelta(seconds=-30 if state == 'expired' else 120)
    return {
        'status': 'stale' if state == 'stale' else 'fresh',
        'fetched_at': (now - timedelta(minutes=1)).isoformat(),
        'expires_at': expires.isoformat(),
        'source': 'National Weather Service',
        'timezone': 'America/Phoenix',
        'rows': [{'temperature_f': temperature, 'condition': condition}]
    }


# This program represents an authored canvas, never a production template.
PROGRAM = r'''(() => {
  const root = document.querySelector('.experiment');
  const add = (tag, className, text) => {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = text;
    root.append(element);
    return element;
  };
  const forecast = add('p', 'forecast', 'Forecast loading');
  const result = add('p', 'status', 'Conditions loading');
  const play = add('button', 'play', 'Play selected album');
  const update = add('button', 'refresh', 'Refresh data');
  let observation, track;
  StonefellowAudio.catalog({release_id:'heat-album'}).then(catalog => {
    track = catalog.releases.find(release => release.release_id === 'heat-album')?.track_ids[0];
    play.classList.add('album-ready');
  });
  async function refresh() {
    const [current, venue] = await Promise.all([StonefellowData.get('phoenix'), StonefellowData.get('venue')]);
    observation = current;
    forecast.textContent = venue.status === 'fresh' ? venue.rows[0].condition : 'Venue forecast unavailable';
    root.style.backgroundColor = forecast.textContent === 'Sunny' ? 'gold' : 'navy';
    result.textContent = current.status === 'fresh' ? 'Phoenix ' + current.rows[0].temperature_f : 'Phoenix ' + current.status;
  }
  play.onclick = async () => {
    const current = observation?.status === 'fresh' && Date.parse(observation.expires_at) > Date.now();
    if (!current || observation.rows[0]?.temperature_f !== 94 || !track) {
      result.textContent = 'Playback locked';
      return;
    }
    await StonefellowAudio.play(track);
    result.textContent = 'Playing selected album';
  };
  update.onclick = refresh;
  void refresh();
})();'''

# The fixture composes services in authored code and has no dependency on a map library.
GEOGRAPHY_PROGRAM = r'''(async () => {
  const root = document.querySelector('.experiment');
  const [origin, nearby] = await Promise.all([StonefellowData.get('origin'), StonefellowData.get('nearby')]);
  if (origin.status !== 'fresh' || nearby.status !== 'fresh') throw new Error('Expected public geography');
  const label = document.createElement('p');
  label.textContent = origin.rows[0].title;
  root.append(label);
  const rows = await Promise.all(nearby.rows.map(async show => {
    const conditions = await StonefellowData.get('weather-nearby', {location_id:show.location_id});
    const item = document.createElement('p');
    item.textContent = show.title + ' ' + show.distance_km + ' km · ' +
      (conditions.status === 'fresh' ? conditions.rows[0].condition : 'Weather unavailable');
    return item;
  }));
  root.append(...rows);
  const wrong = await StonefellowData.get('nearby', {location_id:'show-north'});
  const missing = await StonefellowData.get('weather-nearby');
  const withdrawn = await StonefellowData.get('weather-nearby', {location_id:'show-withdrawn'});
  const boundary = document.createElement('p');
  boundary.textContent = [wrong.status, missing.status, withdrawn.status].join(',');
  root.append(boundary);
})();'''

MAP_PROGRAM = r'''(async () => {
  const [map, origin] = await Promise.all([StonefellowData.get('boundary'), StonefellowData.get('map-origin')]);
  if (map.status !== 'fresh' || origin.status !== 'fresh') throw new Error('Map unavailable');
  const location = origin.rows[0];
  const chunks = map.rows.filter(row => row.region === location.region);
  const count = chunks.reduce((total, chunk) => total + JSON.parse(chunk.coordinates).length, 0);
  document.querySelector('.experiment').textContent = `${location.title} · ${chunks.length} chunks · ${count} points`;
})();'''

EARTHQUAKE_PROGRAM = r'''(() => {
  const root = document.querySelector('.experiment');
  const status = document.createElement('p');
  status.className = 'quake-status';
  const refresh = document.createElement('button');
  refresh.textContent = 'Refresh earthquake data';
  const svg = tag => document.createElementNS('http://www.w3.org/2000/svg', tag);
  const map = svg('svg');
  map.setAttribute('viewBox', '0 0 400 168');
  const boundaries = svg('g'), markers = svg('g');
  map.append(boundaries, markers);
  root.append(refresh, status, map);
  const project = (lon, lat) => [((lon + 117) * 24).toFixed(2), ((50 - lat) * 24).toFixed(2)];
  let renders = 0;
  async function render() {
    const [geometry, quakes] = await Promise.all([StonefellowData.get('boundary'), StonefellowData.get('quakes')]);
    const rings = new Map();
    for (const row of geometry.rows) {
      const key = `${row.region}/${row.part}/${row.ring}`;
      if (!rings.has(key)) rings.set(key, []);
      rings.get(key).push(row);
    }
    boundaries.replaceChildren(...[...rings.values()].map(rows => {
      const edge = svg('polyline');
      const coordinates = rows.sort((a, b) => a.chunk - b.chunk).flatMap(row => JSON.parse(row.coordinates));
      edge.setAttribute('points', coordinates.map(([lon, lat]) => project(lon, lat).join(',')).join(' '));
      edge.setAttribute('stroke', 'white');
      edge.setAttribute('fill', 'none');
      return edge;
    }));
    const records = quakes.status === 'unavailable' ? [] : quakes.rows;
    markers.replaceChildren(...records.map(quake => {
      const marker = svg('circle');
      const label = svg('title');
      const [x, y] = project(quake.lon, quake.lat);
      marker.setAttribute('cx', x);
      marker.setAttribute('cy', y);
      marker.setAttribute('r', quake.magnitude);
      marker.setAttribute('data-event', quake.event_id);
      label.textContent = `${quake.place} · M${quake.magnitude} · ${quake.depth_km} km · ${quake.review_status}`;
      marker.append(label);
      return marker;
    }));
    status.textContent = quakes.status === 'unavailable' ? 'Earthquake data unavailable'
      : `${records.length} earthquake readings${quakes.status === 'stale' ? ' · stale feed' : ''} · ${quakes.source}`;
    status.dataset.renders = ++renders;
  }
  refresh.onclick = render;
  void render();
})();'''

PROXIMITY_PROGRAM = r'''(async () => {
  const root = document.querySelector('.experiment');
  const make = (tag, text) => {
    const element = document.createElement(tag);
    element.textContent = text;
    root.append(element);
    return element;
  };
  const check = make('button', 'Check access');
  const play = make('button', 'Play track');
  const outcome = make('p', 'Pending');
  play.disabled = true;
  function update(result) {
    outcome.textContent = `${result.status}:${Object.keys(result).join(',')}`;
    play.disabled = result.status !== 'inside';
  }
  check.onclick = async () => {
    play.disabled = true;
    outcome.textContent = 'Checking';
    update(await StonefellowData.checkProximity('venue-check'));
  };
  play.onclick = async () => {
    await StonefellowAudio.play('heat-song');
    outcome.textContent = 'Track playing';
  };
  const ordinary = await StonefellowData.get('venue-check');
  make('p', `Ordinary read: ${ordinary.status}`);
  update(await StonefellowData.checkProximity('venue-check'));
})();'''

MOUNT = r'''(sound) => {
  const audio = document.querySelector('audio');
  audio.src = sound;
  window.played = [];
  window.listeningRoom = null;
  window.st = {auth:{authenticated:true}};
  window.canvasSceneOwner = () => 'account-one';
  window.isGalleryScene = scene => typeof scene?.gallery_id === 'string';
  window.api = async (path, body, signal) => {
    const response = await fetch('api/' + path, {credentials:'same-origin',signal});
    if (!response.ok) throw new Error('Data unavailable');
    return response.json();
  };
  SFPublicAgentCanvas.mount(document.querySelector('#target'), {
    audio, getSceneOwner: canvasSceneOwner, canUseScenes: () => true,
    onCanvasDataRead: loadCanvasData,
    onCanvasAudioCatalog: async filters => ({
      tracks: [{track_id:'heat-song', title:'Heat Song', source_available:true}],
      releases: [{release_id:filters.release_id, title:'Selected Album', track_ids:['heat-song']}]
    }),
    onCanvasAudioPlay: async track => {
      await audio.play();
      played.push(track);
    }
  });
  window.makeScene = async (js, gallery = false, dataSources = null) => {
    const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(js));
    return {id:88, ...(gallery?{gallery_id:'5'.repeat(32)}:{}), canvas_kind:'custom', track_id:'', title:'Weather experiment',
      html:'<section class="experiment"></section>', css:'.experiment{padding:88px 16px;min-height:100vh}',
      js, js_hash:btoa(String.fromCharCode(...new Uint8Array(bytes))),
      artist_inputs:{data_sources:dataSources||[
        {alias:'phoenix',product:'weather.current',location_id:'city-0455000'},
        {alias:'venue',product:'weather.hourly',location_id:'show-1'}
      ]}};
  };
  window.display = async (js, gallery = false, dataSources = null) => {
    listeningRoom = await makeScene(js,gallery,dataSources);
    return SFPublicAgentCanvas.showScene(listeningRoom,{focus:false});
  };
}'''


def run(page, width):
    modes = {'phoenix': (94, 'Clear', 'fresh'), 'venue': (84, 'Sunny', 'fresh')}
    requests = []
    unknown_requests = []
    map_rows = json.loads((ROOT / 'config/canvas-maps/us-regions.json').read_text())['MT']
    point = 'point-33450000_-112070000_100'
    proximity_available = True
    earthquake_state = 'fresh'
    earthquake_rows = [
        {'event_id': 'test001', 'place': 'Near Helena, Montana', 'magnitude': 3.2, 'magnitude_type': 'ml',
         'lat': 46.59, 'lon': -112.04, 'depth_km': 8.1, 'occurred_at': '2026-11-07T06:20:00+00:00',
         'updated_at': '2026-11-07T06:25:00+00:00', 'review_status': 'reviewed',
         'source_url': 'https://earthquake.usgs.gov/earthquakes/eventpage/test001'},
        {'event_id': 'test002', 'place': 'Near Bozeman, Montana', 'magnitude': 2.6, 'magnitude_type': 'md',
         'lat': 45.68, 'lon': -111.04, 'depth_km': 10, 'occurred_at': '2026-11-07T04:10:00+00:00',
         'updated_at': '2026-11-07T04:12:00+00:00', 'review_status': 'automatic',
         'source_url': 'https://earthquake.usgs.gov/earthquakes/eventpage/test002'},
    ]

    def route_request(route):
        url = urlparse(route.request.url)
        if route.request.url == SITE:
            route.fulfill(content_type='text/html',
                          body='<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
                               '<body><main id="target"></main><audio></audio></body></html>')
        elif url.path == '/data/api/canvas-data.php':
            query = parse_qs(url.query)
            requests.append(query)
            alias = query.get('alias', [''])[0]
            if alias == 'quakes':
                assert 'location_id' not in query
                data = weather(None, '', earthquake_state)
                data.update(alias=alias, product='earthquakes.recent', source='U.S. Geological Survey', timezone=None,
                            location={'id': 'm25-day', 'title': 'Worldwide · M2.5+ · Past 24 hours', 'kind': 'scope'},
                            rows=earthquake_rows)
                if earthquake_state == 'unavailable':
                    data.update(status='unavailable', fetched_at=None, expires_at=None, rows=[])
                route.fulfill(json={'ok': True, 'data': data})
                return
            if alias in ('boundary', 'map-origin', 'venue-check'):
                assert 'location_id' not in query
                if alias == 'venue-check' and not proximity_available:
                    route.fulfill(status=404, json={'ok': False, 'error': 'unavailable'})
                    return
                data = weather(None, '', 'fresh')
                data.update(alias=alias, timezone=None)
                if alias == 'boundary':
                    data.update(product='map.boundaries', source='U.S. Census Bureau, 2025 Cartographic Boundary Files (1:20m)', rows=map_rows)
                elif alias == 'map-origin':
                    data.update(product='geography.place', rows=[{'title': 'Selected city', 'region': 'MT', 'lat': 46.59, 'lon': -112.04}])
                else:
                    data.update(product='location.proximity', source='Stonefellow public target',
                                location={'id': point, 'kind': 'point', 'title': 'Public point'},
                                rows=[{'lat': 33.45, 'lon': -112.07, 'radius_m': 100}])
                route.fulfill(json={'ok': True, 'data': data})
                return
            if alias in ('origin', 'nearby'):
                assert 'location_id' not in query
                data = weather(None, '', 'fresh')
                data['source'] = 'U.S. Census Bureau Gazetteer Files (2026)' if alias == 'origin' else 'Stonefellow public shows'
                data['timezone'] = None
                data['rows'] = (
                    [{'title': 'Selected city', 'location_id': 'city-0455000', 'lat': 33.45, 'lon': -112.07}]
                    if alias == 'origin' else [
                        {'show_id': 'north', 'title': 'North show', 'location_id': 'show-north', 'distance_km': 18,
                         'venue': 'North stage', 'date': '2030-06-15', 'lat': 33.61, 'lon': -112.07},
                        {'show_id': 'east', 'title': 'East show', 'location_id': 'show-east', 'distance_km': 24,
                         'venue': 'East stage', 'date': '2030-06-16', 'lat': 33.45, 'lon': -111.81},
                    ]
                )
                route.fulfill(json={'ok': True, 'data': data})
                return
            if alias == 'weather-nearby':
                location = query['location_id'][0]
                if location == 'show-withdrawn':
                    route.fulfill(status=404, json={'ok': False, 'error': 'unavailable'})
                else:
                    assert location in ('show-north', 'show-east')
                    if location == 'show-north':
                        route.fulfill(json={'ok': True, 'data': weather(65, 'Cloudy', 'fresh')})
                    else:
                        route.fulfill(status=503, json={'ok': False, 'error': 'unavailable'})
                return
            assert alias in modes, query
            temperature, condition, state = modes[alias]
            if state == 'unavailable':
                route.fulfill(status=403, json={'ok': False, 'error': 'unavailable'})
            else:
                route.fulfill(json={'ok': True, 'data': weather(temperature, condition, state)})
        else:
            unknown_requests.append(route.request.url)
            route.abort()

    page.route('**/*', route_request)
    page.goto(SITE)
    page.add_style_tag(content=(ROOT / 'assets/css/public-agent-canvas.css').read_text())
    for name in ('canvas-audio-sdk.js', 'canvas-location.js', 'public-agent-canvas.js', 'vendor/acorn.js', 'canvas-preview-guard.js', 'canvas-scene-tester.js'):
        page.add_script_tag(content=(ROOT / 'assets/js' / name).read_text())
    page.add_script_tag(content=app_function('  function requireCanvasAudioSession(', '  function canvasAudioSourcePath(')
                        + app_function('  async function loadCanvasData(', '  async function loadCanvasAudioCatalog('))
    page.evaluate(MOUNT, media_fixture())
    assert page.evaluate('code => display(code)', PROGRAM)
    frame = page.frame_locator(SCENE_SELECTOR)

    def wait_text(selector, expected):
        frame.locator(selector).get_by_text(expected, exact=True).wait_for()

    frame.locator('.play.album-ready').wait_for()
    wait_text('.status', 'Phoenix 94')
    wait_text('.forecast', 'Sunny')
    assert frame.locator('.experiment').evaluate('element => getComputedStyle(element).backgroundColor') == 'rgb(255, 215, 0)'
    assert {tuple(sorted((key, values[0]) for key, values in row.items())) for row in requests} == {
        (('action', 'read'), ('alias', 'phoenix'), ('scene_id', '88')),
        (('action', 'read'), ('alias', 'venue'), ('scene_id', '88')),
    }
    assert page.locator(SCENE_SELECTOR).get_attribute('sandbox') == 'allow-scripts'
    frame.locator('.play').click()
    wait_text('.status', 'Playing selected album')
    assert page.evaluate("played.join(',') === 'heat-song' && !document.querySelector('audio').paused")
    page.evaluate("document.querySelector('audio').pause()")
    print(f'PASS Chromium/{width} two scoped reads drive authored forecast visuals and native album playback at fresh 94°F', flush=True)

    for temperature, state, text in ((93, 'fresh', 'Phoenix 93'),
                                      (94, 'stale', 'Phoenix stale'),
                                      (94, 'expired', 'Phoenix stale'),
                                      (94, 'unavailable', 'Phoenix unavailable'),
                                      (None, 'fresh', 'Phoenix null')):
        modes['phoenix'] = (temperature, 'Clear', state)
        modes['venue'] = (65, 'Cloudy', 'fresh')
        frame.locator('.refresh').click()
        wait_text('.status', text)
        wait_text('.forecast', 'Cloudy')
        frame.locator('.play').click()
        wait_text('.status', 'Playback locked')
        assert page.evaluate("played.length === 1 && document.querySelector('audio').paused")
    assert frame.locator('.experiment').evaluate('element => getComputedStyle(element).backgroundColor') == 'rgb(0, 0, 128)'
    assert len(requests) == 12, requests
    modes['phoenix'] = (94, 'Clear', 'fresh')
    frame.locator('.refresh').click()
    wait_text('.status', 'Phoenix 94')
    frame.locator('.experiment').evaluate("() => {const now = Date.now(); Date.now = () => now + 600000;}")
    frame.locator('.play').click()
    wait_text('.status', 'Playback locked')
    assert page.evaluate('played.length') == 1
    print(f'PASS Chromium/{width} changed, stale, unavailable, and expired-at-click readings cannot unlock playback', flush=True)

    restricted = r'''(async()=>{
      const result=await StonefellowData.get('not-attached');
      let invalid=false, network=false;
      try{await StonefellowData.get('https://fixture.test/unknown');}catch(_){invalid=true;}
      try{await fetch('https://fixture.test/forbidden');}catch(_){network=true;}
      document.querySelector('.experiment').textContent=[result.status,invalid,network].join(',');
    })();'''
    count = len(requests)
    assert page.evaluate('code => display(code,true)', restricted)
    wait_text('.experiment', 'unavailable,true,true')
    assert len(requests) == count and not unknown_requests
    print(f'PASS Chromium/{width} unattached aliases and arbitrary connections cannot cross the opaque frame', flush=True)

    assert page.evaluate('code => display(code,true)', PROGRAM)
    wait_text('.forecast', 'Cloudy')
    assert len(requests) == count + 2 and all(row.get('gallery_id') == ['5' * 32] and 'scene_id' not in row for row in requests[-2:])
    preview_code = "StonefellowData.get('phoenix').then(result=>document.querySelector('.experiment').textContent=result.status);"
    page.evaluate('''async code => {
      const {frame}=await SFPublicAgentCanvas.prepareGalleryPreview(await makeScene(code));
      frame.id='detached-preview';
      document.body.append(frame);
    }''', preview_code)
    page.frame_locator('#detached-preview').get_by_text('unavailable', exact=True).wait_for()
    verification = page.evaluate('''async code => SFCanvasSceneTester.test(await makeScene(code))''', preview_code)
    assert verification['state'] == 'passed'
    assert 'live_data' in {entry['code'] for entry in verification['manual']}
    assert len(requests) == count + 2
    print(f'PASS Chromium/{width} gallery and detached testing use their intended data boundaries', flush=True)

    count = len(requests)
    assert page.evaluate('([code, sources]) => display(code,false,sources)', [GEOGRAPHY_PROGRAM, [
        {'alias': 'origin', 'product': 'geography.place', 'location_id': 'city-0455000'},
        {'alias': 'nearby', 'product': 'geography.shows', 'location_id': 'city-0455000'},
        {'alias': 'weather-nearby', 'product': 'weather.current', 'location_id': 'published-shows'},
    ]])
    frame.get_by_text('Selected city', exact=True).wait_for()
    frame.get_by_text('North show 18 km · Cloudy', exact=True).wait_for()
    frame.get_by_text('East show 24 km · Weather unavailable', exact=True).wait_for()
    frame.get_by_text('unavailable,unavailable,unavailable', exact=True).wait_for()
    assert {(row['alias'][0], row.get('location_id', [''])[0]) for row in requests[count:]} == {
        ('origin', ''), ('nearby', ''), ('weather-nearby', 'show-north'),
        ('weather-nearby', 'show-east'), ('weather-nearby', 'show-withdrawn')
    }
    assert len(requests[count:]) == 5 and all(row.get('scene_id') == ['88'] for row in requests[count:])
    assert not unknown_requests
    print(f'PASS Chromium/{width} public geography drives independent authorized venue reads and survives a venue weather outage', flush=True)
    print(f'PASS Chromium/{width} fixed sources cannot accept a new venue and scope reads require a published show', flush=True)

    count = len(requests)
    assert max(len(row['coordinates']) for row in map_rows) > 3000
    assert page.evaluate('([code, sources]) => display(code,false,sources)', [MAP_PROGRAM, [
        {'alias': 'boundary', 'product': 'map.boundaries', 'location_id': 'city-3035680'},
        {'alias': 'map-origin', 'product': 'geography.place', 'location_id': 'city-3035680'},
    ]])
    points = sum(len(json.loads(row['coordinates'])) for row in map_rows)
    frame.get_by_text(f'Selected city · {len(map_rows)} chunks · {points} points', exact=True).wait_for()
    assert {(row['alias'][0], row['scene_id'][0]) for row in requests[count:]} == {('boundary', '88'), ('map-origin', '88')}
    print(f'PASS Chromium/{width} bundled map geometry reaches authored code and composes with public geography', flush=True)

    count = len(requests)
    assert page.evaluate('([code, sources]) => display(code,false,sources)', [EARTHQUAKE_PROGRAM, [
        {'alias': 'boundary', 'product': 'map.boundaries', 'location_id': 'city-3035680'},
        {'alias': 'quakes', 'product': 'earthquakes.recent', 'location_id': 'm25-day'},
    ]])
    wait_text('.quake-status', '2 earthquake readings · U.S. Geological Survey')
    edges = frame.locator('svg polyline')
    assert edges.count() > 0 and len(edges.first.get_attribute('points').split()) > 100
    markers = frame.locator('svg circle')
    assert markers.count() == 2
    assert markers.first.get_attribute('cx') == '119.04' and markers.first.get_attribute('cy') == '81.84'
    assert markers.first.get_attribute('data-event') == 'test001'
    assert markers.first.locator('title').text_content() == 'Near Helena, Montana · M3.2 · 8.1 km · reviewed'
    refresh = frame.get_by_role('button', name='Refresh earthquake data')
    activate_refresh = refresh.tap if width == 390 else refresh.click
    for renders, earthquake_state in enumerate(('stale', 'expired'), start=2):
        activate_refresh()
        wait_text(f'.quake-status[data-renders="{renders}"]', '2 earthquake readings · stale feed · U.S. Geological Survey')
        assert markers.count() == 2
        assert len(requests) == count + 2 * renders
    earthquake_state = 'unavailable'
    activate_refresh()
    wait_text('.quake-status', 'Earthquake data unavailable')
    assert markers.count() == 0 and edges.count() > 0
    quake_requests = requests[count:]
    assert len(quake_requests) == 8 and all(sorted(row) == ['action', 'alias', 'scene_id'] for row in quake_requests)
    assert {(row['action'][0], row['alias'][0], row['scene_id'][0]) for row in quake_requests} == {
        ('read', 'boundary', '88'), ('read', 'quakes', '88'),
    }
    assert not unknown_requests
    print(f'PASS Chromium/{width} authored code plots earthquake readings over bundled boundaries and handles stale or unavailable feeds', flush=True)

    count = len(requests)
    sources = [{'alias': 'venue-check', 'product': 'location.proximity', 'location_id': point}]
    page.context.grant_permissions(['geolocation'], origin='https://fixture.test')
    page.context.set_geolocation({'latitude': 33.45, 'longitude': -112.07, 'accuracy': 5})
    assert page.evaluate('([code, sources]) => display(code,false,sources)', [PROXIMITY_PROGRAM, sources])
    frame.get_by_text('denied:status', exact=True).wait_for()
    frame.get_by_text('Ordinary read: unavailable', exact=True).wait_for()
    assert len(requests) == count
    assert page.locator(SCENE_SELECTOR).get_attribute('allow') == "geolocation 'none'"
    check = frame.get_by_role('button', name='Check access')
    play = frame.get_by_role('button', name='Play track')
    dialog = page.locator('dialog.sf-canvas-location')
    activate = lambda button: button.tap() if width == 390 else button.click()
    assert play.is_disabled()
    activate(check)
    dialog.get_by_role('heading', name='Check your location').wait_for()
    assert play.is_disabled() and len(requests) == count + 1
    activate(dialog.get_by_role('button', name='Cancel'))
    frame.get_by_text('denied:status', exact=True).wait_for()
    assert play.is_disabled() and len(requests) == count + 1

    activate(check)
    activate(dialog.get_by_role('button', name='Check once'))
    frame.get_by_text('inside:status', exact=True).wait_for()
    assert len(requests) == count + 3
    activate(play)
    frame.get_by_text('Track playing', exact=True).wait_for()
    assert page.evaluate('played.length') == 2
    page.evaluate("document.querySelector('audio').pause()")

    page.context.set_geolocation({'latitude': 34, 'longitude': -112.07, 'accuracy': 5})
    activate(check)
    activate(dialog.get_by_role('button', name='Check once'))
    frame.get_by_text('outside:status', exact=True).wait_for()
    assert play.is_disabled()

    page.context.set_geolocation({'latitude': 33.45, 'longitude': -112.07, 'accuracy': 5})
    activate(check)
    dialog.wait_for()
    proximity_available = False
    activate(dialog.get_by_role('button', name='Check once'))
    frame.get_by_text('unavailable:status', exact=True).wait_for()
    assert play.is_disabled() and page.evaluate('played.length') == 2
    assert len(requests) == count + 7 and all(row == {'action': ['read'], 'scene_id': ['88'], 'alias': ['venue-check']} for row in requests[count:])

    preview_code = "StonefellowData.checkProximity('venue-check').then(result=>document.querySelector('.experiment').textContent=JSON.stringify(result));"
    page.evaluate('''async ([code,sources]) => {
      const {frame}=await SFPublicAgentCanvas.prepareGalleryPreview(await makeScene(code,false,sources));
      frame.id='proximity-preview';
      document.body.append(frame);
    }''', [preview_code, sources])
    page.frame_locator('#proximity-preview').get_by_text('{"status":"unavailable"}', exact=True).wait_for()
    assert len(requests) == count + 7 and not unknown_requests
    print(f'PASS Chromium/{width} trusted canvas gestures and host consent deliver status-only proximity with withdrawal and detached preview isolation', flush=True)


with sync_playwright() as playwright:
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    browser = playwright.chromium.launch(headless=True, executable_path=executable, args=['--no-sandbox'])
    for width in (390, 1280):
        context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce', has_touch=width == 390)
        page = context.new_page()
        page.set_default_timeout(8000)
        run(page, width)
        context.close()
    browser.close()
