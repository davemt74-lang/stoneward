"""Venue pairing, shared player, guest chat, returning guests, and session recaps through production routes."""
import base64
import os
from pathlib import Path
import socket
import sys
import re
import json
from http.cookiejar import CookieJar
import sqlite3
import subprocess
import tempfile
import time
import urllib.request

if '--http-only' not in sys.argv:
    from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
CHROME = os.environ.get('CHROMIUM_BIN')
PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH1sAAAAASUVORK5CYII='


def fixture(site):
    for directory in ('api', 'assets/js', 'assets/css', 'config', 'data', 'assets/audio'):
        (site / directory).mkdir(parents=True)
    (site / 'api').rmdir()
    (site / 'api').symlink_to(ROOT / 'api', target_is_directory=True)
    (site / 'jukebox.php').symlink_to(ROOT / 'jukebox.php')
    for file in ('assets/js/jukebox.js', 'assets/js/jukebox-qr.js', 'assets/css/jukebox.css'):
        (site / file).symlink_to(ROOT / file)
    (site / 'config/database.php').write_text("<?php return ['driver'=>'sqlite','path'=>__DIR__.'/fixture.sqlite'];")
    (site / 'config/store.php').write_text('<?php return [];')
    (site / 'data/catalog.json').write_text('''[
        {"id":"a","title":"First Song","release_id":"record","duration":18,"audio":"assets/audio/test.mp3"},
        {"id":"b","title":"Second Song","release_id":"record","duration":18,"audio":"assets/audio/test.mp3"}]''')
    (site / 'data/releases.json').write_text(
        '[{"id":"record","title":"The Record","state":"published","track_ids":["a","b"]}]')
    subprocess.run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'anullsrc', '-t', '18',
                    str(site / 'assets/audio/test.mp3')], check=True)
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.executescript('''CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,
          role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT,password_hash TEXT);
          CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL);
          INSERT INTO users VALUES(1,'admin@example.test','Admin','admin','active','','','',''),
          (2,'venue@example.test','Bartender','customer','active','','','',''),
          (3,'other@example.test','Other','customer','active','','','','');''')
    (site / 'login-fixture.php').write_text("""<?php
        require __DIR__.'/api/bootstrap.php';
        sf_auth_issue_persistent_session((int)$_GET['user']);
        echo 'ok';
    """)
    # Symlinked PHP entry points resolve __DIR__ to the repo; the router fixes SF_ROOT
    # for fixture storage while leaving the production endpoint implementations intact.
    (site / 'router.php').write_text("""<?php
        define('SF_ROOT',__DIR__);
        $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
        if(str_ends_with($path,'.php') && is_file(__DIR__.$path)){require __DIR__.$path;return true;}
        return false;
    """)


def request(page, action, data=None, host=False, session=None):
    return page.evaluate("""async ({action,data,host,session}) => {
      const url = new URL('api/jukebox.php', location.href);
      const current = new URLSearchParams(location.search);
      url.search = new URLSearchParams({action,id:session ?? current.get('id') ?? '',host:host?'1':'0'});
      const options = data === null ? {} : {method:'POST',body:JSON.stringify(data),
        headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=sf-csrf]').content}};
      const response = await fetch(url,options);
      return {status:response.status,...await response.json()};
    }""", dict(action=action, data=data, host=host, session=session))


def scenario(browser, base):
    def signed_in(user):
        page = browser.new_context().new_page()
        page.goto(base + f'/login-fixture.php?user={user}')
        page.goto(base + '/jukebox.php?host=1')
        return page

    admin = signed_in(1)
    assert request(admin, 'venue', {'owner': 2, 'name': 'Test Bar'}, True)['ok']
    universal = request(admin, 'create', {'title': 'Universal', 'listed': True}, True)['id']
    venue = signed_in(2)
    venue.locator('#create input[name=title]').fill('Tonight at Test Bar')
    venue.get_by_role('button', name='Create session').click()
    expect(venue.get_by_role('heading', name='Tonight at Test Bar')).to_be_visible()
    session = venue.url.split('id=')[1].split('&')[0]
    assert session != universal
    expect(venue.locator('#qr svg')).to_be_visible()
    join = venue.locator('#join-link').get_attribute('href')
    first = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True).new_page()
    second = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True).new_page()
    for phone in (first, second):
        phone.goto(join)
    assert request(first, 'request', {'track': 'a', 'nonce': '1'*32})['status'] == 409
    first.locator('#join input[name=name]').fill('Dave')
    first.locator('#join input[name=remember]').check()
    first.get_by_role('button', name='Request access').click()
    venue.locator('#phones li').filter(has_text='Dave').get_by_role('button', name='Approve').click()
    first.get_by_role('button', name='Albums').click()
    first.get_by_role('button', name='The Record').click()
    expect(first.locator('#catalog')).to_contain_text('First Song')
    first.locator('#catalog li').filter(has_text='First Song').get_by_role('button', name='Play').click()
    venue.get_by_role('button', name='Enable player on this device').click()
    venue.locator('#history li, #audio-start:visible').first.wait_for()
    if venue.locator('#audio-start').is_visible():
        venue.locator('#audio-start').click()
    expect(venue.locator('#history')).to_contain_text('First Song')
    expect(first.locator('#now')).to_have_text('First Song')
    assert first.locator('#audio').evaluate('(audio) => !audio.src && audio.paused')
    second.get_by_role('button', name='Request access').click()
    venue.locator('#phones li').filter(has_text='pending').get_by_role('button', name='Approve').click()
    expect(second.locator('#now')).to_have_text('First Song')
    second.locator('#catalog li').filter(has_text='Second Song').get_by_role('button', name='Add to Queue').click()
    expect(first.locator('#queue-label')).to_have_text('Up Next (1)')
    queued = {'track': 'b', 'nonce': '2'*32}
    assert request(first, 'request', queued)['ok'] and request(first, 'request', queued)['ok']
    expect(second.locator('#queue-label')).to_have_text('Up Next (2)')
    venue.locator('#phones li').filter(has_text='Dave').get_by_role('button', name='Chat').click()
    venue.locator('#send input[name=text]').fill('Private hello')
    venue.locator('#send input[name=image]').set_input_files(
        {'name': 'guest.png', 'mimeType': 'image/png', 'buffer': base64.b64decode(PNG)})
    venue.get_by_role('button', name='Send').click()
    expect(first.locator('#messages')).to_contain_text('Private hello')
    expect(first.locator('#messages img')).to_be_visible()
    first.wait_for_function("document.querySelector('#messages img').naturalWidth === 1")
    assert 'Private hello' not in str(request(second, 'thread'))
    venue.get_by_role('button', name='Pause', exact=True).click()
    expect(first.locator('#progress')).to_contain_text('Paused')
    venue.reload()
    expect(venue.locator('#queue-label')).to_have_text('Up Next (2)')
    venue.get_by_role('button', name='Skip', exact=True).click()
    expect(second.locator('#now')).to_have_text('Second Song')
    venue.locator('#phones li').filter(has_text='Dave').get_by_role('button', name='Revoke').click()
    expect(first.locator('#approval')).to_contain_text('closed')
    assert request(first, 'thread')['status'] == 409
    another = request(venue, 'create', {'title': 'Next night'}, True)['id']
    first.goto(base + '/jukebox.php?id=' + another)
    first.get_by_role('button', name='Request access').click()
    venue.goto(base + '/jukebox.php?host=1&id=' + another)
    returning = venue.locator('#phones li').filter(has_text='Returning guest')
    expect(returning).to_contain_text('Dave')
    returning.get_by_role('button', name='Approve').click()
    venue.get_by_text('Venue guests and visit history').click()
    expect(venue.locator('#guests')).to_contain_text('2 visits')
    assert request(venue, 'state', host=True)['state']['queue'] == []
    public = browser.new_context().new_page()
    public.goto(base + '/jukebox.php')
    expect(public.get_by_role('link', name='Universal')).to_be_visible()
    expect(public.get_by_role('link', name='Next night')).to_have_count(0)
    other = signed_in(3)
    assert request(other, 'state', host=True, session=another)['status'] == 404
    venue.goto(base + '/jukebox.php?host=1&id=' + session)
    expect(venue.locator('#recap')).to_be_hidden()
    venue.once('dialog', lambda dialog: dialog.accept())
    venue.get_by_role('button', name='End session').click()
    expect(venue.get_by_role('heading', name='Session recap')).to_be_visible()
    expect(venue.locator('#recap-totals')).not_to_be_empty()
    expect(venue.locator('#recap-tracks')).to_contain_text('First Song')
    expect(venue.locator('#recap-tracks')).to_contain_text('Second Song')
    venue.goto(base + '/jukebox.php?host=1')
    venue.get_by_role('link', name='Tonight at Test Bar (ended)', exact=True).click()
    expect(venue.get_by_role('heading', name='Session recap')).to_be_visible()
    expect(venue.locator('#recap-tracks')).to_contain_text('First Song')
    second.reload()
    expect(second.locator('#recap')).to_be_hidden()
    print('PASS venue pairing, playback, queue persistence and deduplication, private chat, CRM, and reopened session recap')


def http_scenario(base):
    class Client:
        def __init__(self, user=None):
            self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
            self.host = user is not None
            if self.host:
                self.opener.open(base + f'/login-fixture.php?user={user}').close()
            with self.opener.open(base + '/jukebox.php' + ('?host=1' if self.host else '')) as page:
                html = page.read().decode()
                match = re.search(r'name="sf-csrf" content="([^"]+)"', html)
                assert match, (page.geturl(), html[:800])
                self.csrf = match[1]

        def call(self, action, session='', data=None, **params):
            query = urllib.parse.urlencode(dict(action=action, id=session, host=int(self.host), **params))
            request = urllib.request.Request(base + '/api/jukebox.php?' + query, headers={
                'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf},
                data=None if data is None else json.dumps(data).encode())
            try:
                response = self.opener.open(request)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                raw = response.read()
                assert raw.startswith(b'{'), raw[:1000]
                return {'status': response.status, **json.loads(raw)}

    admin, venue, outsider = Client(1), Client(2), Client(3)
    assert admin.call('venue', data={'owner': 2, 'name': 'Test Bar'})['ok']
    public = admin.call('create', data={'title': 'Universal', 'listed': True})['id']
    session = venue.call('create', data={'title': 'Tonight'})['id']
    assert public != session
    first, second = Client(), Client()
    assert [row['id'] for row in first.call('directory')['sessions']] == [public]
    assert first.call('request', session, {'track': 'a', 'nonce': '1'*32})['status'] == 409
    assert first.call('join', session, {'name': 'Dave', 'remember': True})['status'] == 'pending'
    assert second.call('join', session, {})['ok']
    first_id = first.call('state', session)['state']['phone']['id']
    second_id = second.call('state', session)['state']['phone']['id']
    assert venue.call('approve', session, {'phone': first_id})['ok']
    assert first.call('request', session, {'track': 'a', 'nonce': '1'*32})['ok']
    now = first.call('state', session)['state']['current']
    assert now['track_id'] == 'a' and now['played'] is None
    assert venue.call('approve', session, {'phone': second_id})['ok']
    assert second.call('state', session)['state']['current'] == now
    for _ in range(2):
        assert second.call('request', session, {'track': 'b', 'nonce': '2'*32})['ok']
    assert len(first.call('state', session)['state']['queue']) == 1
    player = {'player': 'a'*64}
    assert venue.call('player', session, player | {'event': 'claim'})['ok']
    assert venue.call('player', session, {'player': 'b'*64, 'event': 'claim'})['status'] == 409
    assert venue.call('player', session, player | {'event': 'playing', 'item': now['id']})['ok']
    assert venue.call('state', session)['state']['history'][0]['track_id'] == 'a'
    assert venue.call('player', session, player | {'event': 'ended', 'item': now['id']})['ok']
    assert first.call('state', session)['state']['current']['track_id'] == 'b'
    assert venue.call('message', session, {'phone': first_id, 'text': 'Private hello', 'image': PNG})['ok']
    message = first.call('thread', session)['messages'][0]
    assert message['body'] == 'Private hello' and message['attachment']
    image_url = base + f'/api/jukebox.php?action=image&id={session}&message={message["id"]}'
    with first.opener.open(image_url) as image:
        assert image.headers.get_content_type() == 'image/png'
        assert image.read() == base64.b64decode(PNG)
    assert second.call('image', session, message=message['id'])['status'] == 404
    assert second.call('thread', session, phone=first_id)['messages'] == []
    assert venue.call('revoke', session, {'phone': first_id})['ok']
    assert first.call('thread', session)['status'] == 409
    next_session = venue.call('create', data={'title': 'Next night'})['id']
    assert first.call('join', next_session, {})['ok']
    returning = venue.call('state', next_session)['state']['phones'][0]
    assert returning['name'] == 'Dave' and returning['last_visit']
    assert venue.call('approve', next_session, {'phone': returning['id']})['ok']
    assert venue.call('crm', next_session)['guests'][0]['visits'] == 2
    assert venue.call('state', next_session)['state']['queue'] == []
    assert outsider.call('state', next_session)['status'] == 404
    assert first.call('forget', next_session, {})['ok']
    assert venue.call('crm', next_session)['guests'] == []
    assert venue.call('recap', session)['status'] == 409

    # Add one pending connection and reject another without approving it. The
    # previously approved and then revoked phone still counts as approved.
    pending, rejected = Client(), Client()
    assert pending.call('join', session, {})['ok']
    assert rejected.call('join', session, {})['ok']
    rejected_id = rejected.call('state', session)['state']['phone']['id']
    assert venue.call('revoke', session, {'phone': rejected_id})['ok']

    # Exercise each distinct outcome. A confirmed play can later be skipped,
    # while a request that fails before playback must never count as a play.
    current = venue.call('state', session)['state']['current']
    assert current['track_id'] == 'b' and current['played'] is None
    assert venue.call('request', session, {'track': 'a', 'nonce': '3'*32})['ok']
    assert second.call('request', session, {'track': 'b', 'nonce': '4'*32})['ok']
    remove_item = venue.call('state', session)['state']['queue'][-1]['id']
    assert venue.call('control', session, {'event': 'remove', 'item': remove_item})['ok']
    assert second.call('request', session, {'track': 'b', 'nonce': '5'*32})['ok']
    assert venue.call('player', session, player | {'event': 'failed', 'item': current['id']})['ok']
    current = venue.call('state', session)['state']['current']
    assert current['track_id'] == 'a'
    assert venue.call('player', session, player | {'event': 'playing', 'item': current['id']})['ok']
    assert venue.call('control', session, {'event': 'skip'})['ok']
    assert second.call('request', session, {'track': 'b', 'nonce': '6'*32})['ok']
    assert first.call('request', next_session, {'track': 'a', 'nonce': '7'*32})['ok']
    assert venue.call('control', session, {'event': 'end'})['ok']
    assert second.call('request', session, {'track': 'a', 'nonce': '8'*32})['status'] == 409

    assert second.call('recap', session)['status'] == 409
    assert outsider.call('recap', session)['status'] == 404
    recap = venue.call('recap', session)['recap']
    assert recap['session_id'] == session and recap['title'] == 'Tonight'
    assert 0 < recap['created'] <= time.time()
    assert recap['phones'] == {'connections': 4, 'approved': 2}
    totals = dict(requests=6, guest_requests=5, host_requests=1, confirmed_plays=2,
                  completed=1, skipped=1, failed=1, removed=1, left_in_queue=1, current_at_close=1)
    assert recap['totals'] == totals
    assert recap['tracks'] == [
        dict(track_id='b', title='Second Song', requests=4, guest_requests=4, host_requests=0,
             confirmed_plays=0, completed=0, skipped=0, failed=1, removed=1, left_in_queue=1, current_at_close=1),
        dict(track_id='a', title='First Song', requests=2, guest_requests=1, host_requests=1,
             confirmed_plays=2, completed=1, skipped=1, failed=0, removed=0, left_in_queue=0, current_at_close=0),
    ]
    assert admin.call('recap', session)['recap'] == recap
    assert 'Private hello' not in json.dumps(recap) and 'Dave' not in json.dumps(recap)
    assert admin.call('control', public, {'event': 'end'})['ok']
    empty = admin.call('recap', public)['recap']
    assert empty['phones'] == {'connections': 0, 'approved': 0}
    assert empty['totals'] == dict.fromkeys(totals, 0) and empty['tracks'] == []
    assert Client().call('recap', public)['status'] == 409
    print('PASS HTTP pairing, shared queue, player ownership, play history, private chat, CRM, and host-only session recap')


with tempfile.TemporaryDirectory(prefix='stoneward-jukebox-') as temp:
    site = Path(temp)
    fixture(site)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with (site / 'server.log').open('w+') as log:
        server = subprocess.Popen([PHP, '-d', f'session.save_path={site}', '-S', f'127.0.0.1:{port}', 'router.php'],
                                  cwd=site, stdout=log, stderr=log)
        try:
            for _ in range(60):
                try:
                    urllib.request.urlopen(base + '/jukebox.php', timeout=1).close()
                    break
                except OSError:
                    time.sleep(.1)
            if '--http-only' in sys.argv:
                http_scenario(base)
            else:
                with sync_playwright() as playwright:
                    browser = playwright.chromium.launch(executable_path=CHROME, args=['--no-sandbox'])
                    scenario(browser, base)
        except Exception:
            log.seek(0)
            print(log.read()[-16000:])
            raise
        finally:
            server.terminate()
            server.wait(timeout=5)
