"""Render approved local sprites with PixiJS and Matter in the production canvas sandbox."""
import base64
import hashlib
import os
from pathlib import Path
import shutil
from urllib.parse import urlparse

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SITE = 'https://fixture.test/stonefellow/'
IMAGE_ID = 'a' * 32
GREEN_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAEElEQVR4nGOQfRAGRwzEcQAjohUxD+isAAAAAABJRU5ErkJggg=='
MODULES = ('canvas-library-runtime.js', 'canvas-audio-sdk.js', 'public-agent-canvas.js')
STYLES = ('assets/css/site.css', 'assets/css/public-agent-canvas.css')


def pinned_bundle(name, version, global_name, digest):
    path = f'assets/vendor/canvas/{name}.js'
    source = (ROOT / path).read_bytes()
    assert base64.b64encode(hashlib.sha256(source).digest()).decode() == digest
    card = {'id': name, 'version': version, 'global': global_name, 'path': path,
            'bytes': len(source), 'sha256': digest}
    return card, source


PIXI = pinned_bundle('pixi', '8.22.0', 'PIXI', '28NrL5LScPjXco5gNXKw4CokVRumQ9cPtMUjwc2GjQo=')
MATTER = pinned_bundle('matter', '0.20.0', 'Matter', 'lh+vgT10WyQ/AWgAAT+8jtde0Cw5O8LKm8ASMnAgC0Y=')

SPRITE_SCENE = r"""
(async () => {
  const game = document.querySelector('.game');
  addEventListener('securitypolicyviolation', event => {
    game.dataset.cspError = event.violatedDirective;
  });
  const view = game.querySelector('.view');
  const status = game.querySelector('.status');
  const art = document.querySelector('.texture');
  const img = art.querySelector('img') || await new Promise(resolve => {
    const observer = new MutationObserver(() => {
      const image = art.querySelector('img');
      if (!image) return;
      observer.disconnect();
      resolve(image);
    });
    observer.observe(art, {childList:true});
  });
  await img.decode();
  if (!img.currentSrc.startsWith('blob:')) throw new Error('Expected a host-provided image');

  const app = new PIXI.Application();
  const viewWidth = () => Math.min(620, Math.floor(view.clientWidth));
  await app.init({
    width:viewWidth(), height:144, preference:'webgl', autoStart:false,
    antialias:false, preserveDrawingBuffer:true, background:0x010203,
  });
  const canvas = app.canvas;
  canvas.setAttribute('aria-label', 'Sprite physics stage');
  view.append(canvas);
  const gl = app.renderer.gl;
  const sprite = new PIXI.Sprite(PIXI.Texture.from(img));
  sprite.anchor.set(.5);
  sprite.width = sprite.height = 42;
  sprite.eventMode = 'static';
  app.stage.addChild(sprite);

  const engine = Matter.Engine.create({gravity:{x:0,y:0}});
  const body = Matter.Bodies.circle(48, 64, 20, {frictionAir:0});
  const wall = Matter.Bodies.rectangle(viewWidth()-28,64,16,128,{isStatic:true});
  Matter.Composite.add(engine.world,[body,wall]);

  let frame = 0;
  let moving = false;
  let hits = 0;

  function render() {
    sprite.position.set(body.position.x, body.position.y);
    app.render();
    const pixel = new Uint8Array(4);
    gl.readPixels(Math.round(sprite.x), canvas.height-1-Math.round(sprite.y),1,1,gl.RGBA,gl.UNSIGNED_BYTE,pixel);
    game.dataset.pixel = pixel[1] > 175 && pixel[0] < 60 && pixel[2] < 125 ? 'green' : 'missing';
    game.dataset.spriteX = String(Math.round(sprite.x));
  }
  function tick() {
    if (moving) {
      Matter.Engine.update(engine,1000/60);
      render();
    }
    frame = requestAnimationFrame(tick);
  }
  function onHit({pairs}) {
    if (!moving || !pairs.some(pair => (pair.bodyA === body && pair.bodyB === wall) ||
      (pair.bodyB === body && pair.bodyA === wall))) return;
    Matter.Body.setVelocity(body,{x:0,y:0});
    moving = false;
    game.dataset.hits = String(++hits);
    status.textContent = 'Sprite hit the wall';
  }
  function onPointer(event) {
    if (!event.nativeEvent.isTrusted || hits || moving) return;
    game.dataset.pointer = event.pointerType;
    moving = true;
    Matter.Body.setVelocity(body,{x:12,y:0});
  }
  function onResize() {
    if (gl.isContextLost() || app.renderer.width === viewWidth()) return;
    app.renderer.resize(viewWidth(), 144);
    Matter.Body.setPosition(wall,{x:viewWidth()-28,y:64});
    render();
  }
  function onContextLost(event) {
    event.preventDefault();
    cancelAnimationFrame(frame);
    frame = 0;
    game.dataset.context = 'lost';
    status.textContent = 'Graphics paused';
  }
  function onContextRestored() {
    if (!frame) frame = requestAnimationFrame(tick);
    render();
    game.dataset.context = 'restored';
    status.textContent = 'Graphics restored';
  }

  const resize = new ResizeObserver(onResize);
  resize.observe(view);
  Matter.Events.on(engine,'collisionStart',onHit);
  sprite.on('pointerdown', onPointer);
  canvas.addEventListener('webglcontextlost', onContextLost);
  canvas.addEventListener('webglcontextrestored', onContextRestored);
  addEventListener('pagehide', () => {
    const wasTicking = frame !== 0;
    cancelAnimationFrame(frame);
    resize.disconnect();
    canvas.removeEventListener('webglcontextlost', onContextLost);
    canvas.removeEventListener('webglcontextrestored', onContextRestored);
    sprite.off('pointerdown', onPointer);
    Matter.Events.off(engine,'collisionStart',onHit);
    Matter.Composite.clear(engine.world);
    Matter.Engine.clear(engine);
    app.destroy({removeView:true}, {children:true,texture:true,textureSource:true});
    parent.postMessage({pixiLifecycle:{wasTicking,removed:!canvas.isConnected,
      contextReleased:gl.isContextLost(),bodies:Matter.Composite.allBodies(engine.world).length}}, '*');
  }, {once:true});
  render();
  frame = requestAnimationFrame(tick);
  status.textContent = 'Sprite ready';
})().catch(error => {
  document.querySelector('.status').textContent = 'Initialization error: ' + error.message;
  throw error;
});
"""

SETUP = """
async ({id, png, program}) => {
  window.pixiReads = [];
  window.pixiLifecycle = null;
  addEventListener('message', event => {
    if (event.data?.pixiLifecycle) window.pixiLifecycle = event.data.pixiLifecycle;
  });
  const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(program));
  const scene = {
    id:72, canvas_kind:'custom', track_id:'', title:'Local sprite physics', agent_design:'hidden',
    html:'<section class="game"><p class="status">Loading sprite</p><div class="view"></div></section>'
      + '<figure class="texture sf-image-' + id + '"></figure>',
    css:'.game{padding:76px 16px 0}.texture{display:none}.view canvas{display:block;touch-action:none}',
    js:program, js_hash:btoa(String.fromCharCode(...new Uint8Array(hash))),
    image_assets:[{id,title:'Green fixture sprite'}],
  };
  SFPublicAgentCanvas.mount(document.querySelector('#target'), {
    audio:document.querySelector('audio'), canUseScenes:()=>true, getSceneOwner:()=> 'fixture-owner',
    onSceneImage:async (assetId, signal, selectedScene) => {
      if (signal.aborted || selectedScene.id !== scene.id || assetId !== id) throw new Error('Wrong image');
      pixiReads.push(assetId);
      return new Blob([Uint8Array.from(atob(png), c => c.charCodeAt(0))],{type:'image/png'});
    },
  });
  await SFPublicAgentCanvas.prepareSceneLibraries(scene);
  return SFPublicAgentCanvas.showScene(scene,{focus:false});
}
"""


def run(browser, width, touch):
    context = browser.new_context(viewport={'width': width, 'height': 850}, has_touch=touch, is_mobile=touch)
    page = context.new_page()
    page.set_default_timeout(12000)
    calls = []
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    libraries = {entry['path']: (entry, source) for entry, source in (PIXI, MATTER)}

    def respond(route):
        url = route.request.url
        path = urlparse(url).path.removeprefix('/stonefellow/')
        if url == SITE:
            scripts = ''.join(f'<script src="assets/js/{name}"></script>' for name in MODULES)
            styles = ''.join(f'<link rel="stylesheet" href="{name}">' for name in STYLES)
            route.fulfill(content_type='text/html', body=(
                '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
                + styles + '<body class="sf-agent-canvas-active"><audio></audio>'
                '<main class="stage"><div id="target" class="dynamic-canvas"></div></main>'
                '<footer class="chat-footer"><div class="social-links"></div></footer>' + scripts
            ))
        elif url.startswith(SITE) and path in STYLES:
            route.fulfill(content_type='text/css', body=(ROOT / path).read_bytes())
        elif url == SITE + 'assets/images/agent-atmosphere-ember.webp':
            route.fulfill(content_type='image/webp', body=(ROOT / path).read_bytes())
        elif url.startswith(SITE) and path.removeprefix('assets/js/') in MODULES:
            route.fulfill(content_type='text/javascript', body=(ROOT / path).read_bytes())
        elif url.startswith(SITE) and path == 'api/canvas-libraries.php':
            calls.append('manifest')
            route.fulfill(json={'ok': True, 'libraries': [entry for entry, _ in libraries.values()]})
        elif url.startswith(SITE) and path in libraries:
            entry, source = libraries[path]
            calls.append(entry['id'])
            route.fulfill(content_type='text/javascript', body=source)
        else:
            calls.append(url)
            route.abort()

    page.route('**/*', respond)
    page.goto(SITE)
    pins = ','.join(f"{entry['id']}@{entry['version']}#{entry['sha256']}" for entry, _ in (PIXI, MATTER))
    assert page.evaluate(SETUP, {'id': IMAGE_ID, 'png': GREEN_PNG,
                                'program': f'// sf-libraries: {pins}\n{SPRITE_SCENE}'})
    layer = page.locator('.sf-agent-scene-backdrop iframe')
    assert layer.get_attribute('sandbox') == 'allow-scripts'
    frame = layer.content_frame
    game = frame.locator('.game')
    status = frame.locator('.status')
    canvas = frame.get_by_label('Sprite physics stage')
    try:
        expect(status).to_have_text('Sprite ready')
    except AssertionError as error:
        raise AssertionError(f'{error}\nUncaught browser errors: {errors}') from error
    expect(game).to_have_attribute('data-pixel', 'green')
    assert page.evaluate('[typeof window.PIXI, typeof window.Matter, window.pixiReads]') == [
        'undefined', 'undefined', [IMAGE_ID]
    ]
    assert calls[0] == 'manifest' and sorted(calls[1:]) == ['matter', 'pixi'], calls
    csp = frame.locator('meta[http-equiv="Content-Security-Policy"]').first.get_attribute('content')
    assert "connect-src 'none'" in csp and "'unsafe-eval'" not in csp, csp
    assert all(f"'sha256-{entry['sha256']}'" in csp for entry, _ in (PIXI, MATTER)), csp

    new_width = width - 64 if touch else 540
    page.set_viewport_size({'width': new_width, 'height': 850})
    expect(canvas).to_have_attribute('width', str(new_width - 32))
    expect(game).to_have_attribute('data-pixel', 'green')
    if touch:
        canvas.tap(position={'x': 48, 'y': 64})
    else:
        canvas.click(position={'x': 48, 'y': 64})
    expect(game).to_have_attribute('data-pointer', 'touch' if touch else 'mouse')
    expect(game).to_have_attribute('data-hits', '1')
    expect(status).to_have_text('Sprite hit the wall')
    assert int(game.get_attribute('data-sprite-x')) > 180
    expect(game).to_have_attribute('data-pixel', 'green')

    canvas.evaluate("""canvas => {
      window.testGraphics = canvas.getContext('webgl2').getExtension('WEBGL_lose_context');
      window.testGraphics.loseContext();
    }""")
    expect(game).to_have_attribute('data-context', 'lost')
    expect(status).to_have_text('Graphics paused')
    canvas.evaluate('() => window.testGraphics.restoreContext()')
    expect(game).to_have_attribute('data-context', 'restored')
    expect(game).to_have_attribute('data-pixel', 'green')
    expect(status).to_have_text('Graphics restored')
    assert game.get_attribute('data-csp-error') is None

    # Check authored cleanup while the child can still report it. Removing the
    # iframe destroys the document and can discard its queued messages.
    child = layer.element_handle().content_frame()
    assert child is not None
    child.evaluate("dispatchEvent(new PageTransitionEvent('pagehide'))")
    page.wait_for_function('window.pixiLifecycle !== null')
    assert page.evaluate('window.pixiLifecycle') == {
        'wasTicking': True, 'removed': True, 'contextReleased': True, 'bodies': 0
    }
    expect(canvas).to_have_count(0)
    with page.expect_event('framedetached', lambda detached: detached == child):
        assert page.evaluate('SFPublicAgentCanvas.closeScene(false)')
    expect(layer).to_have_count(0)
    assert child.is_detached()
    assert len(calls) == 3 and not errors, (calls, errors)
    context.close()
    print(f'PASS Chromium/{width}: approved Pixi sprite, responsive Matter collision, context recovery, and teardown', flush=True)


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        headless=True, executable_path=os.environ.get('CHROMIUM_BIN') or shutil.which('chromium'),
        args=['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
    )
    try:
        for width, touch in ((390, True), (1280, False)):
            run(browser, width, touch)
    finally:
        browser.close()
