"""Production lyric controller/actions/rail in Chromium or WebKit, with synthetic
playback and HTTP responses. No microphone, recordings or paid provider calls.
"""
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
BROWSER = os.environ.get('SF_LYRICS_BROWSER', 'chromium')
OUT = Path(os.environ.get('SF_LYRICS_EVIDENCE', 'catalog-lyric-evidence'))
OUT.mkdir(parents=True, exist_ok=True)
APP = (ROOT / 'assets/js/app.js').read_text()
checks = []


def block(start, end):
    a = APP.index(start)
    b = APP.index(end, a)
    return APP[a:b]


def check(value, label):
    if not value:
        capture_evidence(page, width, 'failure')
    assert value, label
    checks.append(label)
    print('PASS', label, flush=True)


def visible_text(locator):
    # innerText can include layout-dependent whitespace. Keep every word and
    # its case, while treating line wrapping as presentation rather than copy.
    return ' '.join(locator.inner_text().split())


def capture_evidence(page, width, stage):
    details = page.evaluate('''() => {
      const describe=selector=>{
        const node=document.querySelector(selector);if(!node)return null;
        const style=getComputedStyle(node),rect=node.getBoundingClientRect();
        return {innerText:node.innerText,textContent:node.textContent,hidden:node.hidden,
          className:node.className,display:style.display,visibility:style.visibility,
          opacity:style.opacity,textTransform:style.textTransform,whiteSpace:style.whiteSpace,
          rect:{x:rect.x,y:rect.y,width:rect.width,height:rect.height}};
      };
      return {phase:songExperience?.phase,controller:songListeningSkill.status(),
        gets:gets.length,posts:posts.length,rail:describe('.sf-agent-page-rail'),
        viewport:describe('.sf-agent-page-viewport'),workspace:describe('.sf-agent-lyric-workspace'),
        phaseLabel:describe('.sf-agent-lyric-workspace .song-experience-phase'),
        orb:describe('.sf-agent-state'),stop:describe('[data-agent-action="stop-lyrics"]')};
    }''')
    name = f'catalog-{stage}-{BROWSER}-{width}'
    (OUT / f'{name}.json').write_text(json.dumps(details, ensure_ascii=False, indent=2))
    print('UI EVIDENCE', name, json.dumps(details, ensure_ascii=False), flush=True)
    page.screenshot(path=str(OUT / f'{name}.png'), full_page=True)
    return details


def wait_open_rail(page, width):
    # Each intentional opening runs openPage's two animation frames, including
    # reopening an existing draft. WebKit omits the still-transparent viewport
    # from innerText until this transition reaches its visible state.
    try:
        page.wait_for_function('''() => {
          const rail=document.querySelector('.sf-agent-page-rail');
          const viewport=document.querySelector('.sf-agent-page-viewport');
          return rail?.classList.contains('open')&&!rail.hidden&&viewport&&getComputedStyle(viewport).opacity==='1';
        }''')
    except Exception:
        capture_evidence(page, width, 'opening-failure')
        raise


with sync_playwright() as p:
    launcher = getattr(p, BROWSER)
    launch = {'headless': True}
    if BROWSER == 'chromium':
        launch['args'] = ['--no-sandbox']
        executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
        if executable:
            launch['executable_path'] = executable
    browser = launcher.launch(**launch)
    for width in (375, 1440):
        context = browser.new_context(
            viewport={'width': width, 'height': 850}, reduced_motion='reduce',
            user_agent='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1')
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.set_content('<html lang="en"><head></head><body class="sf-agent-canvas-active"><main id="target"></main><div id="player"><audio id="audio"></audio></div></body></html>')
        for name in ('assets/css/site.css', 'assets/css/agent-song-capture.css', 'assets/css/public-agent-canvas.css'):
            page.add_style_tag(content=(ROOT / name).read_text())
        page.add_style_tag(content='body{margin:0}#target{min-height:780px}.sf-agent-landing{height:780px!important;min-height:780px!important}')
        page.evaluate('''() => {
          window.recordings=0;window.mics=0;window.captures=0;window.interpretations=0;
          window.gets=[];window.posts=[];window.pending=[];window.pendingReady=[];window.deferReady=false;
          window.fakeClock=0;window.playPosition=60;
          Object.defineProperty(performance,'now',{configurable:true,value:()=>fakeClock});
          window.MediaRecorder=class{constructor(){recordings++;throw Error('Record Clip activated')}};
          Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia(){mics++;throw Error('Microphone activated')}}});
          window.audio=document.querySelector('audio');
          Object.defineProperty(audio,'paused',{configurable:true,get:()=>false});
          Object.defineProperty(audio,'seeking',{configurable:true,get:()=>false});
          Object.defineProperty(audio,'currentSrc',{configurable:true,get:()=> 'https://fixture.test/api/media.php?id=M123'});
          Object.defineProperty(audio,'currentTime',{configurable:true,get:()=>playPosition,set:v=>{playPosition=v}});
          audio.captureStream=()=>{captures++;throw Error('Player-track capture called on mobile')};
          window.advance=ms=>{fakeClock+=ms;playPosition+=ms/1000;audio.dispatchEvent(new Event('timeupdate'))};
          window.st={track:{id:'track-1',title:'Testing song',audio:'api/media.php?id=M123'},playing:true,auth:{csrf:'fixture-csrf'}};
          window.cfg={apiBase:'api'};
          window.songExperience=null;window.songListeningReceipt=null;window.songLyricDraft=null;
          window.songLyricReport=null;window.songLyricCorrected='';window.songPersonalNote='';
          window.songTranscriptionBusy=false;window.songAnalysisBusy=false;
          window.vocalStudio={status:()=>({active:false})};
          window.songCaptureSkill={status:()=>({active:false}),getCapture:()=>null,begin(){recordings++;throw Error('Record Clip fallback')}};
          window.renderPopupEntries=()=>{};window.openChatPopup=()=>{};window.loadAuth=async()=>{};
          window.response=value=>({ok:true,status:200,json:async()=>value});
          window.ready=()=>response({ok:true,available:true,source:'saved-catalog-lyrics',max_seconds:90,source_fingerprint:'a'.repeat(64)});
          window.fetch=(url,options={})=>{
            if(String(url).includes('song-lyrics-section.php?')){
              gets.push(String(url));
              if(deferReady)return new Promise((resolve,reject)=>{
                pendingReady.push(()=>resolve(ready()));
                options.signal?.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')),{once:true});
              });
              return Promise.resolve(ready());
            }
            if(String(url).endsWith('song-lyrics-section.php')&&options.method==='POST'){
              const payload=JSON.parse(options.body);posts.push(payload);
              if(options.headers['X-CSRF-Token']!=='fixture-csrf')throw Error('Missing CSRF token');
              return new Promise((resolve,reject)=>{
                pending.push({payload,resolve});
                options.signal?.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')),{once:true});
              });
            }
            throw Error('Unexpected provider or application request: '+url);
          };
          window.reply=(index,text='Words from the selected song')=>{
            const request=pending[index];request.resolve(response({ok:true,transcript:{...request.payload,
              source:'saved-catalog-lyrics',provider:'stored',model:'',review_status:'approved',timing:'saved_segments',
              selected_start_ms:request.payload.start_ms,selected_end_ms:request.payload.end_ms,
              segments:[{start_ms:request.payload.start_ms,end_ms:request.payload.end_ms,text,lyric_state:'approved',timing_source:'catalog_chunk_estimate'}],
              title:'Testing song',text}}));
          };
        }''')
        for name in ('assets/js/agent-song-experience-ui.js', 'assets/js/agent-song-listening-skill.js',
                     'assets/js/agent-song-catalog-listening.js', 'assets/js/public-agent-canvas.js'):
            page.add_script_tag(content=(ROOT / name).read_text())
        # Execute the production app action/routing/receipt code, not a second
        # implementation of the mobile lifecycle inside the fixture.
        page.add_script_tag(content='\n'.join((
            block('  async function finishSongListening(result){', '  let songTranscriptionBusy='),
            block('  function updateSongExperience(changes={},open=false){', '  async function songExperienceAction('),
            block('  async function executeSongCaptureAction(action){', '  // Private annotation commands'),
            block('  function currentSongDraft(){', '  async function executePrivateAnnotationCommand'),
            block('  async function secureJsonPost(', '  function applyQueue('),
        )))
        page.evaluate('''() => {
          window.StonefellowSongExperience={active:()=>!!songExperience,snapshot:()=>songExperience,
            mount:target=>SFSongExperienceUI.mount(target,songExperience&&{...songExperience,canAnalyze:!!currentSongDraft()},(action,text)=>{
              if(action==='stop')void executeSongCaptureAction({type:'song_capture_stop'});
              if(action==='edit'){songLyricCorrected=text;updateSongExperience({corrected:text})}
              if(action==='analyze')interpretations++;
            })};
          SFPublicAgentCanvas.mount(document.querySelector('#target'),{audio,player:document.querySelector('#player'),
            isCapturing:()=>songListeningSkill.status().active,isVocalRecording:()=>false,
            onListenInterpret:()=>void executeSongCaptureAction({type:'song_capture_live_start',interpret_on_stop:true}),
            onStopListening:()=>void executeSongCaptureAction({type:'song_capture_stop'})});
        }''')
        label = f'{BROWSER}/{width}'
        before = page.locator('[data-orbit-action="listen"]').evaluate('(e)=>[e.style.left,e.style.top]')
        page.locator('[data-orbit-action="listen"]').click(force=True)
        page.wait_for_function('songExperience?.phase === "capturing"')
        wait_open_rail(page, width)
        check(page.evaluate('songListeningSkill.mode() === "catalog"'), label + ': iPhone UA uses server catalog path')
        check(page.locator('.sf-agent-page-rail[data-view="lyrics"]').is_visible(), label + ': opens existing lyric slideout')
        check(not page.locator('.sf-agent-panel').is_visible(), label + ': no duplicate recording panel')
        check(page.evaluate('recordings===0&&mics===0&&captures===0&&posts.length===0'), label + ': selection starts without recording, microphone or paid request')
        check(page.evaluate('gets.length===1&&gets[0].includes("source_ref=")'), label + ': readiness binds current player source')
        details = capture_evidence(page, width, 'selection')
        expected_phase = 'Selecting song section · Stop retrieves saved lyrics'
        check(visible_text(page.locator('.sf-agent-lyric-workspace .song-experience-phase')) == expected_phase
              and details['phaseLabel']['textContent'] == expected_phase,
              label + ': sidebar explains saved-lyrics lookup')
        page.locator('.sf-agent-page-rail [data-agent-action="close-page"]').click()
        check(page.evaluate('songListeningSkill.status().active') and not page.locator('.sf-agent-page-rail').is_visible(), label + ': closing sidebar preserves selection')
        check(visible_text(page.locator('.sf-agent-state')) == 'SELECTING SONG SECTION', label + ': selection state stays truthful')
        check(page.locator('[data-agent-action="stop-lyrics"]').is_visible(), label + ': orb Stop remains visible')
        check(page.locator('[data-orbit-action="listen"]').evaluate('(e)=>[e.style.left,e.style.top]') == before, label + ': orbital icon position stays stable')
        page.evaluate('advance(8000)')
        page.locator('[data-agent-action="stop-lyrics"]').click()
        page.wait_for_function('posts.length===1')
        check(page.evaluate('posts[0].start_ms===60000&&posts[0].end_ms===68000&&posts[0].source_fingerprint==="a".repeat(64)'), label + ': Stop submits the selected source/time window once')
        check(visible_text(page.locator('.sf-agent-state')) == 'LOADING SAVED LYRICS', label + ': processing state remains visible after sidebar closes')
        check(visible_text(page.locator('[data-agent-action="stop-lyrics"]')) == 'Stop waiting', label + ': processing has an explicit Stop waiting control')
        check(page.locator('[data-orbit-action="record"]').is_disabled(), label + ': Record Studio cannot compete with transcription')
        box = page.locator('.sf-agent-lyric-controls').bounding_box()
        check(box and box['x'] >= 0 and box['x']+box['width'] <= width and box['y']+box['height'] <= 850, label + ': transcription controls fit viewport')
        page.evaluate('SFPublicAgentCanvas.setState("music");SFPublicAgentCanvas.syncSongExperience()')
        check(visible_text(page.locator('.sf-agent-state')) == 'LOADING SAVED LYRICS' and not page.locator('.sf-agent-page-rail').is_visible(), label + ': player updates preserve transcription status and closed rail')
        page.screenshot(path=str(OUT / f'catalog-transcribing-{BROWSER}-{width}.png'), full_page=True)
        page.evaluate('reply(0)')
        page.wait_for_function('songExperience?.phase === "draft"')
        check(not page.locator('.sf-agent-page-rail').is_visible(), label + ': completion does not force the slideout open')
        check(not page.locator('[data-agent-action="stop-lyrics"]').is_visible(), label + ': Stop clears on completion')
        check(page.evaluate('currentSongSource()===songLyricDraft&&currentSongDraft()===songLyricDraft'), label + ': catalog receipt integrates with existing draft gates')
        page.locator('[data-agent-action="open-lyrics"]').click()
        wait_open_rail(page, width)
        check('Words from the selected song' in visible_text(page.locator('.sf-agent-lyric-workspace')), label + ': reopening displays completed lyric draft')
        check(page.evaluate('interpretations===0&&posts.length===1'), label + ': completion never auto-interprets or repeats the lookup')
        page.locator('.sf-agent-lyric-workspace [aria-label="Edit lyric draft"]').click()
        page.locator('.sf-agent-lyric-workspace textarea').fill('Corrected lyric words')
        page.locator('.sf-agent-lyric-workspace button', has_text='Save lyric correction').click()
        check('Corrected lyric words' in visible_text(page.locator('.sf-agent-lyric-workspace')), label + ': listener may correct the draft')
        page.locator('.sf-agent-lyric-workspace button', has_text='Review & interpret lyrics').click()
        check(page.evaluate('interpretations===1&&posts.length===1'), label + ': interpretation requires its own explicit action')
        page.keyboard.press('Escape')
        page.locator('[data-orbit-action="listen"]').click(force=True)
        page.wait_for_function('songExperience?.phase === "capturing"')
        wait_open_rail(page, width)
        page.locator('.sf-agent-page-rail [data-agent-action="close-page"]').click()
        page.evaluate('advance(3000)')
        page.locator('[data-agent-action="stop-lyrics"]').click()
        page.wait_for_function('posts.length===2')
        page.locator('[data-agent-action="stop-lyrics"]').click()
        page.wait_for_function('!songListeningSkill.status().active')
        check(page.evaluate('songExperience.phase==="discarded"&&songExperience.message.includes("No audio was sent and no transcription was charged")'), label + ': Stop waiting discards the read-only lookup')
        page.locator('[data-orbit-action="listen"]').click(force=True)
        page.wait_for_function('songExperience?.phase === "capturing"')
        wait_open_rail(page, width)
        request_id = page.evaluate('songExperience.request_id')
        page.evaluate('reply(1, "Obsolete result")')
        page.wait_for_timeout(20)
        check(page.evaluate('songExperience.request_id') == request_id and page.evaluate('songExperience.phase') == 'capturing', label + ': late canceled response cannot replace a new session')
        page.evaluate('audio.dispatchEvent(new Event("seeking"))')
        page.wait_for_function('!songListeningSkill.status().active')
        check(page.evaluate('posts.length===2'), label + ': seek discards selection without submitting')
        page.keyboard.press('Escape')
        page.evaluate('deferReady=true')
        page.locator('[data-orbit-action="listen"]').click(force=True)
        page.wait_for_function('songExperience?.phase === "starting"')
        wait_open_rail(page, width)
        page.locator('.sf-agent-lyric-workspace button', has_text='Stop').click()
        page.wait_for_function('!songListeningSkill.status().active')
        page.evaluate('pendingReady.forEach(resolve=>resolve())')
        page.wait_for_timeout(20)
        check(page.evaluate('posts.length===2&&songExperience.phase!=="capturing"'), label + ': Stop during readiness prevents a later start')
        check(page.evaluate('recordings===0&&mics===0&&captures===0'), label + ': all mobile paths leave Record Clip and microphone untouched')
        page.evaluate('SFPublicAgentCanvas.closePage(true);SFPublicAgentCanvas.unmount();catalogSongListeningSkill.destroy()')
        check(page.locator('.sf-agent-modal-layer').count() == 0, label + ': unmount removes sidebar portal')
        check(not errors, label + ': no browser JavaScript exceptions: ' + str(errors))
        context.close()
    browser.close()
print(json.dumps({'checks':len(checks),'passed':len(checks),'browser':BROWSER,
                  'scope':'Production controller/actions/UI with synthetic playback and saved-lyric responses; no physical-device or paid-provider acceptance'}))
