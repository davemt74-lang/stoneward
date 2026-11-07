"""Exercise multi-source data reads and authored rules in the production canvas sandbox."""
import base64
import io
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
    for name in ('canvas-audio-sdk.js', 'public-agent-canvas.js', 'vendor/acorn.js', 'canvas-preview-guard.js', 'canvas-scene-tester.js'):
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


with sync_playwright() as playwright:
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    browser = playwright.chromium.launch(headless=True, executable_path=executable, args=['--no-sandbox'])
    for width in (390, 1280):
        context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
        page = context.new_page()
        page.set_default_timeout(8000)
        run(page, width)
        context.close()
    browser.close()
