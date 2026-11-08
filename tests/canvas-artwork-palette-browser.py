"""Exercise real Color Thief extraction and active artwork changes in the Canvas host."""
import os
from pathlib import Path
import shutil
from urllib.parse import urlparse

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://palette.test/'
SCRIPTS = (
    'assets/vendor/canvas/color-thief.js', 'assets/js/media-artwork-palette.js',
    'assets/js/canvas-agent-theme.js', 'assets/js/public-agent-canvas.js',
)
SETUP = r"""
async () => {
    window.makeImage = (left, right) => {
        const sample = document.createElement('canvas');
        sample.width = sample.height = 32;
        const ctx = sample.getContext('2d');
        if (left) { ctx.fillStyle = left; ctx.fillRect(0, 0, 16, 32); }
        if (right) { ctx.fillStyle = right; ctx.fillRect(16, 0, 16, 32); }
        return new Promise(resolve => sample.toBlob(resolve, 'image/png'));
    };
    window.backdrop = await makeImage('#d54230', '#242227');
    window.foreground = await makeImage('#00adcc', '#0b1010');
    window.track = 'First';
    window.owner = 'owner-one';
    window.pending = new Map();
    SFPublicAgentCanvas.mount(document.querySelector('#target'), {
        canUseScenes: () => true,
        getSceneOwner: () => owner,
        getTrackArtwork: () => 'api/media.php?id=' + track + '&variant=large',
        onSceneImage: (id, signal) => new Promise(resolve => pending.set(id, {resolve, signal})),
        onEditSceneImage: () => {},
    });
    window.show = (ids, agent_palette) => {
        const html = '<section class="room"><div class="sf-image-' + ids.at(-1) + '"></div></section>';
        const css = '.room{background:var(--sf-image-' + ids[0] + ');color:#b2b2b2}';
        return SFPublicAgentCanvas.showScene({id:1, canvas_kind:'custom', title:'Test', html, css,
            agent_palette, image_assets:ids.map(id => ({id, colors:['#aabbcc']}))}, {focus:false});
    };
}
"""


def run(browser, width):
    context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
    page = context.new_page()
    page.set_default_timeout(8000)
    requests = []

    def route_asset(route):
        url = urlparse(route.request.url)
        path = url.path.removeprefix('/')
        if route.request.url == SITE:
            scripts = ''.join(f'<script src="/{name}"></script>' for name in SCRIPTS)
            route.fulfill(content_type='text/html', body=(
                '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
                '<link rel="stylesheet" href="/assets/css/public-agent-canvas.css">'
                '<body class="sf-agent-canvas-active"><div id="target"></div>'
                '<footer class="chat-footer"><form class="chatbar"><input placeholder="Chat"></form></footer>' + scripts
            ))
        elif path in SCRIPTS or path == 'assets/css/public-agent-canvas.css':
            route.fulfill(content_type='text/javascript' if path.endswith('.js') else 'text/css', body=(ROOT / path).read_bytes())
        elif path == 'api/media.php':
            requests.append(url.query)
            colors = ['#ae63ea'] if 'First' in url.query else ['#a2d937']
            if 'Denied' in url.query:
                route.fulfill(status=404)
            else:
                route.fulfill(json={'palette': {'version': 'color-thief@3.5.0/sf1', 'status': 'ready', 'colors': colors}})
        else:
            route.abort()

    page.route('**/*', route_asset)
    page.goto(SITE)
    page.evaluate(SETUP)
    host = page.locator('#target > .sf-agent-landing')
    footer = page.locator('.chat-footer')
    expect(host).to_have_attribute('data-agent-palette', '1')
    expect(footer).to_have_attribute('data-agent-palette', '1')
    color = lambda: host.evaluate("el => el.style.getPropertyValue('--sf-atmo-accent')")
    assert requests == ['id=First&variant=large&palette=1']
    initial = color()

    pixel_accent = page.evaluate("""async () => {
        const image = await SFArtworkPalette.extract(backdrop);
        const duplicate = await SFArtworkPalette.extract(new Blob([backdrop], {type:'image/png'}));
        const transparent = await SFArtworkPalette.extract(await makeImage(null, null));
        const flat = await SFArtworkPalette.extract(await makeImage('#aaaaaa', '#aaaaaa'));
        if (image !== duplicate || image.status !== 'ready' || image.sha256.length !== 64 ||
            transparent.status !== 'insufficient_pixels' || flat.status !== 'insufficient_pixels') throw Error('Invalid pixel extraction');
        return SFCanvasAgentTheme.fromArtwork(image).accent;
    }""")
    ids = ['b' * 32, 'a' * 32]
    assert page.evaluate('ids => show(ids)', ids)
    page.evaluate('id => pending.get(id).resolve(foreground)', ids[1])
    assert color() != pixel_accent
    page.evaluate('id => pending.get(id).resolve(backdrop)', ids[0])
    page.wait_for_function("accent => document.querySelector('#target > .sf-agent-landing').style.getPropertyValue('--sf-atmo-accent') === accent", arg=pixel_accent)
    assert footer.evaluate("el => el.style.getPropertyValue('--sf-atmo-accent')") == pixel_accent
    page.evaluate("track = 'Second'; SFPublicAgentCanvas.syncPlayer()")
    assert color() == pixel_accent and len(requests) == 1

    assert page.evaluate('ids => show(ids)', ['c' * 32])
    page.evaluate("owner = 'owner-two'; SFPublicAgentCanvas.syncSceneAccess()")
    assert page.evaluate("id => pending.get(id).signal.aborted", 'c' * 32)
    page.evaluate('id => pending.get(id).resolve(foreground)', 'c' * 32)
    expect(host).to_have_attribute('data-agent-palette', '1')
    assert color() not in [pixel_accent, initial]
    assert requests[-1] == 'id=Second&variant=large&palette=1'

    assert page.evaluate("ids => show(ids, {accent:'#eedd55',secondary:'#aaccee',surface:'#111111'})", ids)
    page.evaluate('id => pending.get(id).resolve(backdrop)', ids[0])
    assert color() == '#eedd55'
    page.evaluate("track = 'Denied'; SFPublicAgentCanvas.closeScene(false)")
    expect(host).not_to_have_attribute('data-agent-palette', '1')
    expect(footer).not_to_have_attribute('data-agent-palette', '1')
    page.wait_for_function("async () => await SFArtworkPalette.forArtwork('api/media.php?id=Denied') === null")
    assert all('palette=1' in query for query in requests)
    context.close()
    print(f'PASS Chromium/{width} actual pixels, exact-byte cache, backdrop precedence, owner change, denied media fallback', flush=True)


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True, executable_path=os.environ.get('CHROMIUM_BIN') or shutil.which('chromium'),
                                         args=['--no-sandbox'])
    for width in (390, 1280):
        run(browser, width)
    browser.close()
