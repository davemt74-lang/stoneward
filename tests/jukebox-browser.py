"""Scheduled sessions, venue pairing, shared playback, remote listening, chat, and recaps."""
import base64
import hashlib
from datetime import datetime, timedelta, timezone
import os
from pathlib import Path
import socket
import sys
import re
import json
from http.cookiejar import CookieJar
import sqlite3
import subprocess
import struct
import tempfile
import time
import urllib.request
import zlib
from zoneinfo import ZoneInfo

if '--http-only' not in sys.argv:
    from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SF_TEST_PHP', 'php')
CHROME = os.environ.get('CHROMIUM_BIN')
BROWSER = os.environ.get('SF_JUKEBOX_BROWSER', 'chromium')
PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='
SPLASH_ART = 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAGCAIAAABxZ0isAAAAEUlEQVR4nGNIFS3BihgGUgIAEWksoW5LtJgAAAAASUVORK5CYII='
TRACK_SECONDS = 45
MANAGED_AUDIO = 'M' + 'A'*20


def fixture(site):
    for directory in ('config', 'data', 'assets/audio', 'assets/js', 'storage/media', 'admin'):
        (site / directory).mkdir(parents=True)
    for directory in ('api', 'assets/css', 'assets/icons', 'assets/images', 'assets/vendor', 'admin/api', 'admin/assets'):
        (site / directory).symlink_to(ROOT / directory, target_is_directory=True)
    for file in ('jukebox.php', 'pwa-splash-preview.php', 'manifest.php', 'service-worker.js', 'offline.html',
                 'assets/js/jukebox.js', 'assets/js/jukebox-qr.js', 'assets/js/jukebox-listener.js',
                 'assets/js/pwa-shell.js', 'assets/js/pwa-phone-alerts.js'):
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
    (site / 'storage/media/waiting-room.png').write_bytes(base64.b64decode(SPLASH_ART))
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.executescript('''CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,
          role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT,password_hash TEXT);
          CREATE TABLE app_meta(meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL);
          INSERT INTO users VALUES(1,'admin@example.test','Admin','admin','active','','','',''),
          (2,'venue@example.test','Bartender','customer','active','','','',''),
          (3,'other@example.test','Other','customer','active','','','',''),
          (4,'inactive@example.test','Bartender','customer','disabled','','','',''),
          (5,'staff@example.test','Bartender','customer','active','','','','');''')
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
        sf_media_register_existing_file('storage/media/waiting-room.png', 'Waiting room artwork.png', 1);
    ''', MANAGED_AUDIO, str(TRACK_SECONDS)], cwd=site, check=True)
    (site / 'login-fixture.php').write_text("""<?php
        require __DIR__.'/api/bootstrap.php';
        sf_auth_issue_persistent_session((int)$_GET['user']);
        echo 'ok';
    """)
    # Mount the production editor with the Admin shell adapter, keeping the
    # real settings, CSRF, media picker and publication endpoints in use.
    (site / 'admin/splash-browser-fixture.html').write_text("""<!doctype html>
      <meta charset="utf-8">
      <link rel="stylesheet" href="assets/pwa-splash.css">
      <main></main>
      <script>
      let csrf;
      window.STONEFELLOW_ADMIN_CONTEXT = {
        canvas: document.querySelector('main'),
        head: () => '<h1>PWA &amp; Splash</h1>',
        async api(path, body) {
          const options = body === undefined ? {} : {method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify(body)};
          const response = await fetch('api/' + path, options);
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.message || 'Request failed');
          csrf = data.csrfToken || csrf;
          return data;
        }
      };
      </script>
      <script src="assets/admin-confirm.js"></script>
      <script src="assets/pwa-splash.js"></script>
      <script>window.SFPwaSplashAdmin.render();</script>
    """)
    # Symlinked PHP entry points resolve __DIR__ to the repo; the router fixes SF_ROOT
    # for fixture storage while leaving the production endpoint implementations intact.
    (site / 'router.php').write_text("""<?php
        define('SF_ROOT',__DIR__);
        $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
        if($path==='/manifest.webmanifest')$path='/manifest.php';
        if(str_ends_with($path,'.php') && is_file(__DIR__.$path)){require __DIR__.$path;return true;}
        return false;
    """)


def request(page, action, data=None, host=False, session=None, **params):
    return page.evaluate("""async ({action,data,host,session,params}) => {
      const url = new URL('api/jukebox.php', location.href);
      const current = new URLSearchParams(location.search);
      url.search = new URLSearchParams({action,id:session ?? current.get('id') ?? '',host:host?'1':'0',...params});
      const options = data === null ? {} : {method:'POST',body:JSON.stringify(data),
        headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=sf-csrf]').content}};
      const response = await fetch(url,options);
      return {status:response.status,...await response.json()};
    }""", dict(action=action, data=data, host=host, session=session, params=params))


def player_sample(player, event, item, sequence, position_ms, transport='playing', **extra):
    return dict(player=player, event=event, item=item, seconds=position_ms // 1000,
                position_ms=position_ms, sample_seq=sequence, transport=transport, **extra)


def listener_sample(player, item, generation, sequence, advanced_ms=0, active=False):
    return dict(player=player, item=str(item), generation=generation, seq=sequence,
                advanced_ms=advanced_ms, active=active)


def audience(active=0, unique=0, peak=0, listening_ms=0):
    return dict(active_players=active, unique_players=unique, peak_players=peak, listening_ms=listening_ms)


def rewind_listener_clock(site, session, ms):
    # Advance the isolated fixture's receipt interval without sleeping or
    # making the venue transport stale.
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('UPDATE jukebox_listener_players SET sampled_at_ms=sampled_at_ms-? WHERE session_id=?',
                   (ms, session))


def session_tab(page, name):
    tab = page.locator(f'#session-tabs [data-session-tab="{name}"]')
    expect(tab).to_be_visible()
    if tab.get_attribute('aria-selected') != 'true':
        tab.click()
    expect(tab).to_have_attribute('aria-selected', 'true')
    expect(page.locator(f'#session-panel-{name}')).to_be_visible()
    expect(page.locator('[id^="session-panel-"]:visible')).to_have_count(1)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
    return tab


def assert_playheads_synced(host, phone, tolerance=.75, item=None):
    read = '''audio => ({position: audio.currentTime, paused: audio.paused, seeking: audio.seeking,
      item: audio.currentSrc && new URL(audio.currentSrc).searchParams.get('item')})'''
    venue_audio = host.locator('#audio')
    phone_audio = phone.locator('#listener-audio')
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        venue = venue_audio.evaluate(read)
        guest = phone_audio.evaluate(read)
        if (venue['item'] and venue['item'] == guest['item'] and (item is None or venue['item'] == str(item))
                and not any(player['paused'] or player['seeking'] for player in (venue, guest))
                and abs(venue['position'] - guest['position']) <= tolerance):
            return
        time.sleep(.1)
    assert False, f'Host and phone playheads failed to converge within {tolerance}s: {venue}, {guest}'


def assert_host_player_layout(page):
    footer = page.locator('#footer')
    expect(footer).to_be_visible()
    for control in ('#audio', '#enable', '#volume', '#audio-start', '#player-toggle', '#player-skip'):
        expect(footer.locator(control)).to_have_count(1)
    expect(footer.locator('#end-session, #queue, #phones')).to_have_count(0)
    expect(page.locator('#session-tabs').get_by_role('tab')).to_have_count(6)
    overview = session_tab(page, 'overview')
    expect(page.locator('#session-panel-overview #host-now')).to_be_visible()
    expect(page.locator('#session-panel-overview #queue-label')).to_be_visible()
    overview.press('ArrowRight')
    phones = page.locator('#session-tab-phones')
    expect(phones).to_be_focused()
    expect(phones).to_have_attribute('aria-selected', 'true')
    phones.press('ArrowLeft')
    expect(overview).to_be_focused()
    expect(overview).to_have_attribute('aria-selected', 'true')
    page.locator('[data-session-open=phones]').click()
    expect(phones).to_be_focused()
    expect(phones).to_have_attribute('aria-selected', 'true')
    expect(page.locator('#session-panel-phones #phones-heading')).to_be_visible()
    expect(page.locator('#session-panel-phones #share-heading')).to_be_visible()
    session_tab(page, 'chat')
    expect(page.locator('#session-panel-chat #chat-empty')).to_be_visible()
    session_tab(page, 'history')
    expect(page.locator('#session-panel-history #history-heading')).to_be_visible()
    session_tab(page, 'settings')
    expect(page.locator('#session-panel-settings #end-session')).to_be_visible()
    session_tab(page, 'music')
    expect(page.locator('#catalog li button').last).to_be_visible()
    viewport = page.viewport_size
    page.set_viewport_size({'width': viewport['width'], 'height': 340})
    assert page.evaluate('''() => {
      const footer = document.querySelector('#footer');
      const docked = () => Math.abs(footer.getBoundingClientRect().bottom - innerHeight) <= 2;
      scrollTo(0, 0);
      const top = docked();
      scrollTo(0, document.documentElement.scrollHeight);
      const lastAction = document.querySelector('#catalog li:last-child button').getBoundingClientRect();
      return top && docked() && scrollY > 0 && lastAction.top >= 0 &&
        lastAction.bottom <= footer.getBoundingClientRect().top &&
        document.documentElement.scrollWidth <= innerWidth + 1;
    }''')
    page.set_viewport_size(viewport)
    session_tab(page, 'overview')
    page.evaluate('scrollTo(0, 0)')


def scenario(browser, base, phone_options):
    def signed_in(user, **options):
        page = browser.new_context(**options).new_page()
        page.goto(base + f'/login-fixture.php?user={user}')
        page.goto(base + '/jukebox.php?host=1')
        return page

    guest = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True).new_page()
    guest.goto(base + '/jukebox.php')
    expect(guest.get_by_role('heading', name='Live Sessions', exact=True)).to_be_visible()
    expect(guest.locator('#session-empty')).to_be_visible()
    expect(guest.locator('#upcoming-empty')).to_be_visible()
    assert guest.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
    guest_profile = guest.locator('#jukebox-profile')
    guest_summary = guest_profile.locator('summary')
    expect(guest_summary).to_contain_text('Guest')
    expect(guest_profile.get_by_role('link', name='Sign in')).to_be_hidden()
    guest_summary.click()
    expect(guest_profile.get_by_role('link', name='Sign in', exact=True)).to_have_attribute('href', re.compile(r'\?view=login$'))
    expect(guest_profile.get_by_role('link', name='Create account')).to_have_attribute('href', re.compile(r'\?view=register$'))
    guest_summary.focus()
    guest.keyboard.press('Escape')
    expect(guest_profile.get_by_role('link', name='Sign in')).to_be_hidden()
    expect(guest_summary).to_be_focused()
    expect(guest.locator('#open-create')).to_be_hidden()
    guest.context.close()

    admin = signed_in(1)
    expect(admin.locator('#session-empty')).to_be_visible()
    profile = admin.locator('#jukebox-profile')
    expect(profile.locator('summary')).to_contain_text('Admin')
    profile.locator('summary').click()
    expect(profile.get_by_role('link', name='My Library')).to_have_attribute('href', re.compile(r'\?view=library$'))
    profile.locator('summary').click()
    enable_venue = admin.locator('#open-venue')
    venue_dialog = admin.locator('#venue-dialog')
    expect(admin.locator('#venue')).to_be_hidden()
    enable_venue.click()
    expect(venue_dialog).to_be_visible()
    assert venue_dialog.evaluate("dialog => dialog.matches(':modal')")
    owner = venue_dialog.locator('#venue-owner')
    venue_name = venue_dialog.get_by_label('Venue name')
    save_venue = venue_dialog.get_by_role('button', name='Save venue')
    expect(owner).to_have_value('1')
    expect(venue_name).to_be_focused()
    venue_name.press_sequentially('desertRIO')
    save_venue.click()
    expect(venue_dialog).to_be_hidden()
    expect(enable_venue).to_be_focused()
    assert request(admin, 'directory', host=True)['venue'] == 'desertRIO'

    mobile_admin = signed_in(1, **phone_options)
    mobile_admin.locator('#open-venue').click()
    mobile_venue = mobile_admin.locator('#venue-dialog')
    expect(mobile_venue.locator('#venue-owner')).to_have_value('1')
    expect(mobile_venue.get_by_label('Venue name')).to_be_focused()
    mobile_venue.get_by_label('Venue name').fill('Mobile venue')
    mobile_venue.get_by_role('button', name='Cancel', exact=True).click()
    expect(mobile_venue).to_be_hidden()
    mobile_admin.context.close()

    enable_venue.click()
    expect(owner).to_have_value('1')
    venue_dialog.get_by_text('Find another account', exact=True).click()
    search = venue_dialog.get_by_label('Name or email')
    search.fill('NoSuchStonefellow')
    expect(owner).to_have_value('')
    search.press('Enter')
    expect(venue_dialog.locator('#venue-search-status')).to_contain_text(re.compile(r'no .*accounts?', re.I))
    expect(venue_dialog).to_be_visible()
    assert not owner.evaluate('select => select.validity.valid')
    search.fill('Bartender')
    venue_dialog.get_by_role('button', name='Search', exact=True).click()
    expect(owner.locator('option[value="2"]')).to_contain_text('venue@example.test')
    expect(owner.locator('option[value="5"]')).to_contain_text('staff@example.test')
    expect(owner.locator('option[value="4"]')).to_have_count(0)
    expect(owner).to_have_value('')
    owner.select_option('2')
    venue_name.fill('Test Bar')
    save_venue.click()
    expect(venue_dialog).to_be_hidden()
    expect(enable_venue).to_be_focused()
    assert request(admin, 'directory', host=True)['venue'] == 'desertRIO'
    universal = request(admin, 'create', {'title': 'Universal', 'listed': True}, True)['id']
    venue = signed_in(2)
    expect(venue.locator('#open-create')).to_be_visible()
    expect(venue.locator('#open-venue')).to_be_hidden()
    assert request(venue, 'directory', host=True)['venue'] == 'Test Bar'
    new_session = venue.locator('#open-create')
    create_dialog = venue.locator('#create-dialog')
    expect(venue.locator('#create')).to_be_hidden()
    new_session.click()
    expect(create_dialog).to_be_visible()
    assert create_dialog.evaluate("dialog => dialog.matches(':modal')")
    expect(create_dialog.get_by_label('Session name')).to_be_focused()
    expect(create_dialog.get_by_label('Public universal session')).to_be_hidden()
    venue.keyboard.press('Escape')
    expect(create_dialog).to_be_hidden()
    expect(new_session).to_be_focused()
    new_session.click()
    create_dialog.get_by_role('button', name='Cancel', exact=True).click()
    expect(create_dialog).to_be_hidden()
    expect(new_session).to_be_focused()
    assert request(venue, 'directory', host=True)['sessions'] == []
    new_session.click()
    venue.locator('#create input[name=title]').fill('Tonight at Test Bar')
    venue.locator('#create').get_by_label('Allow remote listening').check()
    create_dialog.get_by_role('button', name='Create session', exact=True).click()
    expect(venue.get_by_role('heading', name='Tonight at Test Bar')).to_be_visible()
    session = venue.url.split('id=')[1].split('&')[0]
    assert session != universal
    assert_host_player_layout(venue)
    expect(venue.locator('#queue-empty')).to_be_visible()
    expect(venue.locator('#player-toggle, #player-skip').filter(visible=True)).to_have_count(0)
    mobile_host = signed_in(2, **phone_options)
    mobile_host.goto(venue.url)
    assert_host_player_layout(mobile_host)
    mobile_host.context.close()
    session_tab(venue, 'phones')
    expect(venue.locator('#qr svg')).to_be_visible()
    join = venue.locator('#join-link').get_attribute('href')
    first = browser.new_context(**phone_options).new_page()
    second = browser.new_context(**phone_options).new_page()
    for phone in (first, second):
        phone.goto(join)
    expect(first.locator('#listen')).to_be_hidden()
    assert request(first, 'request', {'track': 'a', 'nonce': '1'*32})['status'] == 409
    session_tab(first, 'overview')
    first.locator('#join input[name=name]').fill('Dave')
    first.locator('#join input[name=remember]').check()
    first.get_by_role('button', name='Request access').click()
    venue.locator('#phones li').filter(has_text='Dave').get_by_role('button', name='Approve').click()
    session_tab(first, 'music')
    expect(first.locator('#session-tabs').get_by_role('tab')).to_have_count(3)
    first.get_by_role('button', name='Albums').click()
    first.get_by_role('button', name='The Record').click()
    expect(first.locator('#catalog')).to_contain_text('First Song')
    first.locator('#catalog li').filter(has_text='First Song').get_by_role('button', name='Play').click()
    footer = venue.locator('#footer')
    footer.get_by_role('button', name='Enable player on this device').click()
    session_tab(venue, 'history')
    venue.locator('#history li, #audio-start:visible').first.wait_for()
    if venue.locator('#audio-start').is_visible():
        venue.locator('#audio-start').click()
    expect(venue.locator('#history')).to_contain_text('First Song')
    expect(venue.locator('#host-now')).to_have_text('First Song')
    expect(footer.locator('#now')).to_have_text('First Song')
    expect(first.locator('#now')).to_have_text('First Song')
    assert first.evaluate("() => !document.querySelector('#audio[src]')")
    expect(first.locator('#player-toggle, #player-skip').filter(visible=True)).to_have_count(0)
    footer.locator('#volume').fill('0.45')
    assert footer.locator('#audio').evaluate('(audio) => Math.abs(audio.volume - .45) < .01')
    listen = first.get_by_role('button', name='Stream on this phone', exact=True)
    expect(listen).to_be_visible()
    assert listen.evaluate('''button => {
      const player = button.closest('footer').getBoundingClientRect();
      const gap = player.right - button.getBoundingClientRect().right;
      return gap >= 0 && gap <= 36;
    }''')
    assert first.locator('#listener-audio').evaluate('(audio) => audio.paused && !audio.currentSrc')
    first.wait_for_function("""async () => {
      const id = new URLSearchParams(location.search).get('id');
      const {state} = await (await fetch('api/jukebox.php?action=state&id=' + id)).json();
      return state.transport?.status === 'playing' && state.transport.position_ms > 0;
    }""", polling=250)
    session_tab(venue, 'phones')
    listeners = venue.locator('#remote-listener-count')
    expect(listeners).to_have_text('0 phones listening now')
    assert request(venue, 'state', host=True)['state']['listener_metrics'] == audience()
    assert 'listener_metrics' not in request(first, 'state')['state']
    expect(first.locator('#remote-listener-count')).to_be_hidden()
    listen.click()
    first.wait_for_function("!document.querySelector('#listener-audio').paused")
    assert_playheads_synced(venue, first)
    # Deliberately give the phone the kind of small playhead lead the former
    # two-second correction threshold could allow to persist indefinitely.
    venue_position = footer.locator('#audio').evaluate('(audio) => audio.currentTime')
    first.locator('#listener-audio').evaluate('(audio, position) => { audio.currentTime = position; }',
                                              venue_position + 1.2)
    assert_playheads_synced(venue, first, tolerance=.55)
    expect(listeners).to_have_text('1 phone listening now', timeout=15_000)
    metrics = request(venue, 'state', host=True)['state']['listener_metrics']
    assert metrics['unique_players'] == metrics['peak_players'] == 1 and metrics['listening_ms'] > 0
    first.get_by_role('button', name='Stop listening').click()
    expect(listen).to_be_visible()
    expect(listeners).to_have_text('0 phones listening now')
    assert request(venue, 'state', host=True)['state']['transport']['status'] == 'playing'
    listen.click()
    session_tab(second, 'overview')
    second.locator('#join input[name=name]').fill('Jamie')
    second.get_by_role('button', name='Request access').click()
    next_approval = venue.locator('#phones li').first
    expect(next_approval).to_contain_text('pending')
    next_approval.get_by_role('button', name='Approve').click()
    expect(second.locator('#now')).to_have_text('First Song')
    session_tab(second, 'music')
    second.locator('#catalog li').filter(has_text='Second Song').get_by_role('button', name='Add to Queue').click()
    expect(first.locator('#queue-label')).to_have_text('Up Next (1)')
    queued = {'track': 'b', 'nonce': '2'*32}
    assert request(first, 'request', queued)['ok'] and request(first, 'request', queued)['ok']
    expect(second.locator('#queue-label')).to_have_text('Up Next (2)')
    session_tab(venue, 'overview')
    expect(venue.locator('main #queue-label')).to_have_text('Up Next (2)')
    expect(venue.locator('#queue-empty')).to_be_hidden()
    order = [row['id'] for row in request(venue, 'state', host=True)['state']['queue']]
    queue = venue.locator('main #queue')
    move_up = queue.get_by_role('button', name='Move up', exact=True)
    expect(move_up.first).to_be_disabled()
    with venue.expect_response(lambda response: 'action=control' in response.url and
                               response.request.method == 'POST') as moved:
        move_up.nth(1).click()
    assert moved.value.json()['ok']
    assert [row['id'] for row in request(venue, 'state', host=True)['state']['queue']] == order[::-1]

    # Unread updates arrive while the host remains in Overview and only clear
    # for the conversation they open. Opening another tab never opens a thread.
    for phone, question in ((first, 'Can you hear this?'), (second, 'Song request question')):
        session_tab(phone, 'chat')
        phone.locator('#send input[name=text]').fill(question)
        phone.get_by_role('button', name='Send', exact=True).click()
        expect(phone.locator('#messages')).to_contain_text(question)
        expect(phone.locator('#chat-unread')).to_be_hidden()
        session_tab(phone, 'music')
    expect(venue.locator('#chat-unread')).to_have_text('2')
    expect(venue.locator('#chat-unread')).to_be_visible()
    expect(venue.locator('#session-tab-overview')).to_have_attribute('aria-selected', 'true')
    session_tab(venue, 'phones')
    venue.locator('#phones li').filter(has_text='Dave').get_by_role('button', name='Chat').click()
    expect(venue.locator('#chat-title')).to_be_focused()
    expect(venue.locator('#messages')).to_contain_text('Can you hear this?')
    expect(venue.locator('#chat-unread')).to_have_text('1')
    expect(venue.locator('#chat-unread')).to_be_visible()
    venue.locator('#send input[name=text]').fill('Private hello')
    venue.locator('#send input[name=image]').set_input_files(
        {'name': 'guest.png', 'mimeType': 'image/png', 'buffer': base64.b64decode(PNG)})
    venue.get_by_role('button', name='Send').click()
    expect(first.locator('#chat-unread')).to_have_text('1')
    expect(first.locator('#chat-unread')).to_be_visible()
    expect(first.locator('#session-tab-music')).to_have_attribute('aria-selected', 'true')
    expect(second.locator('#chat-unread')).to_be_hidden()
    session_tab(first, 'chat')
    expect(first.locator('#messages')).to_contain_text('Private hello')
    expect(first.locator('#messages img')).to_be_visible()
    first.wait_for_function("document.querySelector('#messages img').naturalWidth === 1")
    expect(first.locator('#chat-unread')).to_be_hidden()
    assert 'Private hello' not in str(request(second, 'thread'))
    venue.locator('#chat-threads').get_by_role('button', name=re.compile('Jamie')).click()
    expect(venue.locator('#messages')).to_contain_text('Song request question')
    expect(venue.locator('#chat-unread')).to_be_hidden()
    expect(venue.locator('#messages')).not_to_contain_text('Private hello')
    session_tab(venue, 'phones')
    expect(listeners).to_have_text('1 phone listening now', timeout=15_000)
    transport = footer.locator('#player-toggle')
    expect(transport).to_have_accessible_name('Pause')
    transport.focus()
    transport.press('Space')
    expect(first.locator('#progress')).to_contain_text('Paused')
    first.wait_for_function("document.querySelector('#listener-audio').paused")
    expect(transport).to_have_accessible_name('Resume')
    expect(transport).to_be_focused()
    assert request(first, 'state')['state']['transport']['status'] == 'paused'
    expect(listeners).to_have_text('0 phones listening now')
    expect(transport).to_be_enabled()
    transport.press('Space')
    expect(transport).to_have_accessible_name('Pause')
    expect(transport).to_be_focused()
    assert_playheads_synced(venue, first)
    expect(venue.locator('#queue-label')).to_have_text('Up Next (2)')
    next_item = request(venue, 'state', host=True)['state']['queue'][0]['id']
    footer.get_by_role('button', name='Skip', exact=True).click()
    expect(second.locator('#now')).to_have_text('Second Song')
    assert_playheads_synced(venue, first, item=next_item)
    venue.reload()
    expect(venue.locator('#queue-label')).to_have_text('Up Next (1)')
    session_tab(venue, 'phones')
    venue.locator('#phones li').filter(has_text='Dave').get_by_role('button', name='Revoke').click()
    session_tab(first, 'overview')
    expect(first.locator('#approval')).to_contain_text('closed')
    assert request(first, 'thread')['status'] == 409
    expect(first.locator('#chat-unread')).to_be_hidden()
    another = request(venue, 'create', {'title': 'Next night'}, True)['id']
    first.goto(base + '/jukebox.php?id=' + another)
    first.get_by_role('button', name='Request access').click()
    venue.goto(base + '/jukebox.php?host=1&id=' + another)
    session_tab(venue, 'phones')
    returning = venue.locator('#phones li').filter(has_text='Returning guest')
    expect(returning).to_contain_text('Dave')
    returning.get_by_role('button', name='Approve').click()
    session_tab(venue, 'history')
    venue.get_by_text('Venue guests and visit history').click()
    expect(venue.locator('#guests')).to_contain_text('2 visits')
    assert request(venue, 'state', host=True)['state']['queue'] == []
    public = browser.new_context().new_page()
    public.goto(base + '/jukebox.php')
    expect(public.get_by_role('link', name='Universal')).to_be_visible()
    expect(public.get_by_role('link', name='Next night')).to_have_count(0)
    other = signed_in(3)
    expect(other.locator('#session-empty')).to_be_visible()
    expect(other.locator('#open-create')).to_be_hidden()
    expect(other.locator('#open-venue')).to_be_hidden()
    assert request(other, 'state', host=True, session=another)['status'] == 404
    venue.goto(base + '/jukebox.php?host=1&id=' + session)
    expect(venue.locator('#recap')).to_be_hidden()
    session_tab(venue, 'settings')
    venue.once('dialog', lambda dialog: dialog.accept())
    venue.locator('main #end-session').click()
    expect(venue.get_by_role('heading', name='Session recap')).to_be_visible()
    expect(footer).to_be_hidden()
    expect(venue.locator('#session-tabs')).to_be_hidden()
    expect(venue.locator('#recap-totals')).not_to_be_empty()
    expect(venue.locator('#recap-tracks')).to_contain_text('First Song')
    expect(venue.locator('#recap-tracks')).to_contain_text('Second Song')
    recap_audience = venue.locator('#recap-listener-totals')
    expect(recap_audience).to_be_visible()
    for label in ('Distinct phone players', 'Peak simultaneous players'):
        expect(recap_audience.locator('div').filter(has_text=label).locator('dd')).to_have_text('1')
    elapsed = recap_audience.locator('div').filter(has_text='Total phone listening time').locator('dd')
    expect(elapsed).to_contain_text('sec')
    expect(elapsed).not_to_have_text('0 sec')
    recap = request(venue, 'recap', host=True)['recap']['listener_metrics']
    assert recap['unique_players'] == recap['peak_players'] == 1
    assert recap['active_players'] == 0 and recap['listening_ms'] >= metrics['listening_ms']
    venue.goto(base + '/jukebox.php?host=1')
    venue.locator('[data-directory-scope=archive]').click()
    venue.get_by_role('link', name='Tonight at Test Bar (ended)', exact=True).click()
    expect(venue.get_by_role('heading', name='Session recap')).to_be_visible()
    expect(venue.locator('#recap-tracks')).to_contain_text('First Song')
    second.reload()
    expect(second.locator('#recap')).to_be_hidden()
    print('PASS host and phone tabs, private unread chat, synchronized native players, queue controls, pairing, and recap')


def remote_scenario(browser, base, phone_options):
    host = browser.new_context().new_page()
    host.goto(base + '/login-fixture.php?user=1')
    host.goto(base + '/jukebox.php?host=1')
    host.locator('#open-create').click()
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
    session_id = urllib.parse.parse_qs(urllib.parse.urlsplit(phone.url).query)['id'][0]
    profile = phone.locator('#jukebox-profile')
    profile.locator('summary').click()
    expect(profile.get_by_role('link', name='Sign in', exact=True)).to_have_attribute(
        'href', re.compile(r'\?view=login#return_jukebox=' + re.escape(session_id) + '$'))
    profile.locator('summary').click()
    listen = phone.get_by_role('button', name='Stream on this phone', exact=True)
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
    mute = phone.locator('#listener-mute')
    press_mute = mute.tap if phone_options.get('has_touch') else mute.click
    expect(mute).to_have_attribute('aria-pressed', 'false')
    press_mute()
    expect(mute).to_have_attribute('aria-pressed', 'true')
    phone.wait_for_function("document.querySelector('#listener-audio').muted")
    press_mute()
    expect(mute).to_have_attribute('aria-pressed', 'false')
    phone.wait_for_function("!document.querySelector('#listener-audio').muted")
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
    session_tab(host, 'settings')
    host.locator('#remote-listening').uncheck()
    expect(listen).to_be_hidden()
    phone.wait_for_function("document.querySelector('#listener-audio').paused")
    print('PASS anonymous phone listening, mid-song join, venue pause and skip, outage recovery, and local stop')


def recovery_scenario(browser, base, phone_options, site):
    # Fault injection must reach fetch: a controlling worker can otherwise
    # bypass page.route(). PWA/worker acceptance stays enabled in its own cases.
    host_context = browser.new_context(service_workers='block')
    phone_context = browser.new_context(service_workers='block', **phone_options)
    host = host_context.new_page()
    phone = phone_context.new_page()
    host.goto(base + '/login-fixture.php?user=1')
    host.goto(base + '/jukebox.php?host=1')
    session = request(host, 'create', {'title': 'Connection recovery test', 'listed': True,
                                     'remote_listening': True}, True)['id']
    host.goto(base + '/jukebox.php?host=1&id=' + session)
    for index in range(6):
        assert request(host, 'request', {'track': 'a' if index % 2 == 0 else 'b',
                                      'nonce': f'{index + 100:032x}'}, True)['ok']
    host.locator('#enable').click()
    session_tab(host, 'history')
    host.locator('#history li, #audio-start:visible').first.wait_for()
    if host.locator('#audio-start').is_visible():
        host.locator('#audio-start').click()
    host.wait_for_function("!document.querySelector('#audio').paused")
    phone.goto(base + '/jukebox.php?id=' + session)
    assert request(phone, 'join', {'name': 'Recovery phone'})['ok']
    phone_id = request(phone, 'state')['state']['phone']['id']
    assert request(host, 'approve', {'phone': phone_id}, True)['ok']
    assert request(phone, 'message', {'text': 'Keep the recovery conversation'})['ok']

    audio = phone.locator('#listener-audio')
    connection = phone.locator('#listener-connection')
    resync = phone.locator('#listener-resync')
    listen = phone.locator('#listen')

    def press(control):
        (control.tap if phone_options.get('has_touch') else control.click)()

    def media_empty(page, selector):
        page.wait_for_function("""selector => {
          const audio = document.querySelector(selector);
          return audio.paused && !audio.hasAttribute('src') && audio.networkState === 0;
        }""", arg=selector)

    def wait_native():
        # The real host supplies production progress reports throughout waits.
        # Require advancing native phone audio, not just a resolved play promise.
        deadline, previous = time.monotonic() + 15, None
        read = """audio => ({position:audio.currentTime, paused:audio.paused,
          seeking:audio.seeking, item:audio.currentSrc && new URL(audio.currentSrc).searchParams.get('item')})"""
        while time.monotonic() < deadline:
            main = host.locator('#audio').evaluate(read)
            remote = audio.evaluate(read)
            advancing = previous is not None and remote['position'] > previous + .02
            if (main['item'] and main['item'] == remote['item'] and advancing
                    and not any(value['paused'] or value['seeking'] for value in (main, remote))
                    and abs(main['position'] - remote['position']) <= .9):
                expect(connection).to_have_attribute('data-connection', 'listening')
                return
            previous = remote['position']
            phone.wait_for_timeout(200)
        assert False, f'Recovery did not restore advancing synchronized audio: {main}, {remote}'

    def restore_lifecycle():
        phone.evaluate("""() => {
          document.dispatchEvent(new Event('visibilitychange'));
          const restored = new Event('pageshow');
          Object.defineProperty(restored, 'persisted', {value:true});
          dispatchEvent(restored);
        }""")

    def action_route(action, exclude_probe=False):
        def matches(url):
            parsed = urllib.parse.urlsplit(url)
            query = urllib.parse.parse_qs(parsed.query)
            return (parsed.path.endswith('/api/jukebox.php') and query.get('action') == [action]
                    and (not exclude_probe or query.get('recovery_probe') != ['1']))
        return matches

    expect(listen).to_be_visible()
    assert audio.evaluate('audio => audio.paused && !audio.hasAttribute("src")')
    press(listen)
    wait_native()
    # Keep this short so normal track completion cannot be confused with a
    # Resync mutation. Long waits still have six real queued tracks available.
    if host.locator('#audio').evaluate('audio => audio.currentTime') > TRACK_SECONDS - 25:
        assert request(host, 'control', {'event': 'skip'}, True)['ok']
        wait_native()
    before = request(host, 'state', host=True)['state']
    press(phone.locator('#listener-mute'))
    expect(phone.locator('#listener-mute')).to_have_attribute('aria-pressed', 'true')
    assert audio.evaluate('audio => audio.muted')
    audio.evaluate('audio => { audio.currentTime = Math.max(0, audio.currentTime - 3); }')
    press(resync)
    wait_native()
    assert audio.evaluate('audio => audio.muted')
    expect(phone.locator('#listener-mute')).to_have_attribute('aria-pressed', 'true')
    after = request(host, 'state', host=True)['state']
    for field in ('current', 'queue', 'phones', 'history'):
        assert after[field] == before[field], field
    assert request(host, 'thread', host=True, phone=phone_id, full=1)['messages'][0]['body'] == 'Keep the recovery conversation'
    press(phone.locator('#listener-mute'))
    expect(phone.locator('#listener-mute')).to_have_attribute('aria-pressed', 'false')
    assert audio.evaluate('audio => !audio.muted')

    assert request(host, 'control', {'event': 'pause'}, True)['ok']
    expect(connection).to_have_attribute('data-connection', 'paused', timeout=10_000)
    assert audio.evaluate('audio => audio.paused')
    assert request(host, 'control', {'event': 'resume'}, True)['ok']
    wait_native()

    # Disconnect the host from its state/report endpoints, then make its last
    # heartbeat absent. The phone remains site-connected but waits for the host.
    host_lost = []
    host_state, host_player = action_route('state'), action_route('player')
    def abort_host(route):
        host_lost.append(route.request.url)
        route.abort()
    host.route(host_state, abort_host)
    host.route(host_player, abort_host)
    deadline = time.monotonic() + 8
    while not host_lost and time.monotonic() < deadline:
        host.wait_for_timeout(50)
    assert host_lost, 'Host outage did not intercept a state or player request'
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('UPDATE jukebox_sessions SET heartbeat=0 WHERE id=?', (session,))
    expect(connection).to_have_attribute('data-connection', 'waiting', timeout=10_000)
    expect(phone.locator('#listener-status')).to_contain_text('session player')
    assert audio.evaluate('audio => audio.paused')
    host.unroute(host_state)
    host.unroute(host_player)
    host.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    wait_native()

    phone_context.set_offline(True)
    expect(connection).to_have_attribute('data-connection', 'reconnecting', timeout=10_000)
    expect(phone.locator('#connection-badge')).to_have_attribute('data-connection', 'offline')
    assert audio.evaluate('audio => audio.paused')
    phone_context.set_offline(False)
    wait_native()

    state_route = action_route('state', exclude_probe=True)
    outage_hits = []
    def abort_state(route):
        outage_hits.append(route.request.url)
        route.abort()
    phone.route(state_route, abort_state)
    expect(connection).to_have_attribute('data-connection', 'reconnecting', timeout=10_000)
    assert outage_hits, 'API outage did not intercept a state request'
    assert audio.evaluate('audio => audio.paused')
    phone.unroute(state_route)
    wait_native()

    # Simulate a background/native pause, then exercise both visibility return
    # and persisted pageshow. This remains a browser test, not a hardware lock.
    phone.evaluate("""() => {
      Object.defineProperty(document, 'hidden', {configurable:true, get:() => true});
      document.dispatchEvent(new Event('visibilitychange'));
      document.querySelector('#listener-audio').pause();
      Object.defineProperty(document, 'hidden', {configurable:true, get:() => false});
    }""")
    restore_lifecycle()
    wait_native()
    press(phone.locator('#listener-stop'))
    media_empty(phone, '#listener-audio')
    phone_context.set_offline(True)
    phone_context.set_offline(False)
    restore_lifecycle()
    expect(connection).to_have_attribute('data-connection', 'idle', timeout=10_000)
    phone.wait_for_timeout(1600)
    media_empty(phone, '#listener-audio')
    expect(resync).to_be_hidden()
    expect(listen).to_be_visible()
    press(listen)
    wait_native()

    # A response begun before Resync must not authorize playback after it.
    # Hold the newer request too, proving no interim playing event occurs.
    old_state = request(phone, 'state', recovery_probe='1')
    pending = []
    phone.route(state_route, lambda route: pending.append(route))
    deadline = time.monotonic() + 5
    while not pending and time.monotonic() < deadline:
        phone.wait_for_timeout(50)
    assert pending, 'No pre-Resync state request was captured'
    phone.evaluate("""() => {
      window.recoveryPlayingEvents = 0;
      document.querySelector('#listener-audio').addEventListener('playing', () => window.recoveryPlayingEvents++);
    }""")
    press(resync)
    assert audio.evaluate('audio => audio.paused')
    assert request(host, 'control', {'event': 'remote_listening', 'enabled': False}, True)['ok']
    old = pending.pop(0)
    old.fulfill(status=200, content_type='application/json', body=json.dumps(old_state))
    deadline = time.monotonic() + 4
    while not pending and time.monotonic() < deadline:
        phone.wait_for_timeout(50)
    assert pending, 'Recovery did not request a newer authorized state'
    phone.wait_for_timeout(200)
    assert audio.evaluate('audio => audio.paused')
    assert phone.evaluate('window.recoveryPlayingEvents') == 0
    denied = request(phone, 'state', recovery_probe='1')
    assert denied['state']['remote_listening'] is False
    for route in pending:
        route.fulfill(status=200, content_type='application/json', body=json.dumps(denied))
    pending.clear()
    phone.unroute(state_route)
    expect(phone.locator('#listener')).to_be_hidden()
    media_empty(phone, '#listener-audio')
    assert phone.evaluate('window.recoveryPlayingEvents') == 0

    # A denied host report after a successful state200 must also terminate the
    # actual native host player, not only state-fetch authentication failures.
    denied_reports = []
    def deny_report(route):
        denied_reports.append(route.request.url)
        route.fulfill(status=403, content_type='application/json',
                      body=json.dumps({'ok': False, 'message': 'Recovery fixture access closed'}))
    host.route(host_player, deny_report)
    expect(host.locator('#connection-badge')).to_have_attribute('data-connection', 'unavailable', timeout=10_000)
    assert denied_reports, 'Host report denial was not intercepted'
    media_empty(host, '#audio')
    host.evaluate("""() => {
      dispatchEvent(new Event('online'));
      document.dispatchEvent(new Event('visibilitychange'));
      const event = new Event('pageshow'); Object.defineProperty(event, 'persisted', {value:true}); dispatchEvent(event);
    }""")
    host.wait_for_timeout(1600)
    media_empty(host, '#audio')
    expect(host.locator('#connection-badge')).to_have_attribute('data-connection', 'unavailable')
    host.unroute(host_player)
    phone_context.close()
    host_context.close()
    print('PASS real host/phone recovery, fresh Resync, mute and Stop preservation, lifecycle, stale authorization, and host report denial')



def branding_scenario(browser, base, phone_options, site):
    paths = [site / 'data/catalog.json', site / 'data/releases.json']
    originals = [path.read_text() for path in paths]
    tracks = json.loads(originals[0])
    tracks[0]['title'] = 'Zebra constellation — a long song title that should wrap without moving its action'
    tracks[1]['title'] = 'Amber horizon — another long song title with a clear action at the far right'
    releases = json.loads(originals[1])
    releases[0]['title'] = 'The Record — an album title long enough to wrap cleanly on a small phone'
    for path, value in zip(paths, (tracks, releases)):
        path.write_text(json.dumps(value))
    try:
        _branding_browser(browser, base, phone_options, site, tracks, releases[0]['title'])
    finally:
        for path, original in zip(paths, originals):
            path.write_text(original)


def _branding_browser(browser, base, phone_options, site, tracks, release_title):
    host_context = browser.new_context(service_workers='block')
    host = host_context.new_page()
    host.goto(base + '/login-fixture.php?user=1')
    host.goto(base + '/jukebox.php?host=1')
    phone_context = browser.new_context(service_workers='block', **phone_options)
    phone = phone_context.new_page()
    other_context = browser.new_context(service_workers='block')
    other = other_context.new_page()
    other.goto(base + '/login-fixture.php?user=2')
    other.goto(base + '/jukebox.php?host=1')
    assert request(host, 'venue', {'owner': 2, 'name': 'Other logo host'}, True)['ok']

    def press(page, control):
        (control.tap if page is phone and phone_options.get('has_touch') else control.click)()

    def capture(page, label):
        directory = os.environ.get('SF_JUKEBOX_SCREENSHOT_DIR')
        if directory:
            target = Path(directory).resolve()
            target.mkdir(parents=True, exist_ok=True)
            page.screenshot(path=str(target / (label + '.png')))

    def upload(page, encoded, width):
        opener = page.locator('#open-branding')
        expect(opener).to_be_visible()
        press(page, opener)
        dialog = page.locator('#branding-dialog')
        expect(dialog).to_be_visible()
        assert dialog.evaluate('dialog => dialog.matches(":modal")')
        expect(dialog.locator('#branding-file')).to_be_enabled()
        dialog.locator('#branding-file').set_input_files({
            'name': 'host-logo.png', 'mimeType': 'image/png', 'buffer': base64.b64decode(encoded)})
        expect(dialog.locator('#branding-preview')).to_be_visible()
        page.wait_for_function("""width => {
          const img = document.querySelector('#branding-preview');
          return img.complete && img.naturalWidth === width;
        }""", arg=width)
        return opener, dialog

    def assert_logo(page, width):
        expect(page.locator('#host-logo')).to_be_visible()
        page.wait_for_function("""width => {
          const image = document.querySelector('#host-logo');
          return image.complete && image.naturalWidth === width;
        }""", arg=width)
        assert page.locator('#host-logo').evaluate(
            'img => getComputedStyle(img).objectFit === "contain"')

    expect(host.locator('#open-branding')).to_be_visible()
    host.set_viewport_size({'width': 1440, 'height': 960})
    opener, dialog = upload(host, PNG, 1)
    capture(host, 'branding-upload-1440')
    host.keyboard.press('Escape')
    expect(dialog).to_be_hidden()
    expect(opener).to_be_focused()
    # An unsaved preview must not replace the saved public logo.
    assert request(host, 'branding', host=True)['branding']['logo_url'] is None
    opener, dialog = upload(host, PNG, 1)
    dialog.locator('#branding-save').click()
    expect(dialog).to_be_hidden()
    expect(opener).to_be_focused()
    assert_logo(host, 1)
    directory_logo = host.locator('#host-logo').get_attribute('src')
    host.reload()
    assert_logo(host, 1)
    assert host.locator('#host-logo').get_attribute('src') == directory_logo
    expect(other.locator('#host-logo')).to_be_hidden()

    other_session = request(other, 'create', {'title': 'Another host session'}, True)['id']
    observer = host_context.new_page()
    observer.goto(base + '/jukebox.php?host=1&id=' + other_session)
    session_tab(observer, 'settings')
    expect(observer.locator('#open-branding')).to_be_hidden()
    expect(observer.locator('#host-logo')).to_be_hidden()
    assert request(observer, 'state', host=True)['state']['branding_editable'] is False
    observer.close()

    session = request(host, 'create', {'title': 'Logo and catalog layout', 'listed': True,
                                      'remote_listening': True}, True)['id']
    upcoming = request(host, 'create', {'title': 'Upcoming host logo', 'listed': True,
                                       'scheduled_at': int(time.time()) + 86400}, True)['id']
    phone.goto(base + '/jukebox.php?id=' + upcoming)
    assert_logo(phone, 1)
    host.goto(base + '/jukebox.php?host=1&id=' + session)
    assert_logo(host, 1)
    phone.goto(base + '/jukebox.php?id=' + session)
    assert_logo(phone, 1)
    assert request(phone, 'join', {'name': 'Catalog phone'})['ok']
    phone_id = request(phone, 'state')['state']['phone']['id']
    assert request(host, 'approve', {'phone': phone_id}, True)['ok']

    def catalog_layout(page, tab, width):
        session_tab(page, 'music')
        page.locator('#tabs [data-tab="' + tab + '"]').click()
        rows = page.locator('#catalog > li.jukebox-catalog-row')
        expect(rows).to_have_count(1 if tab == 'albums' else 2)
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
        for index in range(rows.count()):
            row = rows.nth(index)
            action = row.locator('.jukebox-catalog-actions').get_by_role('button')
            expect(action).to_be_visible()
            bounds = row.bounding_box()
            button_bounds = action.bounding_box()
            assert bounds and button_bounds
            assert 0 <= bounds['x'] + bounds['width'] - button_bounds['x'] - button_bounds['width'] <= 25
            assert button_bounds['width'] >= 44 and button_bounds['height'] >= 44
            assert row.locator('.jukebox-catalog-title').inner_text()
        if tab == 'songs':
            assert rows.first.locator('.jukebox-catalog-title').inner_text() == tracks[1]['title']
        elif tab == 'feed':
            assert rows.first.locator('.jukebox-catalog-title').inner_text() == tracks[0]['title']
        page.evaluate('scrollTo(0, 0)')
        capture(page, f'branding-{"phone" if page is phone else "host"}-{tab}-{width}')

    # Actual request controls remain available and correctly scoped.
    session_tab(phone, 'music')
    phone.locator('#tabs [data-tab=feed]').click()
    first_row = phone.locator('#catalog > li').filter(has_text=tracks[0]['title'])
    press(phone, first_row.get_by_role('button', name='Play', exact=True))
    expect(host.locator('#host-now')).to_have_text(tracks[0]['title'])
    session_tab(host, 'history')
    host.locator('#enable').click()
    host.wait_for_function("""() => !document.querySelector('#audio').paused ||
      !document.querySelector('#audio-start').hidden""")
    if host.locator('#audio-start').is_visible():
        host.locator('#audio-start').click()
    host.wait_for_function("!document.querySelector('#audio').paused")
    for index in range(4):
        assert request(host, 'request', {'track': 'b' if index % 2 == 0 else 'a',
                                       'nonce': f'{index + 800:032x}'}, True)['ok']
    press(phone, phone.locator('#listen'))
    phone.wait_for_function("!document.querySelector('#listener-audio').paused")
    assert_playheads_synced(host, phone)
    for page in (host, phone):
        for width, height in ((1440, 960), (390, 844), (320, 740)):
            page.set_viewport_size({'width': width, 'height': height})
            assert_logo(page, 1)
            for tab in ('feed', 'songs', 'albums'):
                catalog_layout(page, tab, width)
            album_action = page.locator('#catalog .jukebox-catalog-actions').get_by_role('button')
            expect(album_action).to_have_accessible_name('View songs in ' + release_title)
            press(page, album_action)
            expect(page.locator('#catalog')).to_contain_text(tracks[0]['title'])
            expect(page.locator('#catalog')).to_contain_text(tracks[1]['title'])
            page.locator('#catalog-context').get_by_role('button', name='All albums', exact=True).click()
            expect(page.locator('#catalog .jukebox-catalog-actions').get_by_role('button')).to_be_visible()

    # Uploading a replacement from session Settings must retain the same native
    # player, its source and time rather than claiming/reloading the session.
    if host.locator('#audio').evaluate('audio => audio.currentTime') > TRACK_SECONDS - 25:
        assert request(host, 'control', {'event': 'skip'}, True)['ok']
        item = str(request(host, 'state', host=True)['state']['current']['id'])
        host.wait_for_function('''item => {
          const audio = document.querySelector('#audio');
          return !audio.paused && audio.currentSrc && new URL(audio.currentSrc).searchParams.get('item') === item;
        }''', arg=item)
        assert_playheads_synced(host, phone)
    host.set_viewport_size({'width': 390, 'height': 844})
    session_tab(host, 'settings')
    before = host.locator('#audio').evaluate("""audio => {
      audio.brandingFixture = 'same-player';
      return {source:audio.currentSrc, position:audio.currentTime};
    }""")
    opener, dialog = upload(host, SPLASH_ART, 8)
    capture(host, 'branding-upload-390')
    dialog.locator('#branding-save').click()
    expect(dialog).to_be_hidden()
    assert_logo(host, 8)
    assert_logo(phone, 8)
    assert host.locator('#audio').evaluate(
        '(audio, source) => !audio.paused && audio.currentSrc === source && audio.brandingFixture === "same-player"',
        before['source'])
    assert host.locator('#audio').evaluate('audio => audio.currentTime') >= before['position']
    assert request(host, 'state', host=True)['state']['id'] == session
    assert request(phone, 'state')['state']['phone']['id'] == phone_id
    capture(host, 'branding-saved-host-390')

    # A stale logo form offers a reload rather than overwriting another edit.
    press(host, opener)
    expect(dialog.locator('#branding-file')).to_be_enabled()
    current = request(host, 'branding', host=True)['branding']
    assert request(host, 'branding', {'expected_revision': current['revision'],
                                      'image': 'data:image/png;base64,' + PNG}, True)['ok']
    dialog.locator('#branding-file').set_input_files(
        {'name': 'host-logo.png', 'mimeType': 'image/png', 'buffer': base64.b64decode(SPLASH_ART)})
    dialog.locator('#branding-save').click()
    expect(dialog.locator('#branding-reload')).to_be_visible()
    expect(dialog).to_be_visible()
    dialog.locator('#branding-reload').click()
    host.keyboard.press('Escape')
    expect(dialog).to_be_hidden()
    expect(opener).to_be_focused()
    host.set_viewport_size({'width': 320, 'height': 740})
    opener, dialog = upload(host, PNG, 1)
    capture(host, 'branding-upload-320')
    host.keyboard.press('Escape')
    expect(dialog).to_be_hidden()
    press(host, opener)
    dialog.locator('#branding-remove').click()
    expect(dialog).to_be_hidden()
    expect(host.locator('#host-logo')).to_be_hidden()
    expect(phone.locator('#host-logo')).to_be_hidden()
    expect(host.locator('#host-logo-fallback')).to_be_visible()
    host.reload()
    expect(host.locator('#host-logo')).to_be_hidden()
    assert request(host, 'branding', host=True)['branding']['logo_url'] is None

    host_context.close()
    phone_context.close()
    other_context.close()
    print('PASS host logo upload/preview/save/reload/replace/remove, stale edits, uninterrupted players and responsive right-aligned catalog actions')

def inline_splash_editor(browser, base):
    context = browser.new_context()
    editor = context.new_page()
    editor.goto(base + '/login-fixture.php?user=1')
    editor.goto(base + '/admin/splash-browser-fixture.html')
    endpoint = base + '/admin/api/pwa-splash.php'
    waiting = editor.locator('[data-surface=waiting]')
    waiting.locator('select[name="waiting.mode"]').select_option('code')
    waiting.locator('input[name="waiting.title"]').fill('Tonight at Stonefellow')
    editor.locator('input[name=inheritColor]').uncheck()
    editor.locator('input[name=backgroundColor]').fill('#211922')
    waiting.locator('[data-add-code-image]').click()
    slot = waiting.locator('[data-code-image-slot]')
    slot.locator('[data-code-image-name]').fill('hero')
    slot.locator('[data-choose-code-image]').click()
    editor.locator('[data-media-results]').get_by_role('button', name=re.compile(r'^Waiting room artwork(?:\.png)?$')).click()

    # Simulate a draft design trying to navigate its own frame with private
    # data. The fixture intercepts the alternate origin before any network send.
    alternate_origin = base.replace('127.0.0.1', 'localhost')
    navigation_probe = alternate_origin + '/splash-navigation-probe'
    escaped = []

    def trap_navigation(route):
        escaped.append(route.request.url)
        route.fulfill(body='The splash left its preview frame')

    editor.route(navigation_probe + '**', trap_navigation)
    code = {
        'html': '<section><p class="fixture">Opening soon</p><figure class="splash-art"></figure></section>',
        'css': 'p {color: white} .splash-art img {width: 40px; height: 40px}',
        'js': '''
const media = JSON.parse(document.getElementById('sf-splash-media').textContent);
const art = document.createElement('img');
art.src = media.hero;
art.alt = 'Waiting room artwork';
document.querySelector('.splash-art').append(art);
document.querySelector('.fixture').textContent = 'Designed waiting screen';
'''.strip() + """
globalThis.attemptPreviewNavigation = () => {
  setTimeout(() => {
    location.href = """ + json.dumps(navigation_probe) + """ + '?sample=' + encodeURIComponent(media.hero.slice(-12));
  }, 20);
};
"""
    }
    brief = 'Design a calm listening room welcome around the selected hero image.'
    generated = []

    def fake_model(route):
        request_body = route.request.post_data_json
        generated.append(request_body)
        route.fulfill(json={'ok': True, 'surface': 'waiting', 'revision': 0, 'code': code})

    # Model generation is the only intercepted route. No provider is contacted.
    editor.route('**/admin/api/pwa-splash-code.php', fake_model)
    waiting.locator('[data-agent-brief]').fill(brief)
    waiting.locator('[data-agent-generate]').click()
    stage = waiting.locator('[data-agent-stage]')
    expect(stage).to_be_visible()
    expect(stage).to_be_enabled()
    assert len(generated) == 1 and generated[0]['brief'] == brief
    assert generated[0]['surface'] == 'waiting' and generated[0]['revision'] == 0
    image_slots = generated[0]['imageSlots']
    assert len(image_slots) == 1 and image_slots[0]['name'] == 'hero' and int(image_slots[0]['assetId']) > 0
    expect(waiting.locator('textarea[name="waiting.html"]')).to_have_value('')
    assert editor.request.get(endpoint).json()['draft']['revision'] == 0

    stage.click()
    expect(waiting.locator('textarea[name="waiting.html"]')).to_have_value(code['html'])
    expect(editor.locator('[data-publish-splash]')).to_be_disabled()
    assert editor.request.get(endpoint).json()['draft']['revision'] == 0
    editor.locator('[data-save-draft]').click()
    expect(editor.locator('[data-splash-status]')).to_contain_text('Draft saved')
    preview_shell = editor.frame_locator('[data-preview-code]')
    preview = preview_shell.frame_locator('iframe')
    expect(preview.locator('.fixture')).to_have_text('Designed waiting screen')
    expect(preview_shell.locator('iframe')).to_have_attribute('sandbox', 'allow-scripts')
    assert preview_shell.locator('iframe').evaluate('frame => frame.contentDocument === null')
    assert_splash_image(preview)
    if BROWSER == 'chromium':
        with editor.expect_console_message(
            lambda msg: alternate_origin in msg.text and 'Content Security Policy' in msg.text, timeout=5000
        ):
            preview.locator('.fixture').evaluate('() => globalThis.attemptPreviewNavigation()')
        assert not escaped, escaped
    snapshot = editor.request.get(endpoint).json()
    assert snapshot['draft']['revision'] == 1 and snapshot['published']['revision'] == 0
    assert snapshot['published']['waiting']['mode'] == 'default'

    editor.locator('[data-publish-splash]').click()
    editor.get_by_role('button', name='Publish splash settings').click()
    expect(editor.locator('[data-splash-status]')).to_contain_text('Splash settings published')
    snapshot = editor.request.get(endpoint).json()
    assert snapshot['published']['revision'] == 2 and snapshot['published']['waiting']['mode'] == 'code'
    published_slot = snapshot['published']['waiting']['imageSlots'][0]
    assert int(published_slot['assetId']) == int(image_slots[0]['assetId'])
    context.close()
    print('PASS inline Agent code generation, staging, isolated draft preview with managed artwork, and explicit publication')
    return published_slot['assetUrl']


def assert_splash_image(frame):
    artwork = frame.get_by_role('img', name='Waiting room artwork')
    expect(artwork).to_have_attribute('src', 'data:image/png;base64,' + SPLASH_ART)
    expect(artwork).to_be_visible()
    assert artwork.evaluate('async (image) => { await image.decode(); return image.naturalWidth }') == 8


def schedule_scenario(browser, base, phone_options):
    host_zone = ZoneInfo('America/Los_Angeles')
    host = browser.new_context(timezone_id=host_zone.key).new_page()
    host.goto(base + '/login-fixture.php?user=1')
    host.goto(base + '/jukebox.php?host=1')
    create = host.locator('#create')
    start_at = (datetime.now(host_zone) + timedelta(days=2)).replace(hour=19, minute=30, second=0, microsecond=0)

    def local_input(form, date):
        form.locator('input[name=scheduled_at]').fill(date.strftime('%Y-%m-%dT%H:%M'))

    def utc_iso(date):
        return date.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z')

    def create_session(title, listed=False, date=start_at):
        host.locator('#open-create').click()
        create.locator('input[name=title]').fill(title)
        create.locator('input[name=listed]').set_checked(listed)
        create.get_by_label('Allow remote listening').check()
        local_input(create, date)
        create.get_by_role('button', name='Create session').click()
        expect(host.get_by_role('heading', name=title, exact=True)).to_be_visible()
        expect(host.locator('#qr svg')).to_be_visible()
        expect(host.locator('#join-link')).to_be_visible()
        return host.locator('#join-link').get_attribute('href')

    title = 'Scheduled browser session'
    private_title = 'Scheduled private cancellation'
    private_at = start_at + timedelta(hours=2)
    private_join = create_session(private_title, date=private_at)
    host.goto(base + '/jukebox.php?host=1')
    join = create_session(title, listed=True)
    private_artwork_url = inline_splash_editor(browser, base)
    state = request(host, 'state', host=True)['state']
    assert state['phase'] == 'upcoming' and state['scheduled_at'] == int(start_at.timestamp())
    expect(host.locator('#enable')).to_be_hidden()
    expect(host.locator('#footer')).to_be_hidden()
    expect(host.locator('#session-tabs')).to_be_hidden()
    host.evaluate("Object.defineProperty(navigator, 'clipboard', {value: undefined})")
    host.locator('#copy-session-link').click()
    manual_link = host.locator('#session-link-value')
    expect(manual_link).to_be_visible()
    expect(manual_link).to_have_value(join)
    phone_context = browser.new_context(**phone_options, timezone_id='Europe/Berlin')
    directory = phone_context.new_page()
    directory.goto(base + '/jukebox.php')
    upcoming = directory.locator('#upcoming-sessions').get_by_role('link', name=title)
    live = directory.locator('#sessions').get_by_role('link', name=title)
    expect(upcoming).to_be_visible()
    expect(live).to_have_count(0)
    assert urllib.parse.urljoin(directory.url, upcoming.get_attribute('href')) == join
    expect(directory.locator('#upcoming-sessions time')).to_have_attribute('datetime', utc_iso(start_at))

    phone = phone_context.new_page()
    phone.goto(join)
    splash = phone.locator('#upcoming')
    expect(splash.get_by_role('heading', name='Waiting for the session to start', exact=True)).to_be_visible()
    expect(splash.get_by_role('heading', name='Tonight at Stonefellow')).to_be_visible()
    art = splash.locator('iframe')
    expect(art).to_have_attribute('sandbox', 'allow-scripts')
    expect(splash.frame_locator('iframe').locator('.fixture')).to_have_text('Designed waiting screen')
    assert_splash_image(splash.frame_locator('iframe'))
    assert phone.request.get(base + private_artwork_url).status == 404
    assert phone.evaluate('document.querySelector("iframe").contentDocument === null')
    other_phone = phone_context.new_page()
    other_phone.goto(private_join)
    other_splash = other_phone.locator('#upcoming')
    expect(other_phone.locator('#title')).to_have_text(private_title)
    expect(other_phone.locator('#scheduled-start')).to_have_attribute('datetime', utc_iso(private_at))
    expect(other_splash.frame_locator('iframe').locator('.fixture')).to_have_text('Designed waiting screen')
    assert_splash_image(other_splash.frame_locator('iframe'))
    assert other_splash.locator('iframe').get_attribute('src') == art.get_attribute('src')
    expect(phone.locator('#title')).to_have_text(title)
    phone.bring_to_front()
    manifest = phone.request.get(base + '/manifest.webmanifest').json()
    assert manifest['background_color'] == '#211922'
    assert {'192x192', '512x512'} <= {icon['sizes'] for icon in manifest['icons']}
    scheduled = phone.locator('#scheduled-start')
    expect(scheduled).to_be_visible()
    expect(scheduled).to_have_attribute('datetime', utc_iso(start_at))
    before = scheduled.inner_text()
    for element in ('#session-tabs', '#pairing', '#music', '#footer', '#chat'):
        expect(phone.locator(element)).to_be_hidden()
    assert phone.locator('#listener-audio').evaluate('(audio) => audio.paused && !audio.currentSrc')

    edited_at = start_at + timedelta(hours=1)
    reschedule = host.locator('#reschedule')
    local_input(reschedule, edited_at)
    reschedule.get_by_role('button', name='Save new time').click()
    expect(scheduled).not_to_have_text(before, timeout=10_000)
    expect(scheduled).to_have_attribute('datetime', utc_iso(edited_at))
    assert request(phone, 'state')['state']['scheduled_at'] == int(edited_at.timestamp())
    assert host.locator('#join-link').get_attribute('href') == join

    host.locator('#start-session').click()
    expect(splash).to_be_hidden(timeout=10_000)
    expect(host.locator('#footer')).to_be_visible()
    expect(art).to_have_count(0)
    expect(other_splash).to_be_visible()
    assert_splash_image(other_splash.frame_locator('iframe'))
    expect(phone).to_have_url(join)
    assert host.locator('#join-link').get_attribute('href') == join
    directory.bring_to_front()
    expect(upcoming).to_have_count(0, timeout=12_000)
    expect(live).to_be_visible(timeout=12_000)
    assert urllib.parse.urljoin(directory.url, live.get_attribute('href')) == join

    phone.locator('#join input[name=name]').fill('Scheduled guest')
    phone.get_by_role('button', name='Request access').click()
    session_tab(host, 'phones')
    host.locator('#phones li').filter(has_text='Scheduled guest').get_by_role('button', name='Approve').click()
    session_tab(phone, 'music')
    phone.locator('#catalog li').filter(has_text='First Song').get_by_role('button', name='Play', exact=True).click()
    host.get_by_role('button', name='Enable player on this device').click()
    session_tab(host, 'history')
    host.locator('#history li, #audio-start:visible').first.wait_for()
    if host.locator('#audio-start').is_visible():
        host.locator('#audio-start').click()
    expect(host.locator('#history')).to_contain_text('First Song')
    expect(phone.locator('#progress')).to_have_text(re.compile(r'^0:\d{2}$'))
    phone.get_by_role('button', name='Stream on this phone', exact=True).click()
    phone.wait_for_function("!document.querySelector('#listener-audio').paused")
    assert_playheads_synced(host, phone)
    phone.locator('#catalog li').filter(has_text='Second Song').get_by_role('button', name='Add to Queue').click()
    expect(host.locator('#queue-label')).to_have_text('Up Next (1)')
    session_tab(host, 'settings')
    host.once('dialog', lambda dialog: dialog.accept())
    host.get_by_role('button', name='End session').click()
    expect(host.get_by_role('heading', name='Session recap')).to_be_visible()
    expect(host.locator('#footer')).to_be_hidden()
    expect(host.locator('#recap-tracks')).to_contain_text('First Song')

    host.goto(private_join + '&host=1')
    phone.goto(private_join)
    expect(splash).to_be_visible()
    sign_in = phone.get_by_role('link', name='Sign in to follow')
    expect(sign_in).to_be_visible()
    private_id = urllib.parse.parse_qs(urllib.parse.urlparse(private_join).query)['id'][0]
    expect(sign_in).to_have_attribute('href', base + '/?view=login#return_jukebox=' + private_id)
    directory.reload()
    expect(directory.get_by_role('link', name='Scheduled private cancellation')).to_have_count(0)

    follower = browser.new_context(**phone_options).new_page()
    follower.goto(base + '/login-fixture.php?user=3')
    follower.goto(private_join)
    follower.get_by_role('button', name='Follow this session', exact=True).click()
    expect(follower.get_by_role('button', name='Unfollow this session')).to_be_visible()
    follower.goto(base + '/jukebox.php')
    follower.locator('#following-sessions').get_by_role('link', name='Scheduled private cancellation').click()
    expect(follower).to_have_url(private_join)
    follower.get_by_role('button', name='Unfollow this session').click()
    expect(follower.get_by_role('button', name='Follow this session', exact=True)).to_be_visible()
    follower.goto(base + '/jukebox.php')
    expect(follower.locator('#following-directory')).to_be_hidden()
    host.once('dialog', lambda dialog: dialog.accept())
    host.locator('#cancel-session').click()
    expect(splash.get_by_role('heading', name='Session canceled')).to_be_visible(timeout=10_000)
    expect(phone).to_have_url(private_join)
    expect(phone.locator('#pairing')).to_be_hidden()
    print('PASS shared QR splash design with distinct session details, private following, same-link start, playback, and recap')


def management_scenario(browser, base, phone_options, site):
    host = browser.new_context().new_page()
    host.goto(base + '/login-fixture.php?user=1')
    host.goto(base + '/jukebox.php?host=1')
    future = ((int(time.time()) + 172800) // 60) * 60 + 37
    source = request(host, 'create', dict(title='Management browser source', listed=True,
                                         remote_listening=True, scheduled_at=future), True)['id']
    host.goto(base + f'/jukebox.php?host=1&id={source}')
    join = host.locator('#join-link').get_attribute('href')
    qr = host.locator('#qr svg rect').evaluate_all('rects => rects.map(rect => rect.outerHTML)')
    host.locator('#upcoming-edit-session').click()
    edit = host.locator('#edit-dialog')
    expect(edit).to_be_visible()
    assert edit.evaluate("dialog => dialog.matches(':modal')")
    expect(edit.locator('[name=title]')).to_be_focused()
    expect(edit.locator('[name=listed]')).to_be_checked()
    expect(edit.locator('[name=remote_listening]')).to_be_checked()
    expect(edit.locator('[name=scheduled_at]')).not_to_have_value('')
    edit.locator('[name=title]').fill('Scheduled rename')
    edit.get_by_role('button', name='Save changes', exact=True).click()
    expect(edit).to_be_hidden()
    expect(host.locator('#title')).to_have_text('Scheduled rename')
    assert host.locator('#join-link').get_attribute('href') == join
    assert host.locator('#qr svg rect').evaluate_all('rects => rects.map(rect => rect.outerHTML)') == qr
    assert request(host, 'settings', host=True)['settings']['scheduled_at'] == future
    host.locator('#upcoming-edit-session').click()
    changed_time = future + 3600 - 37
    edit.locator('[name=scheduled_at]').fill(datetime.fromtimestamp(changed_time, timezone.utc).strftime('%Y-%m-%dT%H:%M'))
    edit.get_by_role('button', name='Save changes', exact=True).click()
    expect(edit).to_be_hidden()
    assert request(host, 'settings', host=True)['settings']['scheduled_at'] == changed_time
    host.locator('#start-session').click()
    expect(host.locator('#footer')).to_be_visible()

    phone = browser.new_context(**phone_options).new_page()
    phone.goto(join)
    assert request(phone, 'join', {'name': 'Management guest'})['ok']
    phone_id = request(phone, 'state')['state']['phone']['id']
    assert request(host, 'approve', {'phone': phone_id}, True)['ok']
    for track, nonce in (('a', 'c'*32), ('b', 'd'*32)):
        assert request(phone, 'request', {'track': track, 'nonce': nonce})['ok']
    assert request(phone, 'message', {'text': 'Conversation survives editing'})['ok']
    expect(host.locator('#host-now')).to_have_text('First Song')
    host.get_by_role('button', name='Enable player on this device').click()
    host.wait_for_function("!document.querySelector('#audio').paused || document.querySelector('#audio-start').getClientRects().length > 0")
    if host.locator('#audio-start').is_visible():
        host.locator('#audio-start').click()
    host.wait_for_function("!document.querySelector('#audio').paused && document.querySelector('#audio').currentTime > 0")
    phone.get_by_role('button', name='Stream on this phone', exact=True).click()
    phone.wait_for_function("!document.querySelector('#listener-audio').paused")
    assert_playheads_synced(host, phone)
    before = request(host, 'state', host=True)['state']
    position = host.locator('#audio').evaluate("audio => { audio.managementFixture = 'same-node'; return audio.currentTime; }")
    current_src = host.locator('#audio').evaluate('audio => audio.currentSrc')
    session_tab(host, 'settings')
    host.locator('#edit-session').click()
    edit.locator('[name=title]').fill('Live rename')
    snapshot = request(host, 'settings', host=True)['settings']
    assert request(host, 'settings', {'expected': snapshot, 'title': 'Edited by another host'}, True)['ok']
    edit.get_by_role('button', name='Save changes', exact=True).click()
    expect(edit.locator('#edit-reload')).to_be_visible()
    expect(edit.locator('[name=title]')).to_have_value('Live rename')
    edit.locator('#edit-reload').click()
    expect(edit.locator('[name=title]')).to_have_value('Edited by another host')
    edit.locator('[name=title]').fill('Live rename')
    edit.get_by_role('button', name='Save changes', exact=True).click()
    expect(edit).to_be_hidden()
    expect(host.locator('#title')).to_have_text('Live rename')
    expect(phone.locator('#title')).to_have_text('Live rename', timeout=10_000)
    after = request(host, 'state', host=True)['state']
    assert after['current'] == before['current'] and after['queue'] == before['queue']
    assert after['phones'] == before['phones'] and after['history'] == before['history']
    assert host.locator('#audio').evaluate("(audio, position) => audio.managementFixture === 'same-node' && !audio.paused && audio.currentTime >= position", position)
    assert host.locator('#audio').evaluate('audio => audio.currentSrc') == current_src
    assert_playheads_synced(host, phone)
    assert request(host, 'thread', host=True, phone=phone_id, full=1)['messages'][0]['body'] == 'Conversation survives editing'

    mobile = browser.new_context(**phone_options).new_page()
    mobile.goto(base + '/login-fixture.php?user=1')
    mobile.goto(host.url)
    session_tab(mobile, 'settings')
    mobile.locator('#edit-session').click()
    mobile_edit = mobile.locator('#edit-dialog')
    expect(mobile_edit.locator('[name=title]')).to_be_focused()
    assert mobile.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
    mobile_edit.get_by_role('button', name='Cancel', exact=True).click()
    expect(mobile.locator('#edit-session')).to_be_focused()
    mobile.context.close()

    host.once('dialog', lambda dialog: dialog.accept())
    host.locator('#end-session').click()
    expect(host.get_by_role('heading', name='Session recap', exact=True)).to_be_visible()
    host.locator('#recap-edit-session').click()
    expect(edit.get_by_role('heading', name='Rename session', exact=True)).to_be_visible()
    expect(edit.locator('#edit-options')).to_be_hidden()
    edit.locator('[name=title]').fill('Finished session rename')
    edit.get_by_role('button', name='Save changes', exact=True).click()
    expect(edit).to_be_hidden()
    expect(host.locator('#title')).to_have_text('Finished session rename')
    expect(host.locator('#recap-tracks')).to_contain_text('First Song')
    host.locator('#recap-reuse-session').click()
    create = host.locator('#create-dialog')
    expect(create).to_be_visible()
    expect(create.locator('[name=title]')).to_have_value('Finished session rename')
    expect(create.locator('[name=listed]')).to_be_checked()
    expect(create.locator('[name=remote_listening]')).to_be_checked()
    expect(create.locator('[name=scheduled_at]')).to_have_value('')
    create.locator('[name=title]').fill('Independent new session')
    create.get_by_role('button', name='Create session', exact=True).click()
    expect(host.locator('#title')).to_have_text('Independent new session')
    copied = request(host, 'state', host=True)['state']
    assert copied['id'] != source and copied['phase'] == 'live' and copied['scheduled_at'] is None
    assert copied['current'] is None and copied['phones'] == copied['queue'] == copied['history'] == []
    assert copied['chat_threads'] == [] and not copied['online']
    phone.goto(base + '/jukebox.php?id=' + copied['id'])
    assert not request(phone, 'state')['state'].get('phone')
    expect(phone.locator('#edit-session, #reuse-session, #directory-tabs').filter(visible=True)).to_have_count(0)

    archive_ids = []
    for index in range(27):
        old = request(host, 'create', {'title': f'Archive browser {index}', 'listed': True}, True)['id']
        assert request(host, 'control', {'event': 'end'}, True, session=old)['ok']
        archive_ids.append(old)
    canceled = request(host, 'create', {'title': 'Canceled browser', 'listed': True,
                                        'scheduled_at': future}, True)['id']
    assert request(host, 'control', {'event': 'cancel'}, True, session=canceled)['ok']
    host.goto(base + '/jukebox.php?host=1')
    active_tab = host.locator('[data-directory-scope=active]')
    archive_tab = host.locator('[data-directory-scope=archive]')
    expect(active_tab).to_have_attribute('aria-selected', 'true')
    expect(host.locator(f'#sessions [data-session-id="{copied["id"]}"]')).to_be_visible()
    for old in (source, canceled):
        expect(host.locator(f'#sessions [data-session-id="{old}"]')).to_have_count(0)
    # An Edit response that arrives after changing directory scope belongs to
    # the old view and must not open a detached session's modal.
    pending_settings = []
    settings_pattern = '**/api/jukebox.php?action=settings&*'
    host.route(settings_pattern, lambda route: pending_settings.append(route))
    with host.expect_request(lambda req: 'action=settings&' in req.url):
        host.locator(f'[data-session-edit="{copied["id"]}"]').click()
    assert len(pending_settings) == 1
    archive_tab.click()
    expect(archive_tab).to_have_attribute('aria-selected', 'true')
    delayed = pending_settings[0]
    with host.expect_response(lambda response: 'action=settings&' in response.url) as delivered:
        delayed.fulfill(response=delayed.fetch())
    delivered.value.body()
    host.unroute(settings_pattern)
    host.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
    expect(host.locator('#edit-dialog, #create-dialog').filter(visible=True)).to_have_count(0)
    expect(host.locator('#sessions [data-session-id]')).to_have_count(25)
    while host.locator('#directory-more').is_visible():
        count = host.locator('#sessions [data-session-id]').count()
        host.locator('#directory-more').click()
        host.wait_for_function('(count) => document.querySelectorAll("#sessions [data-session-id]").length > count', arg=count)
    rendered = host.locator('#sessions [data-session-id]').evaluate_all('rows => rows.map(row => row.dataset.sessionId)')
    assert set(archive_ids + [source, canceled]).issubset(rendered)
    expect(host.locator('#directory-more')).to_be_hidden()
    expect(host.locator(f'#sessions [data-session-id="{copied["id"]}"]')).to_have_count(0)
    old_row = host.locator(f'#sessions [data-session-id="{archive_ids[0]}"]')
    expect(host.locator(f'#sessions [data-session-id="{canceled}"] [data-session-recap]')).to_be_hidden()
    old_row.locator(f'[data-session-recap="{archive_ids[0]}"]').click()
    expect(host.get_by_role('heading', name='Session recap', exact=True)).to_be_visible()
    expect(host.locator('#title')).to_have_text('Archive browser 0')
    assert request(host, 'recap', host=True)['recap']['totals']['requests'] == 0
    overdue = request(host, 'create', {'title': 'Overdue waiting source', 'listed': True,
                                       'scheduled_at': future}, True)['id']
    past = int(time.time()) - 60
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('UPDATE jukebox_schedule SET scheduled_at=? WHERE session_id=?', (past, overdue))
    host.goto(base + f'/jukebox.php?host=1&id={overdue}')
    host.locator('#upcoming-edit-session').click()
    edit.locator('[name=title]').fill('Still waiting for the host')
    edit.get_by_role('button', name='Save changes', exact=True).click()
    expect(edit).to_be_hidden()
    expect(host.locator('#title')).to_have_text('Still waiting for the host')
    assert request(host, 'settings', host=True)['settings']['scheduled_at'] == past
    print('PASS browser scheduled/live/archived edits preserve QR, playback and chat; mobile modals; fresh reuse; archive pagination and older recaps')


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
    bartender = {'id': 2, 'display_name': 'Bartender', 'email': 'venue@example.test'}
    matches = admin.call('venue_accounts', q='bartender')
    assert matches['ok'] and sorted(matches['accounts'], key=lambda account: account['id']) == [
        bartender,
        {'id': 5, 'display_name': 'Bartender', 'email': 'staff@example.test'},
    ]
    assert admin.call('venue_accounts', q='venue@example.test')['accounts'] == [bartender]
    assert admin.call('venue_accounts', q='%_')['accounts'] == []
    assert admin.call('venue_accounts', q='a')['status'] == 400
    assert venue.call('venue_accounts', q='Bartender')['status'] == 403
    assert Client().call('venue_accounts', q='Bartender')['status'] == 403
    assert admin.call('venue', data={'owner': '2junk', 'name': 'Invalid'})['status'] == 400
    assert admin.call('venue', data={'owner': 4, 'name': 'Inactive'})['status'] == 400
    assert admin.call('venue', data={'owner': 2, 'name': 'Test Bar'})['ok']
    public = admin.call('create', data={'title': 'Universal', 'listed': True})['id']
    session = venue.call('create', data={'title': 'Tonight'})['id']
    assert public != session
    first, second = Client(), Client()
    assert [row['id'] for row in first.call('directory')['sessions']] == [public]
    assert first.call('request', session, {'track': 'a', 'nonce': '1'*32})['status'] == 409
    assert first.call('join', session, {'name': 'Dave', 'remember': True})['status'] == 'pending'
    assert second.call('join', session, {})['ok']
    assert 'chat_threads' not in first.call('state', session)['state']
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
    assert first.call('state', session)['state']['chat_threads'] == [{
        'phone_id': first_id, 'latest_id': str(message['id']), 'incoming_count': 1,
    }]
    assert second.call('state', session, phone=first_id)['state']['chat_threads'] == []
    assert 'chat_threads' not in Client().call('state', session, phone=first_id)['state']
    assert first.call('message', session, {'text': 'Guest reply'})['ok']
    reply = venue.call('thread', session, phone=first_id, full=1)['messages'][-1]
    assert reply['body'] == 'Guest reply'
    assert venue.call('state', session)['state']['chat_threads'] == [{
        'phone_id': first_id, 'latest_id': str(reply['id']), 'incoming_count': 1,
    }]
    image_url = base + f'/api/jukebox.php?action=image&id={session}&message={message["id"]}'
    with first.opener.open(image_url) as image:
        assert image.headers.get_content_type() == 'image/png'
        assert image.read() == base64.b64decode(PNG)
    assert second.call('image', session, message=message['id'])['status'] == 404
    assert second.call('thread', session, phone=first_id)['messages'] == []
    assert venue.call('revoke', session, {'phone': first_id})['ok']
    assert first.call('thread', session)['status'] == 409
    assert first.call('thread', session, full=1)['status'] == 409
    assert venue.call('thread', session, phone=first_id, full=1)['status'] == 409
    assert 'chat_threads' not in first.call('state', session)['state']
    assert venue.call('state', session)['state']['chat_threads'] == []
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
    http_listener_scenario(Client, venue, outsider, site)
    http_schedule_scenario(Client, admin, venue, outsider, site)
    http_management_scenario(Client, admin, venue, outsider, site)
    http_branding_scenario(Client, admin, venue, outsider, site, base)



def http_branding_scenario(Client, admin, venue, outsider, site, base):
    guest = Client()
    first = admin.call('branding')['branding']
    assert first == {'logo_url': None, 'revision': 0, 'owner_available': True}
    venue_before = venue.call('branding')['branding']
    for denied in (guest.call('branding'),
                   guest.call('branding', data={'expected_revision': 0, 'image': 'data:image/png;base64,' + PNG})):
        assert denied['status'] in (403, 409), denied
    guest.host = True
    assert guest.call('branding')['status'] == 401
    guest.host = False
    assert outsider.call('branding', data={'expected_revision': 0, 'image': 'data:image/png;base64,' + PNG})['status'] in (403, 409)
    with admin.response('branding', data={'expected_revision': 0, 'image': 'data:image/png;base64,' + PNG},
                        headers={'X-CSRF-Token': 'incorrect'}) as denied:
        assert denied.status == 403
    assert admin.call('branding')['branding'] == first

    def data_url(raw, mime='image/png'):
        return 'data:' + mime + ';base64,' + base64.b64encode(raw).decode()

    original = base64.b64decode(PNG)
    dimension = bytearray(original)
    dimension[16:24] = struct.pack('>II', 5000, 5000)
    dimension[29:33] = struct.pack('>I', zlib.crc32(dimension[12:29]))
    invalid_images = [
        'data:image/svg+xml;base64,' + base64.b64encode(b'<svg xmlns="http://www.w3.org/2000/svg"/>').decode(),
        data_url(b'not really a PNG'), data_url(original, 'image/jpeg'),
        'https://example.test/logo.png', 'data:image/png;base64,not-base64!',
        'data:image/png;base64,' + PNG + '\n',
        data_url(original + b'\0' * (1_048_577 - len(original))),
        data_url(dimension),
    ]
    for image in invalid_images:
        invalid = admin.call('branding', data={'expected_revision': 0, 'image': image})
        assert invalid['status'] == 400, invalid
        assert admin.call('branding')['branding'] == first
    for revision in (-1, True, '0', None):
        assert admin.call('branding', data={'expected_revision': revision, 'image': data_url(original)})['status'] == 400
    assert admin.call('branding', data={'expected_revision': 0, 'image': data_url(original), 'owner': 2})['status'] == 400

    live = admin.call('create', data={'title': 'Branding live', 'listed': True, 'remote_listening': True})['id']
    upcoming = admin.call('create', data={'title': 'Branding upcoming', 'listed': True,
                                          'scheduled_at': int(time.time()) + 86400})['id']
    ended = admin.call('create', data={'title': 'Branding ended', 'listed': True})['id']
    canceled = admin.call('create', data={'title': 'Branding canceled', 'listed': True,
                                         'scheduled_at': int(time.time()) + 86400})['id']
    assert admin.call('control', ended, {'event': 'end'})['ok']
    assert admin.call('control', canceled, {'event': 'cancel'})['ok']
    other = venue.call('create', data={'title': 'Other host logo'})['id']
    assert guest.call('join', live, {'name': 'Branding listener'})['ok']
    phone = guest.call('state', live)['state']['phone']['id']
    assert admin.call('approve', live, {'phone': phone})['ok']
    assert guest.call('request', live, {'track': 'a', 'nonce': '7'*32})['ok']
    assert guest.call('request', live, {'track': 'b', 'nonce': '8'*32})['ok']
    assert guest.call('message', live, {'text': 'Branding leaves this conversation intact'})['ok']
    player = '7'*64
    assert admin.call('player', live, {'player': player, 'event': 'claim'})['ok']
    before = admin.call('state', live)['state']
    item = before['current']['id']
    assert admin.call('player', live, player_sample(player, 'playing', item, 1, 1000,
                                                   observed_revision=before['revision']))['ok']
    before = admin.call('state', live)['state']
    # A player's revision/heartbeat can change after opening the logo form.
    # It must not make the independent branding revision stale.
    assert admin.call('player', live, player_sample(player, 'progress', item, 2, 1500,
                                                   observed_revision=before['revision']))['ok']
    before = admin.call('state', live)['state']
    saved = admin.call('branding', data={'expected_revision': 0, 'image': data_url(original)})
    assert saved['ok'], saved
    branding = saved['branding']
    assert branding['revision'] == 1 and branding['logo_url']
    assert set(branding) == {'logo_url', 'revision', 'owner_available'}
    assert branding['owner_available'] is True
    assert len(json.dumps(branding)) < 400 and 'base64' not in json.dumps(branding)
    assert admin.call('branding')['branding'] == branding
    after = admin.call('state', live)['state']
    for key in ('id', 'current', 'queue', 'phones', 'history', 'revision'):
        assert after[key] == before[key], key
    assert after['transport']['status'] == 'playing'
    assert guest.call('state', live)['state']['phone']['id'] == phone
    assert admin.call('thread', live, phone=phone, full=1)['messages'][0]['body'] == 'Branding leaves this conversation intact'
    for session in (live, upcoming, ended, canceled):
        assert admin.call('state', session)['state']['branding'] == branding
        assert guest.call('state', session)['state']['branding'] == branding
    for scope in ('active', 'archive'):
        directory = admin.call('directory', scope=scope)
        assert directory['branding'] == branding
        assert all(row['branding'] == branding for row in directory['sessions'])
    directory = guest.call('directory')
    assert next(row for row in directory['sessions'] if row['id'] == live)['branding'] == branding
    assert venue.call('state', other)['state']['branding'] == venue_before
    assert venue.call('branding')['branding'] == venue_before

    # The logo is intentionally public; fetching it needs no signed-in cookies.
    logo = urllib.parse.urljoin(base + '/', branding['logo_url'])
    with urllib.request.urlopen(logo) as image:
        assert image.status == 200 and image.headers.get_content_type() == 'image/png'
        assert image.headers.get('X-Content-Type-Options') == 'nosniff'
        assert 'no-store' in image.headers.get('Cache-Control', '')
        assert image.read() == original
    with urllib.request.urlopen(urllib.request.Request(logo, method='HEAD')) as image:
        assert image.status == 200 and image.read() == b''
        assert int(image.headers.get('Content-Length', '0')) == len(original)
    params = urllib.parse.parse_qs(urllib.parse.urlsplit(logo).query)
    assert params['v'] == [hashlib.sha256(original).hexdigest()]
    bad_logo = logo.replace(params['v'][0], '0'*64)
    try:
        urllib.request.urlopen(bad_logo)
        assert False, 'Wrong content hash was served'
    except urllib.error.HTTPError as error:
        assert error.code == 404

    assert admin.call('branding', data={'expected_revision': 0, 'image': data_url(base64.b64decode(SPLASH_ART))})['status'] == 409
    assert admin.call('branding')['branding'] == branding
    replaced = admin.call('branding', data={'expected_revision': 1, 'image': data_url(base64.b64decode(SPLASH_ART))})['branding']
    assert replaced['revision'] == 2 and replaced['logo_url'] != branding['logo_url']
    try:
        urllib.request.urlopen(logo)
        assert False, 'Replaced logo hash stayed valid'
    except urllib.error.HTTPError as error:
        assert error.code == 404
    removed = admin.call('branding', data={'expected_revision': 2, 'image': None})['branding']
    assert removed == {'logo_url': None, 'revision': 3, 'owner_available': True}
    assert admin.call('branding')['branding'] == removed
    assert guest.call('state', live)['state']['branding'] == removed
    assert admin.call('branding', data={'expected_revision': 2, 'image': data_url(original)})['status'] == 409
    own = venue.call('branding', data={'expected_revision': venue_before['revision'], 'image': data_url(original)})
    assert own['ok'] and own['branding']['logo_url'] != branding['logo_url']
    assert admin.call('branding')['branding'] == removed
    assert guest.call('state', other)['state']['branding'] == own['branding']
    assert admin.call('state', other)['state']['branding_editable'] is False
    assert venue.call('state', other)['state']['branding_editable'] is True
    assert admin.call('state', live)['state']['branding_editable'] is True
    assert admin.call('branding', data={'expected_revision': 3, 'image': None})['branding'] == removed

    # A closed host must disappear from public branding immediately, even if
    # its optional storage cleanup has not run yet. Cleanup is owner-scoped.
    assert admin.call('venue', data={'owner': 5, 'name': 'Closing host'})['ok']
    closing = Client(5)
    closing_session = closing.call('create', data={'title': 'Closing logo session'})['id']
    closing_logo = closing.call('branding', data={'expected_revision': 0, 'image': data_url(original)})['branding']
    closing_url = urllib.parse.urljoin(base + '/', closing_logo['logo_url'])
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute("UPDATE users SET status='closed' WHERE id=5")
    assert guest.call('state', closing_session)['state']['branding'] == {
        'logo_url': None, 'revision': 0, 'owner_available': False}
    try:
        urllib.request.urlopen(closing_url)
        assert False, 'Closed host logo remained publicly available'
    except urllib.error.HTTPError as error:
        assert error.code == 404
    subprocess.run([PHP, '-r', "define('SF_ROOT',getcwd());require 'api/bootstrap.php';require_once 'api/jukebox-core.php';echo json_encode(sf_jukebox_branding_clear_user(5));"],
                   cwd=site, check=True, stdout=subprocess.PIPE)
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        assert db.execute('SELECT COUNT(*) FROM jukebox_host_branding WHERE owner_id=5').fetchone()[0] == 0
        assert db.execute('SELECT COUNT(*) FROM jukebox_host_branding WHERE owner_id=2').fetchone()[0] == 1
    print('PASS HTTP host logo authorization, CSRF, image validation, revisions, public byte serving, owner isolation and preserved session playback/chat')

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
    telemetry = listener_sample('f'*32, first, transport['generation'], 0)
    assert anonymous.call('listener', disabled, telemetry)['status'] == 409
    assert anonymous.call('listener', public, telemetry)['ok']
    assert admin.call('state', public)['state']['listener_metrics'] == audience()
    rewind_listener_clock(site, public, 800)
    assert anonymous.call('listener', public, telemetry | {'seq': 1, 'advanced_ms': 500, 'active': True}) == {
        'status': 200, 'ok': True}
    assert admin.call('state', public)['state']['listener_metrics'] == audience(1, 1, 1, 500)
    assert 'listener_metrics' not in anonymous.call('state', public)['state']
    directory = {row['id']: row for row in anonymous.call('directory')['sessions']}
    assert 'listener_metrics' not in directory[public]

    stale_revision = admin.call('state', public)['state']['revision']
    assert admin.call('control', public, {'event': 'pause'})['ok']
    assert admin.call('player', public, player_sample(player['player'], 'progress', first, 2, 20000,
                                                      observed_revision=stale_revision))['ok']
    transport = anonymous.call('state', public)['state']['transport']
    assert transport['status'] == 'paused' and transport['position_ms'] == 6500
    assert admin.call('state', public)['state']['listener_metrics'] == audience(0, 1, 1, 500)
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
    generation = admin.call('state', public)['state']['transport']['generation']
    assert phone.call('listener', public, listener_sample('e'*32, second, generation, 0))['status'] == 409
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
    recap = admin.call('recap', public)['recap']
    assert recap['phones'] == {'connections': 1, 'approved': 1}
    assert recap['listener_metrics'] == audience(0, 1, 1, 500)

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


def http_listener_scenario(Client, venue, outsider, site):
    session = venue.call('create', data={'title': 'Listener metrics', 'remote_listening': True})['id']
    phone = Client()
    main_player = 'c'*64
    first, second = '1'*32, '2'*32
    assert venue.call('request', session, {'track': 'a', 'nonce': '1'*32})['ok']
    assert venue.call('player', session, {'player': main_player, 'event': 'claim'})['ok']

    def state():
        return venue.call('state', session)['state']

    item = state()['current']['id']

    def playing(seq, position_ms):
        assert venue.call('player', session, player_sample(main_player, 'playing', item, seq, position_ms,
                                                           observed_revision=state()['revision']))['ok']
        return state()['transport']['generation']

    generation = playing(1, 6500)

    def send(player, seq, advanced_ms=0, active=False, **extra):
        data = listener_sample(player, item, generation, seq, advanced_ms, active) | extra
        return phone.call('listener', session, data)

    baseline = listener_sample(first, item, generation, 0)
    assert venue.call('listener', session, baseline)['status'] == 409
    assert send(first, 0)['status'] == 409
    assert phone.call('join', session, {'name': 'Private listener'})['ok']
    phone_id = phone.call('state', session)['state']['phone']['id']
    assert send(first, 0)['status'] == 409
    assert venue.call('approve', session, {'phone': phone_id})['ok']
    assert send('malformed', 0)['status'] == 400
    with phone.response('audio', session, item=item, headers={'Range': 'bytes=0-7'}) as response:
        assert response.status == 206
    assert state()['listener_metrics'] == audience()
    assert 'listener_metrics' not in phone.call('state', session)['state']
    assert venue.call('recap', session)['status'] == 409

    # Two playback tabs can use the same approved phone. A join, range request,
    # or first report alone does not earn time or make either tab a listener.
    assert send(first, 0, 900, True)['ok']
    assert send(second, 0)['ok']
    assert state()['listener_metrics'] == audience()
    rewind_listener_clock(site, session, 1200)
    assert send(first, 1, 900, True)['ok']
    assert state()['listener_metrics'] == audience(1, 1, 1, 900)
    assert send(first, 1, 900, True)['ok']
    assert send(first, 0)['ok']
    assert state()['listener_metrics'] == audience(1, 1, 1, 900)

    assert send(second, 1, 700, True)['ok']
    assert state()['listener_metrics'] == audience(2, 2, 2, 1600)
    rewind_listener_clock(site, session, 300)
    assert send(first, 2, 200)['ok']
    assert send(first, 2, 200)['ok']
    assert state()['listener_metrics'] == audience(1, 2, 2, 1800)

    # Simulate a closed/disconnected client whose final request never arrived.
    # Its historical time and peak survive after its last heartbeat expires.
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('''UPDATE jukebox_listener_players SET sampled_at_ms=0, active_until_ms=0
                      WHERE session_id=?''', (session,))
    assert state()['listener_metrics'] == audience(0, 2, 2, 1800)
    assert send(second, 1, 700, True)['ok']
    assert send(second, 2, 0, True)['ok']
    assert state()['listener_metrics'] == audience(0, 2, 2, 1800)
    assert send(first, 3)['ok']
    rewind_listener_clock(site, session, 900)
    assert send(first, 4, 800, True)['ok']
    assert state()['listener_metrics'] == audience(1, 2, 2, 2600)

    previous_generation = generation
    assert venue.call('control', session, {'event': 'pause'})['ok']
    assert state()['listener_metrics'] == audience(0, 2, 2, 2600)
    assert venue.call('control', session, {'event': 'resume'})['ok']
    generation = playing(2, 7200)
    assert generation != previous_generation
    assert send(first, 5, 600, True, generation=previous_generation)['ok']
    assert send(first, 5, 600, True, item=str(int(item) + 1))['ok']
    assert state()['listener_metrics'] == audience(0, 2, 2, 2600)
    assert send(first, 6)['ok']
    rewind_listener_clock(site, session, 800)
    assert send(first, 7, 500, True)['ok']
    assert state()['listener_metrics'] == audience(1, 2, 2, 3100)

    # A report cannot turn an inflated position delta into more listening time
    # than the server observed between its receipts.
    start = state()['transport']['server_time_ms']
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('UPDATE jukebox_listener_players SET sampled_at_ms=? WHERE session_id=?', (start - 1100, session))
    assert send(first, 8, 50_000, True)['ok']
    after_report = state()
    total = after_report['listener_metrics']['listening_ms']
    elapsed = after_report['transport']['server_time_ms'] - start + 1100
    assert 1100 <= total - 3100 <= min(elapsed, 6000)
    assert venue.call('revoke', session, {'phone': phone_id})['ok']
    assert send(first, 9, 500, True)['status'] == 409
    assert state()['listener_metrics'] == audience(0, 2, 2, total)

    assert venue.call('control', session, {'event': 'end'})['ok']
    assert send(first, 9, 500, True)['status'] == 409
    assert phone.call('recap', session)['status'] == 409
    assert outsider.call('recap', session)['status'] == 404
    recap = venue.call('recap', session)['recap']
    assert recap['listener_metrics'] == audience(0, 2, 2, total)
    assert recap['phones'] == {'connections': 1, 'approved': 1}
    assert recap['totals']['confirmed_plays'] == 1
    encoded = json.dumps(recap)
    assert all(value not in encoded for value in ('Private listener', first, second))
    print('PASS HTTP listener authorization, deduplication, distinct tabs, peak, expiry, and host-only listening recap')


def http_management_scenario(Client, admin, venue, outsider, site):
    guest = Client()
    session = venue.call('create', data={'title': 'Management source', 'remote_listening': True})['id']
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('DROP INDEX jukebox_sessions_owner_directory')
    assert venue.call('settings', session)['ok']
    assert venue.call('settings', session)['ok']
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        assert [row[2] for row in db.execute('PRAGMA index_info(jukebox_sessions_owner_directory)')] == [
            'owner_id', 'ended', 'created', 'id']
        assert sum(row[1] == 'jukebox_sessions_owner_directory'
                   for row in db.execute('PRAGMA index_list(jukebox_sessions)')) == 1
    assert guest.call('join', session, {'name': 'Keep this guest'})['ok']
    phone = guest.call('state', session)['state']['phone']['id']
    assert venue.call('approve', session, {'phone': phone})['ok']
    for track, nonce in (('a', 'a'*32), ('b', 'b'*32)):
        assert guest.call('request', session, {'track': track, 'nonce': nonce})['ok']
    player = 'e'*64
    assert venue.call('player', session, {'player': player, 'event': 'claim'})['ok']
    before = venue.call('state', session)['state']
    item = before['current']['id']
    assert venue.call('player', session, player_sample(player, 'playing', item, 1, 1000,
                                                       observed_revision=before['revision']))['ok']
    assert guest.call('message', session, {'text': 'Keep the conversation'})['ok']
    before = venue.call('state', session)['state']
    expected = venue.call('settings', session)['settings']
    assert expected == dict(title='Management source', listed=False, remote_listening=True,
                            scheduled_at=None, phase='live')
    for unauthorized in (guest, outsider):
        assert unauthorized.call('settings', session)['status'] in (403, 404, 409)
        assert unauthorized.call('settings', session, {'expected': expected, 'title': 'Intrusion'})['status'] in (403, 404, 409)
    for invalid in ({'title': ''}, {'title': []}, {'listed': 'false'},
                    {'remote_listening': 1}, {'scheduled_at': int(time.time()) + 86400}):
        result = venue.call('settings', session, {'expected': expected, **invalid})
        assert result['status'] in (400, 409), result
    assert venue.call('settings', session, {'title': 'Missing snapshot'})['status'] in (400, 409)
    assert venue.call('settings', session, {'expected': expected, 'listed': True})['status'] in (403, 409)
    assert venue.call('settings', session)['settings'] == expected

    # Player heartbeats increment revision while a host edits. They must not
    # invalidate a settings snapshot or restart the active track.
    assert venue.call('player', session, player_sample(player, 'playing', item, 2, 1500,
                                                       observed_revision=before['revision']))['ok']
    saved = venue.call('settings', session, {'expected': expected, 'title': 'Renamed while playing'})
    assert saved['ok'], saved
    after = venue.call('state', session)['state']
    assert after['id'] == session and after['title'] == 'Renamed while playing'
    assert after['current'] == before['current'] and after['queue'] == before['queue']
    assert after['phones'] == before['phones'] and after['history'] == before['history']
    assert after['transport']['status'] == 'playing'
    assert venue.call('thread', session, phone=phone, full=1)['messages'][0]['body'] == 'Keep the conversation'
    assert guest.call('state', session)['state']['phone']['id'] == phone
    assert venue.call('settings', session, {'expected': expected, 'title': 'Stale overwrite'})['status'] == 409
    current = venue.call('settings', session)['settings']
    assert admin.call('settings', session)['settings'] == current
    assert admin.call('settings', session, {'expected': current, 'title': 'Admin reviewed'})['ok']
    current = venue.call('settings', session)['settings']
    assert current['title'] == 'Admin reviewed'

    # Reusing settings creates a distinct session, never copies its queue,
    # phone approvals, conversation, player claim, or its original start time.
    copy = venue.call('create', data={key: current[key] for key in ('title', 'listed', 'remote_listening')})['id']
    copied = venue.call('state', copy)['state']
    assert copy != session and copied['phase'] == 'live' and copied['scheduled_at'] is None
    assert copied['phones'] == copied['queue'] == copied['history'] == [] and copied['current'] is None
    assert copied['chat_threads'] == [] and not copied['online']
    assert not guest.call('state', copy)['state'].get('phone')

    assert venue.call('control', session, {'event': 'end'})['ok']
    recap = venue.call('recap', session)['recap']
    archived = venue.call('settings', session)['settings']
    assert archived['phase'] == 'ended' and archived['remote_listening'] is True
    assert venue.call('settings', session, {'expected': archived, 'remote_listening': False})['status'] == 409
    assert venue.call('settings', session, {'expected': archived, 'title': 'Archive title'})['ok']
    renamed_recap = venue.call('recap', session)['recap']
    assert renamed_recap == {**recap, 'title': 'Archive title'}

    future = int(time.time()) + 86400
    upcoming = admin.call('create', data={'title': 'Future settings', 'listed': True,
                                         'remote_listening': True, 'scheduled_at': future})['id']
    follower = Client(3)
    follower.host = False
    assert follower.call('follow', upcoming, {'following': True})['ok']
    original = admin.call('settings', upcoming)['settings']
    for invalid_time in (None, future - 86460, str(future), True):
        assert admin.call('settings', upcoming, {'expected': original, 'scheduled_at': invalid_time})['status'] == 400
    updated = future + 3600
    assert admin.call('settings', upcoming, {'expected': original, 'title': 'Rescheduled settings',
                                           'scheduled_at': updated})['ok']
    assert guest.call('state', upcoming)['state']['scheduled_at'] == updated
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        alerts = db.execute('SELECT event_type,event_at,state FROM jukebox_alerts WHERE session_id=? ORDER BY id',
                            (upcoming,)).fetchall()
    assert ('reminder', future, 'obsolete') in alerts
    assert ('rescheduled', updated, 'pending') in alerts and ('reminder', updated, 'pending') in alerts
    original = admin.call('settings', upcoming)['settings']
    assert admin.call('settings', upcoming, {'expected': original, 'title': 'Rename only'})['ok']
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        assert db.execute('SELECT event_type,event_at,state FROM jukebox_alerts WHERE session_id=? ORDER BY id',
                          (upcoming,)).fetchall() == alerts
    assert admin.call('control', upcoming, {'event': 'cancel'})['ok']
    canceled = admin.call('settings', upcoming)['settings']
    assert canceled['phase'] == 'canceled'
    assert admin.call('settings', upcoming, {'expected': canceled, 'title': 'Canceled title'})['ok']
    assert admin.call('settings', upcoming, {'expected': {**canceled, 'title': 'Canceled title'},
                                           'scheduled_at': updated + 3600})['status'] == 409
    assert admin.call('recap', upcoming)['status'] == 409

    # Seed enough older records to exceed the former 50-session directory.
    # Stable cursors must expose every active and archived row once, including
    # canceled sessions, while another session arrives between pages.
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        rows = [(f'{9000 + index:032x}', 2, 2, f'Older {index}', 0,
                 int(index < 28), 1000 + index) for index in range(60)]
        db.executemany('INSERT INTO jukebox_sessions(id,owner_id,venue_id,title,listed,ended,created) VALUES(?,?,?,?,?,?,?)', rows)
        canceled_id = rows[0][0]
        db.execute('INSERT INTO jukebox_schedule(session_id,scheduled_at) VALUES(?,?)', (canceled_id, future))
        expected_ids = {}
        for scope, ended in (('active', 0), ('archive', 1)):
            expected_ids[scope] = [row[0] for row in db.execute(
                'SELECT id FROM jukebox_sessions WHERE owner_id=2 AND ended=? ORDER BY created DESC,id DESC', (ended,))]
    assert rows[1][0] not in [row['id'] for row in venue.call('directory')['sessions']]
    for scope in ('active', 'archive'):
        ids, cursor = [], None
        while True:
            page = venue.call('directory', scope=scope, limit=7, **({'cursor': cursor} if cursor else {}))
            assert page['ok'] and page['scope'] == scope and len(page['sessions']) <= 7
            for row in page['sessions']:
                assert row['phase'] in (('live', 'upcoming') if scope == 'active' else ('ended', 'canceled'))
            ids.extend(row['id'] for row in page['sessions'])
            if len(ids) == len(page['sessions']):
                arrived = venue.call('create', data={'title': 'Arrived during pagination'})['id']
                with sqlite3.connect(site / 'config/fixture.sqlite') as db:
                    db.execute('UPDATE jukebox_sessions SET created=? WHERE id=?', (int(time.time()) + 5, arrived))
                if scope == 'archive':
                    assert venue.call('control', arrived, {'event': 'end'})['ok']
            cursor = page['next_cursor']
            if not cursor:
                break
        assert arrived not in ids
        assert ids == expected_ids[scope] and len(ids) == len(set(ids)), (scope, ids, expected_ids[scope])
    assert venue.call('recap', rows[1][0])['recap']['totals']['requests'] == 0
    assert venue.call('recap', canceled_id)['status'] == 409
    assert guest.call('directory', scope='archive')['status'] in (400, 403, 409)
    assert outsider.call('directory', scope='archive')['sessions'] == []
    assert venue.call('directory', scope='invalid')['status'] == 400
    assert venue.call('directory', scope='archive', cursor='broken')['status'] == 400
    assert upcoming not in [row['id'] for row in venue.call('directory', scope='archive')['sessions']]
    print('PASS HTTP session settings validation, roles, safe concurrent edits, preserved playback/chat, reuse isolation, alerts, and archive pagination')


def http_schedule_scenario(Client, admin, venue, outsider, site):
    guest = Client()
    now = int(time.time())
    future = now + 86_400
    for invalid in (now - 60, str(future), future + 0.5, True):
        response = admin.call('create', data={'title': 'Invalid start', 'scheduled_at': invalid})
        assert response['status'] == 400, response

    immediate = admin.call('create', data={'title': 'Immediate', 'listed': True, 'scheduled_at': None})['id']
    public = admin.call('create', data={'title': 'Public scheduled', 'listed': True,
                                      'scheduled_at': future, 'remote_listening': True})['id']
    private = venue.call('create', data={'title': 'Private scheduled', 'scheduled_at': future,
                                        'remote_listening': True})['id']

    def directory(client):
        return {row['id']: row for row in client.call('directory')['sessions']}

    def state(client, session):
        return client.call('state', session)['state']

    listings = directory(guest)
    assert listings[immediate]['phase'] == 'live' and listings[immediate]['scheduled_at'] is None
    assert listings[public]['phase'] == 'upcoming' and listings[public]['scheduled_at'] == future
    assert listings[public]['started_at'] is None and private not in listings
    assert directory(venue)[private]['phase'] == 'upcoming'
    assert admin.call('control', immediate, {'event': 'start'})['status'] == 409

    player = 'd'*64
    for session, host in ((public, admin), (private, venue)):
        waiting = state(guest, session)
        assert waiting['phase'] == 'upcoming' and waiting['scheduled_at'] == future
        assert waiting['started_at'] is None and not waiting.get('remote_listening')
        assert not waiting.get('current') and not waiting.get('queue') and not waiting.get('phone')
        assert guest.call('catalog', session)['status'] == 409
        assert guest.call('thread', session)['status'] == 409
        assert guest.call('join', session, {'name': 'Early arrival'})['status'] == 409
        assert guest.call('request', session, {'track': 'a', 'nonce': '1'*32})['status'] == 409
        assert guest.call('listener', session, listener_sample('1'*32, '1', 0, 1))['status'] == 409
        with guest.response('audio', session, item='1', method='HEAD') as response:
            assert response.status == 404
        assert host.call('catalog', session)['status'] == 409
        assert host.call('request', session, {'track': 'a', 'nonce': '1'*32})['status'] == 409
        assert host.call('player', session, {'player': player, 'event': 'claim'})['status'] == 409
        for event in ('start', 'reschedule', 'cancel'):
            change = {'event': event, 'scheduled_at': future + 120}
            assert guest.call('control', session, change)['status'] == 409
            assert outsider.call('control', session, change)['status'] == 404
        assert host.call('control', session, {'event': 'end'})['status'] == 409

    assert admin.call('control', public, {'event': 'reschedule', 'scheduled_at': now - 60})['status'] == 400
    updated = future + 3600
    assert admin.call('control', public, {'event': 'reschedule', 'scheduled_at': updated})['ok']
    assert state(guest, public)['scheduled_at'] == directory(guest)[public]['scheduled_at'] == updated

    # Cross the published time without waiting or implicitly starting playback.
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('UPDATE jukebox_schedule SET scheduled_at=? WHERE session_id IN (?,?)', (now - 60, public, private))
    assert directory(guest)[public]['phase'] == 'upcoming'
    assert state(guest, private)['phase'] == 'upcoming'
    assert directory(venue)[private]['phase'] == 'upcoming'
    assert guest.call('join', private, {})['status'] == 409

    assert admin.call('control', public, {'event': 'cancel'})['ok']
    canceled = state(guest, public)
    assert canceled['phase'] == 'canceled' and canceled['started_at'] is None
    assert public not in directory(guest) and directory(admin)[public]['phase'] == 'canceled'
    assert admin.call('control', public, {'event': 'start'})['status'] == 409
    assert admin.call('recap', public)['status'] == 409
    assert guest.call('join', public, {})['status'] == 409

    assert venue.call('control', private, {'event': 'start'})['ok']
    live = state(guest, private)
    assert live['phase'] == 'live' and live['scheduled_at'] == now - 60
    assert now <= live['started_at'] <= time.time()
    assert private not in directory(guest)

    # Give the retry a prior-second timestamp to ensure an accidental rewrite is detectable.
    started = now - 10
    with sqlite3.connect(site / 'config/fixture.sqlite') as db:
        db.execute('UPDATE jukebox_schedule SET started_at=? WHERE session_id=?', (started, private))
    assert venue.call('control', private, {'event': 'start'})['ok']
    assert state(guest, private)['started_at'] == started
    assert venue.call('control', private, {'event': 'reschedule', 'scheduled_at': updated})['status'] == 409
    assert venue.call('control', private, {'event': 'cancel'})['status'] == 409
    assert state(guest, private)['phase'] == 'live'
    assert guest.call('request', private, {'track': 'a', 'nonce': '1'*32})['status'] == 409
    assert guest.call('join', private, {'name': 'On time'})['ok']
    phone_id = state(guest, private)['phone']['id']
    assert venue.call('approve', private, {'phone': phone_id})['ok']
    assert guest.call('catalog', private)['ok']
    assert guest.call('request', private, {'track': 'a', 'nonce': '1'*32})['ok']
    assert guest.call('request', private, {'track': 'b', 'nonce': '2'*32})['ok']
    assert venue.call('player', private, {'player': player, 'event': 'claim'})['ok']
    playing = state(venue, private)
    current = playing['current']['id']
    assert [row['track_id'] for row in playing['queue']] == ['b']
    assert venue.call('player', private, player_sample(player, 'playing', current, 1, 1000,
                                                       observed_revision=playing['revision']))['ok']
    assert state(guest, private)['remote_listening'] is True
    with guest.response('audio', private, item=current, headers={'Range': 'bytes=0-7'}) as response:
        assert response.status == 206 and len(response.read()) == 8
    assert venue.call('control', private, {'event': 'end'})['ok']
    assert state(guest, private)['phase'] == 'ended'
    recap = venue.call('recap', private)['recap']
    assert recap['session_id'] == private and recap['phones'] == {'connections': 1, 'approved': 1}
    assert recap['phase'] == 'ended' and recap['scheduled_at'] == now - 60 and recap['started_at'] == started
    assert recap['totals']['confirmed_plays'] == 1 and recap['totals']['left_in_queue'] == 1
    print('PASS HTTP schedule validation, discovery, waiting access, host controls, and explicit playback lifecycle')


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
                    if not any(option in sys.argv for option in ('--remote-only', '--schedule-only', '--management-only', '--recovery-only', '--branding-only')):
                        scenario(browser, base, phone_options)
                        for context in browser.contexts:
                            context.close()
                    if '--remote-only' not in sys.argv and '--management-only' not in sys.argv and '--recovery-only' not in sys.argv and '--branding-only' not in sys.argv:
                        schedule_scenario(browser, base, phone_options)
                        for context in browser.contexts:
                            context.close()
                    if '--schedule-only' not in sys.argv and '--management-only' not in sys.argv and '--recovery-only' not in sys.argv and '--branding-only' not in sys.argv:
                        remote_scenario(browser, base, phone_options)
                        for context in browser.contexts:
                            context.close()
                    if '--remote-only' not in sys.argv and '--schedule-only' not in sys.argv and '--recovery-only' not in sys.argv and '--branding-only' not in sys.argv:
                        management_scenario(browser, base, phone_options, site)
                        for context in browser.contexts:
                            context.close()
                    if not any(option in sys.argv for option in ('--remote-only', '--schedule-only', '--management-only', '--branding-only')):
                        recovery_scenario(browser, base, phone_options, site)
                        for context in browser.contexts:
                            context.close()
                    if not any(option in sys.argv for option in ('--remote-only', '--schedule-only', '--management-only', '--recovery-only')):
                        branding_scenario(browser, base, phone_options, site)
        except Exception:
            log.seek(0)
            print(log.read()[-16000:])
            raise
        finally:
            server.terminate()
            server.wait(timeout=5)
