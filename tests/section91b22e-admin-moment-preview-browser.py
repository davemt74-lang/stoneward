"""Exercise the production Song Moments editor with isolated, seekable audio."""
import io
import json
import os
import re
import shutil
import wave
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
BROWSER = os.environ.get('SF_LYRICS_BROWSER', 'chromium')
OUT = Path(os.environ.get('SF_LYRICS_EVIDENCE', 'saved-lyric-evidence'))
PAGE = 'https://stonefellow.test/fixture/admin/index.php'
FIRST, SECOND = 'M' + 'A' * 20, 'M' + 'B' * 20


def silence(seconds):
    data = io.BytesIO()
    with wave.open(data, 'wb') as pcm:
        pcm.setparams((1, 2, 8000, 0, 'NONE', 'not compressed'))
        pcm.writeframes(b'\0\0' * (8000 * seconds))
    return data.getvalue()


def run(browser, width):
    page = browser.new_page(viewport={'width': width, 'height': 900}, reduced_motion='reduce')
    page.set_default_timeout(8000)
    audio_requests = []
    unexpected = []
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    samples = {FIRST: silence(12), SECOND: silence(10)}

    def serve(route):
        req = route.request
        url = urlparse(req.url)
        if req.url == PAGE and req.method == 'GET':
            route.fulfill(content_type='text/html', body='<main id="preview-fixture"></main>')
            return
        uuid = parse_qs(url.query).get('id', [''])[0]
        if url.hostname != 'stonefellow.test' or url.path != '/fixture/api/media.php' or uuid not in samples or req.method != 'GET':
            unexpected.append((req.method, req.url))
            route.abort()
            return
        audio_requests.append(uuid)
        data = samples[uuid]
        match = re.fullmatch(r'bytes=(\d+)-(\d*)', req.headers.get('range', ''))
        if not match:
            route.fulfill(body=data, content_type='audio/wav', headers={'Accept-Ranges': 'bytes'})
            return
        first = int(match.group(1))
        last = min(int(match.group(2)) if match.group(2) else len(data) - 1, len(data) - 1)
        if first > last:
            route.fulfill(status=416, headers={'Content-Range': f'bytes */{len(data)}'})
            return
        route.fulfill(status=206, body=data[first:last+1], content_type='audio/wav',
                      headers={'Accept-Ranges': 'bytes', 'Content-Range': f'bytes {first}-{last}/{len(data)}'})

    # WebKit's native controls resolve internal same-page blob URLs for icons.
    page.route(re.compile(r'^https?://'), serve)
    try:
        page.goto(PAGE)
        page.add_style_tag(content=(ROOT / 'admin/assets/song-moments.css').read_text())
        page.evaluate('''([first,second]) => {
          window.calls=[]; window.confirmations=[];
          window.confirm=message=>{confirmations.push(message);return true};
          window.fixture={
            entries:[{id:1,track_id:'first',at_ms:1000,end_ms:5000,kind:'lyric',body:'Reviewed lyric',
              status:'published',lyric_state:'approved',confidence:.95,timing_source:'provider',revision:3}],
            references:{
              tracks:[
                {id:'first',title:'First song',duration:12,duration_ms:12000,preview_url:'api/media.php?id='+first},
                {id:'second',title:'Second song',duration:10,duration_ms:10000,preview_url:'api/media.php?id='+second},
                {id:'unavailable',title:'No public copy',duration:10,duration_ms:10000,preview_url:null},
                {id:'invalid',title:'Bad reference',duration:10,duration_ms:10000,preview_url:'https://example.invalid/private.wav'}
              ],
              stories:[
                {id:1,title:'Published first-song story',entity_type:'track',entity_id:'first'},
                {id:2,title:'Published second-song story',entity_type:'track',entity_id:'second'}
              ],
              photos:[{uuid:'MCCCCCCCCCCCCCCCCCCCC',title:'Public photo'}]
            }
          };
          window.STONEFELLOW_ADMIN_CONTEXT={app:{view:'song-moments'},canvas:document.querySelector('main'),
            api:async (...args)=>{calls.push(args);return fixture},head:()=>'',say:()=>{},
            esc:value=>String(value??'').replace(/[&<>"']/g,x=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]))};
        }''', [FIRST, SECOND])
        for script in ('song-moment-preview.js', 'song-moments.js'):
            page.add_script_tag(content=(ROOT / 'admin/assets' / script).read_text())
        page.evaluate("SFSongMomentsAdmin.open('timeline','first')")
        form = page.locator('#sfMomentAdminForm')
        field = lambda name: form.locator('[name="'+name+'"]')
        audio = form.locator('#sfMomentPreviewAudio')
        start = form.locator('#sfMomentUsePlayhead')
        jump = form.locator('#sfMomentJumpToStart')
        preview = form.locator('#sfMomentShowCue')
        cue = form.locator('#sfMomentCuePreview .sf-agent-song-cue')
        caption = form.locator('#sfMomentCuePreview [data-moment-cue-status]')
        expect(start).to_be_enabled()
        assert audio.evaluate('node=>node.paused && node.duration===12')
        assert FIRST in audio_requests

        field('kind').select_option('story')
        field('story_id').select_option('1')
        field('title').fill('<Unsaved story title>')
        field('body').fill('An unsaved description')
        audio.evaluate('node=>{node.currentTime=2.345}')
        expect(start).to_be_enabled()
        start.click()
        expect(field('position')).to_have_value('0:02.345')
        expect(field('status')).to_have_value('draft')
        expect(field('title')).to_have_value('<Unsaved story title>')
        expect(field('body')).to_have_value('An unsaved description')
        expect(cue).to_be_hidden()
        preview.click()
        expect(cue).to_contain_text('0:02 · Story')
        expect(cue).to_contain_text('<Unsaved story title>')
        assert cue.locator('unsaved').count() == 0
        expect(caption).to_contain_text('Private preview')
        expect(preview).to_have_attribute('aria-expanded', 'true')
        page.screenshot(path=str(OUT / f'admin-moment-preview-{BROWSER}-{width}.png'), full_page=True)
        preview.click()
        expect(preview).to_have_attribute('aria-expanded', 'false')
        expect(cue).to_be_hidden()
        preview.click()

        field('title').fill('')
        expect(cue).to_contain_text('Published first-song story')
        field('story_id').select_option('2')
        expect(cue).to_be_hidden()
        expect(caption).to_contain_text('linked to this song')
        field('kind').select_option('photo')
        field('media_uuid').select_option('MCCCCCCCCCCCCCCCCCCCC')
        expect(cue).to_contain_text('0:02 · Photo')
        expect(cue).to_contain_text('Public photo')
        field('kind').select_option('note')
        expect(cue).to_be_hidden()
        expect(caption).to_contain_text('without an orb cue')

        field('position').fill('0:04.125')
        jump.click()
        page.wait_for_function('''() => {
          const player=document.querySelector('#sfMomentPreviewAudio');
          return !player.seeking&&Math.abs(player.currentTime-4.125)<.001;
        }''')
        assert audio.evaluate('node=>node.paused')
        field('position').fill('0:21.000')
        expect(jump).to_be_disabled()

        field('track_id').select_option('second')
        expect(start).to_be_enabled()
        assert audio.evaluate('node=>node.paused&&node.duration===10&&node.currentTime===0')
        assert SECOND in audio_requests
        expect(field('body')).to_have_value('An unsaved description')
        for unavailable in ('unavailable', 'invalid'):
            field('track_id').select_option(unavailable)
            expect(audio).to_be_hidden()
            expect(start).to_be_disabled()
            assert audio.get_attribute('src') is None
        expect(field('position')).to_have_value('0:21.000')
        field('track_id').select_option('first')
        expect(start).to_be_enabled()
        audio.evaluate('async node=>{node.muted=true;await node.play();window.previousAudio=node}')
        assert audio.evaluate('node=>!node.paused')
        page.locator('[data-moment-tab="moderation"]').click()
        expect(audio).to_have_count(0)
        assert page.evaluate('previousAudio.paused&&!previousAudio.hasAttribute("src")')
        assert page.evaluate('confirmations.length') == 1

        page.locator('[data-moment-tab="timeline"]').click()
        page.locator('[data-moment-edit="1"]').click()
        expect(start).to_be_enabled()
        audio.evaluate('node=>{node.currentTime=3.210}')
        end = form.locator('#sfMomentUseEndPlayhead')
        expect(end).to_be_enabled()
        end.click()
        expect(field('end_position')).to_have_value('0:03.210')
        expect(field('lyric_state')).to_have_value('corrected')
        expect(field('timing_source')).to_have_value('manual')
        expect(field('confidence')).to_have_value('')
        expect(field('status')).to_have_value('draft')
        expect(field('revision')).to_have_value('3')
        preview.click()
        expect(caption).to_contain_text('without an orb cue')
        audio.evaluate('async node=>{node.muted=true;await node.play();window.previousAudio=node}')
        admin = (ROOT / 'admin/assets/admin.js').read_text()
        begin = admin.index('  const openExistingAdminView=openView;')
        end = admin.index('  window.STONEFELLOW_ADMIN_CONTEXT=', begin)
        page.evaluate('''() => {window.openView=view=>{
          STONEFELLOW_ADMIN_CONTEXT.app.view=view;
          document.querySelector('main').textContent='Catalog';
        }}''')
        page.add_script_tag(content=admin[begin:end])
        page.evaluate("openView('catalog')")
        assert page.evaluate('previousAudio.paused&&!previousAudio.hasAttribute("src")')
        expect(form).to_have_count(0)
        assert page.evaluate("JSON.stringify(calls)===JSON.stringify([['song-moments.php']])")
        assert not unexpected and not errors, (unexpected, errors)
        assert page.evaluate('document.documentElement.scrollWidth') <= width + 1
        print(f'PASS {BROWSER}/{width}: published audio, exact timing, private cues, playback cleanup, lyric review, no writes')
    except Exception:
        page.screenshot(path=str(OUT / f'admin-moment-preview-failed-{BROWSER}-{width}.png'), full_page=True)
        raise
    finally:
        page.close()


OUT.mkdir(parents=True, exist_ok=True)
with sync_playwright() as playwright:
    options = {'headless': True}
    if BROWSER == 'chromium':
        options['args'] = ['--no-sandbox']
        executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
        if executable:
            options['executable_path'] = executable
    browser = getattr(playwright, BROWSER).launch(**options)
    for width in (375, 1440):
        run(browser, width)
    browser.close()
