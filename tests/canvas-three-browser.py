"""Exercise the production Three.js loader and isolated Canvas renderer."""
import base64
import hashlib
import os
from pathlib import Path
import shutil
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
OUTSIDE = 'https://outside.test/pin.js'

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

SETUP = r"""
window.makeScene = async (program, pin = '') => {
    const js = pin ? '// sf-libraries: ' + pin + '\n' + program : program;
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(js));
    return {id:1, canvas_kind:'custom', track_id:'', title:'Three test canvas',
        html:'<section class="fixture"><span class="result"></span></section>',
        css:'.fixture{padding:80px 20px}', js,
        js_hash:btoa(String.fromCharCode(...new Uint8Array(digest)))};
};
window.mountScene = async (scene) => {
    try {
        await SFPublicAgentCanvas.prepareSceneLibraries(scene);
        return SFPublicAgentCanvas.showScene(scene, {focus:false});
    } catch (_) {return false;}
};
SFPublicAgentCanvas.mount(document.querySelector('#target'), {
    audio:document.querySelector('audio'), canUseScenes:()=>true, getSceneOwner:()=>'owner-one'
});
"""


def run(browser, width):
    context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
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
            route.fulfill(json={'ok': True, 'libraries': [THREE, COLOR] if mode != 'denied' else []})
        elif url.startswith(SITE) and path == THREE['path']:
            calls.append('three')
            body = THREE_SOURCE if mode != 'corrupt' else bytes([THREE_SOURCE[0] ^ 1]) + THREE_SOURCE[1:]
            route.fulfill(content_type='text/javascript', body=body)
        elif url.startswith(SITE) and path == COLOR['path']:
            calls.append('color-thief')
            route.fulfill(content_type='text/javascript', body=COLOR_SOURCE)
        elif url == OUTSIDE:
            calls.append('outside')
            route.abort()
        else:
            route.abort()

    def initialize():
        page.goto(SITE)
        page.evaluate(SETUP)

    page.route('**/*', route_asset)
    initialize()
    frame = page.frame_locator('.sf-agent-scene-backdrop iframe')
    pin = f"three@{THREE['version']}#{THREE['sha256']}"
    both_pins = f"{pin},color-thief@{COLOR['version']}#{COLOR['sha256']}"

    assert page.evaluate("""async () => mountScene(await makeScene(
        "document.querySelector('.result').textContent = [typeof THREE, typeof ColorThief].join(' ');"))""")
    expect(frame.locator('.result')).to_have_text('undefined undefined')
    assert not calls, calls

    assert not page.evaluate("""async ([program,pin]) =>
        SFPublicAgentCanvas.showScene(await makeScene(program,pin), {focus:false})""", [GEOMETRY, pin])
    assert not calls, calls
    expect(frame.locator('.result')).to_have_text('undefined undefined')

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

    mode = 'denied'
    before = len(calls)
    assert not page.evaluate('async ([program,pin]) => mountScene(await makeScene(program,pin))', [GEOMETRY, pin])
    assert calls[before:] == ['manifest'], calls
    expect(frame.locator('.result')).to_have_text('Rendered green geometry')

    mode = 'corrupt'
    initialize()
    before = len(calls)
    assert not page.evaluate('async ([program,pin]) => mountScene(await makeScene(program,pin))', [GEOMETRY, pin])
    assert calls[before:] == ['manifest', 'three'], calls
    expect(iframe).to_have_count(0)
    context.close()
    print(f'PASS Chromium/{width}: pinned geometry and palette, preview and network isolation, absent and denied dependencies, corrupted bytes', flush=True)


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        headless=True, executable_path=os.environ.get('CHROMIUM_BIN') or shutil.which('chromium'),
        args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
    )
    for width in (390, 1280):
        run(browser, width)
    browser.close()
