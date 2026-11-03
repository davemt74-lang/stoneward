"""Production song timeline controls in Chromium/WebKit with synthetic catalog
rows. No real playback, microphone, transcription or AI provider requests.
"""
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
BROWSER = os.environ.get('SF_LYRICS_BROWSER', 'chromium')
OUT = Path(os.environ.get('SF_LYRICS_EVIDENCE', 'lyric-timeline-evidence'))
OUT.mkdir(parents=True, exist_ok=True)
checks = []


def check(value, label):
    if not value:
        page.screenshot(path=str(OUT / f'timeline-failure-{BROWSER}-{width}.png'), full_page=True)
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


with sync_playwright() as p:
    launch = {'headless': True}
    if BROWSER == 'chromium':
        launch['args'] = ['--no-sandbox']
        executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
        if executable:
            launch['executable_path'] = executable
    browser = getattr(p, BROWSER).launch(**launch)
    for width in (375, 1440):
        context = browser.new_context(viewport={'width': width, 'height': 850}, reduced_motion='reduce')
        page = context.new_page()
        errors = []
        requests = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('request', lambda request: requests.append(request.url))
        # The application module's fetch is substituted below. This fixture
        # intentionally has no HTTP server, account, media source or provider.
        page.set_content('<html lang="en"><head></head><body><div class="agent-stage"></div>'
                         '<div class="player-timeline"><input id="seek" type="range" aria-label="Song playback"></div>'
                         '<audio id="audio"></audio></body></html>')
        page.add_style_tag(content=(ROOT / 'assets/css/song-moments.css').read_text())
        page.add_style_tag(content='body{margin:0;background:#0a1019;color:white;font-family:system-ui}'
                           '.player-timeline{margin:24px;width:calc(100% - 48px)}#seek{width:100%}'
                           '.agent-stage{position:fixed;left:180px;top:160px}')
        page.evaluate('''() => {
          window.gets=[];window.unexpected=[];window.references=[];window.presented=[];window.narrated=[];
          window.mics=0;window.recordings=0;window.captures=0;window.pending=[];window.defer=false;
          window.playPosition=10;window.paused=true;
          window.MediaRecorder=class{constructor(){recordings++;throw Error('Unexpected recording')}};
          Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia(){mics++;throw Error('Unexpected microphone')}}});
          window.audio=document.querySelector('#audio');
          Object.defineProperty(audio,'paused',{configurable:true,get:()=>paused});
          Object.defineProperty(audio,'duration',{configurable:true,get:()=>240});
          Object.defineProperty(audio,'currentTime',{configurable:true,get:()=>playPosition,set:value=>{playPosition=Number(value)}});
          audio.captureStream=()=>{captures++;throw Error('Unexpected audio capture')};
          window.tick=(seconds,event='timeupdate')=>{playPosition=seconds;audio.dispatchEvent(new Event(event))};
          window.row=(id,start,end,body,track='song-a',extra={})=>({id,track_id:track,kind:'lyric',at_ms:start,end_ms:end,
            body,status:'published',lyric_state:'approved',confidence:null,timing_source:'manual',...extra});
          window.rows=[row(1,10001,20002,'A stored lyric phrase'),
            row(2,20002,30003,'A second saved lyric phrase','song-a',{timing_source:'catalog_chunk_estimate'}),
            row(3,30003,40004,'<script>saved words stay text</script>')];
          window.response=moments=>({ok:true,json:async()=>({ok:true,moments,mine:[],can_comment:false})});
          window.fetch=(url,options={})=>{
            const value=String(url);gets.push({url:value,method:options.method||'GET'});
            if(!value.startsWith('api/song-moments.php?track_id=')||options.method&&options.method!=='GET'){
              unexpected.push(value);return Promise.reject(Error('Unexpected provider/application request: '+value));
            }
            if(defer)return new Promise(resolve=>pending.push({url:value,resolve}));
            return Promise.resolve(response(rows));
          };
          window.reply=(index,moments)=>pending[index].resolve(response(moments));
        }''')
        page.add_script_tag(content=(ROOT / 'assets/js/song-moments.js').read_text())
        page.evaluate('''() => {
          window.timeline=StonefellowSongMoments.create({audio,seekInput:document.querySelector('#seek'),apiBase:'api',
            onLyricReference:row=>references.push(row),onPresent:row=>presented.push(row),onNarrate:row=>narrated.push(row)});
          timeline.setTrack({id:'song-a',title:'Stored song'});
        }''')
        page.wait_for_function('document.querySelectorAll(".sf-song-lyric-entry").length===3')
        page.evaluate('timeline.open()')
        label = f'{BROWSER}/{width}'
        entries = page.locator('.sf-song-lyric-entry')
        check(page.locator('.sf-song-moment-drawer').is_visible(), label + ': opens existing Song Timeline drawer')
        check('0:10.001 – 0:20.002' in entries.nth(0).inner_text(), label + ': renders precise saved lyric range')
        check('Approved lyrics' in entries.nth(0).inner_text() and 'Confidence: Unknown' in entries.nth(0).inner_text(), label + ': approval and unknown confidence stay distinct')
        check('Estimated block · line alignment unverified' in entries.nth(1).inner_text(), label + ': approximate upload blocks have an honest timing label')
        check(entries.nth(2).locator('script').count() == 0 and '<script>saved words stay text</script>' in entries.nth(2).text_content(), label + ': stored lyrics render as text')
        page.evaluate('tick(10.001)')
        check(entries.nth(0).get_attribute('aria-current') == 'true', label + ': first millisecond enters current lyric range')
        page.evaluate('tick(20.002,"seeked")')
        check(entries.nth(0).get_attribute('aria-current') is None and entries.nth(1).get_attribute('aria-current') == 'true', label + ': paused boundary seek switches exact lyric highlight')
        entries.nth(0).locator('.sf-song-moment-jump').click()
        check(page.evaluate('playPosition===10.001') and entries.nth(0).get_attribute('aria-current') == 'true', label + ': click lyric range seeks existing audio player')
        entries.nth(0).locator('.sf-song-lyric-reference').click()
        check(page.evaluate('references.length===1&&references[0].track_id==="song-a"&&references[0].at_ms===10001&&references[0].end_ms===20002'), label + ': explicit Ask callback carries saved song/time range')
        page.evaluate('paused=false;tick(9.5);tick(10.5);tick(20.5)')
        check(page.evaluate('presented.length===0&&narrated.length===0&&gets.length===1'), label + ': playback does not narrate lyrics or repeat transcription')
        bounds = page.locator('.sf-song-moment-drawer').evaluate('''node=>({width:node.getBoundingClientRect().width,
          right:node.getBoundingClientRect().right,scroll:node.querySelector('.sf-song-moment-scroll').scrollWidth,
          client:node.querySelector('.sf-song-moment-scroll').clientWidth,body:document.documentElement.scrollWidth})''')
        check(bounds['width'] <= width and bounds['right'] <= width + 1 and bounds['scroll'] <= bounds['client'] + 1 and bounds['body'] <= width,
              label + ': drawer and lyric controls fit viewport without horizontal overflow: ' + str(bounds))
        page.screenshot(path=str(OUT / f'timeline-{BROWSER}-{width}.png'), full_page=True)
        page.evaluate('''() => {
          defer=true;timeline.setTrack({id:'song-b',title:'New song'});timeline.setTrack({id:'song-c',title:'Newest song'});
          reply(1,[row(5,1000,3000,'Newest song approved words','song-c')]);
        }''')
        page.wait_for_function('document.querySelector("#sfSongMomentsEntries").textContent.includes("Newest song approved words")')
        page.evaluate('reply(0,[row(4,1000,3000,"Stale song words","song-b")])')
        page.wait_for_timeout(30)
        check(page.evaluate('timeline.getContext().track_id==="song-c"') and 'Stale song words' not in page.locator('#sfSongMomentsEntries').text_content(), label + ': late old-song response cannot replace current lyrics')
        check(page.evaluate('mics===0&&recordings===0&&captures===0&&unexpected.length===0&&gets.every(r=>r.method==="GET")') and not requests, label + ': no provider, microphone, capture or external network use')
        page.evaluate('timeline.destroy()')
        check(page.locator('.sf-song-moment-drawer').count() == 0 and not errors, label + ': cleanup removes controls without browser exceptions')
        context.close()
    browser.close()
print(json.dumps({'checks': len(checks), 'passed': len(checks), 'browser': BROWSER,
                  'scope': 'Production timeline with synthetic saved lyrics; no physical device or paid provider'}))
