"""Check top-level location consent and result-only proximity in Chromium."""
import os
from pathlib import Path
import shutil

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = 'https://location.test'
FIXTURE = '''<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/picker.css">
<button id="start">Start check</button><script src="/location.js"></script>
<script>
window.target = {lat:33, lon:-112, radius_m:100, title:'Public fixture venue'};
window.initial = SFCanvasLocation.check(target);
window.calls = 0;
const nativeCheck = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);
navigator.geolocation.getCurrentPosition = (success, failure, options) => {
  calls++;
  window.lastOptions = options;
  if (window.mode === 'pending') { window.finishPosition = success; return; }
  if (window.mode === 'stale') {
    success({coords:{latitude:33,longitude:-112,accuracy:1}, timestamp:Date.now()-40000});
    return;
  }
  if (window.mode === 'denied') { failure({code:1}); return; }
  nativeCheck(success, failure, options);
};
document.querySelector('#start').onclick = () => {
  window.controller = new AbortController();
  window.answer = null;
  SFCanvasLocation.check(target, controller.signal).then(value => window.answer = value);
};
</script>'''

with sync_playwright() as playwright:
    launch = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        launch['executable_path'] = executable
    browser = playwright.chromium.launch(**launch)
    for width in (390, 1440):
        context = browser.new_context(viewport={'width': width, 'height': 750},
                                      has_touch=width == 390, permissions=['geolocation'],
                                      geolocation={'latitude': 33, 'longitude': -112, 'accuracy': 20})
        page = context.new_page()
        requests, errors = [], []
        page.on('request', lambda request: requests.append(request.url))
        page.on('pageerror', lambda error: errors.append(str(error)))

        def respond(route):
            if route.request.url == ORIGIN + '/':
                route.fulfill(body=FIXTURE, content_type='text/html')
            elif route.request.url == ORIGIN + '/picker.css':
                route.fulfill(body=(ROOT / 'assets/css/canvas-resource-picker.css').read_text(), content_type='text/css')
            elif route.request.url == ORIGIN + '/location.js':
                route.fulfill(body=(ROOT / 'assets/js/canvas-location.js').read_text(), content_type='text/javascript')
            else:
                route.abort()

        page.route('**/*', respond)
        page.goto(ORIGIN + '/')
        dialog = page.get_by_role('dialog', name='Check your location')
        start = page.get_by_role('button', name='Start check')
        cancel = dialog.get_by_role('button', name='Cancel')
        allow = dialog.get_by_role('button', name='Check once')

        def activate(button):
            button.tap() if width == 390 else button.click()

        def checked(status):
            page.wait_for_function('()=>window.answer!==null')
            assert page.evaluate('window.answer') == {'status': status}
            expect(dialog).to_have_count(0)

        assert page.evaluate('window.initial') == {'status': 'denied'}
        assert page.evaluate('window.calls') == 0
        expect(dialog).to_have_count(0)
        assert page.evaluate('SFCanvasLocation.check({...target, radius_m:9}, undefined, true)') == {
            'status': 'unavailable'
        }

        activate(start)
        expect(dialog).to_contain_text('100 m of Public fixture venue')
        assert dialog.bounding_box()['width'] <= width - 24
        assert allow.bounding_box()['height'] >= 44
        allow.evaluate('(button) => button.click()')
        assert page.evaluate('window.calls') == 0
        assert page.evaluate('SFCanvasLocation.check(target, undefined, true)') == {'status': 'unavailable'}
        activate(cancel)
        checked('denied')
        assert page.evaluate('window.calls') == 0

        for latitude, accuracy, expected in ((33, 20, 'inside'), (33.002, 10, 'outside'),
                                             (33.0008, 30, 'uncertain')):
            context.set_geolocation({'latitude': latitude, 'longitude': -112, 'accuracy': accuracy})
            activate(start)
            activate(allow)
            checked(expected)
        assert page.evaluate('window.calls') == 3
        assert page.evaluate('window.lastOptions') == {'enableHighAccuracy': True, 'maximumAge': 0, 'timeout': 12000}

        page.evaluate("window.mode='pending'")
        activate(start)
        activate(allow)
        expect(dialog.get_by_role('status')).to_have_text('Checking your location…')
        page.evaluate('controller.abort()')
        checked('unavailable')
        page.evaluate('finishPosition({coords:{latitude:33,longitude:-112,accuracy:1},timestamp:Date.now()})')
        assert page.evaluate('window.answer') == {'status': 'unavailable'}

        for mode, expected in (('stale', 'unavailable'), ('denied', 'denied')):
            page.evaluate('(mode)=>window.mode=mode', mode)
            activate(start)
            activate(allow)
            checked(expected)
        assert requests == [ORIGIN + '/', ORIGIN + '/picker.css', ORIGIN + '/location.js'], requests
        assert errors == [], errors
        context.close()
        print(f'PASS {width}px trusted opt-in, privacy, accuracy, cancellation and retry')
    browser.close()
