"""Venue pairing, shared playback, remote listening, chat, and recaps through production routes."""
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
BROWSER = os.environ.get('SF_JUKEBOX_BROWSER', 'chromium')
PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH1sAAAAASUVORK5CYII='
TRACK_SECONDS = 45
MANAGED_AUDIO = 'M' + 'A'*20


def fixture(site):
    for directory in ('api', 'assets/js', 'assets/css', 'config', 'data', 'assets/audio', 'storage/media'):
        (site / directory).mkdir(parents=True)
    (site / 'api').rmdir()
    (site / 'api').symlink_to(ROOT / 'api', target_is_directory=True)
    (site / 'jukebox.php').symlink_to(ROOT / 'jukebox.php')
    for file in ('assets/js/jukebox.js', 'assets/js/jukebox-qr.js', 'assets/js/jukebox-listener.js',
                 'assets/css/jukebox.css'):
        (site / file).symlink_to(ROOT / file)
    (site / 'config/database.php').write_text("<?php return ['driver'=>'sqlite','path'=>__DIR__.'/fixture.sqlite'];")
    (site / 'config/store.php').write_text('<?php return [];')
    (site / 'data/catalog.json').write_text(json.dumps([
        dict(id=track_id, title=title, release_id='record', duration=TRACK_SECONDS, audio=audio)
        for track_id, title, audio in [('a', 'First Song', 'assets/audio/test.mp3'),
                                       ('b', 'Second Song', 'api/media.php?id=' + MANAGED_AUDIO)]
    ]))
    (site / 'data/releases.json').write_text(
        '[{"id":"record","title":"The Record","state":"published","track_ids":["a","b"]}]')
    subprocess.run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'anullsrc', '-t', str(TRACK_SECONDS),
                    str(site / 'assets/audio/test.mp3')], check=True)
    (site / 'storage/media/test.mp3').write_bytes((site / 'assets/audio/test.mp3').read_bytes())
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.executescript('''CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,
          role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT,password_hash TEXT);
          CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL);
          INSERT INTO users VALUES(1,'admin@example.test','Admin','admin','active','','','',''),
          (2,'venue@example.test','Bartender','customer','active','','','',''),
          (3,'other@example.test','Other','customer','active','','','','');''')
    subprocess.run([PHP, '-r', '''
        define('SF_ROOT',getcwd());
        require 'api/bootstrap.php';
        sf_media_ensure_schema();
        $db=sf_db();
        $path='storage/media/test.mp3';
        $now=gmdate('c');
        $db->prepare("INSERT INTO media_assets
            (uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,duration_seconds,created_at,updated_at)
            VALUES(?,'audio','audio/mpeg','mp3','test.mp3',?,?,?,?,?,?)")
            ->execute([$argv[1],$path,hash_file('sha256',$path),filesize($path),(int)$argv[2],$now,$now]);
        sf_media_attach((int)$db->lastInsertId(),'track','b','primary_audio',['public_visible'=>true]);
    ''', MANAGED_AUDIO, str(TRACK_SECONDS)], cwd=site, check=True)
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


def player_sample(player, event, item, sequence, position_ms, transport='playing', **extra):
    return dict(player=player, event=event, item=item, seconds=position_ms // 1000,
                position_ms=position_ms, sample_seq=sequence, transport=transport, **extra)


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
    venue.locator('#create').get_by_label('Allow remote listening').check()
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
    expect(first.get_by_role('button', name='Listen on this phone')).to_be_hidden()
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
    expect(first.get_by_role('button', name='Listen on this phone')).to_be_visible()
    assert first.locator('#listener-audio').evaluate('(audio) => audio.paused && !audio.currentSrc')
    first.wait_for_function("""async () => {
      const id = new URLSearchParams(location.search).get('id');
      const {state} = await (await fetch('api/jukebox.php?action=state&id=' + id)).json();
      return state.transport?.status === 'playing' && state.transport.position_ms > 0;
    }""", polling=250)
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
    assert request(first, 'state')['state']['transport']['status'] == 'paused'
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


def remote_scenario(browser, base, phone_options):
    host = browser.new_context().new_page()
    host.goto(base + '/login-fixture.php?user=1')
    host.goto(base + '/jukebox.php?host=1')
    create = host.locator('#create')
    create.locator('input[name=title]').fill('Remote listening test')
    create.locator('input[name=listed]').check()
    create.get_by_label('Allow remote listening').check()
    create.get_by_role('button', name='Create session').click()
    expect(host.get_by_role('heading', name='Remote listening test')).to_be_visible()

    assert request(host, 'request', {'track': 'a', 'nonce': '1'*32}, True)['ok']
    assert request(host, 'request', {'track': 'b', 'nonce': '2'*32}, True)['ok']
    state = request(host, 'state', host=True)['state']
    first_item, second_item = state['current']['id'], state['queue'][0]['id']
    player = 'a'*64
    assert request(host, 'player', {'player': player, 'event': 'claim'}, True)['ok']
    sequence = 0

    def sample(item, position_ms, event='progress', transport='playing'):
        nonlocal sequence
        sequence += 1
        state = request(host, 'state', host=True)['state']
        payload = player_sample(player, event, item, sequence, position_ms, transport,
                                observed_revision=state['revision'])
        assert request(host, 'player', payload, True)['ok']

    phone = browser.new_context(**phone_options).new_page()
    phone.goto(base + '/jukebox.php')
    expect(phone.get_by_role('heading', name='Live Sessions', exact=True)).to_be_visible()
    phone.get_by_role('link', name='Remote listening test', exact=True).click()
    listen = phone.get_by_role('button', name='Listen on this phone')
    expect(listen).to_be_visible()
    remote_audio = phone.locator('#listener-audio')
    assert remote_audio.evaluate('(audio) => audio.paused && !audio.currentSrc')
    assert request(phone, 'request', {'track': 'a', 'nonce': '3'*32})['status'] == 409

    sample(first_item, 6500, event='playing')
    expect(phone.locator('#progress')).to_have_text('0:06')
    listen.click()
    phone.wait_for_function("""() => {
      const audio = document.querySelector('#listener-audio');
      return !audio.paused && audio.currentTime >= 6;
    }""")
    phone.locator('#listener-mute').click()
    assert remote_audio.evaluate('(audio) => audio.muted')
    phone.locator('#listener-mute').click()
    assert remote_audio.evaluate('(audio) => !audio.muted')
    assert request(host, 'control', {'event': 'pause'}, True)['ok']
    phone.wait_for_function("document.querySelector('#listener-audio').paused")
    assert request(host, 'control', {'event': 'resume'}, True)['ok']
    sample(first_item, 7500)
    phone.wait_for_function("!document.querySelector('#listener-audio').paused")

    assert request(host, 'control', {'event': 'skip'}, True)['ok']
    sample(second_item, 1200, event='playing')
    expect(phone.locator('#now')).to_have_text('Second Song')
    phone.wait_for_function("""item => {
      const audio = document.querySelector('#listener-audio');
      return audio.currentSrc && !audio.paused && new URL(audio.currentSrc).searchParams.get('item') === item;
    }""", arg=str(second_item))

    # Interrupt only state polling. Already buffered media must pause and then
    # catch up from a fresh venue sample without a second opt-in gesture.
    def state_route(url):
        parsed = urllib.parse.urlsplit(url)
        return parsed.path.endswith('/api/jukebox.php') and urllib.parse.parse_qs(parsed.query).get('action') == ['state']

    phone.route(state_route, lambda route: route.abort())
    phone.wait_for_function("document.querySelector('#listener-audio').paused", timeout=10_000)
    sample(second_item, 18500)
    phone.unroute(state_route)
    phone.wait_for_function("""() => {
      const audio = document.querySelector('#listener-audio');
      return !audio.paused && audio.currentTime >= 18;
    }""")

    phone.get_by_role('button', name='Stop listening').click()
    expect(listen).to_be_visible()
    assert remote_audio.evaluate('(audio) => audio.paused')
    state = request(host, 'state', host=True)['state']
    assert state['current']['id'] == second_item and state['transport']['status'] == 'playing'
    sample(second_item, 19200)
    expect(phone.locator('#progress')).to_have_text('0:19')
    listen.click()
    phone.wait_for_function("!document.querySelector('#listener-audio').paused")
    host.locator('#remote-listening').uncheck()
    expect(listen).to_be_hidden()
    phone.wait_for_function("document.querySelector('#listener-audio').paused")
    print('PASS anonymous phone listening, mid-song join, venue pause and skip, outage recovery, and local stop')


def http_scenario(base, site):
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

        def response(self, action, session='', data=None, headers=None, method=None, **params):
            query = urllib.parse.urlencode(dict(action=action, id=session, host=int(self.host), **params))
            request = urllib.request.Request(base + '/api/jukebox.php?' + query, headers={
                'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf, **(headers or {})},
                data=None if data is None else json.dumps(data).encode(), method=method)
            try:
                return self.opener.open(request)
            except urllib.error.HTTPError as error:
                return error

        def call(self, action, session='', data=None, **params):
            with self.response(action, session, data, **params) as response:
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
    http_remote_scenario(Client, admin, venue, site)


def http_remote_scenario(Client, admin, venue, site):
    public = admin.call('create', data={'title': 'Remote public', 'listed': True, 'remote_listening': True})['id']
    disabled = admin.call('create', data={'title': 'Remote disabled', 'listed': True})['id']
    private = venue.call('create', data={'title': 'Remote private', 'remote_listening': True})['id']
    anonymous, phone = Client(), Client()
    player = {'player': 'b'*64}

    assert anonymous.call('state', public)['state']['remote_listening'] is True
    assert anonymous.call('state', disabled)['state']['remote_listening'] is False
    assert anonymous.call('state', private)['state']['remote_listening'] is False
    assert anonymous.call('request', public, {'track': 'a', 'nonce': '1'*32})['status'] == 409
    assert admin.call('request', public, {'track': 'a', 'nonce': '1'*32})['ok']
    assert admin.call('request', public, {'track': 'a', 'nonce': '2'*32})['ok']
    first = anonymous.call('state', public)['state']['current']['id']

    def blocked(client, session, item):
        with client.response('audio', session, item=item) as response:
            assert response.status == 404

    def play(host, session, item, sequence, position_ms):
        state = host.call('state', session)['state']
        payload = player_sample(player['player'], 'playing', item, sequence, position_ms,
                                observed_revision=state['revision'])
        assert host.call('player', session, payload)['ok']

    blocked(anonymous, public, first)
    assert admin.call('player', public, player | {'event': 'claim'})['ok']
    assert anonymous.call('state', public)['state']['transport']['status'] == 'starting'
    blocked(anonymous, public, first)
    play(admin, public, first, 1, 6500)
    state = anonymous.call('state', public)['state']
    transport = state['transport']
    assert transport['status'] == 'playing' and transport['position_ms'] == 6500
    assert 0 < transport['sampled_at_ms'] <= transport['server_time_ms']
    directory = {row['id']: row for row in anonymous.call('directory')['sessions']}
    assert directory[public]['remote_listening'] is True and directory[public]['online'] is True
    assert directory[disabled]['remote_listening'] is False and private not in directory
    with anonymous.response('audio', public, item=first, method='HEAD') as response:
        assert response.status == 200 and response.headers.get_content_type() == 'audio/mpeg'
        assert response.read() == b'' and response.headers['Accept-Ranges'] == 'bytes'
        length = int(response.headers['Content-Length'])
    with anonymous.response('audio', public, item=first, headers={'Range': 'bytes=2-10'}) as response:
        assert response.status == 206 and response.headers['Content-Range'] == f'bytes 2-10/{length}'
        assert len(response.read()) == 9
    with anonymous.response('audio', public, item=first, headers={'Range': f'bytes={length}-'}) as response:
        assert response.status == 416 and response.headers['Content-Range'] == f'bytes */{length}'
    blocked(anonymous, public, '')
    blocked(anonymous, disabled, first)

    stale_revision = admin.call('state', public)['state']['revision']
    assert admin.call('control', public, {'event': 'pause'})['ok']
    assert admin.call('player', public, player_sample(player['player'], 'progress', first, 2, 20000,
                                                      observed_revision=stale_revision))['ok']
    transport = anonymous.call('state', public)['state']['transport']
    assert transport['status'] == 'paused' and transport['position_ms'] == 6500
    assert admin.call('control', public, {'event': 'resume'})['ok']
    play(admin, public, first, 3, 7600)
    assert admin.call('player', public, player_sample(player['player'], 'progress', first, 2, 1000))['ok']
    assert anonymous.call('state', public)['state']['transport']['position_ms'] == 7600

    assert admin.call('control', public, {'event': 'skip'})['ok']
    second = anonymous.call('state', public)['state']['current']['id']
    assert second != first
    blocked(anonymous, public, first)
    blocked(anonymous, public, second)
    play(admin, public, second, 4, 1000)
    blocked(anonymous, public, first)
    assert phone.call('join', public, {})['ok']
    phone_id = phone.call('state', public)['state']['phone']['id']
    assert admin.call('approve', public, {'phone': phone_id})['ok']
    with phone.response('audio', public, item=second, method='HEAD') as response:
        assert response.status == 200
    assert admin.call('revoke', public, {'phone': phone_id})['ok']
    blocked(phone, public, second)
    with anonymous.response('audio', public, item=second, method='HEAD') as response:
        assert response.status == 200
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('UPDATE jukebox_sessions SET heartbeat=0 WHERE id=?', (public,))
    assert anonymous.call('state', public)['state']['transport']['status'] == 'offline'
    blocked(anonymous, public, second)
    play(admin, public, second, 5, 1800)
    with anonymous.response('audio', public, item=second, method='HEAD') as response:
        assert response.status == 200
    assert admin.call('control', public, {'event': 'remote_listening', 'enabled': False})['ok']
    blocked(anonymous, public, second)
    assert admin.call('control', public, {'event': 'remote_listening', 'enabled': True})['ok']
    assert admin.call('control', public, {'event': 'end'})['ok']
    blocked(anonymous, public, second)

    assert venue.call('request', private, {'track': 'b', 'nonce': '1'*32})['ok']
    assert venue.call('player', private, player | {'event': 'claim'})['ok']
    private_item = venue.call('state', private)['state']['current']['id']
    play(venue, private, private_item, 1, 6500)
    blocked(anonymous, private, private_item)
    assert phone.call('join', private, {})['ok']
    phone_id = phone.call('state', private)['state']['phone']['id']
    blocked(phone, private, private_item)
    assert venue.call('approve', private, {'phone': phone_id})['ok']
    assert phone.call('state', private)['state']['remote_listening'] is True
    with phone.response('audio', private, item=private_item, headers={'Range': 'bytes=0-7'}) as response:
        assert response.status == 206 and response.read() == (site / 'storage/media/test.mp3').read_bytes()[:8]
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute("UPDATE media_links SET public_visible=0 WHERE entity_id='b' AND role='primary_audio'")
    blocked(phone, private, private_item)
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute("UPDATE media_links SET public_visible=1 WHERE entity_id='b' AND role='primary_audio'")
    with phone.response('audio', private, item=private_item, method='HEAD') as response:
        assert response.status == 200
    assert phone.call('request', private, {'track': 'a', 'nonce': '2'*32})['ok']
    assert venue.call('revoke', private, {'phone': phone_id})['ok']
    blocked(phone, private, private_item)
    print('PASS HTTP remote access, range delivery, transport sample ordering, and stale request rejection')


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
                http_scenario(base, site)
            else:
                with sync_playwright() as playwright:
                    phone_options = dict(viewport={'width': 390, 'height': 844}, is_mobile=True)
                    if BROWSER == 'webkit':
                        browser = playwright.webkit.launch()
                        phone_options = dict(playwright.devices.get('iPhone 13', phone_options))
                        phone_options.pop('default_browser_type', None)
                    else:
                        browser = playwright.chromium.launch(executable_path=CHROME, args=['--no-sandbox'])
                    if '--remote-only' not in sys.argv:
                        scenario(browser, base)
                        for context in browser.contexts:
                            context.close()
                    remote_scenario(browser, base, phone_options)
        except Exception:
            log.seek(0)
            print(log.read()[-16000:])
            raise
        finally:
            server.terminate()
            server.wait(timeout=5)
