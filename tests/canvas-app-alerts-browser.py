"""Published app install, in-app consent, worker delivery and inbox tap-back."""
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('canvas_audio_fixture', ROOT / 'tests/canvas-audio-integration-browser.py')
fixture = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(fixture)


def publish(site, title, version, previous=None):
    source = {
        'canvas_kind': 'custom', 'track_id': '', 'title': title,
        'html': f'<section><h1>Challenge {version}</h1><span class="theme sf-action-app-notifications">Challenge alerts</span></section>',
        'css': '.theme{display:inline-block;border:1px solid white;border-radius:12px;padding:8px 12px}',
    }
    encoded = json.dumps(json.dumps(source, separators=(',', ':')))
    parent = 'null' if previous is None else str(previous)
    return json.loads(fixture.cli(site, f'''
        require_once SF_ROOT.'/api/canvas-gallery-core.php';
        $scene=sf_agent_scene_history_save(1,json_decode({encoded},true,8,JSON_THROW_ON_ERROR),{parent});
        $item=sf_canvas_gallery_submit(['id'=>1,'role'=>'admin'],['scene_id'=>$scene['id'],'title'=>$scene['title'],
            'description'=>'Challenge update fixture','creator_label'=>'Fixture creator','publication_consent'=>true,
            'request_id'=>bin2hex(random_bytes(16))]);
        sf_canvas_gallery_moderate(['id'=>1,'role'=>'admin'],$item['id'],'approve',$item['snapshot_hash']);
        echo json_encode(['id'=>$item['id'],'scene'=>$scene['id']]);
    '''))


def deliver(site):
    return json.loads(fixture.cli(site, """
        require_once SF_ROOT.'/api/canvas-app-alert-core.php';
        echo json_encode(sf_canvas_app_alert_run(100,static fn()=>throw new RuntimeException('No live push in this fixture')));
    """))


with tempfile.TemporaryDirectory(prefix='stonefellow-app-alerts-') as temporary:
    site = Path(temporary)
    fixture.seed(site)
    for name in ('index.php', 'stonefellow-v120.php', 'version.php', 'service-worker.js'):
        shutil.copy2(ROOT / name, site / name)
    fixture.cli(site, 'sf_db()->exec("UPDATE users SET role=\'admin\' WHERE id=1");')
    with socket.socket() as port_socket:
        port_socket.bind(('127.0.0.1', 0))
        port = port_socket.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with (site / 'php.log').open('w+') as log:
        server = subprocess.Popen([fixture.PHP, '-d', 'session.save_path=' + str(site / 'sessions'),
                                   '-S', f'127.0.0.1:{port}', '-t', str(site)], stdout=log, stderr=log)
        try:
            for _ in range(150):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(headless=True, args=['--no-sandbox'],
                    executable_path=os.environ.get('CHROMIUM_BIN') or shutil.which('chromium'))
                for width in (390, 1440):
                    title = f'Challenge Studio {width}'
                    app = publish(site, title, 1)
                    other = publish(site, f'Separate Studio {width}', 1)
                    context = browser.new_context(viewport={'width': width, 'height': 820}, has_touch=width == 390)
                    page = context.new_page()
                    fixture.login(page, base, 'other@example.invalid')
                    csrf = page.request.get(base + '/api/auth.php').json()['csrf']
                    result = page.request.post(base + '/api/canvas-app.php', headers={'X-CSRF-Token': csrf},
                        data={'action': 'install', 'gallery_id': other['id']})
                    assert result.ok and result.json()['app']['enabled'] is False
                    page.goto(base + '/index.php?view=canvas-gallery&id=' + app['id'] + '&open=1')
                    frame = page.frame_locator('.sf-agent-scene-backdrop iframe')
                    control = frame.get_by_role('button', name='Challenge alerts', exact=True)
                    expect(frame.get_by_role('heading', name='Challenge 1')).to_be_visible()
                    expect(control).to_have_attribute('data-sf-notification-state', 'not-installed')
                    control.evaluate('button => button.click()')
                    dialog = page.locator('dialog.sf-canvas-app-notifications')
                    expect(dialog).to_have_count(0)
                    (control.tap if width == 390 else control.click)()
                    expect(dialog.get_by_role('heading', name='Notifications for ' + title)).to_be_visible()
                    dialog.get_by_role('button', name='Install app', exact=True).click()
                    expect(control).to_have_attribute('data-sf-notification-state', 'off')
                    dialog.get_by_role('button', name='Enable app notifications', exact=True).click()
                    expect(control).to_have_attribute('aria-pressed', 'true')
                    dialog.get_by_role('button', name='Close', exact=True).click()

                    revision = publish(site, title, 2, app['scene'])
                    publish(site, f'Separate Studio {width}', 2, other['scene'])
                    sent = deliver(site)
                    assert sent['done'] == 1 and sent['errors'] == 0, sent
                    assert deliver(site)['selected'] == 0
                    page.locator('#notificationButton').click()
                    drawer = page.locator('#notificationDrawer')
                    alert = drawer.locator('article').filter(has=page.get_by_text(title, exact=True))
                    expect(alert).to_be_visible()
                    expect(drawer.get_by_text(f'Separate Studio {width}', exact=True)).to_have_count(0)
                    alert.get_by_role('button', name='Open app').click()
                    expect(frame.get_by_role('heading', name='Challenge 2')).to_be_visible()
                    expect(control).to_have_attribute('aria-pressed', 'true')

                    (control.tap if width == 390 else control.click)()
                    dialog.get_by_role('button', name='Turn off app notifications', exact=True).click()
                    expect(control).to_have_attribute('data-sf-notification-state', 'off')
                    publish(site, title, 3, revision['scene'])
                    assert deliver(site)['selected'] == 0
                    dialog.get_by_role('button', name='Close', exact=True).click()
                    page.locator('[data-agent-action="close-scene"]').click()
                    expect(page).not_to_have_url(re.compile(r'[?&]open=1(?:&|$)'))
                    print(f'PASS {width}px install, in-app opt-in, verified revision, inbox tap-back and opt-out', flush=True)
                    context.close()
                browser.close()
        except Exception:
            log.seek(0)
            print(log.read()[-4000:])
            raise
        finally:
            server.terminate()
            server.wait(timeout=5)
