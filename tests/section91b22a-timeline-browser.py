"""Production song timeline and orb controls in Chromium/WebKit with
synthetic catalog rows and media events. No audio, microphone or AI provider.
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
        page.set_content('<html lang="en"><head></head><body><div class="agent-stage"></div><main id="orbCanvas"></main>'
                         '<div class="player-timeline"><input id="seek" type="range" aria-label="Song playback"></div>'
                         '<audio id="audio"></audio></body></html>')
        page.add_style_tag(content=(ROOT / 'assets/css/song-moments.css').read_text())
        page.add_style_tag(content='body{margin:0;background:#0a1019;color:white;font-family:system-ui}'
                           '.player-timeline{margin:24px;width:calc(100% - 48px)}#seek{width:100%}'
                           '.agent-stage{position:fixed;left:180px;top:160px}')
        page.evaluate('''() => {
          window.gets=[];window.unexpected=[];window.references=[];window.presented=[];window.narrated=[];
          window.mics=0;window.recordings=0;window.captures=0;window.pending=[];window.defer=false;
          window.playPosition=10;window.paused=true;window.orbEnabled=false;window.orbOpened=[];
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
          window.moment=(id,at_ms,kind,title,extra={})=>({id,track_id:'orb-song',kind,at_ms,title,body:title+' details',
            orb_eligible:true,...extra});
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
            orbEnabled:()=>orbEnabled&&window.SFPublicAgentCanvas?.active()===true,
            onOrbMoment:row=>window.SFPublicAgentCanvas?.setSongMoment?.(row),
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

        page.add_style_tag(content=(ROOT / 'assets/css/public-agent-canvas.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/public-agent-canvas.js').read_text())
        page.evaluate('''() => {
          timeline.close();defer=false;paused=false;
          SFPublicAgentCanvas.mount(document.querySelector('#orbCanvas'),{audio,
            onOpenMoments:()=>timeline.open(),onOpenMoment:(id,trigger)=>{
              orbOpened.push(Number(id));timeline.openMoment(id,trigger);
            }});
          SFPublicAgentCanvas.showConversation('assistant','Keep the current chat reply');
          rows=[
            moment(10,2000,'story','Story while disabled',{story:{title:'Story while disabled',summary:'Disabled cue',slug:'disabled'}}),
            moment(11,4000,'note','Ordinary artist note',{orb_eligible:false}),
            row(12,4100,4200,'Lyric is not an orb cue','orb-song'),
            moment(13,4500,'comment','Unmoderated comment',{orb_eligible:false}),
            moment(14,6000,'story','Writing the second verse',{story:{title:'Writing the second verse',summary:'A memory from the rehearsal room',slug:'second-verse'}}),
            moment(17,7900,'comment','Cue skipped by seeking'),
            moment(15,12000,'comment','A public fan observation'),
            moment(16,18000,'photo','A photo from the studio')
          ];
          tick(0,'seeked');timeline.setTrack({id:'orb-song',title:'Orb fixture song'});
        }''')
        page.wait_for_function('document.querySelector("#sfSongMomentsEntries").textContent.includes("Writing the second verse")')
        cue = page.locator('[data-agent-action="open-song-moment"]')
        drawer = page.locator('.sf-song-moment-drawer')
        chat = page.locator('.sf-agent-live-message')
        page.evaluate('tick(1.8);tick(2.1)')
        check(not cue.is_visible() and not drawer.is_visible()
              and page.locator('.sf-song-orb-card:visible').count() == 0
              and page.evaluate('presented.length===0&&narrated.length===0'),
              label + ': disabled feature leaves playback quiet')
        page.evaluate('orbEnabled=true;tick(3.8);tick(4.6)')
        check(not cue.is_visible(), label + ': ordinary notes, lyrics, and ineligible comments produce no orb cue')
        page.evaluate('tick(5.8);tick(6.1)')
        check(cue.is_visible() and 'Writing the second verse' in cue.inner_text(), label + ': crossing a published moment shows its orb cue')
        box = cue.bounding_box()
        check(box is not None and box['x'] >= 0 and box['x'] + box['width'] <= width + 1 and box['height'] >= 44,
              label + ': orb cue fits the viewport with a touch target')
        check(not drawer.is_visible() and 'Keep the current chat reply' in chat.inner_text() and page.evaluate('narrated.length===0'),
              label + ': passive orb cue preserves chat and does not open a drawer or speak')
        cue.click()
        check(drawer.is_visible() and 'A memory from the rehearsal room' in drawer.inner_text()
              and page.evaluate('JSON.stringify(orbOpened)==="[14]"&&playPosition===6.1&&document.activeElement.textContent.includes("Writing the second verse")'),
              label + ': selecting the orb cue opens the matching moment without seeking')
        drawer.locator('#sfSongMomentSubmit').focus()
        page.keyboard.press('Tab')
        first = page.evaluate('document.activeElement.id')
        page.keyboard.press('Shift+Tab')
        check(first == 'sfSongMomentsClose' and page.evaluate('document.activeElement.id') == 'sfSongMomentSubmit',
              label + ': keyboard navigation wraps inside the open timeline')
        page.keyboard.press('Escape')
        check(not drawer.is_visible() and cue.evaluate('(node)=>node===document.activeElement'),
              label + ': closing the moment restores keyboard focus to the orb cue')
        page.screenshot(path=str(OUT / f'orb-moment-{BROWSER}-{width}.png'), full_page=True)
        page.evaluate('paused=true;tick(6.1,"pause");tick(6.15)')
        check(cue.is_visible() and 'Writing the second verse' in cue.inner_text(),
              label + ': pausing preserves the current cue')
        page.evaluate('paused=false;tick(6.15,"play");tick(8.1,"seeking");tick(8.1);tick(8.1,"seeked");tick(8.2)')
        check(not cue.is_visible() and not drawer.is_visible(),
              label + ': seeking clears a prior cue without surfacing a skipped moment')
        page.evaluate('tick(11.7,"seeking");tick(11.7,"seeked");tick(12.1)')
        check(cue.is_visible() and 'A public fan observation' in cue.inner_text(),
              label + ': normal playback after seeking can surface a new approved cue')
        page.evaluate('orbEnabled=false;tick(12.2)')
        check(not cue.is_visible(), label + ': turning the feature off clears its active cue')
        page.evaluate('''() => {
          orbEnabled=true;tick(17.8,'seeked');tick(18.1);
        }''')
        check(cue.is_visible() and 'A photo from the studio' in cue.inner_text(),
              label + ': normal playback can surface an eligible photo cue')
        page.evaluate('''() => {
          defer=true;timeline.setTrack({id:'orb-old',title:'Previous orb song'});
          timeline.setTrack({id:'orb-new',title:'Current orb song'});
        }''')
        check(not cue.is_visible() and not drawer.is_visible(), label + ': changing songs clears the previous cue')
        page.evaluate('''() => {
          reply(pending.length-1,[moment(20,20000,'comment','Current song cue',{track_id:'orb-new'})]);
          reply(pending.length-2,[moment(21,20000,'comment','Stale old song cue',{track_id:'orb-old'})]);
        }''')
        page.wait_for_function('document.querySelector("#sfSongMomentsEntries").textContent.includes("Current song cue")')
        page.evaluate('tick(19.8,"seeked");tick(20.1)')
        check(cue.is_visible() and 'Current song cue' in cue.inner_text() and 'Stale old song cue' not in drawer.text_content(),
              label + ': a delayed old song response cannot introduce a stale cue')
        page.evaluate('tick(20.1,"ended")')
        check(not cue.is_visible(), label + ': completing playback clears the cue')
        page.evaluate('orbEnabled=false;document.querySelector("[data-orbit-action=moments]").click()')
        check(drawer.is_visible() and 'Current song cue' in drawer.inner_text(),
              label + ': the manual Song Timeline remains available while orb cues are off')
        page.evaluate('''() => {
          timeline.close();orbEnabled=true;timeline.setTrack({id:'orb-intro',title:'Intro song'});
          tick(0.2);
          reply(pending.length-1,[moment(22,0,'story','Opening story cue',{track_id:'orb-intro'})]);
        }''')
        page.wait_for_function('document.querySelector("#sfSongMomentsEntries").textContent.includes("Opening story cue")')
        page.evaluate('tick(0.4)')
        check(cue.is_visible() and 'Opening story cue' in cue.inner_text(),
              label + ': an opening cue survives a quick first timeline fetch')
        page.evaluate('''() => {
          SFPublicAgentCanvas.openPage('story');
          document.querySelector('[data-agent-action="open-song-moment"]').click();
        }''')
        page.wait_for_timeout(850)
        check(drawer.is_visible() and drawer.evaluate('(node)=>node.contains(document.activeElement)'),
              label + ': an underlying page cannot take focus from the moment drawer')
        page.keyboard.press('Escape')
        check(not drawer.is_visible() and page.locator('.sf-agent-page-rail').is_visible(),
              label + ': Escape closes only the topmost drawer')
        page.evaluate('SFPublicAgentCanvas.unmount()')
        check(page.evaluate('mics===0&&recordings===0&&captures===0&&unexpected.length===0&&gets.every(r=>r.method==="GET")') and not requests, label + ': no provider, microphone, capture or external network use')
        page.evaluate('timeline.destroy()')
        check(page.locator('.sf-song-moment-drawer').count() == 0 and not errors, label + ': cleanup removes controls without browser exceptions')
        context.close()
    browser.close()
print(json.dumps({'checks': len(checks), 'passed': len(checks), 'browser': BROWSER,
                  'scope': 'Production timeline and orb with synthetic catalog data and playback events'}))
