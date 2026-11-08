"""Exercise pinned libraries in the production isolated Canvas renderer."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
from datetime import datetime, timedelta, timezone
from urllib.parse import urlparse

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/stonefellow/'
MODULES = (
    'canvas-library-runtime.js', 'public-agent-canvas.js', 'canvas-audio-sdk.js', 'vendor/acorn.js',
    'canvas-preview-guard.js', 'canvas-scene-tester.js',
)


def bundle_pin(id_, version, global_, digest):
    path = f'assets/vendor/canvas/{id_}.js'
    source = (ROOT / path).read_bytes()
    assert base64.b64encode(hashlib.sha256(source).digest()).decode() == digest
    return {'id': id_, 'version': version, 'global': global_, 'path': path,
            'bytes': len(source), 'sha256': digest}, source


THREE, THREE_SOURCE = bundle_pin('three', '0.187.0', 'THREE', 'x4iVrN/195Rpb8aECJMAa4ZA8cSt+X/Nx7yIkaptnQY=')
COLOR, COLOR_SOURCE = bundle_pin('color-thief', '3.5.0', 'ColorThief', '9KjGmp30P1mNciedabWfLZ0pVho0/pmxF0ktvJCBY+4=')
D3, D3_SOURCE = bundle_pin('d3', '7.9.2', 'd3', 'kSERCY6plQOm9JZzYdK73J2/WM+cZ4yOJ+WwLRoH8ME=')
ANIME, ANIME_SOURCE = bundle_pin('anime', '4.5.0', 'anime', 'jVs6WKH2QCOgSkzt7vE1oiY7GNoEs1F3rBNDigutAzs=')
OUTSIDE = 'https://outside.test/pin.js'
OUTSIDE_DATA = 'https://outside.test/shows.json'

GEOMETRY = """
const renderer = new THREE.WebGLRenderer({antialias:false, preserveDrawingBuffer:true});
renderer.setSize(96, 96);
const model = new THREE.Scene();
model.background = new THREE.Color(0);
const camera = new THREE.PerspectiveCamera(45, 1, .1, 10);
camera.position.z = 3;
model.add(new THREE.Mesh(new THREE.BoxGeometry(), new THREE.MeshBasicMaterial({color:0x00ff00})));
renderer.render(model, camera);
const gl = renderer.getContext(), pixel = new Uint8Array(4);
gl.readPixels(48, 48, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, pixel);
if (pixel[1] < 128 || pixel[0] > 32 || pixel[2] > 32) throw new Error('Expected rendered geometry');
document.querySelector('.fixture').append(renderer.domElement);
document.querySelector('.result').textContent = 'Rendered green geometry';
"""

OUTSIDE_SCRIPT = f"""
const outside = document.createElement('script');
outside.src = '{OUTSIDE}';
outside.integrity = 'sha256-{THREE['sha256']}';
outside.addEventListener('error', () => document.querySelector('.fixture').dataset.outside = 'blocked');
document.head.append(outside);
"""

PALETTE = """
const art = document.createElement('canvas');
art.width = 32;
art.height = 32;
const drawing = art.getContext('2d');
drawing.fillStyle = '#f20000';
drawing.fillRect(0, 0, 16, 32);
drawing.fillStyle = '#0000f2';
drawing.fillRect(16, 0, 16, 32);
const palette = ColorThief.getPaletteSync(art, {colorCount: 4, quality: 1});
if (!palette || palette.length < 2 || !palette.every(swatch => /^#[a-f0-9]{6}$/i.test(swatch.hex()))) {
    throw new Error('Expected palette swatches from painted pixels');
}
"""

# Authored fixtures exercise reusable host data and library APIs.
DATA_DESIGN = PALETTE + r"""
(async () => {
  const root = d3.select('.fixture');
  const [shows, boundary, unselected] = await Promise.all([
    StonefellowData.get('shows'), StonefellowData.get('boundary'), StonefellowData.get('unselected')
  ]);
  if (shows.status !== 'fresh' || boundary.status !== 'fresh' || unselected.status !== 'unavailable')
    throw new Error('Expected exactly the selected canvas data');
  const scale = d3.scaleLinear([0, d3.max(shows.rows, row => row.distance_km)], [0, 192]);
  const chart = root.append('svg').attr('class', 'distances').attr('viewBox', '0 0 240 60');
  chart.selectAll('rect').data(shows.rows).join('rect')
    .attr('width', row => scale(row.distance_km)).attr('height', 24).attr('y', (_, i) => i * 30)
    .attr('fill', palette[0].hex()).append('title').text(row => row.title);
  const shape = {type: 'LineString', coordinates: boundary.rows.flatMap(row => JSON.parse(row.coordinates))};
  const projection = d3.geoMercator().fitSize([240, 135], shape);
  const map = root.append('svg').attr('class', 'map').attr('viewBox', '0 0 240 135');
  map.append('path').attr('class', 'boundary').attr('d', d3.geoPath(projection)(shape))
    .attr('fill', 'none').attr('stroke', 'black');
  map.selectAll('circle').data(shows.rows).join('circle')
    .attr('cx', row => projection([row.lon, row.lat])[0]).attr('cy', row => projection([row.lon, row.lat])[1])
    .attr('r', 4).append('title').text(row => row.title);
  const rows = d3.csvParseRows('title,count\n"Local, CSV",2');
  root.select('.result').text(rows[1].join(' · '));
  const blocked = await d3.json(OUTSIDE_DATA).then(() => false, () => true);
  root.attr('data-network', blocked ? 'blocked' : 'allowed');
})();
""".replace('OUTSIDE_DATA', json.dumps(OUTSIDE_DATA))


MOTION_DESIGN = r"""
(() => {
  const root = document.querySelector('.fixture');
  const status = root.querySelector('.result');
  const button = document.createElement('button');
  button.type = 'button';
  button.textContent = 'Show artwork';
  const label = document.createElement('p');
  label.textContent = 'Evening artwork';
  label.className = 'motion-label';
  label.style.opacity = '0.25';
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 120 40');
  svg.setAttribute('width', '120');
  svg.setAttribute('height', '40');
  const moon = document.createElementNS(svg.namespaceURI, 'circle');
  moon.setAttribute('cx', '12');
  moon.setAttribute('cy', '20');
  moon.setAttribute('r', '8');
  moon.setAttribute('fill', '#ffe1a6');
  svg.append(moon);
  root.prepend(button, svg, label);
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  let timeline;
  function showStill() {
    moon.setAttribute('cx', '92');
    label.style.opacity = '1';
    status.textContent = 'Still artwork ready';
  }
  if (reduce) showStill();
  button.addEventListener('click', () => {
    if (reduce) {
      status.textContent = 'Still artwork selected';
      return;
    }
    if (timeline) timeline.revert();
    root.dataset.motion = 'starting';
    timeline = anime.createTimeline({
      autoplay: false, loop: true, alternate: true,
      onUpdate() {
        const x = Number(moon.getAttribute('cx'));
        const opacity = Number(label.style.opacity);
        if (x > 12 && x < 92 && opacity > 0.25 && opacity < 1) root.dataset.motion = 'advanced';
      },
    }).add(moon, {cx: 92, duration: 700, ease: 'linear'}, 0)
      .add(label, {opacity: 1, duration: 700, ease: 'linear'}, 0);
    timeline.play();
  });
  addEventListener('pagehide', () => {
    if (!timeline) return;
    timeline.revert();
    root.dataset.motion = 'reverted';
  }, {once: true});
})();
"""


def canvas_data():
    now = datetime.now(timezone.utc)
    fresh = {'status': 'fresh', 'fetched_at': now.isoformat(), 'expires_at': (now + timedelta(minutes=5)).isoformat()}
    return {
        'shows': {**fresh, 'rows': [
            {'title': 'North stage', 'distance_km': 18, 'lon': -112.07, 'lat': 33.61},
            {'title': 'East stage', 'distance_km': 24, 'lon': -111.81, 'lat': 33.45},
        ]},
        'boundary': {**fresh, 'rows': json.loads((ROOT / 'config/canvas-maps/us-regions.json').read_text())['AZ']},
    }


SETUP = r"""
(data) => {
window.dataReads = [];
window.makeScene = async (program, pin = '', dataSources = [], css = '.fixture{padding:80px 20px}') => {
    const js = pin ? '// sf-libraries: ' + pin + '\n' + program : program;
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(js));
    return {id:1, canvas_kind:'custom', track_id:'', title:'Pinned library test canvas',
        html:'<section class="fixture"><span class="result"></span></section>',
        css, js, artist_inputs:{data_sources:dataSources},
        js_hash:btoa(String.fromCharCode(...new Uint8Array(digest)))};
};
window.mountScene = async (scene) => {
    try {
        await SFPublicAgentCanvas.prepareSceneLibraries(scene);
        return SFPublicAgentCanvas.showScene(scene, {focus:false});
    } catch (_) {return false;}
};
SFPublicAgentCanvas.mount(document.querySelector('#target'), {
    audio:document.querySelector('audio'), canUseScenes:()=>true, getSceneOwner:()=>'owner-one',
    onCanvasDataRead(alias, context) {
        dataReads.push([alias, context.scene_id]);
        return data[alias];
    }
});
}
"""


def run(browser, width):
    touch = width == 390
    context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce', is_mobile=touch, has_touch=touch)
    page = context.new_page()
    page.set_default_timeout(10000)
    calls = []
    mode = 'allowed'

    def route_asset(route):
        url = route.request.url
        path = urlparse(url).path.removeprefix('/stonefellow/')
        if url == SITE:
            scripts = ''.join(f'<script src="assets/js/{name}"></script>' for name in MODULES)
            route.fulfill(content_type='text/html', body=(
                '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
                '<audio></audio><div id="target"></div>' + scripts
            ))
        elif url.startswith(SITE) and path.removeprefix('assets/js/') in MODULES:
            route.fulfill(content_type='text/javascript', body=(ROOT / path).read_bytes())
        elif url.startswith(SITE) and path == 'api/canvas-libraries.php':
            calls.append('manifest')
            route.fulfill(json={'ok': True, 'libraries': [THREE, COLOR, D3, ANIME] if mode != 'denied' else []})
        elif url.startswith(SITE) and path == THREE['path']:
            calls.append('three')
            body = THREE_SOURCE if mode != 'corrupt' else bytes([THREE_SOURCE[0] ^ 1]) + THREE_SOURCE[1:]
            route.fulfill(content_type='text/javascript', body=body)
        elif url.startswith(SITE) and path == COLOR['path']:
            calls.append('color-thief')
            route.fulfill(content_type='text/javascript', body=COLOR_SOURCE)
        elif url.startswith(SITE) and path == D3['path']:
            calls.append('d3')
            route.fulfill(content_type='text/javascript', body=D3_SOURCE)
        elif url.startswith(SITE) and path == ANIME['path']:
            calls.append('anime')
            route.fulfill(content_type='text/javascript', body=ANIME_SOURCE)
        elif url in (OUTSIDE, OUTSIDE_DATA):
            calls.append('outside')
            route.abort()
        else:
            route.abort()

    def initialize():
        page.goto(SITE)
        page.evaluate(SETUP, canvas_data())

    page.route('**/*', route_asset)
    initialize()
    frame = page.frame_locator('.sf-agent-scene-backdrop iframe')
    pin = f"three@{THREE['version']}#{THREE['sha256']}"
    color_pin = f"color-thief@{COLOR['version']}#{COLOR['sha256']}"
    both_pins = f"{pin},{color_pin}"
    d3_pin = f"d3@{D3['version']}#{D3['sha256']}"

    assert page.evaluate("""async () => mountScene(await makeScene(
        "document.querySelector('.result').textContent = [typeof THREE, typeof ColorThief, typeof d3].join(' ');"))""")
    expect(frame.locator('.result')).to_have_text('undefined undefined undefined')
    assert not calls, calls

    assert not page.evaluate("""async ([program,pin]) =>
        SFPublicAgentCanvas.showScene(await makeScene(program,pin), {focus:false})""", [GEOMETRY, pin])
    assert not calls, calls
    expect(frame.locator('.result')).to_have_text('undefined undefined undefined')

    assert page.evaluate('async ([program,pin]) => mountScene(await makeScene(program,pin))', [GEOMETRY + OUTSIDE_SCRIPT, pin])
    expect(frame.locator('.result')).to_have_text('Rendered green geometry')
    expect(frame.locator('canvas')).to_have_count(1)
    expect(frame.locator('.fixture')).to_have_attribute('data-outside', 'blocked')
    assert calls == ['manifest', 'three'], calls
    assert page.evaluate('[typeof window.THREE, typeof window.ColorThief]') == ['undefined', 'undefined']
    iframe = page.locator('.sf-agent-scene-backdrop iframe')
    assert iframe.get_attribute('sandbox') == 'allow-scripts'
    csp = frame.locator('meta[http-equiv="Content-Security-Policy"]').first.get_attribute('content')
    script_policy = next(part for part in csp.split(';') if part.strip().startswith('script-src '))
    assert f"'sha256-{THREE['sha256']}'" in script_policy
    assert 'unsafe-inline' not in script_policy and 'https:' not in script_policy

    before = len(calls)
    result = page.evaluate("""async ([program,pin]) => SFCanvasSceneTester.test(
        await makeScene(program + " StonefellowAudio.play().catch(()=>{});", pin),
        {getOwner:()=>'owner-one', canUse:()=>true}
    )""", [GEOMETRY + PALETTE, both_pins])
    assert result['state'] == 'passed', result
    assert result['observed']['visible'] >= 2, result
    assert any(row['code'] == 'host_play' for row in result['manual']), result
    assert calls[before] == 'manifest' and sorted(calls[before + 1:]) == ['color-thief', 'three'], calls
    expect(frame.locator('.result')).to_have_text('Rendered green geometry')

    before = len(calls)
    assert page.evaluate('async args => mountScene(await makeScene(...args))', [DATA_DESIGN, f'{d3_pin},{color_pin}', [
        {'alias': 'shows', 'product': 'geography.shows', 'location_id': 'city-0455000'},
        {'alias': 'boundary', 'product': 'map.boundaries', 'location_id': 'city-0455000'},
    ]])
    expect(frame.locator('.result')).to_have_text('Local, CSV · 2')
    expect(frame.locator('.fixture')).to_have_attribute('data-network', 'blocked')
    bars = frame.locator('.distances rect')
    assert bars.evaluate_all("bars => bars.map(bar => bar.getAttribute('width'))") == ['144', '192']
    assert bars.evaluate_all("bars => bars.every(bar => /^#[a-f0-9]{6}$/i.test(bar.getAttribute('fill')))") is True
    expect(bars.locator('title')).to_have_text(['North stage', 'East stage'])
    expect(frame.locator('.map circle')).to_have_count(2)
    assert len(frame.locator('.boundary').get_attribute('d')) > 150
    assert page.evaluate('typeof window.d3') == 'undefined'
    assert sorted(page.evaluate('dataReads')) == [['boundary', 1], ['shows', 1]]
    assert calls[before] == 'manifest' and sorted(calls[before + 1:]) == ['color-thief', 'd3'], calls
    print(f'PASS Chromium/{width}: D3 chart and map from selected data, Color Thief, local CSV rows and blocked external fetch', flush=True)

    mode = 'denied'
    before = len(calls)
    assert not page.evaluate('async ([program,pin]) => mountScene(await makeScene(program,pin))', [GEOMETRY, pin])
    assert calls[before:] == ['manifest'], calls
    expect(frame.locator('.result')).to_have_text('Local, CSV · 2')

    mode = 'corrupt'
    initialize()
    before = len(calls)
    assert not page.evaluate('async ([program,pin]) => mountScene(await makeScene(program,pin))', [GEOMETRY, pin])
    assert calls[before:] == ['manifest', 'three'], calls
    expect(iframe).to_have_count(0)
    print(f'PASS Chromium/{width}: pinned geometry and palette, preview and network isolation, absent and denied dependencies, corrupted bytes', flush=True)

    mode = 'allowed'
    anime_pin = f"anime@{ANIME['version']}#{ANIME['sha256']}"
    motion_args = [MOTION_DESIGN, anime_pin, [], '.fixture{padding:12px;display:grid;justify-items:start;gap:4px}.motion-label{margin:0}']

    def mount_motion():
        before = len(calls)
        assert page.evaluate('async args => mountScene(await makeScene(...args))', motion_args)
        assert calls[before:] == ['manifest', 'anime'], calls

    def show_artwork():
        button = frame.get_by_role('button', name='Show artwork')
        if touch:
            button.tap()
        else:
            button.click()

    mount_motion()
    expect(frame.locator('.result')).to_have_text('Still artwork ready')
    expect(frame.locator('circle')).to_have_attribute('cx', '92')
    show_artwork()
    expect(frame.locator('.result')).to_have_text('Still artwork selected')
    expect(frame.locator('.motion-label')).to_have_css('opacity', '1')
    assert frame.locator('.fixture').get_attribute('data-motion') is None

    page.emulate_media(reduced_motion='no-preference')
    mount_motion()
    expect(frame.locator('circle')).to_have_attribute('cx', '12')
    expect(frame.locator('.motion-label')).to_have_css('opacity', '0.25')
    show_artwork()
    expect(frame.locator('.fixture')).to_have_attribute('data-motion', 'advanced')
    assert page.evaluate('typeof window.anime') == 'undefined'

    animated_frame = iframe.element_handle().content_frame()
    animated_frame.evaluate("dispatchEvent(new Event('pagehide'))")
    expect(frame.locator('.fixture')).to_have_attribute('data-motion', 'reverted')
    expect(frame.locator('circle')).to_have_attribute('cx', '12')
    expect(frame.locator('.motion-label')).to_have_css('opacity', '0.25')

    show_artwork()
    expect(frame.locator('.fixture')).to_have_attribute('data-motion', 'advanced')
    assert page.evaluate('SFPublicAgentCanvas.closeScene(false)')
    expect(iframe).to_have_count(0)
    assert animated_frame.is_detached()
    assert page.evaluate('typeof window.anime') == 'undefined'
    before = len(calls)
    assert page.evaluate("async () => mountScene(await makeScene(\"document.querySelector('.result').textContent = typeof anime;\"))")
    expect(frame.locator('.result')).to_have_text('undefined')
    assert len(calls) == before, calls

    result = page.evaluate("""async args => SFCanvasSceneTester.test(await makeScene(...args),
        {getOwner:()=>'owner-one', canUse:()=>true})""", motion_args)
    assert result['state'] == 'passed', result
    assert result['observed']['interactive'] == 1, result
    assert calls[before:] == ['manifest', 'anime'], calls
    expect(frame.locator('.result')).to_have_text('undefined')
    context.close()
    print(f'PASS Chromium/{width}: Anime.js coordinates SVG and DOM, supports reduced motion, and passes isolated teardown and preview', flush=True)


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        headless=True, executable_path=os.environ.get('CHROMIUM_BIN') or shutil.which('chromium'),
        args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
    )
    for width in (390, 1280):
        run(browser, width)
    browser.close()
