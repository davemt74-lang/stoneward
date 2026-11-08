"""Exercise approved Image Studio artwork staging through the actual admin API."""

import http.cookiejar
import os
from pathlib import Path
import runpy
import socket
import sqlite3
import subprocess
import tempfile
import time
from urllib.request import HTTPCookieProcessor, build_opener


support = runpy.run_path(str(Path(__file__).with_name('pwa-splash-http.py')))
PHP, ROOT, PREFIX, ADMIN = (support[name] for name in ('PHP', 'ROOT', 'PREFIX', 'ADMIN'))
ICON = PREFIX + '/admin/api/pwa-icon-stage.php'
request = support['request']


def seed_jobs(site):
    exact = site / 'source.png'
    exact.write_bytes(support['png'](512, 512))
    webp = ROOT / 'assets/images/agent-atmosphere-ember.webp'
    subprocess.run([PHP, '-r', '''
        define('SF_ROOT',getcwd());
        require 'api/bootstrap.php';
        require_once 'api/image-generation-core.php';
        sf_image_gen_schema();
        $db=sf_db();
        $insert=$db->prepare("INSERT INTO image_generation_jobs
            (request_key,created_by,prompt,model,size,quality,status,created_at,updated_at)
            VALUES(?,1,'fixture','gpt-image-1.5','1024x1024','medium','generating',?,?)");
        foreach ([$argv[1],$argv[2]] as $i=>$path) {
            $now=gmdate('c');
            $insert->execute(['icon-fixture-'.$i,$now,$now]);
            $id=(int)$db->lastInsertId();
            sf_image_gen_complete($id,1,['data'=>[['b64_json'=>base64_encode(file_get_contents($path))]]]);
            if($i===0)sf_image_gen_approve_asset($id,1);
        }
    ''', str(exact), str(webp)], cwd=site, check=True)
    exact.unlink()


def exercise(origin, site):
    guest = build_opener()
    customer = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    admin = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    for client, uid in ((customer, 2), (admin, 1)):
        assert request(client, origin, f'{PREFIX}/login-fixture.php?user={uid}')[0] == 200
    csrf = request(admin, origin, ADMIN)[1]['csrfToken']
    exact = {'jobId': 1, 'revision': 0}
    assert request(guest, origin, ICON, exact)[0] == 401
    assert request(customer, origin, ICON, exact)[0] == 403
    assert request(admin, origin, ICON, exact)[0] == 403
    assert request(admin, origin, ICON)[0] == 405

    def stage(job, revision, expected):
        status, body, _ = request(admin, origin, ICON, {'jobId': job, 'revision': revision}, csrf)
        assert status == expected, body
        return body

    staged = stage(1, 0, 200)
    exact_asset = staged['iconAssetId']
    assert staged['revision'] == 1
    assert stage(1, 1, 200) == staged
    assert stage(1, 0, 409)['error'] == 'pwa_splash_conflict'
    stage(2, 1, 422)
    status, state, _ = request(admin, origin, ADMIN, {'action': 'save', 'revision': 1, 'settings': {
        'backgroundColor': '#382456', 'waiting': {'title': 'Shared waiting room'},
    }}, csrf)
    assert status == 200 and state['draft']['revision'] == 2
    assert request(guest, origin, state['draft']['iconUrl'])[0] == 404
    assert state['published']['iconAssetId'] == 0
    approved = request(admin, origin, PREFIX + '/admin/api/image-generation.php', {
        'action': 'approve_asset', 'id': 2, 'confirmed': True,
    }, csrf)
    assert approved[0] == 200, approved[1]

    decoder = subprocess.check_output([PHP, '-r',
        "echo function_exists('imagecreatefromwebp') && function_exists('imagepng') && (imagetypes() & IMG_WEBP) ? 'yes' : 'no';"
    ], text=True) == 'yes'
    if os.environ.get('SF_PWA_ICON_TEST_REQUIRE_GD') == '1':
        assert decoder, 'CI requires PHP GD with WebP and PNG support'
    if not decoder:
        unavailable = stage(2, 2, 503)
        assert unavailable['error'] == 'pwa_icon_conversion_unavailable' and 'upload a square' in unavailable['message']
        assert request(admin, origin, ADMIN)[1]['draft']['revision'] == 2
        print('PASS: approved 512 PNG, private draft, revision conflict, and missing-decoder fallback')
        return

    converted = stage(2, 2, 200)
    assert converted['revision'] == 3 and converted['iconAssetId'] != exact_asset
    assert stage(2, 3, 200) == converted
    state = request(admin, origin, ADMIN)[1]
    assert state['draft']['backgroundColor'] == '#382456' and state['draft']['waiting']['title'] == 'Shared waiting room'
    assert state['published']['iconAssetId'] == 0
    assert request(guest, origin, state['draft']['iconUrl'])[0] == 404
    status, body, headers = request(admin, origin, state['draft']['iconUrl'])
    assert status == 200 and headers.get_content_type() == 'image/png'
    output = site / 'converted.png'
    output.write_bytes(body)
    subprocess.run([PHP, '-r', '''
        $image=imagecreatefrompng($argv[1]);
        if(imagesx($image)!==512||imagesy($image)!==512)exit(1);
        if((imagecolorat($image,0,0)>>24)!==127||(imagecolorat($image,256,256)>>24)!==0)exit(2);
    ''', str(output)], check=True)
    output.unlink()

    # An approved job may still lose its managed file integrity after approval.
    with sqlite3.connect(site / 'config/test.sqlite') as db:
        original, = db.execute('SELECT stored_path FROM media_assets JOIN image_generation_jobs '
                               'ON media_assets.id=image_generation_jobs.media_asset_id WHERE image_generation_jobs.id=2').fetchone()
        count, = db.execute("SELECT COUNT(*) FROM media_assets WHERE stored_path LIKE 'storage/media/pwa-icons/%'").fetchone()
    assert count == 1
    with (site / original).open('ab') as damaged:
        damaged.write(b'changed after approval')
    stage(2, 3, 503)
    assert request(admin, origin, ADMIN)[1]['draft']['revision'] == 3
    status, published, _ = request(admin, origin, ADMIN, {'action': 'publish', 'revision': 3}, csrf)
    assert status == 200 and published['published']['iconAssetId'] == converted['iconAssetId']
    assert request(guest, origin, published['published']['iconUrl'])[0] == 200
    print('PASS: approval and file integrity, transparent 512 PNG conversion, deduplication, draft CAS, and explicit publication')


def main():
    with tempfile.TemporaryDirectory(prefix='stonefellow-pwa-icon-') as directory:
        webroot = Path(directory)
        support['fixture'](webroot)
        site = webroot / PREFIX.strip('/')
        seed_jobs(site)
        sessions = webroot / 'sessions'
        sessions.mkdir()
        with socket.socket() as port:
            port.bind(('127.0.0.1', 0))
            address = port.getsockname()[1]
        with tempfile.TemporaryFile() as logs:
            server = subprocess.Popen([PHP, '-d', 'session.save_path=' + str(sessions), '-S',
                                       f'127.0.0.1:{address}', 'router.php'], cwd=webroot, stdout=logs, stderr=logs)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', address), timeout=0.1):
                            break
                    except OSError:
                        time.sleep(0.02)
                exercise(f'http://127.0.0.1:{address}', site)
            except Exception:
                logs.seek(0)
                print(logs.read().decode(), end='')
                raise
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == '__main__':
    main()
