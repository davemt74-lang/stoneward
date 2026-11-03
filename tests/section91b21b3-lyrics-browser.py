"""Real UI + production listening module; synthetic recognition, no microphone/provider calls."""
import json, os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('SF_LYRICS_EVIDENCE','/mnt/data/stoneward-lyrics-new-evidence'));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(value,label):
 assert value,label
 checks.append(label);print('PASS',label,flush=True)
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BIN') or shutil.which('chromium') or None,headless=True,args=['--no-sandbox'])
 for width in [375,1440]:
  page=browser.new_page(viewport={'width':width,'height':850},reduced_motion='reduce')
  errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.set_content('<html lang="en"><head></head><body class="sf-agent-canvas-active"><main id="target"></main><div id="player"><audio id="audio"></audio></div></body></html>')
  for name in ['assets/css/site.css','assets/css/agent-song-capture.css','assets/css/public-agent-canvas.css']:
   page.add_style_tag(content=(ROOT/name).read_text())
  page.add_style_tag(content='body{margin:0}#target{min-height:780px}.sf-agent-landing{height:780px!important;min-height:780px!important}')
  page.evaluate('''() => {
    window.recordings=0;window.mics=0;window.requests=0;window.stops=0;
    window.MediaRecorder=class{constructor(){recordings++;throw Error('Recorder called')}};
    window.fetch=()=>{requests++;throw Error('Unexpected provider call')};
    window.sample={id:'track-1',title:'Testing song',audio:'fixture.mp3'};
    window.audio=document.querySelector('audio');
    Object.defineProperty(audio,'paused',{configurable:true,get:()=>false});
    Object.defineProperty(audio,'currentSrc',{configurable:true,get:()=> 'fixture.mp3'});
    audio.currentTime=60;
    audio.captureStream=()=>({getAudioTracks:()=>[{kind:'audio',readyState:'live'}],getTracks:()=>[{stop(){}}]});
    window.Recognition=class {
      constructor(){window.rec=this}
      start(track){if(!track)throw Error('Microphone fallback');this.track=track;}
      stop(){stops++;queueMicrotask(()=>this.onend?.())}
      abort(){}
    };
    window.exp=null;window.active=false;
  }''')
  for name in ['assets/js/agent-song-experience-ui.js','assets/js/agent-song-listening-skill.js','assets/js/public-agent-canvas.js']:
   page.add_script_tag(content=(ROOT/name).read_text())
  page.evaluate('''() => {
    const draw=()=>{SFPublicAgentCanvas.syncSongExperience();SFPublicAgentCanvas.setState('playing')};
    window.skill=StonefellowSongListening.create({audio,getTrack:()=>sample,Recognition,supports:()=>true,
      onUpdate:r=>{if(exp&&exp.phase!=='stopping'){exp.transcript=[r.text,r.interim].filter(Boolean).join(' ');draw()}},
      onFinish:r=>{exp.phase=r.ok?'draft':'discarded';exp.transcript=r.receipt?.text||'';exp.live_enabled=false;exp.end_ms=r.receipt?.end_ms;draw()}
    });
    window.stop=async()=>{if(!skill.status().active)return;exp.phase='stopping';draw();await skill.stop()};
    window.StonefellowSongExperience={active:()=>!!exp,snapshot:()=>exp,
      mount:target=>SFSongExperienceUI.mount(target,exp&&{...exp,canAnalyze:exp.phase==='draft'},(action,text)=>{
        if(action==='stop')void stop();
        if(action==='edit'){exp.corrected=text;draw()}
      })};
    window.start=()=>{
      if(skill.status().active){SFPublicAgentCanvas.openLyrics();return}
      const r=skill.begin();
      exp={phase:r.ok?'capturing':'unavailable',listening_mode:'recognition',track_id:'track-1',title:'Testing song',start_ms:60000,end_ms:null,live_enabled:r.ok,auto_interpret:true,message:r.text,reports:[]};
      draw();SFPublicAgentCanvas.openLyrics();
    };
    SFPublicAgentCanvas.mount(document.querySelector('#target'),{audio,player:document.querySelector('#player'),
      isCapturing:()=>skill.status().active,isVocalRecording:()=>false,
      onListenInterpret:start,onStopListening:()=>void stop()});
  }''')
  before=page.locator('[data-orbit-action="listen"]').evaluate('(e)=>[e.style.left,e.style.top]')
  page.locator('[data-orbit-action="listen"]').click(force=True)
  page.wait_for_timeout(80)
  check(page.locator('.sf-agent-page-rail[data-view="lyrics"]').is_visible(),f'{width}: opens existing lyric sidebar')
  check(not page.locator('.sf-agent-panel').is_visible(),f'{width}: no duplicate floating capture panel')
  check(page.evaluate('recordings===0&&requests===0'),f'{width}: no recording or paid processing on start')
  page.evaluate('''() => { const r=[{transcript:'A provisional line from the song'}];r.isFinal=true;rec.onresult({resultIndex:0,results:[r]});audio.currentTime=68; }''')
  check('A provisional line' in page.locator('.sf-agent-lyric-workspace').inner_text(),f'{width}: live recognized text rendered')
  page.screenshot(path=str(OUT/f'lyrics-sidebar-{width}.png'),full_page=True)
  page.locator('.sf-agent-page-rail [data-agent-action="close-page"]').click()
  page.wait_for_timeout(50)
  check(not page.locator('.sf-agent-page-rail').is_visible() and page.evaluate('skill.status().active'),f'{width}: close minimizes without stopping')
  check(page.locator('.sf-agent-state').inner_text()=='TRANSCRIBING LYRICS',f'{width}: persistent truthful transcribing status')
  check(page.locator('[data-agent-action="stop-lyrics"]').is_visible(),f'{width}: visible Stop while minimized')
  check(page.locator('[data-orbit-action="listen"]').evaluate('(e)=>[e.style.left,e.style.top]')==before,f'{width}: stable listening icon slot')
  box=page.locator('.sf-agent-lyric-controls').bounding_box()
  check(box['x']>=0 and box['x']+box['width']<=width and box['y']+box['height']<=850,f'{width}: controls fit viewport')
  page.evaluate('SFPublicAgentCanvas.setState("music");SFPublicAgentCanvas.syncSongExperience()')
  check(page.locator('.sf-agent-state').inner_text()=='TRANSCRIBING LYRICS' and not page.locator('.sf-agent-page-rail').is_visible(),f'{width}: player update neither overwrites status nor reopens rail')
  check(page.locator('[data-orbit-action="record"]').is_disabled(),f'{width}: Record Studio cannot compete with recognition')
  page.screenshot(path=str(OUT/f'lyrics-minimized-{width}.png'),full_page=True)
  page.locator('[data-agent-action="open-lyrics"]').click()
  check('A provisional line' in page.locator('.sf-agent-lyric-workspace').inner_text(),f'{width}: reopen retains draft')
  page.keyboard.press('Escape')
  page.locator('[data-agent-action="stop-lyrics"]').click()
  page.wait_for_timeout(80)
  check(page.evaluate('!skill.status().active&&stops===1'),f'{width}: Stop ends actual recognition once')
  check(not page.locator('[data-agent-action="stop-lyrics"]').is_visible(),f'{width}: Stop clears on completion')
  check(not page.locator('.sf-agent-page-rail').is_visible(),f'{width}: completion does not force sidebar open')
  page.locator('[data-agent-action="open-lyrics"]').click()
  check('Unverified' in page.locator('.sf-agent-lyric-workspace').inner_text(),f'{width}: completed draft awaits review')
  page.locator('.sf-agent-lyric-workspace [aria-label="Edit lyric draft"]').click()
  page.locator('.sf-agent-lyric-workspace textarea').fill('Corrected words for review')
  page.locator('.sf-agent-lyric-workspace button',has_text='Save lyric correction').click()
  check('Corrected words for review' in page.locator('.sf-agent-lyric-workspace').inner_text(),f'{width}: editable draft preserved')
  check(page.evaluate('recordings===0&&requests===0'),f'{width}: stop and edit never auto-interpret or upload')
  page.evaluate('''() => { SFPublicAgentCanvas.openPage('music').textContent='Native music page';SFPublicAgentCanvas.syncSongExperience(); }''')
  check(page.locator('#sfAgentNativePage').inner_text()=='Native music page',f'{width}: updates leave another native page intact')
  page.evaluate('SFPublicAgentCanvas.closePage(true);SFPublicAgentCanvas.unmount()')
  check(page.locator('.sf-agent-modal-layer').count()==0,f'{width}: unmount removes sidebar portal')
  check(not errors,f'{width}: no browser JavaScript exceptions: {errors}')
  page.close()
 browser.close()
print(json.dumps({'checks':len(checks),'passed':len(checks),'scope':'Chromium with synthetic recognition; no physical mobile speech or provider calls'}))
