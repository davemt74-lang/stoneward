from pathlib import Path
root=Path('.')
def patch(path,old,new,count=1):
 p=root/path;s=p.read_text();assert s.count(old)==count,(path,old[:90],s.count(old));p.write_text(s.replace(old,new))
patch('assets/js/app.js',"    active:()=>!!songExperience\n", "    active:()=>!!songExperience,\n    snapshot:()=>songExperience?{listening_mode:songExperience.listening_mode,phase:songExperience.phase,\n      track_id:songExperience.track_id,start_ms:songExperience.start_ms}:null\n")
patch('assets/js/app.js',"if(!result.ok||!result.receipt||st.track?.id!==result.receipt.track_id)","if(!result.ok||!result.receipt||String(st.track?.id)!==String(result.receipt.track_id))")
patch('assets/js/app.js',"    if(action.type==='song_capture_live_start'){\n      if(songCaptureSkill?.status().active)","    if(action.type==='song_capture_live_start'){\n      if(songTranscriptionBusy||songAnalysisBusy)return 'Finish the current lyric request before starting another.';\n      if(songCaptureSkill?.status().active)")
patch('assets/js/app.js',"      const started=songListeningSkill.begin();\n      if(!started.ok)return started.text;\n            songListeningReceipt=null;songLyricDraft=null;songLyricReport=null;songLyricCorrected='';songPersonalNote='';", """      const started=songListeningSkill.begin();
      if(!started.ok){
        // Unsupported devices must not silently switch to Record Song or the microphone.
        songExperience={phase:'unavailable',listening_mode:'recognition',title:st.track?.title||'Listen & Interpret',
          track_id:String(st.track?.id||''),start_ms:Math.round((audio.currentTime||0)*1000),end_ms:null,
          transcript:'',corrected:'',reports:[],live_enabled:false,message:started.text};
        updateSongExperience({});window.SFPublicAgentCanvas?.openLyrics?.();return started.text;
      }
      songListeningReceipt=null;songLyricDraft=null;songLyricReport=null;songLyricCorrected='';songPersonalNote='';""")
patch('assets/js/app.js',"      updateSongExperience({},true);\n      return started.text;", "      updateSongExperience({},true);\n      window.SFPublicAgentCanvas?.openLyrics?.();\n      return started.text;")
patch('assets/js/app.js',"      if(songListeningSkill.status().active)return 'Already listening to the song. Select Stop listening to interpret.';", "      if(songListeningSkill.status().active){window.SFPublicAgentCanvas?.openLyrics?.();return 'Already listening to the song. Select Stop listening to review the lyrics.';}")
patch('assets/js/app.js',"    if(action.type==='song_capture_stop'&&songListeningSkill?.status().active)\n      return (await songListeningSkill.stop()).text;", """    if(action.type==='song_capture_stop'&&songListeningSkill?.status().active){
      updateSongExperience({phase:'stopping',message:'Stopping lyric listening…'});
      return (await songListeningSkill.stop()).text;
    }""")
patch('assets/js/app.js',"    if(action.type==='song_capture_start'){\n      if(songListeningSkill?.status().active)","    if(action.type==='song_capture_start'){\n      if(songTranscriptionBusy||songAnalysisBusy)return 'Finish the current lyric request before starting Record Clip.';\n      if(songListeningSkill?.status().active)")
patch('assets/js/app.js',"    const snapshot=songCaptureSkill.status();", "    if(songListeningSkill?.status().active)return 'Transcribing lyrics from the player. Select Stop listening to review; no song clip is recorded.';\n    const snapshot=songCaptureSkill.status();")
patch('assets/js/app.js',r'\s+and\s+(?:then\s+)?(?:interpret|analy[sz]e)',r'\s+(?:and|&)\s+(?:then\s+)?(?:interpret|analy[sz]e)')
patch('assets/js/agent-song-experience-ui.js',"      capturing: 'Listening now', captured: 'Recorded · ready to transcribe',", "      starting: 'Starting lyric listening…', stopping: 'Stopping lyric listening…', unavailable: 'Player-track recognition unavailable',\n      capturing: 'Listening now', captured: 'Recorded · ready to transcribe',")
patch('assets/js/public-agent-canvas.js', '        <div class="sf-agent-state" role="status">AGENT READY</div>', '''        <div class="sf-agent-state" role="status">AGENT READY</div>
        <div class="sf-agent-lyric-controls" hidden aria-label="Lyric listening controls">
          <button type="button" data-agent-action="open-lyrics">Show lyrics</button>
          <button type="button" data-agent-action="stop-lyrics">Stop</button>
        </div>''')
patch('assets/js/public-agent-canvas.js',"    const status = by(root, '.sf-agent-state');", "    const status = by(root, '.sf-agent-state');\n    const lyricControls = by(root, '.sf-agent-lyric-controls');\n    const lyricStop = by(lyricControls, '[data-agent-action=\"stop-lyrics\"]');")
patch('assets/js/public-agent-canvas.js',"    const pageHost = by(root, '.sf-agent-page-content');", "    const pageHost = by(root, '.sf-agent-page-content');\n    const lyricHost=document.createElement('div');lyricHost.className='sf-agent-lyric-workspace';lyricHost.hidden=true;\n    pageViewport.appendChild(lyricHost);\n    let pageView='',baseMode='idle',baseLabel='';")
patch('assets/js/public-agent-canvas.js',"      mode=map[next] || 'idle';", """      baseMode=next;baseLabel=label||'';
      const lyric=window.StonefellowSongExperience?.snapshot?.();
      const hearing=lyric?.listening_mode==='recognition'&&['capturing','stopping'].includes(lyric.phase);
      mode=hearing?'listening':map[next] || 'idle';""")
patch('assets/js/public-agent-canvas.js',"      status.textContent=label || ({idle:","      status.textContent=hearing?(lyric.phase==='stopping'?'STOPPING LYRIC TRANSCRIPTION':'TRANSCRIBING LYRICS'):label || ({idle:")
patch('assets/js/public-agent-canvas.js',"      const visible=Array.from(orbitTools.querySelectorAll('button')).filter(button=>!button.hidden);", "      const visible=Array.from(orbitTools.querySelectorAll('button')).filter(button=>button.dataset.orbitAction!=='stop');")
patch('assets/js/public-agent-canvas.js',"      const capturing=!!options.isCapturing?.();", "      const capturing=!!options.isCapturing?.();\n      const lyric=window.StonefellowSongExperience?.snapshot?.();\n      const lyricBusy=lyric?.listening_mode==='recognition'&&['capturing','stopping'].includes(lyric.phase);")
patch('assets/js/public-agent-canvas.js',"        button.hidden=capturing;\n        button.disabled=!playable;", "        button.hidden=capturing&&!lyricBusy;\n        button.disabled=!lyricBusy&&(!playable||capturing);\n        button.setAttribute('aria-pressed',String(lyricBusy));")
patch('assets/js/public-agent-canvas.js',"        button.hidden=false;button.disabled=false;button.title='Record Studio · Vocal overdub or song clip';", "        button.hidden=false;button.disabled=lyricBusy;button.title=lyricBusy?'Stop lyric listening before opening Record Studio.':'Record Studio · Vocal overdub or song clip';")
patch('assets/js/public-agent-canvas.js',"      for(const button of root.querySelectorAll('[data-agent-action=\"stop-listening\"]'))button.hidden=!capturing||!!options.isVocalRecording?.();", """      for(const button of root.querySelectorAll('[data-agent-action="stop-listening"]'))button.hidden=!capturing||!!options.isVocalRecording?.()||lyricBusy;
      lyricControls.hidden=!lyric||lyric.listening_mode!=='recognition';
      lyricStop.hidden=!lyricBusy;lyricStop.disabled=lyric?.phase==='stopping';
      lyricStop.textContent=lyric?.phase==='stopping'?'Stopping…':'Stop';""")
patch('assets/js/public-agent-canvas.js',"      positionOrbitTools();\n    }\n    function session()", """      positionOrbitTools();
      const stopSlot=by(orbitTools,'[data-orbit-action="stop"]'),listenSlot=by(orbitTools,'[data-orbit-action="listen"]');
      if(stopSlot&&listenSlot){stopSlot.style.left=listenSlot.style.left;stopSlot.style.top=listenSlot.style.top;}
    }
    function session()""")
old="""      if (!remotePanelDismissed) panel.hidden=false;
      window.StonefellowSongExperience.mount(smallSession);
      window.StonefellowSongExperience.mount(bigSession);"""
new="""      const lyric=window.StonefellowSongExperience?.snapshot?.();
      if(lyric?.listening_mode==='recognition'){
        panel.hidden=true;smallSession.replaceChildren();bigSession.replaceChildren();
        // Updates never reopen a dismissed rail or replace another native page.
        if(pageView==='lyrics'&&!pageRail.hidden&&!pageRail.hasAttribute('inert'))window.StonefellowSongExperience.mount(lyricHost);
        state(baseMode,baseLabel);
      }else{
        if (!remotePanelDismissed) panel.hidden=false;
        window.StonefellowSongExperience.mount(smallSession);
        window.StonefellowSongExperience.mount(bigSession);
      }"""
patch('assets/js/public-agent-canvas.js',old,new)
patch('assets/js/public-agent-canvas.js',"      const alreadyOpen=pageRail.classList.contains('open');", "      const alreadyOpen=pageRail.classList.contains('open');\n      pageView=view;pageRail.dataset.view=view;pageHost.hidden=view==='lyrics';lyricHost.hidden=view!=='lyrics';")
patch('assets/js/public-agent-canvas.js',"      pageTitle.textContent=pageLabels[view]||", "      pageTitle.textContent=(view==='lyrics'?'LISTEN & INTERPRET':pageLabels[view])||")
patch('assets/js/public-agent-canvas.js',"      return pageHost;\n    }\n    function closePage", "      return view==='lyrics'?lyricHost:pageHost;\n    }\n    function openLyrics(){\n      const host=pageView==='lyrics'&&!pageRail.hidden&&!pageRail.hasAttribute('inert')?lyricHost:openPage('lyrics');\n      window.StonefellowSongExperience?.mount?.(host);\n      return host;\n    }\n    function closePage")
patch('assets/js/public-agent-canvas.js',"      pageRail.setAttribute('inert','');\n      pageRail.classList.remove('open');", "      pageRail.setAttribute('inert','');\n      pageRail.classList.remove('open');\n      state(baseMode,baseLabel);")
patch('assets/js/public-agent-canvas.js',"        pageHost.replaceChildren();\n        root.classList.remove", "        pageHost.replaceChildren();lyricHost.replaceChildren();\n        pageView='';\n        root.classList.remove")
patch('assets/js/public-agent-canvas.js',"      if(action==='listen-interpret')options.onListenInterpret?.();", "      if(action==='listen-interpret')options.onListenInterpret?.();\n      if(action==='open-lyrics')openLyrics();\n      if(action==='stop-lyrics'&&!lyricStop.disabled)options.onStopListening?.();")
patch('assets/js/public-agent-canvas.js',"syncPlaylist:syncListeningButtons,openPage,closePage,startGuide", "syncPlaylist:syncListeningButtons,openPage,openLyrics,closePage,startGuide")
patch('assets/js/public-agent-canvas.js',"openPage:view=>current?.openPage(view),closePage:","openPage:view=>current?.openPage(view),openLyrics:()=>current?.openLyrics(),closePage:")
patch('api/agent-llm-orchestrator.php',"the live route sends short audio clips to a metered transcription provider, and the listen-and-interpret request authorizes an AI interpretation after Stop.","the live route uses supported browser recognition of the player audio without recording a clip. Stop shows an unverified editable lyric draft; interpretation requires a subsequent explicit review/interpret request. Unsupported browsers must not fall back to recording or microphone listening.")
p=root/'assets/css/public-agent-canvas.css'
p.write_text(p.read_text()+'''
/* 91B21B3 — lyric sidebar and persistent controls; no second recording UI. */
.sf-agent-lyric-controls[hidden]{display:none!important}
.sf-agent-landing .sf-agent-lyric-controls{display:flex;justify-content:center;gap:8px;margin:8px auto;max-width:100%;pointer-events:auto}
.sf-agent-landing .sf-agent-lyric-controls button{min-height:44px;padding:9px 14px;border:1px solid var(--sf-atmo-border,#8bb9df);border-radius:10px;color:inherit;background:#102034;white-space:nowrap}
.sf-agent-landing .sf-agent-lyric-controls button[hidden]{display:none!important}
.sf-agent-landing .sf-agent-lyric-controls button:disabled{opacity:.65}
.sf-agent-landing .sf-agent-lyric-controls button:focus-visible{outline:2px solid currentColor;outline-offset:3px}
.sf-agent-landing .sf-agent-lyric-workspace{min-width:0;padding:12px}
.sf-agent-landing .sf-agent-lyric-workspace[hidden]{display:none!important}
.sf-agent-landing .sf-agent-lyric-workspace .song-experience-card{margin:0;max-width:100%;overflow-wrap:anywhere}
.sf-agent-landing .sf-agent-lyric-workspace .song-experience-transcript,.sf-agent-landing .sf-agent-lyric-workspace .song-experience-report{max-height:none;overflow:visible}
.sf-agent-landing .sf-agent-lyric-workspace textarea,.sf-agent-landing .sf-agent-lyric-workspace input{font-size:16px;max-width:100%;box-sizing:border-box}
.sf-agent-landing .sf-agent-lyric-workspace .song-experience-actions{flex-wrap:wrap}
.sf-agent-landing .sf-agent-lyric-workspace button{min-height:44px}

.sf-agent-landing .sf-agent-page-rail.open[data-view="lyrics"]{width:min(460px,100%);left:0;top:0;height:100%;border-radius:0}
''')
patch('assets/js/app.js',"      if(songExperience?.listening_mode!=='recognition'||songExperience.track_id!==part.track_id)return;", "      if(songExperience?.listening_mode!=='recognition'||songExperience.phase==='stopping'||songExperience.track_id!==part.track_id)return;")
patch('assets/js/agent-song-listening-skill.js',"Select Stop listening for Claude interpretation.","Select Stop listening to review the lyric draft before interpretation.")
patch('api/agent-runtime.php',"function sf_agent_policy(string $route,string $message,array $client=[],?int $userId=null): array {", """function sf_agent_policy(string $route,string $message,array $client=[],?int $userId=null): array {
    if($route==='song_capture_start'&&sf_agent_explicit_song_capture_route($message,$client)==='song_capture_live_start')
        $route='song_capture_live_start';""")
for file in ['version.php','stonefellow-v120.php','admin/index.php','service-worker.js','.htaccess','assets/js/app.js','assets/css/turntable-builder.css']:
 p=root/file;s=p.read_text();assert '1.3.238' in s,file;p.write_text(s.replace('1.3.238','1.3.239'))
for file in ['tests/section91b14c-ios-lunar-mobile.cjs','tests/section91b20h-visual-style-library.test.cjs']:
 p=root/file
 if p.exists():p.write_text(p.read_text().replace('1.3.238','1.3.239').replace(r'1\.3\.238',r'1\.3\.239'))
