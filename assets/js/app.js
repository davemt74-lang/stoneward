(() => {
  window.STONEFELLOW_APP_BUILD='1.3.311';
  const cfg=window.STONEFELLOW_CONFIG||{},site=window.STONEFELLOW_SITE||{},artistHomepageEnabled=!!site.artistHomepageEnabled,homepageTemplate='agent_canvas',fanCommunityEnabled=!!site.fanCommunityEnabled; let releases=window.STONEFELLOW_RELEASES||[], shows=[], catalog=(window.STONEFELLOW_CATALOG||[]).map(x=>({...x,mood:x.mood||[],themes:x.themes||[],credits:x.credits||[]}));
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const orb=$('#agentOrb'),msg=$('#agentMessage'),status=$('#agentState'),agentInterrupt=$('#agentInterrupt'),chatFooter=$('.chat-footer'),mainCanvas=$('#dynamicCanvas'),starters=$('#starterActions'),input=$('#chatInput'),form=$('#chatForm'),voice=$('#voiceButton'),menu=$('#menuPanel'),splash=$('#splashScreen'),footerPlayer=$('#footerPlayer'),audio=$('#persistentAudio'),playerArtwork=$('#playerArtwork'),playerTitle=$('#playerTitle'),playerRelease=$('#playerRelease'),playerPlaybackStatus=$('#playerPlaybackStatus'),playerToggle=$('#playerToggle'),playerPrev=$('#playerPrev'),playerNext=$('#playerNext'),playerSeek=$('#playerSeek'),playerCurrent=$('#playerCurrent'),playerDuration=$('#playerDuration'),playerClose=$('#playerClose'),notificationButton=$('#notificationButton'),notificationBadge=$('#notificationBadge'),notificationDrawer=$('#notificationDrawer'),notificationBackdrop=$('#notificationBackdrop'),notificationClose=$('#notificationClose'),drawerContent=$('#drawerContent'),drawerUnreadCount=$('#drawerUnreadCount'),playerQueueButton=$('#playerQueueButton'),playerQueueCount=$('#playerQueueCount'),queueDrawer=$('#queueDrawer'),queueBackdrop=$('#queueBackdrop'),queueClose=$('#queueClose'),queueContent=$('#queueContent'),queueDrawerCount=$('#queueDrawerCount'),queueClear=$('#queueClear'),quickButton=$('#chatQuickButton'),quickMenu=$('#chatQuickMenu'),chatPopup=$('#agentChatPopup'),chatToggle=$('#agentChatToggle'),chatPopupClose=$('#agentChatClose'),chatPopupNew=$('#agentChatNew'),chatPopupMessages=$('#agentChatPopupMessages'),chatPopupState=$('#agentChatPopupState');
  const playbackLifecycle=window.StonefellowPlaybackLifecycle.create();
  let playbackWantsAudio=false,playbackBufferTimer=null,pendingNetworkRecovery=null,queueAdvancePending=false;
  let canvas=mainCanvas;
  let listeningRoom=null,listeningRoomRequest=null,listeningRoomOwner=null,canvasSelection=null;
  const readJSON=(key,fallback)=>{try{return JSON.parse(localStorage.getItem(key)||'null')??fallback}catch(_){return fallback}};
  const temporaryPlaylistKey='stonefellow.temporary-playlist.v1';
  function restoreTemporaryPlaylist(){
    const saved=readJSON(temporaryPlaylistKey,null),empty={playlistId:null,playlistName:'',trackIds:[],index:-1};
    if(!saved||!Array.isArray(saved.trackIds)||saved.trackIds.length<1||saved.trackIds.length>20||
      saved.trackIds.some(id=>typeof id!=='string'||!id||id.length>180)||
      new Set(saved.trackIds).size!==saved.trackIds.length)return empty;
    return {playlistId:'temporary:restored',playlistName:String(saved.playlistName||'Stonefellow Mix').slice(0,120),
      trackIds:saved.trackIds.slice(),index:Math.max(0,Math.min(saved.trackIds.length-1,Number(saved.index)||0)),temporary:true};
  }
  const legacyBuildV2=readJSON('stonefellow.build.v02',null), legacyBuildV3=readJSON('stonefellow.build.v03',null), newBuild=readJSON('stonefellow.build.v04',null);
  const defaultBuild=()=>({version:4,format:'vinyl',title:'My Stonefellow Record',theme:'desert',A:[],B:[],playerModules:{base:'base-studio',platter:'platter-classic',arm:'arm-studio',label:'label-classic',effect:'effect-none'},coverFront:'stonefellow',coverBack:'stonefellow',customNote:'',savedAt:null});
  const bootAuth=window.STONEFELLOW_AUTH_BOOTSTRAP||{authenticated:false,user:null};
  const st={auth:{loaded:true,authenticated:!!bootAuth.authenticated,user:bootAuth.user||null,csrf:null},plans:[],selectedPlanId:null,conversationId:localStorage.getItem('stonefellow.agent.conversation')||'',conversationOn:false,voiceAudio:null,track:null,playing:false,audio:audio,builder:(newBuild&&newBuild.version===4)?newBuild:(legacyBuildV3?{...defaultBuild(),...legacyBuildV3,version:4}:(legacyBuildV2?{...defaultBuild(),...legacyBuildV2,version:4}:defaultBuild())),builderDrafts:{loaded:false,items:[],activeId:Number(localStorage.getItem('stonefellow.build.draft.id')||0)},cart:readJSON('stonefellow.cart.v03',[]),campaignCode:localStorage.getItem('stonefellow.campaign.code')||'',store:null,drawer:{mode:'activity',tab:'notifications',data:null},listenSession:localStorage.getItem('stonefellow.listen.session')||'',lastProgressAt:0,playSource:'player',playlistPlayback:restoreTemporaryPlaylist(),personalization:{loaded:false,favorites:{tracks:[],releases:[]},continue_listening:[],history:[],recommendations:{personalized:false,summary:'',signals:{},items:[]}},playlists:{loaded:false,items:[]},historyPage:{rows:[],sessions:[],summary:null,nextBefore:null,loaded:false},agentSession:{id:'',title:'',mode:'',status:'',currentIndex:0,tracks:[]},queue:{loaded:false,items:[],open:false,history:readJSON('stonefellow.queue.history.v1',[])},home:{loaded:false,data:null},fanEngagementLoaded:false};
  const drawerTitle=$('#activityDrawerTitle'),drawerCartCount=$('#drawerCartCount'),drawerTools=$('#activityDrawerTools'),chatFooterRestore=$('#chatFooterRestore'),chatFooterHide=$('#chatFooterHide');
  let drawerRenderSequence=0;
  let drawerReturnFocus=null,queueReturnFocus=null;
  let recognition=null,recognitionActive=false,conversationRestartTimer=null,voiceTurnBusy=false,voiceTurnGeneration=0,pendingReply=Promise.resolve(),popupHistoryLoaded=false,popupHistoryEpoch=0,popupEntries=[];
  let voiceGreetingArmed=false,voiceGreetingDelivered=false;
  const guestProfileKey='stonefellow.guest.profile.v1';
  let guestProfile=readJSON(guestProfileKey,null),welcomeStarted=false,welcomeWaitingForName=false,welcomeGreeting='';
  const guestSavedPlaylistsKey='stonefellow.guest.saved-playlists.v1';
  let guestSavedPlaylists=readJSON(guestSavedPlaylistsKey,[]);
  if(!Array.isArray(guestSavedPlaylists))guestSavedPlaylists=[];
  guestSavedPlaylists=guestSavedPlaylists.filter(p=>p&&typeof p.id==='string'&&Array.isArray(p.track_ids)&&p.track_ids.length>0&&p.track_ids.length<=20).slice(-20);
  let welcomeLatestTrackId='',lastSavedMixSignature='';
  // iOS WebKit can request microphone authorization for each Web Speech
  // start(). Retain one continuous session and never silently reopen it.
  let iosVoiceNeedsGesture=false,iosProcessedFinalIndex=-1,iosVoiceMuteUntil=0;
  const voiceReplyToggle=$('#agentVoiceReplyToggle');
  let voiceRepliesEnabled=localStorage.getItem('stonefellow.voice.replies.v1')!=='off',voiceInteractionStarted=false,speechPilotActive=false;
  let speechPilotEpoch=0,speechPilotReplyInProgress=false;
  let voicePlaybackPending=false,speechFallbackResolve=null,voiceAudioCancel=null,voicePlaybackSequence=0,musicOriginalVolume=null,musicFollowupUntil=0,voiceEchoUntil=0,recognitionNetworkFailures=0,lastAudibleVolume=0.7;
  let voiceInputActive=false,voiceSpeechAudible=false,speechPilotAgentSpeaking=false,voiceSpeechReleaseTimer=null,speechPilotReleaseTimer=null;
  const voiceSpeechGuard=window.StonefellowBargeIn?.createSpeechGuard({cooldownMs:1100});
  const voiceTranscript=$('#voiceTranscript'),voiceTranscriptText=$('#voiceTranscriptText');
  function showTranscript(text,phase='listening'){
    if(!voiceTranscript||!voiceTranscriptText)return;
    let value=String(text||'').trim();
    if(value.startsWith('You: ')&&(canvasImageEditDraft||canvasBriefInChat(value.slice(5))))value='Canvas brief received.';
    voiceTranscript.hidden=!value;
    voiceTranscript.dataset.phase=phase;
    voiceTranscriptText.textContent=value;
    window.SFPublicAgentCanvas?.syncTranscript?.();
  }
  function syncAgentDockLayout(){
    if(!chatFooter)return;
    const height=Math.ceil(chatFooter.getBoundingClientRect().height);
    document.documentElement.style.setProperty('--sf-agent-dock-bottom',(height+18)+'px');
    if(chatPopup&&!chatPopup.hidden)document.documentElement.style.setProperty('--sf-agent-popup-rise',(Math.ceil(chatPopup.getBoundingClientRect().height)+12)+'px');
  }
  if(window.ResizeObserver&&chatFooter){const dockObserver=new ResizeObserver(syncAgentDockLayout);dockObserver.observe(chatFooter);if(chatPopup)dockObserver.observe(chatPopup)}
  window.addEventListener('resize',syncAgentDockLayout);
  // Armed microphone, silent recognition and TTS preparation must NOT change
  // the listener's music. Duck only during real voice input or audible speech.
  function syncConversationMusic(){
    const agentSpeaking=voiceSpeechAudible||speechPilotAgentSpeaking;
    const base=playerVolume();
    const effective=(agentSpeaking||voiceInputActive)&&!audio.paused&&!audio.ended
      ? Math.min(base,agentSpeaking?0.16:0.4) : base;
    if(!applyPlayerVolume(effective))return false;
    musicOriginalVolume=effective===base?null:base;
    return true;
  }
  function setVoiceInputActive(active){voiceInputActive=!!active;syncConversationMusic()}
  function setVoiceSpeechAudible(active){
    if(voiceSpeechReleaseTimer!==null){clearTimeout(voiceSpeechReleaseTimer);voiceSpeechReleaseTimer=null}
    if(active){voiceSpeechAudible=true;syncConversationMusic();return}
    // Speech engines split replies into short chunks. Hold the current duck
    // briefly between adjacent audible chunks to avoid audible volume pumping.
    // A completed/cancelled reply restores the volume immediately.
    if(voiceSpeechAudible&&voicePlaybackPending){
      voiceSpeechReleaseTimer=setTimeout(()=>{
        voiceSpeechReleaseTimer=null;voiceSpeechAudible=false;syncConversationMusic();
      },220);
      return;
    }
    voiceSpeechAudible=false;syncConversationMusic();
  }
  function setSpeechPilotSpeaking(active,immediate=false){
    if(speechPilotReleaseTimer!==null){clearTimeout(speechPilotReleaseTimer);speechPilotReleaseTimer=null}
    if(active){speechPilotAgentSpeaking=true;syncConversationMusic();return}
    if(speechPilotAgentSpeaking&&speechPilotActive&&!immediate){
      speechPilotReleaseTimer=setTimeout(()=>{
        speechPilotReleaseTimer=null;speechPilotAgentSpeaking=false;syncConversationMusic();
      },220);
      return;
    }
    speechPilotAgentSpeaking=false;syncConversationMusic();
  }
  // The user-facing level is independent of automatic conversation ducking.
  function elementPlayerVolume(){return audio.muted?0:audio.volume}
  function playerVolume(){return musicOriginalVolume??elementPlayerVolume()}
  function applyPlayerVolume(level,allowMute=false){
    if(elementPlayerVolume()===level)return true;
    try{
      if(audio.volume!==level)audio.volume=level;
      if(audio.volume===level&&level>0&&audio.muted)audio.muted=false;
    }catch(_){}
    if(allowMute&&level===0&&elementPlayerVolume()!==0&&typeof audio.muted==='boolean'){
      // Mobile browsers that ignore element volume can still mute the player.
      try{audio.muted=true}catch(_){}
    }
    return elementPlayerVolume()===level;
  }
  function setPlayerVolume(value){
    const requested=Number(value);
    if(!Number.isFinite(requested))return null;
    const level=Math.max(0,Math.min(1,requested));
    const previous=playerVolume();
    if(musicOriginalVolume!==null){
      musicOriginalVolume=level;
      if(!syncConversationMusic()){musicOriginalVolume=previous;return null}
    }else{
      if(!applyPlayerVolume(level,true))return null;
      syncConversationMusic();
    }
    if(level>0)lastAudibleVolume=level;
    else if(previous>0)lastAudibleVolume=previous;
    return Math.round(level*100);
  }
  // One browser observation feeds the LLM, voice bridge, status replies and
  // player skill. A stale saved title or an unconfirmed command is not state.
  function observedPlayer(){
    const track=(st.track?.id?trackById(st.track.id):null)||null;
    const duration=Number.isFinite(audio.duration)?Math.max(0,Math.floor(audio.duration)):0;
    const position=Number.isFinite(audio.currentTime)?Math.max(0,Math.floor(audio.currentTime)):0;
    return {track,playing:!!track&&!audio.paused&&!audio.ended,
      position:Math.min(86400,position),duration:Math.min(86400,duration)};
  }
  function playerState(){
    const observed=observedPlayer();
    const selectingLyrics=!!songListeningSkill?.status().active;
    return {player_is_playing:observed.playing?1:0,song_capture_active:(songCaptureSkill?.status().active||selectingLyrics)?1:0,song_capture_live_enabled:selectingLyrics?1:0,song_capture_available:songCaptureSkill?.getCapture()?1:0,song_lyric_draft_available:songLyricDraft?1:0,player_volume_percent:Math.round(playerVolume()*100),player_position_seconds:observed.position,player_duration_seconds:observed.duration};
  }
  function describePlayer(){
    const observed=observedPlayer(),track=observed.track;
    if(!track)return 'No published song is loaded in the Stonefellow player.';
    const duration=observed.duration||Number(track.duration||0);
    const progress=duration>0?' At '+time(observed.position)+' of '+time(duration)+'.':'';
    return '“'+track.title+'”'+(track.release?' from '+track.release:'')+' is '+(observed.playing?'playing':'paused')+'.'+progress+' Player volume is '+Math.round(playerVolume()*100)+' percent.';
  }
  async function executePlayerAgentAction(action){
    if(!action||!['play_track','pause_player','stop_player','resume_player','next_track','previous_track','player_status','set_player_volume','load_temporary_playlist'].includes(action.type))return null;
    switch(action.type){
      case 'player_status':return describePlayer();
      case 'set_player_volume':{
        const mode=String(action.mode||'');let target=playerVolume();
        if(mode==='mute')target=0;
        else if(mode==='unmute')target=target>0?target:lastAudibleVolume;
        else if(mode==='set')target=Math.max(0,Math.min(100,Number(action.percent)||0))/100;
        else if(mode==='adjust')target+=Math.max(-100,Math.min(100,Number(action.percent)||0))/100;
        else return describePlayer();
        const actual=setPlayerVolume(target);
        return actual===null?'This browser controls volume through your device. Use the device volume buttons.':'Stonefellow player volume is now '+actual+' percent.';
      }
      case 'pause_player':
        if(!st.track)return 'There is no song to pause.';
        if(audio.paused)return '“'+st.track.title+'” is already paused.';
        recordListen('pause');invalidatePlayback();audio.pause();st.playing=false;updateFooterPlayer();
        return 'Paused “'+st.track.title+'.”';
      case 'stop_player':
        if(!st.track)return 'There is no music to stop.';
        if(!audio.paused)recordListen('pause');
        invalidatePlayback();audio.pause();try{audio.currentTime=0}catch(_){}
        playbackLifecycle.remember(playbackLifecycle.token(),0);
        st.playing=false;musicFollowupUntil=0;setVoiceInputActive(false);updateFooterPlayer();syncConversationMusic();
        return 'Stopped “'+st.track.title+'.”';
      case 'load_temporary_playlist':return startTemporaryPlaylist(action);
      case 'resume_player':
        if(!st.track)return 'There is no song loaded. Tell me which Stonefellow song to play.';
        if(!audio.paused)return '“'+st.track.title+'” is already playing.';
        return await play(st.track,savedPlaybackPosition(),st.playSource,!!st.playlistPlayback.playlistId)
          ?'Resumed “'+st.track.title+'.”':'The browser could not confirm playback. Tap Play or check Media Health.';
      case 'play_track':{
        const track=trackById(action.track_id);
        if(!track)return 'I could not find that published song in the player.';
        if(!track.audio)return 'I found “'+track.title+'”, but it has no playable audio. I have not started a different song.';
        const started=await play(track,0,'agent');
        return started&&st.track?.id===track.id&&!audio.paused&&!!audio.src
          ?'Playing “'+track.title+'.”':'The browser could not confirm playback of “'+track.title+'.” Check its audio file or press Play.';
      }
      case 'next_track':
      case 'previous_track':
        if(!st.track)return 'There is no song loaded. Tell me which song to start.';
        return await stepTrack(action.type==='next_track'?1:-1)
          ?describePlayer():'The browser did not confirm a new song. '+describePlayer();
    }
    return null;
  }
  // Shared 91B5 capability contract: the server chooses an allowed action;
  // this signed-in page alone owns its playback and reports the actual result.
  const publicAudioSkill=window.StonefellowAgentAudioSkill?.create({
    invoke:executePlayerAgentAction,
    getState:()=>({...playerState(),track_id:observedPlayer().track?.id||'',title:observedPlayer().track?.title||''})
  });
  // Listen & Interpret reads saved lyrics and public context by playback range.
  // Record Clip remains the independent browser recording path.
  let songExperience=null;
  let songListeningReceipt=null;
  const songCaptureSkill=window.StonefellowSongCapture?.create({
    audio,getTrack:()=>st.track,
    onChange:capture=>{
      document.body.classList.toggle('song-capture-active',capture.active);
      const freshCapture=capture.active&&(!songExperience||
        songExperience.track_id!==capture.track_id||songExperience.start_ms!==capture.start_ms||
        songExperience.phase!=='capturing');
      if(freshCapture){
                songListeningReceipt=null;songLyricDraft=null;songLyricReport=null;songLyricCorrected='';songPersonalNote='';
      }
      if(capture.active){
        if(freshCapture){
          songExperience={phase:'capturing',listening_mode:'record_clip',title:st.track?.title||'Song section',
            track_id:capture.track_id,start_ms:capture.start_ms,end_ms:null,
            transcript:'',corrected:'',reports:[],saved_note_id:null,message:'',auto_interpret:false};
          agent('recording','Capturing this song section… say “stop listening” to finish.');
          updateSongExperience({},true);
        }
      }else if(capture.last){
        songExperience=songExperience||{reports:[],transcript:'',corrected:''};
        updateSongExperience({phase:'captured',title:capture.last.title,
          track_id:capture.last.track_id,start_ms:capture.last.start_ms,
          end_ms:capture.last.end_ms,
          message:'Record Clip finished. Its audio remains in this browser until you explicitly request transcription.'},true);
      }else if(songExperience?.phase==='capturing'&&songExperience.listening_mode==='record_clip'){
        updateSongExperience({phase:'discarded',message:'The recording was discarded after playback changed. Start a fresh Record Clip.'});
      }
    }
  });
  // Public overdubs are browser-local, opt-in recordings. They use the
  // selectable microphone plus the authoritative player in a separate mix.
  // Record Clip and Listen & Interpret retain their independent audio paths.
  const vocalStudio=window.StonefellowVocalStudio?.create({
    audio,getTrack:()=>st.track,
    drawer:$('#vocalStudioDrawer'),backdrop:$('#vocalStudioBackdrop'),
    onCanRecord:()=>!songCaptureSkill?.status().active&&!songListeningSkill?.status().active,
    onClose:()=>{if(songCaptureSkill?.status().active)void songCaptureSkill.stop();},
    onBeforeRecord:()=>{
      if(st.conversationOn)stopConversation();
      if(voicePlaybackPending)stopAgentSpeaking();
    }
  });
  function selectVocalStudioTab(tab='vocal'){
    const drawer=$('#vocalStudioDrawer');
    if(!drawer)return;
    if(vocalStudio?.status()?.active)return;
    if(tab==='clip')vocalStudio?.disconnectMic?.();
    $$('[data-vocal-tab]',drawer).forEach(button=>{
      const selected=button.dataset.vocalTab===tab;
      button.classList.toggle('active',selected);
      button.setAttribute('aria-selected',String(selected));
    });
    $$('[data-vocal-panel]',drawer).forEach(panel=>panel.hidden=panel.dataset.vocalPanel!==tab);
    $('#vocalClipStatus').textContent=songCaptureSkill?.status().active
      ?'Capturing the current song section…'
      :songCaptureSkill?.getCapture()?'A song section is captured in this browser.':'Play a song to start a clip.';
  }
  $$('[data-vocal-tab]',$('#vocalStudioDrawer')||document).forEach(button=>{
    button.addEventListener('click',()=>selectVocalStudioTab(button.dataset.vocalTab));
  });
  $('#vocalClipStart')?.addEventListener('click',async()=>{
    const reply=await executeSongCaptureAction({type:'song_capture_start'});
    $('#vocalClipStatus').textContent=String(reply||'');
  });
  $('#vocalClipStop')?.addEventListener('click',async()=>{
    const reply=await executeSongCaptureAction({type:'song_capture_stop'});
    $('#vocalClipStatus').textContent=String(reply||'');
  });
  function openVocalStudio(tab='vocal'){
    if(!vocalStudio)return popupEntry('notice','Vocal Studio is unavailable in this browser.');
    if(st.queue.open)closeQueue();
    if(notificationDrawer&&!notificationDrawer.hidden)closeNotificationDrawer();
    selectVocalStudioTab(tab);
    vocalStudio.open();
  }
  // Song Moments are catalog-timecoded public data. Guest drafts stay local;
  // signed-in fan comments require administrator approval before publication.
  const songMoments=window.StonefellowSongMoments?.create({
    audio,seekInput:playerSeek,apiBase:cfg.apiBase||'api',
    submit:payload=>secureJsonPost('song-moments.php',payload),
    onNeedTrack:()=>popupEntry('notice','Play a song first, then open Song Moments.'),
    onStory:slug=>void navigate('story',{slug}),
    onLyricReference:moment=>void reviewTimelineLyric(moment),
    orbEnabled:()=>site.orbSongMomentsEnabled===true&&window.SFPublicAgentCanvas?.active?.()===true,
    onOrbMoment:moment=>window.SFPublicAgentCanvas?.setSongMoment?.(moment),
    onNarrate:moment=>{
      if(!voiceRepliesEnabled||(!st.conversationOn&&!voiceInteractionStarted)||speechPilotActive||voicePlaybackPending||recognitionActive||voiceTurnBusy||songListeningSkill?.status().active||songCaptureSkill?.status().active||vocalStudio?.status().active)return;
      const brief=(moment.kind==='story'?'A Stonefellow story: ':'Here is a photograph from the song: ')+String(moment.title||moment.story?.title||moment.photo?.title||'Song moment').slice(0,160);
      void agentSpeak(brief);
    }
  });
  $('#playerMomentsButton')?.addEventListener('click',()=>songMoments?.open());
  async function reviewTimelineLyric(moment){
    if(songAnalysisBusy){popupEntry('notice','Finish the current lyric interpretation before opening another range.');return;}
    if(songListeningSkill?.status().active){popupEntry('notice','Stop the current lyric selection before opening another range.');return;}
    if(!st.track||String(moment.track_id)!==String(st.track.id))return;
    const start=Number(moment.at_ms),end=Number(moment.end_ms);
    if(!Number.isInteger(start)||!Number.isInteger(end)||end<=start||end-start>90000){
      popupEntry('notice','Choose a lyric range of 90 seconds or less for interpretation.');return;
    }
    const draft=Object.freeze({track_id:String(st.track.id),title:String(st.track.title||''),release:String(st.track.release||''),
      start_ms:start,end_ms:end,captured_at:new Date().toISOString(),text:String(moment.body||'').slice(0,4200),
      source:'saved-catalog-lyrics',provider:'stored',review_status:moment.lyric_state||'unverified',timing:moment.timing_source,
      segments:[{id:Number(moment.id),start_ms:start,end_ms:end,timing_source:moment.timing_source}]});
    songListeningReceipt=draft;songLyricDraft=draft;songLyricCorrected='';songPersonalNote='';songLyricReport=null;
    songExperience={phase:'draft',listening_mode:'catalog',title:draft.title,track_id:draft.track_id,start_ms:start,end_ms:end,
      transcript:draft.text,corrected:'',reports:[],saved_note_id:null,auto_interpret:true,
      lyrics_source:draft.source,review_status:draft.review_status,timing:draft.timing,lyric_segments:draft.segments||[],
      message:'Saved lyric range selected. Review the text, then choose Review & interpret lyrics. No transcription call was made.'};
    songMoments?.close();updateSongExperience({},true);window.SFPublicAgentCanvas?.openLyrics?.();
  }
  async function finishSongListening(result){
    if(songExperience?.listening_mode!=='catalog'||songExperience.request_id!==result.request_id)return result.text;
    if(!result.ok||!result.receipt||String(st.track?.id)!==String(result.receipt.track_id)){
      updateSongExperience({phase:'discarded',end_ms:Math.round((audio.currentTime||0)*1000),
        message:result.error||result.text},true);
      return result.error||result.text;
    }
    const draft=Object.freeze({...result.receipt});
    songListeningReceipt=draft;songLyricDraft=draft;
    songLyricCorrected='';songPersonalNote='';songLyricReport=null;
    updateSongExperience({phase:'draft',start_ms:draft.start_ms,end_ms:draft.end_ms,
      transcript:draft.text,corrected:'',reports:[],saved_note_id:null,
      lyrics_source:draft.source,review_status:draft.review_status,timing:draft.timing,lyric_segments:draft.segments||[],
      section_context:Array.isArray(draft.section_context)?draft.section_context:[],
      message:'Saved song lyrics are ready for this selection. Approximate blocks may extend beyond its edges. Review the text before interpretation.'},true);
    // Even a Listen & Interpret command first offers the listener a chance
    // to correct the draft. Interpretation is a separate explicit request.
    if(songExperience.auto_interpret){
      updateSongExperience({message:'Review these words or tap the pencil to correct them, then select Review & interpret lyrics.'},true);
      return 'Lyric draft ready. Review or correct the words, then select Review & interpret lyrics.';
    }
    return 'Saved lyrics are ready for “'+draft.title+'.” Select Interpret lyrics when ready.';
  }
  // Every browser selects playback offsets from the same saved lyric timeline.
  const songListeningSkill=window.StonefellowCatalogListening?.create({
    audio,getTrack:()=>st.track,
    prepare:async(trackId,sourceRef,signal)=>{
      const response=await fetch((cfg.apiBase||'api')+'/song-lyrics-section.php?track_id='+encodeURIComponent(trackId)+'&source_ref='+encodeURIComponent(sourceRef),
        {credentials:'same-origin',signal});
      const payload=await response.json().catch(()=>({ok:false,message:'Saved lyric availability could not be checked.'}));
      if(response.status===401)throw new Error('Sign in to select saved song lyrics.');
      if(!response.ok||payload.ok!==true)throw new Error(payload.message||'Saved lyrics are unavailable.');
      return payload;
    },
    submit:(payload,signal)=>secureJsonPost('song-lyrics-section.php',payload,false,signal),
    onUpdate:part=>{
      if(songExperience?.listening_mode!=='catalog'||String(songExperience.track_id)!==part.track_id)return;
      if(songExperience.request_id&&songExperience.request_id!==part.request_id)return;
      const changes={phase:part.phase,request_id:part.request_id,start_ms:part.start_ms,
        end_ms:part.phase==='transcribing'?part.end_ms:null};
      if(part.text)changes.message=part.text;
      updateSongExperience(changes);
    },
    onFinish:finishSongListening
  });
  window.addEventListener('pagehide',()=>{
    if(songListeningSkill?.status().active)void songListeningSkill.stop('switch');
  });
  function currentSongSource(){
    if(songLyricDraft?.source==='saved-catalog-lyrics'){
      return songListeningReceipt;
    }
    return songCaptureSkill?.getCapture();
  }
  let songTranscriptionBusy=false;
  let songLyricDraft=null;
  let songAnalysisBusy=false;
  let songLyricReport=null;
  let songLyricCorrected='';
  let songPersonalNote='';
  // The player, popup chat and full My Agent Chat canvas all render this one
  // in-memory session. Nothing is copied to localStorage or Agent Brain.
  function updateSongExperience(changes={},open=false){
    if(!songExperience)return;
    Object.assign(songExperience,changes);
    renderPopupEntries();
    window.SFCustomerAgentChat?.onSongExperience?.();
    window.SFPublicAgentCanvas?.syncSongExperience?.();
    window.SFPublicAgentCanvas?.setState?.(songExperience.phase==='capturing'?(songExperience.listening_mode==='catalog'?'listening':'recording'):
      ['transcribing','analyzing'].includes(songExperience.phase)?'thinking':st.playing?'playing':'idle');
    // Agent Canvas displays transcript and analysis in its attached orb panel.
    // Keep the chat popup under the visitor's explicit control in that template.
    if(open&&!window.SFPublicAgentCanvas?.active?.())void openChatPopup();
  }
  async function songExperienceAction(action,question=''){
    if(action==='open'){
      closeChatPopup();
      await navigate('agent-chat');
      return;
    }
    if(action==='story'&&typeof question==='string'&&/^[a-zA-Z0-9_-]{1,160}$/.test(question)){
      closeChatPopup();
      await navigate('story',{slug:question});
      return;
    }
    if(!songExperience)return;
    if(action==='ask'&&!String(question).trim())return;
    if(action==='edit'){
      // UI corrections use the same browser-private path as typed or spoken
      // "correct lyrics to …" commands. The corrected words never enter a
      // routine Agent chat request or get saved to Brain automatically.
      const result=await executePrivateAnnotationCommand('correct lyrics to '+String(question||''));
      if(!result.startsWith('Updated the listener-edited'))updateSongExperience({message:result});
      return;
    }
    const userText={stop:'Stop listening',transcribe:'Transcribe this song section',
      analyze:'Analyze this captured lyric section',save:'Save this lyric note'}[action]||String(question).trim();
    popupEntry('user',userText);
    let response;
    if(action==='stop')response=await executeSongCaptureAction({type:'song_capture_stop'});
    else if(action==='transcribe')response=await transcribeLastCapturedSong();
    else if(action==='analyze'||action==='ask')response=await analyzeLastSongDraft(
      action==='ask'?String(question).trim():'Interpret lyrical themes, symbols and emotions in this excerpt using documented songwriter thoughts and approved Stonefellow song notes. Distinguish source-backed facts from your own reading.');
    else if(action==='save')response=await executePrivateAnnotationCommand('save this lyric note');
    else return;
    // Only completed LLM interpretation text reaches the Agent's chat/voice
    // response. Capture, source and review status stay in the sidecar.
    if((action==='analyze'||action==='ask')&&songLyricReport?.report===response)
      await say(response);
    else updateSongExperience({message:response});
  }
  window.StonefellowSongExperience=Object.freeze({
    mount:target=>window.SFSongExperienceUI?.mount(target,songExperience&&{
      ...songExperience,canAnalyze:!!currentSongDraft()
    },songExperienceAction),
    active:()=>!!songExperience,
    snapshot:()=>songExperience?{listening_mode:songExperience.listening_mode,phase:songExperience.phase,lyrics_source:songExperience.lyrics_source,
      track_id:songExperience.track_id,start_ms:songExperience.start_ms}:null
  });
  async function transcribeLastCapturedSong(retried=false,interpretAfter=false){
    const captured=songCaptureSkill?.getCapture();
    if(!captured)return 'There is no completed song section. Ask me to listen to a verse, then say stop listening.';
    if(songTranscriptionBusy)return 'The captured section is already being transcribed.';
    songTranscriptionBusy=true;
    try{
      if(!st.auth.csrf)await loadAuth(true);
      const form=new FormData();
      form.set('track_id',captured.track_id);
      form.set('start_ms',String(captured.start_ms));
      form.set('end_ms',String(captured.end_ms));
      form.set('captured_at',captured.captured_at);
      const extension=captured.mime_type?.includes('mp4')?'m4a':'webm';
      form.set('clip',captured.blob,'song-section.'+extension);
      updateSongExperience({phase:'transcribing',message:'Transcription is in progress. You can keep playing music.'},true);
      const response=await fetch((cfg.apiBase||'api')+'/agent-song-transcribe.php',{
        method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':st.auth.csrf||''},body:form
      });
      const payload=await response.json().catch(()=>({ok:false,message:'The transcription service returned an unreadable response.'}));
      if(typeof window!=='undefined')window.SFTokenMeter?.notifyUsage?.();
      if(response.status===403&&payload.error==='csrf_failed'&&!retried){
        await loadAuth(true);songTranscriptionBusy=false;
        return await transcribeLastCapturedSong(true,interpretAfter);
      }
      if(!response.ok||payload.ok!==true)throw new Error(payload.message||'Transcription was not completed.');
      if(songCaptureSkill.getCapture()!==captured){updateSongExperience({phase:'discarded',message:'The captured excerpt changed during transcription; the older result was discarded.'});return 'The song changed while transcription was processing. The older draft was discarded.';}
      const draft=payload.transcript;
      if(!draft||draft.review_status!=='unverified'||!draft.text)throw new Error('No usable transcription draft was returned.');
      songLyricDraft=Object.freeze({...draft});
      songLyricCorrected='';songPersonalNote='';songLyricReport=null;
      updateSongExperience({phase:'draft',title:draft.title,start_ms:draft.start_ms,
        end_ms:draft.end_ms,transcript:draft.text,corrected:'',reports:[],
        saved_note_id:null,message:interpretAfter?'Review the lyric draft before interpretation.':'Review these estimated words, then select Interpret lyrics or ask a question. A clip-only recording does not automatically invoke AI.'},true);
      const range=Math.round(draft.start_ms/1000)+'–'+Math.round(draft.end_ms/1000)+'s';
      if(interpretAfter){
        updateSongExperience({auto_interpret:true,message:'Review these estimated words, use the pencil to correct them if needed, then select Review & interpret lyrics.'},true);
        return 'The lyric draft is ready for your review. Correct any words before selecting Review & interpret lyrics.';
      }
      return 'I captured a draft transcript for “'+draft.title+'” at '+range+'. The lyrics are shown in the listening panel as unverified. You can correct any words before asking me for an analysis.';
    }catch(e){updateSongExperience({phase:'captured',message:'Transcription failed: '+e.message+' The captured audio remains available for another attempt.'});return 'I could not transcribe the section. '+e.message+' The captured audio is still in this browser, so you can retry.'}
    finally{songTranscriptionBusy=false}
  }
  async function analyzeLastSongDraft(question){
    if(songListeningSkill?.status().active)return 'Stop lyric listening and review the finished draft before requesting interpretation.';
    const captured=currentSongSource(),draft=songLyricDraft;
    if(!captured||!draft||draft.track_id!==String(st.track?.id)||draft.track_id!==captured.track_id||draft.start_ms!==captured.start_ms||draft.end_ms!==captured.end_ms)
      return 'Select and review lyrics for the current song before asking me to analyze them.';
    if(songAnalysisBusy)return 'I am already analyzing that lyric section. One moment.';
    const sourceRef=String(st.track?.audio||'');
    const sameSelection=()=>String(st.track?.id)===draft.track_id&&String(st.track?.audio||'')===sourceRef&&
      currentSongSource()===captured&&songLyricDraft===draft;
    songAnalysisBusy=true;
    try{
      updateSongExperience({phase:'analyzing',message:'Your requested interpretation is processing. The selected lyrics stay visible.'},true);
      const payload={track_id:draft.track_id,start_ms:draft.start_ms,end_ms:draft.end_ms,
        captured_at:draft.captured_at,transcript:songLyricCorrected||draft.text,
        listener_edited:!!songLyricCorrected,question:String(question||'').slice(0,700),
        timezone:Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC'};
      if(draft.source==='saved-catalog-lyrics'){
        if(/^[a-f0-9]{64}$/.test(draft.source_fingerprint||''))payload.source_fingerprint=draft.source_fingerprint;
        if(draft.segments?.length&&draft.segments.every(segment=>Number.isInteger(segment.id)&&segment.id>0))
          payload.saved_lyric_ids=draft.segments.map(segment=>segment.id);
      }
      const response=await secureJsonPost('agent-song-analyze.php',payload);
      if(!sameSelection()||(songLyricCorrected||draft.text)!==payload.transcript){
        if(songLyricDraft===draft)updateSongExperience({phase:'discarded',section_context:[],message:'Playback or the lyric draft changed. An outdated analysis was discarded.'});
        return 'Playback or the lyric draft changed during analysis. I discarded the outdated report.';
      }
      const report=response.analysis;
      if(!report||!report.report||report.track_id!==draft.track_id||report.start_ms!==draft.start_ms||report.end_ms!==draft.end_ms)
        throw new Error('No grounded analysis report was returned for this selection.');
      songLyricReport=Object.freeze({...report});
      updateSongExperience({phase:'report',reports:[...(songExperience?.reports||[]),
        {question:payload.question,text:report.report,sources:report.writer_sources||[],
          listener_edited:!!report.listener_edited,draft_status:report.draft_status,shared_note_count:report.shared_note_count}],
        section_context:Array.isArray(report.section_context)?report.section_context:[],
        message:'The lyric interpretation is ready. Credits, source labels and scope notes remain in the expandable metadata panel.'},true);
      // Voice/chat receives the actual lyric interpretation only. Structured
      // report metadata remains attached to this session in the sidecar.
      return report.report;
    }catch(e){
      if(sameSelection())updateSongExperience({phase:songExperience?.reports?.length?'report':'draft',message:'Analysis failed: '+e.message+'. The transcript remains available for another request.'});
      else if(songLyricDraft===draft)updateSongExperience({phase:'discarded',section_context:[],message:'Playback changed before the interpretation finished.'});
      return 'I could not finish the lyric analysis. '+e.message+' Your lyric draft is still available for another request.';
    }
    finally{songAnalysisBusy=false;}
  }
  async function executeSongCaptureAction(action){
    if(!action||!['song_capture_start','song_capture_live_start','song_capture_stop','song_capture_status','song_capture_transcribe','song_capture_analyze'].includes(action.type))return null;
    if(vocalStudio?.status()?.active&&['song_capture_start','song_capture_live_start'].includes(action.type))
      return 'Finish your vocal recording before starting song lyric listening or a separate song clip.';
    if(action.type==='song_capture_live_start'){
      if(songTranscriptionBusy||songAnalysisBusy)return 'Finish the current lyric request before starting another.';
      if(songCaptureSkill?.status().active)return 'Record Clip is active. Stop that recording before opening Song Lyrics.';
      if(!songListeningSkill)return 'Song lyric listening is unavailable. No audio recording was started.';
      if(songListeningSkill.status().active){window.SFPublicAgentCanvas?.openLyrics?.();return 'A song section is already selected. Use Stop to review the lyrics.';}
      const previousExperience=songExperience;
      const attempt={phase:'starting',lyrics_source:'saved-catalog-lyrics',listening_mode:'catalog',title:st.track?.title||'Song Lyrics',
        track_id:String(st.track?.id||''),start_ms:Math.round((audio.currentTime||0)*1000),end_ms:null,
        transcript:'',corrected:'',reports:[],auto_interpret:!!action.interpret_on_stop,
        message:'Checking saved song lyrics…'};
      songExperience=attempt;updateSongExperience({});window.SFPublicAgentCanvas?.openLyrics?.();
      const started=await songListeningSkill.begin();
      if(songExperience!==attempt)return started.text;
      if(!started.ok){
        // A readiness failure must not erase a previously reviewed lyric draft.
        if(previousExperience?.transcript&&String(previousExperience.track_id)===String(st.track?.id))songExperience=previousExperience;
        updateSongExperience({phase:songExperience===previousExperience?songExperience.phase:started.canceled?'discarded':'unavailable',message:started.text});
        return started.text;
      }
      if(!songListeningSkill.status().active)return started.text;
      songListeningReceipt=null;songLyricDraft=null;songLyricReport=null;songLyricCorrected='';songPersonalNote='';
      Object.assign(songExperience,{phase:'capturing',title:st.track?.title||'Song section',
        track_id:songListeningSkill.status().track_id,start_ms:songListeningSkill.status().start_ms,
        end_ms:null,transcript:'',corrected:'',reports:[],saved_note_id:null,
        auto_interpret:!!action.interpret_on_stop,
        message:'Selecting this song section. Stop retrieves the saved lyrics for this range.'});
      updateSongExperience({},true);
      return started.text;
    }
    if(action.type==='song_capture_stop'&&songListeningSkill?.status().active)return (await songListeningSkill.stop()).text;
    if(!songCaptureSkill)return 'Song-section audio capture is unavailable. Playback was not changed.';
    if(action.type==='song_capture_start'){
      if(songTranscriptionBusy||songAnalysisBusy)return 'Finish the current lyric request before starting Record Clip.';
      if(songListeningSkill?.status().active)return 'Song Lyrics is active. Stop selecting lyrics before starting Record Clip.';
      return songCaptureSkill.begin().text;
    }
    if(action.type==='song_capture_stop')return (await songCaptureSkill.stop()).text;
    if(action.type==='song_capture_transcribe')return await transcribeLastCapturedSong();
    if(action.type==='song_capture_analyze')return await analyzeLastSongDraft(action.question);
    if(songListeningSkill?.status().active)return songExperience?.phase==='transcribing'
      ?'Loading the saved lyrics for this section. Stop waiting discards the result.'
      :'Selecting the current song section. Stop retrieves its saved lyrics.';
    const snapshot=songCaptureSkill.status();
    if(snapshot.active)return 'I am recording the current song from '+Math.round(snapshot.start_ms/1000)+' seconds. Stop Record Clip to finish.';
    return snapshot.last?'The last song section is recorded and ready for optional transcription.':'There is no song listening or recording session in progress.';
  }
  // Private annotation commands are intercepted before agent-chat.php so
  // corrected lyrics and personal notes never enter a routine LLM prompt.
  // Both typed and spoken turns use liveAgent(), so their behavior is identical.
  function currentSongDraft(){
    if(songListeningSkill?.status().active)return null;
    const capture=currentSongSource(),draft=songLyricDraft;
    return capture&&draft&&String(st.track?.id)===String(draft.track_id)&&
      capture.track_id===draft.track_id&&capture.start_ms===draft.start_ms&&
      capture.end_ms===draft.end_ms?draft:null;
  }
  async function executePrivateAnnotationCommand(message){
    const q=String(message||'').trim();let m;
    if((m=q.match(/^(?:correct|edit|replace|change|fix)\s+(?:the\s+)?(?:lyrics?|transcript|draft|words)\s+(?:to|as|with|:)\s+([\s\S]+)$/i))){
      if(!currentSongDraft())return 'Capture and transcribe the current song section before correcting its draft.';
      const edit=m[1].trim();if(edit.length<3||edit.length>4200)return 'Corrected lyrics must be between 3 and 4,200 characters.';
      songLyricCorrected=edit;songLyricReport=null;
      updateSongExperience({phase:'draft',corrected:edit,reports:[],saved_note_id:null,
        message:'Your listener-edited draft is still unverified. The earlier analysis was cleared; ask again to analyze this version.'});
      return 'Updated the listener-edited, still-unverified draft in this browser. The original machine draft is preserved. Say “save this lyric note” to keep it privately.';
    }
    if((m=q.match(/^(?:add|write|set)\s+(?:a\s+)?(?:personal\s+)?note\s+(?:about\s+)?(?:this\s+)?(?:section|verse|lyric|draft)?\s*(?:to|as|:)\s+([\s\S]+)$/i))){
      if(!currentSongDraft())return 'Capture and transcribe a song section before adding a personal note.';
      if(m[1].trim().length>1800)return 'Personal notes are limited to 1,800 characters.';
      songPersonalNote=m[1].trim();return 'Your personal note is attached to this draft in the browser. Say “save this lyric note” to keep it privately.';
    }
    if(/^(?:save|remember|keep)\s+(?:this|that|the|my|current)\s+(?:lyric|lyrics|verse|song section|transcript|draft|annotation|note|analysis|report)(?:\s+(?:note|draft|report))?[.!?]*$/i.test(q)){
      const draft=currentSongDraft();if(!draft)return 'There is no current, transcribed song section to save. No note was created.';
      const payload={action:'save',track_id:draft.track_id,start_ms:draft.start_ms,end_ms:draft.end_ms,
        captured_at:draft.captured_at,transcript:draft.text,corrected_transcript:songLyricCorrected,
        annotation:songPersonalNote,question:songLyricReport?.question||'',
        report:songLyricReport?.report||'',mode:songLyricReport?.mode||''};
      try{const result=await secureJsonPost('agent-song-annotations.php',payload);
        const note=result.annotation;
        updateSongExperience({saved_note_id:note.id,message:'Private note #'+note.id+' saved. It has not been shared with Agent Brain.'});
        return 'Saved private lyric note #'+note.id+' for “'+note.title+'” at '+
          time(note.start_ms/1000)+'–'+time(note.end_ms/1000)+'. The draft remains unverified and is not shared with future AI conversations automatically.';
      }catch(e){return 'I could not save the private lyric note. '+e.message;}
    }
    if((m=q.match(/^share\s+(full\s+)?(?:my\s+)?(?:saved\s+)?(?:lyric\s+)?(?:note|annotation)\s*#?(\d+)\s+(?:with\s+)?(?:my\s+)?agent\s+brain[.!?]*$/i))){
      const scope=m[1]?'full':'summary',id=Number(m[2]);
      if(!Number.isSafeInteger(id)||id<1)return 'Choose a valid saved annotation number.';
      const warning=scope==='full'
        ?'Share note #'+id+' FULLY with your signed-in Stonefellow Agent Brain? Relevant future chat questions may send your unverified lyric draft, personal note and report excerpts to the configured AI provider. The Agent may discuss or speak about them. Nothing is shared with public profile visitors. You can revoke this later.'
        :'Share note #'+id+' SUMMARY with your signed-in Stonefellow Agent Brain? Relevant future chat questions may send your personal note or interpretive report excerpt (not your raw lyrics) to the configured AI provider. Public profile visitors cannot access it. You can revoke this later.';
      if(!window.confirm(warning))return 'Sharing cancelled. Your note is still private.';
      try{const result=await secureJsonPost('agent-song-annotations.php',{action:'share',id,scope});
        return result.sharing?'Note #'+id+' is now shared with your signed-in Agent Brain at '+scope+' scope. It remains an unverified/interpretive source; unrelated conversations and public profile agents do not receive it.':'That note was not found in your private account.';
      }catch(e){return 'I could not change that note’s sharing scope. '+e.message;}
    }
    if((m=q.match(/^(?:stop\s+sharing|unshare|make\s+private|keep\s+private)\s+(?:my\s+)?(?:saved\s+)?(?:lyric\s+)?(?:note|annotation)\s*#?(\d+)[.!?]*$/i))){
      const id=Number(m[1]);if(!Number.isSafeInteger(id)||id<1)return 'Choose a valid saved annotation number.';
      try{const result=await secureJsonPost('agent-song-annotations.php',{action:'share',id,scope:'private'});
        if(!result.sharing)return 'That note was not found in your private account.';
        // Rotate this browser's conversation so old turns with paraphrases do
        // not automatically remain in the next request's recent history.
        st.conversationId='';localStorage.removeItem('stonefellow.agent.conversation');
        return 'Sharing revoked for note #'+id+'. A fresh conversation will be used for future chat. Earlier replies and provider processing cannot be retroactively erased; use Agent privacy controls to clear saved conversation history.';
      }catch(e){return 'I could not revoke sharing for that note. '+e.message;}
    }
    if((m=q.match(/^(?:show|review|list)\s+(?:my\s+)?(?:lyric\s+)?(?:note|annotation)\s*#?(\d+)\s+(?:sharing\s+)?history[.!?]*$/i))){
      try{const result=await api('agent-song-annotations.php?history_id='+encodeURIComponent(m[1]));
        const history=result.share_history||[];
        return {text:'I displayed your private sharing history in chat.',privateDisplay:history.length?
          'Agent Brain sharing history for note #'+m[1]+':\n'+history.map(e=>e.changed_at+' | '+e.previous_scope+' → '+e.new_scope).join('\n'):
          'No sharing changes found for that note in your account.'};
      }catch(e){return 'I could not load that sharing history. '+e.message;}
    }
    if(/^(?:which|what)\s+(?:of\s+my\s+)?(?:lyric\s+)?(?:notes|annotations)\s+(?:are\s+)?shared\s+(?:with\s+)?(?:my\s+)?agent\s+brain[.!?]*$/i.test(q)){
      try{const result=await api('agent-song-annotations.php');
        const notes=(result.annotations||[]).filter(n=>n.agent_share_scope&&n.agent_share_scope!=='private');
        return {text:'I displayed your shared-note status privately in chat.',privateDisplay:notes.length?
          notes.map(n=>'#'+n.id+' “'+n.title+'” | '+n.agent_share_scope+' | shared '+n.agent_shared_at).join('\n'):
          'No private lyric notes are currently shared with Agent Brain.'};
      }catch(e){return 'I could not inspect your shared notes. '+e.message;}
    }
    if(/^(?:show|list|review|open|read)(?:\s+me)?\s+(?:my\s+)?(?:saved\s+|private\s+)?(?:lyric|lyrics|song|transcript)?\s*(?:notes|annotations|drafts)(?:\s+(?:please|again))?[.!?]*$/i.test(q)){
      try{const result=await api('agent-song-annotations.php');const notes=result.annotations||[];
        if(!notes.length)return 'You have no saved private lyric annotations.';
        const detail='Your private song annotations (not sent to the AI provider):\n'+notes.slice(0,10).map(n=>
          '#'+n.id+' “'+n.title+'” '+time(n.start_ms/1000)+'–'+time(n.end_ms/1000)+' | '+
          (n.transcript_corrected?'listener-edited draft':'unverified machine draft')+' | '+n.captured_at+' | Agent Brain: '+(n.agent_share_scope||'private')).join('\n')+
          '\nSay “share lyric note #ID with Agent Brain” (summary), “share full lyric note #ID with Agent Brain” (draft included), “stop sharing lyric note #ID”, or “delete lyric note #ID”.';
        return {text:'I listed your private lyric annotations in chat. I will not read the private details aloud.',privateDisplay:detail};
      }catch(e){return 'I could not load your private lyric notes. '+e.message;}
    }
    if((m=q.match(/^(?:show|read|review|open)\s+(?:my\s+)?(?:saved\s+)?(?:lyric\s+)?(?:note|annotation)\s*#?(\d+)[.!?]*$/i))){
      try{const result=await api('agent-song-annotations.php');const n=(result.annotations||[]).find(n=>Number(n.id)===Number(m[1]));
        if(!n)return 'That annotation is not in your private notes.';
        const detail='PRIVATE LYRIC NOTE #'+n.id+' — “'+n.title+'” ('+time(n.start_ms/1000)+'–'+time(n.end_ms/1000)+')\nAgent Brain scope: '+(n.agent_share_scope||'private')+'\n'+
          'Original unverified machine draft:\n'+n.transcript_draft+'\n'+
          (n.transcript_corrected?'Listener-edited, still-unverified draft:\n'+n.transcript_corrected+'\n':'')+
          (n.user_note?'Personal note:\n'+n.user_note+'\n':'')+
          (n.analysis_report?'Interpretive report about the earlier draft (not verified facts):\n'+n.analysis_report:'');
        return {text:'Private lyric note #'+n.id+' is shown in chat. I will not read its contents aloud.',privateDisplay:detail};
      }catch(e){return 'I could not review that private annotation. '+e.message;}
    }
    if((m=q.match(/^(?:edit|correct|update)\s+(?:my\s+)?(?:saved\s+)?(?:lyric\s+)?(?:note|annotation)\s*#?(\d+)\s+(?:to|as|:)\s+([\s\S]+)$/i))){
      const corrected=m[2].trim();if(corrected.length<3||corrected.length>4200)return 'The corrected draft must be between 3 and 4,200 characters.';
      try{const result=await secureJsonPost('agent-song-annotations.php',{action:'update',id:Number(m[1]),corrected_transcript:corrected});
        if(result.updated){st.conversationId='';localStorage.removeItem('stonefellow.agent.conversation');}
        return result.updated?'Updated your private note #'+Number(m[1])+'. The original machine draft remains unchanged; the older AI report was cleared, and previous Agent Brain sharing was revoked.':'That note does not belong to your account or no change was made.';
      }catch(e){return 'I could not update that private annotation. '+e.message;}
    }
    if((m=q.match(/^(?:delete|remove|erase)\s+(?:my\s+)?(?:saved\s+)?(?:lyric\s+)?(?:note|annotation)\s*#?(\d+)[.!?]*$/i))){
      const id=Number(m[1]);if(!Number.isSafeInteger(id)||id<1)return 'Choose a valid private annotation number.';
      if(!window.confirm('Permanently delete your private lyric annotation #'+id+'?'))return 'Deletion cancelled. Your note is unchanged.';
      try{const result=await secureJsonPost('agent-song-annotations.php',{action:'delete',id});
        if(result.deleted){st.conversationId='';localStorage.removeItem('stonefellow.agent.conversation');}
        return result.deleted?'Deleted private annotation #'+id+'.':'That note was already removed or does not belong to your account.';
      }catch(e){return 'I could not delete that private annotation. '+e.message;}
    }
    return null;
  }
  // LLM-resolved titles must carry a server-issued published-catalog receipt,
  // verified against the browser's actual player catalog before execution.
  function verifiedNamedPlayerAction(message,serverAction,resolution){
    return window.StonefellowAgentAudioSkill?.resolvePublished(message,catalog,serverAction,resolution)||{action:serverAction};
  }
  // Both typed chat and Speech Engine actions use the same published-catalog
  // receipt verification and browser-owned player/navigation dispatch.
  async function dispatchAgentBrowserAction(message,action,resolution,allowSongCapture=true){
    if(action?.type==='canvas_discuss')return discussCanvasProject(String(message||''));
    // A generic playlist request must not be converted into a blank /music
    // search by a model or a delayed Speech Engine action.
    if(directRandomPlaylistCommand(message)&&action?.type!=='load_temporary_playlist')
      return startRandomTemporaryPlaylist();
    if(action?.type==='create_playlist'&&!/\b(?:save|saved|permanent|to my account|to my library)\b/i.test(String(message||''))){
      const ids=Array.isArray(action.track_ids)?action.track_ids:[];
      return ids.length?startTemporaryPlaylist({name:action.name||'Stonefellow Mix',track_ids:ids}):startRandomTemporaryPlaylist();
    }
    const verified=verifiedNamedPlayerAction(message,action,resolution);
    if(verified.text)return verified.text;
    const captured=allowSongCapture?await executeSongCaptureAction(verified.action):null;
    const shared=captured===null?await publicAudioSkill?.dispatch(verified.action):null;
    const playerResult=captured??shared?.text??await executePlayerAgentAction(verified.action);
    if(playerResult!==null)return playerResult;
    // Page, builder, queue and account actions also return a browser-observed
    // receipt. Otherwise chat would fall back to the model's intention while
    // Agent Brain silently discarded what actually happened.
    return await executeAgentAction(verified.action);
  }
  function cancelLocalVoiceOutput(){
    const synthesis=window.speechSynthesis;
    try{synthesis?.cancel()}catch(_){}
    // Some engines retain paused after cancel, silently blocking later speech.
    try{if(synthesis?.paused)synthesis.resume?.()}catch(_){}
  }
  function interruptVoicePlayback(){
    voicePlaybackSequence++;
    if(voiceAudioCancel){const cancel=voiceAudioCancel;voiceAudioCancel=null;cancel()}
    if(st.voiceAudio&&!st.voiceAudio.paused)st.voiceAudio.pause();
    if(speechFallbackResolve){const resolve=speechFallbackResolve;speechFallbackResolve=null;cancelLocalVoiceOutput();resolve(null)}
    voicePlaybackPending=false;
    setVoiceSpeechAudible(false);
    if(typeof voiceSpeechGuard!=='undefined')voiceSpeechGuard?.finish();
    if(agentInterrupt)agentInterrupt.hidden=true;
    syncConversationMusic();
    if(st.conversationOn&&!speechPilotActive){showTranscript('Agent interrupted. Listening for you…','listening');agent('listening','I’m listening.');scheduleConversationListen()}
  }
  // Section 91B17F: a single tap on the main particle orb interrupts whichever
  // voice engine is actually speaking. It must not restart a microphone session.
  function stopAgentSpeaking(){
    if(speechPilotActive){
      if(!speechPilotAgentSpeaking)return false;
      const interrupted=window.StonefellowSpeechEngine?.interrupt?.();
      if(interrupted===false)return false;
      speechPilotReplyInProgress=false;
      setSpeechPilotSpeaking(false,true);
      window.StonefellowSpeechEngine?.setInputMuted?.(false);
      agent('listening','I’m listening.');
      showTranscript('Stonefellow stopped speaking. I’m listening.','listening');
      return true;
    }
    if(!voicePlaybackPending&&!voiceSpeechAudible&&!speechFallbackResolve)return false;
    interruptVoicePlayback();
    return true;
  }
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const time=s=>{s=Math.max(0,Math.floor(Number(s)||0));return Math.floor(s/60)+':'+String(s%60).padStart(2,'0')};
  const norm=s=>String(s||'').toLowerCase().replace(/[^a-z0-9 ]+/g,' ').replace(/\s+/g,' ').trim();
  const words=s=>new Set(norm(s).split(' ').filter(w=>w.length>2));
  const money=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:st.store?.currency||'USD'}).format((Number(c)||0)/100);
  function agent(mode,text){orb.className='agent-orb'+(mode&&mode!=='idle'?' '+mode:'');status.textContent=(mode||'idle').toUpperCase();if(chatPopupState)chatPopupState.textContent=status.textContent;if(text)msg.textContent=text;if(agentInterrupt)agentInterrupt.hidden=mode!=='speaking';window.SFPublicAgentCanvas?.setState?.(mode)}
  function renderPopupEntries(){
    if(!chatPopupMessages)return;
    const pendingQuestion=chatPopupMessages.querySelector('.song-experience-followup input')?.value||'';
    chatPopupMessages.replaceChildren();
    if(!popupEntries.length&&!songExperience){
      const empty=document.createElement('p');empty.className='agent-chat-empty';
      empty.textContent='Your conversation will appear here. Use the bar below to type or speak.';
      chatPopupMessages.appendChild(empty);canvasProjects.current?.attach?.();return;
    }
    for(const entry of popupEntries.slice(-60)){
      const row=document.createElement('article'),who=document.createElement('strong'),body=document.createElement('p');
      row.className='agent-chat-popup-entry '+(entry.role==='user'?'from-user':entry.role==='notice'?'notice':'from-agent');
      who.textContent=entry.role==='user'?'You':entry.role==='notice'?'Session update':'Stonefellow';
      body.textContent=entry.text;row.append(who,body);chatPopupMessages.appendChild(row);
      if(entry.imageReview&&/^[a-f0-9]{32}$/.test(entry.imageReview)){
        const button=document.createElement('button');
        button.type='button';button.textContent='Choose an image';
        button.addEventListener('click',()=>void handleListeningRoomCommand({kind:'image_review',requestId:entry.imageReview}));
        row.append(button);
      }
    }
    if(songExperience){
      const host=document.createElement('div');host.className='song-experience-in-chat';
      window.StonefellowSongExperience?.mount(host);
      if(pendingQuestion&&host.querySelector('.song-experience-followup input'))
        host.querySelector('.song-experience-followup input').value=pendingQuestion;
      chatPopupMessages.appendChild(host);
    }
    canvasProjects.current?.attach?.();
    chatPopupMessages.scrollTop=chatPopupMessages.scrollHeight;
  }
  function popupEntry(role,text,{canvasBrief=false,imageReview=null}={}){
    if(!text)return;
    const value=String(text);
    popupEntries.push({role,text:value,...(canvasBrief?{canvasBrief:true}:{}),...(imageReview?{imageReview}:{})});popupEntries=popupEntries.slice(-60);renderPopupEntries();
    if(role!=='user'||(!canvasBrief&&!canvasBriefInChat(value)))window.SFPublicAgentCanvas?.showConversation?.(role,value);
  }
  async function openChatPopup(){if(!chatPopup)return;closeQuickMenu();chatPopup.hidden=false;document.body.classList.add('agent-chat-open');syncAgentDockLayout();chatToggle?.setAttribute('aria-expanded','true');chatToggle?.setAttribute('aria-label','Close Agent Chat');chatToggle.title='Close Agent Chat';orb?.setAttribute('aria-expanded','true');orb?.setAttribute('aria-label','Close Agent Chat');if(popupHistoryLoaded)return;const epoch=popupHistoryEpoch;popupHistoryLoaded=true;await loadAuth();if(epoch!==popupHistoryEpoch)return;if(!st.auth.authenticated){popupHistoryLoaded=false;return}try{const cid=st.conversationId,owner=canvasOwner();const response=await api('agent-brain.php'+(cid?'?conversation_id='+encodeURIComponent(cid):''));if(epoch!==popupHistoryEpoch||owner!==canvasOwner()||cid!==st.conversationId)return;const messages=response.brain?.messages||response.messages||[];const historical=messages.filter(m=>['user','assistant'].includes(m.role)).slice(-40).map(m=>({role:m.role==='user'?'user':'assistant',text:String(m.content||'')}));const live=popupEntries.slice();let overlap=0;for(let n=Math.min(historical.length,live.length);n>0;n--){if(historical.slice(-n).every((m,i)=>m.role===live[i].role&&m.text===live[i].text)){overlap=n;break}}popupEntries=historical.concat(live.slice(overlap)).slice(-60);renderPopupEntries()}catch(_){if(epoch===popupHistoryEpoch)popupHistoryLoaded=false;/* A history outage must not stop new chat turns. */}}
  function closeChatPopup(){if(!chatPopup)return;chatPopup.hidden=true;setCanvasDebugVisible(false);document.body.classList.remove('agent-chat-open');syncAgentDockLayout();chatToggle?.setAttribute('aria-expanded','false');chatToggle?.setAttribute('aria-label','Open Agent Chat');chatToggle.title='Open Agent Chat';orb?.setAttribute('aria-expanded','false');orb?.setAttribute('aria-label','Open Agent Chat')}
  function say(text,cb){const answer=String(text||'');agent('thinking','…');popupEntry('assistant',answer);if(st.conversationOn)showTranscript('Stonefellow: '+answer.slice(0,240),'responding');const work=new Promise(resolve=>setTimeout(resolve,120)).then(async()=>{agent('responding',answer);if(cb)cb();if(!speechPilotActive&&voiceRepliesEnabled&&(st.conversationOn||voiceInteractionStarted)&&(await agentSpeak(answer))===false){const note='The reply is available in text, but speech playback is unavailable on this device.';popupEntry('notice',note);agent('idle',note)}if(st.conversationOn&&!speechPilotActive&&!voiceRepliesEnabled&&!voiceTurnBusy){agent('listening','I’m ready for your next question.');showTranscript(listeningPrompt(),'listening');scheduleConversationListen()}else if(!st.conversationOn)agent(st.playing?'playing':'idle');});pendingReply=work.catch(()=>{agent('idle',answer)});return pendingReply}
  // Local-first visitor identity. The schema deliberately keeps a guest scope
  // and timestamps so a future explicit account migration can read it; never
  // upload this record or claim it is already synchronized with an account.
  function validGuestName(value){
    const name=String(value||'').trim().replace(/\s+/g,' ').replace(/[.!?,;:]+$/,'');
    if(name.length<2||name.length>48||name.split(' ').length>3)return '';
    if(!/^[\p{L}\p{M}][\p{L}\p{M}'’\- ]*$/u.test(name))return '';
    if(/^(?:play|next|stop|skip|music|playlist|latest|release|listen|start|create|make|help|hello|hey|no|yes|open|close|show|queue|add|tell|what|who|how|song|album|nothing|anything)\b/i.test(name))return '';
    return name;
  }
  function rememberedVisitorName(){
    const account=st.auth.authenticated?validGuestName(st.auth.user?.display_name):'';
    return account||validGuestName(guestProfile?.display_name);
  }
  function nameFromTurn(text){
    const value=String(text||'').trim();
    const explicit=value.match(/^(?:my name is|call me)\s+(.+)$/i)
      ||(welcomeWaitingForName?value.match(/^(?:i['’]?m|i am)\s+(.+)$/i):null);
    if(explicit)return validGuestName(explicit[1]);
    if(welcomeWaitingForName&&catalog.some(track=>norm(track.title)===norm(value)))return '';
    return welcomeWaitingForName?validGuestName(value):'';
  }
  function saveVisitorName(value){
    const name=validGuestName(value);if(!name)return '';
    const now=new Date().toISOString(),created=guestProfile?.created_at||now;
    guestProfile={schema_version:1,scope:'guest_local',display_name:name,created_at:created,updated_at:now};
    try{localStorage.setItem(guestProfileKey,JSON.stringify(guestProfile))}catch(_){/* memory-only fallback */}
    return name;
  }
  function beginWelcome(){
    if(welcomeStarted)return;
    welcomeStarted=true;
    const name=rememberedVisitorName();welcomeWaitingForName=!name;
    welcomeGreeting=name
      ?`Hi ${name}, I’m Stonefellow. Welcome back. Ready for some music? You can hear the latest release or let me make you a playlist.`
      :'Hi, I’m Stonefellow. What should I call you?';
    syncWelcomeActions();
    popupEntry('assistant',welcomeGreeting);
  }
  async function acceptVisitorName(text){
    const name=nameFromTurn(text);
    if(!name)return false;
    saveVisitorName(name);welcomeWaitingForName=false;
    voiceGreetingDelivered=true;
    syncWelcomeActions();
    const answer=`Nice to meet you, ${name}! Let’s get some music going. Would you like the latest release, or should I make you a playlist?`;
    await say(answer);
    return true;
  }
  async function skipVisitorName(){
    welcomeWaitingForName=false;voiceGreetingDelivered=true;
    syncWelcomeActions();
    await say('No problem. I’m Stonefellow, and you can jump straight into the music. How about the latest release or a playlist?');
  }
  function currentMixSignature(){return isTemporaryPlaylist()?JSON.stringify(st.playlistPlayback.trackIds):''}
  function currentMixSaved(){
    const signature=currentMixSignature();
    return !!signature&&(signature===lastSavedMixSignature||guestSavedPlaylists.some(p=>JSON.stringify(p.track_ids)===signature));
  }
  function welcomeButtonState(){
    const nameReady=!!rememberedVisitorName()||!welcomeWaitingForName;
    return {mode:nameReady?'choices':'name',latestAction:welcomeLatestTrackId?'play':'latest',
      playlistAction:isTemporaryPlaylist()?(currentMixSaved()?'saved':'save'):'create'};
  }
  function syncWelcomeActions(){if(welcomeStarted)window.SFPublicAgentCanvas?.setWelcomeState?.(welcomeButtonState())}
  // Guest identity and listening hints are imported only after authenticated,
  // itemized consent. Local data is left untouched until a verified receipt.
  const guestImportRoot='stonefellow.guest.migration.v1';
  function guestImportKeys(){
    const userId=Number(st.auth.user?.id||0);
    if(!st.auth.authenticated||userId<1)return null;
    return {userId,offer:guestImportRoot+'.offer.'+userId,recent:guestImportRoot+'.recent.'+userId,pending:guestImportRoot+'.pending.'+userId};
  }
  function guestMigrationPreview(){
    const keys=guestImportKeys();if(!keys)return null;
    const guestName=validGuestName(guestProfile?.display_name);
    const name=guestName&&guestName!==st.auth.user?.display_name?guestName:'';
    const playlists=guestSavedPlaylists.filter(item=>item?.id&&item?.name&&Array.isArray(item.track_ids)&&
      item.track_ids.length>0&&item.track_ids.every(id=>trackById(id)?.audio));
    const recent=[...new Set((Array.isArray(st.queue.history)?st.queue.history:[]).filter(id=>typeof id==='string'&&trackById(id)?.audio))].slice(0,30);
    const recentSignature=JSON.stringify(recent);
    let importedSignature='';try{importedSignature=localStorage.getItem(keys.recent)||''}catch(_){}
    const needsRecent=recent.length>0&&importedSignature!==recentSignature;
    return {keys,name,playlists,recent:needsRecent?recent:[],recentSignature};
  }
  function closeGuestMigrationDialog(){
    const panel=document.getElementById('guestMigrationDialog');
    if(!panel)return;
    panel.remove();document.body.classList.remove('sf-guest-migration-open');
    $('#reviewGuestMigration')?.focus?.({preventScroll:true});
  }
  function openGuestMigrationDialog(){
    const preview=guestMigrationPreview();
    if(!preview||(!preview.name&&!preview.playlists.length&&!preview.recent.length))return;
    closeGuestMigrationDialog();
    const layer=document.createElement('div');layer.id='guestMigrationDialog';layer.className='sf-guest-migration-layer';
    const playlistNames=preview.playlists.slice(0,4).map(p=>'<li>'+esc(p.name)+' · '+p.track_ids.length+' tracks</li>').join('');
    const recentNames=preview.recent.slice(0,5).map(id=>esc(trackById(id)?.title||id)).join(', ');
    layer.innerHTML=`<section class="sf-guest-migration-card" role="dialog" aria-modal="true" aria-labelledby="guestMigrationTitle"><div class="sf-guest-migration-kicker">STONEFELLOW / GUEST MUSIC</div><h2 id="guestMigrationTitle">Bring your music with you?</h2><p>Choose what to copy from this browser into your signed-in Stonefellow account. Existing account playlists and preferences are kept. Nothing transfers until you confirm.</p><form id="guestMigrationForm">${preview.name?`<label class="sf-guest-migration-option"><input type="checkbox" name="name"><span><strong>Use my guest name: ${esc(preview.name)}</strong><small>This replaces your account display name. Off by default.</small></span></label>`:''}${preview.playlists.length?`<label class="sf-guest-migration-option"><input type="checkbox" name="playlists" checked><span><strong>Save ${preview.playlists.length} guest playlist${preview.playlists.length===1?'':'s'}</strong><small>Private playlists in your account, available across devices.</small><ul>${playlistNames}</ul></span></label>`:''}${preview.recent.length?`<label class="sf-guest-migration-option"><input type="checkbox" name="recent"><span><strong>Remember ${preview.recent.length} recently selected song${preview.recent.length===1?'':'s'}</strong><small>${recentNames}. Optional and off by default. Helps future recommendations; no play dates or listening events are invented.</small></span></label>`:''}<p class="sf-guest-migration-error" id="guestMigrationError" role="alert" aria-live="polite"></p><div class="sf-guest-migration-actions"><button type="button" data-guest-import-close>Not now</button><button type="submit" class="sf-guest-migration-submit">Confirm import</button></div></form></section>`;
    document.body.appendChild(layer);document.body.classList.add('sf-guest-migration-open');
    const form=$('#guestMigrationForm',layer),err=$('#guestMigrationError',layer),submit=$('[type="submit"]',form);
    let importing=false;
    layer.querySelector('[data-guest-import-close]').onclick=()=>{if(!importing)closeGuestMigrationDialog();};
    layer.addEventListener('keydown',event=>{
      if(event.key==='Escape'){event.preventDefault();if(!importing)closeGuestMigrationDialog();return;}
      if(event.key!=='Tab')return;
      const list=Array.from(layer.querySelectorAll('button:not([disabled]),input:not([disabled])'));
      if(!list.length)return;
      const first=list[0],last=list[list.length-1];
      if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
      else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
    });
    form.onsubmit=async event=>{
      event.preventDefault();err.textContent='';
      const importName=!!form.elements.name?.checked,selectedPlaylists=form.elements.playlists?.checked?preview.playlists:[],selectedRecent=form.elements.recent?.checked?preview.recent:[];
      if(!importName&&!selectedPlaylists.length&&!selectedRecent.length){err.textContent='Choose at least one item to import.';return;}
      const payload={import_name:importName,display_name:importName?preview.name:'',playlists:selectedPlaylists.map(p=>({name:p.name,track_ids:p.track_ids})),recent_track_ids:selectedRecent};
      const previous=readJSON(preview.keys.pending,null);
      const requestId=previous&&JSON.stringify(previous.payload)===JSON.stringify(payload)?previous.request_id:
        'guest_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,16);
      try{localStorage.setItem(preview.keys.pending,JSON.stringify({request_id:requestId,payload}));}
      catch(_){err.textContent='Browser storage is unavailable. Keeping this data safe for a later attempt.';return;}
      importing=true;submit.disabled=true;submit.textContent='Importing…';
      try{
        const response=await secureJsonPost('guest-migration.php',{action:'import',request_id:requestId,...payload}),receipt=response.import||{};
        if(receipt.request_id!==requestId||Number(receipt.playlist_count)!==selectedPlaylists.length||
          Number(receipt.recent_count)!==selectedRecent.length||!!receipt.name_updated!==importName)
          throw new Error('The server did not confirm the complete import. Your guest information is still in this browser.');
        if(importName&&response.user?.display_name!==preview.name)
          throw new Error('Your account name could not be verified. Guest data is still in this browser.');
        if(importName&&response.user?.display_name===preview.name){
          st.auth.user=response.user;syncAuthMenu();
          try{localStorage.removeItem(guestProfileKey);guestProfile=null}catch(_){}
        }
        if(selectedPlaylists.length){
          if(selectedPlaylists.some(p=>JSON.stringify(p.track_ids)===currentMixSignature()))lastSavedMixSignature=currentMixSignature();
          const transferred=new Set(selectedPlaylists.map(p=>p.id));
          const remaining=guestSavedPlaylists.filter(p=>!transferred.has(p.id));
          try{localStorage.setItem(guestSavedPlaylistsKey,JSON.stringify(remaining));guestSavedPlaylists=remaining;}catch(_){}
        }
        if(selectedRecent.length)try{localStorage.setItem(preview.keys.recent,preview.recentSignature)}catch(_){}
        // Keep request id until local cleanup succeeds; a retry remains server-idempotent.
        const left=guestMigrationPreview();
        if(!left?.name&&!left?.playlists.length&&!left?.recent.length)try{localStorage.removeItem(preview.keys.pending)}catch(_){}
        st.playlists.loaded=false;st.personalization.loaded=false;st.home.loaded=false;
        updateQueueUi();closeGuestMigrationDialog();
        popupEntry('assistant','Your guest music is now linked to this Stonefellow account: '+selectedPlaylists.length+' playlist(s), '+selectedRecent.length+' recent-song hints'+(importName?', and your chosen name':'')+'.');
        await renderAccount();
      }catch(error){err.textContent=error.message||'The import did not complete. Your local data is still available.';}
      finally{importing=false;submit.disabled=false;submit.textContent='Confirm import';}
    };
    layer.querySelector('input,button')?.focus({preventScroll:true});
  }
  function renderGuestMigrationOffer(){
    const preview=guestMigrationPreview(),host=canvas?.querySelector('.account-header');
    if(!host||!preview||(!preview.name&&!preview.playlists.length&&!preview.recent.length))return;
    const button=document.createElement('button');button.type='button';button.id='reviewGuestMigration';button.className='text-control sf-guest-migration-trigger';
    button.textContent='Review guest music transfer';button.onclick=openGuestMigrationDialog;
    host.appendChild(button);
    let offered=false;try{offered=!!localStorage.getItem(preview.keys.offer)}catch(_){}
    if(!offered){
      try{localStorage.setItem(preview.keys.offer,'shown')}catch(_){}
      openGuestMigrationDialog();
    }
  }
  function hide(){starters.hidden=true}
  function persist(){st.builder.savedAt=new Date().toISOString();localStorage.setItem('stonefellow.build.v04',JSON.stringify(st.builder))}
  function persistCart(){localStorage.setItem('stonefellow.cart.v03',JSON.stringify(st.cart));if(st.drawer.mode==='commerce'&&!notificationDrawer?.hidden){syncDrawerChrome();if(st.drawer.tab==='cart')renderDrawer()}}
  function showCartAfterAdd(message){
    if(window.SFPublicAgentCanvas?.active?.()){openExperienceDrawer('commerce','cart');popupEntry('notice',message);}
    else void say(message,renderCart);
  }
  function download(name,obj){const blob=new Blob([JSON.stringify(obj,null,2)],{type:'application/json'}),u=URL.createObjectURL(blob),a=document.createElement('a');a.href=u;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(u),1000)}
  function trackById(id){return catalog.find(t=>t.id===id)}
  function findTitle(q){const n=norm(q);return catalog.find(t=>n.includes(norm(t.title)))||null}
  function scoreTrack(t,q){const qwords=words(q);let score=0;[t.title,t.release,...t.mood,...t.themes].forEach(field=>words(field).forEach(w=>{if(qwords.has(w))score+=field===t.title?5:2}));if(/quiet|mellow|soft|calm/.test(q)&&Number(t.energy)<=2)score+=4;if(/dark|heavy|loud|electric/.test(q)&&Number(t.energy)>=4)score+=4;if(/drive|road|driving/.test(q)&&(t.mood.includes('driving')||t.themes.includes('road')))score+=5;return score}
  function recommend(q,exclude=[]){const ranked=catalog.filter(t=>!exclude.includes(t.id)).map(t=>({t,s:scoreTrack(t,q)})).sort((a,b)=>b.s-a.s||a.t.energy-b.t.energy);return ranked[0]?.t||catalog.find(t=>!exclude.includes(t.id))||catalog[0]}
  function related(base){if(!base)return catalog[0];const keys=new Set([...base.mood,...base.themes]);return catalog.filter(t=>t.id!==base.id).map(t=>({t,s:[...t.mood,...t.themes].filter(x=>keys.has(x)).length*3-Math.abs((t.energy||3)-(base.energy||3))})).sort((a,b)=>b.s-a.s)[0]?.t||catalog.find(t=>t.id!==base.id)}
  async function api(path,body,signal){const opts=body===undefined?{credentials:'same-origin',signal}:{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(body),signal};const r=await fetch((cfg.apiBase||'api')+'/'+path,opts);let j={};try{j=await r.json();if(!j||typeof j!=='object'||Array.isArray(j))throw new Error('invalid_payload')}catch(_){const e=new Error(path==='storefront.php'?'Store service returned unreadable data (HTTP '+r.status+').':'Service returned unreadable data (HTTP '+r.status+').');e.status=r.status;throw e}if(!r.ok||j.ok===false){const e=new Error(j.message||j.errors?.join(' ')||j.error||'Store request failed.');e.payload=j;e.status=r.status;throw e}if(body!==undefined||j.entitlement||j.scene||j.workflow)if(typeof window!=='undefined')window.SFTokenMeter?.notifyUsage?.();return j}
  async function loadPlans(){try{const j=await api('plans.php');st.plans=j.plans||[];return st.plans}catch(_){st.plans=[];return []}}
  async function speakWithDeviceVoice(text,sequence=voicePlaybackSequence,updateOrb=true){
    if(!window.speechSynthesis||!window.SpeechSynthesisUtterance||!voiceRepliesEnabled)return false;
    // A browser voice is only a privacy-safe fallback when the browser marks it local.
    let installed=window.speechSynthesis.getVoices().filter(voice=>voice.localService===true);
    if(!installed.length){await new Promise(resolve=>{const synth=window.speechSynthesis;const timer=setTimeout(done,900);function done(){clearTimeout(timer);synth.removeEventListener?.('voiceschanged',done);resolve()}synth.addEventListener?.('voiceschanged',done)});installed=window.speechSynthesis.getVoices().filter(voice=>voice.localService===true)}
    if(!voiceRepliesEnabled||sequence!==voicePlaybackSequence)return null;
    const selected=installed.find(voice=>/^en[-_]/i.test(voice.lang))||installed[0];
    if(!selected)return false;
    const parts=globalThis.StonefellowSpeechSegments?.split?.(text,180)||[String(text)];
    if(!parts.length)return true;
    return new Promise(resolve=>{
      let finished=false,index=0,retries=0;
      const done=ok=>{if(finished)return;finished=true;setVoiceSpeechAudible(false);if(speechFallbackResolve===done)speechFallbackResolve=null;resolve(ok)};
      speechFallbackResolve=done;
      const retry=()=>{
        if(retries++>=1){done(false);return}
        const resume=()=>{
          if(finished)return;
          if(sequence!==voicePlaybackSequence||!voiceRepliesEnabled){done(null);return}
          cancelLocalVoiceOutput();next();
        };
        setTimeout(resume,150);
      };
      const next=()=>{
        if(finished)return;
        if(!voiceRepliesEnabled||sequence!==voicePlaybackSequence){done(null);return}
        if(index>=parts.length){done(true);return}
        const utterance=new window.SpeechSynthesisUtterance(parts[index]);
        utterance.voice=selected;utterance.lang=selected.lang||'en-US';utterance.rate=1;
        let settled=false,started=false;
        utterance.onstart=()=>{started=true;if(!finished&&sequence===voicePlaybackSequence){setVoiceSpeechAudible(true);if(typeof voiceSpeechGuard!=='undefined')voiceSpeechGuard?.audible();if(updateOrb)agent('speaking',text)}};
        utterance.onend=()=>{
          if(settled||finished)return;settled=true;
          if(sequence!==voicePlaybackSequence){done(null);return}
          setVoiceSpeechAudible(false);
          if(!started){retry();return}
          index++;retries=0;next();
        };
        utterance.onerror=event=>{
          if(settled||finished)return;settled=true;
          if(sequence!==voicePlaybackSequence){done(null);return}
          setVoiceSpeechAudible(false);
          if(['interrupted','canceled','audio-busy'].includes(event?.error)){retry();return}
          done(false);
        };
        try{window.speechSynthesis.speak(utterance)}catch(_){retry()}
      };
      try{cancelLocalVoiceOutput();next()}catch(_){done(false)}
    });
  }
  async function elevenLabsSpeak(text,retried=false,sequence=voicePlaybackSequence){
    if(!st.auth.authenticated)return false;
    const parts=globalThis.StonefellowSpeechSegments?.split?.(text,1400)||[String(text)];
    let nextUnspoken=0;
    try{
      for(let index=0;index<parts.length;index++){
        nextUnspoken=index;
        if(!voiceRepliesEnabled||sequence!==voicePlaybackSequence)return null;
        let r,attemptedRefresh=retried;
        while(true){
          r=await fetch((cfg.apiBase||'api')+'/agent-voice.php',{
            method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':st.auth.csrf||''},body:JSON.stringify({text:parts[index]})
          });
          if(r.status!==403||attemptedRefresh)break;
          let j={};try{j=await r.clone().json()}catch(_){}
          if(j.error!=='csrf_failed')break;
          attemptedRefresh=true;await loadAuth(true);
          if(sequence!==voicePlaybackSequence)return null;
        }
        if(!r.ok)return index?{remaining:parts.slice(index).join(' ')}:false;
        if(typeof window!=='undefined')window.SFTokenMeter?.notifyUsage?.();
        const blob=await r.blob(),url=URL.createObjectURL(blob);
        if(!voiceRepliesEnabled||sequence!==voicePlaybackSequence){URL.revokeObjectURL(url);return null}
        if(st.voiceAudio)st.voiceAudio.pause();
        const a=new Audio(url);st.voiceAudio=a;
        const spoken=await new Promise(resolve=>{
          let finished=false;
          const done=ok=>{if(finished)return;finished=true;if(sequence===voicePlaybackSequence)setVoiceSpeechAudible(false);URL.revokeObjectURL(url);if(st.voiceAudio===a)st.voiceAudio=null;if(voiceAudioCancel===cancel)voiceAudioCancel=null;resolve(ok)};
          const cancel=()=>{a.pause();done(null)};voiceAudioCancel=cancel;
          a.onended=()=>done(true);a.onerror=()=>done(false);
          a.play().then(()=>{if(!finished&&sequence===voicePlaybackSequence&&voiceRepliesEnabled){setVoiceSpeechAudible(true);if(typeof voiceSpeechGuard!=='undefined')voiceSpeechGuard?.audible();agent('speaking',text)}else if(!finished)cancel()}).catch(()=>done(false));
        });
        if(spoken===null||sequence!==voicePlaybackSequence)return null;
        if(spoken!==true)return index?{remaining:parts.slice(index).join(' ')}:false;
      }
      return true;
    }catch(_){return sequence!==voicePlaybackSequence?null:(nextUnspoken?{remaining:parts.slice(nextUnspoken).join(' ')}:false)}
  }
  async function agentSpeak(text,deviceOnly=false,signal){
    if(!voiceRepliesEnabled||(!st.conversationOn&&!voiceInteractionStarted)||!text||signal?.aborted)return null;
    if(voicePlaybackPending){
      if(voiceAudioCancel){const cancel=voiceAudioCancel;voiceAudioCancel=null;cancel()}
      if(speechFallbackResolve){const resolve=speechFallbackResolve;speechFallbackResolve=null;cancelLocalVoiceOutput();resolve(null)}
    }
    const sequence=++voicePlaybackSequence;
    const cancel=()=>{if(sequence===voicePlaybackSequence)interruptVoicePlayback();};
    signal?.addEventListener('abort',cancel,{once:true});
    if(typeof voiceSpeechGuard!=='undefined')voiceSpeechGuard?.begin(text);
    voicePlaybackPending=true;setVoiceInputActive(false);
    if(st.conversationOn){
      clearTimeout(conversationRestartTimer);
      if(!isIOSVoiceBrowser){
        try{recognition?.abort()}catch(_){}
        recognitionActive=false;
      }
    }
    try{
      const remote=deviceOnly?false:await elevenLabsSpeak(text,false,sequence);
      if(sequence!==voicePlaybackSequence)return null;
      if(remote===true||remote===null||!voiceRepliesEnabled)return remote;
      if(remote&&typeof remote==='object'&&remote.remaining)return await speakWithDeviceVoice(remote.remaining,sequence);
      // The installed browser voice is a local, free fallback; it sends no text to a new provider.
      return await speakWithDeviceVoice(text,sequence,!deviceOnly);
    }finally{
      signal?.removeEventListener('abort',cancel);
      if(sequence===voicePlaybackSequence){
        voicePlaybackPending=false;setVoiceSpeechAudible(false);if(typeof voiceSpeechGuard!=='undefined')voiceSpeechGuard?.finish();voiceEchoUntil=Date.now()+3000;syncConversationMusic();
        if(agentInterrupt)agentInterrupt.hidden=true;
        if(isIOSVoiceBrowser)iosVoiceMuteUntil=Date.now()+1800;
        if(!deviceOnly)agent(st.conversationOn&&!iosVoiceNeedsGesture?'listening':st.playing?'playing':'idle');
        if(iosVoiceNeedsGesture&&st.conversationOn)showTranscript('Microphone paused · tap Resume Voice Chat.','waiting');
        if(st.conversationOn&&!voiceTurnBusy)scheduleConversationListen();
      }
    }
  }
  // Browser-owned action receipt: all Agent entry points share this adapter.
  // Never turn a server plan into a completed-action claim without observing
  // the local route, player state, or the result of an authenticated write.
  async function executeAgentAction(a){
    if(!a?.type||a.type==='none')return null;
    const t=a.track_id?trackById(a.track_id):null;
    if(['play_track','pause_player','stop_player','resume_player','next_track','previous_track','player_status','set_player_volume','load_temporary_playlist'].includes(a.type))
      return executePlayerAgentAction(a);
    switch(a.type){
      case 'open_view':{
        const view=a.view||'home';
        return await navigate(view,{slug:a.slug||'',id:a.id||'',type:a.inquiry_type||''})
          ?'Opened '+(view==='builder'?'the Create Record studio':view.replaceAll('_',' '))+'.':'That page could not be opened.';
      }
      case 'open_search':return await navigate('music',{q:a.query||''})?'Opened the music catalog.':'The music catalog could not be opened.';
      case 'open_site_search':return await navigate('search',{q:a.query||'',type:a.result_type||'all'})?'Opened site search.':'Site search could not be opened.';
      case 'open_library':return await navigate('library',{tab:a.tab||'all'})?'Opened your library.':'Your library could not be opened.';
      case 'open_notifications':openNotificationDrawer();return 'Opened your notifications.';
      case 'show_track':{
        if(!t)return 'That song is no longer in the published catalog.';
        return await navigate('track',{id:t.id})?'Opened the details for “'+t.title+'.”':'The song details could not be opened.';
      }
      case 'builder_add_track':{
        if(!t)return 'That song is no longer in the published catalog.';
        const result=add(t,a.side||undefined);persist();
        await navigate('builder');
        return result.ok?'Added “'+t.title+'” to Side '+result.side+'.'
          :result.why==='already'?'“'+t.title+'” is already on the record.':'“'+t.title+'” does not fit on the record. No track was added.';
      }
      case 'builder_move_track':return t?moveBuilderTrackToSide(t.id,a.side,false):'That song is no longer in the published catalog.';
      case 'builder_fill':return fillBuild(a.side||'both',false);
      case 'builder_dedupe':{
        const before=selectedBuildIds().length;dedupeBuild();
        const count=before-selectedBuildIds().length;
        return count?'Removed '+count+' duplicate track'+(count===1?'':'s')+' from the record.':'The record already has no duplicate tracks.';
      }
      case 'builder_save':{
        const saved=await saveBuilderDraft(false);
        return saved?'Saved “'+st.builder.title+'” '+(saved.id==='local'?'on this device.':'to your account drafts.'):'The record draft was not saved.';
      }
      case 'set_favorite_track':{
        if(!t)return 'That song is no longer in the published catalog.';
        const desired=a.favorite===true;
        const j=await secureJsonPost('personalization.php',{action:'set_favorite',item_type:'track',item_id:t.id,favorite:desired});
        st.personalization={loaded:true,...(j.personalization||{})};st.home.loaded=false;
        return desired?'Saved “'+t.title+'” to your favorites.':'Removed “'+t.title+'” from your favorites.';
      }
      case 'create_playlist':{
        const j=await secureJsonPost('playlists.php',{action:'create',name:a.name||'Stonefellow Mix',description:'Curated by the Stonefellow agent.',visibility:a.visibility||'private',source_type:'agent',track_ids:Array.isArray(a.track_ids)?a.track_ids:[]});
        if(!j.playlist?.id)return 'The server did not confirm a saved playlist.';
        st.playlists={loaded:true,items:j.playlists||[]};await navigate('playlist',{id:j.playlist.id});
        return 'Saved “'+(j.playlist.name||a.name||'Stonefellow Mix')+'” to your playlists.';
      }
      case 'save_agent_session':{
        const j=await secureJsonPost('playlists.php',{action:'save_agent_session',name:a.name||'Agent Listening Session'});
        if(!j.playlist?.id)return 'The server did not confirm a saved listening session.';
        st.playlists={loaded:true,items:j.playlists||[]};await navigate('playlist',{id:j.playlist.id});
        return 'Saved “'+(j.playlist.name||a.name||'Agent Listening Session')+'” as a playlist.';
      }
      case 'start_listening_session':{
        const started=await startAgentListeningSession(a.session);
        return started?'Started the “'+st.agentSession.title+'” listening session. '+describePlayer()
          :'The listening session was created, but the browser did not confirm playback. Open the playlist and tap Play.';
      }
      case 'session_feedback':{
        if(!a.session_id||!a.track_id)return 'A listening session and track are required for feedback.';
        const j=await secureJsonPost('listening-sessions.php',{action:'feedback',session_id:a.session_id,track_id:a.track_id,sentiment:a.sentiment||'neutral'});
        st.agentSession={...st.agentSession,...(j.session||{})};await loadPersonalization(true);renderAgentSessionPanel();
        return 'Saved your feedback for “'+(trackById(a.track_id)?.title||'this song')+'.”';
      }
      case 'end_listening_session':{
        if(!a.session_id)return 'No listening session was supplied.';
        const j=await secureJsonPost('listening-sessions.php',{action:'end',session_id:a.session_id});
        st.agentSession={...st.agentSession,...(j.session||{}),status:'ended'};clearPlaylistPlayback();renderAgentSessionPanel();
        return 'Ended the listening session.';
      }
      case 'save_current_listening_session':{
        if(!a.session_id)return 'There is no listening session to save.';
        const j=await secureJsonPost('listening-sessions.php',{action:'save_playlist',session_id:a.session_id,name:a.name||st.agentSession.title||'Agent Listening Session'});
        if(!j.playlist?.id)return 'The server did not confirm a saved playlist.';
        st.playlists={loaded:true,items:j.playlists||[]};await navigate('playlist',{id:j.playlist.id});
        return 'Saved “'+(j.playlist.name||a.name||'Agent Listening Session')+'” as a playlist.';
      }
      case 'open_queue':await openQueue();return 'Opened your song queue.';
      case 'queue_add_track':return addTrackToQueue(t,'end',false);
      case 'queue_play_next':return addTrackToQueue(t,'next',false);
      case 'queue_remove_track':return a.track_id?removeTrackFromQueue(a.track_id,false):'Choose a song to remove from Up Next.';
      case 'queue_clear':return clearQueue(false,false);
    }
    return null;
  }
  function syncAuthMenu(){
    // Account navigation is rendered exclusively by PHP on full-page requests.
    document.documentElement.dataset.authState=st.auth.authenticated?'logged-in':'logged-out';
    window.SFTokenMeter?.setOwner?.();
    window.SFListeningTelemetry?.setOwner?.();
    syncCanvasDebugOwner();
    if(accountPhotoPreferencesRequest&&accountPhotoPreferencesRequest.owner!==canvasOwner()){
      accountPhotoPreferencesRequest.request.abort();accountPhotoPreferencesRequest=null;
    }
    if(chatImagePicker&&chatImagePicker.owner!==canvasOwner())closeChatImagePicker(chatImagePicker,false);
    if(chatMusicPicker&&chatMusicPicker.owner!==canvasOwner())chatMusicPicker.request.abort();
    if(chatResourcePicker&&chatResourcePicker.owner!==canvasOwner())chatResourcePicker.request.abort();
    if(stopListeningRoomBuild.pending&&stopListeningRoomBuild.pending.owner!==canvasOwner())stopListeningRoomBuild.pending=null;
    if(canvasImageEditDraft&&canvasImageEditDraft.owner!==canvasOwner())setCanvasImageEditDraft(null);
    if(gallerySceneRequest&&gallerySceneRequest.galleryOwner!==canvasOwner())cancelGalleryOpen();
    if((!st.auth.authenticated&&!isGalleryScene())||(listeningRoomOwner!==null&&listeningRoomOwner!==canvasSceneOwner()))closeListeningRoom(false);
    canvasGalleryView?.setOwner();
    canvasAccess.current?.setOwner();
    const canvasPermissionsMenu=document.getElementById('menuCanvasPermissions');if(canvasPermissionsMenu)canvasPermissionsMenu.hidden=!canvasOwner();
    window.SFPublicAgentCanvas?.syncSceneAccess?.();
    syncCanvasCodingModelMenu();
    canvasProjects()?.setOwner();
  }
  async function loadAuth(force=false){
    if(st.auth.loaded&&!force)return st.auth;
    try{
      const r=await fetch((cfg.apiBase||'api')+'/auth.php',{cache:'no-store',credentials:'same-origin'});
      const j=await r.json();
      if(!r.ok||j.ok===false)throw new Error(j.message||j.error||'Could not load account status.');
      st.auth={loaded:true,authenticated:!!j.authenticated,user:j.user||null,csrf:j.csrf||null};
    }catch(e){
      st.auth={...st.auth,loaded:true,error:e.message};
    }
    syncAuthMenu();return st.auth;
  }
  async function authPost(body,retried=false){
    if(!st.auth.csrf)await loadAuth(true);
    const headers={'Content-Type':'application/json','X-CSRF-Token':st.auth.csrf||''};
    const r=await fetch((cfg.apiBase||'api')+'/auth.php',{method:'POST',headers,credentials:'same-origin',body:JSON.stringify(body||{})});
    let j={};try{j=await r.json()}catch(_){throw new Error('Account service returned an invalid response.')}
    if(r.status===403&&j.error==='csrf_failed'&&!retried){await loadAuth(true);return authPost(body,true)}
    if(!r.ok||j.ok===false){const e=new Error(j.message||j.error||'Account request failed.');e.payload=j;e.status=r.status;throw e}
    if(Object.prototype.hasOwnProperty.call(j,'authenticated')||j.user){
      const hasAuth=Object.prototype.hasOwnProperty.call(j,'authenticated');
      const authenticated=hasAuth?!!j.authenticated:(j.user?true:st.auth.authenticated);
      st.auth={loaded:true,authenticated,user:j.user||(authenticated?st.auth.user:null),csrf:j.csrf||st.auth.csrf||null};
      syncAuthMenu();
    }
    return j;
  }
  async function readSceneResponseStream(response,onDelta,onConcept,onRetry,onTrace){
    const reader=response.body?.getReader();
    if(!reader)throw new Error('Missing scene stream');
    const decoder=new TextDecoder('utf-8',{fatal:true});
    const eventLimit=1048576;
    let buffer='',outputLength=0,conceptReceived=false,retried=false;
    try{
      while(true){
        const {done,value}=await reader.read();
        if(done)break;
        buffer+=decoder.decode(value,{stream:true});
        let end;
        while((end=buffer.indexOf('\n'))!==-1){
          if(end>eventLimit)throw new Error('Scene event exceeded its limit');
          const event=JSON.parse(buffer.slice(0,end));
          buffer=buffer.slice(end+1);
          if(event?.type==='retry'&&!retried){
            retried=true;
            conceptReceived=false;
            outputLength=0;
            onRetry?.(event.reason);
          }else if(event?.type==='concept'&&typeof event.text==='string'){
            if(!conceptReceived&&!outputLength&&event.text.length<=160){
              conceptReceived=true;
              onConcept?.(event.text);
            }
          }else if(event?.type==='delta'&&typeof event.text==='string'){
            outputLength+=event.text.length;
            if(outputLength>36000)throw new Error('Scene output exceeded its limit');
            onDelta(event.text);
          }else if(event?.type==='image'&&['generating','working','saved'].includes(event.state)){
            if(event.state!=='working')onTrace?.('image_'+event.state,{index:event.index,total:event.total,image:event.image});
          }else if((event?.type==='result'||event?.type==='error')&&Number.isInteger(event.status)&&
            (event.type==='result'?event.status>=200&&event.status<300:event.status>=400&&event.status<600))return event;
          else throw new Error('Invalid scene event');
        }
        if(buffer.length>eventLimit)throw new Error('Scene event exceeded its limit');
      }
      throw new Error('Scene stream ended before its result');
    }finally{
      void reader.cancel().catch(()=>{});
      reader.releaseLock();
    }
  }
  // A deferred result is already saved. Read its authorized data without replaying the build.
  async function hydrateCanvasDatasets(result,contextTrackId,signal){
    const original=result.scene,id=original?.id;
    const trackId=typeof contextTrackId==='string'&&/^[A-Za-z0-9_-]{1,180}$/.test(contextTrackId)?contextTrackId:'';
    if(signal?.aborted)throw new DOMException('Canvas data request canceled.','AbortError');
    try{
      if(!Number.isSafeInteger(id)||id<1||original.canvas_kind!=='custom')throw new Error('Invalid saved canvas.');
      const {scene}=await api('agent-scene.php?id='+encodeURIComponent(id)+'&current_track_id='+encodeURIComponent(trackId),undefined,signal);
      if(scene?.id!==id||scene.canvas_kind!==original.canvas_kind||scene.track_id!==original.track_id)
        throw new Error('The saved canvas changed.');
      const {datasets,dataset_track_id:datasetTrackId}=scene;
      if(datasetTrackId!==undefined&&datasetTrackId!==trackId||
        datasets!==undefined&&(!datasets||typeof datasets!=='object'||Array.isArray(datasets)))
        throw new Error('Invalid saved canvas data.');
      if(signal?.aborted)throw new DOMException('Canvas data request canceled.','AbortError');
      const {datasets_deferred,...response}=result;
      return {...response,scene:{...original,datasets,dataset_track_id:datasetTrackId}};
    }catch{
      if(signal?.aborted)throw new DOMException('Canvas data request canceled.','AbortError');
      throw Object.assign(new Error('Your canvas was saved. Its data could not load. Open Canvas History to try again.'),{
        payload:{error:'scene_data_unavailable',generated_images:result.generated_images,image_total:result.image_total}});
    }
  }
  async function secureJsonPost(path,payload,retried=false,signal,onDelta,onConcept,onRetry,onTrace){
    if(signal?.aborted)throw new Error('The request was canceled.');
    if(!st.auth.csrf)await loadAuth(true);
    if(signal?.aborted)throw new Error('The request was canceled.');
    const headers={'Content-Type':'application/json','X-CSRF-Token':st.auth.csrf||''};
    if(onDelta&&typeof ReadableStream==='function'&&typeof TextDecoder==='function')headers.Accept='application/x-ndjson';
    const r=await fetch((cfg.apiBase||'api')+'/'+path,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify(payload||{}),signal});
    const type=r.headers.get('content-type')?.split(';')[0].trim().toLowerCase();
    const streamed=!!headers.Accept&&type===headers.Accept;
    onTrace?.('response_headers',{http_status:r.status,transport:streamed?'ndjson':type==='application/json'?'json':'other'});
    let j,status=r.status;
    try{
      if(streamed){
        const event=await readSceneResponseStream(r,onDelta,onConcept,onRetry,onTrace);
        j=event.data;
        status=event.status;
      }else j=await r.json();
      if(!j||typeof j!=='object'||Array.isArray(j))throw new Error('Invalid JSON payload');
    }catch(_){
      onTrace?.('response_unreadable',{});
      const error=new Error('The server returned an invalid response.');error.status=r.status;throw error;
    }
    onTrace?.('response_result',{status});
    if(typeof window!=='undefined')window.SFTokenMeter?.notifyUsage?.();
    if(status===403&&j.error==='csrf_failed'&&!retried){await loadAuth(true);return secureJsonPost(path,payload,true,signal,onDelta,onConcept,onRetry,onTrace)}
    if(status<200||status>=300||j.ok===false){const e=new Error(j.message||j.error||'Request failed.');e.payload=j;e.status=status;throw e}
    if(streamed&&j.datasets_deferred===true){
      if(path==='agent-scene.php')return hydrateCanvasDatasets(j,payload?.context_track_id,signal);
      if(path==='canvas-build-workflow.php'&&payload?.action==='repair')return hydrateCanvasDatasets(j,payload.current_track_id,signal);
    }
    if(path==='privacy.php'&&['save_profile','clear_history','clear_agent_history','reset_personalization'].includes(payload?.action)||path==='personalization.php'&&payload?.action==='clear_history')
      globalThis.SFListeningTelemetry?.privacyChanged?.();
    return j;
  }
  function applyQueue(queue){const q=queue||{};st.queue.loaded=true;st.queue.items=Array.isArray(q.items)?q.items:[];updateQueueUi();return st.queue.items}
  function isTemporaryPlaylist(){return st.playlistPlayback.temporary===true&&st.playlistPlayback.trackIds.length>0}
  function updateQueueUi(){
    const temp=isTemporaryPlaylist(),n=temp?st.playlistPlayback.trackIds.length:st.queue.items.length;
    try{
      if(temp)localStorage.setItem(temporaryPlaylistKey,JSON.stringify({version:1,playlistName:st.playlistPlayback.playlistName,
        trackIds:st.playlistPlayback.trackIds,index:st.playlistPlayback.index}));
      else localStorage.removeItem(temporaryPlaylistKey);
    }catch(_){/* Private browsing may make device storage unavailable. */}
    if(playerQueueCount)playerQueueCount.textContent=String(n);
    if(playerQueueButton){playerQueueButton.firstChild.nodeValue=temp?'Playlist ':'Queue ';playerQueueButton.setAttribute('aria-label',temp?'Open temporary playlist and song queue':'Open Up Next queue')}
    if(queueDrawerCount)queueDrawerCount.textContent=n+' track'+(n===1?'':'s');
    window.SFPublicAgentCanvas?.syncPlaylist?.();
    syncWelcomeActions();
    if(st.queue.open)renderQueueDrawer();
    scheduleSpeechContextSync();
  }
  async function loadQueue(force=false){if(!st.auth.authenticated){st.queue={...st.queue,loaded:true,items:[]};updateQueueUi();return st.queue.items}if(st.queue.loaded&&!force)return st.queue.items;try{const j=await api('queue.php');if(j.csrf)st.auth.csrf=j.csrf||st.auth.csrf;applyQueue(j.queue)}catch(_){st.queue={...st.queue,loaded:true,items:[]};updateQueueUi()}return st.queue.items}
  function pushQueueHistory(){const id=st.track?.id;if(!id)return;const h=Array.isArray(st.queue.history)?st.queue.history:[];if(h[0]!==id)h.unshift(id);st.queue.history=h.slice(0,30);localStorage.setItem('stonefellow.queue.history.v1',JSON.stringify(st.queue.history))}
  async function addTrackToQueue(t,placement='end',announce=true){
    if(!t)return 'The selected song is not in the published catalog.';
    if(!st.auth.authenticated){if(announce)say('Sign in to keep an Up Next queue across devices.',()=>navigate('login'));return 'Sign in to use the saved queue.'}
    try{
      const j=await secureJsonPost('queue.php',{action:'add',track_id:t.id,placement:placement==='next'?'next':'end'});
      applyQueue(j.queue);
      if(!Array.isArray(j.queue?.items)||!j.queue.items.some(item=>String(item.track_id)===String(t.id)))
        return 'The server did not confirm “'+t.title+'” in Up Next.';
      const result=placement==='next'?'“'+t.title+'” is queued to play next.':'Added “'+t.title+'” to Up Next.';
      if(announce)say(result);return result;
    }catch(e){if(announce)say(e.message);return 'The queue was not updated: '+e.message}
  }
  async function removeTrackFromQueue(trackId,announce=true){
    if(!st.auth.authenticated)return 'Sign in to edit the saved queue.';
    try{
      const before=await loadQueue(true);
      const wasQueued=before.some(item=>String(item.track_id)===String(trackId));
      if(!wasQueued)return 'That song is not currently in Up Next.';
      const j=await secureJsonPost('queue.php',{action:'remove',track_id:trackId});applyQueue(j.queue);
      if(!Array.isArray(j.queue?.items)||j.queue.items.some(item=>String(item.track_id)===String(trackId)))
        return 'The server did not confirm that the song was removed from Up Next.';
      return 'Removed “'+(trackById(trackId)?.title||'the song')+'” from Up Next.';
    }catch(e){if(announce)say(e.message);return 'The queue was not updated: '+e.message}
  }
  async function removeQueueItem(itemId){try{const j=await secureJsonPost('queue.php',{action:'remove',item_id:Number(itemId)});applyQueue(j.queue)}catch(e){say(e.message)}}
  async function reorderQueueItem(itemId,delta){const ids=st.queue.items.map(x=>Number(x.item_id)),i=ids.indexOf(Number(itemId)),j=i+Number(delta);if(i<0||j<0||j>=ids.length)return;[ids[i],ids[j]]=[ids[j],ids[i]];try{const x=await secureJsonPost('queue.php',{action:'reorder',item_ids:ids});applyQueue(x.queue)}catch(e){say(e.message)}}
  async function clearQueue(confirmFirst=true,announce=true){
    if(!st.auth.authenticated)return 'Sign in to edit the saved queue.';
    if(confirmFirst&&!confirm('Clear everything from Up Next?'))return 'Queue unchanged.';
    try{const j=await secureJsonPost('queue.php',{action:'clear'});applyQueue(j.queue);if(!Array.isArray(j.queue?.items)||j.queue.items.length)return 'The server did not confirm an empty queue.';if(announce&&!confirmFirst)say('Up Next is clear.');return 'Up Next is clear.'}
    catch(e){if(announce)say(e.message);return 'The queue was not cleared: '+e.message}
  }
  async function restoreQueueTrack(trackId){
    if(!trackId)return;
    try{const restored=await secureJsonPost('queue.php',{action:'add',track_id:String(trackId),placement:'next'});applyQueue(restored.queue)}
    catch(_){void loadQueue(true)}
  }
  async function takeQueueItem(itemId){
    if(agentSessionActive()){say('End the active Agent listening session before switching to a queued track.');return false}
    invalidatePlayback();audio.pause();const token=playbackLifecycle.token();
    try{
      const j=await secureJsonPost('queue.php',{action:'take',item_id:Number(itemId)});
      if(!playbackLifecycle.isCurrent(token)){
        if(j.item?.track_id)await restoreQueueTrack(j.item.track_id);
        return false;
      }
      applyQueue(j.queue);const t=j.item?trackById(j.item.track_id):null;if(!t)return false;
      pushQueueHistory();const started=await play(t,0,'queue');
      if(!started)await restoreQueueTrack(j.item.track_id);
      return started;
    }catch(e){say(e.message);return false}
  }
  async function playNextFromQueue(expectedToken=null){
    if(agentSessionActive()||!st.auth.authenticated||queueAdvancePending)return false;
    const token=expectedToken??playbackLifecycle.token();queueAdvancePending=true;
    try{
      const j=await secureJsonPost('queue.php',{action:'pop'});
      if(!playbackLifecycle.isCurrent(token)){
        // A user action took over while the server responded: restore the popped item.
        if(j.item?.track_id)await restoreQueueTrack(j.item.track_id);
        else void loadQueue(true);
        return false;
      }
      applyQueue(j.queue);
      const t=j.item?trackById(j.item.track_id):null;if(!t)return false;
      pushQueueHistory();const started=await play(t,0,'queue');
      if(!started)await restoreQueueTrack(j.item.track_id);
      return started;
    }catch(_){return false}
    finally{queueAdvancePending=false}
  }
  function renderQueueDrawer(){
    if(!queueContent)return;
    const temp=isTemporaryPlaylist(),rows=st.queue.items||[],mix=st.playlistPlayback;
    const title=document.getElementById('queueDrawerTitle');if(title)title.textContent=temp?'Your Playlist':'Up Next';
    if(queueClear)queueClear.hidden=!rows.length;
    const playlistHtml=temp?`<section class="temporary-playlist" aria-label="Temporary song playlist"><div class="temporary-playlist-head"><div><span class="drawer-kicker">TEMPORARY PLAYLIST · ${currentMixSaved()?'SAVED':'NOT SAVED'}</span><h3>${esc(mix.playlistName)}</h3></div><span>${mix.trackIds.length} SONGS</span></div>${mix.trackIds.map((id,i)=>{const t=trackById(id);if(!t)return '';const current=i===mix.index;return `<article class="queue-item temporary-playlist-item ${current?'is-current':''}" ${current?'aria-current="true"':''}><button type="button" class="queue-item-play" data-temp-play="${i}" aria-label="Play ${esc(t.title)}">${current&&!audio.paused?'♫':'▶'}</button><div><strong>${String(i+1).padStart(2,'0')} · ${esc(t.title)}</strong><small>${esc(t.release||'Stonefellow')} · ${time(t.duration)}</small></div><span class="temporary-playlist-state">${current?(audio.ended?'FINISHED':audio.paused?'READY':'NOW PLAYING'):''}</span></article>`}).join('')}${currentMixSaved()?'':`<button type="button" class="buy-primary" data-queue-save>Save this playlist</button>`}</section>`:'';
    const savedHtml=rows.length?`<section class="persistent-queue"><h3>${temp?'UP NEXT · SAVED QUEUE':'UP NEXT'}</h3>${rows.map((x,i)=>`<article class="queue-item"><button type="button" class="queue-item-play" data-queue-play="${x.item_id}" aria-label="Play ${esc(x.title)}">▶</button><div><strong>${esc(x.title)}</strong><small>${esc(x.release||'')} · ${time(x.duration)}</small></div><div class="queue-item-actions"><button type="button" data-queue-move="${x.item_id}" data-dir="-1" ${i===0?'disabled':''}>↑</button><button type="button" data-queue-move="${x.item_id}" data-dir="1" ${i===rows.length-1?'disabled':''}>↓</button><button type="button" data-queue-remove="${x.item_id}" aria-label="Remove ${esc(x.title)}">×</button></div></article>`).join('')}</section>`:'';
    const deviceHtml=guestSavedPlaylists.length?`<section class="persistent-queue guest-device-playlists"><div class="drawer-kicker">GUEST MUSIC · THIS BROWSER ONLY</div><h3>Saved on this device</h3>${guestSavedPlaylists.slice().reverse().map(p=>`<article class="queue-item"><button type="button" class="queue-item-play" data-guest-playlist-load="${esc(p.id)}" aria-label="Load ${esc(p.name)}">▶</button><div><strong>${esc(p.name)}</strong><small>${p.track_ids.length} SONGS · SAVED HERE</small></div><button type="button" class="queue-guest-remove" data-guest-playlist-remove="${esc(p.id)}" aria-label="Remove saved playlist ${esc(p.name)}">×</button></article>`).join('')}</section>`:'';
    queueContent.innerHTML=playlistHtml+savedHtml+deviceHtml||'<section class="queue-empty-workspace"><div class="drawer-kicker">STONEFELLOW / PLAYLIST STUDIO</div><h3>Nothing in the queue yet</h3><p>Ask Stonefellow to create a playlist, or start a random mix right here.</p><button class="buy-primary" type="button" data-queue-create-random>Create random playlist</button></section>';
    queueContent.querySelector('[data-queue-create-random]')?.addEventListener('click',()=>void startRandomTemporaryPlaylist().then(result=>popupEntry('notice',result)));
    queueContent.querySelector('[data-queue-save]')?.addEventListener('click',()=>void saveTemporaryPlaylist().then(result=>popupEntry('assistant',result)));
    Array.from(queueContent.querySelectorAll('[data-guest-playlist-load]')).forEach(b=>b.onclick=()=>void loadGuestSavedPlaylist(b.dataset.guestPlaylistLoad));
    Array.from(queueContent.querySelectorAll('[data-guest-playlist-remove]')).forEach(b=>b.onclick=()=>{
      if(b.dataset.confirmRemove!=='yes'){b.dataset.confirmRemove='yes';b.textContent='Confirm ×';b.setAttribute('aria-label','Confirm removal of saved playlist');return;}
      const next=guestSavedPlaylists.filter(p=>p.id!==b.dataset.guestPlaylistRemove);
      try{localStorage.setItem(guestSavedPlaylistsKey,JSON.stringify(next))}catch(_){popupEntry('notice','Could not update device storage.');return;}
      guestSavedPlaylists=next;updateQueueUi();popupEntry('notice','Removed the playlist saved on this device.');
    });
    Array.from(queueContent.querySelectorAll('[data-temp-play]')).forEach(b=>b.onclick=()=>playTemporaryTrack(Number(b.dataset.tempPlay)));
    Array.from(queueContent.querySelectorAll('[data-queue-play]')).forEach(b=>b.onclick=()=>takeQueueItem(b.dataset.queuePlay));
    Array.from(queueContent.querySelectorAll('[data-queue-remove]')).forEach(b=>b.onclick=()=>removeQueueItem(b.dataset.queueRemove));
    Array.from(queueContent.querySelectorAll('[data-queue-move]')).forEach(b=>b.onclick=()=>reorderQueueItem(b.dataset.queueMove,b.dataset.dir));
    const n=temp?mix.trackIds.length:rows.length;
    if(queueDrawerCount)queueDrawerCount.textContent=n+' track'+(n===1?'':'s');
  }
  async function openQueue(){
    // The queue is a right-hand canvas drawer, never a /music page or page modal.
    if(canvas?.id==='sfAgentNativePage'&&window.SFPublicAgentCanvas?.active?.()){
      window.SFPublicAgentCanvas.closePage?.(true);canvas=mainCanvas;setUrl('home',{});
    }
    if(notificationDrawer&&!notificationDrawer.hidden)closeNotificationDrawer();
    if(queueDrawer?.hidden)queueReturnFocus=document.activeElement;
    st.queue.open=true;if(queueDrawer)queueDrawer.hidden=false;if(queueBackdrop)queueBackdrop.hidden=false;
    playerQueueButton?.setAttribute('aria-expanded','true');document.body.classList.add('queue-open');renderQueueDrawer();
    queueClose?.focus({preventScroll:true});
    if(st.auth.authenticated)void loadQueue(true);
  }
  function closeQueue(){st.queue.open=false;if(queueDrawer)queueDrawer.hidden=true;if(queueBackdrop)queueBackdrop.hidden=true;playerQueueButton?.setAttribute('aria-expanded','false');document.body.classList.remove('queue-open');if(queueReturnFocus?.isConnected)queueReturnFocus.focus({preventScroll:true});queueReturnFocus=null}
  async function loadPersonalization(force=false){
    if(!st.auth.authenticated){st.personalization={loaded:true,favorites:{tracks:[],releases:[]},continue_listening:[],history:[],recommendations:{personalized:false,summary:'Sign in and listen to make recommendations personal.',signals:{},items:[]}};return st.personalization}
    if(st.personalization.loaded&&!force)return st.personalization;
    try{const j=await api('personalization.php');st.personalization={loaded:true,...(j.personalization||{})};if(j.csrf)st.auth.csrf=j.csrf||st.auth.csrf}catch(_){st.personalization={loaded:true,favorites:{tracks:[],releases:[]},continue_listening:[],history:[]}}
    return st.personalization;
  }
  function favoriteIds(type){return new Set(((st.personalization.favorites||{})[type==='track'?'tracks':'releases']||[]).map(x=>String(x.id)))}
  async function loadHome(force=false){
    if(!st.auth.authenticated){st.home={loaded:true,data:null};return null}
    if(st.home.loaded&&!force)return st.home.data;
    try{const j=await api('home.php');if(j.csrf)st.auth.csrf=j.csrf||st.auth.csrf;st.home={loaded:true,data:j.home||null};return st.home.data}
    catch(_){st.home={loaded:true,data:null};return null}
  }

  function isFavorite(type,id){return favoriteIds(type).has(String(id))}
  function favoriteButton(type,id,label='Favorite'){const active=isFavorite(type,id);return `<button class="favorite-control ${active?'active':''}" type="button" data-favorite-type="${esc(type)}" data-favorite-id="${esc(id)}" aria-pressed="${active?'true':'false'}" aria-label="${active?'Remove from favorites':'Add to favorites'}">${active?'♥':'♡'}<span>${esc(active?'Favorited':label)}</span></button>`}
  function bindFavoriteButtons(r=document){$$('[data-favorite-type]',r).forEach(b=>b.onclick=async e=>{e.stopPropagation();await toggleFavorite(b.dataset.favoriteType,b.dataset.favoriteId)})}
  async function toggleFavorite(type,id){if(!st.auth.authenticated){say('Sign in to save favorites.',()=>navigate('login'));return}try{const desired=!isFavorite(type,id),j=await secureJsonPost('personalization.php',{action:'set_favorite',item_type:type,item_id:id,favorite:desired});st.personalization={loaded:true,...(j.personalization||{})};st.home.loaded=false;routeFromLocation()}catch(e){say(e.message)}}
  async function clearListeningHistory(){if(!confirm('Clear your personal listening history and Continue Listening positions? Aggregate anonymous listening totals will remain.'))return;try{const j=await secureJsonPost('personalization.php',{action:'clear_history'});st.personalization={loaded:true,...(j.personalization||{})};st.home.loaded=false;say('Your personal listening history was cleared.',()=>navigate('account'))}catch(e){say(e.message)}}
  function resumeTrack(trackId,position){const t=trackById(trackId);if(!t)return;play(t,Number(position)||0);renderStory(t);setUrl('track',{id:t.id})}
  async function loadPlaylists(force=false){
    if(!st.auth.authenticated){st.playlists={loaded:true,items:[]};return st.playlists.items}
    if(st.playlists.loaded&&!force)return st.playlists.items;
    try{const j=await api('playlists.php');st.playlists={loaded:true,items:j.playlists||[]};if(j.csrf)st.auth.csrf=j.csrf||st.auth.csrf}catch(_){st.playlists={loaded:true,items:[]}}
    return st.playlists.items;
  }
  async function loadAgentListeningSession(){if(!st.auth.authenticated)return null;try{const j=await api('listening-sessions.php');if(j.csrf)st.auth.csrf=j.csrf||st.auth.csrf;if(j.session){st.agentSession={id:j.session.id,title:j.session.title||'Agent Listening Session',mode:j.session.mode||'mix',status:j.session.status||'',currentIndex:Number(j.session.current_index||0),tracks:j.session.tracks||[]};const ids=sessionTrackIds();if(ids.length)st.playlistPlayback={playlistId:'agent-session:'+j.session.id,playlistName:st.agentSession.title,trackIds:ids,index:Math.max(0,Math.min(ids.length-1,st.agentSession.currentIndex))};renderAgentSessionPanel();return j.session}}catch(_){}return null}
  function agentSessionActive(){return !!(st.agentSession?.id&&st.agentSession.status==='active')}
  function agentSessionVisible(){return !!(st.agentSession?.id&&st.agentSession.status!=='ended')}
  function sessionTrackIds(){return (st.agentSession?.tracks||[]).map(x=>x.track_id).filter(id=>trackById(id))}
  async function startAgentListeningSession(session){
    if(!session?.id)return false;
    st.agentSession={id:session.id,title:session.title||'Agent Listening Session',mode:session.mode||'mix',status:session.status||'active',currentIndex:Number(session.current_index||0),tracks:session.tracks||[]};
    const ids=sessionTrackIds();if(!ids.length){renderAgentSessionPanel();return false}
    const idx=Math.max(0,Math.min(ids.length-1,st.agentSession.currentIndex));
    st.playlistPlayback={playlistId:'agent-session:'+session.id,playlistName:st.agentSession.title,trackIds:ids,index:idx};
    const t=trackById(ids[idx]);const started=t?await play(t,0,'agent_session',true):false;
    renderAgentSessionPanel();return started;
  }
  function renderAgentSessionPanel(){let panel=$('#agentSessionPanel');if(!agentSessionVisible()){panel?.remove();return}if(!panel){panel=document.createElement('section');panel.id='agentSessionPanel';panel.className='agent-session-panel';document.body.appendChild(panel)}const ids=sessionTrackIds(),i=Math.max(0,Number(st.agentSession.currentIndex||0)),current=trackById(ids[i]),feedback=((st.personalization.track_feedback||{})[current?.id||'']||{}).sentiment||'';panel.innerHTML=`<div><span class="agent-session-kicker">AGENT LISTENING</span><strong>${esc(st.agentSession.title)}</strong><small>${st.agentSession.status==='completed'?'Complete · ':''}${i+1} of ${ids.length}${current?' · '+esc(current.title):''}</small></div><div class="agent-session-actions">${st.agentSession.status==='active'? `<button type="button" data-session-feedback="like" class="${feedback==='like'?'active':''}">♥ Like</button><button type="button" data-session-feedback="dislike" class="${feedback==='dislike'?'active':''}">× Dislike</button>`:''}<button type="button" id="saveAgentListeningSession">Save playlist</button><button type="button" id="endAgentListeningSession">${st.agentSession.status==='completed'?'Close':'End'}</button></div>`;Array.from(panel.querySelectorAll('[data-session-feedback]')).forEach(b=>b.onclick=()=>submitSessionFeedback(b.dataset.sessionFeedback));$('#saveAgentListeningSession',panel).onclick=saveCurrentAgentSession;$('#endAgentListeningSession',panel).onclick=st.agentSession.status==='completed'?()=>{st.agentSession={id:'',title:'',mode:'',status:'',currentIndex:0,tracks:[]};renderAgentSessionPanel()}:endCurrentAgentSession}
  async function submitSessionFeedback(sentiment){if(!agentSessionActive()||!st.track)return;try{const j=await secureJsonPost('listening-sessions.php',{action:'feedback',session_id:st.agentSession.id,track_id:st.track.id,sentiment});st.agentSession={...st.agentSession,...(j.session||{})};await loadPersonalization(true);renderAgentSessionPanel();say(sentiment==='like'?'Got it. I’ll lean more in this direction.':'Got it. I’ll steer away from this direction.')}catch(e){say(e.message)}}
  async function saveCurrentAgentSession(){if(!agentSessionVisible())return;const name=prompt('Save this listening session as a playlist',st.agentSession.title||'Agent Listening Session');if(name===null)return;try{const j=await secureJsonPost('listening-sessions.php',{action:'save_playlist',session_id:st.agentSession.id,name});st.playlists={loaded:true,items:j.playlists||[]};say('Saved this listening session as a playlist.',()=>navigate('playlist',{id:j.playlist.id}))}catch(e){say(e.message)}}
  async function endCurrentAgentSession(){if(!agentSessionActive())return;try{const j=await secureJsonPost('listening-sessions.php',{action:'end',session_id:st.agentSession.id});st.agentSession={...st.agentSession,...(j.session||{}),status:'ended'};clearPlaylistPlayback();renderAgentSessionPanel();say('Listening session ended.')}catch(e){say(e.message)}}
  async function advanceAgentSession(result='complete',expectedToken=null){if(!agentSessionActive()||!st.track)return null;try{const j=await secureJsonPost('listening-sessions.php',{action:'advance',session_id:st.agentSession.id,track_id:st.track.id,result});if(expectedToken!==null&&!playbackLifecycle.isCurrent(expectedToken))return null;st.agentSession={...st.agentSession,...(j.session||{}),currentIndex:Number(j.session?.current_index||0),tracks:j.session?.tracks||st.agentSession.tracks};return j.session||null}catch(_){return null}}
  async function startTemporaryPlaylist(action){
    if(agentSessionActive())return 'End the active Agent listening session before starting a random playlist.';
    const ids=Array.isArray(action?.track_ids)?action.track_ids:[];
    if(!ids.length||ids.length>20||ids.some(id=>!trackById(id)?.audio)||new Set(ids).size!==ids.length)
      return 'The temporary playlist contains an unavailable or repeated song. I have not changed playback.';
    const name=String(action.name||'Stonefellow Random Mix').slice(0,120);
    st.playlistPlayback={playlistId:'temporary:'+Date.now(),playlistName:name,trackIds:ids.slice(),index:0,temporary:true};
    updateQueueUi();
    const started=await play(trackById(ids[0]),0,'temporary_playlist',true);
    await openQueue();
    return started?'Created a temporary '+ids.length+'-song playlist and started “'+st.track.title+'.” The song queue is open.':'Created a temporary '+ids.length+'-song playlist and opened the queue. Tap Play to start the music.';
  }
  function directRandomPlaylistCommand(message){
    const q=String(message||'').trim().toLowerCase()
      .replace(/^(?:(?:hey|hi|okay|ok)\s+)?stone[\s-]?fellow[,.:!?\s]*/,'')
      .replace(/[.!?]+$/,'').trim();
    return /^(?:please\s+)?(?:(?:make|create|build|generate|start|play|give|put together)(?:\s+me)?\s+(?:(?:a|an|the)\s+)?(?:(?:random|surprise|new)\s+)?(?:(?:stonefellow)\s+)?(?:playlist|mix)|surprise me with (?:a |some )?(?:playlist|mix))(?:\s+of\s+(?:stonefellow\s+)?(?:songs|music|tracks))?$/.test(q);
  }
  async function startRandomTemporaryPlaylist(){
    const seen=new Set(),available=catalog.filter(track=>{
      if(!track?.audio||!track.id||seen.has(track.id)||track.agent_discoverable===false)return false;
      seen.add(track.id);return true;
    });
    if(!available.length)return 'No playable Stonefellow songs are currently available to create a playlist.';
    for(let i=available.length-1;i>0;i--){
      const j=Math.floor(Math.random()*(i+1));[available[i],available[j]]=[available[j],available[i]];
    }
    return startTemporaryPlaylist({name:'Stonefellow Random Mix',track_ids:available.slice(0,Math.min(8,available.length)).map(track=>track.id)});
  }
  async function saveTemporaryPlaylist(){
    if(!isTemporaryPlaylist())return 'Create a temporary playlist first. Then I can save that mix.';
    const ids=st.playlistPlayback.trackIds.slice(),name=st.playlistPlayback.playlistName||'Stonefellow Mix',signature=currentMixSignature();
    if(currentMixSaved())return '“'+name+'” is already saved. Open the playlist drawer to listen again.';
    if(st.auth.authenticated){
      try{
        const result=await secureJsonPost('playlists.php',{action:'create',name,description:'Made with the Stonefellow Agent.',visibility:'private',source_type:'agent',track_ids:ids});
        if(!result.playlist?.id)return 'The account did not confirm saving this playlist.';
        st.playlists={loaded:true,items:result.playlists||[]};lastSavedMixSignature=signature;
      }catch(error){return 'The playlist could not be saved to your account: '+error.message}
    }else{
      const record={id:'guest-'+Date.now()+'-'+Math.random().toString(36).slice(2,8),name,track_ids:ids,created_at:new Date().toISOString(),scope:'guest_local'};
      const next=guestSavedPlaylists.concat(record).slice(-20);
      try{localStorage.setItem(guestSavedPlaylistsKey,JSON.stringify(next))}catch(_){return 'Browser storage is unavailable, so the playlist was not saved.'}
      guestSavedPlaylists=next;
    }
    updateQueueUi();
    return 'Saved “'+name+'”. '+(st.auth.authenticated?'It is in your account playlists.':'It is available in this browser under Saved on this device.')+' Open the playlist drawer anytime.';
  }
  async function loadGuestSavedPlaylist(id){
    const saved=guestSavedPlaylists.find(p=>p.id===id);
    if(!saved)return;
    const ids=saved.track_ids.filter(trackId=>trackById(trackId)?.audio);
    if(!ids.length){popupEntry('notice','The songs in that saved mix are not currently available.');return;}
    st.playlistPlayback={playlistId:'temporary:guest:'+saved.id,playlistName:saved.name||'Saved Mix',trackIds:ids,index:0,temporary:true};
    updateQueueUi();
    const started=await play(trackById(ids[0]),0,'guest_saved_playlist',true);
    popupEntry(started?'assistant':'notice',started?'Loaded your saved mix and started “'+st.track.title+'”.':'Loaded the saved mix. Choose a song to play.');
  }
  async function playWelcomeLatest(){
    const selected=trackById(welcomeLatestTrackId);
    if(!selected?.audio)return playLatestRelease();
    if(st.track?.id===selected.id&&!audio.paused)return '“'+selected.title+'” is already playing.';
    if(st.track?.id===selected.id){
      try{await audio.play();return 'Playing “'+selected.title+'”.'}catch(_){return 'I could not resume “'+selected.title+'”.'}
    }
    const started=await play(selected,0,'welcome_latest_release',true);
    return started?'Playing “'+selected.title+'”.':'I could not start “'+selected.title+'”.';
  }
  async function playLatestRelease(){
    await refreshSiteData();
    const published=releases.filter(release=>(release.state||'published')==='published'&&release.public_visible!==false&&release.agent_discoverable!==false)
      .sort((a,b)=>String(b.release_date||'').localeCompare(String(a.release_date||'')));
    const latest=published.find(release=>(release.track_ids||[]).some(id=>trackById(id)?.audio));
    if(!latest)return 'There isn’t a playable published release right now. I can make you a random playlist instead.';
    const tracks=(latest.track_ids||[]).map(trackById).filter(track=>track?.audio&&track.agent_discoverable!==false);
    if(!tracks.length)return 'That release has no playable songs yet.';
    st.playlistPlayback={playlistId:'release:'+latest.id,playlistName:latest.title,trackIds:tracks.map(track=>track.id),index:0};
    updateQueueUi();
    const started=await play(tracks[0],0,'welcome_latest_release',true);
    if(!started){clearPlaylistPlayback();return 'The latest release was found, but its audio could not start. Try the playlist button.'}
    welcomeLatestTrackId=tracks[0].id;syncWelcomeActions();
    return 'Now playing “'+tracks[0].title+'” from the latest release, “'+latest.title+'”. Enjoy!';
  }
  async function handleWelcomeAction(action,recordUser=true){
    if(!['latest-release','welcome-play','random-playlist','save-playlist','open-saved-queue'].includes(action))return;
    if(recordUser)popupEntry('user',({'latest-release':'Play the latest release','welcome-play':'Play','random-playlist':'Make me a playlist','save-playlist':'Save playlist','open-saved-queue':'Show my saved playlist'})[action]);
    let result='';
    if(action==='latest-release')result=await playLatestRelease();
    else if(action==='welcome-play')result=await playWelcomeLatest();
    else if(action==='random-playlist')result=await startRandomTemporaryPlaylist();
    else if(action==='save-playlist')result=await saveTemporaryPlaylist();
    else{await openQueue();result='Your playlist is open. Choose a track to play.'}
    await say(result);
  }
  function playTemporaryTrack(index){
    if(!isTemporaryPlaylist()||!Number.isInteger(index)||index<0||index>=st.playlistPlayback.trackIds.length)return;
    const track=trackById(st.playlistPlayback.trackIds[index]);if(!track?.audio)return;
    st.playlistPlayback.index=index;updateQueueUi();void play(track,0,'temporary_playlist',true);
  }
  function clearPlaylistPlayback(){st.playlistPlayback={playlistId:null,playlistName:'',trackIds:[],index:-1};updateQueueUi()}
  function playPlaylist(p,startIndex=0){const ids=(p?.tracks||[]).map(x=>x.id).filter(id=>trackById(id));if(!ids.length){say('That playlist is empty.');return}const idx=Math.max(0,Math.min(ids.length-1,Number(startIndex)||0));st.playlistPlayback={playlistId:Number(p.id)||null,playlistName:p.name||'Playlist',trackIds:ids,index:idx};updateQueueUi();const t=trackById(ids[idx]);if(t)play(t,0,'playlist',true)}
  function playlistShareUrl(p){const u=new URL(location.href);u.search='';u.searchParams.set('view','playlist');u.searchParams.set('id',String(p.id));return u.toString()}
  async function copyPlaylistLink(p){const url=playlistShareUrl(p);try{await navigator.clipboard.writeText(url);say('Public playlist link copied.')}catch(_){window.prompt('Copy this public playlist link:',url)}}
  async function createPlaylistPrompt(){if(!st.auth.authenticated){navigate('login');return}const name=prompt('Playlist name','My Stonefellow Playlist');if(!name)return;try{const j=await secureJsonPost('playlists.php',{action:'create',name,description:'',visibility:'private',source_type:'user',track_ids:[]});st.playlists={loaded:true,items:j.playlists||[]};navigate('playlist',{id:j.playlist.id})}catch(e){say(e.message)}}
  async function saveAgentSessionPrompt(){const name=prompt('Name this saved agent session','Agent Listening Session');if(!name)return;try{const j=await secureJsonPost('playlists.php',{action:'save_agent_session',name});st.playlists={loaded:true,items:j.playlists||[]};navigate('playlist',{id:j.playlist.id})}catch(e){say(e.message)}}
  async function renderPlaylist(id){
    starters.hidden=true;id=Number(id)||0;if(!id){navigate('account');return}await loadAuth();let p=null,owner=false;
    if(st.auth.authenticated){try{const j=await api('playlists.php?id='+id);p=j.playlist;owner=!!p;if(j.csrf)st.auth.csrf=j.csrf}catch(_){}}
    if(!p){try{const r=await fetch((cfg.apiBase||'api')+'/playlists.php?public_id='+encodeURIComponent(id),{cache:'no-store',credentials:'same-origin'}),j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||'Playlist not found.');p=j.playlist}catch(e){canvas.innerHTML=`<article class="module"><div class="module-kicker">PLAYLIST</div><h2>Unavailable</h2><p class="player-note">${esc(e.message)}</p></article>`;return}}
    const rows=p.tracks||[],duration=rows.reduce((n,x)=>n+Number(x.duration||0),0),share=p.visibility==='public'?`<button class="text-control" id="copyPlaylistLink" type="button">Copy public link</button>`:'';
    canvas.innerHTML=`<article class="module playlist-module"><div class="module-kicker">${owner?'MY PLAYLIST':'PUBLIC PLAYLIST'}</div><div class="title-actions"><div><h2>${esc(p.name)}</h2><p class="player-note">${esc(p.description||'')}</p></div><div class="player-controls"><button class="buy-primary" id="playPlaylist" type="button" ${rows.length?'':'disabled'}>Play all</button>${share}</div></div><div class="playlist-summary"><span>${rows.length} track${rows.length===1?'':'s'}</span><span>${time(duration)}</span><span>${esc((p.visibility||'private').toUpperCase())}</span><span>${esc((p.source_type||'user').replaceAll('_',' '))}</span></div>${owner?`<form id="playlistMetaForm" class="auth-form playlist-meta"><label>Name<input name="name" maxlength="120" required value="${esc(p.name)}"></label><label>Description<textarea name="description" maxlength="1000">${esc(p.description||'')}</textarea></label><label>Visibility<select name="visibility"><option value="private" ${p.visibility==='private'?'selected':''}>Private</option><option value="public" ${p.visibility==='public'?'selected':''}>Public</option></select></label><div class="auth-actions"><button class="text-control" type="submit">Save details</button><button class="text-control danger-text" id="deletePlaylist" type="button">Delete playlist</button></div></form><div class="playlist-add"><select id="playlistTrackSelect"><option value="">Add a track…</option>${catalog.map(t=>`<option value="${esc(t.id)}">${esc(t.title)} — ${esc(t.release||'')}</option>`).join('')}</select><button class="text-control" id="addPlaylistTrack" type="button">Add track</button></div>`:''}<div class="playlist-track-list">${rows.length?rows.map((t,i)=>`<div class="playlist-track-row"><button class="catalog-play" type="button" data-play-playlist-index="${i}" aria-label="Play ${esc(t.title)}">▶</button><button class="catalog-title-button" type="button" data-track-view="${esc(t.id)}"><strong>${esc(t.title)}</strong><small>${esc(t.release||'')} · ${time(t.duration)}</small></button>${owner?`<div class="playlist-row-actions"><button class="text-control compact" type="button" data-move-item="${t.item_id}" data-move-dir="-1" ${i===0?'disabled':''}>↑</button><button class="text-control compact" type="button" data-move-item="${t.item_id}" data-move-dir="1" ${i===rows.length-1?'disabled':''}>↓</button><button class="text-control compact" type="button" data-remove-playlist-item="${t.item_id}">Remove</button></div>`:''}<span class="duration">${time(t.duration)}</span></div>`).join(''):'<p class="player-note">This playlist is empty.</p>'}</div>${owner?'<div class="player-controls"><button class="text-control" data-view="account" type="button">Back to My Stonefellow</button></div>':''}</article>`;
    $('#playPlaylist',canvas).onclick=()=>playPlaylist(p,0);if($('#copyPlaylistLink',canvas))$('#copyPlaylistLink',canvas).onclick=()=>copyPlaylistLink(p);$$('[data-play-playlist-index]',canvas).forEach(b=>b.onclick=()=>playPlaylist(p,Number(b.dataset.playPlaylistIndex)));$$('[data-track-view]',canvas).forEach(b=>b.onclick=()=>navigate('track',{id:b.dataset.trackView}));bind(canvas);
    if(owner){$('#playlistMetaForm',canvas).onsubmit=async e=>{e.preventDefault();const f=new FormData(e.currentTarget);try{await secureJsonPost('playlists.php',{action:'update',playlist_id:p.id,name:f.get('name'),description:f.get('description'),visibility:f.get('visibility')});st.playlists.loaded=false;renderPlaylist(p.id)}catch(x){say(x.message)}};$('#deletePlaylist',canvas).onclick=async()=>{if(!confirm('Delete this playlist?'))return;try{await secureJsonPost('playlists.php',{action:'delete',playlist_id:p.id});st.playlists.loaded=false;say('Playlist deleted.',()=>navigate('account'))}catch(x){say(x.message)}};$('#addPlaylistTrack',canvas).onclick=async()=>{const trackId=$('#playlistTrackSelect',canvas).value;if(!trackId)return;try{await secureJsonPost('playlists.php',{action:'add_track',playlist_id:p.id,track_id:trackId});st.playlists.loaded=false;renderPlaylist(p.id)}catch(x){say(x.message)}};$$('[data-remove-playlist-item]',canvas).forEach(b=>b.onclick=async()=>{try{await secureJsonPost('playlists.php',{action:'remove_item',playlist_id:p.id,item_id:Number(b.dataset.removePlaylistItem)});st.playlists.loaded=false;renderPlaylist(p.id)}catch(x){say(x.message)}});$$('[data-move-item]',canvas).forEach(b=>b.onclick=async()=>{const itemId=Number(b.dataset.moveItem),dir=Number(b.dataset.moveDir),ids=rows.map(x=>Number(x.item_id)),i=ids.indexOf(itemId),j=i+dir;if(i<0||j<0||j>=ids.length)return;[ids[i],ids[j]]=[ids[j],ids[i]];try{await secureJsonPost('playlists.php',{action:'reorder',playlist_id:p.id,item_ids:ids});st.playlists.loaded=false;renderPlaylist(p.id)}catch(x){say(x.message)}})}
  }
  function ensureListenSession(){if(!st.listenSession){st.listenSession='ls_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,10);localStorage.setItem('stonefellow.listen.session',st.listenSession)}return st.listenSession}
  function recordListen(eventType){if(globalThis.SFListeningTelemetry?.hasMain?.()||!st.track)return;const payload={session_key:ensureListenSession(),track_id:String(st.track.id||''),event_type:eventType,position_seconds:Math.round(audio.currentTime||0),duration_seconds:Math.round(audio.duration||st.track.duration||0),source:st.playSource||'player'};fetch((cfg.apiBase||'api')+'/listening.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).catch(()=>{})}
  function listeningTelemetryMain(){
    if(listeningTelemetryMain.current||!window.SFListeningTelemetry?.create)return listeningTelemetryMain.current;
    window.SFListeningTelemetry.create({
      getOwner:canvasOwner,
      readProtocol:signal=>api('listening.php?protocol=2',undefined,signal),
      post:async(payload,csrf,signal)=>{
        const response=await fetch((cfg.apiBase||'api')+'/listening.php',{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
          headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload),signal});
        const result=await response.json();if(!response.ok||result.ok!==true){const error=new Error('Listening report unavailable.');error.status=response.status;error.payload=result;throw error;}return result;
      },
    });
    listeningTelemetryMain.current=window.SFListeningTelemetry.observeMain(audio,{getTrack:()=>st.track,getContext:()=>({app:true}),method:'main',
      isRecovering:()=>!!pendingNetworkRecovery,canComplete:()=>!playbackEndedEarly()});
    return listeningTelemetryMain.current;
  }
  function fmtWhen(v){if(!v)return'';const d=new Date(v);return Number.isNaN(d.getTime())?String(v):d.toLocaleString()}
  function drawerItem(title,meta,body=''){return `<article class="drawer-item"><strong>${esc(title)}</strong>${body?`<p>${esc(body)}</p>`:''}<small>${esc(meta)}</small></article>`}
  function notificationTrack(n){
    const context=n?.action_context;
    return context?.type==='track'?trackById(String(context.track_id||'')):null;
  }
  function notificationActionMarkup(n){
    const id=Number(n.id)||0,kind=String(n.kind||'service'),track=notificationTrack(n);
    let html='<div class="notification-context-actions" aria-label="Notification options">';
    if(track){
      html+='<button type="button" class="notification-primary-action" data-notification-play="'+id+'">▶ '+(kind==='listening'?'Resume':'Play track')+'</button>';
      html+='<button type="button" data-notification-queue="'+id+'">+ Up Next</button>';
      const playlists=st.playlists?.items||[];
      if(playlists.length){
        html+='<select data-notification-playlist="'+id+'" aria-label="Add '+esc(track.title)+' to playlist"><option value="">+ Playlist…</option>';
        for(const p of playlists)html+='<option value="'+(Number(p.id)||0)+'">'+esc(p.name||'Playlist')+'</option>';
        html+='</select>';
      }else html+='<button type="button" data-notification-new-playlist="'+id+'">+ New playlist</button>';
    }else if(n.link_url){
      const label={release:'Explore release',build:'My builds',community:'Account details',vinyl_production:'Order status',membership:'Membership'}[kind]||'Open details';
      html+='<button type="button" class="notification-primary-action" data-notification-open="'+id+'">'+label+'</button>';
    }else if(kind==='build'||kind==='community'||kind==='membership'){
      html+='<button type="button" class="notification-primary-action" data-notification-account="'+id+'">'+(kind==='build'?'My builds':'My account')+'</button>';
    }
    html+='<button type="button" class="notification-chat-action" data-notification-chat="'+id+'">Ask Agent</button>';
    return html+'</div>';
  }
  function notificationById(id){return (st.drawer.data?.notifications||[]).find(n=>Number(n.id)===Number(id))||null}
  function notificationFeedback(id,message){
    const card=drawerContent?.querySelector('[data-notification-id="'+Number(id)+'"]');
    if(!card)return;
    let label=card.querySelector('.notification-action-status');
    if(!label){label=document.createElement('p');label.className='notification-action-status';label.setAttribute('role','status');card.querySelector('.notification-card-main')?.append(label)}
    label.textContent=String(message);
  }
  async function recordNotificationInteraction(id){
    try{
      const result=await secureJsonPost('notifications.php',{action:'click',id:Number(id)});
      updateNotificationBadge(result.unread||0);
      const row=notificationById(id);if(row)row.is_read=1;
      drawerContent?.querySelector('[data-notification-id="'+Number(id)+'"]')?.classList.remove('unread');
    }catch(_){}
  }
  async function notificationPlay(id){
    const n=notificationById(id),t=notificationTrack(n);if(!n||!t)return;
    if(agentSessionActive()){notificationFeedback(id,'End the active Agent listening session before switching tracks.');return}
    // Invoke audio.play from the original tap before awaiting a network request:
    // iPhone Safari requires a direct user gesture for playback.
    const task=play(t,Math.max(0,Number(n.action_context.position_seconds)||0),'notification');
    const started=await task;
    if(!started){notificationFeedback(id,'Playback was blocked. Tap Play again to retry.');return}
    closeNotificationDrawer();void recordNotificationInteraction(id);
  }
  async function notificationQueue(id){
    const t=notificationTrack(notificationById(id));if(!t)return;
    try{
      const j=await secureJsonPost('queue.php',{action:'add',track_id:t.id,placement:'end'});
      applyQueue(j.queue);notificationFeedback(id,'Added “'+t.title+'” to Up Next.');
      void recordNotificationInteraction(id);
    }catch(e){notificationFeedback(id,e.message||'Could not add that track to the queue.')}
  }
  async function notificationPlaylist(id,playlistId){
    const t=notificationTrack(notificationById(id));if(!t)return;
    try{
      const j=playlistId>0
        ?await secureJsonPost('playlists.php',{action:'add_track',playlist_id:playlistId,track_id:t.id})
        :await secureJsonPost('playlists.php',{action:'create',name:'Activity Picks',description:'Tracks saved from Stonefellow Activity',visibility:'private',track_ids:[t.id]});
      st.playlists={loaded:true,items:j.playlists||[]};
      notificationFeedback(id,'Saved “'+t.title+'” in '+(j.playlist?.name||'your playlist')+'.');
      void recordNotificationInteraction(id);
      // Preserve the feedback; refresh playlist controls only on the next open.
    }catch(e){notificationFeedback(id,e.message||'Could not save the track to that playlist.')}
  }
  async function notificationChat(id){
    const n=notificationById(id);if(!n)return;
    closeNotificationDrawer();void recordNotificationInteraction(id);
    // Open and focus in the original tap turn so mobile Safari can raise the
    // keyboard. Loading prior chat history may finish asynchronously later.
    const opening=openChatPopup();
    if(input){
      const context=(String(n.title||'')+'. '+String(n.body||'')).trim().slice(0,360);
      input.value='Help me with this Stonefellow notification: '+context;
      input.dispatchEvent(new Event('input',{bubbles:true}));
      input.focus();
    }
    await opening;
  }
  function syncDrawerChrome(){
    if(!notificationDrawer)return;
    const mode=st.drawer.mode||'activity';
    if(drawerTitle)drawerTitle.textContent={activity:'Activity',music:'Music Library',commerce:'Merch & Tickets'}[mode]||'Stonefellow';
    $$('[data-drawer-group]',notificationDrawer).forEach(group=>group.hidden=group.dataset.drawerGroup!==mode);
    $$('[data-drawer-tab]',notificationDrawer).forEach(button=>{
      const active=button.dataset.drawerTab===st.drawer.tab;
      button.classList.toggle('active',active);button.setAttribute('aria-selected',String(active));
    });
    if(drawerTools)drawerTools.hidden=mode!=='activity';
    if(drawerCartCount){const count=st.cart.reduce((sum,item)=>sum+(item.type==='merch'?Number(item.quantity||1):1),0);drawerCartCount.textContent=count?'('+count+')':'';}
    notificationDrawer.dataset.drawerMode=mode;
  }
  function drawerDetail(view,params={}){closeNotificationDrawer();void navigate(view,params)}
  function drawerFeedback(message){const feedback=$('[data-drawer-feedback]',drawerContent);if(feedback)feedback.textContent=String(message||'')}
  function renderMusicDrawer(tab){
    const songs=catalog.filter(track=>track.public_visible!==false&&!['draft','private','archived'].includes(String(track.state||'')));
    const albums=releases.filter(release=>(release.state||'published')==='published'&&release.public_visible!==false);
    if(tab==='songs'){
      drawerContent.innerHTML='<div class="sf-drawer-intro"><span>THE CATALOG / '+songs.length+' SONGS</span><p>Play a song here or open its full recording page.</p></div><div data-drawer-feedback class="sf-drawer-feedback" role="status"></div>'+(songs.length?songs.map(track=>'<article class="sf-drawer-entity"><div class="sf-drawer-entity-copy"><strong>'+esc(track.title)+'</strong><small>'+esc(track.release||'Stonefellow')+(Number(track.duration)?' · '+time(track.duration):'')+'</small></div><div class="sf-drawer-entity-actions"><button type="button" data-drawer-play="'+esc(track.id)+'" aria-label="Play '+esc(track.title)+'">▶ Play</button><button type="button" data-drawer-track="'+esc(track.id)+'">Details</button></div></article>').join(''):'<p class="drawer-empty">No public songs available.</p>')+'<button type="button" class="sf-drawer-page-link" data-drawer-page="music">Full music page ↗</button>';
      $$('[data-drawer-play]',drawerContent).forEach(button=>button.onclick=async()=>{const track=trackById(button.dataset.drawerPlay);if(!track)return;try{const started=await play(track,0,'music_drawer');drawerFeedback(started?'Playing “'+track.title+'”.':'The player could not start “'+track.title+'”. Check its published audio.')}catch(error){drawerFeedback('Could not start this song: '+String(error.message||'Try again.'))}});
      $$('[data-drawer-track]',drawerContent).forEach(button=>button.onclick=()=>drawerDetail('track',{id:button.dataset.drawerTrack}));
    }else{
      drawerContent.innerHTML='<div class="sf-drawer-intro"><span>RELEASES / '+albums.length+' ALBUMS & EPS</span><p>Browse published releases and play their tracklists.</p></div><div data-drawer-feedback class="sf-drawer-feedback" role="status"></div>'+(albums.length?albums.map(release=>{const cover=typeof release.artwork==='object'?(release.artwork?.cover||''):typeof release.artwork==='string'?release.artwork:'';const tracks=(release.track_ids||[]).map(trackById).filter(Boolean);return '<article class="sf-drawer-entity album"><div class="sf-drawer-album-art">'+(cover?'<img src="'+esc(cover)+'" alt="">':'SF')+'</div><div class="sf-drawer-entity-copy"><strong>'+esc(release.title)+'</strong><small>'+esc(release.type||'Release')+' · '+tracks.length+' songs</small><div class="sf-drawer-entity-actions"><button type="button" data-drawer-play-album="'+esc(release.id)+'" '+(tracks.length?'':'disabled')+'>▶ Play</button><button type="button" data-drawer-album="'+esc(release.id)+'">Details</button></div></div></article>'}).join(''):'<p class="drawer-empty">No public releases available.</p>')+'<button type="button" class="sf-drawer-page-link" data-drawer-page="releases">Full releases page ↗</button>';
      $$('[data-drawer-play-album]',drawerContent).forEach(button=>button.onclick=async()=>{const release=albums.find(r=>String(r.id)===button.dataset.drawerPlayAlbum),tracks=(release?.track_ids||[]).map(trackById).filter(Boolean);if(!tracks.length)return;st.playlistPlayback={playlistId:'release:'+release.id,playlistName:release.title,trackIds:tracks.map(t=>t.id),index:0};try{const started=await play(tracks[0],0,'release',true);if(!started)clearPlaylistPlayback();drawerFeedback(started?'Playing “'+release.title+'”.':'The player could not start this release. Check its published audio.')}catch(error){clearPlaylistPlayback();drawerFeedback(String(error.message||'Could not play this release.'))}});
      $$('[data-drawer-album]',drawerContent).forEach(button=>button.onclick=()=>drawerDetail('release',{id:button.dataset.drawerAlbum}));
    }
    $$('[data-drawer-page]',drawerContent).forEach(button=>button.onclick=()=>drawerDetail(button.dataset.drawerPage));
  }
  async function renderCommerceDrawer(tab,token){
    if(tab==='merch'){
      drawerContent.innerHTML='<p class="drawer-empty">Loading the merchandise catalog…</p>';
      try{
        const data=await api('storefront.php');if(token!==drawerRenderSequence||notificationDrawer.hidden||st.drawer.tab!=='merch')return;
        st.storefrontProducts=data.products||[];
        const products=st.storefrontProducts.filter(product=>product.kind==='merch');
        drawerContent.innerHTML='<div class="sf-drawer-intro"><span>STONEFELLOW / MERCHANDISE</span><p>Select a size or edition and add it to your cart.</p></div><div data-drawer-feedback class="sf-drawer-feedback" role="status"></div>'+(products.length?products.map(product=>'<article class="sf-drawer-entity merchandise">'+(product.image?'<div class="sf-drawer-album-art"><img src="'+esc(product.image)+'" alt=""></div>':'')+'<div class="sf-drawer-entity-copy"><strong>'+esc(product.label)+'</strong><small>'+esc(product.category||'Merch')+' · '+money(product.price_cents||0)+'</small>'+(product.description?'<p>'+esc(product.description)+'</p>':'')+merchVariantOptions(product)+'<div class="sf-drawer-entity-actions"><button type="button" data-drawer-add-merch="'+esc(product.id)+'" '+(product.available?'':'disabled')+'>'+(product.available?'Add to cart':'Sold out')+'</button><button type="button" data-drawer-merch="'+esc(product.slug||product.id)+'">Details</button></div></div></article>').join(''):'<p class="drawer-empty">No merchandise is currently listed.</p>')+'<button type="button" class="sf-drawer-page-link" data-drawer-page="store">Full merch store ↗</button>';
        $$('[data-drawer-add-merch]',drawerContent).forEach(button=>button.onclick=()=>{const id=button.dataset.drawerAddMerch,selector=$('[data-merch-variant="'+CSS.escape(id)+'"]',drawerContent);addMerchToCart(id,selector?Number(selector.value):null,1)});
        $$('[data-drawer-merch]',drawerContent).forEach(button=>button.onclick=()=>drawerDetail('store',{slug:button.dataset.drawerMerch}));
      }catch(error){if(token===drawerRenderSequence)drawerContent.innerHTML='<p class="drawer-empty">'+esc(error.message||'Merchandise is unavailable right now.')+'</p><button type="button" class="sf-drawer-page-link" data-drawer-page="store">Open store page ↗</button>';}
    }else if(tab==='tickets'){
      drawerContent.innerHTML='<p class="drawer-empty">Loading tickets and VIP access…</p>';
      try{
        const data=await api('ticketing.php');if(token!==drawerRenderSequence||notificationDrawer.hidden||st.drawer.tab!=='tickets')return;
        const offers=data.offers||[],reservations=data.reservations||[];
        drawerContent.innerHTML='<div class="sf-drawer-intro"><span>LIVE / TICKETS & VIP</span><p>View active offers and your existing reservations.</p></div><div data-drawer-feedback class="sf-drawer-feedback" role="status"></div>'+(reservations.length?'<h3 class="sf-drawer-subhead">My reservations</h3>'+reservations.map(ticketReservationCard).join(''):'')+'<h3 class="sf-drawer-subhead">Available offers</h3>'+(offers.length?offers.map(ticketOfferCard).join(''):'<p class="drawer-empty">No ticket or VIP offers are listed right now.</p>')+'<button type="button" class="sf-drawer-page-link" data-drawer-page="tickets">Full tickets page ↗</button>';
        $$('[data-ticket-reserve]',drawerContent).forEach(button=>button.onclick=async()=>{if(!st.auth.authenticated){drawerDetail('login');return}const id=Number(button.dataset.ticketReserve),qty=Number($('[data-ticket-qty="'+id+'"]',drawerContent)?.value||1),request_id='ticket_'+id+'_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,10);button.disabled=true;try{const response=await secureJsonPost('ticketing.php',{action:'reserve',offer_id:id,quantity:qty,request_id,source:'public_ticketing'});await renderCommerceDrawer('tickets',++drawerRenderSequence);drawerFeedback('Reservation confirmed: '+response.reservation.confirmation_code+'.');}catch(error){drawerFeedback(error.message||'Reservation unavailable.')}finally{button.disabled=false}});
        $$('[data-ticket-cancel]',drawerContent).forEach(button=>button.onclick=async()=>{if(!confirm('Cancel this reservation and release its capacity?'))return;try{await secureJsonPost('ticketing.php',{action:'cancel',reservation_id:Number(button.dataset.ticketCancel)});await renderCommerceDrawer('tickets',++drawerRenderSequence);drawerFeedback('Reservation cancelled.')}catch(error){drawerFeedback(error.message)}});
        $$('[data-view]',drawerContent).forEach(button=>button.onclick=()=>drawerDetail(button.dataset.view));
      }catch(error){if(token===drawerRenderSequence)drawerContent.innerHTML='<p class="drawer-empty">'+esc(error.message||'Ticketing unavailable.')+'</p>';}
    }else if(tab==='cart'){
      const count=st.cart.reduce((sum,item)=>sum+(item.type==='merch'?Number(item.quantity||1):1),0);
      drawerContent.innerHTML='<div class="sf-drawer-intro"><span>YOUR CART / '+count+' ITEMS</span><p>Review your selections here. Checkout remains a full-page secure step.</p></div>'+(st.cart.length?'<div class="sf-drawer-cart-lines">'+st.cart.map(cartItemMarkup).join('')+'</div><div id="sfDrawerQuote" class="sf-drawer-quote" role="status">Calculating order total…</div><div class="sf-drawer-cart-actions"><button type="button" data-drawer-checkout>Review & checkout ↗</button><button type="button" data-drawer-clear>Clear cart</button></div>':'<p class="drawer-empty">Your cart is empty. Add merchandise or music to begin.</p><button type="button" class="sf-drawer-page-link" data-drawer-switch="merch">Browse merch ↗</button>');
      $$('[data-remove]',drawerContent).forEach(button=>button.onclick=()=>{st.cart.splice(Number(button.dataset.remove),1);persistCart()});
      $$('[data-merch-cart-qty]',drawerContent).forEach(field=>field.onchange=()=>{const item=st.cart[Number(field.dataset.merchCartQty)];if(item?.type==='merch'){item.quantity=Math.max(1,Math.min(50,Number(field.value)||1));persistCart()}});
      const clear=$('[data-drawer-clear]',drawerContent);if(clear)clear.onclick=()=>{st.cart=[];persistCart()};
      const checkout=$('[data-drawer-checkout]',drawerContent);if(checkout)checkout.onclick=()=>drawerDetail('checkout');
      const browse=$('[data-drawer-switch]',drawerContent);if(browse)browse.onclick=()=>openExperienceDrawer('commerce',browse.dataset.drawerSwitch);
      if(st.cart.length)try{
        const quote=await quoteCart();if(token!==drawerRenderSequence||notificationDrawer.hidden||st.drawer.tab!=='cart')return;
        const discount=Number(quote.member_discount_cents||0)+Number(quote.campaign_discount_cents||0);
        const host=$('#sfDrawerQuote',drawerContent);
        if(host)host.innerHTML='<div><span>Subtotal</span><strong>'+money(quote.subtotal_cents||0)+'</strong></div><div><span>Shipping</span><strong>'+(quote.shipping_cents?money(quote.shipping_cents):'—')+'</strong></div><div><span>Discounts</span><strong>'+(discount?'-'+money(discount):'—')+'</strong></div><div class="sf-drawer-quote-total"><span>Total</span><strong>'+money(quote.total_cents||0)+'</strong></div>';
      }catch(error){if(token===drawerRenderSequence){
        const host=$('#sfDrawerQuote',drawerContent);
        if(host)host.innerHTML='<span>'+esc(error.message||'Order total unavailable.')+'</span><button type="button" class="sf-drawer-page-link" data-drawer-retry-quote>Retry total</button>';
        const retry=$('[data-drawer-retry-quote]',drawerContent);if(retry)retry.onclick=renderDrawer;
        const checkout=$('[data-drawer-checkout]',drawerContent);if(checkout)checkout.disabled=true;
      }}
    }
    $$('[data-drawer-page]',drawerContent).forEach(button=>button.onclick=()=>drawerDetail(button.dataset.drawerPage));
  }
  function renderSiteDrawer(){
    const token=++drawerRenderSequence;
    if(st.drawer.mode==='music')renderMusicDrawer(st.drawer.tab);
    else if(st.drawer.mode==='commerce')void renderCommerceDrawer(st.drawer.tab,token);
  }
  function renderDrawer(){
    if(!drawerContent)return;
    syncDrawerChrome();
    if(st.drawer.mode!=='activity'){renderSiteDrawer();return;}
    if(!st.auth.authenticated){
      drawerContent.innerHTML='<div class="drawer-empty drawer-signin"><strong>Your Stonefellow activity</strong><p>Sign in to see notifications, listening history and Agent Brain updates.</p><button id="drawerSignIn" type="button" class="text-control">Sign in</button></div>';
      $('#drawerSignIn',drawerContent).onclick=()=>{closeNotificationDrawer();navigate('login')};
      return;
    }
    if(!st.drawer.data){drawerContent.innerHTML='<p class="drawer-empty">Loading Activity…</p>';return;}
    const d=st.drawer.data||{},tab=st.drawer.tab;
    $$('[data-drawer-tab]').forEach(b=>b.classList.toggle('active',b.dataset.drawerTab===tab));
    if(tab==='notifications'){
      const rows=d.notifications||[];
      drawerContent.innerHTML=rows.length?rows.map(n=>'<article data-notification-id="'+(Number(n.id)||0)+'" class="drawer-item notification-card '+(Number(n.is_read)?'':'unread')+'"><div class="notification-card-main"><strong>'+esc(n.title)+'</strong>'+(n.body?'<p>'+esc(n.body)+'</p>':'')+'<small>'+esc(String(n.kind||'service').replaceAll('_',' '))+' · '+esc(fmtWhen(n.created_at))+'</small>'+notificationActionMarkup(n)+'</div><div class="notification-card-actions">'+(!Number(n.is_read)?'<button type="button" data-notification-read="'+(Number(n.id)||0)+'">Mark read</button>':'')+'<button type="button" data-notification-dismiss="'+(Number(n.id)||0)+'">Dismiss</button></div></article>').join(''):'<p class="drawer-empty">No notifications yet.</p>';
      Array.from(drawerContent.querySelectorAll('[data-notification-read]')).forEach(b=>b.onclick=()=>markNotification(Number(b.dataset.notificationRead)));
      Array.from(drawerContent.querySelectorAll('[data-notification-open]')).forEach(b=>b.onclick=()=>openNotification(Number(b.dataset.notificationOpen)));
      Array.from(drawerContent.querySelectorAll('[data-notification-dismiss]')).forEach(b=>b.onclick=()=>dismissNotification(Number(b.dataset.notificationDismiss)));
      Array.from(drawerContent.querySelectorAll('[data-notification-play]')).forEach(b=>b.onclick=()=>notificationPlay(Number(b.dataset.notificationPlay)));
      Array.from(drawerContent.querySelectorAll('[data-notification-queue]')).forEach(b=>b.onclick=()=>notificationQueue(Number(b.dataset.notificationQueue)));
      Array.from(drawerContent.querySelectorAll('[data-notification-new-playlist]')).forEach(b=>b.onclick=()=>notificationPlaylist(Number(b.dataset.notificationNewPlaylist),0));
      Array.from(drawerContent.querySelectorAll('[data-notification-playlist]')).forEach(b=>b.onchange=()=>{const playlistId=Number(b.value)||0;if(playlistId){void notificationPlaylist(Number(b.dataset.notificationPlaylist),playlistId);b.value=''}});
      Array.from(drawerContent.querySelectorAll('[data-notification-chat]')).forEach(b=>b.onclick=()=>notificationChat(Number(b.dataset.notificationChat)));
      Array.from(drawerContent.querySelectorAll('[data-notification-account]')).forEach(b=>b.onclick=()=>{const id=Number(b.dataset.notificationAccount);closeNotificationDrawer();void recordNotificationInteraction(id);navigate('account')});
    }else if(tab==='history'){const rows=d.history||[],listens=d.listening_preview||[];drawerContent.innerHTML=(listens.length?`<div class="drawer-section-title">Recent listening</div>${listens.map(r=>drawerItem(r.title,(r.event_type||'listen')+' · '+fmtWhen(r.created_at),r.release||'')).join('')}<button class="drawer-history-link" type="button" id="drawerOpenHistory">Open full listening history</button>`:'')+(rows.length?`<div class="drawer-section-title">Account activity</div>${rows.map(r=>drawerItem(r.title,(r.event_type||'activity')+' · '+fmtWhen(r.created_at),r.entity_type?`${r.entity_type}${r.entity_id?' · '+r.entity_id:''}`:'' )).join('')}`:'')||'<p class="drawer-empty">Your Stonefellow history will appear here.</p>';const open=$('#drawerOpenHistory',drawerContent);if(open)open.onclick=()=>{closeNotificationDrawer();navigate('history')}}else{const rows=d.brain||[];drawerContent.innerHTML=rows.length?rows.map(r=>`<article class="drawer-item brain"><strong>${esc(r.route||r.phase||'Agent decision')}</strong><p>${esc(r.request_excerpt||r.response_excerpt||'')}</p><small>${esc(r.phase||'')} · ${esc(r.action||'none')} · ${esc(fmtWhen(r.created_at))}</small></article>`).join(''):'<p class="drawer-empty">No Agent Brain events yet.</p>'}}
  function updateNotificationBadge(count){if(!notificationBadge)return;const n=Math.max(0,Number(count)||0);notificationBadge.textContent=n>99?'99+':String(n);notificationBadge.hidden=n===0;if(drawerUnreadCount)drawerUnreadCount.textContent=n?`(${n})`:''}
  async function loadNotificationDrawer(){if(!st.auth.authenticated||!notificationDrawer)return;try{const [r]=await Promise.all([fetch((cfg.apiBase||'api')+'/notifications.php',{cache:'no-store',credentials:'same-origin'}),loadPlaylists(true)]),j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||'Could not load activity.');st.drawer.data=j;if(j.csrf)st.auth.csrf=j.csrf;updateNotificationBadge(j.unread||0);if(st.drawer.mode==='activity')renderDrawer()}catch(e){if(st.drawer.mode==='activity'&&drawerContent)drawerContent.innerHTML=`<p class="drawer-empty">${esc(e.message)}</p>`}}
  function openExperienceDrawer(mode='activity',tab=''){
    if(!notificationDrawer)return;
    // A purchase can originate inside a full-screen product detail modal.
    // Return to the persistent Agent Canvas before opening its cart drawer.
    if(canvas?.id==='sfAgentNativePage'&&window.SFPublicAgentCanvas?.active?.()){
      window.SFPublicAgentCanvas.closePage?.(true);canvas=mainCanvas;setUrl('home',{});
    }
    if(notificationDrawer.hidden)drawerReturnFocus=document.activeElement;
    const defaults={activity:'notifications',music:'songs',commerce:'merch'};
    st.drawer.mode=Object.hasOwn(defaults,mode)?mode:'activity';
    st.drawer.tab=tab||defaults[st.drawer.mode];
    if(st.queue.open)closeQueue();
    notificationDrawer.hidden=false;if(notificationBackdrop)notificationBackdrop.hidden=false;
    document.body.classList.add('drawer-open');notificationButton?.setAttribute('aria-expanded',String(st.drawer.mode==='activity'));
    renderDrawer();if(st.drawer.mode==='activity'&&st.auth.authenticated)void loadNotificationDrawer();
    notificationClose?.focus({preventScroll:true});
  }
  function openNotificationDrawer(){openExperienceDrawer('activity','notifications')}
  function closeNotificationDrawer(){if(!notificationDrawer)return;drawerRenderSequence++;notificationDrawer.hidden=true;if(notificationBackdrop)notificationBackdrop.hidden=true;document.body.classList.remove('drawer-open');notificationButton?.setAttribute('aria-expanded','false');if(drawerReturnFocus?.isConnected)drawerReturnFocus.focus({preventScroll:true});drawerReturnFocus=null}
  async function markNotification(id){try{const j=await secureJsonPost('notifications.php',{action:'read',id});updateNotificationBadge(j.unread||0);await loadNotificationDrawer()}catch(_){}}
  async function openNotification(id){try{const j=await secureJsonPost('notifications.php',{action:'click',id});updateNotificationBadge(j.unread||0);closeNotificationDrawer();if(j.link_url)location.href=j.link_url}catch(e){say(e.message)}}
  async function dismissNotification(id){try{const j=await secureJsonPost('notifications.php',{action:'dismiss',id});updateNotificationBadge(j.unread||0);await loadNotificationDrawer()}catch(e){say(e.message)}}
  async function markAllNotifications(){try{await secureJsonPost('notifications.php',{action:'read_all'});updateNotificationBadge(0);await loadNotificationDrawer()}catch(_){}}
  async function loadListeningHistory(reset=true){
    if(!st.auth.authenticated){navigate('login');return null}
    const before=reset?0:Number(st.historyPage.nextBefore||0),path='listening-history.php?limit=80'+(before?'&before_id='+before:'');
    const j=await api(path),h=j.history||{};if(j.csrf)st.auth.csrf=j.csrf||st.auth.csrf;
    st.historyPage={rows:reset?(h.rows||[]):[...st.historyPage.rows,...(h.rows||[])],sessions:reset?(h.sessions||[]):[...st.historyPage.sessions,...(h.sessions||[])],summary:h.summary||st.historyPage.summary,nextBefore:h.next_before_id||null,loaded:true};return st.historyPage;
  }
  function historySessionLabel(key){return key?String(key).slice(-8).toUpperCase():'—'}
  async function renderListeningHistory(reset=true){
    await loadAuth();if(!st.auth.authenticated){renderLogin();return}starters.hidden=true;try{await loadListeningHistory(reset);const h=st.historyPage,summary=h.summary||{},rows=h.rows||[];const sessions=new Map();rows.forEach(r=>{const k=r.session_key||'unknown';if(!sessions.has(k))sessions.set(k,[]);sessions.get(k).push(r)});canvas.innerHTML=`<article class="module listening-history-module"><div class="module-kicker">MY STONEFELLOW</div><div class="title-actions"><div><h2>Listening History</h2><p class="player-note">Your personal listening trail. Clearing history removes your account association while aggregate anonymous listening totals remain.</p></div><div class="player-controls"><button class="text-control" data-view="account" type="button">My account</button><button class="text-control danger-text" id="historyClearAll" type="button">Clear all</button></div></div><div class="token-panel history-summary"><div class="token-stat"><strong>${Number(summary.sessions||0).toLocaleString()}</strong><span>Sessions</span></div><div class="token-stat"><strong>${Number(summary.tracks||0).toLocaleString()}</strong><span>Tracks</span></div><div class="token-stat"><strong>${Number(summary.starts||0).toLocaleString()}</strong><span>Starts</span></div><div class="token-stat"><strong>${Number(summary.completes||0).toLocaleString()}</strong><span>Completed</span></div></div><div class="history-toolbar"><input id="historySearch" type="search" placeholder="Search track or release…"><select id="historyEventFilter"><option value="all">All activity</option><option value="start">Started</option><option value="resume">Resumed</option><option value="pause">Paused</option><option value="complete">Completed</option></select></div><div id="historySessions">${[...sessions.entries()].map(([key,items])=>renderHistorySession(key,items)).join('')||'<p class="player-note">Your listening history is empty.</p>'}</div>${h.nextBefore?'<div class="player-controls"><button class="text-control" id="historyLoadMore" type="button">Load earlier listening</button></div>':''}</article>`;bind(canvas);bindHistoryPage();}catch(e){say(e.message,()=>navigate('account'))}
  }
  function renderHistorySession(key,items){const newest=items[0]||{},source=[...new Set(items.map(x=>x.source||'player'))].join(', ');return `<section class="history-session" data-history-session><div class="history-session-head"><div><strong>${esc(fmtWhen(newest.created_at))}</strong><small>Session ${esc(historySessionLabel(key))} · ${esc(source)}</small></div><span>${items.length} event${items.length===1?'':'s'}</span></div>${items.map(r=>`<div class="history-event" data-history-text="${esc(norm((r.title||'')+' '+(r.release||'')))}" data-history-event="${esc(r.event_type||'')}"><button class="catalog-play" type="button" data-history-play="${esc(r.track_id)}" data-history-position="${Number(r.can_resume?r.resume_position:0)}" aria-label="${r.can_resume?'Resume':'Play'} ${esc(r.title)}">▶</button><div class="history-event-main"><strong>${esc(r.title)}</strong><small>${esc(r.release||'')} · ${esc(r.event_type)} · ${time(r.position_seconds)}${r.can_resume?' · resume at '+time(r.resume_position):''}</small></div><time>${esc(fmtWhen(r.created_at))}</time><button class="text-control compact" type="button" data-history-clear-track="${esc(r.track_id)}">Remove track history</button></div>`).join('')}</section>`}
  function bindHistoryPage(){const apply=()=>{const q=norm($('#historySearch')?.value||''),event=$('#historyEventFilter')?.value||'all';$$('.history-event',canvas).forEach(r=>{const show=(!q||String(r.dataset.historyText||'').includes(q))&&(event==='all'||r.dataset.historyEvent===event);r.hidden=!show});$$('[data-history-session]',canvas).forEach(s=>{s.hidden=!$$('.history-event',s).some(r=>!r.hidden)})};$('#historySearch',canvas).oninput=apply;$('#historyEventFilter',canvas).onchange=apply;$$('[data-history-play]',canvas).forEach(b=>b.onclick=()=>{const t=trackById(b.dataset.historyPlay);if(t)play(t,Number(b.dataset.historyPosition)||0)});$$('[data-history-clear-track]',canvas).forEach(b=>b.onclick=()=>clearTrackListeningHistory(b.dataset.historyClearTrack));$('#historyClearAll',canvas).onclick=clearAllListeningHistory;const more=$('#historyLoadMore',canvas);if(more)more.onclick=()=>renderListeningHistory(false)}
  async function clearAllListeningHistory(){if(!confirm('Clear all personal listening history and Continue Listening positions? Aggregate anonymous listening totals will remain.'))return;try{const j=await secureJsonPost('listening-history.php',{action:'clear_all'});st.personalization.loaded=false;st.historyPage={rows:[],sessions:[],summary:j.history?.summary||null,nextBefore:null,loaded:true};await loadNotificationDrawer();renderListeningHistory(true)}catch(e){say(e.message)}}
  async function clearTrackListeningHistory(trackId){const t=trackById(trackId);if(!confirm('Remove '+(t?'“'+t.title+'”':'this track')+' from your personal listening history?'))return;try{await secureJsonPost('listening-history.php',{action:'clear_track',track_id:trackId});st.personalization.loaded=false;await loadNotificationDrawer();renderListeningHistory(true)}catch(e){say(e.message)}}
  function renderLogin(){
    starters.hidden=true;
    canvas.innerHTML=`<article class="module auth-module"><div class="module-kicker">WELCOME BACK</div><h2>Login</h2><p class="player-note">Sign in without interrupting the music player.</p><form id="loginForm" class="auth-form"><label>Email<input name="email" type="email" autocomplete="email" required maxlength="190"></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><p id="authError" class="auth-error" role="alert"></p><div class="auth-actions"><button class="buy-primary" type="submit">Login</button><button class="text-control" type="button" data-view="register">Create account</button><button class="text-control" type="button" data-view="forgot-password">Forgot password?</button></div></form></article>`;
    bind(canvas);
    $('#loginForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.currentTarget),er=$('#authError');er.textContent='';try{await authPost({action:'login',email:f.get('email'),password:f.get('password')});finishAccountEntry()}catch(x){er.textContent=x.message}};
  }
  function renderForgotPassword(){starters.hidden=true;canvas.innerHTML=`<article class="module auth-module"><div class="module-kicker">ACCOUNT RECOVERY</div><h2>Reset your password</h2><p class="player-note">Enter your account email. For privacy, Stonefellow always returns the same confirmation whether or not an account exists.</p><form id="forgotForm" class="auth-form"><label>Email<input name="email" type="email" autocomplete="email" required maxlength="190"></label><p id="authError" class="auth-error"></p><div class="auth-actions"><button class="buy-primary" type="submit">Send reset link</button><button class="text-control" type="button" data-view="login">Back to login</button></div></form></article>`;bind(canvas);$('#forgotForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.currentTarget),er=$('#authError');try{const j=await authPost({action:'request_password_reset',email:f.get('email')});er.textContent=j.message||'If the account exists, a reset link has been sent.';e.currentTarget.reset()}catch(x){er.textContent=x.message}}}
  function renderResetPassword(token=''){starters.hidden=true;canvas.innerHTML=`<article class="module auth-module"><div class="module-kicker">ACCOUNT RECOVERY</div><h2>Choose a new password</h2><form id="resetForm" class="auth-form"><label>New password<input name="password" type="password" minlength="10" autocomplete="new-password" required></label><label>Confirm password<input name="confirm" type="password" minlength="10" autocomplete="new-password" required></label><p id="authError" class="auth-error"></p><div class="auth-actions"><button class="buy-primary" type="submit">Reset password</button><button class="text-control" type="button" data-view="login">Back to login</button></div></form></article>`;bind(canvas);$('#resetForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.currentTarget),er=$('#authError');try{const j=await authPost({action:'reset_password',token,password:f.get('password'),confirm:f.get('confirm')});er.textContent=j.message||'Password updated.';setTimeout(()=>navigate('login'),700)}catch(x){er.textContent=x.message}}}
  async function renderVerifyEmail(token=''){starters.hidden=true;canvas.innerHTML=`<article class="module auth-module"><div class="module-kicker">EMAIL VERIFICATION</div><h2>Verifying…</h2><p id="verifyStatus" class="player-note">Checking your verification link.</p></article>`;const el=$('#verifyStatus');try{const j=await authPost({action:'verify_email',token});el.textContent='Email verified. Your Stonefellow account is ready.';if(j.user){st.auth.user=j.user;st.auth.authenticated=true;syncAuthMenu()}setTimeout(()=>navigate(st.auth.authenticated?'account':'login'),800)}catch(x){el.textContent=x.message}}
  function renderRegister(params={}){
    starters.hidden=true;
    const selected=Number(params.plan||st.selectedPlanId||st.plans[0]?.id||0);
    st.selectedPlanId=selected;
    canvas.innerHTML=`<article class="module auth-module"><div class="module-kicker">CREATE ACCOUNT + FREE TRIAL</div><h2>Join the listening room.</h2><p class="player-note">Choose a plan, create your account, and your trial Active Tokens are added immediately.</p><form id="registerForm" class="auth-form"><label>Plan<select name="plan_id">${st.plans.map(p=>`<option value="${p.id}" ${Number(p.id)===selected?'selected':''}>${esc(p.name)}${p.trial_days?' — '+p.trial_days+' day trial':''}</option>`).join('')}</select></label><label>Name<input name="display_name" autocomplete="name" required maxlength="100"></label><label>Email<input name="email" type="email" autocomplete="email" required maxlength="190"></label><label>Password<input name="password" type="password" minlength="10" autocomplete="new-password" required></label><label>Confirm password<input name="confirm" type="password" minlength="10" autocomplete="new-password" required></label><p id="authError" class="auth-error" role="alert"></p><div class="auth-actions"><button class="buy-primary" type="submit">Create account + start trial</button><button class="text-control" type="button" data-view="plans">Compare plans</button><button class="text-control" type="button" data-view="login">Already have an account</button></div></form></article>`;
    bind(canvas);
    const suggestedName=validGuestName(guestProfile?.display_name);
    const nameInput=$('#registerForm input[name="display_name"]');
    if(suggestedName&&nameInput)nameInput.value=suggestedName;
    $('#registerForm').onsubmit=async e=>{
      e.preventDefault();const f=new FormData(e.currentTarget),er=$('#authError');er.textContent='';
      try{
        const j=await authPost({action:'register',display_name:f.get('display_name'),email:f.get('email'),password:f.get('password'),confirm:f.get('confirm'),plan_id:Number(f.get('plan_id')||0)});
        if(j.authenticated){finishAccountEntry();return}
        throw new Error('Account was created but the login session was not established.');
      }catch(x){er.textContent=x.message}
    };
  }
  const jukeboxReturn = new URLSearchParams(location.search).get('view')==='login'
    ? new URLSearchParams(location.hash.slice(1)).get('return_jukebox') : null;
  function finishAccountEntry(){
    location.href=/^[a-f0-9]{32}$/.test(jukeboxReturn||'')
      ? new URL('jukebox.php?id='+jukeboxReturn,document.baseURI).href : '?view=account';
  }
  async function renderMyLibrary(params={}){
    await loadAuth(true);if(!st.auth.authenticated){renderLogin();return}
    const renderer=window.StonefellowLibrary;if(!renderer?.render){canvas.innerHTML=`<article class="module"><div class="module-kicker">MY LIBRARY</div><h2>Library unavailable</h2><p class="player-note">Reload Stonefellow to load the library workspace.</p></article>`;return}
    return renderer.render({canvas,api,securePost:secureJsonPost,esc,time,$,$,bind,navigate,state:st,favoriteButton,bindFavoriteButtons,bindQueueTrackButtons,trackById,play,playPlaylist,loadBuild:loadAccountBuild,resumeTrack,say},params)
  }
  let accountPhotoPreferencesRequest=null;
  async function openAccountPhotoPreferences(){
    const owner=canvasOwner();
    if(!owner)return;
    accountPhotoPreferencesRequest?.request.abort();
    const pending={owner,request:new AbortController()};accountPhotoPreferencesRequest=pending;
    try{
      await window.SFCanvasImageAnalysis?.openPreferences({signal:pending.request.signal,
        api:(path,body,signal)=>body===undefined?api(path,undefined,signal):secureJsonPost(path,body,false,signal)});
    }finally{if(accountPhotoPreferencesRequest===pending)accountPhotoPreferencesRequest=null;}
  }
  async function renderAccount(){
    await loadAuth(true);if(!st.auth.authenticated){renderLogin();return}let j;try{j=await api('account.php')}catch(e){if(e.payload?.error==='authentication_required'){st.auth={loaded:true,authenticated:false,user:null,csrf:null};syncAuthMenu();renderLogin();return}throw e}
    st.auth.user=j.user;st.auth.authenticated=true;st.auth.csrf=j.csrf||st.auth.csrf;syncAuthMenu();const orders=j.orders||[],ent=j.entitlement||{},sub=ent.subscription||{},saved=j.saved_builds||[],library=j.library||[],life=j.lifecycle||{},invoices=j.invoices||[],sessions=j.sessions||[],personal=j.personalization||{favorites:{tracks:[],releases:[]},continue_listening:[],history:[],recommendations:{personalized:false,summary:'',signals:{},items:[]}},playlists=j.playlists||[],notificationPrefs=j.notification_preferences||[],myLibrary=j.my_library||{summary:{}},fanCrm=j.fan_crm||{},privacy=j.privacy||{};st.personalization={loaded:true,...personal};st.playlists={loaded:true,items:playlists};const favTracks=personal.favorites?.tracks||[],favReleases=personal.favorites?.releases||[],continueRows=personal.continue_listening||[],history=personal.history||[],recommendations=personal.recommendations?.items||[];const membership=ent.membership||{},paidManaged=(sub.provider||'local')==='stripe'&&!!sub.provider_subscription_id;
    const notificationPrefsHtml=`<div class="account-orders notification-preferences"><div class="module-kicker account-section-head"><span>NOTIFICATIONS</span><button class="text-control" id="saveNotificationPreferences" type="button">Save preferences</button></div><p class="player-note">Choose which personalized reminders appear in Stonefellow or are sent by email. Account, security, and order service messages remain enabled.</p><div class="notification-preference-list">${notificationPrefs.map(p=>`<div class="notification-preference-row" data-notification-category="${esc(p.category)}"><div><strong>${esc(p.label||p.category)}</strong><small>${esc(p.description||'')}</small></div><label><input type="checkbox" data-notification-in-app ${p.in_app_enabled?'checked':''}> In app</label><label><input type="checkbox" data-notification-email ${p.email_enabled?'checked':''}> Email</label></div>`).join('')}</div></div>`;
    const fanPrefsHtml=`<div class="account-orders fan-preferences"><div class="module-kicker account-section-head"><span>FAN + AGENT</span><button class="text-control" id="saveFanPreferences" type="button">Save preferences</button></div><p class="player-note">Control newsletter email and whether the Stonefellow Agent can proactively greet or suggest something based on your recent activity. Automatic Agent touches are in-app and logged in Agent Brain.</p><div class="fan-preference-grid"><label><input id="fanNewsletterOptIn" type="checkbox" ${fanCrm.marketing_opt_in?'checked':''}> Stonefellow newsletter</label><label><input id="fanAutoEngage" type="checkbox" ${fanCrm.agent_auto_engage!==false?'checked':''}> Proactive Agent interaction</label></div></div>`;
    const verifyNotice=!life.email_verified?`<div class="account-alert"><div><strong>Verify your email</strong><span>${esc(j.user.email)} is not verified. Verification is required before starting a paid subscription.</span></div><button class="text-control" id="resendVerification" type="button">Resend verification</button></div>`:'';
    const billingActions=`<div class="player-controls">${paidManaged?'<button class="text-control" id="manageBilling" type="button">Manage billing</button>':''}${sub.status&&sub.status!=='none'&&!sub.cancel_at_period_end?'<button class="text-control" id="cancelSubscription" type="button">Cancel at period end</button>':''}${Number(sub.cancel_at_period_end)?'<button class="text-control" id="resumeSubscription" type="button">Resume subscription</button>':''}</div>`;
    canvas.innerHTML=`<article class="module auth-module"><div class="module-kicker">MY STONEFELLOW</div><div class="account-header"><div><h2>${esc(j.user.display_name)}</h2><div class="account-meta">${esc(j.user.email)} · ${life.email_verified?'Verified':'Unverified'}<br>${j.user.role==='admin'?'Administrator · ':''}Member since ${esc((j.user.created_at||'').slice(0,10))}</div></div></div>${verifyNotice}<div class="token-panel"><div class="token-stat"><strong>${j.user.role==='admin'?'Unlimited':Number(ent.balance||0).toLocaleString()}</strong><span>Active Tokens</span></div><div class="token-stat"><strong>${esc(sub.package_name||'No package')}</strong><span>Plan</span></div><div class="token-stat"><strong>${esc(sub.status||'none')}</strong><span>Status</span></div>${sub.current_period_end?`<div class="token-stat"><strong>${esc(sub.current_period_end.slice(0,10))}</strong><span>${Number(sub.cancel_at_period_end)?'Access ends':'Renews'}</span></div>`:''}${sub.trial_ends_at?`<div class="token-stat"><strong>${esc(sub.trial_ends_at.slice(0,10))}</strong><span>Trial ends</span></div>`:''}</div>${membership.active?`<section class="account-membership-panel"><div><div class="module-kicker">MEMBERSHIP</div><h3>${esc(membership.badge||membership.package_name||'Stonefellow Member')}</h3><p>${membershipBenefitText(membership.benefits||{})}</p></div><button class="buy-primary" type="button" data-view="membership">Open membership</button></section>`:''}<div class="player-controls"><button class="text-control" data-view="library" type="button">My Library</button><button class="text-control" data-view="plans" type="button">Plans</button><button class="text-control" id="editProfile" type="button">Edit profile</button><button class="text-control" data-view="account-privacy" type="button">Privacy & data</button><button class="text-control" id="accountPhotoPreferences" type="button">Photo preferences</button><button class="text-control" id="saveCurrentBuild" type="button">Save current build</button></div>${billingActions}<div class="account-orders"><div class="module-kicker">BILLING + RECEIPTS</div>${invoices.length?invoices.map(i=>`<div class="account-order"><div><strong>${esc(i.provider_invoice_id)}</strong><small>${esc((i.paid_at||i.created_at||'').replace('T',' ').slice(0,19))} · ${esc(i.status)}</small></div><div><strong>${new Intl.NumberFormat('en-US',{style:'currency',currency:i.currency||'USD'}).format((i.amount_paid_cents||0)/100)}</strong>${i.hosted_invoice_url?`<a class="text-control inline-link" href="${esc(i.hosted_invoice_url)}" target="_blank" rel="noopener">Receipt</a>`:''}${i.invoice_pdf?`<a class="text-control inline-link" href="${esc(i.invoice_pdf)}" target="_blank" rel="noopener">PDF</a>`:''}</div></div>`).join(''):'<p class="player-note">Subscription receipts will appear here after billing begins.</p>'}</div><div class="account-orders account-library-portal"><div class="module-kicker account-section-head"><span>MY LIBRARY</span><button class="text-control" type="button" data-view="library">Open My Library</button></div><p class="player-note">Your saved tracks and releases, playlists, owned music, custom-media builds, Continue Listening, history, and Collections now live in one library workspace.</p><div class="account-library-summary"><button type="button" data-view="library" data-tab="tracks"><strong>${Number(myLibrary.summary?.tracks||0)}</strong><span>Tracks</span></button><button type="button" data-view="library" data-tab="releases"><strong>${Number(myLibrary.summary?.releases||0)}</strong><span>Releases</span></button><button type="button" data-view="library" data-tab="playlists"><strong>${Number(myLibrary.summary?.playlists||0)}</strong><span>Playlists</span></button><button type="button" data-view="library" data-tab="purchases"><strong>${Number(myLibrary.summary?.purchases||0)}</strong><span>Purchases</span></button><button type="button" data-view="library" data-tab="collections"><strong>${Number(myLibrary.summary?.collections||0)}</strong><span>Collections</span></button></div></div>${notificationPrefsHtml}${fanPrefsHtml}<div class="account-orders"><div class="module-kicker">SECURITY + SESSIONS</div><div class="player-controls"><button class="text-control" type="button" id="revokeOtherSessions">Sign out other sessions</button></div>${sessions.length?sessions.map(x=>`<div class="account-order"><div><strong>${x.current?'Current browser':'Signed-in session'}</strong><small>Last active ${esc(String(x.last_seen_at||'').replace('T',' ').slice(0,19))} · Expires ${esc(String(x.expires_at||'').slice(0,10))}</small></div>${x.current?'<span class="account-badge">current</span>':`<button class="text-control" type="button" data-revoke-session="${esc(x.id)}">Sign out</button>`}</div>`).join(''):'<p class="player-note">No persistent sessions are active.</p>'}</div><div class="account-orders"><div class="module-kicker">PURCHASES</div>${orders.length?orders.map(o=>`<div class="account-order"><div><strong>${esc(o.id)}</strong><small>${esc((o.created_at||'').replace('T',' ').slice(0,19))} · ${(o.items||[]).map(i=>esc(i.label)).join(', ')}</small></div><div><div class="account-badge">${esc(o.fulfillment?.status&&o.fulfillment.status!=='not_required'?o.fulfillment.status:o.status)}</div>${o.vinyl_production?`<small class="order-vinyl-status">Vinyl production: ${esc(String(o.vinyl_production.status||'').replaceAll('_',' '))}</small>`:''}${o.fulfillment?.tracking_number?`<small>${esc(o.fulfillment.carrier||'')} ${esc(o.fulfillment.tracking_number)}</small>`:''}<strong>${new Intl.NumberFormat('en-US',{style:'currency',currency:o.currency||'USD'}).format((o.total_cents||0)/100)}</strong><button class="text-control" type="button" data-order-id="${esc(o.id)}">View details</button></div></div>`).join(''):'<p class="player-note">No purchases yet.</p>'}</div><p class="tiny-note">Account navigation, logout, and Admin access are available from the ••• menu.</p></article>`;
    bind(canvas);Array.from(canvas.querySelectorAll('[data-view="library"][data-tab]')).forEach(b=>b.onclick=()=>navigate('library',{tab:b.dataset.tab||'all'}));$('#editProfile').onclick=()=>renderProfileForm();$('#saveCurrentBuild').onclick=saveCurrentBuildToAccount;$$('[data-load-build]',canvas).forEach(b=>b.onclick=()=>loadAccountBuild(Number(b.dataset.loadBuild),saved));$$('[data-delete-build]',canvas).forEach(b=>b.onclick=()=>deleteAccountBuild(Number(b.dataset.deleteBuild)));const resend=$('#resendVerification');if(resend)resend.onclick=resendVerification;const manage=$('#manageBilling');if(manage)manage.onclick=manageBilling;const cancel=$('#cancelSubscription');if(cancel)cancel.onclick=cancelSubscription;const resume=$('#resumeSubscription');if(resume)resume.onclick=resumeSubscription;$$('[data-order-id]',canvas).forEach(b=>b.onclick=()=>navigate('order',{id:b.dataset.orderId}));const revokeOthers=$('#revokeOtherSessions');if(revokeOthers)revokeOthers.onclick=revokeOtherSessions;$$('[data-revoke-session]',canvas).forEach(b=>b.onclick=()=>revokeSession(b.dataset.revokeSession));$$('[data-favorite-track-open]',canvas).forEach(b=>b.onclick=()=>navigate('track',{id:b.dataset.favoriteTrackOpen}));$$('[data-favorite-release-open]',canvas).forEach(b=>b.onclick=()=>navigate('release',{id:b.dataset.favoriteReleaseOpen}));const createPlaylist=$('#createPlaylist');if(createPlaylist)createPlaylist.onclick=createPlaylistPrompt;const saveAgentSession=$('#saveAgentSession');if(saveAgentSession)saveAgentSession.onclick=saveAgentSessionPrompt;$$('[data-playlist-open]',canvas).forEach(b=>b.onclick=()=>navigate('playlist',{id:b.dataset.playlistOpen}));Array.from(canvas.querySelectorAll('[data-resume-track]')).forEach(b=>b.onclick=()=>resumeTrack(b.dataset.resumeTrack,Number(b.dataset.resumePosition||0)));Array.from(canvas.querySelectorAll('[data-recommend-play]')).forEach(b=>b.onclick=()=>{const t=trackById(b.dataset.recommendPlay);if(t)play(t,0,'recommendation')});const clearHistory=$('#clearListeningHistory');if(clearHistory)clearHistory.onclick=clearListeningHistory;const saveNotificationPrefs=$('#saveNotificationPreferences');if(saveNotificationPrefs)saveNotificationPrefs.onclick=saveNotificationPreferences;const saveFanPrefs=$('#saveFanPreferences');if(saveFanPrefs)saveFanPrefs.onclick=saveFanPreferences;
    const photoPreferences=$('#accountPhotoPreferences');
    if(photoPreferences)photoPreferences.onclick=openAccountPhotoPreferences;
    renderGuestMigrationOffer();
  }
  async function saveFanPreferences(){try{await secureJsonPost('fan-preferences.php',{marketing_opt_in:!!$('#fanNewsletterOptIn',canvas)?.checked,agent_auto_engage:!!$('#fanAutoEngage',canvas)?.checked});st.fanEngagementLoaded=false;say('Fan and Agent preferences saved.',()=>renderAccount())}catch(e){say(e.message)}}
  async function saveNotificationPreferences(){const rows=$('[data-notification-category]',canvas).map(row=>({category:row.dataset.notificationCategory,in_app_enabled:!!$('[data-notification-in-app]',row)?.checked,email_enabled:!!$('[data-notification-email]',row)?.checked}));try{const j=await secureJsonPost('notifications.php',{action:'preferences',preferences:rows});if(st.drawer.data)st.drawer.data.preferences=j.preferences||rows;say('Notification preferences saved.',()=>renderAccount())}catch(e){say(e.message)}}
  async function resendVerification(){try{const j=await authPost({action:'resend_verification'});say(j.message||'Verification email queued.')}catch(e){say(e.message)}}
  async function manageBilling(){try{const j=await secureJsonPost('subscription.php',{action:'portal'});if(j.portal_url){location.href=j.portal_url;return}say('Billing portal unavailable.')}catch(e){say(e.message)}}
  async function cancelSubscription(){if(!confirm('Cancel this subscription at the end of the current billing period?'))return;try{await secureJsonPost('subscription.php',{action:'cancel'});say('Cancellation scheduled.',()=>renderAccount())}catch(e){say(e.message)}}
  async function resumeSubscription(){try{await secureJsonPost('subscription.php',{action:'resume'});say('Subscription resumed.',()=>renderAccount())}catch(e){say(e.message)}}
  async function revokeOtherSessions(){if(!confirm('Sign out every other persistent Stonefellow session?'))return;try{const j=await authPost({action:'revoke_other_sessions'});say(`${Number(j.revoked||0)} other session${Number(j.revoked||0)===1?'':'s'} signed out.`,()=>renderAccount())}catch(e){say(e.message)}}
  async function revokeSession(id){try{await authPost({action:'revoke_session',session_id:id});say('Session signed out.',()=>renderAccount())}catch(e){say(e.message)}}
  function orderShipmentHtml(shipments=[]){
    if(!shipments.length)return '';
    return '<div class="account-orders order-ops-section"><div class="module-kicker">SHIPMENTS</div>'+shipments.map(s=>'<article class="order-shipment-card"><div><strong>'+esc(String(s.status||'shipment').replaceAll('_',' '))+'</strong><small>'+esc([s.carrier,s.service].filter(Boolean).join(' · ')||'Shipment')+' · '+esc(String(s.updated_at||'').replace('T',' ').slice(0,19))+'</small></div><div>'+(s.tracking_number?'<strong>'+esc(s.tracking_number)+'</strong>':'')+(s.tracking_url?'<a class="text-control inline-link" href="'+esc(s.tracking_url)+'" target="_blank" rel="noopener">Track shipment</a>':'')+(s.estimated_delivery_at?'<small>ETA '+esc(String(s.estimated_delivery_at).replace('T',' ').slice(0,16))+'</small>':'')+'</div>'+(s.note?'<p>'+esc(s.note)+'</p>':'')+'</article>').join('')+'</div>';
  }
  function orderRefundHtml(refunds=[]){
    if(!refunds.length)return '';
    return '<div class="account-orders order-ops-section"><div class="module-kicker">REFUNDS + RETURNS</div>'+refunds.map(r=>'<article class="order-refund-card"><div><strong>'+money(r.amount_cents||0)+'</strong><small>'+esc(String(r.status||'requested').replaceAll('_',' '))+' · '+esc(String(r.requested_at||'').replace('T',' ').slice(0,19))+'</small></div><div><span>'+esc(r.reason||'')+'</span><small>'+esc(String(r.restock_status||'').replaceAll('_',' '))+'</small></div></article>').join('')+'</div>';
  }
  function orderCareHtml(cases=[]){
    return '<div class="account-orders order-care-section"><div class="module-kicker">ORDER HELP</div>'+(cases.length?cases.map(c=>'<article class="order-care-card"><div class="order-care-head"><div><strong>'+esc(c.case_key)+'</strong><small>'+esc(c.subject)+' · '+esc(String(c.status||'').replaceAll('_',' '))+'</small></div>'+(c.status!=='closed'&&c.status!=='resolved'?'<button class="text-control compact" type="button" data-close-care="'+c.id+'">Close case</button>':'')+'</div><div class="order-care-thread">'+(c.messages||[]).map(m=>'<div class="order-care-message '+esc(m.actor_type)+'"><strong>'+esc(String(m.actor_type||'message').replaceAll('_',' '))+'</strong><p>'+esc(m.message).replace(/\n/g,'<br>')+'</p><small>'+esc(String(m.created_at||'').replace('T',' ').slice(0,19))+'</small></div>').join('')+'</div>'+(c.status!=='closed'&&c.status!=='resolved'?'<form class="order-care-reply" data-care-reply="'+c.id+'"><textarea name="message" required placeholder="Reply to Stonefellow support"></textarea><button class="text-control" type="submit">Send reply</button></form>':'')+'</article>').join(''):'<p class="player-note">No support cases for this order.</p>')+'<details class="order-help-form"><summary>Get help with this order</summary><form id="orderHelpForm" class="auth-form"><label>Issue<select name="issue_type"><option value="order_status">Order status</option><option value="shipping">Shipping</option><option value="damaged">Damaged item</option><option value="wrong_item">Wrong item</option><option value="refund">Refund</option><option value="download">Download</option><option value="billing">Billing</option><option value="other">Other</option></select></label><label>Subject<input name="subject" maxlength="255" required></label><label>Message<textarea name="message" maxlength="8000" required></textarea></label><button class="buy-primary" type="submit">Open support case</button><p id="orderHelpStatus" class="auth-error"></p></form></details></div>';
  }
  function bindOrderCare(orderId){
    const form=$('#orderHelpForm',canvas);if(form)form.onsubmit=async e=>{e.preventDefault();const d=new FormData(e.currentTarget),status=$('#orderHelpStatus',canvas);status.textContent='';try{await secureJsonPost('customer-care.php',{action:'open_case',case:{order_id:orderId,issue_type:d.get('issue_type'),subject:d.get('subject'),message:d.get('message')}});say('Support case opened.',()=>renderOrderDetail(orderId))}catch(x){status.textContent=x.message}};
    $$('[data-care-reply]',canvas).forEach(f=>f.onsubmit=async e=>{e.preventDefault();const d=new FormData(f);try{await secureJsonPost('customer-care.php',{action:'reply_case',case_id:Number(f.dataset.careReply),message:d.get('message'),request_key:crypto.randomUUID?.()||Date.now().toString()});say('Reply sent.',()=>renderOrderDetail(orderId))}catch(x){say(x.message)}});
    $$('[data-close-care]',canvas).forEach(b=>b.onclick=async()=>{if(!confirm('Close this support case?'))return;try{await secureJsonPost('customer-care.php',{action:'close_case',case_id:Number(b.dataset.closeCare)});say('Support case closed.',()=>renderOrderDetail(orderId))}catch(x){say(x.message)}});
  }
  function orderVinylProductionHtml(data){
    if(!data||!data.order_id)return '';
    const formatStatus=value=>String(value||'awaiting_artwork_review').replaceAll('_',' ');
    const entries=(Array.isArray(data.events)?data.events:[]).map(event=>
      '<li><strong>'+esc(formatStatus(event.status))+'</strong><small>'+esc(String(event.at||'').replace('T',' ').slice(0,19))+'</small></li>').join('');
    return '<section class="account-orders order-vinyl-timeline" aria-label="Vinyl production status">'+
      '<div class="module-kicker">VINYL PRODUCTION</div>'+
      '<p class="player-note">Current stage: <strong>'+esc(formatStatus(data.status))+'</strong>. Manufacturing is managed separately from shipping.</p>'+
      (entries?'<ol class="vinyl-milestones">'+entries+'</ol>':'<p class="player-note">Awaiting the first production update.</p>')+
      '</section>';
  }
  async function renderOrderDetail(id){
    try{
      const r=await fetch((cfg.apiBase||'api')+'/customer-order.php?id='+encodeURIComponent(id),{cache:'no-store',credentials:'same-origin'}),j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||'Order not found.');
      const o=j.order||{},ops=j.operations||{},q=o.quote||{},payment=o.payment||{},pod=o.pod||[],timeline=o.timeline||[],ship=o.customer?.shipping||null,f=o.fulfillment||{},fStatus=f.status||(!q.physical?'not_required':'pending');
      canvas.innerHTML='<article class="module auth-module"><div class="module-kicker">ORDER DETAILS</div><h2>'+esc(o.id||id)+'</h2><div class="token-panel"><div class="token-stat"><strong>'+esc(o.status||'')+'</strong><span>Order status</span></div><div class="token-stat"><strong>'+new Intl.NumberFormat('en-US',{style:'currency',currency:q.currency||'USD'}).format((q.total_cents||0)/100)+'</strong><span>Total</span></div><div class="token-stat"><strong>'+esc(payment.status||'—')+'</strong><span>Payment</span></div><div class="token-stat"><strong>'+esc(fStatus)+'</strong><span>Fulfillment</span></div></div><div class="account-orders"><div class="module-kicker">ITEMS</div>'+(q.items||[]).map(i=>'<div class="account-order"><div><strong>'+esc(i.label||i.type)+'</strong><small>'+esc(i.type||'item')+'</small></div><strong>'+new Intl.NumberFormat('en-US',{style:'currency',currency:q.currency||'USD'}).format((i.price_cents||0)/100)+'</strong></div>').join('')+'</div>'+(ship?'<div class="account-orders"><div class="module-kicker">SHIPPING</div><p class="player-note">'+esc(ship.line1||'')+(ship.line2?'<br>'+esc(ship.line2):'')+'<br>'+esc(ship.city||'')+', '+esc(ship.region||'')+' '+esc(ship.postal||'')+'<br>'+esc(ship.country||'')+'</p></div>':'')+'<div class="account-orders"><div class="module-kicker">FULFILLMENT</div>'+(q.physical?'<div class="account-order"><div><strong>'+esc(fStatus.replaceAll('_',' '))+'</strong><small>'+esc(f.updated_at?('Updated '+String(f.updated_at).replace('T',' ').slice(0,19)):'')+'</small></div>'+(f.tracking_number?'<div><strong>'+esc(f.carrier||'Tracking')+'</strong><small>'+esc(f.tracking_number)+'</small>'+(f.tracking_url?'<a class="text-control inline-link" href="'+esc(f.tracking_url)+'" target="_blank" rel="noopener">Track shipment</a>':'')+'</div>':'')+'</div>'+(f.note?'<p class="player-note">'+esc(f.note)+'</p>':'')+(pod.length?pod.map(x=>'<div class="account-order"><div><strong>'+esc(x.status||'POD')+'</strong><small>'+esc(x.file||'')+'</small></div></div>').join(''):''):'<p class="player-note">No physical fulfillment is required for this order.</p>')+'</div>'+orderShipmentHtml(ops.shipments||[])+orderVinylProductionHtml(j.vinyl_production)+orderRefundHtml(ops.refunds||[])+orderCareHtml(ops.cases||[])+(timeline.length?'<div class="account-orders"><div class="module-kicker">ORDER TIMELINE</div>'+timeline.slice().reverse().map(x=>'<div class="account-order"><div><strong>'+esc(x.label||x.status||x.type)+'</strong><small>'+esc(String(x.created_at||'').replace('T',' ').slice(0,19))+'</small></div></div>').join('')+'</div>':'')+'<div class="player-controls"><button class="text-control" type="button" data-view="account">Back to account</button></div></article>';
      bind(canvas);bindOrderCare(o.id||id);
    }catch(e){say(e.message,()=>navigate('account'))}
  }

  async function saveCurrentBuildToAccount(){return saveBuilderDraft(true)}
  function loadAccountBuild(id,rows){const row=(rows||[]).find(x=>Number(x.id)===Number(id));if(!row||!row.build)return;st.builderDrafts={loaded:true,items:rows||[],activeId:Number(row.id)};localStorage.setItem('stonefellow.build.draft.id',String(row.id));st.builder={...defaultBuild(),...row.build,version:4};persist();say('Loaded “'+(row.name||st.builder.title)+'.”',()=>navigate('builder'))}
  async function deleteAccountBuild(id){if(!confirm('Delete this saved build?'))return;try{await secureJsonPost('builds.php',{action:'delete',id});if(Number(st.builderDrafts.activeId)===Number(id)){st.builderDrafts.activeId=0;localStorage.removeItem('stonefellow.build.draft.id')}st.builderDrafts.loaded=false;say('Saved build deleted.',()=>renderAccount())}catch(e){say(e.message)}}
  function downloadPrivacyJson(filename,data){const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=filename||'stonefellow-account-export.json';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000)}
  function privacyEventRows(rows=[]){return rows.length?'<div class="privacy-event-list">'+rows.map(x=>'<div><strong>'+esc(String(x.event_type||'privacy event').replaceAll('_',' '))+'</strong><small>'+esc(String(x.created_at||'').replace('T',' ').slice(0,19))+'</small></div>').join('')+'</div>':'<p class="player-note">No recent privacy changes.</p>'}
  async function renderPrivacyCenter(){
    await loadAuth(true);if(!st.auth.authenticated){renderLogin();return}let j;try{j=await api('privacy.php')}catch(e){if(e.payload?.error==='authentication_required'){renderLogin();return}throw e}
    const p=j.privacy||{},profile=p.profile||{},blockers=p.closure_blockers||[],events=p.recent_privacy_events||[],browserTz=Intl.DateTimeFormat().resolvedOptions().timeZone||'';
    canvas.innerHTML='<article class="module privacy-center"><div class="module-kicker">ACCOUNT · PROFILE + PRIVACY</div><div class="privacy-head"><div><h2>Your Stonefellow data</h2><p class="player-note">Control the profile details Stonefellow uses, whether future listening is tied to your account, and whether your activity is used for personalized recommendations.</p></div><button class="text-control" type="button" data-view="account">Back to account</button></div><section class="privacy-panel"><div class="module-kicker">PROFILE + PERSONALIZATION</div><form id="privacyProfileForm" class="privacy-profile-form"><label>Timezone<input name="timezone" list="privacyTimezoneList" value="'+esc(profile.timezone||browserTz)+'" placeholder="America/Phoenix"><datalist id="privacyTimezoneList">'+(browserTz?'<option value="'+esc(browserTz)+'">':'')+'<option value="America/Phoenix"><option value="America/Los_Angeles"><option value="America/Denver"><option value="America/Chicago"><option value="America/New_York"><option value="UTC"></datalist></label><label>Country code<input name="country_code" maxlength="2" value="'+esc(profile.country_code||'')+'" placeholder="US"></label><label>Locale<input name="locale" maxlength="20" value="'+esc(profile.locale||'en-US')+'" placeholder="en-US"></label><label class="privacy-switch"><input type="checkbox" name="listening_history_enabled" '+(profile.listening_history_enabled!==false?'checked':'')+'><span><strong>Save listening history</strong><small>When off, new listening events remain anonymous and Continue Listening stops updating.</small></span></label><label class="privacy-switch"><input type="checkbox" name="personalization_enabled" '+(profile.personalization_enabled!==false?'checked':'')+'><span><strong>Use my activity for personalization</strong><small>When off, recommendations and Agent music ranking stop using favorites, listening, playlists and feedback.</small></span></label><div class="actions"><button class="buy-primary" type="submit">Save profile + privacy</button><span id="privacyProfileStatus" class="auth-error"></span></div></form></section><section class="privacy-panel"><div class="module-kicker">YOUR DATA</div><p class="player-note">Exports include account/profile information, library, listening history, playlists, purchases, invoices, Agent conversations, support history and identifiable telemetry associated with your account.</p><div class="privacy-action-grid"><button class="text-control" id="privacyExport" type="button">Download my data</button><button class="text-control" id="privacyClearHistory" type="button">Clear listening history</button><button class="text-control" id="privacyResetPersonalization" type="button">Reset personalization signals</button><button class="text-control" id="privacyClearSearch" type="button">Clear search/discovery activity</button><button class="text-control" id="privacyClearAgent" type="button">Clear Agent conversation history and canvas scenes</button></div></section><section class="privacy-panel"><div class="module-kicker">RECENT PRIVACY ACTIVITY</div>'+privacyEventRows(events)+'</section><section class="privacy-panel privacy-danger"><div class="module-kicker">CLOSE ACCOUNT</div><h3>Close your Stonefellow account</h3><p class="player-note">'+esc(p.retention_note||'')+'</p>'+(blockers.length?'<div class="account-alert"><div><strong>Closure is currently blocked</strong><span>'+esc(blockers.join(' '))+'</span></div></div>':'<p class="player-note">Closing your account signs you out everywhere, removes or anonymizes fan-behavior/profile data, archives newsletter identity, and disables future login.</p>')+'<form id="privacyCloseForm" class="privacy-close-form"><label>Current password<input name="current_password" type="password" autocomplete="current-password" required '+(blockers.length?'disabled':'')+'></label><label>Type DELETE<input name="confirmation" autocomplete="off" required pattern="DELETE" '+(blockers.length?'disabled':'')+'></label><button class="danger-button" type="submit" '+(blockers.length?'disabled':'')+'>Close account permanently</button><p id="privacyCloseStatus" class="auth-error"></p></form></section></article>';
    bind(canvas);
    const form=$('#privacyProfileForm',canvas);if(form)form.onsubmit=async e=>{e.preventDefault();const fd=new FormData(form),status=$('#privacyProfileStatus',canvas);status.textContent='Saving…';try{await secureJsonPost('privacy.php',{action:'save_profile',profile:{timezone:fd.get('timezone'),country_code:fd.get('country_code'),locale:fd.get('locale'),listening_history_enabled:fd.get('listening_history_enabled')==='on',personalization_enabled:fd.get('personalization_enabled')==='on'}});st.personalization.loaded=false;status.textContent='Saved.'}catch(x){status.textContent=x.message}};
    const exportBtn=$('#privacyExport',canvas);if(exportBtn)exportBtn.onclick=async()=>{if(!confirm('Generate and download a JSON copy of your Stonefellow account data?'))return;exportBtn.disabled=true;try{const x=await secureJsonPost('privacy.php',{action:'export',confirmed:true});downloadPrivacyJson(x.filename,x.export);say('Your Stonefellow data export is ready.')}catch(x){say(x.message)}finally{exportBtn.disabled=false}};
    const clearHistory=$('#privacyClearHistory',canvas);if(clearHistory)clearHistory.onclick=async()=>{if(!confirm('Clear your identifiable Stonefellow listening history and Continue Listening progress?'))return;try{await secureJsonPost('privacy.php',{action:'clear_history',confirmed:true});st.personalization.loaded=false;say('Listening history cleared.',()=>renderPrivacyCenter())}catch(x){say(x.message)}};
    const reset=$('#privacyResetPersonalization',canvas);if(reset)reset.onclick=async()=>{if(!confirm('Reset listening history and track-feedback signals used for personalization? Favorites, playlists and purchases will stay saved.'))return;try{await secureJsonPost('privacy.php',{action:'reset_personalization',confirmed:true});st.personalization.loaded=false;say('Personalization signals reset.',()=>renderPrivacyCenter())}catch(x){say(x.message)}};
    const search=$('#privacyClearSearch',canvas);if(search)search.onclick=async()=>{if(!confirm('Remove your account identity from saved search and discovery analytics?'))return;try{await secureJsonPost('privacy.php',{action:'clear_search_activity',confirmed:true});say('Search and discovery activity anonymized.',()=>renderPrivacyCenter())}catch(x){say(x.message)}};
    const agentClear=$('#privacyClearAgent',canvas);if(agentClear)agentClear.onclick=async()=>{if(!confirm('Permanently delete your Stonefellow Agent conversation history and saved canvas scenes?'))return;try{closeListeningRoom();await secureJsonPost('privacy.php',{action:'clear_agent_history',confirmed:true});popupHistoryEpoch++;window.SFPublicAgentCanvas?.setSceneWorkflow?.(null);stopListeningRoomBuild.pending=null;canvasProjects.current?.clearHistory?.();popupEntries=[];popupHistoryLoaded=true;st.conversationId='';localStorage.removeItem('stonefellow.agent.conversation');renderPopupEntries();say('Agent conversations and canvas scenes cleared.',()=>renderPrivacyCenter())}catch(x){say(x.message)}};
    const close=$('#privacyCloseForm',canvas);if(close)close.onsubmit=async e=>{e.preventDefault();const fd=new FormData(close),status=$('#privacyCloseStatus',canvas);if(String(fd.get('confirmation')||'')!=='DELETE'){status.textContent='Type DELETE exactly.';return}if(!confirm('Permanently close this Stonefellow account? This cannot be undone from the site.'))return;status.textContent='Closing account…';try{await secureJsonPost('privacy.php',{action:'close_account',current_password:fd.get('current_password'),confirmation:fd.get('confirmation'),confirmed:true});localStorage.removeItem('stonefellow.agent.conversation');localStorage.removeItem('stonefellow.auth');location.href=new URL('./',document.baseURI).href}catch(x){status.textContent=x.message}};
  }
  function renderProfileForm(){
    const u=st.auth.user;if(!u){renderLogin();return}
    canvas.innerHTML=`<article class="module auth-module"><div class="module-kicker">ACCOUNT SETTINGS</div><h2>Your profile</h2><form id="profileForm" class="auth-form"><label>Display name<input name="display_name" value="${esc(u.display_name)}" required maxlength="100"></label><p id="authError" class="auth-error"></p><div class="auth-actions"><button class="buy-primary" type="submit">Save</button><button class="text-control" type="button" data-view="account">Cancel</button></div></form><form id="emailForm" class="auth-form"><h3>Change email</h3><label>New email<input name="email" type="email" autocomplete="email" required value="${esc(u.email)}"></label><label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label><p id="emailError" class="auth-error"></p><button class="text-control" type="submit">Change email + verify</button></form><form id="accountPasswordForm" class="auth-form"><h3>Change password</h3><label>Current password<input name="current" type="password" autocomplete="current-password" required></label><label>New password<input name="next" type="password" minlength="10" autocomplete="new-password" required></label><label>Confirm new password<input name="confirm" type="password" minlength="10" autocomplete="new-password" required></label><p id="passwordError" class="auth-error"></p><button class="text-control" type="submit">Change password</button></form></article>`;
    bind(canvas);$('#profileForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.currentTarget),er=$('#authError');er.textContent='';try{await authPost({action:'profile',display_name:f.get('display_name')});location.href='?view=account'}catch(x){er.textContent=x.message}};$('#emailForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.currentTarget),er=$('#emailError');er.textContent='';try{const j=await authPost({action:'change_email',email:f.get('email'),current_password:f.get('current_password')});if(j.user){st.auth.user=j.user}er.textContent=j.message||'Email updated.';setTimeout(()=>navigate('account'),700)}catch(x){er.textContent=x.message}};$('#accountPasswordForm').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.currentTarget),er=$('#passwordError');er.textContent='';try{const j=await authPost({action:'password',current_password:f.get('current'),new_password:f.get('next'),confirm:f.get('confirm')});if(j.csrf)st.auth.csrf=j.csrf;e.currentTarget.reset();er.textContent='Password updated.'}catch(x){er.textContent=x.message}};
  }
  async function renderMyAgentBrain(){
    await loadAuth(true);
    if(!st.auth.authenticated){renderLogin();return;}
    if(!window.SFCustomerBrain?.render){canvas.textContent='Your Agent Brain is unavailable.';return;}
    await window.SFCustomerBrain.render({
      canvas,api,post:secureJsonPost,esc,navigate,
      chooseConversation:cid=>{
        st.conversationId=cid;
        localStorage.setItem('stonefellow.agent.conversation',cid);
      }
    });
  }
  async function renderMyAgentConversation(){
    await loadAuth(true);
    if(!st.auth.authenticated){renderLogin();return;}
    if(!window.SFCustomerAgentChat?.render){canvas.textContent='Agent Chat is unavailable.';return;}
    await window.SFCustomerAgentChat.render({
      canvas,api,esc,navigate,post:secureJsonPost,send:liveAgent,
      conversationId:cid=>{
        if(cid===undefined)return st.conversationId;
        st.conversationId=cid;
        if(cid)localStorage.setItem('stonefellow.agent.conversation',cid);
        else localStorage.removeItem('stonefellow.agent.conversation');
        return cid;
      }
    });
  }
  // Explicit player-listening commands are owned by this browser, not the
  // conversational Agent endpoint. One offline Agent request must not prevent
  // the user from starting or stopping a selected song excerpt.
  function directSongListeningCommand(message){
    const q=String(message||'').trim()
      .replace(/^(?:(?:hey|hi|ok|okay)\s+)?stone[\s-]?fellow[,.:!?\s]*/i,'')
      .replace(/[.!?]+$/,'').trim();
    if(/^(?:please\s+)?listen(?:\s+(?:to|in\s+on)\s+(?:this\s+|the\s+)?(?:song|music|verse|chorus|section))?\s+(?:and|&)\s+(?:then\s+)?(?:interpret|analy[sz]e)(?:\s+(?:the|this|its)\s+(?:lyrics|song|verse|chorus|section))?$/i.test(q))
      return {type:'song_capture_live_start',interpret_on_stop:true};
    if(/^(?:please\s+)?(?:(?:start|begin|enable|activate|turn\s+on)\s+(?:the\s+)?(?:live\s+)?(?:song\s+|lyric\s+)?(?:transcription|transcribing|captions|lyrics)|transcribe\s+live)$/i.test(q))
      return {type:'song_capture_live_start',interpret_on_stop:false};
    if(songListeningSkill?.status().active&&/^(?:please\s+)?(?:stop|finish|end)\s+(?:listening|transcribing)(?:\s+(?:to\s+)?(?:this\s+|the\s+)?(?:song|verse|section))?$/i.test(q))
      return {type:'song_capture_stop'};
    if(currentSongDraft()&&/^(?:please\s+)?(?:review\s+and\s+)?(?:interpret|analy[sz]e|explain)(?:\s+(?:this|the|these))?\s+(?:lyric\s+draft|lyrics?|verse|chorus|transcript|section)(?:\s+now)?$/i.test(q))
      return {type:'song_capture_analyze',question:String(message).trim().slice(0,700)};
    return null;
  }
  // One bounded, canonical browser snapshot serves typed chat and the live
  // Speech Engine bridge. Queue/playlist entries are IDs, not model-written
  // song titles; the PHP agent resolves them against the published catalog.
  function agentClientState(){
    const u=new URL(location.href),current=observedPlayer();
    const view=history.state?.view||window.STONEFELLOW_INITIAL_ROUTE?.view||u.searchParams.get('view')||(String(u.pathname).replace(/\/+$/,'').endsWith('/search')?'search':'home');
    const ids=values=>(Array.isArray(values)?values:[]).map(String).filter(Boolean).slice(0,20);
    return {
      view,route_id:history.state?.params?.id||u.searchParams.get('id')||'',
      active_track_id:current.track?.id||'',active_release_title:current.track?.release||'',
      public_canvas_enabled:window.SFPublicAgentCanvas?.active?.()===true,
      timezone:Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC',
      ...playerState(),builder_format:st.builder.format,
      builder_side_a_count:st.builder.A.length,builder_side_b_count:st.builder.B.length,
      builder_side_a_seconds:sideTime('A'),builder_side_b_seconds:sideTime('B'),
      builder_limit_seconds:limit(),builder_draft_id:Number(st.builderDrafts.activeId||0),
      playlist_name:st.playlistPlayback.playlistName||'',
      playlist_track_ids:ids(st.playlistPlayback.trackIds),
      playlist_index:Math.max(0,Number(st.playlistPlayback.index)||0),
      agent_listening_session_id:agentSessionVisible()?st.agentSession.id:'',
      queue_track_ids:ids(st.queue.items.map(item=>item.track_id)),
      queue_count:st.queue.items.length,home_suggestion_kind:st.home.data?.suggestion?.kind||'',
      home_suggestion_title:st.home.data?.suggestion?.title||'',
      notification_unread:Number(st.drawer.data?.unread||0),
      latest_notification_title:st.drawer.data?.notifications?.[0]?.title||'',
      search_query:$('#siteSearchInput',canvas)?.value||$('#catalogSearch',canvas)?.value||''
    };
  }
  function canvasDesignBrief(brief){
    const clean=brief.replace(/\{music-(?:track:[A-Za-z0-9_-]{1,180}|all|clear)\}/gi,' ')
      .replace(/\{(?:canvas:[1-9]\d*|lyrics:[A-Za-z0-9_-]{1,180}|me:[a-z][a-z0-9_]{0,63}|data:[a-z][a-z0-9-]{0,31}:(?=[a-z0-9_.]{3,64}:)[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*:[A-Za-z0-9_-]{1,100}|merch:[A-Za-z0-9_-]{1,180}|ticket:[A-Za-z0-9_-]{1,180}|playlist:(?:own|artist)-[1-9]\d{0,18}@[a-f0-9]{64}|story:[1-9]\d{0,18}|stories-(?:all|clear|tag:[a-z0-9-]{1,64})|lyrics-clear|me-clear|data-clear|playlists-clear|merch-clear|tickets-clear)\}/gi,' ')
      .replace(/(`?)\{[^\S\r\n]*(?:hide-agent|design-ag(?:ent|net))[^\S\r\n]*\}\1/gi,' ').trim();
    return clean!==brief&&/^[.!?…]+$/.test(clean)?'':clean;
  }
  let manualAgentHidden=false;
  function syncAgentVisibility(hidden){
    const effective=manualAgentHidden||hidden;
    document.body.classList.toggle('sf-agent-hidden-by-scene',effective);
    const restore=$('#menuShowAgent');
    if(restore)restore.hidden=!effective;
    syncAgentDockLayout();
  }
  function handleAgentVisibilityCommand(text){
    const action=String(text||'').trim().toLowerCase();
    if(action!=='/hide-agent'&&action!=='/show-agent')return false;
    manualAgentHidden=action==='/hide-agent';
    const moveFocus=manualAgentHidden&&document.activeElement?.closest?.('.agent-stage,#voiceButton,#agentVoiceReplyToggle');
    document.body.classList.toggle('sf-agent-manually-hidden',manualAgentHidden);
    if(!window.SFPublicAgentCanvas?.setAgentHidden?.(manualAgentHidden))syncAgentVisibility(manualAgentHidden);
    if(moveFocus)$('#menuButton')?.focus({preventScroll:true});
    return true;
  }
  function bindAgentVisibilityMenu(){
    const restore=$('#menuShowAgent');
    if(restore)restore.onclick=()=>{
      handleAgentVisibilityCommand('/show-agent');
      closeMenu();
      $('#menuButton')?.focus({preventScroll:true});
    };
  }
  let chatImagePicker=null,chatImageDraftLimit=-1,canvasImageEditDraft=null;
  async function uploadCanvasPhoto(file,{signal}={},retried=false){
    if(signal?.aborted)throw new Error('The photo upload was canceled.');
    if(!st.auth.csrf)await loadAuth(true);
    const payload=new FormData();
    payload.append('file',file);
    payload.append('title',String(file.name||'My photo').replace(/\.[^.]+$/,'').slice(0,180));
    const response=await fetch((cfg.apiBase||'api')+'/agent-canvas-photo.php',{
      method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':st.auth.csrf||''},body:payload,signal});
    let result;
    try{result=await response.json();}catch(_){throw new Error('The server returned an unreadable photo upload response.');}
    if(response.status===403&&result.error==='csrf_failed'&&!retried){
      await loadAuth(true);
      return uploadCanvasPhoto(file,{signal},true);
    }
    if(!response.ok||result.ok===false)throw new Error(result.message||'The photo could not be uploaded.');
    if(typeof window!=='undefined')window.SFTokenMeter?.notifyUsage?.();
    if(!result.image||typeof result.image.id!=='string'||!/^[a-f0-9]{32}$/.test(result.image.id))
      throw new Error('The server could not confirm the saved photo.');
    return result.image;
  }
  const chatImageReferenceLimit=4;
  const chatImageId=/^(?:ref-)?[a-f0-9]{32}$/;
  function setCanvasImageEditDraft(selection){
    if(canvasImageEditDraft===selection)return;
    canvasImageEditDraft=selection;
    const row=document.getElementById?.('canvasImageEditDraft');
    if(row){
      row.hidden=!selection;
      row.querySelector('[data-canvas-image-edit-label]').textContent=selection?'Editing '+selection.title+' · describe the change':'';
      row.querySelector('[data-canvas-image-edit-cancel]').onclick=selection?()=>{
        setCanvasImageEditDraft(null);
        input.focus({preventScroll:true});
      }:null;
    }
    document.body?.classList?.toggle('sf-canvas-image-editing',!!selection);
  }
  function selectCanvasImageForEdit(asset){
    const owner=canvasOwner();
    if(!owner||listeningRoomRequest||!listeningRoom||asset?.sceneId!==listeningRoom.id||listeningRoom.canvas_kind!=='custom'||
      !window.SFPublicAgentCanvas?.sceneActive?.()||typeof asset.id!=='string'||!chatImageId.test(asset.id))return false;
    const current=Array.isArray(listeningRoom.image_assets)&&listeningRoom.image_assets.find(image=>image?.id===asset.id);
    if(!current)return false;
    const title=(typeof current.title==='string'&&current.title.trim().slice(0,160))||'selected image';
    setCanvasImageEditDraft({owner,sceneId:listeningRoom.id,imageId:current.id,title});
    setChatFooterHidden(false);
    closeQuickMenu();
    input.focus({preventScroll:true});
    return true;
  }
  function chatDraftImageIds(draft){
    return new Set(Array.from(draft.matchAll(/\{image-((?:ref-)?[a-f0-9]{32})\}/gi),match=>match[1].toLowerCase()));
  }
  function chatDraftVisualReferenceIds(draft){
    return Array.from(new Set(Array.from(draft.matchAll(/\{reference-image-((?:ref-)?[a-f0-9]{32})\}/gi),match=>match[1].toLowerCase())));
  }
  let chatMusicPicker=null,chatMusicCommandTimer=null;
  let chatResourcePicker=null,chatResourceCommandTimer=null;
  function validChatStoryTag(value){
    return typeof value==='string'&&value.length<=64&&/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value);
  }
  function chatDraftResourceInputs(draft){
    const sources=[],lyrics=[],meTools=[],merch=[],tickets=[],playlists=[];
    for(const match of draft.matchAll(/\{(canvas|lyrics|me|merch|ticket):([A-Za-z0-9_-]{1,180})\}/gi)){
      const kind=match[1].toLowerCase(),id=match[2];
      if(kind==='canvas'){
        const value=Number(id);if(/^[1-9]\d*$/.test(id)&&Number.isSafeInteger(value))sources.push(value);
      }else if(kind==='lyrics')lyrics.push(id);
      else if(kind==='me'){if(/^[a-z][a-z0-9_]{0,63}$/i.test(id))meTools.push(id.toLowerCase());}
      else (kind==='merch'?merch:tickets).push(id);
    }
    const playlistSeen=new Set();
    for(const match of draft.matchAll(/\{playlist:((?:own|artist)-[1-9]\d{0,18})@([a-f0-9]{64})\}/gi)){
      const ref=match[1].toLowerCase(),version=match[2].toLowerCase(),key=ref+'@'+version;
      if(!playlistSeen.has(key)){playlistSeen.add(key);playlists.push({ref,version});}
    }
    const artist={};
    if(lyrics.length||/\{lyrics-clear\}/i.test(draft))artist.lyrics_ids=Array.from(new Set(lyrics));
    if(meTools.length||/\{me-clear\}/i.test(draft))artist.me_tools=Array.from(new Set(meTools));
    const dataSources=[],seenDataSources=new Set();
    for(const match of draft.matchAll(/\{data:([a-z][a-z0-9-]{0,31}):((?=[a-z0-9_.]{3,64}:)[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*):([A-Za-z0-9_-]{1,100})\}/gi)){
      const source={alias:match[1].toLowerCase(),product:match[2].toLowerCase(),location_id:match[3]};
      const key=source.alias+':'+source.product+':'+source.location_id;
      if(!seenDataSources.has(key)){seenDataSources.add(key);dataSources.push(source);}
    }
    if(dataSources.length||/\{data-clear\}/i.test(draft))artist.data_sources=dataSources;
    if(merch.length||/\{merch-clear\}/i.test(draft))artist.merch_ids=Array.from(new Set(merch));
    if(tickets.length||/\{tickets-clear\}/i.test(draft))artist.ticket_ids=Array.from(new Set(tickets));
    const storyIds=Array.from(new Set(Array.from(draft.matchAll(/\{story:([1-9]\d{0,18})\}/gi),match=>match[1])));
    const storyTag=draft.match(/\{stories-tag:([a-z0-9-]{1,64})\}/i)?.[1].toLowerCase();
    if(/\{stories-all\}/i.test(draft))artist.stories={mode:'all'};
    else if(validChatStoryTag(storyTag))artist.stories={mode:'tag',tag:storyTag};
    else if(storyIds.length||/\{stories-clear\}/i.test(draft))artist.stories={mode:'selected',story_ids:storyIds};
    return {...(sources.length?{source_scene_ids:Array.from(new Set(sources))}:{}),
      ...(Object.keys(artist).length?{artist_inputs:artist}:{}),
      ...(playlists.length||/\{playlists-clear\}/i.test(draft)?{playlist_inputs:{items:playlists}}:{})};
  }
  function chatResourceCommandRange(draft,caret){
    for(const match of draft.matchAll(/(^|[\s(])\/(canvas|lyrics|me|merch|tickets|playlists|data|stories(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?)(?=$|[\s),.!?;:])/gi)){
      const start=match.index+match[1].length,end=start+match[2].length+1;
      if(caret!==undefined&&caret!==end)continue;
      const command=match[2].toLowerCase();
      const tag=command.startsWith('stories-')?command.slice('stories-'.length):null;
      if(tag&&!validChatStoryTag(tag))continue;
      return {kind:tag?'stories':command,start,end,standalone:draft.trim().toLowerCase()==='/'+command,...(tag?{tag}:{})};
    }
    return null;
  }
  function applyChatResourceSelection(range,ids,playlistItems=[],storyScope=null){
    const tokenKind=range.kind==='tickets'?'ticket':range.kind==='playlists'?'playlist':range.kind==='stories'?'story':range.kind;
    let rule=new RegExp('\\{'+tokenKind+':[A-Za-z0-9_-]{1,180}\\}|\\{'+range.kind+'-clear\\}','gi');
    if(range.kind==='playlists')rule=/\{(?:playlist:(?:own|artist)-[1-9]\d{0,18}@[a-f0-9]{64}|playlists-clear)\}/gi;
    if(range.kind==='me')rule=/\{(?:me:[a-z][a-z0-9_]{0,63}|me-clear)\}/gi;
    if(range.kind==='stories')rule=/\{(?:story:[1-9]\d{0,18}|stories-(?:all|clear|tag:[a-z0-9-]{1,64}))\}/gi;
    const versions=new Map(playlistItems.map(item=>[item.ref,item.version]));
    if(range.kind==='playlists'&&ids.some(id=>!/^(?:own|artist)-[1-9]\d{0,18}$/.test(id)||!/^[a-f0-9]{64}$/.test(versions.get(id)||'')))throw new Error('Choose a verified playlist version before adding it.');
    if(range.kind==='lyrics'&&ids.some(id=>typeof id!=='string'||!/^[A-Za-z0-9_-]{1,180}$/.test(id)))
      throw new Error('Choose published lyrics from the picker.');
    if(range.kind==='me'&&ids.some(id=>typeof id!=='string'||!/^[a-z][a-z0-9_]{0,63}$/.test(id)))
      throw new Error('Choose account data from the picker.');
    if(range.kind==='stories'&&ids.some(id=>typeof id!=='string'||!/^[1-9]\d{0,18}$/.test(id)||(id.length===19&&id>'9223372036854775807')))
      throw new Error('Choose published stories from the picker.');
    let tokens;
    if(range.kind==='stories'&&storyScope?.all===true){
      const tag=storyScope.tag??'';
      if((tag!==''&&!validChatStoryTag(tag))||(range.tag&&range.tag!==tag))
        throw new Error('Choose a valid published story tag.');
      tokens=tag?'{stories-tag:'+tag+'}':'{stories-all}';
    }else tokens=ids.map(id=>'{'+tokenKind+':'+id+(range.kind==='playlists'?'@'+versions.get(id):'')+'}').join(' ')||
      (range.kind==='canvas'?'':'{'+range.kind+'-clear}');
    setChatResourceTokens(range,rule,tokens);
  }
  function setChatResourceTokens(range,rule,tokens){
    const before=input.value.slice(0,range.start).replace(rule,''),after=input.value.slice(range.end).replace(rule,'');
    input.value=before+tokens+after;
    syncChatImageDraftLimit();
    input.setSelectionRange(before.length+tokens.length,before.length+tokens.length);
  }
  function applyChatDataSelection(range,sources){
    if(!Array.isArray(sources)||sources.some(source=>!source||typeof source.alias!=='string'||!/^[a-z][a-z0-9-]{0,31}$/.test(source.alias)||
      typeof source.product!=='string'||source.product.length>64||!/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/.test(source.product)||typeof source.location_id!=='string'||!/^[A-Za-z0-9_-]{1,100}$/.test(source.location_id)))
      throw new Error('Choose verified live data sources from the picker.');
    if(new Set(sources.map(source=>source.alias)).size!==sources.length)throw new Error('Use a unique name for each data source.');
    const tokens=sources.map(source=>'{data:'+source.alias+':'+source.product+':'+source.location_id+'}').join(' ')||'{data-clear}';
    setChatResourceTokens(range,/\{(?:data:[a-z][a-z0-9-]{0,31}:(?=[a-z0-9_.]{3,64}:)[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*:[A-Za-z0-9_-]{1,100}|data-clear)\}/gi,tokens);
  }
  async function openChatResourcePicker(range){
    if(chatResourcePicker||chatMusicPicker||chatImagePicker)return;
    const requestedDraft=input.value;
    await loadAuth();
    if(input.value!==requestedDraft||chatResourcePicker||chatMusicPicker||chatImagePicker)return;
    if(!canvasOwner()&&!['data','lyrics','playlists','stories'].includes(range.kind)){
      popupEntry('notice',range.kind==='me'?'Sign in to choose your account data.':'Sign in to choose canvas project sources.');return;
    }
    const dataPicker=range.kind==='data';
    const open=dataPicker?window.SFCanvasDataPicker?.open:window.SFCanvasResourcePicker?.open;
    if(typeof open!=='function'){popupEntry('notice','Reload the page to open the source picker.');return;}
    const draft=input.value,owner=canvasOwner(),sourceScene=listeningRoom,resources=chatDraftResourceInputs(draft);
    const revising=directListeningRoomCommand(draft)?.kind==='revise';
    const previous=revising?sourceScene:null;
    const playlistSelection=resources.playlist_inputs??previous?.playlist_inputs;
    const stories=resources.artist_inputs?.stories??previous?.artist_inputs?.stories;
    const storyTag=range.tag??(stories?.mode==='tag'&&validChatStoryTag(stories.tag)?stories.tag:'');
    const storyAllSelected=storyTag?stories?.mode==='tag'&&stories.tag===storyTag:stories?.mode==='all';
    let selected;
    if(range.kind==='playlists')selected=(playlistSelection?.items||[]).map(item=>item.ref);
    else if(range.kind==='stories')selected=stories?.mode==='selected'?stories.story_ids:[];
    else if(range.kind==='canvas')selected=resources.source_scene_ids;
    else {
      const key=dataPicker?'data_sources':range.kind==='me'?'me_tools':range.kind==='tickets'?'ticket_ids':range.kind+'_ids';
      selected=resources.artist_inputs?.[key]??previous?.artist_inputs?.[key];
    }
    const picker={owner,request:new AbortController()};chatResourcePicker=picker;
    try{
      const result=dataPicker?await open({
        load:signal=>api('canvas-data.php?action=inventory',undefined,signal),
        search:(query,signal)=>api('canvas-data.php?action=places&q='+encodeURIComponent(query),undefined,signal),
        resolvePlace:(id,signal)=>api('canvas-data.php?action=places&id='+encodeURIComponent(id),undefined,signal),
        selectedSources:selected,signal:picker.request.signal
      }):await open({kind:range.kind,load:signal=>api('canvas-resources.php?kind='+range.kind,undefined,signal),
        selectedIds:(selected||[]).map(String),
        ...(range.kind==='stories'?{storyTag,storyTagLocked:!!range.tag,
          selectedAll:storyAllSelected}:{}),
        selectedVersions:range.kind==='playlists'?Object.fromEntries((playlistSelection?.items||[]).map(item=>[item.ref,item.version])):undefined,signedIn:!!owner,signal:picker.request.signal});
      if(!result||picker.request.signal.aborted||chatResourcePicker!==picker||owner!==canvasOwner()||input.value!==draft||
        (revising&&sourceScene!==listeningRoom&&['data','lyrics','me','playlists','stories'].includes(range.kind)))return;
      if(dataPicker)applyChatDataSelection(range,result);
      else applyChatResourceSelection(range,result.ids,result.items,result);
    }catch(error){if(error.name!=='AbortError'&&owner===canvasOwner())popupEntry('notice',error.message||'Sources could not load.');}
    finally{if(chatResourcePicker===picker)chatResourcePicker=null;picker.request.abort();}
  }
  function bindChatResourcePicker(){
    const read=event=>{
      clearTimeout(chatResourceCommandTimer);chatResourceCommandTimer=null;
      if(event?.isComposing||input.selectionStart!==input.selectionEnd)return;
      const draft=input.value,range=chatResourceCommandRange(draft,input.selectionEnd),owner=canvasOwner();
      // Wait for submission when a command could grow into another resource command.
      if(!range||['stories','me','data'].includes(range.kind))return;
      chatResourceCommandTimer=setTimeout(()=>{
        if(input.value===draft&&owner===canvasOwner())void openChatResourcePicker(range);
      },350);
    };
    input.addEventListener('input',read);input.addEventListener('compositionend',read);
  }
  function chatDraftMusicInputs(draft){
    if(/\{music-all\}/i.test(draft))return {mode:'all'};
    const ids=Array.from(new Set(Array.from(draft.matchAll(/\{music-track:([A-Za-z0-9_-]{1,180})\}/gi),match=>match[1])));
    return ids.length||/\{music-clear\}/i.test(draft)?{mode:'selected',track_ids:ids}:null;
  }
  function chatDraftBindingLength(draft){
    const placed=Array.from(draft.matchAll(/\{image-(?:ref-)?[a-f0-9]{32}\}/gi)).slice(0,chatImageReferenceLimit);
    const references=Array.from(draft.matchAll(/\{reference-image-(?:ref-)?[a-f0-9]{32}\}/gi)).slice(0,chatImageReferenceLimit);
    const music=Array.from(draft.matchAll(/\{music-(?:track:[A-Za-z0-9_-]{1,180}|all|clear)\}/gi));
    const resources=Array.from(draft.matchAll(/\{(?:canvas:[1-9]\d*|lyrics:[A-Za-z0-9_-]{1,180}|me:[a-z][a-z0-9_]{0,63}|data:[a-z][a-z0-9-]{0,31}:(?=[a-z0-9_.]{3,64}:)[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*:[A-Za-z0-9_-]{1,100}|merch:[A-Za-z0-9_-]{1,180}|ticket:[A-Za-z0-9_-]{1,180}|playlist:(?:own|artist)-[1-9]\d{0,18}@[a-f0-9]{64}|story:[1-9]\d{0,18}|stories-(?:all|clear|tag:[a-z0-9-]{1,64})|lyrics-clear|me-clear|data-clear|playlists-clear|merch-clear|tickets-clear)\}/gi));
    return [...placed,...references,...music,...resources].reduce((length,match)=>length+match[0].length+1,0);
  }
  function syncChatImageDraftLimit(){
    if(chatImageDraftLimit<=0)return;
    input.maxLength=chatImageDraftLimit+chatDraftBindingLength(input.value);
  }
  function chatMusicCommandRange(draft){
    for(const match of draft.matchAll(/(^|[\s(])\/(music-all|music)(?=$|[\s),.!?;:])/gi)){
      const start=match.index+match[1].length,end=start+match[2].length+1;
      const standalone=draft.trim().toLowerCase()==='/'+match[2].toLowerCase();
      if(standalone||['create','revise','build_on'].includes(directListeningRoomCommand(draft)?.kind))
        return {start,end,standalone,all:match[2].toLowerCase()==='music-all'};
    }
    return null;
  }
  function applyChatMusicSelection(range,selection){
    const bindings=selection.mode==='all'?'{music-all}':selection.track_ids.map(id=>'{music-track:'+id+'}').join(' ')||'{music-clear}';
    const strip=value=>value.replace(/\{music-(?:track:[A-Za-z0-9_-]{1,180}|all|clear)\}/gi,'');
    const before=strip(input.value.slice(0,range.start)),after=strip(input.value.slice(range.end));
    const inserted=(range.standalone?"Let's build ":'')+bindings;
    input.value=before+inserted+after;
    syncChatImageDraftLimit();
    input.setSelectionRange(before.length+inserted.length,before.length+inserted.length);
  }
  async function openChatMusicPicker(range){
    if(chatMusicPicker||chatImagePicker||chatResourcePicker)return;
    const open=window.SFCanvasMusicPicker?.open;
    if(typeof open!=='function'){popupEntry('notice','Reload the page to open the music picker.');return;}
    const draft=input.value,owner=canvasOwner();
    const existing=chatDraftMusicInputs(draft)||
      (directListeningRoomCommand(draft)?.kind==='revise'?(Object.prototype.hasOwnProperty.call(listeningRoom||{},'direct_music_inputs')?listeningRoom.direct_music_inputs:listeningRoom?.music_inputs):null);
    const picker={owner,request:new AbortController()};
    chatMusicPicker=picker;
    try{
      const selection=await open({api,signal:picker.request.signal,selection:existing,selectedIds:existing?.mode==='selected'?existing.track_ids:[]});
      if(!selection||picker.request.signal.aborted||chatMusicPicker!==picker||canvasOwner()!==owner||input.value!==draft)return;
      applyChatMusicSelection(range,selection);
    }finally{
      if(chatMusicPicker===picker)chatMusicPicker=null;
      picker.request.abort();
    }
  }
  function bindChatMusicPicker(){
    const readDraft=event=>{
      clearTimeout(chatMusicCommandTimer);chatMusicCommandTimer=null;
      if(event?.isComposing||input.selectionStart!==input.selectionEnd)return;
      const draft=input.value,range=chatMusicCommandRange(draft),owner=canvasOwner();
      if(!range)return;
      const apply=()=>{
        chatMusicCommandTimer=null;
        if(input.value!==draft||canvasOwner()!==owner||chatImagePicker||chatMusicPicker||chatResourcePicker)return;
        if(range.all)applyChatMusicSelection(range,{mode:'all'});
        else void openChatMusicPicker(range);
      };
      if(range.end<draft.length)apply();
      else chatMusicCommandTimer=setTimeout(apply,350);
    };
    input.addEventListener('input',readDraft);
    input.addEventListener('compositionend',readDraft);
  }
  function chatImageCommandRange(draft,caret){
    const standalone=draft.trim().toLowerCase()==='/images';
    for(const match of draft.matchAll(/(^|[\s(])\/images(?=$|[\s),.!?;:])/gi)){
      const start=match.index+match[1].length,end=start+7;
      if(caret!==undefined&&caret!==end)continue;
      if(standalone||['create','revise','build_on'].includes(directListeningRoomCommand(draft)?.kind))
        return {start,end,standalone};
    }
    return null;
  }
  function closeChatImagePicker(picker,restoreFocus=true){
    if(chatImagePicker!==picker)return;
    chatImagePicker=null;
    picker.request?.abort();
    picker.lifetime?.abort();
    if(picker.dialog.open)picker.dialog.close();
    picker.dialog.remove();
    if(restoreFocus)input.focus({preventScroll:true});
  }
  function openChatImagePicker(range){
    if(chatImagePicker||chatMusicPicker||chatResourcePicker)return;
    setCanvasImageEditDraft(null);
    const draft=input.value,owner=canvasOwner();
    const existingByMode={place:chatDraftImageIds(draft),reference:new Set(chatDraftVisualReferenceIds(draft))};
    const dialog=document.createElement('dialog');
    dialog.id='chatImagePicker';
    dialog.className='sf-chat-image-picker';
    dialog.setAttribute('aria-labelledby','chatImagePickerTitle');
    dialog.innerHTML=`<section class="sf-chat-image-picker-panel">
      <header><h2 id="chatImagePickerTitle">Choose saved images</h2>
        <div class="sf-chat-image-picker-options" role="group" aria-label="Image source"><button type="button" data-image-picker-source="saved" aria-pressed="false" ${owner?'':'disabled'}>My images</button><button type="button" data-image-picker-source="reference" aria-pressed="false">Artist references</button></div>
        <div class="sf-chat-image-picker-options" role="group" aria-label="How to use images"><button type="button" data-image-picker-mode="place" aria-pressed="true">Place in canvas</button><button type="button" data-image-picker-mode="reference" aria-pressed="false">Use as visual reference</button></div>
        <p data-image-picker-help>Place the selected images in your canvas.</p>
        <form class="sf-chat-image-picker-search" data-image-picker-search role="search"><label>Search images<input type="search" name="query" maxlength="240" placeholder="Describe an image or search its visible text"></label><button type="submit">Search</button></form>
        <div class="sf-chat-image-picker-options" data-image-picker-private ${owner?'':'hidden'}><button type="button" data-image-picker-upload>Upload photo</button><button type="button" data-image-picker-mode="analysis" aria-pressed="false">Select for analysis</button><button type="button" data-image-picker-batch>Batch analysis</button><button type="button" data-image-picker-preferences>Photo preferences</button><input type="file" data-image-picker-photo accept="image/jpeg,image/png,image/webp" hidden></div></header>
      <div class="sf-chat-image-picker-body"><p class="sf-chat-image-picker-status" role="status"></p><ul aria-label="Saved images"></ul><button type="button" data-image-picker-more hidden>Load more</button></div>
      <footer><span class="sf-chat-image-picker-count" aria-live="polite"></span><div><button type="button" data-image-picker-cancel autofocus>Cancel</button><button type="button" data-image-picker-add disabled>Add images</button></div></footer>
    </section>`;
    const status=$('.sf-chat-image-picker-status',dialog),list=$('ul',dialog),count=$('.sf-chat-image-picker-count',dialog);
    const more=$('[data-image-picker-more]',dialog),add=$('[data-image-picker-add]',dialog),cancel=$('[data-image-picker-cancel]',dialog);
    const help=$('[data-image-picker-help]',dialog),search=$('[data-image-picker-search]',dialog),searchInput=$('input[type="search"]',search);
    const privateControls=$('[data-image-picker-private]',dialog),upload=$('[data-image-picker-upload]',dialog),photoInput=$('[data-image-picker-photo]',dialog);
    const picker={dialog,owner,request:null,lifetime:new AbortController()};
    const selected=new Set();
    const sources={saved:{rows:new Map(),cursor:null,loaded:false,failed:false},reference:{rows:new Map(),cursor:null,loaded:false,failed:false}};
    let source=owner?'saved':'reference',mode='place',query='',uploading=false;
    const active=()=>chatImagePicker===picker&&canvasOwner()===owner;
    const analysisApi=(path,body,signal)=>body===undefined?api(path,undefined,signal):secureJsonPost(path,body,false,signal);
    const selectionExisting=()=>mode==='analysis'?new Set():existingByMode[mode];
    const selectionLimit=()=>mode==='analysis'?200:chatImageReferenceLimit;
    const syncSelection=()=>{
      const existing=selectionExisting(),remaining=Math.max(0,selectionLimit()-existing.size);
      count.textContent=existing.size?`${selected.size} selected · ${existing.size} already in draft`:`${selected.size} of ${remaining} selected`;
      add.disabled=selected.size===0;
      add.textContent=mode==='analysis'?'Analyze selected':mode==='reference'?'Add references':'Add images';
      help.textContent=mode==='analysis'?'Select your images for a saved analysis batch. You can pause and review the results.':mode==='reference'?'Use the selected images to guide newly generated artwork.':'Place the selected images in your canvas.';
      for(const inventory of Object.values(sources))for(const [id,row] of inventory.rows){
        row.checkbox.checked=existing.has(id)||selected.has(id);
        row.checkbox.disabled=existing.has(id)||(!selected.has(id)&&selected.size>=remaining);
      }
      for(const button of $$('[data-image-picker-mode]',dialog))button.setAttribute('aria-pressed',String(button.dataset.imagePickerMode===mode));
    };
    const renderSource=()=>{
      const inventory=sources[source],empty=query?'No images match this search.':source==='reference'?'No artist references are available yet.':'No saved images yet.';
      list.replaceChildren(...Array.from(inventory.rows.values(),row=>row.item));
      list.setAttribute('aria-label',source==='reference'?'Artist reference images':'Saved images');
      privateControls.hidden=!owner||source!=='saved';
      for(const button of $$('[data-image-picker-source]',dialog))button.setAttribute('aria-pressed',String(button.dataset.imagePickerSource===source));
      status.textContent=inventory.failed?'Images could not be loaded.':inventory.loaded?(inventory.rows.size?'':empty):'Loading images…';
      more.textContent=inventory.failed?'Try again':'Load more';more.hidden=!inventory.failed&&!inventory.cursor;
      syncSelection();
    };
    const renderImageMetadata=(row,image)=>{
      row.image=image;
      const analysis=image.image_analysis||{};
      row.description.textContent=typeof analysis.description==='string'?analysis.description.slice(0,4000):'';
      row.details.replaceChildren();
      const fields=[['Description',analysis.description],['Visible text / OCR',analysis.visible_text],['Objects',analysis.objects],['Scene',analysis.scene],['Style',analysis.style],['Colors',analysis.colors],['Tags',analysis.tags],['Uncertainties',analysis.uncertainties],['Caption',image.caption],['Credit',image.credit]];
      const entries=fields.filter(([,value])=>typeof value==='string'?value.trim():Array.isArray(value)&&value.length);
      if(entries.length){
        const summary=document.createElement('summary'),metadata=document.createElement('dl');
        summary.textContent='Image details';
        for(const [label,value] of entries){
          const term=document.createElement('dt'),definition=document.createElement('dd');
          term.textContent=label;
          definition.textContent=Array.isArray(value)?value.filter(item=>typeof item==='string').join(', '):value;
          metadata.append(term,definition);
        }
        row.details.append(summary,metadata);
      }
      row.details.hidden=entries.length===0;
    };
    const analyzeImage=image=>{
      if(!active()||!owner||!window.SFCanvasImageAnalysis?.open)return;
      window.SFCanvasImageAnalysis.open(image,{api:analysisApi,signal:picker.lifetime.signal,imageUrl:asset=>sceneImagePath(asset.id),onSaved:saved=>{
        if(!active()||saved?.id!==image.id)return;
        for(const inventory of Object.values(sources)){
          const row=inventory.rows.get(saved.id);
          if(row)renderImageMetadata(row,saved);
        }
      }});
    };
    const appendImage=(inventory,image,loadingSource)=>{
      const id=image?.id;
      if(typeof id!=='string'||!chatImageId.test(id)||(id.startsWith('ref-')!==(loadingSource==='reference'))||inventory.rows.has(id))return;
      const url=new URL(sceneImagePath(id),document.baseURI);
      if(url.origin!==window.location.origin)return;
      const item=document.createElement('li'),label=document.createElement('label');
      const preview=document.createElement('img'),title=document.createElement('span'),checkbox=document.createElement('input');
      const description=document.createElement('p'),details=document.createElement('details');
      description.className='sf-chat-image-picker-description';details.className='sf-chat-image-picker-details';
      preview.alt='';preview.loading='lazy';preview.decoding='async';preview.src=url.href;
      title.textContent=(typeof image.title==='string'&&image.title.trim().slice(0,160))||(loadingSource==='reference'?'Artist reference':'Saved image');
      checkbox.type='checkbox';checkbox.setAttribute('aria-label',title.textContent);
      checkbox.addEventListener('change',()=>{
        if(!active())return;
        const remaining=Math.max(0,selectionLimit()-selectionExisting().size);
        if(checkbox.checked&&selected.size<remaining)selected.add(id);
        else selected.delete(id);
        syncSelection();
      });
      label.append(preview,checkbox,title);item.append(label,description,details);
      const row={item,checkbox,description,details,image};
      renderImageMetadata(row,image);
      if(loadingSource==='saved'){
        const analyze=document.createElement('button');
        analyze.type='button';analyze.textContent='Analyze image';analyze.setAttribute('aria-label','Analyze image '+title.textContent);
        analyze.addEventListener('click',()=>analyzeImage(row.image));item.append(analyze);
      }
      inventory.rows.set(id,row);
    };
    const load=async()=>{
      if(!active()||picker.request||(source==='saved'&&!owner))return;
      const loadingSource=source,inventory=sources[loadingSource],request=new AbortController();
      picker.request=request;more.disabled=true;status.textContent='Loading images…';
      try{
        const params=new URLSearchParams();
        if(loadingSource==='reference')params.set('scope','reference');
        if(query)params.set('q',query);
        if(inventory.cursor)params.set('before',inventory.cursor);
        const path='agent-canvas-image.php'+(params.size?'?'+params.toString():'');
        if(new URL((cfg.apiBase||'api')+'/'+path,document.baseURI).origin!==window.location.origin)
          throw new Error('Image inventory must use the site origin.');
        const page=await api(path,undefined,request.signal);
        if(!active()||request.signal.aborted)return;
        if(!Array.isArray(page?.images))throw new Error('Invalid image inventory.');
        for(const image of page.images)appendImage(inventory,image,loadingSource);
        inventory.cursor=typeof page.next_cursor==='string'&&chatImageId.test(page.next_cursor)&&page.next_cursor!==inventory.cursor?page.next_cursor:null;
        inventory.loaded=true;inventory.failed=false;
      }catch(_){
        if(!active()||request.signal.aborted)return;
        inventory.failed=true;
      }finally{
        if(picker.request===request){picker.request=null;more.disabled=false;}
        if(active()&&!request.signal.aborted&&source===loadingSource)renderSource();
      }
    };
    const resetInventories=()=>{
      picker.request?.abort();picker.request=null;more.disabled=false;
      for(const inventory of Object.values(sources)){
        inventory.rows.clear();inventory.cursor=null;inventory.loaded=false;inventory.failed=false;
      }
    };
    const searchImages=()=>{
      if(!active())return;
      query=searchInput.value.trim();resetInventories();renderSource();void load();
    };
    chatImagePicker=picker;
    cancel.addEventListener('click',()=>closeChatImagePicker(picker));
    dialog.addEventListener('cancel',event=>{event.preventDefault();closeChatImagePicker(picker);});
    dialog.addEventListener('click',event=>{if(event.target===dialog)closeChatImagePicker(picker);});
    dialog.addEventListener('keydown',event=>event.stopPropagation());
    search.addEventListener('submit',event=>{event.preventDefault();event.stopPropagation();searchImages();});
    searchInput.addEventListener('input',()=>{if(!searchInput.value&&query)searchImages();});
    for(const button of $$('[data-image-picker-source]',dialog))button.addEventListener('click',()=>{
      const next=button.dataset.imagePickerSource;
      if(!active()||source===next||(next==='saved'&&!owner))return;
      picker.request?.abort();picker.request=null;more.disabled=false;source=next;
      if(source==='reference'&&mode==='analysis'){mode='place';selected.clear();}
      renderSource();if(!sources[source].loaded)void load();
    });
    for(const button of $$('[data-image-picker-mode]',dialog))button.addEventListener('click',()=>{
      if(!active()||(button.dataset.imagePickerMode==='analysis'&&(!owner||source!=='saved')))return;
      mode=button.dataset.imagePickerMode;
      const existing=selectionExisting(),remaining=Math.max(0,selectionLimit()-existing.size);
      for(const id of selected)if(existing.has(id)||(mode==='analysis'&&id.startsWith('ref-')))selected.delete(id);
      while(selected.size>remaining)selected.delete(Array.from(selected).at(-1));
      syncSelection();
    });
    const openBatch=selectedIds=>{
      if(!active()||!owner||!window.SFCanvasImageAnalysis?.openBatch)return;
      window.SFCanvasImageAnalysis.openBatch({api:analysisApi,signal:picker.lifetime.signal,
        imageUrl:asset=>sceneImagePath(asset.id),selectedIds,onSaved:()=>{
          if(!active())return;
          resetInventories();renderSource();void load();
        }});
    };
    $('[data-image-picker-batch]',dialog).addEventListener('click',()=>openBatch([]));
    $('[data-image-picker-preferences]',dialog).addEventListener('click',()=>{
      if(active()&&owner)window.SFCanvasImageAnalysis?.openPreferences({api:analysisApi,signal:picker.lifetime.signal,imageUrl:asset=>sceneImagePath(asset.id)});
    });
    upload.addEventListener('click',()=>{if(active()&&owner&&!uploading)photoInput.click();});
    photoInput.addEventListener('change',async()=>{
      const file=photoInput.files?.[0];photoInput.value='';
      if(!file||!active()||!owner||uploading)return;
      uploading=true;upload.disabled=true;status.textContent='Uploading photo…';
      try{
        const image=await uploadCanvasPhoto(file,{signal:picker.lifetime.signal});
        if(!active())return;
        source='saved';query='';searchInput.value='';resetInventories();renderSource();
        await load();
        if(active()&&image?.id)analyzeImage(image);
      }catch(error){
        if(active()&&!picker.lifetime.signal.aborted)status.textContent=error.message||'Photo could not be uploaded.';
      }finally{uploading=false;upload.disabled=false;}
    });
    more.addEventListener('click',()=>void load());
    add.addEventListener('click',()=>{
      if(!active()||!selected.size)return;
      if(mode==='analysis'){openBatch(Array.from(selected).filter(id=>!id.startsWith('ref-')));return;}
      if(input.value!==draft){closeChatImagePicker(picker);return;}
      const prefix=mode==='reference'?'reference-image-':'image-';
      const refs=Array.from(selected,id=>'{'+prefix+id+'}').join(' ');
      input.setRangeText((range.standalone?"Let's build ":'')+refs,range.start,range.end,'end');
      syncChatImageDraftLimit();
      closeChatImagePicker(picker);
    });
    document.body.append(dialog);
    dialog.showModal();renderSource();void load();
  }
  function bindChatImagePicker(){
    chatImageDraftLimit=input.maxLength;
    input.addEventListener('paste',event=>{
      if(chatImageDraftLimit<=0||!event.clipboardData)return;
      const draft=input.value.slice(0,input.selectionStart)+event.clipboardData.getData('text')+input.value.slice(input.selectionEnd);
      if(!/\{(?:music-(?:track:[A-Za-z0-9_-]{1,180}|all|clear)|canvas:[1-9]\d*|lyrics:[A-Za-z0-9_-]{1,180}|me:[a-z][a-z0-9_]{0,63}|data:[a-z][a-z0-9-]{0,31}:(?=[a-z0-9_.]{3,64}:)[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*:[A-Za-z0-9_-]{1,100}|merch:[A-Za-z0-9_-]{1,180}|ticket:[A-Za-z0-9_-]{1,180}|playlist:(?:own|artist)-[1-9]\d{0,18}@[a-f0-9]{64}|story:[1-9]\d{0,18}|stories-(?:all|clear|tag:[a-z0-9-]{1,64})|lyrics-clear|me-clear|data-clear|playlists-clear|merch-clear|tickets-clear)\}|\/music-all(?=$|[\s),.!?;:])/i.test(draft))return;
      input.maxLength=chatImageDraftLimit+chatDraftBindingLength(draft);
      setTimeout(syncChatImageDraftLimit,0);
    });
    const readDraft=()=>{
      syncChatImageDraftLimit();
      if(input.selectionStart!==input.selectionEnd)return;
      const range=chatImageCommandRange(input.value,input.selectionEnd);
      if(range)openChatImagePicker(range);
    };
    input.addEventListener('input',event=>{if(!event.isComposing)readDraft();});
    input.addEventListener('compositionend',readDraft);
  }
  function submitAgentChat(event){
    event.preventDefault();
    clearTimeout(chatMusicCommandTimer);chatMusicCommandTimer=null;
    clearTimeout(chatResourceCommandTimer);chatResourceCommandTimer=null;
    const resourceRange=chatResourceCommandRange(input.value);
    if(resourceRange){void openChatResourcePicker(resourceRange);return;}
    const imageRange=chatImageCommandRange(input.value);
    if(imageRange){openChatImagePicker(imageRange);return;}
    const musicRange=chatMusicCommandRange(input.value);
    if(musicRange){
      if(!musicRange.all){void openChatMusicPicker(musicRange);return;}
      applyChatMusicSelection(musicRange,{mode:'all'});
      if(musicRange.standalone)return;
    }
    if(input.value.trim().toLowerCase()==='ddd'&&toggleCanvasDebug()){input.value='';return;}
    if(handleAgentVisibilityCommand(input.value)){input.value='';return;}
    if(voiceTurnBusy){showTranscript('Finishing the previous voice reply. Your typed message is still in the bar.','waiting');return;}
    if(chatImageDraftLimit>0)input.maxLength=chatImageDraftLimit;
    voiceInteractionStarted=true;
    command(input.value).catch(err=>say('I could not process that message. '+err.message));
  }
  function directListeningRoomCommand(message){
    const text=String(message||'').trim()
      .replace(/^(?:(?:hey|hi|ok|okay)\s+)?stone[\s-]?fellow[,.:!?\s]*/i,'')
      .replace(/^please\s+/i,'');
    const edit=text.match(/^\/edit(?:\s+([\s\S]*)|$)/i);
    if(edit)return {kind:'revise',brief:(edit[1]||'').trim()};
    const command=text.replace(/[.!?…]+$/,'').trim()
      .replace(/^(?:(?:can|could|would|will)\s+you|i(?:['’]d|\s+would)\s+like\s+you\s+to)\s+(?:please\s+)?/i,'');
    const assetReference=/\{\s*(?:canvas:|lyrics:|me:|data:|merch:|ticket:|playlist:|story:|stories-(?:all|clear|tag:)|lyrics-clear|me-clear|data-clear|playlists-clear|merch-|tickets-clear|show-|price-|media-|image-|reference-image-|music-)|\[\[\s*(?:merch|show|ticket|price|media)[:-]/i;
    if(/^\/review-images$/i.test(text))return {kind:'image_review'};
    const build=command.match(/^let['’]?s\s+build(?:\s*[:,]\s*|\s+|$)([\s\S]*)$/i);
    if(build){
      const brief=build[1].trim();
      if(!brief)return {kind:'suggest'};
      const earlier=brief.match(/^on\s+(.+?)(?:\s*[:,]\s*(.+))?$/i);
      if(earlier)return {kind:'build_on',target:earlier[1].replace(/^my\s+/i,'').trim(),brief:(earlier[2]||'').trim()};
      if(assetReference.test(brief))
        return {kind:'create',canvasKind:'custom',brief};
      const design=canvasDesignBrief(brief);
      const room=design.match(/^(?:(?:a|the)\s+)?(listening room|canvas\s+1)(?:\s+for\s+(.+))?$/i);
      if(room)return {kind:'create',canvasKind:'listening_room',subject:(room[2]||'').trim(),
        ...(/^canvas/i.test(room[1])?{requiresTrack:true}:{}),...(brief!==design?{brief}:{})};
      const example=design.match(/^(?:(?:a|the)\s+)?space eq example(?:\s+for\s+(.+))?$/i);
      if(example)return {kind:'create',canvasKind:'space_eq',subject:(example[1]||'').trim(),brief};
      return {kind:'create',canvasKind:'custom',brief};
    }
    if(/^(?:what (?:should|could|can) we build(?: next)?|suggest (?:a |some )?(?:new )?canvas(?: ideas?| experiences?)?)$/i.test(command))return {kind:'suggest'};
    if(/^(?:close|hide|exit)\s+(?:(?:this|the|my)\s+)?(?:listening room|(?:space eq|canvas))$/i.test(command))return {kind:'close'};
    if(/^(?:open|show|view)\s+(?:me\s+)?(?:my\s+|the\s+)?(?:canvas|listening room)\s+history$/i.test(command))return {kind:'history'};
    const revision=command.match(/^(?:revise|update|change)\s+(?:(?:this|the|my)\s+)?(?:listening room|canvas|space eq)(?:(?:\s*:\s*|\s+)(.*))?$/i);
    if(revision)return {kind:'revise',brief:(revision[1]||'').trim()};
    const design=canvasDesignBrief(command);
    const create=design.match(/^(?:build|create|make|open|try|design)\s+(?:me\s+)?(?:(?:a|the|my)\s+)?(listening room|canvas\s+1)(?:\s+for\s+(.+))?$/i);
    if(create)return {kind:'create',subject:(create[2]||'').trim(),
      ...(/^canvas/i.test(create[1])?{requiresTrack:true}:{}),...(command!==design?{brief:command}:{})};
    if(/^(?:show|display|open|use|reuse)\s/i.test(command)&&/\{(?:reference-)?image-(?:ref-)?[a-f0-9]{32}\}/i.test(command))
      return {kind:'create',canvasKind:'custom',brief:command};

    const request=command.match(/^(build|create|make|design|draw|generate|render)\s+(?:me\s+)?([\s\S]+)$/i);
    if(!request)return null;
    const brief=request[2].trim();
    const subject=canvasDesignBrief(brief).split(/\s+(?:about|around|based on|featuring|for|from|inspired by|showing|that|to|using|with)\b|[,.!?;:\r\n]/i,1)[0];
    const imageSubject=/\b(?:illustrations?|images?|photos?|pictures?)\b/i.test(subject);
    const visual=imageSubject||/\b(?:animation|backdrop|background|canvas|dashboard|design|diagram|display|eq|equalizer|gallery|graphics?|interface|layout|listening room|mockup|page|panel|posters?|scene|ui|visual|visuali[sz]er|wallpaper|webpage|window)\b/i.test(subject);
    const native=/\b(?:account|album|cassette|checkout|collection|guide|list|mix|mixtape|order|plan|playlist|purchase|queue|receipt|record|reservation|song|subscription|ticket|track|vinyl)\b/i.test(subject);
    const creative=/^(?:build|create|design|draw|render)$/i.test(request[1])&&/^(?:a|an|the)\s+\S/i.test(subject)&&!native;
    return visual||creative||assetReference.test(brief)?{kind:'create',canvasKind:'custom',brief:imageSubject?command:brief}:null;
  }
  function canvasBriefInChat(text){
    const action=directListeningRoomCommand(text);
    return ['create','revise','build_on'].includes(action?.kind);
  }
  function canvasLyricSection(brief){
    if(/\bchorus\b/i.test(brief))return 'chorus';
    if(/\bverse\b/i.test(brief))return 'verse';
    if(/\b(?:(?:this|the|its|song['’]s|track['’]s)\s+bridge|bridge\s+lyrics?)\b/i.test(brief))return 'bridge';
    return /\blyrics?\b/i.test(brief)?'whole':null;
  }
  function canvasTrackTarget(brief){
    const namedLyrics=brief.match(/\b(?:lyrics?|chorus|verse|bridge)\s+(?:of|from)\s+(.+?)(?:\s+(?:using|with|as)\s+.+)?$/is);
    const namedTrack=brief.match(/\bfor\s+(.+?)(?:\s+(?:using|with)\s+(?:its|the|this)\s+(?:lyrics?|chorus|verse|bridge)\b.*)?$/is);
    const target=(namedLyrics||namedTrack)?.[1];
    const isKnown=title=>{
      const name=norm(title);
      return canvasCurrentTrack(title)||catalog.some(track=>norm(track.title)===name);
    };
    if(!target||isKnown(target))return target;
    // Keep a catalog title intact when it includes punctuation of its own.
    let named;
    for(const boundary of target.matchAll(/[.!?…]+(?=\s)|[\r\n]/g)){
      const title=target.slice(0,boundary.index).trim();
      if(isKnown(title))named=title;
    }
    return named??target;
  }
  function canvasCurrentTrack(target){
    return /^(?:(?:this|the)(?: current| playing| currently playing)?|current|playing|currently playing) (?:song|track)$/.test(norm(target));
  }
  function canvasTargetMatches(scene,target){
    const name=norm(target);
    const kind=scene?.canvas_kind||'listening_room';
    return !!scene&&(norm(scene.title)===name||
      (kind==='space_eq'&&['space eq','space equalizer'].includes(name))||
      (kind==='listening_room'&&['canvas 1','listening room'].includes(name)));
  }
  let canvasGalleryView=null,gallerySceneRequest=null,gallerySceneSequence=0;
  function canvasOwner(){
    return st.auth.authenticated&&st.auth.user?.id?String(st.auth.user.id):'';
  }
  function isGalleryScene(scene=listeningRoom){
    return !!scene&&typeof scene.gallery_id==='string'&&/^[a-f0-9]{32}$/.test(scene.gallery_id)&&!Number.isSafeInteger(scene.id);
  }
  function canvasSceneOwner(){return canvasOwner()||(isGalleryScene()?'gallery-guest':'');}
  function galleryRequestId(){return Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');}
  function cancelGalleryOpen(){gallerySceneSequence++;gallerySceneRequest?.abort();gallerySceneRequest=null;}
  function galleryQuery(values){const query=new URLSearchParams();for(const [key,value] of Object.entries(values))if(value!==undefined&&value!==null&&value!=='')query.set(key,String(value));return query;}
  async function galleryRead(id,signal){
    if(!/^[a-f0-9]{32}$/.test(id||''))throw new Error('Choose a published canvas.');
    return api('canvas-gallery.php?'+galleryQuery({action:'open',id,current_track_id:observedPlayer().track?.id||''}),undefined,signal);
  }
  async function requirePublishedCanvas(scene,signal){
    if(!isGalleryScene(scene))return;
    try{
      const result=await galleryRead(scene.gallery_id,signal);
      if(result.scene?.gallery_id!==scene.gallery_id)throw new Error('This published canvas is unavailable.');
    }catch(error){
      if(scene===listeningRoom&&error.name!=='AbortError'&&error.status===404){closeListeningRoom(false);popupEntry('notice','This canvas is no longer in the public catalog.');}
      throw error;
    }
  }
  function canvasAccess(){
    if(canvasAccess.current)return canvasAccess.current;
    if(!window.SFCanvasAccess?.create)return null;
    canvasAccess.current=window.SFCanvasAccess.create({
      getOwner:canvasOwner,getScene:()=>listeningRoom,isBusy:()=>!!listeningRoomRequest,
      readMine:signal=>api('canvas-access.php?mine=1',undefined,signal),
      read:(context,signal)=>api('canvas-access.php?'+new URLSearchParams(context).toString(),undefined,signal),
      write:(payload,signal)=>secureJsonPost('canvas-access.php',payload,false,signal),
      onSignIn:()=>navigate('login'),
      onRefresh:async(scene,signal)=>{
        if(!scene)return;
        const owner=canvasOwner(),stage=window.SFPublicAgentCanvas;
        const active=()=>!signal.aborted&&canvasOwner()===owner&&listeningRoom===scene&&!listeningRoomRequest&&stage?.active?.();
        if(!active())return;
        const result=isGalleryScene(scene)?await galleryRead(scene.gallery_id,signal):await api('agent-scene.php?id='+encodeURIComponent(scene.id)+'&current_track_id='+encodeURIComponent(observedPlayer().track?.id||''),undefined,signal);
        if(!active())return;
        const next=result.scene;
        if(!next||(isGalleryScene(scene)?next.gallery_id!==scene.gallery_id:next.id!==scene.id))throw new Error('The canvas access response changed. Reopen this canvas.');
        await stage.prepareSceneLibraries?.(next,{signal});
        if(!active())return;
        if(stage.showScene(next,{focus:false})){listeningRoom=next;listeningRoomOwner=canvasSceneOwner();}
        else throw new Error('The updated canvas could not display. Reopen this canvas.');
      },
    });
    return canvasAccess.current;
  }
  document.getElementById('menuCanvasPermissions')?.addEventListener('click',()=>{closeMenu();void canvasAccess()?.manage();});
  async function openGalleryCanvas(item,signal){
    const id=item?.id,viewer=canvasOwner();
    cancelGalleryOpen();
    const sequence=gallerySceneSequence,request=new AbortController();request.galleryOwner=viewer;gallerySceneRequest=request;
    const abort=()=>request.abort();signal?.addEventListener('abort',abort,{once:true});if(signal?.aborted)abort();
    const active=()=>!request.signal.aborted&&sequence===gallerySceneSequence&&viewer===canvasOwner()&&!listeningRoomRequest;
    try{
      if(listeningRoomRequest)throw new Error('Finish or stop the current build before opening another canvas.');
      const response=await galleryRead(id,request.signal);
      if(!active())return;
      const scene=response.scene;
      if(scene?.gallery_id!==id||scene.canvas_kind!=='custom'||Number.isSafeInteger(scene.id))throw new Error('The catalog returned an invalid canvas.');
      if(!window.SFPublicAgentCanvas?.active?.())await renderAgentCanvas();
      const stage=window.SFPublicAgentCanvas;
      await stage?.prepareSceneLibraries?.(scene,{signal:request.signal});
      if(!active())return;
      const previous=listeningRoom,previousOwner=listeningRoomOwner;
      listeningRoom=scene;listeningRoomOwner=canvasSceneOwner();
      if(!stage?.showScene?.(scene,{focus:false})){listeningRoom=previous;listeningRoomOwner=previousOwner;throw new Error('The published canvas could not open.');}
      stage.setSceneWorkflow?.(null);stage.closePage?.();canvas=mainCanvas;
      selectCanvasId(viewer,null);setUrl('home',{});
      popupEntry('notice','Opened “'+String(item.title||scene.title)+'” by '+String(item.creator_label||'a Stonefellow creator')+'. Remix it to make your own version.');
    }finally{
      signal?.removeEventListener('abort',abort);if(gallerySceneRequest===request)gallerySceneRequest=null;
    }
  }
  async function remixGalleryCanvas(item,signal){
    const owner=canvasOwner();
    if(!owner){await navigate('login');return;}
    if(listeningRoomRequest)throw new Error('Finish or stop the current build before remixing.');
    cancelGalleryOpen();
    const sequence=gallerySceneSequence,request=new AbortController();request.galleryOwner=owner;gallerySceneRequest=request;
    const abort=()=>request.abort();signal?.addEventListener('abort',abort,{once:true});if(signal?.aborted)abort();
    const active=()=>!request.signal.aborted&&sequence===gallerySceneSequence&&owner===canvasOwner()&&!listeningRoomRequest;
    try{
      const result=await secureJsonPost('canvas-gallery.php',{action:'remix',id:item.id,request_id:galleryRequestId()},false,request.signal);
      if(!active())return;
      if(!Number.isSafeInteger(result.scene?.id)||result.scene.id<1)throw new Error('The private remix could not be confirmed.');
      if(!window.SFPublicAgentCanvas?.active?.())await renderAgentCanvas();
      if(!active())return;
      await handleListeningRoomCommand({kind:'restore',id:result.scene.id});
      if(owner===canvasOwner()&&listeningRoom?.id===result.scene.id){window.SFPublicAgentCanvas?.closePage?.();canvas=mainCanvas;setUrl('home',{});popupEntry('notice','Saved your private remix. Use /edit followed by your changes.');}
    }finally{signal?.removeEventListener('abort',abort);if(gallerySceneRequest===request)gallerySceneRequest=null;}
  }
  async function renderCanvasGallery(params={}){
    canvasGalleryView?.destroy();
    const host=canvas;
    if(!window.SFCanvasGallery?.create)throw new Error('The canvas catalog is unavailable. Refresh this page.');
    canvasGalleryView=window.SFCanvasGallery.create({
      getOwner:canvasOwner,getCreatorLabel:()=>st.auth.user?.display_name||'',onSignIn:()=>navigate('login'),
      api:{
        list:(query,signal)=>api('canvas-gallery.php?'+galleryQuery({action:'list',...query}),undefined,signal),
        detail:(id,signal)=>api('canvas-gallery.php?'+galleryQuery({id}),undefined,signal),
        mine:(query,signal)=>api('canvas-gallery.php?'+galleryQuery({action:'mine',...query}),undefined,signal),
        sources:signal=>api('canvas-resources.php?kind=canvas',undefined,signal),
        source:(id,signal)=>api('agent-scene.php?id='+encodeURIComponent(id),undefined,signal),
        submit:(payload,signal)=>secureJsonPost('canvas-gallery.php',{action:'submit',...payload},false,signal),
        withdraw:async(id,signal)=>{const result=await secureJsonPost('canvas-gallery.php',{action:'withdraw',id},false,signal);if(isGalleryScene()&&listeningRoom.gallery_id===id)closeListeningRoom(false);return result;},
      },getPreviewUrl:sceneImagePath,getItemUrl:item=>{const url=new URL(location.href);url.search='';url.hash='';url.searchParams.set('view','canvas-gallery');url.searchParams.set('id',item.id);return url.href;},onOpen:openGalleryCanvas,onRemix:remixGalleryCanvas,
    });
    await canvasGalleryView.mount(host,{view:['mine','submit'].includes(params.tab)?params.tab:'public',sceneId:Number(params.id)||undefined,itemId:/^[a-f0-9]{32}$/.test(params.id||'')?params.id:undefined});
  }
  const canvasDebugEvents=[];
  let canvasDebugOwner='';
  const canvasDebugElement=id=>document.getElementById?.(id);
  function syncCanvasDebugOwner(){
    const owner=canvasOwner();
    if(owner===canvasDebugOwner)return;
    canvasDebugOwner=owner;
    canvasDebugEvents.length=0;
    const status=canvasDebugElement('canvasDebugStatus');
    if(status)status.textContent='';
    renderCanvasDebug();
  }
  function renderCanvasDebug(){
    const report=canvasDebugElement('canvasDebugReport');
    if(!report)return;
    const build=window.STONEFELLOW_APP_BUILD;
    const heading='Stonefellow canvas debug'+(/^\d+\.\d+\.\d+$/.test(build)?' · v'+build:'');
    const entries=canvasDebugEvents.map(({at,id,event,data})=>
      `${new Date(at).toISOString()} ${id} ${event}${Object.keys(data).length?' '+JSON.stringify(data):''}`);
    report.value=[heading,...(entries.length?entries:['No canvas builds captured in this tab yet.'])].join('\n');
    report.scrollTop=report.scrollHeight;
  }
  function recordCanvasDebug(id,event,data={}){
    if(!/^[a-f0-9]{32}$/.test(id||''))return;
    syncCanvasDebugOwner();
    if(!canvasDebugOwner)return;
    canvasDebugEvents.push({at:Date.now(),id,event,data});
    if(canvasDebugEvents.length>60)canvasDebugEvents.shift();
    if(canvasDebugVisible())renderCanvasDebug();
  }
  function canvasDebugReason(reason){
    if(typeof reason==='string'&&/^http_[45]\d{2}$/.test(reason))return reason;
    return ['timeout','stream_incomplete','output_limit','provider_busy','provider_refused','provider_unavailable',
      'invalid_json','invalid_markup','output_too_large','missing_player_slot','invalid_code','invalid_library','invalid_image_request','invalid_image_binding',
      'image_provider_unavailable','image_daily_limit','image_account_disabled','image_generation_failed','image_storage_failed','image_generation_disabled',
      'image_already_started'].includes(reason)?reason:undefined;
  }
  function canvasDebugFailure(error){
    const code=error.payload?.error||(error.code==='canvas_library_unavailable'?error.code:undefined);
    const codes=['authentication_required','csrf_failed','scene_coding_model_unavailable','invalid_scene_request',
      'scene_provider_required','scene_model_unavailable','scene_image_unavailable','invalid_scene_output','active_tokens_required',
      'scene_unavailable','scene_data_unavailable','canvas_library_unavailable','response_encoding_failed','scene_request_not_found','scene_request_too_large','invalid_json'];
    return {code:codes.includes(code)?code:error.name==='TypeError'?'network_error':'canvas_request_failed',
      ...(Number.isInteger(error.status)&&error.status>=100&&error.status<600?{status:error.status}:{}),
      reason:canvasDebugReason(error.payload?.reason)};
  }
  function canvasDebugVisible(){
    return canvasDebugElement('canvasDebugPanel')?.hidden===false;
  }
  function setCanvasDebugVisible(visible){
    const panel=canvasDebugElement('canvasDebugPanel');
    if(!panel)return;
    panel.hidden=!visible;
    document.body.classList.toggle('sf-canvas-debug-open',visible);
    if(visible){syncCanvasDebugOwner();renderCanvasDebug();}
    const status=canvasDebugElement('canvasDebugStatus');
    if(status)status.textContent='';
  }
  function toggleCanvasDebug(){
    if(!canvasDebugElement('canvasDebugPanel'))return false;
    const visible=!canvasDebugVisible();
    setCanvasDebugVisible(visible);
    if(visible){setChatFooterHidden(false);void openChatPopup();}
    syncAgentDockLayout();
    return true;
  }
  async function copyCanvasDebug(){
    const report=canvasDebugElement('canvasDebugReport'),status=canvasDebugElement('canvasDebugStatus');
    if(!report||!status)return;
    if(navigator.clipboard?.writeText){
      try{await navigator.clipboard.writeText(report.value);status.textContent='Debug report copied.';return;}
      catch(_){/* The selected report supports manual copying when clipboard access is blocked. */}
    }
    report.focus({preventScroll:true});
    report.select();
    try{if(document.execCommand?.('copy')){status.textContent='Debug report copied.';return;}}
    catch(_){/* Keep the report selected for manual copying. */}
    status.textContent='Report selected. Use your device’s copy command.';
  }
  function bindCanvasDebug(){
    const copy=canvasDebugElement('canvasDebugCopy');
    if(!copy)return;
    copy.onclick=copyCanvasDebug;
    let count=0,last=0;
    window.addEventListener('keydown',event=>{
      if(event.defaultPrevented||event.repeat||event.isComposing||event.ctrlKey||event.metaKey||event.altKey||
        event.target?.closest?.('input,textarea,select,[contenteditable],[role="textbox"]')||event.key.toLowerCase()!=='d'){
        count=0;return;
      }
      const now=Date.now();
      count=now-last<1000?count+1:1;
      last=now;
      if(count===3){count=0;if(toggleCanvasDebug())event.preventDefault();}
    },true);
  }
  let codingModelMemory=null,codingModelMenuOwner='',codingModelMenuRequest=null,codingModelMenuLoadedAt=0;
  function canvasCodingModelKey(owner){
    const apiPath=new URL(cfg.apiBase||'api',document.baseURI).pathname;
    return 'stonefellow.codingModel.'+apiPath+':'+owner;
  }
  function canvasCodingModel(value){
    if(!['openai','anthropic'].includes(value?.provider)||typeof value.model!=='string'||
      !value.model||value.model.length>160||/\s/.test(value.model))return null;
    return {provider:value.provider,model:value.model};
  }
  function selectedCanvasCodingModel(owner){
    if(!owner)return null;
    if(codingModelMemory?.owner===owner)return codingModelMemory.choice;
    try{return canvasCodingModel(JSON.parse(localStorage.getItem(canvasCodingModelKey(owner))));}
    catch(_){return null;}
  }
  function setCanvasCodingModel(owner,choice){
    if(!owner)return;
    try{
      if(choice)localStorage.setItem(canvasCodingModelKey(owner),JSON.stringify(choice));
      else localStorage.removeItem(canvasCodingModelKey(owner));
      codingModelMemory=null;
    }catch(_){codingModelMemory={owner,choice};}
  }
  function canvasCodingModelLabel(choice){
    return choice?(choice.provider==='anthropic'?'Claude':'OpenAI')+' · '+choice.model:'Use default';
  }
  function renderCanvasCodingModelMenu(picker,models,choice){
    const selected=choice?JSON.stringify(choice):'';
    const choices=[null,...models];
    const values=choices.map(model=>model?JSON.stringify(model):'');
    const inputs=Array.from(picker.querySelectorAll('input'));
    $('#menuCodingModelCurrent').textContent=canvasCodingModelLabel(choice);
    if(inputs.length===values.length&&inputs.every((input,index)=>input.value===values[index])){
      for(const input of inputs)input.checked=input.value===selected;
      return;
    }
    const focused=inputs.includes(document.activeElement)?document.activeElement.value:null;
    let nextFocus=null;
    picker.replaceChildren(...choices.map((model,index)=>{
      const row=document.createElement('label');
      const input=document.createElement('input');
      const text=document.createElement('span');
      input.type='radio';input.name='coding-model-choice';input.value=values[index];input.checked=input.value===selected;
      if(input.value===focused)nextFocus=input;
      text.textContent=canvasCodingModelLabel(model);
      row.append(input,text);
      return row;
    }));
    if(focused!==null)(nextFocus||picker.querySelector(':checked'))?.focus({preventScroll:true});
  }
  function syncCanvasCodingModelMenu(){
    const picker=$('#menuCodingModelOptions'),owner=canvasOwner();
    if(!picker||owner===codingModelMenuOwner)return;
    codingModelMenuOwner=owner;
    codingModelMenuRequest?.abort();
    codingModelMenuRequest=null;
    codingModelMenuLoadedAt=0;
    const field=$('#menuCodingModelField');
    field.hidden=!owner;
    field.open=false;
    $('#menuCodingModelStatus').textContent='';
    const choice=selectedCanvasCodingModel(owner);
    renderCanvasCodingModelMenu(picker,choice?[choice]:[],choice);
    if(owner)void refreshCanvasCodingModelMenu();
  }
  async function refreshCanvasCodingModelMenu(force=false){
    const picker=$('#menuCodingModelOptions'),owner=canvasOwner();
    if(!picker||!owner||codingModelMenuRequest||(!force&&Date.now()-codingModelMenuLoadedAt<60000))return;
    const status=$('#menuCodingModelStatus');
    const request=new AbortController();
    codingModelMenuRequest=request;
    const active=()=>!request.signal.aborted&&codingModelMenuRequest===request&&canvasOwner()===owner;
    if(!codingModelMenuLoadedAt)status.textContent='Loading coding models…';
    try{
      const response=await api('agent-scene.php?models=1',undefined,request.signal);
      if(!active())return;
      if(!Array.isArray(response.models))throw new Error('Invalid coding models');
      codingModelMenuLoadedAt=Date.now();
      const models=response.models.map(canvasCodingModel).filter(Boolean);
      const choice=selectedCanvasCodingModel(owner);
      const stale=choice&&!models.some(model=>model.provider===choice.provider&&model.model===choice.model);
      if(stale)setCanvasCodingModel(owner,null);
      renderCanvasCodingModelMenu(picker,models,stale?null:choice);
      status.textContent=stale?'The previous coding model is no longer available.':
        models.length?'For canvas builds and revisions.':'No coding model overrides are available.';
    }catch(_){
      if(active())status.textContent='Coding models could not be loaded. You can still use the default.';
    }finally{
      if(active())codingModelMenuRequest=null;
    }
  }
  function bindCanvasCodingModelMenu(){
    const picker=$('#menuCodingModelOptions');
    if(!picker)return;
    picker.addEventListener('change',event=>{
      const owner=canvasOwner(),input=event.target;
      if(!owner||owner!==codingModelMenuOwner||input.name!=='coding-model-choice'||!input.checked)return;
      const choice=input.value?canvasCodingModel(JSON.parse(input.value)):null;
      setCanvasCodingModel(owner,choice);
      $('#menuCodingModelCurrent').textContent=canvasCodingModelLabel(choice);
    });
    const field=$('#menuCodingModelField');
    field.addEventListener('keydown',event=>{
      if(event.key!=='Escape'||!field.open)return;
      event.preventDefault();event.stopPropagation();
      field.open=false;
      $('#menuCodingModel').focus({preventScroll:true});
    });
    syncCanvasCodingModelMenu();
  }
  function canvasSelectionKey(owner){
    const apiPath=new URL(cfg.apiBase||'api',document.baseURI).pathname;
    return 'stonefellow.activeCanvas.'+apiPath+':'+owner;
  }
  function selectedCanvasId(owner){
    if(!owner)return null;
    if(canvasSelection?.owner===owner)return canvasSelection.id;
    try{
      const id=Number(localStorage.getItem(canvasSelectionKey(owner)));
      if(Number.isSafeInteger(id)&&id>0)return id;
    }catch(_){/* Browser storage can be disabled. */}
    return null;
  }
  function selectCanvasId(owner,id){
    if(!owner)return;
    try{
      if(id===null)localStorage.removeItem(canvasSelectionKey(owner));
      else localStorage.setItem(canvasSelectionKey(owner),String(id));
      canvasSelection=null;
    }catch(_){canvasSelection={owner,id};}
  }
  async function restoreActiveCanvas(){
    const owner=canvasOwner(),id=selectedCanvasId(owner),stage=window.SFPublicAgentCanvas;
    const canRestore=()=>owner===canvasOwner()&&selectedCanvasId(owner)===id&&
      stage?.active?.()&&!stage.sceneActive?.()&&!listeningRoomRequest;
    if(!id||!canRestore())return;
    try{
      const response=await api('agent-scene.php?id='+id);
      if(!canRestore()||response.scene?.id!==id)return;
      const scene=response.scene,standard=(response.design_source??scene.design_source)==='standard';
      if(scene.canvas_kind==='space_eq'){selectCanvasId(owner,null);return;}
      if(!savedCanvasCanOpen(response)){
        selectCanvasId(owner,null);popupEntry('notice','The saved candidate has not completed its checks. Open Canvas History to review its source.');return;
      }
      await stage.prepareSceneLibraries?.(scene);
      if(!canRestore())return;
      let workflow=response.workflow;
      if(workflow){
        const committed=await secureJsonPost('canvas-build-workflow.php',{action:'apply',request_id:workflow.request_id,scene_id:scene.id});
        if(!canRestore()||committed.workflow?.state!=='applied'||committed.workflow.candidate_scene_id!==scene.id)return;
        workflow=committed.workflow;
      }
      if(stage.showScene?.(scene,{standard,focus:false})){
        listeningRoom=scene;
        listeningRoomOwner=owner;
        if(workflow){
          stage.setSceneWorkflow?.({...workflow,phase:'coding',silent:true});
          stage.setSceneCandidateCode?.(scene);
          stage.setSceneWorkflow?.({...workflow,phase:'applied',silent:true});
        }
      }
    }catch(error){
      if(error.payload?.error==='scene_not_found'&&canRestore())selectCanvasId(owner,null);
      if(error.code==='canvas_library_unavailable'&&canRestore())popupEntry('notice',error.message);
    }
  }
  function savedCanvasCanOpen(response){
    const scene=response?.scene,workflow=response?.workflow;
    if(workflow)return workflow.candidate_scene_id===scene?.id&&['ready','applied'].includes(workflow.state);
    return scene?.workflow_required!==true||response.verification?.verified===true;
  }
  async function deleteSavedCanvas(id){
    const owner=canvasOwner();
    if(!owner)throw new Error('Sign in again to manage your canvases.');
    if(!Number.isSafeInteger(id)||id<1)throw new Error('Choose a saved canvas.');
    if(listeningRoomRequest)throw new Error('Wait for the current canvas request to finish.');
    try{
      const result=await secureJsonPost('agent-scene.php',{action:'delete',id});
      if(result.deleted_id!==id)throw new Error('Stonefellow could not confirm the canvas was deleted.');
    }catch(error){
      if(error.status!==404||error.payload?.error!=='scene_not_found')throw error;
    }
    if(owner!==canvasOwner())throw new Error('Your account changed. Reopen Canvas History.');
    if(selectedCanvasId(owner)===id)selectCanvasId(owner,null);
    if(listeningRoomOwner===owner&&listeningRoom?.id===id){
      setCanvasImageEditDraft(null);
      listeningRoom=null;
      if(!listeningRoomRequest)listeningRoomOwner=null;
      window.SFPublicAgentCanvas?.closeScene?.(false);
    }
  }
  function clearListeningRoom(){
    canvasAccess.current?.close();
    setCanvasImageEditDraft(null);
    listeningRoomRequest?.abort();
    listeningRoomRequest=null;
    listeningRoom=null;
    listeningRoomOwner=null;
  }
  function closeListeningRoom(forget=true){
    cancelGalleryOpen();
    const owner=canvasOwner();
    const hadRoom=!!(listeningRoom||listeningRoomRequest||(forget&&selectedCanvasId(owner)));
    if(forget)selectCanvasId(owner,null);
    clearListeningRoom();
    window.SFPublicAgentCanvas?.closeScene?.(forget);
    window.SFPublicAgentCanvas?.closeSceneHistory?.();
    window.SFPublicAgentCanvas?.setSceneCoding?.('idle');
    return hadRoom;
  }
  function listeningRoomError(error,reading){
    if(error.payload?.error==='authentication_required')return 'Sign in again to access your canvases.';
    if(['scene_model_unavailable','scene_image_unavailable','invalid_scene_output'].includes(error.payload?.error)&&/^[a-f0-9]{32}$/.test(error.payload.request_id||''))
      return error.message+' Build reference '+error.payload.request_id+'.';
    if(error.payload||!Number.isInteger(error.status)||error.status<100)return String(error.message||'Please try again.');
    const status=' (HTTP '+error.status+').';
    if(error.status===404)return 'The canvas service was not found'+status+' Check that the current deploy ZIP was extracted into the site folder.';
    if(error.status===401||error.status===403)return 'The server refused access to the canvas service'+status;
    return 'The server returned an unreadable response'+status+(reading?'':' Check Canvas History before asking Stonefellow to build it again.');
  }
  function listeningRoomHasResult(response,trackId,canvasKind='listening_room'){
    const scene=response?.scene;
    return Number.isSafeInteger(scene?.id)&&scene.id>0&&String(scene.track_id??'')===trackId&&
      (scene.canvas_kind||'listening_room')===canvasKind;
  }
  function waitForListeningRoom(delay,signal){
    return new Promise(resolve=>{
      if(signal.aborted){resolve();return;}
      const timer=setTimeout(done,delay);
      signal.addEventListener('abort',done,{once:true});
      function done(){clearTimeout(timer);signal.removeEventListener('abort',done);resolve();}
    });
  }
  async function recoverListeningRoom(payload,signal,failure,started,trace){
    if(signal.aborted)throw failure;
    const poll=new AbortController();
    const stop=()=>poll.abort();
    const elapsed=Math.max(0,Date.now()-started);
    const extended=payload.canvas_kind==='custom';
    const recoveryWindow=extended?90000:25000;
    let deadline=setTimeout(stop,Math.max(10000,recoveryWindow-elapsed));
    const delays=[0,550,950,1400,1850,2200,2500,3000,3000,3000,3000,3000];
    let confirmedPending=false;
    signal.addEventListener('abort',stop,{once:true});
    try{
      for(let attempt=0;extended||confirmedPending||attempt<delays.length;attempt++){
        const delay=delays[attempt]??3500;
        if(delay)await waitForListeningRoom(delay,poll.signal);
        if(poll.signal.aborted)break;
        let result;
        try{result=await api('agent-scene.php?request_id='+payload.request_id,undefined,poll.signal);}
        catch(error){
          if(error.status===401||error.status===403||error.payload?.error==='authentication_required')throw error;
          continue;
        }
        if(poll.signal.aborted)break;
        if(result?.state==='review')return result;
        if(result?.state==='ready'&&listeningRoomHasResult(result,payload.track_id,payload.canvas_kind)){
          trace?.('recovery_ready');return result;
        }
        if(result?.state==='canceled'){trace?.('recovery_canceled');return result;}
        if(result?.state==='failed'){
          trace?.('recovery_failed');
          return result;
        }
        // Keep checking while the server can confirm the original build is pending.
        if(result?.state==='pending'){
          if(!confirmedPending)trace?.('recovery_pending');
          confirmedPending=true;
          clearTimeout(deadline);
          deadline=setTimeout(stop,recoveryWindow);
        }
      }
      trace?.('recovery_unconfirmed');
      throw failure;
    }finally{
      clearTimeout(deadline);
      signal.removeEventListener('abort',stop);
    }
  }
  function canvasProjects(){
    if(!window.SFCanvasProjects?.create)return null;
    if(!canvasProjects.current)canvasProjects.current=window.SFCanvasProjects.create({
      getOwner:canvasOwner,getHost:()=>chatPopupMessages,
      get:signal=>api('canvas-projects.php',undefined,signal),
      post:(payload,signal)=>secureJsonPost('canvas-projects.php',payload,false,signal),
      onPresent:concept=>{openChatPopup();agent(st.playing?'playing':'idle',concept);},
      onSuggest:()=>{closeMenu();void handleListeningRoomCommand({kind:'suggest'});},
      onExecute:proposal=>{const owner=canvasOwner();setTimeout(()=>{if(owner&&canvasOwner()===owner)void handleListeningRoomCommand({kind:'project',proposal,owner});},0);}
    });
    canvasProjects.current.setOwner();return canvasProjects.current;
  }
  async function discussCanvasProject(brief){
    if(!window.SFPublicAgentCanvas?.active?.())return 'Open the Stonefellow home canvas to discuss a canvas project.';
    await loadAuth();
    const owner=canvasOwner();
    if(!owner)return 'Sign in to discuss your saved canvases.';
    if(listeningRoomRequest)return 'Review or finish the current project first.';
    const controller=canvasProjects();
    if(!controller?.discuss)throw new Error('Reload the page to discuss your canvas.');
    const resources=chatDraftResourceInputs(brief),music=chatDraftMusicInputs(brief);
    agent('thinking','Reviewing your canvas ideas…');
    const result=await controller.discuss({brief,currentCanvasId:listeningRoom?.id,codingModel:selectedCanvasCodingModel(owner),
      sourceSceneIds:resources.source_scene_ids,artistInputs:resources.artist_inputs,musicInputs:music,playlistInputs:resources.playlist_inputs,
      imageReferenceIds:chatDraftVisualReferenceIds(brief)});
    if(canvasOwner()!==owner)return null;
    return result.reply||(result.proposal?'Your canvas project is ready for review.':'');
  }
  async function requestListeningRoom(details,request,stage,requestActive,announce){
    if(details.action!=='edit_image'){
      const projects=canvasProjects();
      if(!projects)throw new Error('Refresh the page to load project approval.');
      const {proposal_id:reuseId,...project}=details;
      let approved;
      try{approved=await projects.prepare(project,{signal:request.signal,reuseId});}
      catch(error){if(error.name==='AbortError'){stage.setSceneCoding?.('idle');request.abort();}throw error;}
      if(!requestActive())return {state:'canceled'};
      details={...approved.project,project_managed:true,proposal_id:approved.id};
    }

    stage.setSceneImage?.('clear');
    const requestId=Array.from(window.crypto.getRandomValues(new Uint8Array(16)),
      byte=>byte.toString(16).padStart(2,'0')).join('');
    const payload={...details,request_id:requestId};
    const trace=(event,data)=>{if(requestActive())recordCanvasDebug(requestId,event,data);};
    const model=canvasCodingModel(details.coding_model);
    trace('build_started',{action:details.action==='edit_image'?'edit_image':details.previous_id?'revise':'create',canvas_kind:details.canvas_kind||'listening_room',
      canvas_track:!!details.track_id,context_track:!!details.context_track_id,
      provider:model?.provider||'default',model:model&&/^(?:gpt-|o[1-9]|claude-)[a-z0-9._-]{0,80}$/i.test(model.model)?model.model:undefined});
    const stop=new Promise(settle=>{request.sceneBuild={payload,trace,settle,request,owner:canvasOwner(),stopping:false,stopConfirmed:false,
      workflowEligible:payload.project_managed===true&&payload.canvas_kind==='custom',imageIndex:0,imageTotal:0};});
    stopListeningRoomBuild.pending=null;
    if(request.sceneBuild.workflowEligible)stage.setSceneWorkflow?.({request_id:requestId,phase:'coding',project:details,
      summary:'Coding Agent is building the approved project.',steps:[],total_active_tokens_used:0});
    return Promise.race([sendListeningRoomRequest(request.sceneBuild,request.signal,stage,requestActive,announce),stop]);
  }
  async function runCanvasBuildWorkflow(initial,request,stage,requestActive){
    const build=request.sceneBuild;
    if(!build?.workflowEligible||initial.scene?.workflow_required!==true||initial.verification?.verified===true){
      if(build?.workflowEligible)stage.setSceneWorkflow?.(null);
      return initial;
    }
    if(build.stopRequested)return {state:'canceled'};
    if(!window.SFCanvasBuildWorkflow?.create||!window.SFCanvasSceneTester?.test||!stage.prepareSceneTest)
      throw new Error('Reload the page to load canvas testing and repair. Your current canvas is still available.');
    const owner=build.owner;
    const owned=()=>owner===canvasOwner()&&st.auth.authenticated;
    const active=()=>owned()&&requestActive()&&!build.stopRequested;
    const getScene=async(id,signal)=>{
      const response=await api('agent-scene.php?id='+encodeURIComponent(id)+'&current_track_id='+encodeURIComponent(observedPlayer().track?.id||''),undefined,signal);
      if(!active()||response.scene?.id!==id)throw new DOMException('Project session changed.','AbortError');
      return response.scene;
    };
    const controller=window.SFCanvasBuildWorkflow.create({requestId:build.payload.request_id,project:build.payload,
      getOwner:canvasOwner,active,signal:request.signal,
      post:(payload,options={})=>secureJsonPost('canvas-build-workflow.php',payload,false,options.signal,options.onDelta,undefined,options.onRetry),
      get:signal=>api('canvas-build-workflow.php?request_id='+encodeURIComponent(build.payload.request_id),undefined,signal),getScene,
      onUpdate:update=>{if(owned()&&stage.active?.()&&(requestActive()||build.stopRequested))stage.setSceneWorkflow?.(update);},
      onCandidate:scene=>{if(active())stage.setSceneCandidateCode?.(scene);},
      onCode:text=>{
        if(!active())return;
        stage.setSceneCoding?.('streaming','Repair Agent is revising this candidate.');stage.appendSceneCode?.('raw',text);
      },
      onRetry:()=>{if(active())stage.setSceneCoding?.('generating','Repair Agent is retrying the same approved correction.');},
      test:(scene,{signal,onEvent})=>{
        const context=canvasAudioContext(scene),valid=()=>active()&&!signal.aborted;
        const loadCatalog=async(filters={},readSignal)=>{
          if(!valid())throw new DOMException('Project session changed.','AbortError');
          const params=new URLSearchParams({action:'catalog',...context});
          for(const field of ['release_id','track_id'])if(filters[field]!==undefined){
            if(typeof filters[field]!=='string'||!/^[A-Za-z0-9_-]{1,180}$/.test(filters[field]))throw new Error('Choose a valid catalog source.');
            params.set(field,filters[field]);
          }
          const response=await api('canvas-audio.php?'+params,undefined,readSignal);
          if(!valid()||!Array.isArray(response.tracks)||!Array.isArray(response.releases)||!Array.isArray(response.playlists))throw new Error('The preview catalog is unavailable.');
          return {...scopeCanvasMusicCatalog(response,scene),playlists:response.playlists};
        };
        return window.SFCanvasSceneTester.test(scene,{signal,onEvent,getOwner:canvasOwner,canUse:valid,getState:canvasAudioState,
          prepareScene:(candidate,probe,previewProgram)=>stage.prepareSceneTest(candidate,{probe,previewProgram,currentTrackId:observedPlayer().track?.id||''}),loadCatalog,
          loadAudio:async(id,readSignal)=>{
            const catalog=await loadCatalog({track_id:id},readSignal),track=catalog.tracks.find(value=>value.track_id===id);
            if(!track?.source_available)throw new Error('The selected MP3 is unavailable.');
            const response=await fetch((cfg.apiBase||'api')+'/'+canvasAudioSourcePath(id,scene),
              {credentials:'same-origin',mode:'same-origin',redirect:'error',cache:'no-store',signal:readSignal});
            if(!response.ok||!/^audio\/mpeg(?:\s*;|$)/i.test(response.headers.get('content-type')||'')||
              Number(response.headers.get('content-length'))>128*1024*1024)throw new Error('The preview MP3 could not load.');
            const blob=await response.blob();if(!valid()||!blob.size||blob.size>128*1024*1024)throw new Error('The preview MP3 could not load.');
            return {track,blob};
          },
          onSceneImage:async(id,readSignal)=>{
            const path=sceneImagePath(id);if(!path||!valid())throw new Error('The preview image is unavailable.');
            const response=await fetch(path,{credentials:'same-origin',mode:'same-origin',redirect:'error',cache:'no-store',signal:readSignal});
            if(!response.ok||Number(response.headers.get('content-length'))>16*1024*1024)throw new Error('The preview image could not load.');
            const blob=await response.blob();if(!valid())throw new DOMException('Project session changed.','AbortError');return blob;
          }});
      }});
    build.workflowController=controller;
    return controller.run(initial);
  }
  async function stopCanvasBuildWorkflow(build){
    if(build.stopping||build.owner!==canvasOwner())return;
    build.stopping=true;build.stopRequested=true;stopListeningRoomBuild.pending=build;
    try{
      const canceled=build.workflowController?await build.workflowController.cancel():
        await secureJsonPost('canvas-build-workflow.php',{action:'cancel',request_id:build.payload.request_id});
      if(!build.workflowController)await secureJsonPost('agent-scene.php',{action:'cancel',request_id:build.payload.request_id});
      if(build.owner!==canvasOwner())return;
      if(canceled?.workflow?.state==='applied'){build.stopConfirmed=true;stopListeningRoomBuild.pending=null;return;}
      if(canceled?.workflow?.state!=='canceled')throw new Error('The server did not confirm that the project stopped.');
      window.SFPublicAgentCanvas?.setSceneWorkflow?.({...canceled.workflow,phase:'canceled',summary:'The project was stopped.'});
      build.stopConfirmed=true;build.settle({state:'canceled'});stopListeningRoomBuild.pending=null;
    }catch(error){
      if(build.owner===canvasOwner())return 'The project is stopped locally, but server cancellation could not be confirmed. '+error.message;
    }finally{build.stopping=false;}
  }
  function validCanvasImagePosition(index,total){
    return Number.isInteger(index)&&Number.isInteger(total)&&index>=1&&index<=total&&total<=4;
  }
  function showCanvasBuildImages(stage,build,result){
    if(!build||!result)return;
    const requestId=build.payload.request_id;
    const assets=Array.isArray(result.generated_images)?result.generated_images.slice(0,4):[];
    if(!assets.length){
      const asset=Array.isArray(result.scene?.image_assets)&&result.scene.image_assets.find(image=>image?.id===requestId);
      if(asset)assets.push({id:asset.id,title:asset.title,index:1});
    }
    const total=validCanvasImagePosition(1,result.image_total)?result.image_total:Math.max(build.imageTotal,assets.length);
    for(const asset of assets){
      if(validCanvasImagePosition(asset?.index,total)&&/^[a-f0-9]{32}$/.test(asset.id||'')&&
        (asset.index!==1||asset.id===requestId))stage.setSceneImage?.('saved',asset,{index:asset.index,total});
    }
  }
  async function stopListeningRoomBuild(){
    const request=listeningRoomRequest,build=request?.sceneBuild;
    const managed=build?.workflowEligible?build:stopListeningRoomBuild.pending;
    if(managed&&managed.owner===canvasOwner())return stopCanvasBuildWorkflow(managed);
    if(!build||build.stopping||request.signal.aborted)return;
    build.stopping=true;
    build.trace('stop_requested');
    try{
      const {request_id,track_id,canvas_kind}=build.payload;
      const result=await secureJsonPost('agent-scene.php',{action:'cancel',request_id},false,request.signal);
      if(listeningRoomRequest!==request||request.signal.aborted)return;
      if(result?.state!=='canceled'&&result?.state!=='failed'&&
        !(result?.state==='ready'&&listeningRoomHasResult(result,track_id,canvas_kind)))
        throw new Error('The server did not confirm the build status.');
      build.stopConfirmed=true;
      build.trace('stop_result',{state:result.state});
      build.settle(result);
    }catch(error){
      if(listeningRoomRequest===request&&!request.signal.aborted){
        build.trace('stop_failed',canvasDebugFailure(error));
        return 'Could not stop the build. '+listeningRoomError(error,true);
      }
    }finally{
      build.stopping=false;
    }
  }
  async function sendListeningRoomRequest(build,signal,stage,requestActive,announce){
    const {payload,trace}=build;
    const started=Date.now();
    let result,failure,streaming=false,receivedSource=false,announcement='',announced=false;
    try{
      result=await secureJsonPost('agent-scene.php',payload,false,signal,text=>{
        if(!text||!requestActive())return;
        if(!streaming){
          streaming=true;
          trace('source_started');
          if(receivedSource)stage.setSceneCoding?.('generating');
          stage.setSceneCoding?.('streaming',announcement||'Receiving live code from the text model…');
        }
        receivedSource=true;
        stage.appendSceneCode?.('raw',text);
      },concept=>{
        if(!requestActive()||announcement)return;
        const plan=concept.trim().replace(/[.!?…]+$/,'');
        if(!plan)return;
        trace('concept_received');
        const briefKey=value=>norm(value).replace(/^(?:please )?(?:build|make|create|design|render)(?: me)? /,'');
        const planKey=briefKey(plan),designKey=briefKey(canvasDesignBrief(payload.brief));
        const echoesBrief=payload.canvas_kind==='custom'&&(planKey===designKey||
          (planKey.split(' ').length>=5&&(' '+designKey+' ').includes(' '+planKey+' ')));
        announcement=echoesBrief?(payload.previous_id?'I’m revising your canvas.':'I’m building your canvas.'):
          (payload.previous_id?'I’m updating the canvas with ':'I’m building ')+plan+'.';
        if(!announced){
          announced=true;
          announce(announcement,receivedSource?'streaming':'generating');
        }else stage.setSceneCoding?.(receivedSource?'streaming':'generating',announcement);
      },reason=>{
        if(!requestActive())return;
        trace('provider_retry',{reason:canvasDebugReason(reason)});
        streaming=false;
        announcement='';
        stage.setSceneCoding?.(receivedSource?'streaming':'generating','Retrying the same canvas brief…');
      },(event,data)=>{
        if(!requestActive())return;
        if(event!=='image_generating'&&event!=='image_saved'){trace(event,data);return;}
        const {index=1,total=1,image}=data||{};
        if(!validCanvasImagePosition(index,total)||(build.imageTotal&&total!==build.imageTotal))return;
        if(event==='image_generating'&&index===build.imageIndex+1){
          build.imageIndex=index;
          build.imageTotal=total;
          trace(event,{index,total});
          const editing=payload.action==='edit_image';
          stage.setSceneCoding?.('streaming',editing?'Editing your selected image…':total===1?'Creating your canvas image…':`Creating image ${index} of ${total}…`);
          stage.setSceneImage?.('generating',undefined,{index,total,...(editing?{editing:true}:{})});
        }else if(event==='image_saved'&&index===build.imageIndex&&/^[a-f0-9]{32}$/.test(image?.id||'')&&
          (index!==1||image.id===payload.request_id)){
          trace(event,{index,total});
          stage.setSceneCoding?.('streaming',index<total?`Image ${index} of ${total} saved.`:
            total===1?'Image saved. Finishing your canvas…':'Images saved. Finishing your canvas…');
          stage.setSceneImage?.('saved',image,{index,total});
        }
      });
    }catch(error){
      const status=error.status;
      const network=error.name==='TypeError';
      const encoding=error.payload?.error==='response_encoding_failed';
      if(signal.aborted||!(network||encoding||(!error.payload&&(status>=500||(status>=200&&status<300)))))throw error;
      trace('request_interrupted',canvasDebugFailure(error));
      failure=network?new Error('The connection was interrupted. Check Canvas History before asking Stonefellow to build it again.'):
        encoding?Object.assign(new Error(error.message),{status}):error;
    }
    if(['canceled','failed','review'].includes(result?.state)||listeningRoomHasResult(result,payload.track_id,payload.canvas_kind))return result;
    failure??=new Error((result?.state==='pending'?'Stonefellow is still building your canvas.':
      'The server returned an incomplete canvas response.')+' Check Canvas History in a moment.');
    if(signal.aborted)throw failure;
    trace('recovery_started');
    stage.setSceneCoding?.(receivedSource?'streaming':'generating','Checking whether your canvas was saved…');
    return recoverListeningRoom(payload,signal,failure,started,trace);
  }
  let reuseCanvasImagesOnly=null;
  const canvasImageReviewKey=owner=>'stonefellow.canvas-image-review.'+owner;
  function rememberCanvasImageReview(owner,requestId){
    try{
      if(requestId)localStorage.setItem(canvasImageReviewKey(owner),requestId);
      else localStorage.removeItem(canvasImageReviewKey(owner));
    }catch(_){}
  }
  function pendingCanvasImageReview(owner){
    try{
      const id=localStorage.getItem(canvasImageReviewKey(owner));
      return /^[a-f0-9]{32}$/.test(id||'')?id:null;
    }catch(_){return null;}
  }
  async function confirmCanvasImageGeneration(brief,editing,request){
    const owner=canvasOwner();
    if(!editing&&reuseCanvasImagesOnly){
      const savedOnly=reuseCanvasImagesOnly;reuseCanvasImagesOnly=null;
      if(savedOnly.owner===owner&&brief.startsWith(savedOnly.brief))return {image_count:0};
    }
    const result=await secureJsonPost('agent-scene.php',{action:'image_preflight',brief,editing},false,request.signal);
    if(!Number.isInteger(result.preflight?.requested_count))return {};
    const confirmImages=window.SFCanvasImageReview?.confirmGeneration;
    if(typeof confirmImages!=='function')throw new Error('Refresh the page to load the image generation controls.');
    const choice=await confirmImages(result.preflight,{signal:request.signal});
    if(!choice)return null;
    if(choice.use_saved&&editing)return {use_saved:true};
    if(choice.use_saved){
      input.value="Let's build "+brief+' ';
      input.setSelectionRange(input.value.length,input.value.length);
      reuseCanvasImagesOnly={owner,brief};
      setChatFooterHidden(false);
      openChatImagePicker({start:input.value.length,end:input.value.length,standalone:false});
      popupEntry('notice','Choose images for this draft. It will use saved images only.');
      return null;
    }
    return {image_count:choice.count,review_images:!editing&&choice.review};
  }
  async function chooseCanvasImageVariation(response,requestId,request,stage,requestActive){
    const owner=canvasOwner();
    rememberCanvasImageReview(owner,requestId);
    stage.setSceneCoding?.('ready','Your image variations are ready to compare.');
    const choose=window.SFCanvasImageReview?.choose;
    if(typeof choose!=='function')throw new Error('Refresh the page to load image comparison.');
    const imageId=await choose(response.generated_images||[],{
      signal:request.signal,title:'Choose an image for your canvas',imageUrl:asset=>sceneImagePath(asset.id)});
    if(!requestActive())return null;
    if(!imageId){
      popupEntry('assistant','Your variations are saved. Choose an image when you’re ready.',{imageReview:requestId});
      return null;
    }
    const selected=await secureJsonPost('agent-scene.php',{action:'choose_image_variation',request_id:requestId,image_id:imageId},false,request.signal);
    if(requestActive()&&selected.state==='ready')rememberCanvasImageReview(owner,null);
    return selected;
  }
  async function replaceCanvasImage(asset){
    const owner=canvasOwner(),stage=window.SFPublicAgentCanvas;
    if(!owner||listeningRoomRequest||asset?.sceneId!==listeningRoom?.id||!stage?.sceneActive?.())return false;
    const previous=listeningRoom;
    const request=new AbortController();listeningRoomRequest=request;
    const active=()=>listeningRoomRequest===request&&!request.signal.aborted&&canvasOwner()===owner&&listeningRoom?.id===previous.id;
    try{
      const loadImages=async scope=>{
        const images=[],seen=new Set();
        let cursor=null;
        do{
          const query=new URLSearchParams({limit:'100',...(scope?{scope}:{}),...(cursor?{before:cursor}:{})});
          const page=await api('agent-canvas-image.php?'+query,undefined,request.signal);
          if(!Array.isArray(page.images))throw new Error('The image gallery could not be loaded.');
          images.push(...page.images);
          cursor=typeof page.next_cursor==='string'&&!seen.has(page.next_cursor)?page.next_cursor:null;
          if(cursor)seen.add(cursor);
        }while(cursor&&active());
        return images;
      };
      const [saved,references]=await Promise.all([loadImages(''),loadImages('reference')]);
      if(!active())return false;
      const images=[...saved,...references].filter(image=>image.id!==asset.id);
      if(!images.length){popupEntry('notice','Save or generate another image before replacing this one.');return false;}
      const id=await window.SFCanvasImageReview?.choose(images,{
        signal:request.signal,title:'Replace this canvas image',imageUrl:image=>sceneImagePath(image.id)});
      if(!id||!active())return false;
      const requestId=Array.from(window.crypto.getRandomValues(new Uint8Array(16)),byte=>byte.toString(16).padStart(2,'0')).join('');
      const result=await secureJsonPost('agent-scene.php',{action:'swap_image',request_id:requestId,
        previous_id:previous.id,image_id:asset.id,replacement_id:id},false,request.signal);
      if(!active()||!listeningRoomHasResult(result,String(previous.track_id??''),'custom'))
        throw new Error('The replacement canvas could not open.');
      await stage.prepareSceneLibraries?.(result.scene,{signal:request.signal});
      if(!active()||!stage.showScene(result.scene))throw new Error('The replacement canvas could not open.');
      listeningRoom=result.scene;selectCanvasId(owner,result.scene.id);
      popupEntry('assistant','I replaced the image. The previous version is saved in Canvas History.');
      return true;
    }catch(error){if(active())popupEntry('notice','The image could not be replaced. '+error.message);return false;}
    finally{if(listeningRoomRequest===request)listeningRoomRequest=null;}
  }
  async function handleListeningRoomCommand(action){
    cancelGalleryOpen();
    if(action.kind==='close'){
      if(closeListeningRoom())agent(st.playing?'playing':'idle','Canvas closed.');
      return;
    }
    await loadAuth(true);
    if(!st.auth.authenticated||!st.auth.user?.id){
      popupEntry('notice','Sign in to create or reopen your private canvases.');return;
    }
    if(listeningRoomRequest){popupEntry('notice','A canvas request is already in progress.');return;}
    const stage=window.SFPublicAgentCanvas;
    if(!stage?.active?.()||!stage.showScene){popupEntry('notice','Open the Stonefellow home canvas to build or reopen a canvas.');return;}
    let trackId,brief,previousId,previousScene,canvasKind=action.canvasKind;
    const selectedProject=action.kind==='project'?action.proposal:null;
    if(selectedProject&&action.owner!==canvasOwner())return;
    const editingImage=action.kind==='edit_image';
    const reading=['history','restore','suggest','image_review'].includes(action.kind)||(action.kind==='build_on'&&!action.brief);
    if(action.kind==='image_review'){
      const requestId=action.requestId||pendingCanvasImageReview(canvasOwner());
      if(!/^[a-f0-9]{32}$/.test(requestId||'')){popupEntry('notice','There are no image variations waiting for a choice.');return;}
      action={...action,requestId};
    }
    if(action.kind==='restore'&&(!Number.isSafeInteger(action.id)||action.id<1))return;
    if(action.kind==='create'&&(!canvasKind||canvasKind==='listening_room')&&!action.subject&&!action.requiresTrack&&!st.track?.id){
      canvasKind='custom';
      action={...action,brief:action.brief||'A listening room'};
    }
    if(selectedProject){
      const project=selectedProject.project;
      if(!project||typeof project.brief!=='string')return;
      trackId=project.track_id||'';previousId=project.previous_id;canvasKind=project.canvas_kind||'custom';brief=project.brief;
    }else if(editingImage){
      const selection=action.selection;
      if(!selection||selection.owner!==canvasOwner()||selection.sceneId!==listeningRoom?.id||!stage.sceneActive?.()||
        !Array.isArray(listeningRoom?.image_assets)||!listeningRoom.image_assets.some(image=>image?.id===selection.imageId)){
        setCanvasImageEditDraft(null);
        popupEntry('notice','Choose an image on the current canvas to edit it.');return;
      }
      trackId=listeningRoom.track_id;
      previousId=listeningRoom.id;
      canvasKind='custom';
      brief=action.brief;
      setCanvasImageEditDraft(null);
    }else if(action.kind==='revise'){
      if(!listeningRoom||!stage.sceneActive?.()){popupEntry('notice','Open a canvas before editing it.');return;}
      if(isGalleryScene()){popupEntry('notice','Choose Remix in the Canvas Catalog to edit your own private copy.');return;}
      if(!action.brief){popupEntry('notice','Type /edit followed by what you want to change.');return;}
      trackId=listeningRoom.track_id;
      previousId=listeningRoom.id;
      previousScene=listeningRoom;
      canvasKind=listeningRoom.canvas_kind;
      brief=action.brief;
    }else if(action.kind==='create'&&canvasKind==='custom'){
      brief=action.brief;
      const design=canvasDesignBrief(brief);
      const pickedLyrics=chatDraftResourceInputs(brief).artist_inputs?.lyrics_ids;
      const lyricSection=pickedLyrics?null:canvasLyricSection(design);
      const namedSong=pickedLyrics?null:canvasTrackTarget(design);
      const matches=namedSong?catalog.filter(track=>norm(track.title)===norm(namedSong)):[];
      if(matches.length>1){popupEntry('notice','More than one song has that title. Play the version you want and use “this song.”');return;}
      if(lyricSection&&namedSong&&!matches.length&&!canvasCurrentTrack(namedSong)){
        popupEntry('notice','I couldn’t match that song title. Play the song and use “this song,” or use its exact catalog title.');return;
      }
      trackId=matches[0]?.id??((lyricSection||canvasCurrentTrack(namedSong)||/\b(?:this|current|playing)\s+(?:song|track)\b/i.test(design))?st.track?.id:'');
      if(lyricSection&&!trackId){popupEntry('notice','Play the song whose lyrics you want to use, then tell me what to build.');return;}
    }else if(action.kind==='create'){
      const subject=norm(action.subject);
      if(!subject||canvasCurrentTrack(subject)){
        trackId=canvasKind==='space_eq'&&!subject?'':st.track?.id;
        if(!trackId&&canvasKind!=='space_eq'){popupEntry('notice','Play a song first, or say “Build a listening room for” followed by its exact title.');return;}
      }else{
        const matches=catalog.filter(track=>norm(track.title)===subject);
        if(matches.length!==1){popupEntry('notice',matches.length?'More than one song has that title. Play the version you want and say “Build a listening room for this song.”':'I could not find that exact song title. Play the song first or try its catalog title.');return;}
        trackId=matches[0].id;
      }
      brief=action.brief||'Design a cinematic listening room that reflects this song and its published story.';
    }
    const request=new AbortController();
    listeningRoomRequest=request;
    const owner=String(st.auth.user.id);
    listeningRoomOwner=owner;
    const requestActive=()=>listeningRoomRequest===request&&!request.signal.aborted&&
      st.auth.authenticated&&String(st.auth.user?.id)===owner;
    try{
      if(action.kind==='suggest'){
        const projects=canvasProjects();
        if(!projects)throw new Error('Refresh the page to load canvas ideas.');
        await projects.suggest({signal:request.signal,currentCanvasId:listeningRoom?.id,
          brief:action.brief||'',codingModel:selectedCanvasCodingModel(owner)});
        return;
      }
      if(action.kind==='build_on'){
        const current=stage.sceneActive?.()?listeningRoom:null;
        let saved=/^(?:this|current|the current) canvas$/i.test(action.target)?current:null;
        if(!saved&&canvasTargetMatches(current,action.target))saved=current;
        if(!saved){
          const history=await api('agent-scene.php',undefined,request.signal);
          if(!requestActive())return;
          saved=(Array.isArray(history.scenes)?history.scenes:[]).find(scene=>canvasTargetMatches(scene,action.target));
        }
        if(!Number.isSafeInteger(saved?.id)||saved.id<1){popupEntry('notice','I couldn’t find that canvas in your history. Open Canvas History to see your saved builds.');return;}
        if(!action.brief)action={kind:'restore',id:saved.id};
        else{
          trackId=saved.track_id;previousId=saved.id;canvasKind=saved.canvas_kind||'listening_room';brief=action.brief;
          previousScene=saved;
          action={kind:'revise'};
        }
      }
      const design=reading?'':canvasDesignBrief(brief);
      const resourceInputs=reading?{}:chatDraftResourceInputs(brief);
      if(Object.keys(resourceInputs).length)canvasKind='custom';
      const roomImageEdit=(!canvasKind||canvasKind==='listening_room')&&
        (/\{(?:reference-)?image-(?:ref-)?[a-f0-9]{32}\}/i.test(design)||/\b(?:create|generate|make|draw|render|design)\b[^.!?\n]{0,100}\b(?:images?|photos?|pictures?|illustrations?)\b/i.test(design));
      if(previousId&&design&&(canvasKind==='space_eq'||roomImageEdit))canvasKind='custom';
      const pickedLyrics=resourceInputs.artist_inputs?.lyrics_ids??selectedProject?.project?.artist_inputs?.lyrics_ids??previousScene?.artist_inputs?.lyrics_ids;
      const lyricSection=canvasKind==='custom'&&!reading&&!editingImage&&!pickedLyrics?canvasLyricSection(design):null;
      if(lyricSection&&previousId){
        const target=canvasTrackTarget(design);
        const targetMatches=!target||(canvasCurrentTrack(target)?String(st.track?.id??'')===String(trackId??''):
          catalog.some(track=>String(track.id)===String(trackId)&&norm(track.title)===norm(target)));
        if(!trackId||!targetMatches){popupEntry('notice','Start a new canvas for that song to use its lyrics. A revision keeps the original song.');return;}
      }
      let contextTrackId;
      if(!reading&&canvasKind==='custom'&&(previousId||!trackId)){
        const playback=observedPlayer();
        if(previousId||playback.playing)contextTrackId=playback.track?.id;
      }
      const announce=(announcement,codingState='generating')=>{
        popupEntry('assistant',announcement);
        agent('thinking',announcement);
        stage.setSceneCoding?.(codingState,announcement);
        // Use the installed device voice for this status update, without a paid speech request.
        if(!speechPilotActive)void agentSpeak(announcement,true,request.signal).catch(()=>{});
      };
      let imageOptions={};
      if(!reading&&(canvasKind==='custom'||editingImage)){
        imageOptions=await confirmCanvasImageGeneration(brief,editingImage,request);
        if(!requestActive()||imageOptions===null)return;
        if(imageOptions.use_saved){
          listeningRoomRequest=null;
          await replaceCanvasImage({id:action.selection.imageId,sceneId:action.selection.sceneId,title:action.selection.title});
          return;
        }
      }
      if(!reading){
        if(editingImage)announce('I’m editing “'+action.selection.title+'.”');
        else agent('thinking','Planning your canvas…');
      }
      const codingModel=!reading&&!editingImage&&canvasKind!=='space_eq'&&!(previousId&&!design)?selectedCanvasCodingModel(owner):null;
      const imageReferenceIds=reading?[]:chatDraftVisualReferenceIds(brief);
      const musicInputs=reading?null:chatDraftMusicInputs(brief);
      let response=reading
        ?await api('agent-scene.php'+(action.kind==='restore'?'?id='+action.id:action.kind==='image_review'?'?request_id='+action.requestId:''),undefined,request.signal)
        :await requestListeningRoom({track_id:String(trackId??''),brief,...(canvasKind?{canvas_kind:canvasKind}:{}),
          ...(previousId?{previous_id:previousId}:{}),...(lyricSection?{lyric_section:lyricSection}:{}),
          ...(editingImage?{action:'edit_image',image_id:action.selection.imageId}:{}),
          ...(imageReferenceIds.length?{image_reference_ids:imageReferenceIds}:{}),...imageOptions,
          ...(musicInputs?{music_inputs:musicInputs}:{}),
          ...resourceInputs,
          ...(contextTrackId?{context_track_id:String(contextTrackId)}:{}),...(codingModel?{coding_model:codingModel}:{}),
          ...(selectedProject?{...selectedProject.project,...imageOptions,proposal_id:selectedProject.id}:{})},request,stage,requestActive,announce);
      if(!requestActive())return;
      if(!reading)showCanvasBuildImages(stage,request.sceneBuild,response);
      if(response?.state==='review'){
        response=await chooseCanvasImageVariation(response,action.requestId||request.sceneBuild.payload.request_id,request,stage,requestActive);
        if(!response||!requestActive())return;
      }
      if(action.kind==='image_review'&&['ready','canceled','failed'].includes(response?.state))rememberCanvasImageReview(owner,null);
      if(action.kind==='image_review'&&response.scene){trackId=response.scene.track_id;canvasKind=response.scene.canvas_kind;}
      if(action.kind==='image_review'&&response.project_managed===true&&response.approved_project&&response.scene?.canvas_kind==='custom'){
        const requestId=action.requestId;
        request.sceneBuild={payload:{...response.approved_project,request_id:requestId,project_managed:true,proposal_id:response.proposal_id},
          owner,request,workflowEligible:true,trace:()=>{},settle:()=>{},stopping:false,stopConfirmed:false};
        stage.setSceneWorkflow?.({request_id:requestId,phase:'coding',project:response.approved_project,steps:[],summary:'Testing the selected image candidate.'});
      }
      if(response?.scene&&request.sceneBuild?.workflowEligible){
        response=await runCanvasBuildWorkflow(response,request,stage,requestActive);
        if(!requestActive())return;
      }
      if(response?.state==='needs_attention'){
        canvasProjects.current?.complete?.(request.sceneBuild?.payload.proposal_id,'needs_attention');
        const message='The candidate needs attention. Your current canvas is still open; see the Testing and Repair tabs.';
        popupEntry('notice',message);agent(st.playing?'playing':'idle',message);return;
      }
      if(response?.state==='canceled'){
        canvasProjects.current?.complete?.(request.sceneBuild?.payload.proposal_id,'canceled');
        request.sceneBuild?.trace('build_canceled');
        request.abort();
        const message=editingImage?'Image edit stopped.':'Canvas build stopped.';
        stage.setSceneImage?.('failed',editingImage?message:'Image generation stopped.');
        stage.setSceneCoding?.('cancelled',message);
        popupEntry('notice',message);agent(st.playing?'playing':'idle',message);
        return;
      }
      if(response?.state==='failed')throw new Error('This canvas request did not finish. You can ask Stonefellow to try again.');
      if(action.kind==='history'){
        if(!stage.showSceneHistory?.(response.scenes||[]))throw new Error('The history could not open.');
        closeChatPopup();return;
      }
      const scene=response.scene;
      if(scene?.workflow_required===true&&!response.workflow&&response.verification?.verified!==true){
        const key='unverified-scene-'+scene.id;
        stage.setSceneWorkflow?.(null);
        stage.setSceneImage?.('clear');
        stage.setSceneWorkflow?.({request_id:key,phase:'coding',project:response.approved_project||{brief:scene.design_brief||''},steps:[]});
        stage.setSceneCandidateCode?.(scene);
        stage.setSceneWorkflow?.({request_id:key,phase:'needs_attention',summary:'This saved candidate has not completed testing.'});
        const message='This saved candidate has not completed testing. I opened its source; your current canvas is still in place.';
        popupEntry('notice',message);agent(st.playing?'playing':'idle',message);return;
      }
      if(reading&&response.workflow&&scene){
        const workflow=response.workflow;
        stage.setSceneWorkflow?.(null);
        stage.setSceneImage?.('clear');
        stage.setSceneWorkflow?.({...workflow,phase:'coding'});
        stage.setSceneCandidateCode?.(scene);
        stage.setSceneWorkflow?.({...workflow,phase:workflow.candidate_scene_id===scene.id?workflow.state:'needs_attention',summary:workflow.message||'Saved project activity.'});
        if(workflow.candidate_scene_id!==scene.id||!['ready','applied'].includes(workflow.state)){
          if(['testing','reviewing','needs_repair','repairing'].includes(workflow.state))stopListeningRoomBuild.pending={
            payload:{request_id:workflow.request_id},owner,workflowEligible:true,stopping:false,settle:()=>{},
            workflowController:{cancel:()=>secureJsonPost('canvas-build-workflow.php',{action:'cancel',request_id:workflow.request_id})}};
          const message='This saved candidate has not passed the current checks. I opened its code and agent activity; your canvas is still in place.';
          popupEntry('notice',message);agent(st.playing?'playing':'idle',message);return;
        }
      }
      const standard=(response.design_source??scene?.design_source)==='standard';
      if(!stage.active?.()||!Number.isSafeInteger(scene?.id)||scene.id<1||
        (!reading&&!listeningRoomHasResult(response,String(trackId??''),canvasKind)))throw new Error('The canvas could not open.');
      if(editingImage&&(scene.id===previousId||!Array.isArray(scene.image_assets)||
        !scene.image_assets.some(asset=>asset?.id===request.sceneBuild?.payload.request_id)))
        throw new Error('The server could not confirm the revised image on the canvas.');
      await stage.prepareSceneLibraries?.(scene,{signal:request.signal});
      if(!requestActive()||request.sceneBuild?.stopRequested)return;
      if(reading)stage.setSceneImage?.('clear');
      if(reading&&action.kind!=='image_review'&&!response.workflow)stage.setSceneWorkflow?.(null);
      if(response.workflow){
        const committed=await secureJsonPost('canvas-build-workflow.php',{action:'apply',request_id:response.workflow.request_id,scene_id:scene.id},false,request.signal);
        if(!requestActive()||request.sceneBuild?.stopRequested)return;
        if(committed.workflow?.state!=='applied'||committed.workflow.candidate_scene_id!==scene.id)
          throw new Error('The server did not authorize this candidate to open. Your current canvas is still available.');
        response.workflow=committed.workflow;
      }
      if(!stage.showScene(scene,{standard}))throw new Error('The canvas could not open.');
      if(response.workflow){
        stage.setSceneWorkflow?.({...response.workflow,phase:'applied',summary:'The checked candidate is now open.'});
      }
      setCanvasImageEditDraft(null);
      listeningRoom=scene;
      canvasProjects.current?.complete?.(request.sceneBuild?.payload.proposal_id);
      selectCanvasId(owner,scene.canvas_kind==='space_eq'?null:scene.id);
      stage.closePage?.(true);
      request.sceneBuild?.trace('canvas_opened',{source:standard?'standard':'model'});
      if(!canvasDebugVisible())closeChatPopup();
      let message=action.kind==='image_review'||request.sceneBuild?.payload.review_images?'I applied your chosen image.':editingImage?'I updated the image and kept the original in your library.':previousId&&!design?(musicInputs?'I updated your canvas’s music selection.':response.active_tokens_used>0?'I updated how the agent appears.':'I updated how the agent appears. No Active Tokens were used.'):
        scene.canvas_kind==='space_eq'?(reading?'I reopened your Space EQ.':'I built your Space EQ with code. No Active Tokens were used.'):
        scene.canvas_kind==='custom'?(standard?(reading?'I reopened your built-in catalog layout.':'I opened your live catalog in a built-in layout. No Active Tokens were used.'):
          reading?'I reopened your canvas.':action.kind==='revise'?'I updated your canvas.':'Your canvas is ready.'):
        standard?'I opened a listening room using the built-in layout. No Active Tokens were used.':
        action.kind==='revise'?'I revised the listening room.':reading?'I reopened the listening room for “'+scene.title+'.”':'I built a listening room for “'+scene.title+'.”';
      if(!standard&&!reading&&Number.isSafeInteger(response.active_tokens_used)&&response.active_tokens_used>0)message+=' Used '+response.active_tokens_used.toLocaleString()+' Active Tokens.';
      popupEntry('assistant',message);
      agent(st.playing?'playing':'idle',message);
    }catch(error){
      if(!requestActive())return;
      const dataUnavailable=error.payload?.error==='scene_data_unavailable';
      request.sceneBuild?.trace(dataUnavailable?'data_load_failed':'build_failed',canvasDebugFailure(error));
      canvasProjects.current?.complete?.(request.sceneBuild?.payload.proposal_id,dataUnavailable?'needs_attention':'failed');
      if(error.payload?.error==='scene_coding_model_unavailable')void refreshCanvasCodingModelMenu(true);
      const message=dataUnavailable?error.message:(editingImage?'Image edit could not finish. ':action.kind==='suggest'?'Canvas suggestions could not be loaded. ':reading?'Canvas history could not be opened. ':
        canvasKind&&canvasKind!=='listening_room'?'Canvas could not be created. ':'Listening room could not be created. ')+listeningRoomError(error,reading);
      if(!reading){
        showCanvasBuildImages(stage,request.sceneBuild,error.payload);
        if(request.sceneBuild?.workflowEligible)stage.setSceneWorkflow?.({request_id:request.sceneBuild.payload.request_id,phase:dataUnavailable?'needs_attention':'failed',summary:message});
        request.abort();
        if(!dataUnavailable)stage.setSceneImage?.('failed',editingImage?'Image edit did not complete.':'Image generation did not complete.');
        stage.setSceneCoding?.('error',message);
      }
      popupEntry('notice',message);agent(st.playing?'playing':'idle',message);
    }finally{
      if(request.sceneBuild?.stopConfirmed)request.abort();
      if(listeningRoomRequest===request)listeningRoomRequest=null;
    }
  }
  async function liveAgent(text){
    if(handleAgentVisibilityCommand(text))return;
    if(canvasProjects()?.handleCommand(text))return;
    const listeningRoomCommand=directListeningRoomCommand(text);
    if(listeningRoomCommand){await handleListeningRoomCommand(listeningRoomCommand);return;}
    if(directRandomPlaylistCommand(text)){
      const result=await startRandomTemporaryPlaylist();
      await say(result);
      return;
    }
    const directAction=directSongListeningCommand(text);
    if(directAction){
      try{
        const result=await executeSongCaptureAction(directAction);
        if(directAction.type==='song_capture_analyze'&&songLyricReport?.report===result){
          await say(result);
        }else if(result){
          // The listening status lives in the orb sidecar. No capture
          // metadata or provisional transcript is spoken back into the mic.
          popupEntry('notice',String(result));
          if(directAction.type==='song_capture_live_start'&&songListeningSkill?.status().active)
            agent('listening','Listening to the playing song. Say stop listening to review the lyrics.');
        }
      }catch(e){popupEntry('notice','Song listening could not complete: '+String(e.message||'Please retry.'));}
      return;
    }
    await loadAuth();
    if(!st.auth.authenticated){
      await say('Create a free account to use the live Stonefellow AI agent. Your music player will keep playing.',()=>navigate('register'));
      return;
    }
    agent('thinking','Thinking…');
    try{
      const privateResult=await executePrivateAnnotationCommand(text);
      if(privateResult!==null){
        if(typeof privateResult==='object'&&privateResult.privateDisplay){
          popupEntry('notice',privateResult.privateDisplay);await say(privateResult.text);
        }else await say(privateResult);
        return;
      }
      const client_state=agentClientState();
      const contextEpoch=popupHistoryEpoch,chatOwner=canvasOwner();
      const j=await secureJsonPost('agent-chat.php',{message:text,conversation_id:st.conversationId,client_state});
      if(contextEpoch!==popupHistoryEpoch||chatOwner!==canvasOwner())return;
      st.conversationId=j.conversation_id||st.conversationId;
      if(st.conversationId)localStorage.setItem('stonefellow.agent.conversation',st.conversationId);
      const playerResult=await dispatchAgentBrowserAction(text,j.action,j.audio_resolution);
      // Persist only the outcome reported by the local player/action adapter.
      // A queued server action alone must never become a claimed success on
      // later Agent turns; failed receipts stay marked unconfirmed.
      if(Number.isSafeInteger(Number(j.action_receipt_id))&&Number(j.action_receipt_id)>0
        &&typeof playerResult==='string'&&playerResult.trim()){
        try{
          await secureJsonPost('agent-action-receipt.php',{
            conversation_id:st.conversationId,message_id:Number(j.action_receipt_id),
            action_type:String(j.action?.type||''),text:playerResult.trim()
          });
        }catch(_){/* Keep unconfirmed server history if the receipt is unavailable. */}
      }
      if(contextEpoch===popupHistoryEpoch&&chatOwner===canvasOwner())
        await say(playerResult??(String(j.text||'').trim()||'The Agent received your message but returned no reply. Please try again.'));
    }catch(e){
      if(e.name==='AbortError')return;
      if(e.payload?.error==='active_tokens_required'){
        await say(e.message||'You are out of Active Tokens.',()=>navigate('plans'));return;
      }
      if(e.payload?.reference){await say(e.message);return;}
      await say('The live agent is unavailable right now. '+e.message);
    }
  }
  function membershipBenefitList(b={}){const x=[];if(Number(b.merch_discount_percent||0)>0)x.push(Number(b.merch_discount_percent)+'% merch discount');if(Number(b.early_access_days||0)>0)x.push(Number(b.early_access_days)+' days early access');if(b.vip_access)x.push('VIP access');if(b.priority_presale)x.push('priority presale');if(b.member_content)x.push('member exclusives');if(b.exclusive_downloads)x.push('exclusive downloads');if(b.member_only_offers)x.push('member-only offers');return x}
  function membershipBenefitText(b={}){const x=membershipBenefitList(b);return esc(x.length?x.join(' · '):'Stonefellow membership benefits')}
  function memberContentCard(c){const a=c.access||{},open=!!a.allowed,media=open?publicEntityMediaHtml(c.media||[],'MEMBER MEDIA'):'';return '<article class="member-content-card '+(open?'unlocked':'locked')+'"><div class="member-content-head"><div><div class="module-kicker">'+esc(String(c.content_type||'member').replaceAll('_',' '))+(a.early_access?' · EARLY ACCESS':'')+'</div><h3>'+esc(c.title)+'</h3></div><span class="member-lock">'+(open?'UNLOCKED':'MEMBERS')+'</span></div><p>'+esc(c.teaser||'')+'</p>'+(open&&c.body_text?'<div class="member-content-body">'+esc(c.body_text).replace(/\n/g,'<br>')+'</div>':'')+(open&&c.external_url?'<a class="buy-primary button-link" href="'+esc(c.external_url)+'" target="_blank" rel="noopener">Open member access</a>':'')+media+(!open?'<small>'+esc(a.required_package_name?('Requires '+a.required_package_name):'Active membership required')+'</small>':'')+'</article>'}
  async function renderMembership(){
    starters.hidden=true;let j;try{j=await api('membership.php')}catch(e){canvas.innerHTML='<article class="module"><div class="module-kicker">MEMBERSHIP + VIP</div><h2>Membership unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const state=j.state||{},plans=j.plans||[],content=j.content||[];
    const hero=state.active?'<section class="membership-hero active"><div><div class="module-kicker">YOUR MEMBERSHIP</div><h2>'+esc(state.badge||state.package_name||'Stonefellow Member')+'</h2><p>'+membershipBenefitText(state.benefits||{})+'</p>'+(state.cancel_at_period_end?'<small>Benefits remain active through '+esc(String(state.current_period_end||'').slice(0,10))+'.</small>':'')+'</div><span class="membership-rank">TIER '+Number(state.rank||0)+'</span></section>':'<section class="membership-hero"><div><div class="module-kicker">STONEFELLOW MEMBERSHIP</div><h2>Closer to the music.</h2><p>Membership can unlock early access, VIP offers, member-only media, exclusive downloads and merch benefits.</p></div><button class="buy-primary" type="button" data-view="plans">'+(j.authenticated?'Choose a membership':'View membership plans')+'</button></section>';
    const tiers='<section class="member-tier-public"><div class="module-kicker">MEMBERSHIP TIERS</div><div class="plan-grid">'+plans.map(p=>'<article class="plan-card '+(p.is_featured?'featured':'')+'"><div class="module-kicker">'+esc(p.membership?.badge||p.name)+'</div><h3>'+esc(p.name)+'</h3><div class="plan-price">'+(p.monthly_price_cents?money(p.monthly_price_cents):'Free')+(p.monthly_price_cents?'<small> / month</small>':'')+'</div><div class="plan-features">'+membershipBenefitList(p.membership?.benefits||{}).map(x=>'<span>• '+esc(x)+'</span>').join('')+'</div><button class="text-control" type="button" data-view="plans">View plan</button></article>').join('')+'</div></section>';
    canvas.innerHTML='<article class="module membership-module">'+hero+(content.length?'<section class="member-content-grid"><div class="module-kicker">MEMBER ACCESS</div>'+content.map(memberContentCard).join('')+'</section>':'<section class="member-content-grid"><p class="player-note">Member exclusives will appear here when published.</p></section>')+tiers+'</article>';bind(canvas)
  }
  async function renderPlans(){await Promise.all([loadPlans(),loadAuth(),loadStore()]);starters.hidden=true;canvas.innerHTML=`<article class="module"><div class="module-kicker">STONEFELLOW MEMBERSHIP</div><h2>Plans</h2><p class="player-note">Active Tokens power live AI conversation and optional ElevenLabs voice. Paid production plans use secure hosted billing when configured.</p><div class="plan-grid">${st.plans.map(p=>`<section class="plan-card ${p.is_featured?'featured':''}"><div class="module-kicker">${p.membership?.enabled?esc(p.membership.badge||'MEMBERSHIP'):(p.trial_days?`${p.trial_days}-DAY FREE TRIAL`:'MONTHLY')}</div><h3>${esc(p.name)}</h3><div class="plan-price">${p.monthly_price_cents?money(p.monthly_price_cents):'Free'}${p.monthly_price_cents?'<small> / month</small>':''}</div><p class="player-note">${esc(p.description||'')}</p><div class="plan-features">${(p.features||[]).map(x=>`<span>• ${esc(x)}</span>`).join('')}${p.membership?.enabled?membershipBenefitList(p.membership.benefits||{}).map(x=>`<span>• ${esc(x)}</span>`).join(''):''}<span>• ${Number(p.monthly_tokens||0).toLocaleString()} monthly Active Tokens</span>${p.trial_tokens?`<span>• ${Number(p.trial_tokens).toLocaleString()} trial tokens</span>`:''}</div><button class="buy-primary" type="button" data-choose-plan="${p.id}">${st.auth.authenticated?'Choose plan':'Start free trial'}</button></section>`).join('')||'<p class="player-note">No public packages are available yet.</p>'}</div></article>`;$$('[data-choose-plan]',canvas).forEach(b=>b.onclick=()=>{st.selectedPlanId=Number(b.dataset.choosePlan);st.auth.authenticated?activatePlan(st.selectedPlanId):navigate('register',{plan:st.selectedPlanId})})}
  async function activatePlan(id){const p=st.plans.find(x=>Number(x.id)===Number(id));if(!p)return;try{const j=await secureJsonPost('subscription.php',{action:'choose_package',package_id:id});if(j.checkout_url){location.href=j.checkout_url;return}if(j.portal_url){location.href=j.portal_url;return}say((j.simulated?'Test subscription activated. ':'')+p.name+' is now active.',()=>navigate('account'))}catch(e){say(e.message)}}
  async function loadStore(){if(st.store)return st.store;try{const health=await fetch((cfg.apiBase||'api')+'/health.php',{cache:'no-store'}).then(r=>r.json());st.store={mode:health.mode||'test',currency:'USD'};return st.store}catch(_){st.store={mode:'offline',currency:'USD'};return st.store}}
  async function refreshSiteData(){try{const r=await fetch((cfg.apiBase||'api')+'/site-data.php',{cache:'no-store'});const j=await r.json();if(r.ok&&j.ok){if(Array.isArray(j.catalog))catalog=j.catalog.map(x=>({...x,mood:x.mood||[],themes:x.themes||[],credits:x.credits||[]}));if(Array.isArray(j.releases))releases=j.releases;if(Array.isArray(j.shows))shows=j.shows;return true}}catch(_){}return false}
  function publicRouteUrl(view,params={}){
    const id=encodeURIComponent(String(params.id||'')),slug=encodeURIComponent(String(params.slug||'')),base=document.baseURI,finish=u=>{if(window.STONEFELLOW_THEME_PREVIEW)u.searchParams.set('theme_preview','1');return u};
    if(!view||view==='home')return finish(new URL('./',base));
    if(view==='world'&&id){const u=finish(new URL('world',base));u.searchParams.set('id',String(params.id).slice(0,180));return u;}
    if(view==='track'&&id)return finish(new URL('track/'+id,base));
    if(view==='release'&&id)return finish(new URL('release/'+id,base));
    if(view==='show'&&id)return finish(new URL('show/'+id,base));
    if(view==='store'&&slug)return finish(new URL('store/'+slug,base));
    if(view==='campaign'&&slug)return finish(new URL('campaign/'+slug,base));
    if(view==='story'&&slug)return finish(new URL('story/'+slug,base));
    if(view==='video'&&slug)return finish(new URL('video/'+slug,base));
    if(view==='photo'&&slug)return finish(new URL('photos/'+slug,base));
    if(view==='press'&&slug){const u=new URL('press/'+slug,base);if(params.token)u.hash='token='+encodeURIComponent(String(params.token));return finish(u)}
    if(view==='contact'){const u=finish(new URL('contact',base));if(['booking','press','licensing','collaboration','general'].includes(String(params.type||'')))u.searchParams.set('type',String(params.type));return u}if(view==='search'){const u=finish(new URL('search',base));if(params.q)u.searchParams.set('q',String(params.q).slice(0,300));if(params.type&&params.type!=='all'&&['track','release','show','story','video','photo','product','campaign'].includes(String(params.type)))u.searchParams.set('type',String(params.type));return u}if(['world','music','releases','shows','store','about','archive','videos'].includes(view))return finish(new URL(view,base));if(view==='photos')return finish(new URL('photos',base));
    const u=new URL('./',base);u.searchParams.set('view',view);Object.entries(params).forEach(([k,v])=>{if(k!=='token'&&v!==undefined&&v!==null&&v!=='')u.searchParams.set(k,String(v))});return finish(u);
  }
  async function syncSeo(view,params={}){
    try{
      const qs=new URLSearchParams({view:String(view||'home')});if(params.id)qs.set('id',String(params.id));if(params.slug)qs.set('slug',String(params.slug));
      const j=await api('seo.php?'+qs.toString()),m=j.meta||{};if(window.STONEFELLOW_THEME_PREVIEW){m.robots='noindex,nofollow,noarchive';m.canonical=''}if(m.title)document.title=m.title;
      const setMeta=(sel,attr,val)=>{let el=document.querySelector(sel);if(!val){el?.remove();return}if(!el){el=document.createElement('meta');if(attr==='name')el.setAttribute('name',sel.match(/\[name="([^"]+)"/)?.[1]||'');else el.setAttribute('property',sel.match(/\[property="([^"]+)"/)?.[1]||'');document.head.appendChild(el)}el.setAttribute('content',String(val))};
      setMeta('meta[name="description"]','name',m.description||'');setMeta('meta[name="robots"]','name',m.robots||'');
      for(const [prop,val] of [['og:title',m.title],['og:description',m.description],['og:type',m.type],['og:url',m.canonical],['og:image',m.image]])setMeta('meta[property="'+prop+'"]','property',val||'');
      for(const [name,val] of [['twitter:title',m.title],['twitter:description',m.description],['twitter:image',m.image],['twitter:card',m.image?'summary_large_image':'summary']])setMeta('meta[name="'+name+'"]','name',val||'');
      let canonical=document.querySelector('link[rel="canonical"]');if(m.canonical){if(!canonical){canonical=document.createElement('link');canonical.rel='canonical';document.head.appendChild(canonical)}canonical.href=m.canonical}else canonical?.remove();
      document.querySelectorAll('script[data-seo-jsonld]').forEach(x=>x.remove());(m.json_ld||[]).forEach(row=>{const el=document.createElement('script');el.type='application/ld+json';el.dataset.seoJsonld='1';el.textContent=JSON.stringify(row);document.head.appendChild(el)});
    }catch(_){}
  }
  async function recordDiscovery(event_type,extra={}){
    if(window.STONEFELLOW_THEME_PREVIEW)return;try{const u=new URL(location.href);await api('discovery.php',{event_type,referrer:document.referrer||'',utm_source:u.searchParams.get('utm_source')||'',utm_medium:u.searchParams.get('utm_medium')||'',utm_campaign:u.searchParams.get('utm_campaign')||'',...extra})}catch(_){}
  }
  function shareButton(view,id,title,slug=''){return '<button class="text-control" type="button" data-share-view="'+esc(view)+'" data-share-id="'+esc(id||'')+'" data-share-slug="'+esc(slug||'')+'" data-share-title="'+esc(title||'Stonefellow')+'">Share</button>'}
  async function shareRoute(view,id,title,slug=''){
    const params={};if(id)params.id=id;if(slug)params.slug=slug;const url=publicRouteUrl(view,params).href;
    if(navigator.share){try{await navigator.share({title:title||'Stonefellow',url});await recordDiscovery('share',{route_type:view,route_id:id||slug||'',share_target:'native'});if(view==='story'&&id)await storyEvent(Number(id),'share',{target:'native'});return}catch(e){if(e?.name==='AbortError')return}}
    try{await navigator.clipboard.writeText(url);await recordDiscovery('share',{route_type:view,route_id:id||slug||'',share_target:'copy'});if(view==='story'&&id)await storyEvent(Number(id),'share',{target:'copy'});say('Share link copied.')}catch(_){say(url)}
  }
  function bindShareButtons(r=document){$$('[data-share-view]',r).forEach(b=>b.onclick=()=>shareRoute(b.dataset.shareView,b.dataset.shareId||'',b.dataset.shareTitle||'Stonefellow',b.dataset.shareSlug||''))}
  function setUrl(view,params={}){const u=publicRouteUrl(view,params);history.pushState({view,params},'',u);syncSeo(view,params)}
  function closeMenu(){if(menu?.tagName==='DETAILS'){menu.open=false}else{menu?.classList.remove('open');menu?.setAttribute('aria-hidden','true')}$('#menuButton')?.setAttribute('aria-expanded','false');window.SFTokenMeter?.setOpen?.(false)}
  function recommendationCard(x){const t=trackById(x.track_id)||x,reasons=Array.isArray(x.reasons)?x.reasons:[];return `<article class="recommendation-card"><button class="recommendation-play" type="button" data-recommend-play="${esc(x.track_id)}" aria-label="Play ${esc(x.title||t.title||'recommendation')}">▶</button><button class="recommendation-main" type="button" data-recommend-open="${esc(x.track_id)}"><span class="recommendation-art">${(x.artwork||t.artwork)?`<img src="${esc(x.artwork||t.artwork)}" alt="">`:'SF'}</span><span><strong>${esc(x.title||t.title||x.track_id)}</strong><small>${esc(x.release||t.release||'Stonefellow')}</small>${reasons.length?`<em>${esc(reasons[0])}</em>`:''}</span></button>${favoriteButton('track',x.track_id,'Favorite')}</article>`}
  function bindRecommendationCards(r=document){Array.from(r.querySelectorAll('[data-recommend-play]')).forEach(b=>b.onclick=e=>{e.stopPropagation();const t=trackById(b.dataset.recommendPlay);if(t)play(t,0,'recommendation')});Array.from(r.querySelectorAll('[data-recommend-open]')).forEach(b=>b.onclick=()=>{const t=trackById(b.dataset.recommendOpen);if(t){renderStory(t);setUrl('track',{id:t.id})}});bindFavoriteButtons(r)}
  function homeArtwork(src,label='SF'){return `<span class="home-card-art">${src?`<img src="${esc(src)}" alt="">`:`<span>${esc(label)}</span>`}</span>`}
  function homeEmpty(text,button='',attrs=''){return `<div class="home-empty"><p>${esc(text)}</p>${button?`<button class="text-control compact" type="button" ${attrs}>${esc(button)}</button>`:''}</div>`}
  function homeTrackCard(x,mode='play'){const id=x.track_id||x.id,t=trackById(id)||x,progress=Number(x.progress_percent||0),resume=mode==='resume',action=resume?`data-home-resume="${esc(id)}" data-position="${Number(x.position_seconds||0)}"`:`data-home-play="${esc(id)}"`;return `<article class="home-card home-track-card"><button class="home-card-main" type="button" ${action}>${homeArtwork(x.artwork||t.artwork,'SF')}<span><strong>${esc(x.title||t.title||id)}</strong><small>${esc(x.release||t.release||'Stonefellow')}</small>${resume?`<span class="home-progress"><i style="width:${Math.max(0,Math.min(100,progress))}%"></i></span><em>Resume at ${time(x.position_seconds||0)}</em>`:''}</span></button><button class="home-card-open" type="button" data-home-track-open="${esc(id)}" aria-label="Open ${esc(x.title||t.title||'track')}">›</button></article>`}
  function homeReleaseCard(x,favorite=false){return `<article class="home-card home-release-card"><button class="home-card-main" type="button" data-home-release="${esc(x.id)}">${homeArtwork(x.artwork,'SF')}<span><strong>${esc(x.title||'Release')}</strong><small>${esc((x.type||'release').toUpperCase())}${x.release_date?' · '+esc(x.release_date):''}</small>${x.track_count?`<em>${Number(x.track_count)} track${Number(x.track_count)===1?'':'s'}</em>`:''}</span></button>${favorite?favoriteButton('release',x.id,'Favorite release'):''}</article>`}
  function homeBuildCard(x){const b=x.build||{},count=(b.A?.length||0)+(b.B?.length||0);return `<article class="home-card home-build-card"><button class="home-card-main" type="button" data-home-build="${Number(x.id)}"><span class="home-build-icon">${esc(String(x.format||b.format||'vinyl').toUpperCase())}</span><span><strong>${esc(x.name||b.title||'Custom record')}</strong><small>${count} track${count===1?'':'s'} · updated ${esc(String(x.updated_at||'').replace('T',' ').slice(0,10))}</small><em>Continue editing Side A / Side B</em></span></button></article>`}
  function homeFavoriteCard(x,type){if(type==='release')return homeReleaseCard(x,true);return homeTrackCard({...x,track_id:x.id},'play')}
  function homeSection(kicker,title,body,action=''){return `<section class="home-section"><div class="home-section-head"><div><div class="module-kicker">${esc(kicker)}</div><h2>${esc(title)}</h2></div>${action}</div>${body}</section>`}
  function openHomeBuild(id){const rows=st.home.data?.saved_builds||[],row=rows.find(x=>Number(x.id)===Number(id));if(!row)return;st.builderDrafts={loaded:true,items:rows,activeId:Number(row.id)};localStorage.setItem('stonefellow.build.draft.id',String(row.id));st.builder={...defaultBuild(),...(row.build||{}),version:4};persist();navigate('builder')}
  function executeHomeSuggestion(suggestion){const a=suggestion?.action||{};if(a.type==='resume_track'){resumeTrack(a.track_id,a.position_seconds||0);return}if(a.type==='play_track'){const t=trackById(a.track_id);if(t)play(t,0,'home_suggestion');return}if(a.type==='open_build'){openHomeBuild(a.build_id);return}if(a.type==='open_release'){openRelease(a.release_id,true);return}if(a.type==='open_view'){navigate(a.view||'music')}}
  function bindPersonalizedHome(r=document){Array.from(r.querySelectorAll('[data-home-resume]')).forEach(b=>b.onclick=()=>resumeTrack(b.dataset.homeResume,Number(b.dataset.position||0)));Array.from(r.querySelectorAll('[data-home-play]')).forEach(b=>b.onclick=()=>{const t=trackById(b.dataset.homePlay);if(t)play(t,0,'home')});Array.from(r.querySelectorAll('[data-home-track-open]')).forEach(b=>b.onclick=()=>navigate('track',{id:b.dataset.homeTrackOpen}));Array.from(r.querySelectorAll('[data-home-release]')).forEach(b=>b.onclick=()=>openRelease(b.dataset.homeRelease,true));Array.from(r.querySelectorAll('[data-home-build]')).forEach(b=>b.onclick=()=>openHomeBuild(b.dataset.homeBuild));const suggestion=$('#homeSuggestionAction',r);if(suggestion)suggestion.onclick=()=>executeHomeSuggestion(st.home.data?.suggestion);bindRecommendationCards(r);bindFavoriteButtons(r);bind(r)}
  function newsletterSignupHtml(compact=false){const user=st.auth.user||{};return `<section class="newsletter-signup ${compact?'compact':''}"><div><div class="module-kicker">STONEFELLOW NEWSLETTER</div><h3>Music, shows & community updates</h3><p class="player-note">Join the Stonefellow list. Your email feeds the fan CRM and is used for Stonefellow news only; you can unsubscribe anytime.</p></div><form data-newsletter-form class="newsletter-form"><input name="name" maxlength="120" placeholder="Name (optional)" value="${esc(user.display_name||'')}"><input name="email" type="email" required maxlength="190" placeholder="Email address" value="${esc(user.email||'')}"><input class="newsletter-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"><button class="buy-primary" type="submit">Join newsletter</button><span data-newsletter-status class="newsletter-status"></span></form></section>`}
  function bindNewsletterForms(r=document){$$('[data-newsletter-form]',r).forEach(f=>f.onsubmit=async e=>{e.preventDefault();const fd=new FormData(f),status=$('[data-newsletter-status]',f),button=$('button[type="submit"]',f);if(button)button.disabled=true;if(status)status.textContent='Joining…';try{const res=await fetch((cfg.apiBase||'api')+'/newsletter.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'signup',name:fd.get('name')||'',email:fd.get('email')||'',website:fd.get('website')||''})});const j=await res.json();if(!res.ok||j.ok===false)throw new Error(j.message||'Newsletter signup failed.');if(status)status.textContent=j.message||'You’re on the list.'}catch(x){if(status)status.textContent=x.message}finally{if(button)button.disabled=false}})}
  function renderNewsletter(){starters.hidden=true;canvas.innerHTML=`<article class="module community-module"><div class="module-kicker">STAY CONNECTED</div><h2>Stonefellow Newsletter</h2><p class="player-note">Join for Stonefellow music, release, show and project updates. Newsletter and CRM remain active independently of the fan community.</p>${newsletterSignupHtml(false)}</article>`;bindNewsletterForms(canvas);bind(canvas)}
  async function renderCommunity(params={}){if(!fanCommunityEnabled){renderNewsletter();return}await loadAuth();let unsubscribeMessage='';if(params.unsubscribe){try{const r=await fetch((cfg.apiBase||'api')+'/newsletter.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'unsubscribe',token:params.unsubscribe})}),j=await r.json();unsubscribeMessage=j.message||''}catch(_){unsubscribeMessage='Could not update newsletter preference.'}}
    let data={posts:[]};try{data=await api('community.php')}catch(_){}
    const posts=data.posts||[],composer=st.auth.authenticated?`<form id="communityPostForm" class="community-composer"><textarea id="communityPostBody" maxlength="600" placeholder="Share something with the Stonefellow community…" required></textarea><div><span id="communityPostStatus" class="newsletter-status"></span><button class="buy-primary" type="submit">Post</button></div></form>`:`<div class="community-signin"><p>Sign in to post to the fan community.</p><button class="text-control" type="button" data-view="login">Sign in</button><button class="text-control" type="button" data-view="register">Create account</button></div>`;
    canvas.innerHTML=`<article class="module community-module"><div class="module-kicker">FAN COMMUNITY</div><div class="community-head"><div><h2>Stonefellow Community</h2><p class="player-note">A direct fan space connected to your Stonefellow account, listening history, and Agent experience.</p></div><button class="text-control" type="button" data-command="what do you know about my fan profile">Ask my agent</button></div>${unsubscribeMessage?`<div class="account-alert"><div><strong>Newsletter</strong><span>${esc(unsubscribeMessage)}</span></div></div>`:''}${newsletterSignupHtml(false)}<section class="community-feed-section"><div class="module-kicker">COMMUNITY FEED</div>${composer}<div class="community-feed">${posts.length?posts.map(p=>`<article class="community-post"><header><strong>${esc(p.display_name||'Stonefellow fan')}</strong><time>${esc(fmtWhen(p.created_at))}</time></header><p>${esc(p.body_text)}</p>${p.agent_reply_text?`<div class="community-agent-reply"><strong>Stonefellow Agent</strong><p>${esc(p.agent_reply_text)}</p></div>`:''}${st.auth.authenticated&&Number(st.auth.user?.id)===Number(p.user_id)?`<button class="tiny-control" type="button" data-community-delete="${p.id}">Delete</button>`:''}</article>`).join(''):'<div class="search-empty"><strong>No community posts yet.</strong><span>Be the first fan to start the conversation.</span></div>'}</div></section></article>`;
    bind(canvas);bindNewsletterForms(canvas);const form=$('#communityPostForm',canvas);if(form)form.onsubmit=async e=>{e.preventDefault();const body=$('#communityPostBody',form).value.trim(),status=$('#communityPostStatus',form);if(!body)return;try{await secureJsonPost('community.php',{action:'create',body});st.fanEngagementLoaded=false;await renderCommunity({});setTimeout(()=>loadFanEngagement(true),250)}catch(x){if(status)status.textContent=x.message}};$$('[data-community-delete]',canvas).forEach(b=>b.onclick=async()=>{if(!confirm('Delete this community post?'))return;try{await secureJsonPost('community.php',{action:'delete',id:Number(b.dataset.communityDelete)});renderCommunity({})}catch(x){say(x.message)}});if(params.newsletter)setTimeout(()=>$('[data-newsletter-form] input[type="email"]',canvas)[0]?.focus(),50)}
  async function renderPress(slugValue,tokenValue=''){
    starters.hidden=true;const slug=String(slugValue||'').trim(),token=String(tokenValue||'');
    if(!slug){canvas.innerHTML='<article class="module press-public"><div class="module-kicker">STONEFELLOW PRESS</div><h2>Press kit unavailable</h2></article>';return}
    let data;try{data=await api('press.php',{action:'load',slug,token})}catch(e){canvas.innerHTML='<article class="module press-public"><div class="module-kicker">STONEFELLOW PRESS</div><h2>Press kit unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const k=data.kit||{},r=k.release||null,media=k.media||[],artist=k.artist_media||{},releaseMedia=r?.media||[],hero=media.find(x=>x.category==='image'&&x.role==='hero')?.url||releaseMedia.find(x=>x.category==='image'&&x.role==='cover')?.url||artist.artist_photo||'',tracks=k.tracks||[],live=r?.distribution?.live_links||[];
    const releaseHtml=r?'<section class="press-public-section"><div class="module-kicker">RELEASE</div><div class="press-release-card">'+(releaseMedia.find(x=>x.category==='image'&&x.role==='cover')?.url?'<img src="'+esc(releaseMedia.find(x=>x.category==='image'&&x.role==='cover').url)+'" alt="'+esc(r.title)+' cover">':'')+'<div><h3>'+esc(r.title)+'</h3><p class="press-meta">'+esc([r.type,r.release_date,r.genre].filter(Boolean).join(' · '))+'</p>'+(r.description?'<p>'+esc(r.description)+'</p>':'')+(r.credits?.length?'<ul class="credit-list">'+r.credits.map(x=>'<li>'+esc(x)+'</li>').join('')+'</ul>':'')+(live.length?'<div class="press-dsp-links">'+live.map(x=>'<a data-press-dsp="'+esc(x.provider)+'" href="'+esc(x.url)+'" target="_blank" rel="noopener">'+esc(String(x.provider||'DSP').replaceAll('_',' '))+' ↗</a>').join('')+'</div>':'')+'</div></div></section>':'';
    const tracksHtml=tracks.length?'<section class="press-public-section"><div class="module-kicker">LISTEN</div><h3>Selected music</h3><div class="press-track-list">'+tracks.map(t=>'<article><div><strong>'+esc(t.title)+'</strong><small>'+(t.duration?time(t.duration):'')+'</small></div>'+(t.audio?.url?'<audio controls preload="metadata" data-press-audio="'+esc(t.id)+'" src="'+esc(t.audio.url)+'"></audio>':'<span class="press-no-preview">Public audio preview not enabled</span>')+'</article>').join('')+'</div></section>':'';
    const contactHtml=(k.contact_email||k.website_url)?'<section class="press-public-section press-contact-card"><div class="module-kicker">PRESS CONTACT</div>'+(k.contact_name?'<strong>'+esc(k.contact_name)+'</strong>':'')+(k.contact_email?'<a data-press-contact href="mailto:'+esc(k.contact_email)+'">'+esc(k.contact_email)+'</a>':'')+(k.website_url?'<a data-press-link href="'+esc(k.website_url)+'" target="_blank" rel="noopener">Stonefellow website ↗</a>':'')+'</section>':'';
    canvas.innerHTML='<article class="module press-public">'+(hero?'<div class="press-public-hero"><img src="'+esc(hero)+'" alt=""></div>':'')+'<div class="module-kicker">STONEFELLOW · ELECTRONIC PRESS KIT</div><h1>'+esc(k.headline||k.title||'Stonefellow')+'</h1>'+(k.summary?'<p class="press-public-summary">'+esc(k.summary).replace(/\\n/g,'<br>')+'</p>':'')+(k.bio?'<section class="press-public-section"><div class="module-kicker">BIO</div><div class="press-bio">'+esc(k.bio).replace(/\\n/g,'<br>')+'</div></section>':'')+releaseHtml+tracksHtml+publicEntityMediaHtml(media,'PRESS MEDIA')+contactHtml+'</article>';
    $$('[data-press-audio]',canvas).forEach(a=>a.addEventListener('play',()=>api('press.php',{slug,token,event_type:'release_play',detail:{track_id:a.dataset.pressAudio}}).catch(()=>{}),{once:true}));
    const contact=$('[data-press-contact]',canvas);if(contact)contact.onclick=()=>api('press.php',{slug,token,event_type:'contact_click',detail:{kind:'email'}}).catch(()=>{});
    $$('[data-press-link]',canvas).forEach(a=>a.onclick=()=>api('press.php',{slug,token,event_type:'contact_click',detail:{kind:'website'}}).catch(()=>{}));
    $$('[data-press-dsp]',canvas).forEach(a=>a.onclick=()=>api('press.php',{slug,token,event_type:'dsp_click',detail:{provider:a.dataset.pressDsp}}).catch(()=>{}));
    $$('.public-entity-media a',canvas).forEach(a=>a.addEventListener('click',()=>api('press.php',{slug,token,event_type:a.href.includes('download=1')?'download_click':'media_click',detail:{role:a.dataset?.role||''}}).catch(()=>{})));
  }
  async function renderCampaign(slugValue){
    starters.hidden=true;const campaignSlug=String(slugValue||'').trim();if(!campaignSlug){canvas.innerHTML='<article class="module"><h2>Campaign not found.</h2></article>';return}
    let data;try{data=await api('campaign.php?slug='+encodeURIComponent(campaignSlug))}catch(e){canvas.innerHTML='<article class="module campaign-public"><div class="module-kicker">STONEFELLOW CAMPAIGN</div><h2>Campaign unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const c=data.campaign||{},l=c.landing||{},offers=c.offers||[];
    const hero=(c.artwork?'<div class="campaign-public-art"><img src="'+esc(c.artwork)+'" alt=""></div>':'');
    canvas.innerHTML='<article class="module campaign-public">'+hero+'<div class="module-kicker">STONEFELLOW CAMPAIGN</div><h2>'+esc(c.headline||c.name||'Stonefellow')+'</h2><p class="campaign-public-copy">'+esc(c.body_text||'').replace(/\n/g,'<br>')+'</p><form id="campaignEntryForm" class="campaign-entry-form"><h3>'+esc(l.form_title||'Join this campaign')+'</h3><input name="name" maxlength="120" placeholder="Name'+(l.require_name?'':' (optional)')+'" '+(l.require_name?'required':'')+'><input name="email" type="email" maxlength="190" placeholder="Email address" required><input name="website" class="newsletter-hp" tabindex="-1" autocomplete="off" aria-hidden="true"><label class="campaign-consent"><input name="marketing_opt_in" type="checkbox"> '+esc(l.newsletter_label||'Send me Stonefellow news and offers')+'</label><button class="buy-primary" type="submit">'+esc(l.cta||'Continue')+'</button><span id="campaignEntryStatus" class="newsletter-status"></span></form><div id="campaignOfferArea"></div>'+publicEntityMediaHtml(c.media||[],'CAMPAIGN MEDIA')+'</article>';
    const f=$('#campaignEntryForm',canvas),statusEl=$('#campaignEntryStatus',canvas);let started=false;
    f.oninput=()=>{if(!started){started=true;api('campaign.php',{action:'event',slug:campaignSlug,event_type:'form_started'}).catch(()=>{})}};
    f.onsubmit=async e=>{e.preventDefault();const d=new FormData(f),btn=$('button[type="submit"]',f);btn.disabled=true;statusEl.textContent='Working…';try{const j=await api('campaign.php',{action:'enter',slug:campaignSlug,name:d.get('name')||'',email:d.get('email')||'',website:d.get('website')||'',marketing_opt_in:d.get('marketing_opt_in')==='on'});statusEl.textContent=l.success_message||'You’re in.';renderCampaignOffers(campaignSlug,j.campaign?.offers||offers)}catch(x){statusEl.textContent=x.message;btn.disabled=false}};
  }
  function renderCampaignOffers(slugValue,offers){const host=$('#campaignOfferArea',canvas);if(!host)return;if(!offers.length){host.innerHTML='<div class="campaign-success-note">Thanks for connecting with Stonefellow.</div>';return}host.innerHTML='<section class="campaign-offers"><div class="module-kicker">YOUR OFFERS</div>'+offers.map(o=>'<article class="campaign-offer-card"><div><strong>'+esc(o.title||'Campaign offer')+'</strong><p>'+esc(o.description||'')+'</p></div><button class="buy-primary" type="button" data-campaign-claim="'+esc(o.node_id)+'">'+esc(o.cta||'Claim offer')+'</button><div class="campaign-offer-result" data-offer-result="'+esc(o.node_id)+'"></div></article>').join('')+'</section>';$$('[data-campaign-claim]',host).forEach(b=>b.onclick=()=>claimCampaignOffer(slugValue,b.dataset.campaignClaim))}
  async function claimCampaignOffer(slugValue,nodeId){const result=$('[data-offer-result="'+CSS.escape(nodeId)+'"]',canvas);try{const j=await api('campaign.php',{action:'claim',slug:slugValue,node_id:nodeId}),o=j.offer||{},p=o.payload||{};if(o.type==='offer_download'&&o.download_url){result.innerHTML='<a class="buy-primary button-link" href="'+esc(o.download_url)+'">Download song</a>'}else if(o.type==='offer_discount'){st.campaignCode=o.code||'';localStorage.setItem('stonefellow.campaign.code',st.campaignCode);result.innerHTML='<strong>Code '+esc(o.code)+'</strong><p>Applied to your Stonefellow cart.</p><button class="text-control" type="button" data-view="store">Open store</button>';bind(result)}else if(o.type==='offer_vip'||o.type==='offer_exclusive'){result.innerHTML=(p.access_code?'<strong>Access code: '+esc(p.access_code)+'</strong>':'')+(p.url?'<a class="buy-primary button-link" data-campaign-outbound="'+esc(nodeId)+'" href="'+esc(p.url)+'" target="_blank" rel="noopener">Open offer</a>':'');const link=$('[data-campaign-outbound]',result);if(link)link.onclick=()=>api('campaign.php',{action:'event',slug:slugValue,event_type:'link_clicked',node_id:nodeId}).catch(()=>{})}else result.textContent='Offer claimed: '+(o.code||'confirmed');}catch(x){if(result)result.textContent=x.message}}
  function merchPriceHtml(p){const price=Number(p.price_cents||0),compare=Number(p.compare_at_price_cents||0);return '<span class="merch-price">'+(compare>price?'<del>'+money(compare)+'</del>':'')+'<strong>'+money(price)+'</strong></span>'}
  function merchVariantOptions(p){const rows=(p.variants||[]).filter(v=>v.status==='active');return rows.length?'<select data-merch-variant="'+esc(p.id)+'">'+rows.map(v=>'<option value="'+v.id+'" '+(!v.available?'disabled':'')+'>'+esc(v.title)+' · '+money(v.price_cents)+(v.available?(v.low_stock&&v.inventory_qty!==null?' · '+v.inventory_qty+' left':''):' · SOLD OUT')+'</option>').join('')+'</select>':''}
  function addMerchToCart(productId,variantId,quantity=1){const p=(st.storefrontProducts||[]).find(x=>x.kind==='merch'&&x.id===productId);if(!p)return say('That product is unavailable.');const v=(p.variants||[]).find(x=>Number(x.id)===Number(variantId))||null;if((p.variants||[]).length&&!v)return say('Choose an available option.');const max=Math.max(1,Number(p.max_per_order||10)),qty=Math.max(1,Math.min(max,Number(quantity)||1)),existing=st.cart.find(x=>x.type==='merch'&&x.product_id===productId&&Number(x.variant_id||0)===Number(variantId||0));if(existing)existing.quantity=Math.min(max,Number(existing.quantity||1)+qty);else st.cart.push({type:'merch',product_id:productId,variant_id:v?Number(v.id):null,quantity:qty,label:p.label,variant_label:v?.title||''});persistCart();showCartAfterAdd('Added '+p.label+(v?' · '+v.title:'')+' to your cart.')}
  function merchDetailHtml(p){return '<article class="module merch-detail-module"><div class="module-kicker">STONEFELLOW STORE · '+esc(String(p.category||'MERCH').toUpperCase())+'</div><div class="merch-detail-grid">'+(p.image?'<div class="merch-detail-art"><img src="'+esc(p.image)+'" alt="'+esc(p.label)+'"></div>':'<div class="merch-detail-art placeholder">SF</div>')+'<div><h2>'+esc(p.label)+'</h2>'+merchPriceHtml(p)+(p.description?'<p class="player-note">'+esc(p.description)+'</p>':'')+merchVariantOptions(p)+'<div class="merch-buy-row"><input type="number" min="1" max="'+Number(p.max_per_order||10)+'" value="1" data-merch-qty="'+esc(p.id)+'" aria-label="Quantity"><button class="buy-primary" type="button" data-merch-add="'+esc(p.id)+'" '+(p.available?'':'disabled')+'>'+(p.available?'Add to cart':'Sold out')+'</button>'+shareButton('store',p.id,p.label,p.slug||p.id)+'</div>'+(p.low_stock&&p.inventory_qty!==null?'<em class="stock-note">Only '+Number(p.inventory_qty)+' left</em>':'')+'<button class="text-control" type="button" data-view="store">Back to store</button></div></div></article>'}
  async function renderStore(params={}){
    starters.hidden=true;let data={products:[],releases:[]};
    try{data=await api('storefront.php')}catch(e){canvas.innerHTML=`<article class="module"><div class="module-kicker">STORE</div><h2>Store unavailable</h2><p class="player-note">${esc(e.message)}</p></article>`;return}
    const products=data.products||[],builders=products.filter(p=>p.kind==='builder'),merch=products.filter(p=>p.kind==='merch'),rels=data.releases||[];st.storefrontProducts=products;
    let returnNotice='';
    const returnType=new URLSearchParams(window.location.search).get('checkout');
    if(returnType==='return'||returnType==='canceled'){
      const pending=readJSON('stonefellow.pending.stripe',null);
      if(pending?.id&&pending?.token){
        try{
          const resp=await fetch((cfg.apiBase||'api')+'/store-payment-status.php?id='+encodeURIComponent(pending.id)+'&token='+encodeURIComponent(pending.token),{credentials:'same-origin',cache:'no-store'});
          const payment=await resp.json();if(!resp.ok||!payment.ok)throw new Error('Payment status is not available yet.');
          if(payment.payment_status==='paid'){
            st.cart=[];st.campaignCode='';persistCart();localStorage.removeItem('stonefellow.campaign.code');
            localStorage.removeItem('stonefellow.pending.stripe');window.StonefellowTurntable?.invalidate?.();
          }
          const label=payment.payment_status==='paid'?'Payment confirmed. Thank you for your purchase.'
            :payment.status==='canceled'?'Checkout ended without a payment. Your cart is preserved.'
            :'Payment confirmation is pending. Your cart remains saved; do not submit another payment until the status is resolved.';
          returnNotice='<p class="player-note" role="status">Order '+esc(payment.order_id)+': '+esc(label)+'</p>';
        }catch(_){returnNotice='<p class="player-note" role="status">Could not verify payment status. Your cart has been preserved; contact support with the order number before retrying.</p>';}
      }else{returnNotice='<p class="player-note" role="status">If you completed Stripe Checkout, payment verification may still be in progress.</p>';}
    }
    const storeWarningHtml=returnNotice+(Array.isArray(data.warnings)&&data.warnings.length?'<div class="store-warnings" role="status">'+data.warnings.map(w=>'<p class="player-note">'+esc(w)+'</p>').join('')+'</div>':'');
    const focusKey=String(params.slug||''),focus=focusKey?merch.find(p=>String(p.slug||p.id)===focusKey||String(p.id)===focusKey):null;if(focus){canvas.innerHTML=merchDetailHtml(focus);bind(canvas);bindShareButtons(canvas);const add=$('[data-merch-add]',canvas);if(add)add.onclick=()=>{const id=add.dataset.merchAdd,v=$('[data-merch-variant="'+CSS.escape(id)+'"]',canvas),q=$('[data-merch-qty="'+CSS.escape(id)+'"]',canvas);addMerchToCart(id,v?Number(v.value):null,Number(q?.value||1))};return}
    const builderCards=builders.map(p=>`<article class="store-card">${p.image?`<div class="store-card-art"><img src="${esc(p.image)}" alt="${esc(p.label)}"></div>`:'<div class="store-card-art">SF</div>'}<small>${esc(String(p.format||'').toUpperCase())}</small><strong>${esc(p.label)}</strong><span>${money(p.price_cents||0)}</span><button class="buy-primary" type="button" data-store-format="${esc(p.format)}">Create ${esc(p.format)}</button></article>`).join('');
    const merchCards=merch.length?merch.map(p=>`<article class="store-card merch-card ${p.available?'':'sold-out'}">${p.image?`<div class="store-card-art"><img src="${esc(p.image)}" alt="${esc(p.label)}"></div>`:'<div class="store-card-art">SF</div>'}<small>${esc(String(p.category||'MERCH').toUpperCase())}${p.featured?' · FEATURED':''}</small><strong>${esc(p.label)}</strong>${p.description?`<p>${esc(p.description)}</p>`:''}${merchPriceHtml(p)}${merchVariantOptions(p)}<div class="merch-buy-row"><input type="number" min="1" max="${Number(p.max_per_order||10)}" value="1" data-merch-qty="${esc(p.id)}" aria-label="Quantity"><button class="buy-primary" type="button" data-merch-add="${esc(p.id)}" ${p.available?'':'disabled'}>${p.available?'Add to cart':'Sold out'}</button><button class="text-control" type="button" data-merch-open="${esc(p.slug||p.id)}">Details</button></div>${p.low_stock&&p.inventory_qty!==null?'<em class="stock-note">Only '+Number(p.inventory_qty)+' left</em>':''}</article>`).join('') :'<p class="player-note">'+(data.merch_status==='unavailable'?'Merchandise catalog temporarily unavailable. Please try again later.':'No merch products are available right now.')+'</p>';
    const releaseCards=rels.length?rels.map(r=>`<article class="store-card">${r.artwork?.cover?`<div class="store-card-art"><img src="${esc(r.artwork.cover)}" alt=""></div>`:'<div class="store-card-art">SF</div>'}<small>${esc(String(r.type||'release').toUpperCase())}</small><strong>${esc(r.title)}</strong><span>${Number(r.digital_price||0)>0?'From $'+Number(r.digital_price).toFixed(2):'Open release'}</span><button class="text-control" type="button" data-store-release="${esc(r.id)}">Open release</button></article>`).join(''):'<p class="player-note">No purchasable releases are listed right now.</p>';
    canvas.innerHTML=`<article class="module store-module"><div class="module-kicker">STONEFELLOW STORE</div><div class="store-head"><div><h2>Music & merch</h2><p class="player-note">Buy Stonefellow music and merchandise directly, or build a one-off custom physical release.</p></div><button class="text-control" type="button" data-view="cart">Cart · ${st.cart.reduce((n,x)=>n+(x.type==='merch'?Number(x.quantity||1):1),0)}</button></div>${storeWarningHtml}<section class="store-section"><h3>Merchandise</h3><div class="store-grid">${merchCards}</div></section><section class="store-section"><h3>Custom physical releases</h3><div class="store-grid">${builderCards}</div></section><section class="store-section"><h3>Releases</h3><div class="store-grid">${releaseCards}</div></section></article>`;
    bind(canvas);$$('[data-store-format]',canvas).forEach(b=>b.onclick=()=>{if(['vinyl','cassette'].includes(b.dataset.storeFormat))st.builder.format=b.dataset.storeFormat;persist();navigate('builder')});$$('[data-store-release]',canvas).forEach(b=>b.onclick=()=>navigate('release',{id:b.dataset.storeRelease}));$$('[data-merch-open]',canvas).forEach(b=>b.onclick=()=>navigate('store',{slug:b.dataset.merchOpen}));$$('[data-merch-add]',canvas).forEach(b=>b.onclick=()=>{const id=b.dataset.merchAdd,v=$('[data-merch-variant="'+CSS.escape(id)+'"]',canvas),q=$('[data-merch-qty="'+CSS.escape(id)+'"]',canvas);addMerchToCart(id,v?Number(v.value):null,Number(q?.value||1))});
  }
  async function loadFanEngagement(force=false){
    if(!st.auth.authenticated||(!force&&st.fanEngagementLoaded))return;
    st.fanEngagementLoaded=true;
    try{const r=await fetch((cfg.apiBase||'api')+'/fan-engagement.php',{cache:'no-store',credentials:'same-origin'}),j=await r.json();if(r.ok&&j.ok&&j.engagement?.message)say(j.engagement.message)}catch(_){}
  }
  async function homepageEvent(event_type,section_key='',entity_type='',entity_id='',detail={}){if(window.STONEFELLOW_THEME_PREVIEW)return;try{await api('artist-home.php',{event_type,section_key,entity_type,entity_id,detail})}catch(_){}}
  function homepageMoney(c){return new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format((Number(c)||0)/100)}
  function homepageTrackObject(t){const base=trackById(t.id)||{id:t.id,title:t.title,release:t.release||'',duration:t.duration||0,mood:[],themes:[],credits:[]};return {...base,audio:t.audio?.url||'',artwork:t.artwork?.url||base.artwork||''}}
  function artistHomeSection(s){return window.SFArtistHomeRenderer.renderSection(s,{esc,time,homepageMoney,newsletterSignupHtml,homeTrackCard});}
  async function renderArtistHomepage(){
    starters.hidden=true;document.body.classList.add('artist-home-active');let data;try{data=await api('artist-home.php')}catch(e){document.body.classList.remove('artist-home-active');return false}const home=data.homepage||{};if(!home.enabled){document.body.classList.remove('artist-home-active','artist-home-chat-hidden');return false}
    // Honor the published homepage preference; the floating companion can always reveal chat on demand.
    document.body.classList.toggle('artist-home-chat-hidden',home.chat_bar_enabled===false);
    canvas.innerHTML='<article class="artist-homepage">'+(home.sections||[]).map(artistHomeSection).join('')+'</article>';homepageEvent('view','hero');bindNewsletterForms(canvas);bind(canvas);const newsletter=$('.artist-home-block .newsletter-form',canvas);if(newsletter)newsletter.addEventListener('focusin',()=>homepageEvent('newsletter_open','newsletter'),{once:true});
    $$('[data-home-view]',canvas).forEach(b=>b.onclick=()=>{homepageEvent(b.dataset.homeEvent||'hero_primary','hero','view',b.dataset.homeView);navigate(b.dataset.homeView||'music')});
    $$('[data-home-release]',canvas).forEach(b=>b.onclick=()=>{homepageEvent('release_open','featured_release','release',b.dataset.homeRelease);navigate('release',{id:b.dataset.homeRelease})});
    $$('[data-home-track]',canvas).forEach(b=>b.onclick=()=>{homepageEvent('track_open','featured_track','track',b.dataset.homeTrack);navigate('track',{id:b.dataset.homeTrack})});
    $$('[data-home-play]',canvas).forEach(b=>b.onclick=()=>{const sec=(home.sections||[]).find(x=>x.section_key==='featured_track'),t=sec?.data;if(t){homepageEvent('track_play','featured_track','track',t.id);play(homepageTrackObject(t),0,'artist_home')}});
    $$('[data-home-video]',canvas).forEach(b=>b.onclick=()=>{homepageEvent('video_open','featured_video','video',b.dataset.homeVideo);navigate('video',{slug:b.dataset.homeVideo})});
    $$('[data-home-show]',canvas).forEach(b=>b.onclick=()=>{homepageEvent('show_open','upcoming_show','show',b.dataset.homeShow);navigate('show',{id:b.dataset.homeShow})});
    $$('[data-home-store]',canvas).forEach(b=>b.onclick=()=>{homepageEvent('merch_open','merch','store',b.dataset.homeStore);navigate('store',{slug:b.dataset.homeStore})});
    $$('[data-home-ticket-offer]',canvas).forEach(b=>b.onclick=()=>{homepageEvent('show_open','custom_blocks','ticket',b.dataset.homeTicketOffer);navigate('tickets',{offer_id:b.dataset.homeTicketOffer})});
    $$('[data-home-dsp]',canvas).forEach(a=>a.onclick=()=>homepageEvent('release_open','featured_release','dsp',''));
    $$('[data-home-ticket]',canvas).forEach(a=>a.onclick=()=>homepageEvent('show_open','upcoming_show','ticket',''));
    const agentBtn=$('[data-home-agent]',canvas);if(agentBtn)agentBtn.onclick=()=>{homepageEvent('agent_open','hero');document.body.classList.remove('artist-home-chat-hidden');syncAgentDockLayout();input?.focus();agent('idle','Ask me about a song, a release, a show, or what you should hear first.')};
    const pp=$('[data-home-personalized]',canvas);if(pp)pp.onclick=()=>{homepageEvent('personalized_open','personalized');const sec=(home.sections||[]).find(x=>x.section_key==='personalized'),a=sec?.data?.suggestion?.action||{};if(a.type==='play_track'&&a.track_id){const t=trackById(a.track_id);if(t)play(t)}else if(a.type==='resume_track'&&a.track_id){const t=trackById(a.track_id);if(t)play(t,a.position_seconds||0)}else if(a.type==='open_release')navigate('release',{id:a.release_id});else if(a.type==='open_build')navigate('builder');else navigate(a.view||'music')};
    agent(st.playing?'playing':'idle','Welcome to Stonefellow. Start with the music, see what is next, or ask me where to begin.');return true;
  }
  function sceneImagePath(id){
    return typeof id==='string'&&chatImageId.test(id)?(cfg.apiBase||'api')+'/agent-canvas-image.php?id='+encodeURIComponent(id):'';
  }
  function gallerySceneImagePath(scene,id){
    if(!isGalleryScene(scene)||!Array.isArray(scene.image_assets))return '';
    const asset=scene.image_assets.find(item=>item?.id===id);
    if(!asset||typeof asset.url!=='string')return '';
    try{
      const url=new URL(asset.url,document.baseURI),endpoint=new URL((cfg.apiBase||'api')+'/canvas-gallery.php',document.baseURI);
      return url.origin===endpoint.origin&&url.pathname===endpoint.pathname&&url.searchParams.get('action')==='asset'&&
        url.searchParams.get('id')===scene.gallery_id&&url.searchParams.get('asset_id')===id?url.href:'';
    }catch(_){return '';}
  }
  function canvasAudioState(){
    const duration=Number.isFinite(audio.duration)&&audio.duration>0?audio.duration:Math.max(0,Number(st.track?.duration)||0);
    return {trackId:st.track?String(st.track.id):'',title:String(st.track?.title||''),
      currentTime:Number.isFinite(audio.currentTime)?Math.max(0,audio.currentTime):0,duration,
      playing:!!st.track&&!audio.paused&&!audio.ended&&!audio.error,volume:playerVolume(),muted:!!audio.muted,ended:!!audio.ended};
  }
  function canvasListeningContext(scene=listeningRoom){
    if(isGalleryScene(scene))return {gallery_id:scene.gallery_id};
    return Number.isSafeInteger(scene?.id)&&scene.id>0?{scene_id:scene.id}:{app:true};
  }
  const canvasAudioDebugSessions=new WeakMap();
  function recordCanvasAudioDiagnostic(detail){
    const scene=listeningRoom,owner=canvasOwner();
    if(!scene||!owner)return;
    let entry=canvasAudioDebugSessions.get(scene);
    if(!entry||entry.owner!==owner){
      entry={owner,id:Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('')};
      canvasAudioDebugSessions.set(scene,entry);
    }
    recordCanvasDebug(entry.id,'canvas_audio',{scene_id:scene.id,...detail});
  }
  function requireCanvasAudioSession(owner,scene){
    if(!owner||owner!==canvasSceneOwner()||(!st.auth.authenticated&&!isGalleryScene(scene))||!scene||scene!==listeningRoom)
      throw Object.assign(new Error('The canvas audio session is no longer active.'),{code:'audio_session_closed',stage:'session'});
  }
  function canvasAudioContext(scene){
    if(isGalleryScene(scene)&&/^[a-f0-9]{32}$/.test(scene.gallery_id))return Object.freeze({gallery_id:scene.gallery_id});
    if(Number.isSafeInteger(scene?.id)&&scene.id>0)return Object.freeze({scene_id:scene.id});
    throw Object.assign(new Error('The canvas audio context is unavailable.'),{code:'audio_session_closed',stage:'session'});
  }
  function canvasAudioSourcePath(trackId,scene){
    return 'canvas-audio.php?'+new URLSearchParams({action:'source',track_id:trackId,...canvasAudioContext(scene)});
  }
  function scopeCanvasMusicCatalog(catalog,scene){
    const inputs=scene?.music_inputs;
    if(inputs==null)return catalog;
    if(inputs.mode!=='all'&&(inputs.mode!=='selected'||!Array.isArray(inputs.track_ids)))
      throw new Error('This canvas has an invalid music selection.');
    const available=new Map(catalog.tracks.filter(track=>track?.source_available===true).map(track=>[track.track_id,track]));
    const ids=inputs.mode==='all'?Array.from(available.keys()):Array.from(new Set(inputs.track_ids));
    const tracks=ids.flatMap(id=>available.has(id)?[available.get(id)]:[]),selected=new Set(tracks.map(track=>track.track_id));
    const releases=catalog.releases.flatMap(release=>{
      const trackIds=Array.isArray(release.track_ids)?release.track_ids.filter(id=>selected.has(id)):[];
      return trackIds.length?[{...release,track_ids:trackIds}]:[];
    });
    return {tracks,releases};
  }
  async function loadCanvasData(alias,context,signal,locationId){
    const owner=canvasSceneOwner(),scene=listeningRoom;
    requireCanvasAudioSession(owner,scene);
    const selected=canvasAudioContext(scene);
    if(selected.scene_id!==context?.scene_id||selected.gallery_id!==context?.gallery_id||
      typeof alias!=='string'||!/^[a-z][a-z0-9-]{0,31}$/.test(alias)||
      (locationId!==undefined&&(typeof locationId!=='string'||!/^show-[a-z0-9][a-z0-9-]{0,79}$/.test(locationId))))
      throw new Error('This canvas data source is unavailable.');
    const params=new URLSearchParams({action:'read',alias,...selected});
    if(locationId!==undefined)params.set('location_id',locationId);
    const result=await api('canvas-data.php?'+params,undefined,signal);
    requireCanvasAudioSession(owner,scene);
    return result.data;
  }
  async function loadCanvasAudioCatalog(filters={},signal){
    const owner=canvasSceneOwner(),scene=listeningRoom;
    requireCanvasAudioSession(owner,scene);
    const context=canvasAudioContext(scene);
    await requirePublishedCanvas(scene,signal);
    requireCanvasAudioSession(owner,scene);
    const params=new URLSearchParams({action:'catalog',...context});
    for(const field of ['release_id','track_id'])if(filters[field]!==undefined){
      if(typeof filters[field]!=='string'||!/^[A-Za-z0-9_-]{1,180}$/.test(filters[field]))throw new Error('Choose a valid music catalog ID.');
      params.set(field,filters[field]);
    }
    const result=await api('canvas-audio.php?'+params,undefined,signal);
    requireCanvasAudioSession(owner,scene);
    if(!Array.isArray(result.tracks)||!Array.isArray(result.releases)||!Array.isArray(result.playlists))throw new Error('The music catalog returned an invalid response.');
    return {...scopeCanvasMusicCatalog({tracks:result.tracks,releases:result.releases},scene),playlists:result.playlists};
  }
  async function loadCanvasAudioSource(trackId,signal){
    const owner=canvasSceneOwner(),scene=listeningRoom;
    const catalog=await loadCanvasAudioCatalog({track_id:trackId},signal);
    const track=catalog.tracks.find(row=>row.track_id===trackId);
    if(!track?.source_available)throw new Error('This song does not have an available hosted MP3 source.');
    requireCanvasAudioSession(owner,scene);
    const path=canvasAudioSourcePath(trackId,scene);
    const response=await fetch((cfg.apiBase||'api')+'/'+path,{credentials:'same-origin',mode:'same-origin',cache:'no-store',redirect:'error',signal});
    if(!response.ok||!/^audio\/mpeg(?:\s*;|$)/i.test(response.headers.get('content-type')||''))
      throw new Error('The song’s MP3 source is unavailable.');
    const maximum=128*1024*1024;
    if(Number(response.headers.get('content-length'))>maximum)throw new Error('The MP3 exceeds the canvas audio file size limit.');
    const blob=await response.blob();
    requireCanvasAudioSession(owner,scene);
    if(!blob.size||blob.size>maximum)throw new Error('The song’s MP3 could not be loaded.');
    return {track,blob};
  }
  async function playCanvasAudio(trackId){
    const owner=canvasSceneOwner(),scene=listeningRoom,id=trackId??(st.track?String(st.track.id):'');
    let stage='selection';
    try{
      if(!id)throw Object.assign(new Error('Choose a song before starting playback.'),{code:'invalid_track'});
      stage='catalog';
      const catalog=await loadCanvasAudioCatalog({track_id:id});
      stage='selection';
      const published=catalog.tracks.find(track=>track.track_id===id);
      if(!published)throw Object.assign(new Error('This song is no longer in the published catalog.'),{code:'track_unavailable'});
      if(!published.source_available)throw Object.assign(new Error('This song does not have an available hosted MP3 source.'),{code:'audio_source_unavailable'});
      stage='site_catalog';
      let track=trackById(id);
      if(!track){await refreshSiteData();track=trackById(id);}
      stage='session';
      requireCanvasAudioSession(owner,scene);
      if(!track)throw Object.assign(new Error('This song is unavailable.'),{code:'track_unavailable',stage:'site_catalog'});
      if(String(st.track?.id)===id&&!audio.paused&&!audio.ended)return canvasAudioState();
      // The fresh catalog authorized this source. Keep canonical player state,
      // but do not fall back to a listening URL cached before a media update.
      track={...track,audio:(cfg.apiBase||'api')+'/'+canvasAudioSourcePath(id,scene)};
      const position=String(st.track?.id)===id?savedPlaybackPosition():0;
      stage='playback';
      let failure;
      if(!await play(track,position,'player',false,error=>{failure=error;},canvasListeningContext(scene))){
        const mediaCode=Number(audio.error?.code)||0;
        const code=failure?.code==='audio_seek_unavailable'?'audio_seek_unavailable':
          failure?.name==='NotAllowedError'?'audio_gesture_required':
          failure?.name==='AbortError'||String(st.track?.id)!==id?'audio_playback_interrupted':
          mediaCode===2?'audio_network_error':mediaCode===3?'audio_decode_error':
          mediaCode===4||failure?.name==='NotSupportedError'?'audio_source_unavailable':'audio_unavailable';
        const error=new Error(code==='audio_gesture_required'?'Tap Play to allow audio for this session.':'The player could not start this song.');
        error.code=code;error.stage=code==='audio_seek_unavailable'?'resume_seek':'playback';
        if(mediaCode>=1&&mediaCode<=4)error.media_code=mediaCode;
        error.source_kind='host_reference';
        if(['NotAllowedError','AbortError','NotSupportedError'].includes(failure?.name))error.name=failure.name;
        throw error;
      }
      stage='session';
      requireCanvasAudioSession(owner,scene);
      return canvasAudioState();
    }catch(error){
      // Stage names and codes are safe diagnostic classifications; never send
      // upstream messages, URLs or song content to the canvas debug stream.
      const failure=new Error('The canvas audio operation could not be completed.');
      failure.stage=['catalog','selection','site_catalog','source','playback','resume_seek','session'].includes(error?.stage)?error.stage:stage;
      failure.code=typeof error?.code==='string'?error.code:stage==='catalog'?'audio_catalog_unavailable':stage==='session'?'audio_session_closed':
        stage==='site_catalog'?'track_unavailable':'audio_unavailable';
      if(['NotAllowedError','AbortError','NotSupportedError','TypeError','NetworkError'].includes(error?.name))failure.name=error.name;
      if(Number.isInteger(error?.media_code)&&error.media_code>=1&&error.media_code<=4)failure.media_code=error.media_code;
      if(['host_reference','blob','other'].includes(error?.source_kind))failure.source_kind=error.source_kind;
      throw failure;
    }
  }
  function pauseCanvasAudio(){
    requireCanvasAudioSession(canvasSceneOwner(),listeningRoom);
    if(st.track&&!audio.paused)toggle();
    return canvasAudioState();
  }
  function seekCanvasAudio(seconds){
    requireCanvasAudioSession(canvasSceneOwner(),listeningRoom);
    if(!Number.isFinite(seconds)||seconds<0||!Number.isFinite(audio.duration)||audio.duration<=0||seconds>audio.duration)
      throw new Error('The requested playback position is not currently seekable.');
    seekPlayback(seconds/audio.duration);
    return canvasAudioState();
  }
  function setCanvasAudioVolume(value){
    requireCanvasAudioSession(canvasSceneOwner(),listeningRoom);
    if(!Number.isFinite(value)||value<0||value>1||setPlayerVolume(value)===null)
      throw new Error('The player could not apply that volume.');
    return canvasAudioState();
  }
  async function renderAgentCanvas(){
    if(!window.SFPublicAgentCanvas?.mount)return false;
    clearListeningRoom();
    document.body.classList.remove('artist-home-active','artist-home-chat-hidden');
    document.body.classList.add('sf-agent-canvas-active');
    starters.hidden=true;
    const mounted=window.SFPublicAgentCanvas.mount(mainCanvas,{
      audio,
      player:footerPlayer,
      initialAgentHidden:manualAgentHidden,
      initialConversation:popupEntries.filter(entry=>entry.role!=='user'||(!entry.canvasBrief&&!canvasBriefInChat(entry.text))).at(-1)||null,
      isCapturing:()=>!!(songCaptureSkill?.status()?.active||songListeningSkill?.status()?.active||vocalStudio?.status()?.active),
      isVocalRecording:()=>!!vocalStudio?.status()?.active,
      hasTemporaryPlaylist:()=>isTemporaryPlaylist(),
      onOpenQueue:()=>void openQueue(),
      onOpenMoments:()=>songMoments?.open(),
      onOpenMoment:(id,trigger)=>songMoments?.openMoment(id,trigger),
      getTrackId:()=>st.track?.id,
      getTrackArtwork:()=>trackArtwork(st.track),
      canUseScenes:()=>!!canvasOwner()||isGalleryScene(),
      canManageScenes:()=>!!canvasOwner(),
      onOpenSceneHistory:()=>void handleListeningRoomCommand({kind:'history'}),
      onRestoreScene:id=>void handleListeningRoomCommand({kind:'restore',id:Number(id)}),
      onDeleteScene:deleteSavedCanvas,
      onSubmitScene:id=>void navigate('canvas-gallery',{tab:'submit',id}),
      onPageClose:view=>{if(view==='canvas-gallery'){canvasGalleryView?.destroy();canvasGalleryView=null;cancelGalleryOpen();}},
      onSceneFeedback:()=>{
        if(listeningRoom&&canvasOwner())void canvasProjects()?.feedbackForScene?.(listeningRoom)?.catch(error=>{
          if(error.name!=='AbortError')popupEntry('notice',error.message||'Canvas feedback could not open.');
        });
      },
      getSceneOwner:canvasSceneOwner,
      getSceneImageUrl:sceneImagePath,
      onEditSceneImage:selectCanvasImageForEdit,
      onReplaceSceneImage:replaceCanvasImage,
      onLoadSceneImages:(cursor,signal)=>api('agent-canvas-image.php'+(cursor?'?before='+encodeURIComponent(cursor):''),undefined,signal),
      onSceneStop:stopListeningRoomBuild,
      onSceneAccess:()=>void canvasAccess()?.show(),
      onSceneMounted:scene=>canvasAccess()?.sceneMounted(scene),
      onSceneTrackData:async(sceneId,trackId,signal)=>{
        if(isGalleryScene()&&sceneId===listeningRoom.gallery_id){
          const result=await api('canvas-gallery.php?'+galleryQuery({action:'open',id:sceneId,current_track_id:trackId}),undefined,signal);
          return result.scene??null;
        }
        if(typeof sceneId==='string')return null;
        const result=await api('agent-scene.php?id='+encodeURIComponent(sceneId)+'&current_track_id='+encodeURIComponent(trackId),undefined,signal);
        return result.scene??null;
      },
      onSceneImage:async(id,signal,scene=null)=>{
        const viewer=canvasSceneOwner();
        if(scene===null&&!canvasOwner())return null;
        const path=isGalleryScene(scene)?gallerySceneImagePath(scene,id):sceneImagePath(id);
        if(!path)return null;
        const response=await fetch(path,{mode:'same-origin',credentials:'same-origin',cache:'no-store',redirect:'error',signal});
        if(!response.ok||!/^image\/(?:png|webp|jpeg)$/i.test(response.headers.get('content-type')||'')||Number(response.headers.get('content-length'))>16*1024*1024)return null;
        const blob=await response.blob();
        return (scene===null||scene===listeningRoom)&&viewer===canvasSceneOwner()&&blob.size<=16*1024*1024?blob:null;
      },
      onScenePlay:trackId=>{
        if(!listeningRoom||String(listeningRoom.track_id)!==String(trackId))return;
        const track=trackById(trackId);
        if(!track)return;
        if(String(st.track?.id)===String(trackId)){
          if(audio.paused)void play(track,savedPlaybackPosition(),'player',!!st.playlistPlayback.playlistId,null,canvasListeningContext());
        }else void play(track,0,'player',false,null,canvasListeningContext());
      },
      onSceneCatalogPlay:async trackId=>{
        const current=listeningRoom,owner=canvasSceneOwner();
        if(!current||(!st.auth.authenticated&&!isGalleryScene(current)))return;
        try{await requirePublishedCanvas(current);}catch(_){return;}
        if(listeningRoom!==current||owner!==canvasSceneOwner())return;
        let track=trackById(trackId);
        if(!track){
          await refreshSiteData();
          if(listeningRoom!==current||(!st.auth.authenticated&&!isGalleryScene(current)))return;
          track=trackById(trackId);
        }
        if(!track){popupEntry('notice','That song is no longer available in the public catalog.');return;}
        if(String(st.track?.id)===String(trackId)){
          if(audio.paused)void play(track,savedPlaybackPosition(),'player',!!st.playlistPlayback.playlistId,null,canvasListeningContext(current));
        }else void play(track,0,'player',false,null,canvasListeningContext(current));
      },
      onSceneCatalogOpen:(kind,id)=>{
        if(!listeningRoom||(!st.auth.authenticated&&!isGalleryScene())||!/^[A-Za-z0-9_-]{1,180}$/.test(String(id||'')))return;
        if(kind==='merch')void navigate('store',{slug:id});
        if(kind==='album')void navigate('release',{id});
        if(kind==='show')void navigate('show',{id});
        if(kind==='tickets')void navigate('tickets',{id});
      },
      onSceneStory:(trackId,story)=>{
        if(!listeningRoom||String(listeningRoom.track_id)!==String(trackId))return;
        if(story?.story_slug)void navigate('story',{slug:story.story_slug});
        else if(String(st.track?.id)===String(trackId)&&songMoments?.open())return;
        else void navigate('track',{id:trackId});
      },
      onSceneClose:()=>{selectCanvasId(canvasOwner(),null);clearListeningRoom();},
      onCanvasIndependentAudioObserve:(media,track,context,settings={})=>window.SFListeningTelemetry?.observe(media,{...settings,getTrack:()=>track,getContext:()=>context,method:'independent'}),
      onCanvasDataRead:loadCanvasData,
      onCanvasAudioCatalog:loadCanvasAudioCatalog,
      onCanvasAudioSource:loadCanvasAudioSource,
      onCanvasAudioPlay:playCanvasAudio,
      onCanvasAudioPause:pauseCanvasAudio,
      onCanvasAudioSeek:seekCanvasAudio,
      onCanvasAudioVolume:setCanvasAudioVolume,
      onCanvasAudioState:canvasAudioState,
      onCanvasAudioDiagnostic:recordCanvasAudioDiagnostic,
      onAgentVisibilityChange:syncAgentVisibility,
      onShowAgent:()=>handleAgentVisibilityCommand('/show-agent'),
      onOpenLibrary:()=>openExperienceDrawer('music','songs'),
      onOpenRecordBuilder:()=>void navigate('builder'),
      onOpenCommerce:()=>openExperienceDrawer('commerce','merch'),
      isConversationActive:()=>st.conversationOn,
      onStopSpeaking:()=>stopAgentSpeaking(),
      needsVoiceGesture:()=>iosVoiceNeedsGesture,
      // Keep the microphone start inside this click's user-activation turn.
      onStartChat:()=>{beginWelcome();if(!st.conversationOn||iosVoiceNeedsGesture)void handleVoiceClick();},
      onGuestName:name=>{if(!validGuestName(name)){popupEntry('notice','Please enter your name, using up to three words.');return}popupEntry('user',name);void acceptVisitorName(name);},
      onSkipGuestName:()=>void skipVisitorName(),
      onWelcomeAction:action=>{void handleWelcomeAction(action);},
      onTourOpen:()=>{
        if(st.queue.open)closeQueue();
        if(notificationDrawer&&!notificationDrawer.hidden)closeNotificationDrawer();
        closeMenu();voiceInteractionStarted=true;
      },
      onTourStep:step=>{
        const description=step.title+'. '+step.description;
        popupEntry('assistant',description);
        agent('responding',description);
        if(voiceRepliesEnabled&&!speechPilotActive)void agentSpeak(description);
      },
      onTourClose:()=>{
        if(voicePlaybackPending)interruptVoicePlayback();
        agent(st.playing?'playing':'idle');
      },
      onOpenChat:()=>{void openChatPopup();input?.focus();},
      onNavigate:view=>void navigate(view),
      onClosePage:()=>{
        canvas=mainCanvas;
        document.body.classList.remove('builder-workspace-active');
        setUrl('home',{});
        document.dispatchEvent(new CustomEvent('stonefellow:navigation',{detail:{view:'home',params:{}}}));
      },
      onListenInterpret:async()=>{
        const reply=await executeSongCaptureAction({type:'song_capture_live_start',interpret_on_stop:true});
        if(reply&&!window.StonefellowSongExperience?.active?.())popupEntry('notice',String(reply));
      },
      onStopListening:async()=>{
        const reply=await executeSongCaptureAction({type:'song_capture_stop'});
        if(reply&&!window.StonefellowSongExperience?.active?.())popupEntry('notice',String(reply));
      },
      onStartCapture:()=>openVocalStudio('vocal')
    });
    if(mounted){
      window.SFPublicAgentCanvas.setSongMoment?.(songMoments?.getOrbMoment?.()||null);
      syncWelcomeActions();
      agent(st.playing?'playing':'idle','Welcome to Stonefellow. Play a song, then ask me to listen and interpret its lyrics, or record a clip.');
      void restoreActiveCanvas();
    }
    return mounted;
  }
  async function renderHome(){
    window.SFPublicAgentCanvas?.unmount?.();
    document.body.classList.remove('sf-agent-canvas-active');
    if(homepageTemplate==='agent_canvas'&&await renderAgentCanvas())return;
    // Legacy landing templates stay in the repository for historical content,
    // but are never used as a public fallback when the agent fails to mount.
    throw new Error('The Stonefellow Agent Canvas could not initialize.');
    if(artistHomepageEnabled&&await renderArtistHomepage())return;document.body.classList.remove('artist-home-active','artist-home-chat-hidden');canvas.innerHTML='';starters.hidden=false;agent(st.playing?'playing':'idle','Hey, I’m Stonefellow. I can play you something, tell you about the music, help you make your own record, or sell you the music directly.');
    if(!st.auth.authenticated){canvas.innerHTML=newsletterSignupHtml(false);bindNewsletterForms(canvas);return;}
    await loadPersonalization();const home=await loadHome(true);
    if(!home){const rec=st.personalization.recommendations||{},items=Array.isArray(rec.items)?rec.items:[];canvas.innerHTML=items.length?`<article class="module recommendation-module"><div class="module-kicker">FOR YOU</div><div class="recommendation-heading"><div><h2>Recommended for you</h2><p class="player-note">${esc(rec.summary||'Based on your Stonefellow listening.')}</p></div><button class="text-control" type="button" data-command="recommend something for me">Ask the agent</button></div><div class="recommendation-grid">${items.slice(0,6).map(recommendationCard).join('')}</div></article>`:'';bindRecommendationCards(canvas);bind(canvas);return}
    const cont=home.continue_listening||[],recent=home.recently_played||[],favTracks=home.favorites?.tracks||[],favReleases=home.favorites?.releases||[],rec=home.recommendations||{},recItems=rec.items||[],recentReleases=home.recent_releases||[],builds=home.saved_builds||[],suggestion=home.suggestion||{};
    const suggestionHtml=`<section class="home-suggestion"><div><div class="module-kicker">${esc(suggestion.eyebrow||'AGENT SUGGESTION')}</div><h2>${esc(suggestion.title||'Something for today')}</h2><p>${esc(suggestion.text||'I pulled together a place to start from your Stonefellow activity.')}</p></div><button id="homeSuggestionAction" class="buy-primary" type="button">${esc(suggestion.button||'Open')}</button></section>`;
    const continueBody=cont.length?`<div class="home-card-grid">${cont.map(x=>homeTrackCard(x,'resume')).join('')}</div>`:homeEmpty('Nothing is waiting to be resumed yet. Start a track and your place will show up here.','Browse music','data-view="music"');
    const recentBody=recent.length?`<div class="home-card-grid compact-grid">${recent.map(x=>homeTrackCard(x,'play')).join('')}</div>`:homeEmpty('Your recent listening will appear here as you explore the catalog.','Play something','data-command="play something"');
    const favorites=[...favTracks.map(x=>({x,type:'track'})),...favReleases.map(x=>({x,type:'release'}))];const favoritesBody=favorites.length?`<div class="home-card-grid">${favorites.slice(0,8).map(v=>homeFavoriteCard(v.x,v.type)).join('')}</div>`:homeEmpty('Save tracks and releases you want to find quickly.','Browse music','data-view="music"');
    const recBody=recItems.length?`${rec.summary?`<p class="home-section-copy">${esc(rec.summary)}</p>`:''}<div class="recommendation-grid">${recItems.map(recommendationCard).join('')}</div>`:homeEmpty('Listen, favorite tracks, or make a playlist and recommendations will become personal.','Play something','data-command="play something"');
    const releasesBody=recentReleases.length?`<div class="home-card-grid release-grid">${recentReleases.map(x=>homeReleaseCard(x,isFavorite('release',x.id))).join('')}</div>`:homeEmpty('No published releases are available right now.');
    const buildsBody=builds.length?`<div class="home-card-grid build-grid">${builds.map(homeBuildCard).join('')}</div>`:homeEmpty('You have no saved custom-media drafts yet.','Create a record','data-view="builder"');
    canvas.innerHTML=`<article class="personalized-home module"><div class="home-title-row"><div><div class="module-kicker">YOUR STONEFELLOW</div><h1>Welcome back.</h1><p class="player-note">Your listening, favorites, releases, and unfinished ideas in one place.</p></div><button class="text-control compact" type="button" id="homeQueueShortcut">Up Next · ${Number(home.queue_count||0)}</button></div>${suggestionHtml}${homeSection('CONTINUE','Continue listening',continueBody,'<button class="text-control compact" type="button" data-view="history">Listening history</button>')}${homeSection('RECENT','Recently played',recentBody,'<button class="text-control compact" type="button" data-view="history">See all</button>')}${homeSection('FAVORITES','Saved for later',favoritesBody,'<button class="text-control compact" type="button" data-view="account">Manage</button>')}${homeSection('FOR YOU','Recommended for you',recBody,'<button class="text-control compact" type="button" data-command="recommend something for me">Ask the agent</button>')}${homeSection('RELEASES','Recent releases',releasesBody,'<button class="text-control compact" type="button" data-view="releases">All releases</button>')}${homeSection('CUSTOM MEDIA','Saved builds',buildsBody,'<button class="text-control compact" type="button" data-view="builder">Create a record</button>')}${homeSection('STAY CONNECTED','Stonefellow updates',newsletterSignupHtml(true),fanCommunityEnabled?'<button class="text-control compact" type="button" data-view="community">Fan community</button>':'')}</article>`;
    const qs=$('#homeQueueShortcut',canvas);if(qs)qs.onclick=openQueue;bindPersonalizedHome(canvas);bindNewsletterForms(canvas);
    agent(st.playing?'playing':'idle','Welcome back. I pulled together your listening, favorites, recent releases, and saved builds below. I also left you one suggestion for today.');
  }
  function storyBodyHtml(text){return String(text||'').split(/\n{2,}/).map(p=>'<p>'+esc(p).replace(/\n/g,'<br>')+'</p>').join('')}
  function storyHero(e){const m=(e.media||[]).find(x=>x.role==='hero'&&x.category==='image')||(e.media||[]).find(x=>x.category==='image');return m?.url||''}
  function storyTeaser(e){return '<article class="story-teaser">'+(storyHero(e)?'<button type="button" class="story-teaser-art" data-story-open="'+esc(e.slug)+'"><img src="'+esc(storyHero(e))+'" alt="'+esc(e.title)+'"></button>':'')+'<div><small>'+esc(String(e.eyebrow||e.entry_type||'STORY').replaceAll('_',' ').toUpperCase())+'</small><strong>'+esc(e.title)+'</strong>'+(e.summary?'<p>'+esc(e.summary)+'</p>':'')+'<button class="text-control" type="button" data-story-open="'+esc(e.slug)+'">Read story</button></div></article>'}
  function bindStoryLinks(r=canvas){$$('[data-story-open]',r).forEach(b=>b.onclick=()=>navigate('story',{slug:b.dataset.storyOpen}));$$('[data-story-entity]',r).forEach(b=>b.onclick=()=>{const t=b.dataset.storyEntity,id=b.dataset.storyEntityId;if(t&&id)navigate(t,{id})})}
  async function storyEvent(story_id,event_type,detail={}){if(window.STONEFELLOW_THEME_PREVIEW)return;try{await api('stories.php',{story_id,event_type,detail})}catch(_){}}
  async function renderAbout(){starters.hidden=true;let j={};try{j=await api('stories.php?mode=about')}catch(_){}const d=j.storytelling||{},about=d.about||null,essays=d.essays||[],timeline=d.timeline||[],legacyPhoto=window.STONEFELLOW_SITE?.media?.artist_photo||'',hero=about?storyHero(about):legacyPhoto;
    if(!about&&!essays.length&&!timeline.length){canvas.innerHTML=`<article class="module about-module">${legacyPhoto?`<div class="about-artist-photo"><img src="${esc(legacyPhoto)}" alt="Stonefellow"></div>`:''}<div class="module-kicker">STONEFELLOW</div><h2>About</h2><p class="player-note">Stonefellow is David Evans’ music project. This site is an agent-driven listening room for exploring the catalog, learning the stories behind the recordings, building custom vinyl or cassettes, and buying music directly.</p><div class="player-controls"><button class="text-control" type="button" data-view="music">Listen</button><button class="text-control" type="button" data-view="builder">Make a record</button></div></article>`;bind(canvas);return}
    canvas.innerHTML='<article class="module storytelling-about">'+(hero?'<div class="story-about-hero"><img src="'+esc(hero)+'" alt="Stonefellow"></div>':'')+'<div class="module-kicker">'+esc(about?.eyebrow||'STONEFELLOW')+'</div><h1>'+esc(about?.title||'About Stonefellow')+'</h1>'+(about?.summary?'<p class="story-lede">'+esc(about.summary)+'</p>':'')+'<div class="story-rich-copy">'+storyBodyHtml(about?.body_text||'Stonefellow is David Evans’ music project.')+'</div><div class="player-controls"><button class="text-control" type="button" data-view="music">Listen</button><button class="text-control" type="button" data-view="releases">Releases</button></div>'+(essays.length?'<section class="story-about-section"><div class="module-kicker">EDITORIAL</div><h2>Stories</h2><div class="story-teaser-grid">'+essays.map(storyTeaser).join('')+'</div></section>':'')+(timeline.length?'<section class="story-about-section"><div class="module-kicker">TIMELINE</div><h2>Stonefellow history</h2><div class="story-timeline">'+timeline.map(e=>'<article><time>'+esc(String(e.event_date||'').slice(0,10))+'</time><div><small>'+esc(e.era||'')+'</small><strong>'+esc(e.title)+'</strong>'+(e.summary?'<p>'+esc(e.summary)+'</p>':'')+'<button class="text-control" type="button" data-story-open="'+esc(e.slug)+'">Read milestone</button></div></article>').join('')+'</div></section>':'')+'</article>';bind(canvas);bindStoryLinks(canvas)}
  async function renderEditorialStory(slug){starters.hidden=true;let j;try{j=await api('stories.php?slug='+encodeURIComponent(slug)+(window.STONEFELLOW_THEME_PREVIEW?'&theme_preview=1':''))}catch(e){canvas.innerHTML='<article class="module"><div class="module-kicker">STONEFELLOW STORY</div><h2>Story unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}const e=j.story||{},hero=storyHero(e),gallery=(e.media||[]).filter(x=>x.category==='image'&&x.role!=='hero');canvas.innerHTML='<article class="module story-public-page">'+(hero?'<div class="story-public-hero"><img src="'+esc(hero)+'" alt="'+esc(e.title)+'"></div>':'')+'<div class="module-kicker">'+esc(e.eyebrow||String(e.entry_type||'story').replaceAll('_',' ').toUpperCase())+'</div><h1>'+esc(e.title||'Stonefellow story')+'</h1>'+(e.event_date?'<p class="story-date">'+esc(String(e.event_date).slice(0,10))+(e.era?' · '+esc(e.era):'')+'</p>':'')+(e.summary?'<p class="story-lede">'+esc(e.summary)+'</p>':'')+'<div class="story-rich-copy">'+storyBodyHtml(e.body_text||'')+'</div>'+(e.entity?'<aside class="story-related-entity"><div><small>CONNECTED TO</small><strong>'+esc(e.entity.title)+'</strong></div><button class="text-control" type="button" data-story-entity="'+esc(e.entity.type)+'" data-story-entity-id="'+esc(e.entity.id)+'">Open '+esc(e.entity.type)+'</button></aside>':'')+(gallery.length?'<section class="story-gallery">'+gallery.map(m=>'<figure><a href="'+esc(m.url)+'" target="_blank" rel="noopener" data-story-media><img src="'+esc(m.thumb_url||m.url)+'" alt="'+esc(m.alt_text||m.title||e.title)+'"></a>'+(m.caption?'<figcaption>'+esc(m.caption)+'</figcaption>':'')+'</figure>').join('')+'</section>':'')+'<div class="player-controls">'+shareButton('story',e.id,e.title,e.slug)+'<button class="text-control" type="button" data-view="about">About Stonefellow</button></div></article>';bind(canvas);bindShareButtons(canvas);bindStoryLinks(canvas);$$('[data-story-media]',canvas).forEach(a=>a.onclick=()=>storyEvent(Number(e.id),'media_open',{role:'gallery'}));const rel=$('[data-story-entity]',canvas);if(rel)rel.addEventListener('click',()=>storyEvent(Number(e.id),'entity_open',{entity_type:e.entity?.type||'',entity_id:e.entity?.id||''}),{once:true})}
  async function renderEntityStories(type,id,hostId){const host=$('#'+hostId,canvas);if(!host)return;try{const j=await api('stories.php?entity_type='+encodeURIComponent(type)+'&entity_id='+encodeURIComponent(id)),rows=j.stories||[];if(!rows.length){host.remove();return}host.innerHTML='<div class="module-kicker">STORIES</div><h3>Behind this '+esc(type)+'</h3><div class="story-teaser-grid">'+rows.map(storyTeaser).join('')+'</div>';host.hidden=false;bindStoryLinks(host)}catch(_){host.remove()}}
  async function songbookEvent(track_id,event_type,detail={}){try{await api('songbook.php',{track_id,event_type,detail})}catch(_){}}
  function songbookSectionHtml(s){return '<details class="songbook-section"><summary><span>'+esc(s.label||'Lyrics')+'</span><small>Lines '+Number(s.start_line||0)+'–'+Number(s.end_line||0)+'</small></summary><div class="songbook-lines">'+(s.lines||[]).map(l=>'<div class="songbook-line" data-songbook-line="'+Number(l.line||0)+'"><span class="songbook-line-number">'+Number(l.line||0)+'</span><p>'+esc(l.text||'')+'</p>'+((l.annotations||[]).length?'<div class="songbook-annotations">'+l.annotations.map((note,i)=>'<button class="songbook-annotation-toggle" type="button" data-songbook-annotation="'+Number(l.line||0)+'-'+i+'">Note</button><div class="songbook-annotation" data-songbook-note="'+Number(l.line||0)+'-'+i+'" hidden>'+esc(note)+'</div>').join('')+'</div>':'')+'</div>').join('')+'</div></details>'}
  async function renderPublicSongbook(trackId){const host=$('#publicSongbookPanel',canvas);if(!host)return;try{const j=await api('songbook.php?track_id='+encodeURIComponent(trackId)),b=j.songbook||{};host.innerHTML='<div class="module-kicker">SONGBOOK</div><div class="songbook-public-head"><div><h3>'+esc(b.title||'Lyrics')+'</h3><p>'+(b.release?esc(b.release)+' · ':'')+esc(b.language||'en')+'</p></div>'+(b.credits?.length?'<div class="songbook-credit-chips">'+b.credits.map(c=>'<span><small>'+esc(c.label)+'</small>'+esc(c.value)+'</span>').join('')+'</div>':'')+'</div><div class="songbook-public-sections">'+(b.sections||[]).map(songbookSectionHtml).join('')+'</div>';host.hidden=false;songbookEvent(trackId,'view');$$('.songbook-section',host).forEach(d=>d.addEventListener('toggle',()=>{if(d.open)songbookEvent(trackId,'section_open',{label:$('summary span',d)?.textContent||''})}));$$('[data-songbook-annotation]',host).forEach(btn=>btn.onclick=()=>{const note=$('[data-songbook-note="'+CSS.escape(btn.dataset.songbookAnnotation)+'"]',host);if(!note)return;const opening=note.hidden;note.hidden=!note.hidden;if(opening)songbookEvent(trackId,'annotation_open',{line:String(btn.dataset.songbookAnnotation||'').split('-')[0]})})}catch(_){host.remove()}}

  function renderLegal(kind){const site=window.STONEFELLOW_SITE||{},isPrivacy=kind==='privacy',text=String(isPrivacy?site.privacy||'':site.terms||'').trim();canvas.innerHTML=`<article class="module legal-module"><div class="module-kicker">STONEFELLOW</div><h2>${isPrivacy?'Privacy':'Terms'}</h2><div class="legal-copy">${text?esc(text).replace(/\n/g,'<br>'):'This page has not been configured yet.'}</div></article>`;bind(canvas)}
  async function renderContact(params={}){
    starters.hidden=true;let data={types:[],references:{tracks:[],releases:[]}};try{data=await api('inquiries.php')}catch(e){canvas.innerHTML='<article class="module contact-module"><div class="module-kicker">STONEFELLOW CONTACT</div><h2>Contact unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const types=data.types||[],refs=data.references||{},allowed=new Set(types.map(x=>x.value)),initial=allowed.has(String(params.type||''))?String(params.type):'general';
    const typeCards=types.map(x=>'<button type="button" class="inquiry-type-card '+(x.value===initial?'active':'')+'" data-inquiry-type="'+esc(x.value)+'"><small>'+esc(x.value.toUpperCase())+'</small><strong>'+esc(x.label)+'</strong></button>').join('');
    canvas.innerHTML='<article class="module contact-module"><div class="module-kicker">WORK WITH STONEFELLOW</div><div class="contact-intro"><div><h1>Contact Stonefellow</h1><p>Booking, press, licensing / sync, collaboration, and other professional inquiries.</p></div><p class="contact-boundary">This form is for a direct professional conversation. Submitting it does not subscribe you to the Stonefellow newsletter or Fan CRM marketing.</p></div><div class="inquiry-type-grid">'+typeCards+'</div><form id="professionalInquiryForm" class="contact-form"><input class="inquiry-honeypot" name="company_fax" autocomplete="off" tabindex="-1" aria-hidden="true"><label class="field">Inquiry type<select name="inquiry_type">'+types.map(x=>'<option value="'+esc(x.value)+'" '+(x.value===initial?'selected':'')+'>'+esc(x.label)+'</option>').join('')+'</select></label><label class="field">Your name<input name="name" maxlength="180" autocomplete="name" required></label><label class="field">Email<input name="email" type="email" maxlength="190" autocomplete="email" required></label><label class="field">Organization <span>optional</span><input name="organization" maxlength="220" autocomplete="organization"></label><label class="field">Website <span>optional · HTTPS</span><input name="website_url" type="url" maxlength="700" placeholder="https://"></label><div class="contact-context span2" data-inquiry-context="booking"><label class="field">Event date <span>optional</span><input name="event_date" type="date"></label><label class="field">Venue / city / location <span>optional</span><input name="location" maxlength="255"></label></div><div class="contact-context span2" data-inquiry-context="licensing"><label class="field">Song context <span>optional</span><select name="track_id"><option value="">No specific song</option>'+(refs.tracks||[]).map(t=>'<option value="'+esc(t.id)+'">'+esc(t.title+(t.release?' · '+t.release:''))+'</option>').join('')+'</select></label><label class="field">Release context <span>optional</span><select name="release_id"><option value="">No specific release</option>'+(refs.releases||[]).map(r=>'<option value="'+esc(r.id)+'">'+esc(r.title+(r.date?' · '+r.date:''))+'</option>').join('')+'</select></label></div><label class="field span2">Subject<input name="subject" maxlength="255" required></label><label class="field span2">Message<textarea name="message_text" rows="8" maxlength="6000" required placeholder="Tell me what you are working on, what you need, timing, and any useful details."></textarea></label><label class="check-field span2 contact-consent"><input name="consent_contact" type="checkbox" required> Stonefellow may contact me about this inquiry. This permission applies only to this professional conversation and does not opt me into marketing.</label><div class="actions span2"><button class="buy-primary" type="submit">Send inquiry</button></div><p id="professionalInquiryStatus" class="status-line span2" aria-live="polite"></p></form></article>';
    const form=$('#professionalInquiryForm',canvas),select=form.elements.inquiry_type,status=$('#professionalInquiryStatus',canvas),submit=$('button[type="submit"]',form);
    const sync=()=>{const type=String(select.value||'general');$$('[data-inquiry-type]',canvas).forEach(b=>b.classList.toggle('active',b.dataset.inquiryType===type));$$('[data-inquiry-context]',canvas).forEach(x=>x.hidden=x.dataset.inquiryContext!==type)};
    select.onchange=sync;$$('[data-inquiry-type]',canvas).forEach(b=>b.onclick=()=>{select.value=b.dataset.inquiryType;sync();form.scrollIntoView({behavior:'smooth',block:'start'})});sync();
    form.onsubmit=async e=>{e.preventDefault();submit.disabled=true;status.textContent='Sending…';const d=new FormData(form),payload={inquiry_type:d.get('inquiry_type'),name:d.get('name'),email:d.get('email'),organization:d.get('organization'),website_url:d.get('website_url'),event_date:d.get('event_date'),location:d.get('location'),track_id:d.get('track_id'),release_id:d.get('release_id'),subject:d.get('subject'),message_text:d.get('message_text'),consent_contact:d.get('consent_contact')==='on',company_fax:d.get('company_fax'),source_path:location.pathname};try{const j=await api('inquiries.php',payload);form.innerHTML='<div class="contact-success"><div class="module-kicker">INQUIRY RECEIVED</div><h2>Thank you.</h2><p>Your professional inquiry has been recorded'+(j.reference?' as <strong>'+esc(j.reference)+'</strong>':'')+'. If a response is appropriate, Stonefellow can use the email you provided for this inquiry only.</p><div class="actions"><button class="text-control" type="button" data-view="home">Back to Stonefellow</button></div></div>';bind(form)}catch(x){status.textContent=x.message;submit.disabled=false}};
  }
  async function videoEvent(video_id,event_type,detail={}){if(window.STONEFELLOW_THEME_PREVIEW)return;try{await api('videos.php',{video_id,event_type,detail})}catch(_){}}
  function videoKindLabel(k){return String(k||'video').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}
  function videoCard(v){return '<article class="video-public-card"><button class="video-public-poster" type="button" data-video-open="'+esc(v.slug)+'">'+(v.poster?.url?'<img src="'+esc(v.poster.url)+'" alt="'+esc(v.poster.alt_text||v.title)+'">':'<span class="placeholder">VIDEO</span>')+'<i>▶</i></button><div><small>'+esc(videoKindLabel(v.kind))+'</small><strong>'+esc(v.title)+'</strong>'+(v.video?.duration?'<span>'+time(v.video.duration)+'</span>':'')+'<button class="text-control" type="button" data-video-open="'+esc(v.slug)+'">Watch</button></div></article>'}
  async function renderVideos(){
    starters.hidden=true;let j={videos:[]};try{j=await api('videos.php')}catch(e){canvas.innerHTML='<article class="module"><div class="module-kicker">STONEFELLOW VIDEO</div><h2>Videos unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const rows=j.videos||[],featured=j.featured||rows[0]||null;canvas.innerHTML='<article class="module video-hub"><div class="module-kicker">STONEFELLOW VIDEO</div><div class="video-hub-head"><div><h1>Videos</h1><p>Music videos, live sessions, visualizers, short films and behind-the-scenes work from Stonefellow.</p></div></div>'+(featured?'<section class="video-featured">'+videoCard(featured)+'</section>':'')+'<section class="video-grid">'+rows.filter(v=>!featured||v.id!==featured.id).map(videoCard).join('')+'</section></article>';$$('[data-video-open]',canvas).forEach(b=>b.onclick=()=>navigate('video',{slug:b.dataset.videoOpen}));bind(canvas)
  }
  async function renderVideo(slug){
    starters.hidden=true;let j;try{j=await api('videos.php?slug='+encodeURIComponent(slug))}catch(e){canvas.innerHTML='<article class="module"><div class="module-kicker">STONEFELLOW VIDEO</div><h2>Video unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const v=j.video||{},video=v.video||{},poster=v.poster||{},captions=v.captions||[],entities=v.entities||[],chapters=v.chapters||[],gallery=v.gallery||[];canvas.innerHTML='<article class="module video-detail"><div class="module-kicker">'+esc(videoKindLabel(v.kind).toUpperCase())+'</div><h1>'+esc(v.title||'Stonefellow video')+'</h1>'+(v.description?'<p class="video-detail-lede">'+esc(v.description)+'</p>':'')+'<div class="video-player-shell"><video id="stonefellowVideo" controls preload="metadata" playsinline '+(poster.url?'poster="'+esc(poster.url)+'"':'')+'><source src="'+esc(video.url||'')+'" type="'+esc(video.mime_type||'video/mp4')+'">'+captions.map(c=>'<track kind="captions" src="'+esc(c.url)+'" srclang="'+esc(v.caption_language||'en')+'" label="'+esc((v.caption_language||'en').toUpperCase())+'">').join('')+'</video></div>'+(chapters.length?'<section class="video-chapters"><div class="module-kicker">CHAPTERS</div>'+chapters.map(c=>'<button type="button" data-video-chapter="'+Number(c.seconds||0)+'"><span>'+time(c.seconds||0)+'</span><strong>'+esc(c.title)+'</strong></button>').join('')+'</section>':'')+(entities.length?'<section class="video-related"><div class="module-kicker">CONNECTED TO</div>'+entities.map(e=>'<button class="text-control" type="button" data-video-entity="'+esc(e.type)+'" data-video-entity-id="'+esc(e.id)+'">'+esc(e.title)+'</button>').join('')+'</section>':'')+(gallery.length?'<section class="video-gallery">'+gallery.map(m=>'<figure><a href="'+esc(m.url)+'" target="_blank" rel="noopener"><img src="'+esc(m.url)+'" alt="'+esc(m.alt_text||m.title||v.title)+'"></a>'+(m.caption?'<figcaption>'+esc(m.caption)+'</figcaption>':'')+'</figure>').join('')+'</section>':'')+'<div class="player-controls">'+shareButton('video',v.id,v.title,v.slug)+'<button class="text-control" type="button" data-view="videos">All videos</button></div></article>';bind(canvas);bindShareButtons(canvas);videoEvent(Number(v.id),'view');
    const el=$('#stonefellowVideo',canvas),seen=new Set();if(el){el.addEventListener('play',()=>{if(!seen.has('play')){seen.add('play');videoEvent(Number(v.id),'play')}},{passive:true});el.addEventListener('pause',()=>{if(!el.ended)videoEvent(Number(v.id),'pause',{seconds:Math.round(el.currentTime||0)})},{passive:true});el.addEventListener('timeupdate',()=>{const d=Number(el.duration||video.duration||0);if(!d)return;const p=(el.currentTime/d)*100;for(const mark of [25,50,75])if(p>=mark&&!seen.has('p'+mark)){seen.add('p'+mark);videoEvent(Number(v.id),'progress_'+mark,{seconds:Math.round(el.currentTime)})}},{passive:true});el.addEventListener('ended',()=>{if(!seen.has('complete')){seen.add('complete');videoEvent(Number(v.id),'complete')}},{passive:true});if(el.textTracks)el.textTracks.addEventListener('change',()=>{for(const t of el.textTracks)if(t.mode==='showing'){videoEvent(Number(v.id),'caption_on',{language:t.language||v.caption_language||''});break}})}
    $$('[data-video-chapter]',canvas).forEach(b=>b.onclick=()=>{if(!el)return;const at=Number(b.dataset.videoChapter||0);el.currentTime=at;videoEvent(Number(v.id),'chapter_seek',{seconds:at,title:b.textContent.trim()})});
    $$('[data-video-entity]',canvas).forEach(b=>b.onclick=()=>{videoEvent(Number(v.id),'entity_open',{entity_type:b.dataset.videoEntity,entity_id:b.dataset.videoEntityId});navigate(b.dataset.videoEntity,{id:b.dataset.videoEntityId})});
  }
  async function galleryEvent(gallery_id,event_type,detail={}){if(window.STONEFELLOW_THEME_PREVIEW)return;try{await api('galleries.php',{gallery_id,event_type,detail})}catch(_){}}
  function galleryCard(g){const c=g.cover||{},meta=[g.event_date,g.location].filter(Boolean).join(' · ');return '<article class="photo-gallery-card"><button class="photo-gallery-cover" type="button" data-gallery-open="'+esc(g.slug)+'">'+(c.thumb_url||c.url?'<img src="'+esc(c.thumb_url||c.url)+'" alt="'+esc(c.alt_text||g.title)+'">':'<span class="placeholder">PHOTO</span>')+'</button><div><small>'+esc(meta||'Stonefellow photo gallery')+'</small><strong>'+esc(g.title)+'</strong><span>'+Number((g.photos||[]).length)+' photos</span><button class="text-control" type="button" data-gallery-open="'+esc(g.slug)+'">View gallery</button></div></article>'}
  async function renderPhotos(){
    starters.hidden=true;let j={galleries:[]};try{j=await api('galleries.php')}catch(e){canvas.innerHTML='<article class="module"><div class="module-kicker">STONEFELLOW PHOTOS</div><h2>Photos unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const rows=j.galleries||[],featured=j.featured||rows[0]||null;canvas.innerHTML='<article class="module photo-hub"><div class="module-kicker">STONEFELLOW VISUAL ARCHIVE</div><div class="photo-hub-head"><div><h1>Photos</h1><p>Stonefellow sessions, shows, releases, portraits, and visual moments from the archive.</p></div></div>'+(featured?'<section class="photo-featured">'+galleryCard(featured)+'</section>':'')+'<section class="photo-gallery-grid">'+rows.filter(g=>!featured||g.id!==featured.id).map(galleryCard).join('')+'</section></article>';$$('[data-gallery-open]',canvas).forEach(b=>b.onclick=()=>navigate('photo',{slug:b.dataset.galleryOpen}));bind(canvas)
  }
  function openGalleryLightbox(g,index){
    const photos=g.photos||[],i=Math.max(0,Math.min(photos.length-1,Number(index)||0)),p=photos[i];if(!p)return;galleryEvent(Number(g.id),'photo_open',{photo_index:i+1,asset_id:p.asset_id||0});
    const overlay=document.createElement('div');overlay.className='photo-lightbox';overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');overlay.setAttribute('aria-label',p.alt_text||p.title||g.title);overlay.innerHTML='<button class="photo-lightbox-close" type="button" aria-label="Close photo">×</button><button class="photo-lightbox-nav prev" type="button" aria-label="Previous photo">‹</button><figure><img src="'+esc(p.url)+'" alt="'+esc(p.alt_text||p.title||g.title)+'"><figcaption>'+(p.caption?'<strong>'+esc(p.caption)+'</strong>':'')+(p.credit?'<span>Photo: '+esc(p.credit)+'</span>':'')+(p.copyright?'<small>'+esc(p.copyright)+'</small>':'')+'<em>'+(i+1)+' / '+photos.length+'</em></figcaption></figure><button class="photo-lightbox-nav next" type="button" aria-label="Next photo">›</button>';document.body.appendChild(overlay);const close=()=>overlay.remove(),go=delta=>{close();openGalleryLightbox(g,(i+delta+photos.length)%photos.length)};$('.photo-lightbox-close',overlay).onclick=close;$('.photo-lightbox-nav.prev',overlay).onclick=()=>go(-1);$('.photo-lightbox-nav.next',overlay).onclick=()=>go(1);overlay.onclick=e=>{if(e.target===overlay)close()};overlay.onkeydown=e=>{if(e.key==='Escape')close();else if(e.key==='ArrowLeft')go(-1);else if(e.key==='ArrowRight')go(1)};overlay.tabIndex=-1;overlay.focus()
  }
  async function renderPhotoGallery(slug){
    starters.hidden=true;let j;try{j=await api('galleries.php?slug='+encodeURIComponent(slug))}catch(e){canvas.innerHTML='<article class="module"><div class="module-kicker">STONEFELLOW PHOTOS</div><h2>Gallery unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const g=j.gallery||{},photos=g.photos||[],entities=g.entities||[],cover=g.cover||{};canvas.innerHTML='<article class="module photo-detail"><div class="module-kicker">STONEFELLOW PHOTO GALLERY</div><h1>'+esc(g.title||'Stonefellow photos')+'</h1><p class="photo-detail-meta">'+esc([g.event_date,g.location].filter(Boolean).join(' · '))+'</p>'+(g.summary?'<p class="photo-detail-lede">'+esc(g.summary)+'</p>':'')+(cover.url?'<div class="photo-detail-cover"><img src="'+esc(cover.url)+'" alt="'+esc(cover.alt_text||g.title)+'">'+(cover.credit?'<small>Photo: '+esc(cover.credit)+'</small>':'')+'</div>':'')+(entities.length?'<section class="photo-related"><div class="module-kicker">CONNECTED TO</div>'+entities.map(e=>'<button class="text-control" type="button" data-gallery-entity="'+esc(e.type)+'" data-gallery-entity-id="'+esc(e.id)+'" data-gallery-entity-slug="'+esc(e.slug||'')+'">'+esc(e.title)+'</button>').join('')+'</section>':'')+'<section class="photo-detail-grid">'+photos.map((p,i)=>'<button type="button" class="photo-detail-item" data-photo-index="'+i+'"><img src="'+esc(p.thumb_url||p.url)+'" alt="'+esc(p.alt_text||p.title||g.title)+'"><span>'+(p.caption?'<strong>'+esc(p.caption)+'</strong>':'')+(p.credit?'<small>'+esc(p.credit)+'</small>':'')+'</span></button>').join('')+'</section><div class="player-controls">'+shareButton('photo',g.id,g.title,g.slug)+'<button class="text-control" type="button" data-view="photos">All photos</button></div></article>';bind(canvas);bindShareButtons(canvas);galleryEvent(Number(g.id),'view');$$('[data-photo-index]',canvas).forEach(b=>b.onclick=()=>openGalleryLightbox(g,Number(b.dataset.photoIndex)));$$('[data-gallery-entity]',canvas).forEach(b=>b.onclick=()=>{galleryEvent(Number(g.id),'entity_open',{entity_type:b.dataset.galleryEntity,entity_id:b.dataset.galleryEntityId});if(b.dataset.galleryEntity==='story')navigate('story',{slug:b.dataset.galleryEntitySlug});else navigate(b.dataset.galleryEntity,{id:b.dataset.galleryEntityId})})
  }
  function renderReleases(){const rows=releases.filter(r=>(r.state||'published')==='published'&&r.public_visible!==false);canvas.innerHTML=`<article class="module"><div class="module-kicker">STONEFELLOW RELEASES</div><h2>Releases</h2><div class="release-browser">${rows.length?rows.map(r=>`<div class="release-browser-wrap"><button class="release-browser-row" type="button" data-release-id="${esc(r.id)}"><span class="release-cover">${r.artwork?.cover?`<img src="${esc(r.artwork.cover)}" alt="">`:'SF'}</span><span><strong>${esc(r.title)}</strong><small>${esc((r.type||'release').toUpperCase())}${r.release_date?' • '+esc(r.release_date):''}</small></span></button>${favoriteButton('release',r.id,'Favorite release')}</div>`).join(''):'<p class="player-note">No published releases yet.</p>'}</div></article>`;$$('[data-release-id]',canvas).forEach(b=>b.onclick=()=>openRelease(b.dataset.releaseId,true));bindFavoriteButtons(canvas)}
  function addReleaseTracksToCart(r,tracks){let added=0;for(const t of tracks){if(!st.cart.some(x=>x.type==='track'&&x.track_id===t.id)){st.cart.push({type:'track',track_id:t.id});added++}}persistCart();say(added?'Added '+added+' digital track'+(added===1?'':'s')+' from “'+r.title+'” to your cart.':'Those tracks are already in your cart.',added?renderCart:null)}
  function openRelease(id,push=false){const r=releases.find(x=>x.id===id);if(!r){renderReleases();return}const tracks=(r.track_ids||[]).map(trackById).filter(Boolean),cover=r.artwork?.cover||r.artwork||'',releaseCredits=Array.isArray(r.credits)?r.credits:[],notes=r.notes||r.liner_notes||'',runtime=tracks.reduce((n,t)=>n+Number(t.duration||0),0);starters.hidden=true;canvas.innerHTML=`<article class="module release-detail-module"><div class="module-kicker">${esc((r.type||'release').toUpperCase())}</div><div class="release-detail-hero"><div class="release-detail-cover rich-release-cover">${cover?`<img src="${esc(cover)}" alt="${esc(r.title)} cover">`:'<span>SF</span>'}</div><div><div class="title-actions"><h2>${esc(r.title)}</h2>${favoriteButton('release',r.id,'Favorite release')}</div><p class="release-meta">${r.release_date?esc(r.release_date):'Release date not set'}${tracks.length?' · '+tracks.length+' track'+(tracks.length===1?'':'s'):''}${runtime?' · '+time(runtime):''}</p><p class="player-note">${esc(r.description||'No release notes have been added yet.')}</p><div class="player-controls">${tracks.length?`<button class="buy-primary" type="button" data-play-release>Play release</button><button class="text-control" type="button" data-buy-release>Buy digital album · includes 1 print license per song</button>`:''}${r.vinyl_enabled?`<button class="buy-primary" type="button" data-buy-album-vinyl>Order locked album vinyl · ${money(r.vinyl_price_cents||0)}</button>`:''}${(r.vinyl_license_packages||[]).length?`<label class="player-note">More album print licenses <select data-album-license-select>${r.vinyl_license_packages.map(p=>`<option value="${Number(p.licenses)}">${Number(p.licenses)} record(s) · ${money(p.price_cents)}</option>`).join('')}</select></label><button class="text-control" type="button" data-buy-album-licenses>Add album licenses</button>`:''}<span id="releaseVinylBalance" class="player-note" role="status">Checking album print licenses…</span><button class="text-control" type="button" data-command="tell me about ${esc(r.title)}">Ask the agent</button>${shareButton('release',r.id,r.title)}</div></div></div>${releaseCredits.length||notes?`<section class="release-context"><h3>Release notes & credits</h3>${releaseCredits.length?`<ul class="credit-list">${releaseCredits.map(x=>`<li>${esc(x)}</li>`).join('')}</ul>`:''}${notes?`<p class="player-note">${esc(notes).replace(/\n/g,'<br>')}</p>`:''}</section>`:''}<section id="releaseStoryLinks" class="entity-story-links" hidden></section><section class="release-tracklist"><div class="module-kicker">TRACKLIST</div><div class="catalog-list">${tracks.map((t,i)=>`<div class="catalog-row rich-release-track"><span class="track-number">${String(i+1).padStart(2,'0')}</span><button class="catalog-play" data-play-track="${esc(t.id)}" type="button" aria-label="Play ${esc(t.title)}">▶</button><button class="catalog-title-button" data-release-track-open="${esc(t.id)}" type="button"><strong>${esc(t.title)}</strong><small>${esc((t.mood||[]).slice(0,2).join(' · '))}</small></button>${favoriteButton('track',t.id,'Favorite')}<button class="tiny-control" type="button" data-queue-track="${esc(t.id)}" data-queue-placement="end">+ Queue</button><button class="tiny-control" type="button" data-release-add-build="${esc(t.id)}">+ Record</button><div class="duration">${time(t.duration)}</div></div>`).join('')}</div></section><div id="publicReleaseMedia" hidden></div></article>`;const releaseArchive=archiveReleaseContextHtml(r);if(releaseArchive){const list=$('.release-tracklist',canvas);if(list)list.insertAdjacentHTML('beforebegin',releaseArchive)}bind(canvas);bindTrackButtons(canvas);bindFavoriteButtons(canvas);bindQueueTrackButtons(canvas);renderPublicEntityMedia('release',r.id,'publicReleaseMedia','RELEASE MEDIA');renderEntityStories('release',r.id,'releaseStoryLinks');$$('[data-release-track-open]',canvas).forEach(b=>b.onclick=()=>{const t=trackById(b.dataset.releaseTrackOpen);if(t){renderStory(t);setUrl('track',{id:t.id})}});$$('[data-release-add-build]',canvas).forEach(b=>b.onclick=()=>{const t=trackById(b.dataset.releaseAddBuild);if(t)addTrackToBuilderFromDetail(t)});const playRelease=$('[data-play-release]',canvas);if(playRelease&&tracks.length)playRelease.onclick=()=>{st.playlistPlayback={playlistId:'release:'+r.id,playlistName:r.title,trackIds:tracks.map(t=>t.id),index:0};play(tracks[0],0,'release',true)};const buyRelease=$('[data-buy-release]',canvas);if(buyRelease)buyRelease.onclick=()=>addDigitalAlbumToCart(r);
  const albumVinyl=$('[data-buy-album-vinyl]',canvas);if(albumVinyl)albumVinyl.onclick=()=>addAlbumVinylToCart(r);
  const albumLicenses=$('[data-buy-album-licenses]',canvas);if(albumLicenses)albumLicenses.onclick=()=>addVinylLicensePack('album_vinyl_license_pack',r.id,Number($('[data-album-license-select]',canvas)?.value||0));void updatePrintLicenseBalance(tracks.map(t=>t.id),'#releaseVinylBalance');if(push)setUrl('release',{id:r.id})}


  // 88B2 Artist World: published nodes only, navigation stays inside the existing SPA.
  const artistWorldState={nodes:[],edges:[],selected:'',filter:'all',query:'',partial:[]};
  const artistWorldKinds={track:'Songs',release:'Albums',story:'Stories',video:'Videos',photo:'Photo Collections',show:'Live Shows'};
  function artistWorldPhoto(node){
    return node.image?'<img src="'+esc(node.image)+'" alt="" loading="lazy">':'<span class="artist-world-fallback" aria-hidden="true">'+esc(node.type.slice(0,1).toUpperCase())+'</span>';
  }
  function artistWorldCard(node){
    return '<button type="button" class="artist-world-card'+(node.key===artistWorldState.selected?' selected':'')+'" data-world-key="'+esc(node.key)+'" aria-pressed="'+(node.key===artistWorldState.selected?'true':'false')+'">'+
      '<span class="artist-world-card-art">'+artistWorldPhoto(node)+'</span>'+
      '<span class="artist-world-card-body"><small>'+esc(artistWorldKinds[node.type]||node.type)+'</small><strong>'+esc(node.title)+'</strong>'+
      (node.summary?'<span>'+esc(node.summary)+'</span>':'')+'</span></button>';
  }
  function paintArtistWorld(){
    const graph=artistWorldState,byId=new Map(graph.nodes.map(n=>[n.key,n]));
    const filter=graph.filter,q=graph.query.trim().toLowerCase();
    const visible=graph.nodes.filter(n=>(filter==='all'||n.type===filter)&&(!q||(n.title+' '+n.summary).toLowerCase().includes(q)));
    const grid=$('#artistWorldGrid',canvas);
    if(grid)grid.innerHTML=visible.length?visible.slice(0,160).map(artistWorldCard).join(''):'<div class="artist-world-empty">No published items match. Try another collection or search.</div>';
    const chosen=byId.get(graph.selected)||graph.nodes[0]||null;
    const detail=$('#artistWorldDetail',canvas);
    if(detail){
      if(!chosen)detail.innerHTML='<div class="artist-world-empty">Published music and stories will appear as the artist releases them.</div>';
      else {
        const connections=[];
        for(const e of graph.edges){
          const other=e.from===chosen.key?byId.get(e.to):e.to===chosen.key?byId.get(e.from):null;
          if(other&&other.key!==chosen.key&&!connections.some(x=>x.key===other.key))connections.push(other);
          if(connections.length>=18)break;
        }
        detail.innerHTML='<div class="artist-world-detail-art">'+artistWorldPhoto(chosen)+'</div>'+
          '<div class="module-kicker">'+esc(artistWorldKinds[chosen.type]||chosen.type)+'</div>'+
          '<h2>'+esc(chosen.title)+'</h2>'+
          (chosen.summary?'<p>'+esc(chosen.summary)+'</p>':'<p>Explore this part of the Stonefellow story.</p>')+
          '<div class="artist-world-detail-actions"><button class="buy-primary" type="button" id="artistWorldOpen">Open '+esc(chosen.type==='track'?'song':chosen.type==='release'?'album':'experience')+'</button><button class="text-control" type="button" data-view="builder">Design vinyl</button></div>'+
          '<h3>Connected in this world</h3>'+
          (connections.length?'<div class="artist-world-related">'+connections.map(x=>'<button class="artist-world-link" type="button" data-world-key="'+esc(x.key)+'"><small>'+esc(artistWorldKinds[x.type]||x.type)+'</small><strong>'+esc(x.title)+'</strong></button>').join('')+'</div>':
            '<p class="player-note">This is a starting point. More connections appear as related stories and media are published.</p>');
        $('#artistWorldOpen',detail).onclick=()=>navigate(chosen.view,chosen.params);
        bind(detail);
      }
    }
    canvas.querySelectorAll('[data-world-key]').forEach(button=>button.onclick=()=>{
      const key=button.dataset.worldKey;if(!byId.has(key))return;
      graph.selected=key;paintArtistWorld();setUrl('world',{id:key});
      const panel=$('#artistWorldDetail',canvas);
      if(panel&&window.innerWidth<800)panel.scrollIntoView({behavior:'smooth',block:'start'});
    });
    canvas.querySelectorAll('[data-world-filter]').forEach(button=>{
      const active=button.dataset.worldFilter===graph.filter;
      button.setAttribute('aria-pressed',String(active));
    });
  }
  async function renderArtistWorld(params={}){
    starters.hidden=true;
    canvas.innerHTML='<article class="module artist-world"><div class="module-kicker">STONEFELLOW · ARTIST WORLD</div><h1>Every song has a world around it.</h1><p>Travel between Stonefellow music, stories, photography, films and live moments without interrupting playback.</p><div class="artist-world-empty">Opening Artist World…</div></article>';
    try{
      const j=await api('artist-world.php'),w=j.world||{};
      artistWorldState.nodes=Array.isArray(w.nodes)?w.nodes:[];
      artistWorldState.edges=Array.isArray(w.edges)?w.edges:[];
      artistWorldState.partial=Array.isArray(w.unavailable_sources)?w.unavailable_sources:[];
    }catch(e){
      canvas.innerHTML='<article class="module artist-world"><div class="module-kicker">ARTIST WORLD</div><h1>The world is temporarily unavailable.</h1><p>'+esc(e.message)+'</p><button type="button" class="text-control" data-view="music">Explore songs instead</button></article>';
      bind(canvas);return;
    }
    const types=['all','release','track','story','video','photo','show'];
    const counts={};for(const n of artistWorldState.nodes)counts[n.type]=(counts[n.type]||0)+1;
    artistWorldState.selected=artistWorldState.nodes.some(n=>n.key===String(params.id||''))?String(params.id):artistWorldState.nodes[0]?.key||'';
    artistWorldState.filter='all';artistWorldState.query='';
    canvas.innerHTML='<article class="module artist-world" aria-label="Artist World exploration"><div class="module-kicker">STONEFELLOW · ARTIST WORLD</div>'+
      '<div class="artist-world-intro"><div><h1>Explore the world behind the music.</h1><p>Songs, albums, stories, films, photographs and live moments are all connected. Choose any doorway and see what surrounds it.</p></div><button type="button" class="text-control" data-view="builder">Make your own vinyl</button></div>'+
      (artistWorldState.partial.length?'<p class="player-note" role="status">Some published collections are temporarily unavailable: '+esc(artistWorldState.partial.join(', '))+'.</p>':'')+
      '<div class="artist-world-controls"><div class="artist-world-filters" role="group" aria-label="Explore content types">'+types.map(t=>'<button type="button" data-world-filter="'+t+'" aria-pressed="'+(t==='all'?'true':'false')+'">'+(t==='all'?'All':artistWorldKinds[t])+' <span>'+ (t==='all'?artistWorldState.nodes.length:(counts[t]||0))+'</span></button>').join('')+'</div>'+
      '<label class="artist-world-search">Search this world<input id="artistWorldSearch" type="search" autocomplete="off" placeholder="Song, story, album…" maxlength="100" aria-label="Search published Artist World content"></label></div>'+
      '<div class="artist-world-layout"><section class="artist-world-grid" id="artistWorldGrid" aria-label="Published experiences"></section>'+
      '<aside id="artistWorldDetail" class="artist-world-detail" aria-label="Selected experience"></aside></div>'+
      '<div class="artist-world-footnote">Every connection comes from published Stonefellow content. Private drafts, purchases, and listening profiles are not exposed.</div></article>';
    bind(canvas);
    canvas.querySelectorAll('[data-world-filter]').forEach(button=>button.onclick=()=>{artistWorldState.filter=button.dataset.worldFilter;paintArtistWorld()});
    $('#artistWorldSearch',canvas).oninput=e=>{artistWorldState.query=e.target.value.slice(0,100);paintArtistWorld()};
    paintArtistWorld();
  }


  async function renderTeamWorkspace(){
    await loadAuth(true);
    if(!st.auth.authenticated){renderLogin();return;}
    canvas.innerHTML='<section class="module"><h1>Team Workspace</h1><p>Loading your roles and activity timeline…</p></section>';
    let result;
    try{result=await api('team-workspace.php');}
    catch(e){canvas.innerHTML='<section class="module"><h1>Team Workspace</h1><p role="alert">'+esc(e.message||'Access denied')+'</p></section>';return;}
    const w=result.workspace||{},roles=Array.isArray(w.roles)?w.roles:[],
      permissions=Array.isArray(w.permissions)?w.permissions:[],catalog=w.role_catalog||{},
      roster=Array.isArray(w.roster)?w.roster:[],timeline=w.timeline||{},events=Array.isArray(timeline.events)?timeline.events:[];
    const canUpload=permissions.includes('media.upload')||st.auth.user?.role==='admin';
    canvas.innerHTML='<article class="module team-workspace"><div class="module-kicker">STONEFELLOW · TEAM</div>'+
      '<h1>Team Workspace</h1><p>Your assigned roles determine which team tools you can use. Your account, purchases and private fan records stay separate.</p>'+
      '<section class="panel"><h2>My roles</h2><div class="team-role-tags">'+roles.map(r=>'<span class="team-role-tag">'+esc(catalog[r]?.label||r)+'</span>').join('')+
      (roles.length?'':'<span>Site administrator</span>')+'</div></section>'+
      (canUpload?'<section class="panel"><h2>Upload to Media Library</h2><p>Upload audio, images or video for review. Uploading does not publish a song or grant rights.</p>'+
      '<input id="teamUploadFile" type="file" accept="audio/*,image/*,video/*">'+
      '<button class="buy-primary" type="button" id="teamUploadButton">Upload media</button><p id="teamUploadStatus" role="status"></p></section>':'')+
      (roster.length?'<section class="panel"><h2>Team roster</h2><div class="team-roster">'+roster.map(u=>
        '<div class="team-roster-row"><strong>'+esc(u.display_name||'Team member')+'</strong><small>'+esc((u.team_roles||[]).map(r=>catalog[r]?.label||r).join(' · '))+'</small></div>').join('')+'</div></section>':'')+
      '<section class="panel"><h2>Activity timeline</h2><p>'+esc(timeline.note||'')+'</p>'+
      '<div class="team-timeline">'+events.map(e=>'<div class="team-timeline-event"><time datetime="'+esc(e.created_at||'')+'">'+esc(e.created_at||'')+'</time><strong>'+esc(e.actor_name||'System')+'</strong><span>'+esc(e.description||e.event_type||'Activity')+'</span><small>'+esc(e.entity_type||'')+' '+esc(e.entity_id||'')+'</small></div>').join('')+
      (events.length?'':'<p>No team activity is recorded yet.</p>')+'</div>'+
      (timeline.has_more?'<button type="button" id="teamLoadMore">More activity</button>':'')+'</section></article>';
    if(canUpload){
      const btn=$('#teamUploadButton',canvas),file=$('#teamUploadFile',canvas),status=$('#teamUploadStatus',canvas);
      btn.onclick=async()=>{
        if(!file.files?.length){status.textContent='Select a file first.';return;}
        btn.disabled=true;status.textContent='Uploading…';
        try{const data=new FormData();data.append('file',file.files[0]);
          const r=await fetch((cfg.apiBase||'api')+'/team-media-upload.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':st.auth.csrf||''},body:data});
          const body=await r.json();if(!r.ok||body.ok===false)throw new Error(body.message||'Upload was rejected.');
          status.textContent='Uploaded '+(body.asset?.original_name||'media')+' to Media Library for review.';
          file.value='';
        }catch(e){status.textContent=e.message||'Upload failed.';}finally{btn.disabled=false;}
      };
    }
    const more=$('#teamLoadMore',canvas);
    if(more)more.onclick=async()=>{
      more.disabled=true;try{const n=await api('team-workspace.php?offset='+Number(timeline.next_offset||80));
        const t=n.workspace?.timeline||{};const container=$('.team-timeline',canvas);
        if(container)container.insertAdjacentHTML('beforeend',(t.events||[]).map(e=>
          '<div class="team-timeline-event"><time>'+esc(e.created_at||'')+'</time><strong>'+esc(e.actor_name||'System')+'</strong><span>'+esc(e.description||'')+'</span></div>').join(''));
        if(t.has_more){more.disabled=false;more.onclick=async()=>navigate('team');}else more.remove();
      }catch(e){more.disabled=false;}
    };
  }


  async function renderCreativeCapture(){
    await loadAuth(true);
    if(!st.auth.authenticated){renderLogin();return;}
    starters.hidden=true;
    canvas.innerHTML='<section class="module"><h1>Creative Capture</h1><p>Loading capture requests…</p></section>';
    let data;try{data=await api('story-capture.php');}catch(e){
      canvas.innerHTML='<section class="module"><h1>Creative Capture</h1><p role="alert">'+esc(e.message||'Unavailable')+'</p></section>';return;}
    const requests=Array.isArray(data.requests)?data.requests:[],
      my=Array.isArray(data.submissions)?data.submissions:[];
    let assignedTasks=[],taskUpdates=[],scheduleBoard={tasks:[],counts:{}},myAvailability=[],myDeadlineRequests=[];
    try{
      const response=await api('story-production-tasks.php');
      assignedTasks=Array.isArray(response.tasks)?response.tasks:[];
      taskUpdates=Array.isArray(response.task_updates)?response.task_updates:[];
      scheduleBoard=response.schedule_board||scheduleBoard;
      myAvailability=Array.isArray(response.my_availability)?response.my_availability:[];
      myDeadlineRequests=Array.isArray(response.my_deadline_requests)?response.my_deadline_requests:[];
    }catch(e){/* Capture functionality stays usable during task-service outage. */}

    canvas.innerHTML='<article class="module capture-studio"><div class="module-kicker">ARTIST WORLD · CREATIVE STUDIO</div>'+
      '<h1>Capture the world around the music.</h1>'+
      '<p>Respond to a Director request or contribute your own background scene, photograph, ambient sound, dialogue or story idea. All submissions remain private until reviewed.</p>'+
      '<div class="capture-layout"><section><h2>Director requests</h2>'+
      (requests.map(r=>'<div class="capture-request"><small>'+esc(r.shot_type||'')+' · '+esc(r.target_role||'')+'</small>'+
        '<h3>'+esc(r.title||'')+'</h3><p>'+esc(r.brief||'')+'</p>'+
        (r.creative_suggestion?'<p><strong>Creative direction:</strong> '+esc(r.creative_suggestion)+'</p>':'')+
        '<button type="button" data-capture-request="'+Number(r.id)+'">Capture this shot</button></div>').join('')||'<p>No open requests yet. Background contributions are welcome.</p>')+
      '</section><section class="capture-submit"><h2>Contribute a capture</h2><form id="creativeCaptureForm">'+
      '<label>Request<select name="request_id" id="creativeCaptureRequest"><option value="0">General / background material</option>'+
      requests.map(r=>'<option value="'+Number(r.id)+'">'+esc(r.title||'')+'</option>').join('')+'</select></label>'+
      '<label>Type<select name="submission_type">'+
      [['photo','Photograph'],['video','Video'],['audio','Audio'],['ambient','Ambient sound'],['dialogue','Dialogue'],['story_idea','Story idea'],['screenshot','Suggested screenshot/frame']].map(a=>'<option value="'+a[0]+'">'+a[1]+'</option>').join('')+'</select></label>'+
      '<label>Title<input name="title" required maxlength="180"></label>'+
      '<label>Description<textarea name="description" maxlength="3000" rows="3"></textarea></label>'+
      '<label>Suggested dialogue<textarea name="dialogue_text" maxlength="5000" rows="2"></textarea></label>'+
      '<label>Upload existing photo, video or audio<input name="file" type="file" accept="image/*,video/*,audio/*"></label>'+
      '<label>Capture from camera<input id="creativeCamera" type="file" accept="image/*,video/*" capture="environment"></label>'+
      '<label>Captured at<input name="captured_at" type="datetime-local"></label>'+
      '<label>People and likeness release<select name="people_release_status"><option value="unconfirmed">Not yet cleared</option><option value="requires_review">May require releases</option><option value="not_applicable">No recognizable people</option></select></label>'+
      '<label class="capture-agreement"><input name="rights_attested" type="checkbox" required> I have the right to submit this material for private editorial review. Publication and use of likenesses require separate approval.</label>'+
      '<button class="buy-primary" type="submit">Submit for review</button><p role="status" id="creativeCaptureStatus"></p></form></section></div>'+
      '<section class="capture-history"><h2>My assigned production tasks</h2>'+
      '<p class="player-note">Tasks are private. Submit your own captured evidence or an editorial assessment. A separate human editor decides when work is complete; publication rights are independent.</p>'+
      (assignedTasks.map(t=>{
        const proofs=my.filter(x=>Number(x.request_id)===Number(t.source_id));
        const calendar=(scheduleBoard.tasks||[]).find(x=>Number(x.task_id)===Number(t.id))||{};
        const extensions=myDeadlineRequests.filter(x=>Number(x.task_id)===Number(t.id));
        return '<div class="capture-request"><small>'+esc(t.status)+' · '+esc(t.target_role)+'</small>'+
          '<h3>'+esc(t.title)+'</h3><p>'+esc(t.brief)+'</p>'+
          (calendar.due_at?'<p class="player-note">Due '+esc(calendar.due_at)+' · '+esc(calendar.priority)+
            ' priority'+(calendar.flags?.length?' · '+esc(calendar.flags.join(', ')):'')+'</p>':'')+

          (['assigned','in_progress'].includes(t.status)?'<label>Evidence<select data-assigned-evidence="'+Number(t.id)+'">'+
            (t.kind==='editorial'?'<option value="'+Number(t.source_id)+'">Review proposal #'+Number(t.source_id)+'</option>':
              '<option value="">Select your capture for this exact request</option>'+
              proofs.map(x=>'<option value="'+Number(x.id)+'">#'+Number(x.id)+' · '+esc(x.title)+' · '+esc(x.review_status)+'</option>').join(''))+
            '</select></label><textarea data-assigned-note="'+Number(t.id)+'" rows="2" maxlength="750" '+
            'placeholder="Describe your work and evidence for human review (15+ characters)"></textarea>'+
            '<div class="actions"><button type="button" data-assigned-progress="'+Number(t.id)+'">Record progress</button>'+
            '<button type="button" data-assigned-submit="'+Number(t.id)+'">Submit completed work</button></div>'+
            (calendar.due_at?'<details><summary>Ask Agent team for a deadline extension</summary>'+
              '<p class="player-note">Propose a later date and explain the reason. The production editor must review it in Agent Chat before anything changes.</p>'+
              '<label>Proposed date (local)<input type="datetime-local" data-extension-date="'+Number(t.id)+'"></label>'+
              '<label>Reason<textarea rows="2" maxlength="900" data-extension-reason="'+Number(t.id)+'" placeholder="Explain why the original deadline needs an extension (25+ characters)"></textarea></label>'+
              '<button type="button" data-extension-request="'+Number(t.id)+'">Request editor approval in chat</button></details>':'')+
            (extensions.length?'<p class="player-note">Extension history: '+extensions.slice(0,5).map(x=>
              '#'+Number(x.id)+' · '+esc(x.status)+' · '+esc(x.proposed_due_at)).join(' | ')+'</p>':''):
            '<p>Evidence #'+Number(t.evidence_id||0)+' · '+esc(t.status)+
            (t.review_basis?' · '+esc(t.review_basis):'')+'</p>')+'</div>';
      }).join('')||'<p>No private production tasks assigned to you.</p>')+
      '<details><summary>My crew availability</summary><p class="player-note">Mark when you are available. The AI Director may suggest scheduling changes but cannot automatically assign you.</p>'+
      '<form id="crewAvailabilityForm"><label>From<input name="start_date" type="date" required></label>'+
      '<label>To<input name="end_date" type="date" required></label>'+
      '<label>Status<select name="status"><option value="available">Available</option><option value="limited">Limited</option><option value="unavailable">Unavailable</option></select></label>'+
      '<label>Notes<textarea name="note" rows="2" maxlength="500" required placeholder="Availability or production conflicts (10+ characters)"></textarea></label>'+
      '<button type="submit">Save availability</button><p role="status" id="crewAvailabilityStatus"></p></form>'+
      '<ul>'+myAvailability.map(x=>'<li>'+esc(x.start_date)+' – '+esc(x.end_date)+' · '+esc(x.status)+
         ' · '+esc(x.note)+'</li>').join('')+'</ul></details>'+
      '<details><summary>My task activity ('+Number(taskUpdates.length)+')</summary>'+
      '<ol class="capture-task-activity">'+taskUpdates.map(e=>
        '<li>'+esc(e.created_at)+' · Task #'+Number(e.task_id)+' · '+
        esc(e.event_type)+' → '+esc(e.status_to)+'</li>').join('')+'</ol></details>'+
      '<p role="status" id="assignedTaskStatus"></p></section>'+
      '<section class="capture-history"><h2>My contributions</h2>'+
      (my.map(r=>'<div><time>'+esc(r.created_at||'')+'</time><strong>'+esc(r.title||'')+'</strong><small>'+esc(r.review_status||'')+'</small></div>').join('')||'<p>Nothing submitted yet.</p>')+'</section>'+
      '<p class="player-note">AI suggestions and original uploads are evidence for an editorial review—not automatic permission to generate or publish a finished video.</p></article>';
    canvas.querySelectorAll('[data-capture-request]').forEach(b=>b.onclick=()=>{
      $('#creativeCaptureRequest',canvas).value=b.dataset.captureRequest;
      $('#creativeCaptureForm',canvas).scrollIntoView({behavior:'smooth',block:'center'});
    });
    const availabilityForm=canvas.querySelector('#crewAvailabilityForm');
    if(availabilityForm)availabilityForm.onsubmit=async event=>{
      event.preventDefault();
      const values=Object.fromEntries(new FormData(availabilityForm).entries());
      const status=canvas.querySelector('#crewAvailabilityStatus');
      if(String(values.note||'').trim().length<10){if(status)status.textContent='Explain availability in at least 10 characters.';return;}
      try{
        await api('story-production-tasks.php',{action:'availability',...values});
        await renderCreativeCapture();
      }catch(err){if(status)status.textContent=err.message||'Availability update unavailable.';}
    };
    canvas.querySelectorAll('[data-extension-request]').forEach(button=>button.onclick=async()=>{
      const id=Number(button.dataset.extensionRequest),
        value=canvas.querySelector('[data-extension-date="'+id+'"]')?.value||'',
        reason=canvas.querySelector('[data-extension-reason="'+id+'"]')?.value.trim()||'',
        timestamp=new Date(value),status=canvas.querySelector('#assignedTaskStatus');
      if(!id||!value||!Number.isFinite(timestamp.getTime())||reason.length<25){
        if(status)status.textContent='Choose a later date and provide at least 25 characters explaining the request.';return;
      }
      button.disabled=true;
      try{
        const reply=await api('story-production-tasks.php',{action:'request_deadline',task_id:id,
          proposed_due_at:timestamp.toISOString().replace('.000Z','Z'),reason});
        if(status)status.textContent='Extension request #'+Number(reply.request?.id)+' saved. Editor review appears in Agent Chat; the current date is unchanged.';
        await renderCreativeCapture();
      }catch(err){if(status)status.textContent=err.message||'Deadline request unavailable.';button.disabled=false;}
    });
    canvas.querySelectorAll('[data-assigned-progress]').forEach(button=>button.onclick=async()=>{
      const task=Number(button.dataset.assignedProgress);
      const note=canvas.querySelector('[data-assigned-note="'+task+'"]')?.value.trim()||'';
      const status=canvas.querySelector('#assignedTaskStatus');
      if(note.length<15){if(status)status.textContent='Describe actual task progress (15+ characters).';return;}
      button.disabled=true;
      try{
        await api('story-production-tasks.php',{action:'progress',task_id:task,note});
        await renderCreativeCapture();
      }catch(err){if(status)status.textContent=err.message||'Progress update failed.';button.disabled=false;}
    });
    canvas.querySelectorAll('[data-assigned-submit]').forEach(button=>button.onclick=async()=>{
      const task=Number(button.dataset.assignedSubmit),evidence=Number(
        canvas.querySelector('[data-assigned-evidence="'+task+'"]')?.value||0);
      const note=canvas.querySelector('[data-assigned-note="'+task+'"]')?.value.trim()||'';
      const status=canvas.querySelector('#assignedTaskStatus');
      if(evidence<1||note.length<15){if(status)status.textContent='Select valid evidence and provide at least 15 characters of notes.';return;}
      button.disabled=true;try{
        await api('story-production-tasks.php',{action:'submit',task_id:task,evidence_id:evidence,note});
        if(status)status.textContent='Evidence submitted privately for independent human review. Nothing was published.';
        await renderCreativeCapture();
      }catch(err){if(status)status.textContent=err.message||'Task submission unavailable.';button.disabled=false;}
    });
    const formCapture=$('#creativeCaptureForm',canvas),camera=$('#creativeCamera',canvas);
    camera.onchange=()=>{if(camera.files?.length)formCapture.querySelector('[name="file"]').value='';};
    formCapture.onsubmit=async e=>{
      e.preventDefault();
      const note=$('#creativeCaptureStatus',canvas),button=formCapture.querySelector('[type="submit"]');
      button.disabled=true;note.textContent='Submitting privately…';
      try{
        const form=new FormData(formCapture);
        if(camera.files?.length)form.set('file',camera.files[0]);
        const time=String(form.get('captured_at')||'');
        form.set('captured_at',time?new Date(time).toISOString():'');
        let response;
        const file=form.get('file');
        if(file instanceof File&&file.size){
          if(file.size>250*1024*1024)throw new Error('Maximum upload is 250 MB.');
          form.set('rights_attested',form.has('rights_attested')?'1':'0');
          const http=await fetch((cfg.apiBase||'api')+'/story-capture-upload.php',
            {method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':st.auth.csrf||''},body:form});
          response=await http.json();
          if(!http.ok||!response.ok)throw new Error(response.message||response.error||'Upload rejected.');
        }else{
          response=await api('story-capture.php',{
            request_id:Number(form.get('request_id')||0),submission_type:String(form.get('submission_type')),
            title:String(form.get('title')||''),description:String(form.get('description')||''),
            dialogue_text:String(form.get('dialogue_text')||''),captured_at:String(form.get('captured_at')||''),
            people_release_status:String(form.get('people_release_status')),
            rights_attested:form.has('rights_attested')});
        }
        note.textContent='Submitted for review. Nothing has been published.';
        formCapture.reset();camera.value='';
      }catch(err){note.textContent=err.message||'Submission failed.';}
      finally{button.disabled=false;}
    };
  }

  function showNativePageNotice(title,message,view,params){
    if(canvas?.id!=='sfAgentNativePage')return;
    canvas.innerHTML=`<article class="module sf-agent-page-empty" role="status"><div class="module-kicker">STONEFELLOW / PAGE WORKSPACE</div><h2>${esc(title)}</h2><p class="player-note">${esc(message)}</p><button class="buy-primary" type="button" data-agent-retry-page>Try again</button></article>`;
    canvas.querySelector('[data-agent-retry-page]')?.addEventListener('click',()=>void navigate(view,params,false));
  }
  async function navigate(view='home',params={},push=true){
    try{
      await renderNavigation(view,params,push);
      if(canvas?.id==='sfAgentNativePage'&&(!canvas.hasChildNodes()||canvas.querySelector('.sf-agent-page-loading'))){
        showNativePageNotice('Page is empty','This page did not return any content. You can retry without leaving the Agent Canvas.',view,params);
        return false;
      }
      return true;
    }catch(error){
      console.error('[Stonefellow] Page render failed:',view,error);
      if(canvas?.id==='sfAgentNativePage'){
        showNativePageNotice('Page unavailable','The page could not be loaded. Your Agent Canvas and music are still here.',view,params);
        return false;
      }
      throw error;
    }
  }
  async function renderNavigation(view='home',params={},push=true){
    if(view!=='canvas-gallery'){canvasGalleryView?.destroy();canvasGalleryView=null;cancelGalleryOpen();}
    closeMenu();hide();
    if(view==='home'&&window.SFPublicAgentCanvas?.active?.()){
      window.SFPublicAgentCanvas.closePage?.();
      canvas=mainCanvas;
      document.body.classList.remove('builder-workspace-active');
      if(push)setUrl('home',{});else syncSeo('home',{});
      document.dispatchEvent(new CustomEvent('stonefellow:navigation',{detail:{view:'home',params:{}}}));
      return;
    }
    // The record builder is an immersive, full-page studio, not a sidebar view.
    if(view==='builder'&&window.SFPublicAgentCanvas?.active?.()){
      clearListeningRoom();
      window.SFPublicAgentCanvas.unmount();
      document.body.classList.remove('sf-agent-canvas-active');
    }
    const nativePanel=view!=='home'&&view!=='builder'&&homepageTemplate==='agent_canvas'&&window.SFPublicAgentCanvas?.active?.();
    canvas=nativePanel?(window.SFPublicAgentCanvas.openPage?.(view)||mainCanvas):mainCanvas;
    document.body.classList.toggle('builder-workspace-active',view==='builder');if(view!=='home')document.body.classList.remove('artist-home-active','artist-home-chat-hidden');await refreshSiteData();if(st.auth.authenticated&&['home','music','releases','archive','shows','show','community','store','track','release','account','library'].includes(view))await loadPersonalization();if(view==='home'){await renderHome()}else if(view==='press'){await renderPress(params.slug||'',params.token||'')}else if(view==='story'){await renderEditorialStory(params.slug||'')}else if(view==='contact'){await renderContact(params)}else if(view==='canvas-gallery'){await renderCanvasGallery(params)}else if(view==='world'){await renderArtistWorld(params)}else if(view==='videos'){await renderVideos()}else if(view==='video'){await renderVideo(params.slug||'')}else if(view==='photos'){await renderPhotos()}else if(view==='photo'){await renderPhotoGallery(params.slug||'')}else if(view==='search'){await renderSiteSearch(params)}else if(view==='campaign'){await renderCampaign(params.slug||'')}else if(view==='music'){await renderCatalog(params)}else if(view==='releases'){renderReleases()}else if(view==='archive'){renderArchive()}else if(view==='shows'){renderShows()}else if(view==='show'){renderShow(params.id||'')}else if(view==='tickets'){await renderTickets({show_id:params.id||''})}else if(view==='community'){await renderCommunity(params)}else if(view==='newsletter'){renderNewsletter()}else if(view==='store'){await renderStore(params)}else if(view==='playlist'){await renderPlaylist(params.id||0)}else if(view==='history'){await renderListeningHistory(true)}else if(view==='library'){await renderMyLibrary(params)}else if(view==='builder'){window.scrollTo(0,0);renderBuilder()}else if(view==='cart'){await renderCart()}else if(view==='checkout'){await renderCheckout()}else if(view==='about'){await renderAbout()}else if(view==='privacy'){renderLegal('privacy')}else if(view==='terms'){renderLegal('terms')}else if(view==='plans'){await renderPlans()}else if(view==='membership'){await renderMembership()}else if(view==='login'){await loadAuth();st.auth.authenticated?await renderAccount():renderLogin()}else if(view==='register'){await loadAuth();st.auth.authenticated?await renderAccount():renderRegister(params)}else if(view==='forgot-password'){renderForgotPassword()}else if(view==='reset-password'){renderResetPassword(params.token||'')}else if(view==='verify-email'){await renderVerifyEmail(params.token||'')}else if(view==='capture'){await renderCreativeCapture()}else if(view==='team'){await renderTeamWorkspace()}else if(view==='account'){await renderAccount()}else if(view==='account-privacy'){await renderPrivacyCenter()}else if(view==='agent-brain'){await renderMyAgentBrain()}else if(view==='agent-chat'){await renderMyAgentConversation()}else if(view==='order'){await renderOrderDetail(params.id||'')}else if(view==='track'){const t=trackById(params.id);if(t)renderStory(t);else await renderCatalog({})}else if(view==='release'){openRelease(params.id,false)}else{await renderHome();view='home'}bindShareButtons(canvas);if(push)setUrl(view,params);else syncSeo(view,params);document.dispatchEvent(new CustomEvent('stonefellow:navigation',{detail:{view,params}}))}
  async function routeFromLocation(){const u=new URL(location.href),initial=window.STONEFELLOW_INITIAL_ROUTE||{},view=u.searchParams.get('view')||initial.view||'home',hashParams=new URLSearchParams(u.hash.replace(/^#/,'')),params={id:u.searchParams.get('id')||initial.id||'',plan:u.searchParams.get('plan')||'',token:u.searchParams.get('token')||hashParams.get('token')||'',q:u.searchParams.get('q')||'',type:u.searchParams.get('type')||'all',mood:u.searchParams.get('mood')||'',theme:u.searchParams.get('theme')||'',release:u.searchParams.get('release')||'',year:u.searchParams.get('year')||'',energy:u.searchParams.get('energy')||'',sort:u.searchParams.get('sort')||'relevance',tab:u.searchParams.get('tab')||'',collection:u.searchParams.get('collection')||'',newsletter:u.searchParams.get('newsletter')||'',unsubscribe:u.searchParams.get('unsubscribe')||'',slug:u.searchParams.get('slug')||initial.slug||''};if(!window.__STONEFELLOW_DISCOVERY_RECORDED){window.__STONEFELLOW_DISCOVERY_RECORDED=true;recordDiscovery('landing',{route_type:view,route_id:params.id||params.slug||''})}

    if(homepageTemplate==='agent_canvas'&&view!=='home'&&view!=='builder'&&!window.SFPublicAgentCanvas?.active?.()){
      canvas=mainCanvas;
      await renderHome();
      return navigate(view,params,false);
    }
    return navigate(view,params,false);
  }

  function updateFooterPlayer(){
    window.StonefellowTurntable?.sync?.(st.track,st.playing,st.builder);
    if(!st.track){
      footerPlayer.hidden=true;
      songMoments?.setTrack(null);
      window.SFPublicAgentCanvas?.syncPlayer?.();
      return;
    }
    footerPlayer.hidden=false;
    songMoments?.setTrack(st.track);
    playerTitle.textContent=st.track.title;
    playerRelease.textContent=st.track.release||'Stonefellow';
    playerDuration.textContent=time(Number.isFinite(audio.duration)&&audio.duration>0?audio.duration:st.track.duration);
    playerToggle.textContent=st.playing?'Ⅱ':'▶';
    playerToggle.setAttribute('aria-label',st.playing?'Pause':'Play');
    const art=st.track.artwork||'';
    playerArtwork.innerHTML=art?`<img src="${esc(art)}" alt="">`:'<span>SF</span>';
    document.body.classList.add('has-player');
    window.SFPublicAgentCanvas?.syncPlayer?.();
  }
  function playbackEndedEarly(){
    // A Media Library preview intentionally ends before the master duration.
    // Other large mismatches mean the published file or stream is incomplete;
    // never log a full listen or loop/advance a truncated song silently.
    const actual=Number(audio.duration),position=Number(audio.currentTime)||0;
    if(Number.isFinite(actual)&&actual>=10&&position<actual-Math.max(5,actual*.02))return true;
    if(['preview_only','preview_master_split'].includes(st.track?.audio_publishing_mode))return false;
    const published=Number(st.track?.duration||0);
    const played=Number(audio.currentTime||0);
    const fileDuration=Number.isFinite(audio.duration)?audio.duration:played;
    return published>=40&&played>=2&&Math.max(played,fileDuration)<published-Math.max(15,published*.08);
  }
  function rememberPlaybackPosition(){
    if(!pendingNetworkRecovery&&!audio.error&&!audio.seeking&&Number(audio.readyState)>=2)
      playbackLifecycle.remember(playbackLifecycle.token(),Number(audio.currentTime));
  }
  function savedPlaybackPosition(){
    // Replaying a completed song starts at zero; its EOF is not a resume seek.
    if(audio.ended&&!playbackEndedEarly())return 0;
    return Math.max(playbackLifecycle.position(playbackLifecycle.token()),Number(audio.currentTime)||0);
  }
  function setPlaybackStatus(message='',phase=''){
    if(playerPlaybackStatus){playerPlaybackStatus.textContent=message;playerPlaybackStatus.hidden=!message}
    if(footerPlayer){if(phase)footerPlayer.dataset.playbackPhase=phase;else delete footerPlayer.dataset.playbackPhase}
  }
  function clearPlaybackBufferWatch(){if(playbackBufferTimer!==null){clearTimeout(playbackBufferTimer);playbackBufferTimer=null}}
  function cancelNetworkRecovery(){
    if(!pendingNetworkRecovery)return;
    const recovery=pendingNetworkRecovery;pendingNetworkRecovery=null;
    recovery.controller?.cancel();recovery.reject?.(new Error('Playback action replaced'));
  }
  function invalidatePlayback(){
    playbackWantsAudio=false;playbackLifecycle.begin(savedPlaybackPosition());clearPlaybackBufferWatch();cancelNetworkRecovery();setPlaybackStatus();
  }
  function playbackProblem(message){
    playbackWantsAudio=false;st.playing=false;clearPlaybackBufferWatch();cancelNetworkRecovery();
    setPlaybackStatus(message,'error');updateFooterPlayer();
  }
  function recoverNetworkPlayback(){
    const token=playbackLifecycle.token(),code=Number(audio.error?.code||0);
    if(!playbackWantsAudio||!st.track?.audio||!playbackLifecycle.allowNetworkRetry(token,code))return false;
    const id=String(st.track.id),source=String(st.track.audio);
    // MediaError can reset currentTime before error dispatch. Keep the last verified progress.
    const position=Math.max(playbackLifecycle.position(token),Number(audio.currentTime)||0);
    clearPlaybackBufferWatch();setPlaybackStatus('Connection interrupted · restoring '+time(position)+'…','buffering');
    const recovery={token,position,controller:null};
    pendingNetworkRecovery=recovery;
    const current=()=>pendingNetworkRecovery===recovery&&playbackWantsAudio&&playbackLifecycle.isCurrent(token)&&String(st.track?.id)===id;
    const failed=()=>{
      if(!current())return;
      playbackProblem('Cannot resume at '+time(position)+' yet · tap Play to retry. Your position is saved.');
    };
    recovery.controller=window.StonefellowPlaybackLifecycle.restorePosition({audio,position,isCurrent:current,
      onFailure:failed,
      onRestored:()=>{
        if(!current())return;
        let attempt;try{attempt=audio.play()}catch(_){failed();return;}
        Promise.resolve(attempt).then(()=>{
          if(!current())return;
          if(audio.paused){failed();return;}
          cancelNetworkRecovery();st.playing=true;setPlaybackStatus();updateFooterPlayer();
        }).catch(failed);
      }});
    audio.pause();audio.src=source;audio.load();
    return true;
  }
  function play(t,resumeAt=0,source='player',keepPlaylist=false,onFailure=null,listeningContext=null){
    if(!t?.audio)return Promise.resolve(false);
    const changing=!st.track||String(st.track.id)!==String(t.id)||audio.getAttribute('src')!==String(t.audio);
    const reloading=changing||!!audio.error||footerPlayer?.dataset.playbackPhase==='error';
    if(changing){songCaptureSkill?.discard();if(songListeningSkill?.status().active)void songListeningSkill.stop('switch');}
    if(changing&&st.track&&!audio.paused){const dur=Number(audio.duration||st.track.duration||0),pos=Number(audio.currentTime||0);if(!dur||pos<Math.max(0,dur-2))recordListen('skip')}
    const token=playbackLifecycle.begin(Math.max(0,Number(resumeAt)||0));cancelNetworkRecovery();clearPlaybackBufferWatch();setPlaybackStatus();
    if(!keepPlaylist)clearPlaylistPlayback();
    st.playSource=source||'player';st.track=t;playbackWantsAudio=true;st.playing=false;updateQueueUi();
    globalThis.SFListeningTelemetry?.prepareMain?.(listeningContext||{app:true},{trackId:String(t.id),restart:reloading||audio.ended,startAt:Math.max(0,Number(resumeAt)||0)});
    if(reloading){audio.pause();audio.src=t.audio;audio.load()}
    const startAt=Math.max(0,Number(resumeAt)||0);
    if(!reloading&&keepPlaylist&&startAt===0)try{audio.currentTime=0}catch(_){}
    const resumeNeeded=startAt>0&&(reloading||!Number.isFinite(audio.currentTime)||Math.abs(audio.currentTime-startAt)>0.35||audio.seeking);
    updateFooterPlayer();
    let attempt;
    if(resumeNeeded){
      // Manual resume uses the same verified-seek gate as automatic network recovery.
      attempt=new Promise((resolve,reject)=>{
        const recovery={token,position:startAt,controller:null,reject};pendingNetworkRecovery=recovery;
        const current=()=>pendingNetworkRecovery===recovery&&playbackWantsAudio&&playbackLifecycle.isCurrent(token)&&String(st.track?.id)===String(t.id);
        recovery.controller=window.StonefellowPlaybackLifecycle.restorePosition({audio,position:startAt,isCurrent:current,
          onFailure:()=>{if(current()){const error=new Error('Saved position is not seekable yet');error.code='audio_seek_unavailable';error.stage='resume_seek';reject(error);}},
          onRestored:()=>{if(current()){try{Promise.resolve(audio.play()).then(resolve,reject)}catch(error){reject(error)}}}});
        recovery.controller.check();
      });
    }else{try{attempt=audio.play()}catch(error){attempt=Promise.reject(error)}}
    return Promise.resolve(attempt).then(()=>{
      if(!playbackLifecycle.isCurrent(token)||String(st.track?.id)!==String(t.id)||audio.paused)return false;
      cancelNetworkRecovery();st.playing=true;rememberPlaybackPosition();recordListen(startAt>0?'resume':'start');
      agent('playing',(startAt>0?'Resuming ':'Playing ')+'“'+t.title+'.”'+(st.playlistPlayback.playlistId?' · '+st.playlistPlayback.playlistName:'')+' Ask me about it, buy it, or put it on a record.');
      updateFooterPlayer();return true;
    }).catch(error=>{
      if(!playbackLifecycle.isCurrent(token))return false;
      onFailure?.(error);
      const blocked=error?.name==='NotAllowedError';
      playbackProblem(blocked?'Tap Play to allow audio for this session.':'Playback unavailable · tap Play to retry.');
      agent('idle',blocked?'Tap Play to allow audio for this session.':'The browser could not start “'+t.title+'.” Check its audio in Media Health.');return false;
    });
  }
  function toggle(){
    if(!st.track)return;
    if(!audio.paused){recordListen('pause');invalidatePlayback();audio.pause();st.playing=false;agent('idle','Paused “'+st.track.title+'.”');updateFooterPlayer();return}
    void play(st.track,savedPlaybackPosition(),st.playSource,!!st.playlistPlayback.playlistId);
  }
  function seekPlayback(fraction){
    if(!Number.isFinite(fraction)||fraction<0||fraction>1||!Number.isFinite(audio.duration)||audio.duration<=0)return;
    if(songCaptureSkill?.status().active)songCaptureSkill.discard();
    if(songListeningSkill?.status().active)void songListeningSkill.stop('seek');
    audio.currentTime=fraction*audio.duration;
  }
  async function stepTrack(delta){
    // Await the real media outcome before the Agent records a successful skip.
    if(agentSessionActive()&&delta>0){
      invalidatePlayback();audio.pause();const token=playbackLifecycle.token();
      const session=await advanceAgentSession('skip',token);
      if(!playbackLifecycle.isCurrent(token))return false;
      if(!session||session.status!=='active'){renderAgentSessionPanel();return false}
      const ids=sessionTrackIds();st.playlistPlayback.index=Number(session.current_index||0);
      const next=trackById(ids[st.playlistPlayback.index]);
      const played=next?await play(next,0,'agent_session',true):false;
      renderAgentSessionPanel();return played;
    }
    if(st.playlistPlayback.playlistId&&st.playlistPlayback.trackIds.length){
      const ids=st.playlistPlayback.trackIds;let i=st.playlistPlayback.index;
      if(i<0)i=Math.max(0,ids.indexOf(st.track?.id));
      i=(i+delta+ids.length)%ids.length;st.playlistPlayback.index=i;updateQueueUi();
      const next=trackById(ids[i]);return next?play(next,0,isTemporaryPlaylist()?'temporary_playlist':'playlist',true):false;
    }
    if(delta>0&&st.auth.authenticated&&st.queue.items.length){
      invalidatePlayback();audio.pause();return playNextFromQueue(playbackLifecycle.token());
    }
    if(delta<0&&st.queue.history.length){
      const id=st.queue.history.shift();localStorage.setItem('stonefellow.queue.history.v1',JSON.stringify(st.queue.history));
      const previous=trackById(id);if(previous)return play(previous,0,'queue_history');
    }
    if(!st.track||!catalog.length)return false;
    let i=catalog.findIndex(t=>t.id===st.track.id);if(i<0)i=0;
    i=(i+delta+catalog.length)%catalog.length;
    return play(catalog[i]);
  }
  function bindTrackButtons(r=document){$$('[data-play-track]',r).forEach(b=>b.onclick=e=>{e.stopPropagation();const t=trackById(b.dataset.playTrack);if(t)play(t)})}
  function bindQueueTrackButtons(r=document){Array.from(r.querySelectorAll('[data-queue-track]')).forEach(b=>b.onclick=e=>{e.stopPropagation();const t=trackById(b.dataset.queueTrack);if(t)addTrackToQueue(t,b.dataset.queuePlacement==='next'?'next':'end')})}
  async function updatePrintLicenseBalance(trackIds,target){
    const node=$(target,canvas);if(!node)return;
    if(!st.auth.authenticated){node.textContent='Sign in to view owned vinyl print licenses.';return;}
    try{
      const response=await api('vinyl-print-balance.php');
      if(!node.isConnected)return;
      const balances=response.per_song||{},ids=trackIds.filter(Boolean);
      if(!ids.length){node.textContent='No album songs selected.';return;}
      if(ids.length===1){
        const b=balances[ids[0]]||{granted:0,reserved_or_used:0,available:0};
        node.textContent='Vinyl licenses: '+Number(b.available||0)+' available · '+Number(b.reserved_or_used||0)+' reserved/used · '+Number(b.granted||0)+' purchased.';
      }else{
        const copies=Math.min(...ids.map(id=>Number(balances[id]?.available||0)));
        node.textContent='Whole-album vinyl licenses: '+copies+' full pressing'+(copies===1?'':'s')+' available (one license required for each song per pressing).';
      }
    }catch(_){node.textContent='Vinyl license balance is unavailable. Purchasing packages does not automatically confer audio ownership.';}
  }
  function addVinylLicensePack(type,id,copies){
    const amount=Number(copies);
    const source=type==='vinyl_license_pack'?trackById(id):releases.find(r=>r.id===id);
    const product=(source?.vinyl_license_packages||[]).find(p=>Number(p.licenses)===amount);
    if(!product||!(Number(product.price_cents)>=50)){say('That print-license package is not available.');return;}
    if(st.cart.some(x=>x.type===type&&(x.track_id===id||x.release_id===id)&&Number(x.licenses)===amount)){
      say('That package is already in your cart.');return;
    }
    st.cart.push({type,...(type==='vinyl_license_pack'?{track_id:id}:{release_id:id}),licenses:amount});
    persistCart();showCartAfterAdd('Added '+amount+' vinyl print license'+(amount===1?'':'s')+' to your cart. Streaming and downloads remain separate.');
  }
  function addAlbumVinylToCart(release){
    if(!release?.vinyl_enabled||!(Number(release.vinyl_price_cents)>=50)){say('This album vinyl edition is not on sale yet.');return;}
    if(st.cart.some(x=>x.type==='album_vinyl'&&x.release_id===release.id)){say('This album pressing is already in your cart.');return;}
    st.cart.push({type:'album_vinyl',release_id:release.id});persistCart();
    showCartAfterAdd('Added a print-on-demand locked album. Every song needs one unused vinyl print license.');
  }
  function addDigitalAlbumToCart(r){
    if(st.cart.some(x=>x.type==='release'&&x.release_id===r.id)){say('The digital album is already in your cart.');return;}
    st.cart.push({type:'release',release_id:r.id});persistCart();
    showCartAfterAdd('Added digital album. Purchase includes one vinyl print license per song.');
  }
  function addTrackToCart(t){if(!t)return;if(t.digital_purchase_enabled===false){say('This song is available for listening only. Paid downloads are not enabled.');return;}if(st.cart.some(x=>x.type==='track'&&x.track_id===t.id)){say('“'+t.title+'” is already in your cart.');return}st.cart.push({type:'track',track_id:t.id});persistCart();showCartAfterAdd('Added the digital track “'+t.title+'” to your cart.')}
  function renderPlayer(t){renderStory(t)}

  function siteSearchTypeLabel(type){return ({track:'Song',release:'Release',show:'Show',story:'Story',video:'Video',photo:'Photos',product:'Store',campaign:'Offer'})[type]||'Result'}
  function siteSearchOpenParams(r){if(['track','release','show'].includes(r.type))return {view:r.type,id:r.id};if(r.type==='story')return {view:'story',slug:r.slug};if(r.type==='video')return {view:'video',slug:r.slug};if(r.type==='photo')return {view:'photo',slug:r.slug};if(r.type==='product')return {view:'store',slug:r.slug};if(r.type==='campaign')return {view:'campaign',slug:r.slug};return {view:'search'}}
  function siteSearchResultHtml(r){return '<article class="site-search-result" data-site-result-type="'+esc(r.type)+'"><button type="button" class="site-search-result-main" data-site-search-open="'+esc(r.type)+'" data-site-search-id="'+esc(r.id)+'" data-site-search-slug="'+esc(r.slug||'')+'">'+(r.image?'<span class="site-search-art"><img src="'+esc(r.image)+'" alt=""></span>':'<span class="site-search-art placeholder">SF</span>')+'<span class="site-search-copy"><small>'+esc(siteSearchTypeLabel(r.type))+(r.subtitle?' · '+esc(r.subtitle):'')+'</small><strong>'+esc(r.title)+'</strong>'+(r.snippet?'<span>'+esc(r.snippet)+'</span>':'')+'<em>'+esc(r.reason||'Public Stonefellow content')+'</em></span><span class="site-search-arrow">→</span></button></article>'}
  function siteSearchParams(raw={}){const allowed=['all','track','release','show','story','video','photo','product','campaign'],type=allowed.includes(String(raw.type||'all'))?String(raw.type||'all'):'all';return {q:String(raw.q||'').slice(0,300),type,limit:40}}
  async function siteSearchApi(p,log=false){if(!log){const qs=new URLSearchParams();if(p.q)qs.set('q',p.q);if(p.type&&p.type!=='all')qs.set('type',p.type);qs.set('limit',String(p.limit||40));return api('site-search.php?'+qs.toString())}const payload={action:'search',session_key:searchSessionKey(),query:p};if(st.auth.authenticated)return secureJsonPost('site-search.php',payload);const r=await fetch((cfg.apiBase||'api')+'/site-search.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}),j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||j.error||'Site search failed.');return j}
  async function siteSearchRecordClick(result,resultCount,query){const payload={action:'click',session_key:searchSessionKey(),query,result_type:result.type,result_id:result.id,result_count:Number(resultCount||0)};try{if(st.auth.authenticated){await secureJsonPost('site-search.php',payload);return true}const r=await fetch((cfg.apiBase||'api')+'/site-search.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});return r.ok}catch(_){return false}}
  function renderSiteSearchRecent(rows=[]){const host=$('#siteSearchRecent',canvas);if(!host)return;host.innerHTML=rows.length?'<div class="site-search-recent-head"><span>Recent searches</span>'+(st.auth.authenticated?'<button type="button" class="text-control" id="siteSearchClearHistory">Clear</button>':'')+'</div><div class="site-search-recent-list">'+rows.slice(0,8).map(x=>'<button type="button" data-site-recent="'+esc(x.query_text)+'">'+esc(x.query_text)+'</button>').join('')+'</div>':'';$$('[data-site-recent]',host).forEach(b=>b.onclick=()=>{const q=$('#siteSearchInput',canvas);if(q){q.value=b.dataset.siteRecent;runSiteSearch(true)}});const clear=$('#siteSearchClearHistory',host);if(clear)clear.onclick=async()=>{try{await secureJsonPost('site-search.php',{action:'clear_history',session_key:searchSessionKey()});renderSiteSearchRecent([])}catch(e){say(e.message)}}}
  function renderSiteSearchResults(search){const host=$('#siteSearchResults',canvas),summary=$('#siteSearchSummary',canvas),suggestions=$('#siteSearchSuggestions',canvas);if(!host)return;const rows=search.results||[],p=search.query||{};host.dataset.resultCount=String(search.result_count||0);summary.textContent=p.q?(search.result_count?Number(search.result_count).toLocaleString()+' public result'+(Number(search.result_count)===1?'':'s'):'No public matches'):'Search across Stonefellow';host.innerHTML=rows.length?rows.map(siteSearchResultHtml).join(''):(p.q?'<div class="site-search-empty"><strong>No public Stonefellow match.</strong><span>Try a title, place, story phrase, video, photo, product, or release.</span></div>':'<div class="site-search-empty"><strong>Everything public, one search.</strong><span>Find songs, releases, shows, stories, videos, photos, merch and active offers.</span></div>');suggestions.innerHTML=(search.suggestions||[]).length?'<span>Try:</span>'+(search.suggestions||[]).map(x=>'<button type="button" data-site-suggestion="'+esc(x)+'">'+esc(x)+'</button>').join(''):'';$$('[data-site-search-open]',host).forEach(b=>b.onclick=async()=>{const result=rows.find(x=>x.type===b.dataset.siteSearchOpen&&String(x.id)===String(b.dataset.siteSearchId));if(!result)return;const current=await siteSearchRecordClick(result,search.result_count,p);if(!current){say('That search result is no longer public or available.');runSiteSearch(false);return}const dest=siteSearchOpenParams(result);navigate(dest.view,{id:dest.id||'',slug:dest.slug||''})});$$('[data-site-suggestion]',suggestions).forEach(b=>b.onclick=()=>{const q=$('#siteSearchInput',canvas);if(q){q.value=b.dataset.siteSuggestion;runSiteSearch(true)}})}
  async function runSiteSearch(log=true){const q=$('#siteSearchInput',canvas),type=$('#siteSearchType',canvas),p=siteSearchParams({q:q?.value||'',type:type?.value||'all'}),host=$('#siteSearchResults',canvas);if(host)host.classList.add('loading');const u=publicRouteUrl('search',p);history.replaceState({view:'search',params:p},'',u);syncSeo('search',p);try{const j=await siteSearchApi(p,log),search=j.search||{};if(j.csrf)st.auth.csrf=j.csrf;renderSiteSearchResults(search);renderSiteSearchRecent(search.recent_searches||[])}catch(e){if(host)host.innerHTML='<div class="site-search-empty"><strong>Search unavailable.</strong><span>'+esc(e.message)+'</span></div>'}finally{host?.classList.remove('loading')}}
  async function renderSiteSearch(raw={}){starters.hidden=true;const p=siteSearchParams(raw);let j;try{j=await siteSearchApi(p,false)}catch(e){canvas.innerHTML='<article class="module site-search-module"><div class="module-kicker">STONEFELLOW SEARCH</div><h1>Search</h1><div class="site-search-empty"><strong>Search unavailable.</strong><span>'+esc(e.message)+'</span></div></article>';return}const search=j.search||{};if(j.csrf)st.auth.csrf=j.csrf;canvas.innerHTML='<article class="module site-search-module"><div class="module-kicker">STONEFELLOW · ONE ARTIST</div><div class="site-search-head"><div><h1>Search Stonefellow</h1><p>Find music, releases, shows, stories, videos, photos, merch and current offers—all from the public Stonefellow catalog.</p></div></div><form id="siteSearchForm" class="site-search-form"><input id="siteSearchInput" type="search" maxlength="300" autocomplete="off" placeholder="Search Stonefellow…" value="'+esc(p.q)+'"><button class="buy-primary" type="submit">Search</button></form><div class="site-search-types"><select id="siteSearchType" aria-label="Search category">'+[['all','Everything'],['track','Songs'],['release','Releases'],['show','Shows'],['story','Stories'],['video','Videos'],['photo','Photos'],['product','Store'],['campaign','Offers']].map(x=>'<option value="'+x[0]+'" '+(p.type===x[0]?'selected':'')+'>'+x[1]+'</option>').join('')+'</select><span id="siteSearchSummary"></span></div><div id="siteSearchRecent"></div><div id="siteSearchSuggestions" class="catalog-suggestions"></div><div id="siteSearchResults" class="site-search-results"></div></article>';const form=$('#siteSearchForm',canvas);if(form)form.onsubmit=e=>{e.preventDefault();runSiteSearch(true)};const type=$('#siteSearchType',canvas);if(type)type.onchange=()=>runSiteSearch(true);renderSiteSearchResults(search);renderSiteSearchRecent(search.recent_searches||[]);setTimeout(()=>$('#siteSearchInput',canvas)?.focus(),0)}
  function searchSessionKey(){let key=localStorage.getItem('stonefellow.search.session')||'';if(!key){key='ss_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,12);localStorage.setItem('stonefellow.search.session',key)}return key}
  function catalogSearchParams(raw={}){return {q:String(raw.q||''),type:['track','release'].includes(raw.type)?raw.type:'all',mood:String(raw.mood||''),theme:String(raw.theme||''),release:String(raw.release||''),year:String(raw.year||''),energy:String(raw.energy||''),sort:['popular','newest','title'].includes(raw.sort)?raw.sort:'relevance',limit:40}}
  function catalogSearchParamsFromUi(){return catalogSearchParams({q:$('#catalogSearch',canvas)?.value||'',type:$('#catalogType',canvas)?.value||'all',mood:$('#catalogMood',canvas)?.value||'',theme:$('#catalogTheme',canvas)?.value||'',release:$('#catalogRelease',canvas)?.value||'',year:$('#catalogYear',canvas)?.value||'',energy:$('#catalogEnergy',canvas)?.value||'',sort:$('#catalogSort',canvas)?.value||'relevance'})}
  function catalogReplaceUrl(p){const u=new URL(location.href);u.search='';u.searchParams.set('view','music');for(const k of ['q','type','mood','theme','release','year','energy','sort']){const v=p[k];if(v&&v!=='all'&&v!=='relevance')u.searchParams.set(k,v)}history.replaceState({},'',u)}
  async function catalogSearchApi(p,log=false){if(!log){const qs=new URLSearchParams();Object.entries(p).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined)qs.set(k,String(v))});return api('search.php?'+qs.toString())}const payload={action:'search',session_key:searchSessionKey(),query:p};if(st.auth.authenticated)return secureJsonPost('search.php',payload);const r=await fetch((cfg.apiBase||'api')+'/search.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.message||j.error||'Search failed.');return j}
  async function catalogRecordClick(type,id,resultCount){const payload={action:'click',session_key:searchSessionKey(),query:catalogSearchParamsFromUi(),result_type:type,result_id:id,result_count:Number(resultCount||0)};try{if(st.auth.authenticated)await secureJsonPost('search.php',payload);else await fetch((cfg.apiBase||'api')+'/search.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})}catch(_){}}
  function catalogOptions(rows,current,allLabel){return `<option value="">${esc(allLabel)}</option>${(rows||[]).map(x=>`<option value="${esc(x.value)}" ${String(current)===String(x.value)?'selected':''}>${esc(x.value)} · ${Number(x.count||0)}</option>`).join('')}`}
  function catalogResultHtml(r){if(r.type==='release'){return `<article class="search-result-card release-result"><button class="search-result-art" type="button" data-search-open="release" data-search-id="${esc(r.id)}">${r.artwork?`<img src="${esc(r.artwork)}" alt="">`:'SF'}</button><div class="search-result-main"><div class="search-result-eyebrow">${esc((r.release_type||'release').toUpperCase())} · ${esc(r.reason||'Release')}</div><button class="search-result-title" type="button" data-search-open="release" data-search-id="${esc(r.id)}">${esc(r.title)}</button><p>${esc(r.description||'Stonefellow release')}</p><div class="search-result-tags"><span>${Number(r.track_count||0)} tracks</span>${r.release_date?`<span>${esc(r.release_date)}</span>`:''}${(r.mood||[]).slice(0,2).map(x=>`<span>${esc(x)}</span>`).join('')}</div></div><div class="search-result-actions">${favoriteButton('release',r.id,'Favorite release')}<button class="text-control compact" type="button" data-search-open="release" data-search-id="${esc(r.id)}">Open</button></div></article>`}
    const t=trackById(r.id),art=r.artwork||trackArtwork(t);return `<article class="search-result-card track-result"><button class="search-result-art" type="button" data-search-open="track" data-search-id="${esc(r.id)}">${art?`<img src="${esc(art)}" alt="">`:'SF'}</button><div class="search-result-main"><div class="search-result-eyebrow">TRACK · ${esc(r.reason||'Catalog match')}${r.personalized?' · FOR YOU':''}</div><button class="search-result-title" type="button" data-search-open="track" data-search-id="${esc(r.id)}">${esc(r.title)}</button><p>${esc(r.release||'Stonefellow')}${r.year?' · '+esc(r.year):''} · ${time(r.duration||0)}</p><div class="search-result-tags">${(r.mood||[]).slice(0,3).map(x=>`<span>${esc(x)}</span>`).join('')}${(r.themes||[]).slice(0,2).map(x=>`<span>${esc(x)}</span>`).join('')}</div></div><div class="search-result-actions"><button class="buy-primary compact" type="button" data-search-play="${esc(r.id)}">Play</button>${favoriteButton('track',r.id,'Favorite')}<button class="tiny-control" type="button" data-queue-track="${esc(r.id)}" data-queue-placement="end">+ Queue</button></div></article>`}
  function renderCatalogResults(search){const host=$('#catalogDiscoveryResults',canvas),summary=$('#catalogResultSummary',canvas),suggestions=$('#catalogSuggestions',canvas);if(!host)return;const rows=search.results||[],q=search.query||{};host.dataset.resultCount=String(search.result_count||0);summary.textContent=search.result_count?`${Number(search.result_count).toLocaleString()} result${Number(search.result_count)===1?'':'s'} · ${Number(search.track_count||0)} tracks · ${Number(search.release_count||0)} releases`:(q.q?'No matching tracks or releases':'Explore the Stonefellow catalog');host.innerHTML=rows.length?rows.map(catalogResultHtml).join(''):`<div class="search-empty"><strong>No exact catalog match.</strong><span>Try a broader title, release, mood, theme, credit, lyric, or story phrase.</span></div>`;suggestions.innerHTML=(search.suggestions||[]).length?`<span>Try:</span>${search.suggestions.map(x=>`<button type="button" data-search-suggestion="${esc(x)}">${esc(x)}</button>`).join('')}`:'';bindTrackButtons(host);bindFavoriteButtons(host);bindQueueTrackButtons(host);$$('[data-search-play]',host).forEach(b=>b.onclick=async()=>{const id=b.dataset.searchPlay,t=trackById(id);await catalogRecordClick('track',id,search.result_count);if(t)play(t,0,'catalog_search')});$$('[data-search-open]',host).forEach(b=>b.onclick=async()=>{const type=b.dataset.searchOpen,id=b.dataset.searchId;await catalogRecordClick(type,id,search.result_count);if(type==='track')navigate('track',{id});else navigate('release',{id})});$$('[data-search-suggestion]',suggestions).forEach(b=>b.onclick=()=>{$('#catalogSearch',canvas).value=b.dataset.searchSuggestion;runCatalogSearch(true)})}
  async function runCatalogSearch(log=true){const p=catalogSearchParamsFromUi(),host=$('#catalogDiscoveryResults',canvas);if(host)host.classList.add('loading');catalogReplaceUrl(p);try{const j=await catalogSearchApi(p,log),search=j.search||{};if(j.csrf)st.auth.csrf=j.csrf;renderCatalogResults(search);renderRecentSearches(search.recent_searches||[])}catch(e){if(host)host.innerHTML=`<div class="search-empty"><strong>Search unavailable.</strong><span>${esc(e.message)}</span></div>`}finally{host?.classList.remove('loading')}}
  function renderRecentSearches(rows){const host=$('#catalogRecentSearches',canvas);if(!host)return;host.innerHTML=rows.length?`<div class="discovery-label">Recent searches</div><div class="discovery-chips">${rows.map(x=>`<button type="button" data-recent-search="${esc(x.query_text)}">${esc(x.query_text)}</button>`).join('')}${st.auth.authenticated?'<button type="button" class="muted-chip" id="clearSearchHistory">Clear</button>':''}</div>`:'';$$('[data-recent-search]',host).forEach(b=>b.onclick=()=>{$('#catalogSearch',canvas).value=b.dataset.recentSearch;runCatalogSearch(true)});const clear=$('#clearSearchHistory',host);if(clear)clear.onclick=clearCatalogSearchHistory}
  async function clearCatalogSearchHistory(){if(!st.auth.authenticated)return;try{await secureJsonPost('search.php',{action:'clear_history',session_key:searchSessionKey()});renderRecentSearches([])}catch(e){say(e.message)}}
  function bindCatalogDiscovery(search){const form=$('#catalogSearchForm',canvas);if(form)form.onsubmit=e=>{e.preventDefault();runCatalogSearch(true)};for(const id of ['catalogType','catalogMood','catalogTheme','catalogRelease','catalogYear','catalogEnergy','catalogSort']){const el=$('#'+id,canvas);if(el)el.onchange=()=>runCatalogSearch(true)}const reset=$('#catalogReset',canvas);if(reset)reset.onclick=()=>navigate('music',{});$$('[data-discovery-mood]',canvas).forEach(b=>b.onclick=()=>{$('#catalogMood',canvas).value=b.dataset.discoveryMood;runCatalogSearch(true)});$$('[data-discovery-theme]',canvas).forEach(b=>b.onclick=()=>{$('#catalogTheme',canvas).value=b.dataset.discoveryTheme;runCatalogSearch(true)});renderCatalogResults(search);renderRecentSearches(search.recent_searches||[])}
  async function renderCatalog(raw={}){starters.hidden=true;const p=catalogSearchParams(raw);let j;try{j=await catalogSearchApi(p,false)}catch(e){canvas.innerHTML=`<article class="module"><div class="module-kicker">STONEFELLOW CATALOG</div><h2>Music</h2><div class="search-empty"><strong>Search unavailable.</strong><span>${esc(e.message)}</span></div></article>`;return}const search=j.search||{},facets=search.facets||{},moods=facets.moods||[],themes=facets.themes||[],releaseFacets=facets.releases||[],years=facets.years||[],energies=facets.energies||[];if(j.csrf)st.auth.csrf=j.csrf;canvas.innerHTML=`<article class="module catalog-discovery-module"><div class="module-kicker">STONEFELLOW CATALOG</div><div class="catalog-discovery-head"><div><h2>Search & Discover</h2><p class="player-note">Search songs and releases by title, mood, theme, lyrics, credits, recording notes, story, or release.</p></div><div class="catalog-counts"><span>${Number(facets.track_count||0)} tracks</span><span>${Number(facets.release_count||0)} releases</span></div></div><div id="fanDiscoveryMount"></div><form id="catalogSearchForm" class="catalog-search-form"><div class="catalog-search-main"><input id="catalogSearch" type="search" maxlength="300" placeholder="Try “desert”, “memory”, a lyric, credit, or song title…" value="${esc(p.q)}" autocomplete="off"><button class="buy-primary" type="submit">Search</button></div><div class="catalog-filter-grid"><select id="catalogType"><option value="all" ${p.type==='all'?'selected':''}>Tracks + releases</option><option value="track" ${p.type==='track'?'selected':''}>Tracks only</option><option value="release" ${p.type==='release'?'selected':''}>Releases only</option></select><select id="catalogMood">${catalogOptions(moods,p.mood,'All moods')}</select><select id="catalogTheme">${catalogOptions(themes,p.theme,'All themes')}</select><select id="catalogRelease">${catalogOptions(releaseFacets,p.release,'All releases')}</select><select id="catalogYear">${catalogOptions(years,p.year,'All years')}</select><select id="catalogEnergy">${catalogOptions(energies,p.energy,'All energy levels')}</select><select id="catalogSort"><option value="relevance" ${p.sort==='relevance'?'selected':''}>Best match</option><option value="popular" ${p.sort==='popular'?'selected':''}>Most played</option><option value="newest" ${p.sort==='newest'?'selected':''}>Newest</option><option value="title" ${p.sort==='title'?'selected':''}>Title A–Z</option></select><button class="text-control" id="catalogReset" type="button">Reset</button></div></form><section class="discovery-rail"><div><div class="discovery-label">Explore moods</div><div class="discovery-chips">${moods.slice(0,10).map(x=>`<button type="button" data-discovery-mood="${esc(x.value)}">${esc(x.value)} <span>${x.count}</span></button>`).join('')}</div></div><div><div class="discovery-label">Explore themes</div><div class="discovery-chips">${themes.slice(0,10).map(x=>`<button type="button" data-discovery-theme="${esc(x.value)}">${esc(x.value)} <span>${x.count}</span></button>`).join('')}</div></div><div id="catalogRecentSearches"></div></section><div class="catalog-result-head"><strong id="catalogResultSummary"></strong><div id="catalogSuggestions" class="catalog-suggestions"></div></div><div id="catalogDiscoveryResults" class="catalog-search-results"></div></article>`;bindCatalogDiscovery(search);window.STONEFELLOW_FAN_DISCOVERY?.mount($('#fanDiscoveryMount'),{
   apiBase:cfg.apiBase||'api',endpoint:'fan-purchase-journey.php',
   authenticated:st.auth.authenticated,
   play:id=>{const t=trackById(id);if(t)play(t,0,'guided_discovery')},
   open:id=>navigate('track',{id}),
   save:async(id,desired)=>{
     if(!st.auth.authenticated)throw Error('Sign in to save songs.');
     const j=await secureJsonPost('personalization.php',{
       action:'set_favorite',item_type:'track',item_id:id,favorite:!!desired
     });
     st.personalization={loaded:true,...(j.personalization||{})};
     st.home.loaded=false;return j;
   },
   cart:id=>{
     const track=trackById(id);
     if(!track)throw Error('Song no longer available.');
     addTrackToCart(track);
     if(!window.SFPublicAgentCanvas?.active?.())navigate('cart');
   },
   library:()=>navigate('library'),
   vinyl:()=>navigate('builder'),
   ask:text=>{if(input&&form){input.value=text;form.requestSubmit()}},
   queue:async id=>{if(!trackById(id))throw Error('Song no longer available');const r=await secureJsonPost('queue.php',{action:'add',track_id:id,placement:'end'});applyQueue(r.queue);return r},
   feedback:async(id,sentiment,path)=>{
     const result=await secureJsonPost('fan-discovery-feedback.php',{track_id:id,sentiment,path});
     st.personalization.loaded=false;st.home.loaded=false;return result;
   }
  })}
  function releaseForTrack(t){if(!t)return null;return releases.find(r=>String(r.title||'')===String(t.release||'')||String(r.id||'')===String(t.release_id||''))||null}
  function trackArtwork(t){const r=releaseForTrack(t);return t?.artwork||r?.artwork?.cover||r?.artwork||''}
  function relatedTracksFor(t,limit=4){if(!t)return[];const keys=new Set([...(t.mood||[]),...(t.themes||[])]);return catalog.filter(x=>x.id!==t.id).map((x,index)=>{const overlap=[...(x.mood||[]),...(x.themes||[])].filter(v=>keys.has(v)).length;const sameRelease=x.release&&x.release===t.release?3:0;const energy=Math.max(0,3-Math.abs(Number(x.energy||3)-Number(t.energy||3)));return{x,index,score:overlap*4+sameRelease+energy}}).sort((a,b)=>b.score-a.score||a.index-b.index).slice(0,Math.max(1,limit)).map(x=>x.x)}
  function creditRows(t){const m=t?.metadata||{},rows=[];if(m.words_by)rows.push(['Words',m.words_by]);if(m.music_by)rows.push(['Music',m.music_by]);if(m.producer)rows.push(['Producer',m.producer]);if(m.co_producer)rows.push(['Co-producer',m.co_producer]);if(m.recording_engineer||m.engineer)rows.push(['Recording engineer',m.recording_engineer||m.engineer]);if(m.mixing_engineer||m.mixed_by)rows.push(['Mixed by',m.mixing_engineer||m.mixed_by]);if(m.mastering_engineer||m.mastered_by)rows.push(['Mastered by',m.mastering_engineer||m.mastered_by]);if(m.studio)rows.push(['Studio',m.studio]);if(m.recording_location)rows.push(['Location',m.recording_location]);return rows}
  function trackArchive(t){return t&&t.archive&&typeof t.archive==='object'?t.archive:{}}
  function versionTracksFor(t){if(!t)return[];const a=trackArchive(t),ids=new Set((a.alternate_track_ids||[]).map(String));if(a.version_of)ids.add(String(a.version_of));const work=String(a.canonical_work_id||'').trim();catalog.forEach(x=>{if(x.id===t.id)return;const xa=trackArchive(x);if(work&&String(xa.canonical_work_id||'')===work)ids.add(String(x.id));if(String(xa.version_of||'')===String(t.id)||String(a.version_of||'')===String(x.id))ids.add(String(x.id))});return [...ids].map(trackById).filter(Boolean)}
  function archiveMediaHtml(media){const rows=Array.isArray(media)?media:[];if(!rows.length)return'';return '<div class="archive-media-grid">'+rows.map(x=>'<a class="archive-media-card" href="'+esc(x.url||'#')+'" target="_blank" rel="noopener"><small>'+esc(String(x.type||'archive').toUpperCase())+(x.date?' · '+esc(x.date):'')+'</small><strong>'+esc(x.title||'Archive item')+'</strong>'+(x.caption?'<span>'+esc(x.caption)+'</span>':'')+'</a>').join('')+'</div>'}
  function archiveTrackContextHtml(t){const a=trackArchive(t),versions=versionTracksFor(t),personnel=Array.isArray(a.personnel)?a.personnel:[],has=Object.values(a).some(v=>Array.isArray(v)?v.length:String(v||'').trim()!=='')||versions.length;if(!has)return'';const facts=[['Era',a.era],['Version',a.version_label||a.version_type],['Recorded',a.recorded_date],['Session',a.session]].filter(x=>x[1]);return '<section class="archive-detail-section"><div class="module-kicker">MUSIC ARCHIVE</div><h3>Recording context & versions</h3>'+(facts.length?'<div class="archive-facts">'+facts.map(x=>'<div><span>'+esc(x[0])+'</span><strong>'+esc(x[1])+'</strong></div>').join('')+'</div>':'')+(a.source_notes?'<p class="player-note archive-source-notes">'+esc(a.source_notes).replace(/\n/g,'<br>')+'</p>':'')+(personnel.length?'<div class="archive-personnel"><h4>Personnel</h4>'+personnel.map(p=>'<div><strong>'+esc(p.name||'')+'</strong><span>'+esc([p.instrument,p.role].filter(Boolean).join(' · '))+'</span></div>').join('')+'</div>':'')+(versions.length?'<div class="archive-versions"><h4>Other versions</h4>'+versions.map(x=>{const xa=trackArchive(x);return '<button type="button" data-archive-track="'+esc(x.id)+'"><strong>'+esc(x.title)+'</strong><span>'+esc(xa.version_label||xa.version_type||x.release||'Catalog version')+'</span></button>'}).join('')+'</div>':'')+archiveMediaHtml(a.media)+'</section>'}
  function archiveReleaseContextHtml(r){const a=r&&r.archive&&typeof r.archive==='object'?r.archive:{},media=Array.isArray(a.media)?a.media:[],facts=[['Era',a.era],['Edition',a.edition],['Original release',a.original_release_date],['Reissue of',a.reissue_of]].filter(x=>x[1]);if(!facts.length&&!media.length)return'';return '<section class="archive-detail-section release-archive-context"><div class="module-kicker">ARCHIVE EDITION</div><h3>Release history</h3>'+(facts.length?'<div class="archive-facts">'+facts.map(x=>'<div><span>'+esc(x[0])+'</span><strong>'+esc(x[1])+'</strong></div>').join('')+'</div>':'')+archiveMediaHtml(media)+'</section>'}
  function showById(id){return shows.find(s=>String(s.id)===String(id))}
  function ticketAccessCopy(a={}){const m={available:'Available',member_presale:'Member presale',membership_required:'Members only',higher_tier_required:'Higher membership tier required',vip_required:'VIP benefit required',presale_required:'Priority presale required',sign_in_required:'Sign in to reserve',sold_out:'Sold out',not_open:'Not open yet',closed:'Closed'};return m[a.reason]||String(a.reason||'Unavailable').replaceAll('_',' ')}
  function ticketOfferCard(o){const a=o.access||{},available=!!a.allowed,internal=o.fulfillment_type==='reservation',remaining=a.remaining===null?'No capacity limit':Number(a.remaining)+' remaining',price=Number(o.price_cents||0)>0?money(o.price_cents):internal?'Free reservation':'Ticket provider';let action='';if(available&&internal){action='<div class="ticket-reserve-row"><select data-ticket-qty="'+o.id+'">'+Array.from({length:Math.max(1,Math.min(Number(o.max_per_fan||1),Number((a.remaining??o.max_per_fan)||1)))},(_,i)=>'<option value="'+(i+1)+'">'+(i+1)+'</option>').join('')+'</select><button class="buy-primary" type="button" data-ticket-reserve="'+o.id+'">Reserve</button></div>'}else if(available&&!internal&&o.external_url){action='<a class="buy-primary button-link" href="'+esc(o.external_url)+'" target="_blank" rel="noopener">Open ticket provider</a>'}else if(a.reason==='sign_in_required'){action='<button class="text-control" type="button" data-view="login">Sign in to reserve</button>'}else if(['membership_required','higher_tier_required','vip_required','presale_required'].includes(a.reason)){action='<button class="text-control" type="button" data-view="membership">View membership</button>'}return '<article class="ticket-offer-card '+(available?'available':'locked')+'"><div class="ticket-offer-head"><div><div class="module-kicker">'+esc(String(o.offer_type||'rsvp').replaceAll('_',' '))+(a.presale?' · EARLY ACCESS':'')+'</div><h3>'+esc(o.title)+'</h3></div><span class="ticket-access-badge">'+esc(ticketAccessCopy(a))+'</span></div><p>'+esc(o.description||'')+'</p><div class="ticket-offer-meta"><span>'+esc(showDateLabel({date:o.show_date}))+'</span><span>'+esc(o.venue||'')+'</span><span>'+esc(o.location||'')+'</span><span>'+esc(price)+'</span><span>'+esc(remaining)+'</span></div>'+action+'</article>'}
  function ticketReservationCard(r){const s=r.show||{};return '<article class="ticket-reservation-card"><div><div class="module-kicker">'+esc(String(r.offer_type||'ticket').replaceAll('_',' '))+'</div><h3>'+esc(r.offer_title||'Stonefellow reservation')+'</h3><p>'+esc([showDateLabel(s),s.venue,showLocation(s)].filter(Boolean).join(' · '))+'</p></div><div class="ticket-confirmation"><small>CONFIRMATION</small><strong>'+esc(r.confirmation_code)+'</strong><span>'+Number(r.quantity||1)+' guest'+(Number(r.quantity||1)===1?'':'s')+' · '+esc(String(r.status||'reserved').replaceAll('_',' '))+'</span></div>'+(r.status==='reserved'?'<button class="text-control" type="button" data-ticket-cancel="'+r.id+'">Cancel reservation</button>':'')+'</article>'}
  async function renderTickets(params={}){
    starters.hidden=true;await loadAuth();let data;try{data=await api('ticketing.php'+(params.show_id?'?show_id='+encodeURIComponent(params.show_id):''))}catch(e){canvas.innerHTML='<article class="module"><div class="module-kicker">TICKETS + VIP</div><h2>Ticketing unavailable</h2><p class="player-note">'+esc(e.message)+'</p></article>';return}
    const offers=data.offers||[],reservations=data.reservations||[];canvas.innerHTML='<article class="module tickets-module"><div class="module-kicker">STONEFELLOW LIVE ACCESS</div><div class="tickets-head"><div><h2>Tickets, RSVPs & VIP</h2><p class="player-note">Show reservations, member presales, VIP access and external ticket offers. Stonefellow internal reservations are free; paid tickets open the official external ticket provider.</p></div><button class="text-control" type="button" data-view="shows">Shows & Live</button></div>'+(reservations.length?'<section class="my-ticket-section"><div class="module-kicker">MY RESERVATIONS</div><div class="ticket-reservation-list">'+reservations.map(ticketReservationCard).join('')+'</div></section>':'')+'<section class="ticket-offers-section"><div class="module-kicker">AVAILABLE OFFERS</div><div class="ticket-offer-grid">'+(offers.length?offers.map(ticketOfferCard).join(''):'<div class="search-empty"><strong>No public ticket or VIP offers are listed right now.</strong></div>')+'</div></section></article>';bind(canvas);$$('[data-ticket-reserve]',canvas).forEach(b=>b.onclick=()=>reserveTicket(Number(b.dataset.ticketReserve)));$$('[data-ticket-cancel]',canvas).forEach(b=>b.onclick=()=>cancelTicket(Number(b.dataset.ticketCancel)))
  }
  async function reserveTicket(offerId){if(!st.auth.authenticated){navigate('login');return}const qty=Number($('[data-ticket-qty="'+offerId+'"]',canvas)?.value||1),request_id='ticket_'+offerId+'_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,10);try{const j=await secureJsonPost('ticketing.php',{action:'reserve',offer_id:offerId,quantity:qty,request_id,source:'public_ticketing'});say('Reservation confirmed: '+j.reservation.confirmation_code+'.',()=>renderTickets({}))}catch(e){say(e.message)}}
  async function cancelTicket(id){if(!confirm('Cancel this reservation and release the reserved capacity?'))return;try{await secureJsonPost('ticketing.php',{action:'cancel',reservation_id:id});say('Reservation cancelled.');renderTickets({})}catch(e){say(e.message)}}
  async function renderShowTicketOffers(showId){const host=$('#showTicketOffers',canvas);if(!host)return;try{const data=await api('ticketing.php?show_id='+encodeURIComponent(showId)),offers=data.offers||[];if(!offers.length){host.remove();return}host.innerHTML='<div class="module-kicker">TICKETS + VIP</div><h3>Access this show</h3><div class="ticket-offer-grid">'+offers.map(ticketOfferCard).join('')+'</div>';host.hidden=false;bind(host);$$('[data-ticket-reserve]',host).forEach(b=>b.onclick=()=>reserveTicket(Number(b.dataset.ticketReserve)))}catch(_){host.remove()}}
  function showLocation(s){return [s.city,s.region,s.country].filter(Boolean).join(', ')}
  function showDateLabel(s){const raw=String(s.date||'');if(!raw)return'Date TBD';const d=new Date(raw.length===10?raw+'T12:00:00':raw);return Number.isNaN(d.getTime())?raw:new Intl.DateTimeFormat('en-US',{year:'numeric',month:'short',day:'numeric'}).format(d)}
  function showCardHtml(s){const loc=showLocation(s);return '<button type="button" class="live-show-card" data-show-open="'+esc(s.id)+'">'+(s.poster?'<span class="live-show-poster"><img src="'+esc(s.poster)+'" alt=""></span>':'<span class="live-show-poster live-show-poster-empty">LIVE</span>')+'<span class="live-show-copy"><small>'+esc(showDateLabel(s))+(s.status&&s.status!=='scheduled'?' · '+esc(String(s.status).toUpperCase()):'')+'</small><strong>'+esc(s.title||s.venue||'Stonefellow live')+'</strong><span>'+esc(s.venue||'')+(loc?' · '+esc(loc):'')+'</span>'+(s.tour||s.era?'<em>'+esc(s.tour||s.era)+'</em>':'')+'</span><b>›</b></button>'}
  function renderShows(){starters.hidden=true;const today=new Date().toISOString().slice(0,10),visible=shows.filter(s=>s.public_visible!==false),upcoming=visible.filter(s=>String(s.date||'').slice(0,10)>=today&&!['completed','archived','cancelled'].includes(s.status)).sort((a,b)=>String(a.date).localeCompare(String(b.date))),past=visible.filter(s=>!upcoming.includes(s)).sort((a,b)=>String(b.date||'').localeCompare(String(a.date||'')));canvas.innerHTML='<article class="module live-module"><div class="module-kicker">STONEFELLOW LIVE</div><div class="live-head"><div><h2>Shows & Live Archive</h2><p class="player-note">Upcoming performances, past shows, setlists, live recordings and tour history.</p></div><div class="archive-counts"><span><strong>'+upcoming.length+'</strong> upcoming</span><span><strong>'+past.length+'</strong> archived</span></div></div><section class="live-section"><h3>Upcoming</h3>'+(upcoming.length?'<div class="live-show-list">'+upcoming.map(showCardHtml).join('')+'</div>':'<div class="search-empty"><strong>No upcoming public shows are listed.</strong></div>')+'</section><section class="live-section"><h3>Past shows</h3>'+(past.length?'<div class="live-show-list">'+past.map(showCardHtml).join('')+'</div>':'<div class="search-empty"><strong>No past shows have been archived yet.</strong></div>')+'</section></article>';$$('[data-show-open]',canvas).forEach(b=>b.onclick=()=>navigate('show',{id:b.dataset.showOpen}))}
  function renderShow(id){const s=showById(id);if(!s||s.public_visible===false){renderShows();return}starters.hidden=true;const set=(s.setlist_track_ids||[]).map(trackById).filter(Boolean),live=(s.live_recording_track_ids||[]).map(trackById).filter(Boolean),loc=showLocation(s),media=Array.isArray(s.media)?s.media:[];canvas.innerHTML='<article class="module show-detail-module"><div class="module-kicker">LIVE ARCHIVE · '+esc(String(s.status||'show').toUpperCase())+'</div><div class="show-detail-hero">'+(s.poster?'<div class="show-detail-poster"><img src="'+esc(s.poster)+'" alt=""></div>':'<div class="show-detail-poster show-detail-poster-empty">LIVE</div>')+'<div><h2>'+esc(s.title||s.venue||'Stonefellow live')+'</h2><p class="release-meta">'+esc(showDateLabel(s))+(s.venue?' · '+esc(s.venue):'')+(loc?' · '+esc(loc):'')+'</p>'+(s.tour||s.era?'<p class="player-note">'+esc([s.tour,s.era].filter(Boolean).join(' · '))+'</p>':'')+(s.description?'<p class="player-note">'+esc(s.description)+'</p>':'')+'<div class="player-controls">'+(s.ticket_url&&s.status==='scheduled'?'<a class="buy-primary button-link" href="'+esc(s.ticket_url)+'" target="_blank" rel="noopener">Tickets</a>':'')+'<button class="text-control" type="button" data-command="tell me about the '+esc(showDateLabel(s))+' Stonefellow show at '+esc(s.venue||'this venue')+'">Ask the agent</button>'+shareButton('show',s.id,s.title||s.venue||'Stonefellow live')+'</div></div></div>'+(s.archive_notes?'<section class="archive-detail-section"><div class="module-kicker">SHOW NOTES</div><p class="player-note">'+esc(s.archive_notes).replace(/\n/g,'<br>')+'</p></section>':'')+'<section class="live-setlist"><div class="module-kicker">SETLIST</div><h3>'+(set.length?set.length+' songs':'Setlist not added yet')+'</h3>'+(set.length?set.map((t,n)=>'<div class="live-setlist-row"><span><b>'+String(n+1).padStart(2,'0')+'</b><strong>'+esc(t.title)+'</strong><small>'+esc(trackArchive(t).version_label||t.release||'')+'</small></span><div><button type="button" data-play-track="'+esc(t.id)+'">Play</button><button type="button" data-show-track="'+esc(t.id)+'">Details</button></div></div>').join(''):'<p class="player-note">This performance does not have a published setlist yet.</p>')+'</section>'+(live.length?'<section class="live-recordings"><div class="module-kicker">LIVE RECORDINGS</div><h3>Listen from this show</h3><div class="live-recording-grid">'+live.map(t=>'<article><strong>'+esc(t.title)+'</strong><small>'+esc(trackArchive(t).version_label||'Live recording')+'</small><button type="button" data-play-track="'+esc(t.id)+'">Play</button><button type="button" data-show-track="'+esc(t.id)+'">Recording details</button></article>').join('')+'</div></section>':'')+(media.length?'<section class="archive-detail-section"><div class="module-kicker">LEGACY SHOW ARCHIVE</div><h3>Legacy media</h3>'+archiveMediaHtml(media)+'</section>':'')+'<section id="showStoryLinks" class="entity-story-links" hidden></section><section id="showTicketOffers" class="show-ticket-offers" hidden></section><div id="publicShowMedia" hidden></div></article>';bindTrackButtons(canvas);bind(canvas);renderShowTicketOffers(s.id);renderPublicEntityMedia('show',s.id,'publicShowMedia','SHOW MEDIA');renderEntityStories('show',s.id,'showStoryLinks');$$('[data-show-track]',canvas).forEach(b=>b.onclick=()=>navigate('track',{id:b.dataset.showTrack}))}
  function renderArchive(){starters.hidden=true;const entries=[];releases.filter(r=>(r.state||'published')==='published'&&r.public_visible!==false).forEach(r=>{const a=r.archive||{};entries.push({kind:'release',id:r.id,title:r.title,date:a.original_release_date||r.release_date||'',era:a.era||'',label:(a.edition?String(a.edition)+' · ':'')+String(r.type||'release')})});catalog.forEach(t=>{const a=trackArchive(t),m=t.metadata||{},date=a.recorded_date||m.release_date||(t.year?String(t.year):'');if(date||a.era||a.version_type||a.session)entries.push({kind:'track',id:t.id,title:t.title,date,era:a.era||'',label:a.version_label||a.version_type||a.session||t.release||'Recording'})});shows.filter(s=>s.public_visible!==false).forEach(s=>entries.push({kind:'show',id:s.id,title:s.title||s.venue||'Stonefellow live',date:s.date||'',era:s.era||'',label:[s.tour,s.venue,showLocation(s)].filter(Boolean).join(' · ')}));entries.sort((a,b)=>String(a.date||'9999').localeCompare(String(b.date||'9999'))||a.title.localeCompare(b.title));const eras=[...new Set(entries.map(x=>x.era).filter(Boolean))];const workMap=new Map();catalog.forEach(t=>{const a=trackArchive(t),key=String(a.canonical_work_id||a.version_of||'').trim();if(!key)return;if(!workMap.has(key))workMap.set(key,[]);workMap.get(key).push(t)});const families=[...workMap.entries()].filter(([,rows])=>rows.length>1);canvas.innerHTML='<article class="module archive-module"><div class="module-kicker">STONEFELLOW MUSIC ARCHIVE</div><div class="archive-head"><div><h2>Recordings, versions & eras</h2><p class="player-note">Explore the catalog chronologically — studio masters, demos, alternate mixes, live takes, sessions and release history.</p></div><div class="archive-counts"><span><strong>'+catalog.length+'</strong> recordings</span><span><strong>'+releases.filter(r=>(r.state||'published')==='published').length+'</strong> releases</span><span><strong>'+families.length+'</strong> version families</span></div></div>'+(eras.length?'<div class="archive-era-filter"><button type="button" class="active" data-archive-era="">All eras</button>'+eras.map(e=>'<button type="button" data-archive-era="'+esc(e)+'">'+esc(e)+'</button>').join('')+'</div>':'')+(families.length?'<section class="archive-family-section"><h3>Version families</h3><div class="archive-family-grid">'+families.map(([key,rows])=>'<article><small>'+esc(key)+'</small><strong>'+esc(rows[0]?.title||key)+'</strong><span>'+rows.length+' connected versions</span><div>'+rows.map(t=>'<button type="button" data-archive-track="'+esc(t.id)+'">'+esc(trackArchive(t).version_label||trackArchive(t).version_type||t.title)+'</button>').join('')+'</div></article>').join('')+'</div></section>':'')+'<section class="archive-timeline-section"><h3>Timeline</h3><div id="archiveTimeline" class="archive-timeline"></div></section></article>';const draw=era=>{const host=$('#archiveTimeline',canvas),rows=era?entries.filter(x=>x.era===era):entries;host.innerHTML=rows.length?rows.map(x=>'<button type="button" class="archive-timeline-row" data-archive-'+x.kind+'="'+esc(x.id)+'"><time>'+esc(x.date||'Undated')+'</time><span><strong>'+esc(x.title)+'</strong><small>'+esc([x.era,x.label].filter(Boolean).join(' · '))+'</small></span><b>›</b></button>').join(''):'<div class="search-empty"><strong>No archive entries in this era.</strong></div>';$$('[data-archive-track]',host).forEach(b=>b.onclick=()=>navigate('track',{id:b.dataset.archiveTrack}));$$('[data-archive-release]',host).forEach(b=>b.onclick=()=>navigate('release',{id:b.dataset.archiveRelease}));$$('[data-archive-show]',host).forEach(b=>b.onclick=()=>navigate('show',{id:b.dataset.archiveShow}))};draw('');$$('[data-archive-era]',canvas).forEach(b=>b.onclick=()=>{$$('[data-archive-era]',canvas).forEach(x=>x.classList.toggle('active',x===b));draw(b.dataset.archiveEra)});$$('[data-archive-track]',canvas).forEach(b=>b.onclick=()=>navigate('track',{id:b.dataset.archiveTrack}))}
  function addTrackToBuilderFromDetail(t){const r=add(t);if(r.ok){say('Added “'+t.title+'” to Side '+r.side+'.');renderStory(t)}else say(r.why==='full'?'That track will not fit on either side.':'That track is already on your build.')}
  function publicEntityMediaHtml(rows,kicker='MEDIA'){
    const media=Array.isArray(rows)?rows:[],hiddenPrimary=['cover','poster','hero','product_primary','primary_artwork','primary_audio'],images=media.filter(x=>x.category==='image'&&!hiddenPrimary.includes(x.role)),videos=media.filter(x=>x.category==='video'),audioRows=media.filter(x=>x.category==='audio'&&!hiddenPrimary.includes(x.role)),docs=media.filter(x=>x.category==='document'||x.role==='archive');
    if(!images.length&&!videos.length&&!audioRows.length&&!docs.length)return'';
    return '<section class="public-track-media public-entity-media"><div class="module-kicker">'+esc(kicker)+'</div><h3>Media & archive</h3>'+(images.length?'<div class="public-song-media-grid">'+images.map(x=>'<figure><img src="'+esc(x.url+(x.url.includes('?')?'&':'?')+'variant=medium')+'" alt="'+esc(x.alt_text||x.title||'Media')+'"><figcaption><strong>'+esc(x.title||roleTitle(x.role))+'</strong>'+(x.caption?'<span>'+esc(x.caption)+'</span>':'')+(x.credit?'<small>'+esc(x.credit)+'</small>':'')+'</figcaption></figure>').join('')+'</div>':'')+(videos.length?'<div class="public-song-video-grid">'+videos.map(x=>'<article><video controls preload="metadata" src="'+esc(x.url)+'"></video><strong>'+esc(x.title||'Video')+'</strong>'+(x.caption?'<p>'+esc(x.caption)+'</p>':'')+'</article>').join('')+'</div>':'')+(audioRows.length?'<div class="public-song-audio-list">'+audioRows.map(x=>'<article><div><strong>'+esc(x.title||'Audio')+'</strong><small>'+esc(String(x.role||'audio').replaceAll('_',' '))+(x.duration?' · '+time(x.duration):'')+'</small></div><audio controls preload="metadata" src="'+esc(x.url)+'"></audio>'+(x.download_url?'<a class="text-control" href="'+esc(x.download_url)+'">Download</a>':'')+'</article>').join('')+'</div>':'')+(docs.length?'<div class="public-song-docs">'+docs.map(x=>'<a href="'+esc(x.download_url||x.url)+'" '+(x.download_url?'download':'target="_blank" rel="noopener"')+'><strong>'+esc(x.title||'Archive file')+'</strong>'+(x.caption?'<span>'+esc(x.caption)+'</span>':'')+'</a>').join('')+'</div>':'')+'</section>';
  }
  function roleTitle(role){return String(role||'media').replaceAll('_',' ').replace(/\b\w/g,m=>m.toUpperCase())}
  async function renderPublicEntityMedia(entityType,entityId,hostId,kicker){
    const host=$('#'+hostId,canvas);if(!host)return;try{const j=await api('media-links.php?entity_type='+encodeURIComponent(entityType)+'&entity_id='+encodeURIComponent(entityId)),html=publicEntityMediaHtml(j.media||[],kicker);if(html){host.innerHTML=html;host.hidden=false}else host.remove()}catch(_){host.remove()}
  }
  function publicTrackMediaHtml(rows){const media=Array.isArray(rows)?rows:[],images=media.filter(x=>x.category==='image'&&!['primary_artwork'].includes(x.role)),videos=media.filter(x=>x.category==='video'),audioRows=media.filter(x=>x.category==='audio'&&x.role!=='primary_audio'),docs=media.filter(x=>x.category==='document'||x.role==='archive');if(!images.length&&!videos.length&&!audioRows.length&&!docs.length)return'';return '<div class="module-kicker">SONG MEDIA</div><h3>Media & archive</h3>'+(images.length?'<div class="public-song-media-grid">'+images.map(x=>'<figure><img src="'+esc(x.url+(x.url.includes('?')?'&':'?')+'variant=medium')+'" alt="'+esc(x.alt_text||x.title||'Song media')+'"><figcaption><strong>'+esc(x.title||'Image')+'</strong>'+(x.caption?'<span>'+esc(x.caption)+'</span>':'')+(x.credit?'<small>'+esc(x.credit)+'</small>':'')+'</figcaption></figure>').join('')+'</div>':'')+(videos.length?'<div class="public-song-video-grid">'+videos.map(x=>'<article><video controls preload="metadata" src="'+esc(x.url)+'"></video><strong>'+esc(x.title||'Video')+'</strong>'+(x.caption?'<p>'+esc(x.caption)+'</p>':'')+'</article>').join('')+'</div>':'')+(audioRows.length?'<div class="public-song-audio-list">'+audioRows.map(x=>'<article><div><strong>'+esc(x.title||'Audio')+'</strong><small>'+esc(x.role.replaceAll('_',' '))+(x.duration?' · '+time(x.duration):'')+'</small></div><audio controls preload="metadata" src="'+esc(x.url)+'"></audio>'+(x.download_url?'<a class="text-control" href="'+esc(x.download_url)+'">Download</a>':'')+'</article>').join('')+'</div>':'')+(docs.length?'<div class="public-song-docs">'+docs.map(x=>'<a href="'+esc(x.download_url||x.url)+'" '+(x.download_url?'download':'target="_blank" rel="noopener"')+'><strong>'+esc(x.title||'Archive file')+'</strong>'+(x.caption?'<span>'+esc(x.caption)+'</span>':'')+'</a>').join('')+'</div>':'')}
  async function renderPublicTrackMedia(trackId){const host=$('#publicTrackMedia',canvas);if(!host)return;try{const j=await api('media-links.php?entity_type=track&entity_id='+encodeURIComponent(trackId));const html=publicTrackMediaHtml(j.media||[]);if(html){host.innerHTML=html;host.hidden=false}else host.remove()}catch(_){host.remove()}}
  function renderStory(t){starters.hidden=true;const m=t.metadata||{},release=releaseForTrack(t),art=trackArtwork(t),rights=[m.pro_affiliation,m.publisher].filter(Boolean).join(' • '),structuredCredits=creditRows(t),relatedRows=relatedTracksFor(t,4),notes=m.recording_notes||m.notes||t.notes||'',year=t.year||release?.release_date?.slice?.(0,4)||'',releaseLabel=release?.title||t.release||'Unreleased';canvas.innerHTML=`<article class="module track-detail-module"><div class="module-kicker">ABOUT THIS SONG</div><div class="track-detail-hero"><div class="track-detail-art">${art?`<img src="${esc(art)}" alt="${esc(t.title)} artwork">`:'<span>SF</span>'}</div><div class="track-detail-intro"><div class="title-actions"><h2>${esc(t.title)}</h2>${favoriteButton('track',t.id,'Favorite track')}</div><p class="track-release-line">${release?`<button type="button" class="inline-link track-release-link" data-open-release="${esc(release.id)}">${esc(releaseLabel)}</button>`:esc(releaseLabel)}${year?' · '+esc(year):''} · ${time(t.duration)}</p><p class="player-note track-story">${esc(t.story||'No story has been added yet.')}</p><div class="player-controls"><button class="buy-primary" type="button" data-play-track="${esc(t.id)}">Play</button><button class="text-control" type="button" data-queue-track="${esc(t.id)}" data-queue-placement="next">Play next</button><button class="text-control" type="button" data-queue-track="${esc(t.id)}" data-queue-placement="end">Add to queue</button>${t.digital_purchase_enabled===false?`<span class="player-note">Streaming only · digital downloads unavailable</span>`:`<button class="text-control" type="button" id="storyBuy">Buy digital · ${Number(t.price||0).toFixed(2)}</button>`}<label class="player-note">Additional vinyl print licenses <select id="storyPrintLicenseSelect" aria-label="Vinyl license package">${(t.vinyl_license_packages||[]).map(p=>`<option value="${Number(p.licenses)}">${Number(p.licenses)} print(s) · ${money(p.price_cents)}</option>`).join('')}</select></label><button class="text-control" type="button" id="storyBuyPrint" ${!t.podEligible||!(t.vinyl_license_packages||[]).length?'disabled':''}>Add vinyl licenses</button><small id="storyVinylBalance" class="player-note" role="status">Checking vinyl print licenses…</small><button class="text-control" type="button" id="storyAddBuild" ${!t.podEligible?'disabled':''}>Put it on a record</button><button class="text-control" type="button" data-command="tell me about ${esc(t.title)}">Ask about this song</button>${shareButton('track',t.id,t.title)}</div></div></div><div class="track-detail-body"><section class="track-detail-copy"><h3>Credits & recording</h3>${structuredCredits.length?`<div class="credit-grid">${structuredCredits.map(([label,value])=>`<div><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('')}</div>`:''}${(t.credits||[]).length?`<ul class="credit-list">${t.credits.map(x=>`<li>${esc(x)}</li>`).join('')}</ul>`:''}${notes?`<h3>Recording notes</h3><p class="player-note">${esc(notes).replace(/\n/g,'<br>')}</p>`:''}</section><aside class="story-facts"><div class="fact"><span>Release</span>${esc(releaseLabel)}</div><div class="fact"><span>Length</span>${time(t.duration)}</div><div class="fact"><span>Mood</span>${esc((t.mood||[]).join(', ')||'—')}</div><div class="fact"><span>Themes</span>${esc((t.themes||[]).join(', ')||'—')}</div>${m.isrc?`<div class="fact"><span>ISRC</span>${esc(m.isrc)}</div>`:''}${rights?`<div class="fact"><span>Rights</span>${esc(rights)}</div>`:''}</aside></div><section id="publicSongbookPanel" class="public-songbook-panel" hidden></section>${archiveTrackContextHtml(t)}<section id="trackStoryLinks" class="entity-story-links" hidden></section><section id="publicTrackMedia" class="public-track-media" hidden></section>${relatedRows.length?`<section class="related-section"><div class="module-kicker">KEEP LISTENING</div><h3>Related tracks</h3><div class="related-track-grid">${relatedRows.map(x=>`<article class="related-track-card"><button type="button" class="related-art" data-related-open="${esc(x.id)}">${trackArtwork(x)?`<img src="${esc(trackArtwork(x))}" alt="">`:'SF'}</button><div><strong>${esc(x.title)}</strong><small>${esc(x.release||'Stonefellow')} · ${time(x.duration)}</small><div class="player-controls"><button type="button" class="tiny-control" data-play-track="${esc(x.id)}">Play</button><button type="button" class="tiny-control" data-queue-track="${esc(x.id)}" data-queue-placement="end">+ Queue</button><button type="button" class="tiny-control" data-related-open="${esc(x.id)}">Details</button></div></div></article>`).join('')}</div></section>`:''}</article>`;bind(canvas);bindTrackButtons(canvas);bindFavoriteButtons(canvas);bindQueueTrackButtons(canvas);const buy=$('#storyBuy',canvas);if(buy)buy.onclick=()=>addTrackToCart(t);const buyPrint=$('#storyBuyPrint',canvas);if(buyPrint)buyPrint.onclick=()=>addVinylLicensePack('vinyl_license_pack',t.id,Number($('#storyPrintLicenseSelect',canvas)?.value||0));void updatePrintLicenseBalance([t.id],'#storyVinylBalance');const addBuild=$('#storyAddBuild',canvas);if(addBuild)addBuild.onclick=()=>addTrackToBuilderFromDetail(t);$$('[data-open-release]',canvas).forEach(b=>b.onclick=()=>openRelease(b.dataset.openRelease,true));$$('[data-related-open]',canvas).forEach(b=>b.onclick=()=>{const x=trackById(b.dataset.relatedOpen);if(x){renderStory(x);setUrl('track',{id:x.id})}});$$('[data-archive-track]',canvas).forEach(b=>b.onclick=()=>{const x=trackById(b.dataset.archiveTrack);if(x){renderStory(x);setUrl('track',{id:x.id})}});renderPublicTrackMedia(t.id);renderPublicSongbook(t.id);renderEntityStories('track',t.id,'trackStoryLinks')}


  const limit=()=>cfg.formats[st.builder.format]?.sideLimitSeconds||1200;
  const sideTime=side=>(st.builder[side]||[]).reduce((sum,id)=>sum+(trackById(id)?.duration||0),0);
  const remainingTime=side=>Math.max(0,limit()-sideTime(side));
  function selectedBuildIds(){return [...(st.builder.A||[]),...(st.builder.B||[])]}
  function duplicateBuildIds(){const seen=new Set(),dup=[];for(const id of selectedBuildIds()){if(seen.has(id)&&!dup.includes(id))dup.push(id);seen.add(id)}return dup}
  function has(id){return st.builder.A.includes(id)||st.builder.B.includes(id)}
  function dedupeBuild(){const seen=new Set();for(const side of ['A','B'])st.builder[side]=st.builder[side].filter(id=>{if(seen.has(id))return false;seen.add(id);return true});persist();renderBuilder()}
  function add(t,preferred){if(!t||has(t.id))return {ok:false,why:'already'};const sides=preferred?[preferred,preferred==='A'?'B':'A']:['A','B'];for(const side of sides){if(sideTime(side)+Number(t.duration||0)<=limit()){st.builder[side].push(t.id);persist();return {ok:true,side}}}return {ok:false,why:'full'}}
  function remove(id){st.builder.A=st.builder.A.filter(x=>x!==id);st.builder.B=st.builder.B.filter(x=>x!==id);persist()}
  function move(side,index,delta){const arr=st.builder[side],to=index+delta;if(to<0||to>=arr.length)return;[arr[index],arr[to]]=[arr[to],arr[index]];persist()}
  function relocateTrack(id,from,to,toIndex=null,announce=true){if(!['A','B'].includes(from)||!['A','B'].includes(to))return false;const t=trackById(id);if(!t)return false;if(from!==to&&sideTime(to)+Number(t.duration||0)>limit()){if(announce)say('That track will not fit on Side '+to+'.');return false}const source=st.builder[from],sourceIndex=source.indexOf(id);if(sourceIndex<0)return false;source.splice(sourceIndex,1);const target=st.builder[to];let index=toIndex===null?target.length:Number(toIndex);if(from===to&&sourceIndex<index)index--;index=Math.max(0,Math.min(target.length,index));target.splice(index,0,id);persist();return true}
  function transfer(id,from,to=from==='A'?'B':'A'){if(relocateTrack(id,from,to,null))renderBuilder()}
  function moveBuilderTrackToSide(id,side,announce=true){
    const from=st.builder.A.includes(id)?'A':(st.builder.B.includes(id)?'B':'');
    let result='';
    if(!from)result='That track is not on the current build.';
    else if(!['A','B'].includes(side))result='Choose Side A or Side B.';
    else if(from===side)result='That track is already on Side '+side+'.';
    else if(relocateTrack(id,from,side,null,false)){renderBuilder();result='Moved “'+(trackById(id)?.title||id)+'” to Side '+side+'.'}
    else result='That track does not fit on Side '+side+'. The record is unchanged.';
    if(announce)say(result);return result;
  }
  function localFillOrder(){const rec=new Map((st.personalization.recommendations?.items||[]).map((x,i)=>[x.track_id,1000-i*10+Number(x.score||0)]));const chosen=selectedBuildIds().map(trackById).filter(Boolean),seed=chosen.at(-1)||st.track||catalog[0];return catalog.filter(t=>t.podEligible&&!has(t.id)).map((t,index)=>{const keys=new Set([...(seed?.mood||[]),...(seed?.themes||[])]),similarity=[...(t.mood||[]),...(t.themes||[])].filter(v=>keys.has(v)).length*3-Math.abs(Number(t.energy||3)-Number(seed?.energy||3));return {t,score:(rec.get(t.id)||0)+similarity,index}}).sort((x,y)=>y.score-x.score||x.index-y.index).map(x=>x.t)}
  function localFill(side='both'){const targets=side==='both'?['A','B']:[side],added=[];for(const target of targets){for(const t of localFillOrder()){if(has(t.id))continue;if(Number(t.duration||0)<=remainingTime(target)){st.builder[target].push(t.id);added.push(t.id);if(remainingTime(target)<30)break}}}persist();return added}
  async function fillBuild(side='both',announce=true){
    if(!['A','B','both'].includes(side))side='both';
    try{
      let result='';
      if(st.auth.authenticated){
        const j=await secureJsonPost('builds.php',{action:'suggest',build:st.builder,side});
        st.builder={...defaultBuild(),...(j.build||st.builder),version:4};persist();renderBuilder();
        const count=(j.added?.A?.length||0)+(j.added?.B?.length||0);
        result=count?(j.personalized?'Filled the remaining time using your Stonefellow listening profile.':'Filled the remaining time with the best-fitting eligible tracks.'):'There is no eligible track that fits the remaining time.';
      }else{
        await loadPersonalization();const added=localFill(side);renderBuilder();
        result=added.length?'Filled the remaining time. Sign in to make this personalized.':'There is no eligible track that fits the remaining time.';
      }
      if(announce)say(result);return result;
    }catch(e){if(announce)say(e.message);return 'The record was not filled: '+e.message}
  }
  async function loadBuilderDrafts(force=false){if(!st.auth.authenticated){st.builderDrafts={...st.builderDrafts,loaded:true,items:[],activeId:0};localStorage.removeItem('stonefellow.build.draft.id');return[]}if(st.builderDrafts.loaded&&!force)return st.builderDrafts.items;try{const j=await api('builds.php');if(j.csrf)st.auth.csrf=j.csrf||st.auth.csrf;const items=j.builds||[];let activeId=Number(st.builderDrafts.activeId||0);if(activeId&&!items.some(x=>Number(x.id)===activeId)){activeId=0;localStorage.removeItem('stonefellow.build.draft.id')}st.builderDrafts={...st.builderDrafts,loaded:true,items,activeId};return st.builderDrafts.items}catch(_){st.builderDrafts={...st.builderDrafts,loaded:true,items:[]};return[]}}
  // Draft comparisons exclude local savedAt timestamps and preserve the full design payload.
  const builderDraftFingerprint=build=>JSON.stringify({
    title:build.title,theme:build.theme,format:build.format,
    A:build.A,B:build.B,coverFront:build.coverFront,coverBack:build.coverBack,
    customNote:build.customNote,playerModules:build.playerModules
  });
  let builderCleanFingerprint=null;
  function builderHasUnsavedChanges(){
    return builderCleanFingerprint!==null&&builderDraftFingerprint(st.builder)!==builderCleanFingerprint;
  }
  function confirmBuilderReplacement(){
    return !builderHasUnsavedChanges()||(typeof window.confirm==='function'&&window.confirm('This design has unsaved changes. Discard those edits and load another design?'));
  }
  let builderDraftBusy=false,builderNotice={text:'',kind:'info'};
  function showBuilderNotice(text,kind='info'){
    builderNotice={text:String(text||''),kind:kind==='error'?'error':kind==='success'?'success':'info'};
    const target=$('#builderFeedback',canvas);
    if(target){target.textContent=builderNotice.text;target.dataset.kind=builderNotice.kind;target.hidden=!builderNotice.text;}
  }
  async function saveBuilderDraft(showAccount=false){
    if(builderDraftBusy)return null;
    builderDraftBusy=true;
    const button=$('#saveBuild',canvas);
    if(button){button.disabled=true;button.textContent='Saving…';}
    showBuilderNotice('Saving your record draft…');
    try{
      persist();
      if(!st.auth.authenticated){
        builderTab='saved-drafts';renderBuilder();
        showBuilderNotice('Saved on this device. Sign in to sync drafts with your account.','success');
        return {id:'local',name:st.builder.title,build:st.builder};
      }
      if(!st.builderDrafts.loaded)await loadBuilderDrafts(true);
      const result=await secureJsonPost('builds.php',{action:'save',id:Number(st.builderDrafts.activeId||0),build:st.builder});
      if(!result?.build?.id)throw new Error('The server did not confirm the saved draft.');
      st.builderDrafts={loaded:true,items:result.builds||[],activeId:Number(result.build.id)};
      localStorage.setItem('stonefellow.build.draft.id',String(st.builderDrafts.activeId));
      if(result.build.build)st.builder={...defaultBuild(),...result.build.build,version:4};
      persist();
      builderCleanFingerprint=builderDraftFingerprint(st.builder);
      builderTab='saved-drafts';
      renderBuilder();
      showBuilderNotice(result.build.build?.pendingPlayerModules && Object.keys(result.build.build.pendingPlayerModules).length
        ?'Draft saved. Some player upgrades need ownership verification before checkout.'
        :'Saved “'+st.builder.title+'” to your drafts.','success');
      if(showAccount)renderAccount();
      return result.build;
    }catch(e){
      const detail=e?.message||'Save failed. Please try again.';
      showBuilderNotice('Draft not saved: '+detail,'error');
      console.error('Stonefellow draft save failed',e);
      return null;
    }finally{
      builderDraftBusy=false;
      if(button?.isConnected){button.disabled=false;button.textContent=st.auth.authenticated?'Save draft':'Save locally';}
    }
  }
  function loadBuilderDraft(id){
    const row=(st.builderDrafts.items||[]).find(x=>Number(x.id)===Number(id));
    if(!row?.build||!confirmBuilderReplacement())return;
    st.builder={...defaultBuild(),...row.build,version:4};
    builderCleanFingerprint=builderDraftFingerprint(st.builder);st.builderDrafts.activeId=Number(row.id);
    localStorage.setItem('stonefellow.build.draft.id',String(row.id));
    persist();builderTab='song-lists';renderBuilder();
    showBuilderNotice('Loaded “'+(row.name||st.builder.title)+'”.','success');
  }
  function openBuilderDraftModal(value){
    if(document.querySelector('#builderDraftModal'))return;
    const local=String(value)==='local';
    const row=local?{id:'local',name:st.builder.title}:st.builderDrafts.items.find(x=>Number(x.id)===Number(value));
    if(!row)return;
    const dialog=document.createElement('dialog');dialog.className='builder-draft-dialog';dialog.id='builderDraftModal';
    dialog.setAttribute('aria-labelledby','builderDraftModalTitle');
    dialog.innerHTML='<div class="draft-modal-shell"><div class="draft-modal-kicker">STONEFELLOW · MY LIBRARY</div>'+
      '<div class="draft-modal-heading"><div><h2 id="builderDraftModalTitle">Manage saved draft</h2><p>Rename your record edition or remove this saved version.</p></div>'+
      '<button type="button" class="draft-modal-x" data-draft-dismiss aria-label="Close draft manager">×</button></div>'+
      '<form id="builderDraftRenameForm"><label for="builderDraftName">Draft name</label>'+
      '<input id="builderDraftName" name="name" maxlength="60" required value="'+esc(row.name||'My Stonefellow Record')+'" autocomplete="off">'+
      '<div class="draft-modal-feedback" id="builderDraftModalFeedback" role="alert" hidden></div>'+
      '<div class="draft-modal-actions"><button type="submit" class="draft-modal-primary" data-draft-rename>Save name</button>'+
      '<button type="button" class="draft-modal-outline" data-draft-dismiss>Cancel</button></div></form>'+
      '<div class="draft-modal-separator"></div><div class="draft-modal-danger"><div><strong>Delete this draft</strong>'+
      '<small>Delete only this saved version. Your purchased music will not be affected.</small></div>'+
      '<button type="button" class="draft-modal-delete" data-draft-delete>Delete draft</button></div>'+
      '<div class="draft-modal-confirm" id="builderDraftDeleteConfirm" hidden><strong>Delete “'+esc(row.name)+'”?</strong>'+
      '<p>This cannot be undone. Your other drafts and purchases are kept.</p>'+
      '<div class="draft-modal-actions"><button type="button" class="draft-modal-delete" data-draft-confirm>Yes, delete draft</button>'+
      '<button type="button" class="draft-modal-outline" data-draft-keep>Keep draft</button></div></div></div>';
    document.body.appendChild(dialog);
    dialog.addEventListener('close',()=>dialog.remove(),{once:true});
    dialog.querySelectorAll('[data-draft-dismiss]').forEach(b=>b.addEventListener('click',()=>dialog.close()));
    const form=dialog.querySelector('#builderDraftRenameForm');
    const nameInput=dialog.querySelector('#builderDraftName');
    const feedback=dialog.querySelector('#builderDraftModalFeedback');
    const renameButton=dialog.querySelector('[data-draft-rename]');
    const error=message=>{feedback.hidden=false;feedback.textContent=message;};
    form.addEventListener('submit',async e=>{
      e.preventDefault();
      const name=nameInput.value.trim();
      if(!name){error('Enter a name for this draft.');nameInput.focus();return;}
      renameButton.disabled=true;renameButton.textContent='Saving…';feedback.hidden=true;
      try{
        if(local){st.builder.title=name;persist();}
        else{
          const result=await secureJsonPost('builds.php',{action:'rename',id:Number(row.id),name});
          st.builderDrafts.items=result.builds||[];
          if(Number(st.builderDrafts.activeId)===Number(row.id)){st.builder.title=name;persist();}
        }
        dialog.close();builderTab='saved-drafts';renderBuilder();
        showBuilderNotice('Renamed draft to “'+name+'”.','success');
      }catch(e){error(e?.message||'Unable to rename this draft.');renameButton.disabled=false;renameButton.textContent='Save name';}
    });
    const confirm=dialog.querySelector('#builderDraftDeleteConfirm');
    dialog.querySelector('[data-draft-delete]').onclick=()=>{confirm.hidden=false;dialog.querySelector('[data-draft-confirm]').focus();};
    dialog.querySelector('[data-draft-keep]').onclick=()=>{confirm.hidden=true;nameInput.focus();};
    dialog.querySelector('[data-draft-confirm]').onclick=async e=>{
      const button=e.currentTarget;button.disabled=true;button.textContent='Deleting…';feedback.hidden=true;
      try{
        if(local){
          localStorage.removeItem('stonefellow.build.v04');
          st.builder=defaultBuild();st.builderDrafts.activeId=0;
          localStorage.removeItem('stonefellow.build.draft.id');
        }else{
          const result=await secureJsonPost('builds.php',{action:'delete',id:Number(row.id)});
          st.builderDrafts.items=result.builds||[];
          if(Number(st.builderDrafts.activeId)===Number(row.id)){
            st.builderDrafts.activeId=0;localStorage.removeItem('stonefellow.build.draft.id');
          }
        }
        dialog.close();builderTab='saved-drafts';renderBuilder();
        showBuilderNotice('Draft deleted. Your other drafts and purchased music are unchanged.','success');
      }catch(e){error(e?.message||'Unable to delete this draft.');button.disabled=false;button.textContent='Yes, delete draft';}
    };
    if(typeof dialog.showModal==='function')dialog.showModal();
    else dialog.setAttribute('open','');
    nameInput.focus();nameInput.select();
  }
  function deleteBuilderDraft(id){openBuilderDraftModal(id);}
  // Curated media library is the only source of fan-selectable artwork.
  const vinylArtState={loaded:false,loading:false,collections:[],error:''};
  let vinylArtSide='coverFront';
  const vinylArtEntries=()=>vinylArtState.collections.flatMap(g=>(g.photos||[]).map(p=>({...p,galleryTitle:g.title})));
  const vinylArtByRef=ref=>vinylArtEntries().find(p=>p.reference===ref)||null;
  async function loadVinylArtCollections(){
    if(vinylArtState.loaded||vinylArtState.loading)return;
    vinylArtState.loading=true;
    try{const r=await api('vinyl-artwork-collections.php');vinylArtState.collections=Array.isArray(r.collections)?r.collections:[];vinylArtState.error='';}
    catch(e){vinylArtState.error='Curated photos are unavailable right now.';}
    finally{vinylArtState.loading=false;vinylArtState.loaded=true;
      if((new URL(location.href)).searchParams.get('view')==='builder')renderBuilder();}
  }
  function vinylArtLabel(ref){
    const p=vinylArtByRef(ref);
    return p?p.title||p.galleryTitle||'Curated photograph':ref.startsWith('api/media.php?')?'Photo no longer available':ref;
  }
  function vinylArtPicker(){
    const galleries=vinylArtState.collections||[];
    const items=galleries.map(g=>'<section class="vinyl-world-collection"><h4>'+esc(g.title)+'</h4><p>'+esc(g.summary||'')+'</p><div class="vinyl-world-photo-grid">'+(g.photos||[]).map(p=>'<button class="vinyl-world-photo" type="button" data-vinyl-image="'+esc(p.reference)+'"><img src="'+esc(p.thumb_url||p.url)+'" alt="'+esc(p.alt_text||p.title||'Stonefellow photo')+'" loading="lazy"><span>'+esc(p.title||'Photo')+'</span>'+(p.credit?'<small>'+esc(p.credit)+'</small>':'')+'</button>').join('')+'</div></section>').join('');
    return '<section class="vinyl-world-picker" aria-label="Artist World curated cover photography"><h3>Artist World photo collections</h3><p>Select artist-approved Media Library photography for the front or back of your record. Availability is rechecked at checkout.</p><div class="vinyl-world-sides"><button type="button" data-vinyl-side="coverFront" aria-pressed="'+(vinylArtSide==='coverFront')+'">Front cover</button><button type="button" data-vinyl-side="coverBack" aria-pressed="'+(vinylArtSide==='coverBack')+'">Back cover</button></div><p class="player-note">Editing: <strong>'+esc(vinylArtSide==='coverFront'?'Front':'Back')+'</strong> · '+esc(vinylArtLabel(st.builder[vinylArtSide]||'stonefellow'))+'</p><button class="text-control" type="button" id="vinylClearPhoto">Use default Stonefellow artwork</button>'+(vinylArtState.loading?'<p>Loading approved photos…</p>':vinylArtState.error?'<p role="status">'+esc(vinylArtState.error)+'</p>':items||'<p>No galleries are approved for vinyl yet. Existing themes are still available.</p>')+'</section>';
  }
  function builderArtworkTiles(){const ids=selectedBuildIds().slice(0,4),tiles=ids.map(id=>{const t=trackById(id),art=t?trackArtwork(t):'';return art?'<img src="'+esc(art)+'" alt="">':'<span>'+esc((t?.title||'SF').slice(0,2).toUpperCase())+'</span>'});while(tiles.length<4)tiles.push('<span>SF</span>');return tiles.join('')}
  function builderSideLimit(builder=st.builder){return cfg.formats[builder?.format||st.builder.format]?.sideLimitSeconds||1200}
  function builderSequencePreview(builder=st.builder){const sideLimit=builderSideLimit(builder),renderSide=side=>{const ids=builder?.[side]||[],rows=ids.map((id,i)=>{const t=trackById(id);return t?'<li><span>'+(i+1)+'. '+esc(t.title)+'</span><small>'+time(t.duration)+'</small></li>':''}).join('');const secs=ids.reduce((n,id)=>n+Number(trackById(id)?.duration||0),0);return '<div class="build-sequence-side"><div><strong>Side '+side+'</strong><span>'+time(secs)+' / '+time(sideLimit)+'</span></div><ol>'+(rows||'<li class="empty">Empty</li>')+'</ol></div>'};return '<div class="build-sequence-preview">'+renderSide('A')+renderSide('B')+'</div>'}
  function quoteSequencePreview(item){const sides=item?.builder?.sides||{},renderSide=side=>{const data=sides[side]||{duration:0,tracks:[]},rows=(data.tracks||[]).map((t,i)=>'<li><span>'+(i+1)+'. '+esc(t.title||t.id)+'</span><small>'+time(t.duration||0)+'</small></li>').join('');return '<div class="build-sequence-side"><div><strong>Side '+side+'</strong><span>'+time(data.duration||0)+'</span></div><ol>'+(rows||'<li class="empty">Empty</li>')+'</ol></div>'};return '<div class="build-sequence-preview validated">'+renderSide('A')+renderSide('B')+'</div>'}
  function cartItemMarkup(x,i){
    if(x.type==='track')return '<div class="cart-line"><div><strong>'+esc(trackById(x.track_id)?.title||x.track_id)+'</strong><small>Digital track</small></div><button type="button" data-remove="'+i+'" aria-label="Remove item">×</button></div>';
    if(x.type==='vinyl_license_pack'||x.type==='album_vinyl_license_pack'){
      const label=x.type==='vinyl_license_pack'?trackById(x.track_id)?.title:releases.find(r=>r.id===x.release_id)?.title;
      return '<div class="cart-line"><div><strong>'+esc(label||'Vinyl print license')+'</strong><small>'+Number(x.licenses)+' additional vinyl print license(s)</small></div><button type="button" data-remove="'+i+'">×</button></div>';
    }
    if(x.type==='release'||x.type==='album_vinyl'){
      const r=releases.find(y=>y.id===x.release_id);
      return '<div class="cart-line"><div><strong>'+esc(r?.title||x.release_id)+'</strong><small>'+(x.type==='release'?'Digital album · 1 print license per song':'Locked album pressing · song print licenses required')+'</small></div><button type="button" data-remove="'+i+'">×</button></div>';
    }
    if(x.type==='turntable_module'){return '<div class="cart-line"><div><strong>'+esc(x.module_id)+'</strong><small>Digital turntable module · unlocks after payment</small></div><button type="button" data-remove="'+i+'" aria-label="Remove item">×</button></div>'}
    if(x.type==='merch'){const p=(st.storefrontProducts||[]).find(z=>z.id===x.product_id),v=p?.variants?.find(z=>Number(z.id)===Number(x.variant_id||0));return '<div class="cart-line merch-cart-line"><div><strong>'+esc(p?.label||x.label||x.product_id)+'</strong><small>'+esc(v?.title||x.variant_label||'Merchandise')+'</small></div><label>Qty <input type="number" min="1" max="'+Number(p?.max_per_order||50)+'" value="'+Number(x.quantity||1)+'" data-merch-cart-qty="'+i+'"></label><button type="button" data-remove="'+i+'" aria-label="Remove item">×</button></div>'}
    const b=x.builder||{};return '<div class="cart-line cart-custom-build"><div class="cart-custom-main"><strong>'+esc(b.title||'Custom '+x.format)+'</strong><small>Custom '+esc(x.format)+' · exact sequence</small>'+builderSequencePreview(b)+'</div><button type="button" data-remove="'+i+'" aria-label="Remove item">×</button></div>'
  }
  function builderCartItem(){return {type:'custom_media',format:st.builder.format,builder:{version:4,format:st.builder.format,title:st.builder.title,theme:st.builder.theme,A:[...st.builder.A],B:[...st.builder.B],coverFront:st.builder.coverFront||'stonefellow',coverBack:st.builder.coverBack||'stonefellow',customNote:String(st.builder.customNote||'').slice(0,280)}}}
  function builderReviewProblems(){
    const problems=[];
    if(!String(st.builder.title||'').trim())problems.push('Add a record title.');
    if(!st.builder.A.length&&!st.builder.B.length)problems.push('Add at least one song.');
    if(duplicateBuildIds().length)problems.push('Remove duplicate songs.');
    for(const side of ['A','B']){
      if(sideTime(side)>limit())problems.push('Shorten Side '+side+' to fit the time limit.');
      for(const id of st.builder[side]){
        const track=trackById(id);
        if(!track||!track.podEligible)problems.push('Side '+side+' contains a song no longer eligible for custom vinyl.');
      }
    }
    return [...new Set(problems)];
  }
  function addBuildToCart(){
    const problems=builderReviewProblems();
    if(problems.length){
      builderTab='submit';renderBuilder();
      showBuilderNotice(problems.join(' '),'error');
      return;
    }
    st.cart=st.cart.filter(x=>x.type!=='custom_media');
    st.cart.push(builderCartItem());persistCart();
    showBuilderNotice('Added your reviewed vinyl design to the cart. Checkout is the next step.','success');
    say('Your custom vinyl design is in the cart. Review the total and complete checkout.',renderCart);
  }
  // Preserve the active tab when the builder re-renders after an edit.
  let builderTab='songs';
  function renderBuilder(){
    st.builder.format='vinyl';
    const fmt=cfg.formats[st.builder.format]||{label:st.builder.format},theme=(cfg.artworkThemes||[]).find(x=>x.id===st.builder.theme)||cfg.artworkThemes?.[0]||{className:''},dups=duplicateBuildIds(),drafts=st.builderDrafts.items||[],activeDraft=drafts.find(x=>Number(x.id)===Number(st.builderDrafts.activeId||0));
    // Historical cassette builds remain in the account; this page builds vinyl only.
    const chosenFront=vinylArtByRef(st.builder.coverFront||''),photoImg=chosenFront?'<img class="vinyl-world-preview-image" src="'+esc(chosenFront.url)+'" alt="'+esc(chosenFront.alt_text||chosenFront.title)+'">':'';
    const preview='<div class="object-sleeve '+theme.className+' builder-sleeve-v2">'+(photoImg||'<div class="builder-art-grid">'+builderArtworkTiles()+'</div>')+'<div class="builder-preview-overlay"><strong>'+esc(st.builder.title)+'</strong><small>STONEFELLOW · CUSTOM EDITION</small></div></div>';
    const draftsHtml=st.auth.authenticated?'<details class="builder-drafts" open><summary>Saved drafts <span>'+drafts.length+'</span></summary><div class="builder-draft-list">'+(drafts.length?drafts.map(d=>'<div class="builder-draft-row '+(Number(d.id)===Number(st.builderDrafts.activeId)?'active':'')+'"><button type="button" data-builder-draft-load="'+d.id+'"><strong>'+esc(d.name)+'</strong><small>'+esc(String(d.format||'vinyl').toUpperCase())+' · '+esc(String(d.updated_at||'').replace('T',' ').slice(0,19))+'</small></button><button type="button" class="builder-draft-manage" data-builder-draft-manage="'+d.id+'" aria-label="Rename or delete '+esc(d.name)+'">Manage</button></div>').join(''):'<p class="player-note">Save this build to keep a draft in your account.</p>')+'</div></details>':'<div class="builder-drafts"><div class="builder-drafts-local-title">Saved on this device</div><div class="builder-draft-row"><div class="builder-local-name"><strong>'+esc(st.builder.title||'My Stonefellow Record')+'</strong><small>'+esc(st.builder.savedAt?'Updated '+String(st.builder.savedAt).replace('T',' ').slice(0,19):'Current local edition')+'</small></div><button type="button" class="builder-draft-manage" data-builder-draft-manage="local">Manage</button></div><p class="player-note builder-draft-guest-copy">Sign in to sync this draft across devices.</p></div>';
    const tabs=[['songs','Songs'],['song-lists','Song Lists'],['album-artwork','Album Artwork'],['personal-notes','Personal Notes'],['saved-drafts','Saved Drafts'],['submit','Submit']];
    if(!tabs.some(([id])=>id===builderTab))builderTab='songs';
    const panel=(id,body)=>'<section class="builder-tab-panel" id="builder-panel-'+id+'" role="tabpanel" aria-labelledby="builder-tab-'+id+'" tabindex="0" '+(builderTab===id?'':'hidden')+'>'+body+'</section>';
    const nav='<div class="builder-tablist" role="tablist" aria-label="Vinyl record builder sections">'+tabs.map(([id,name])=>'<button type="button" class="builder-tab" id="builder-tab-'+id+'" role="tab" data-builder-tab="'+id+'" aria-controls="builder-panel-'+id+'" aria-selected="'+(builderTab===id?'true':'false')+'" tabindex="'+(builderTab===id?'0':'-1')+'">'+name+'</button>').join('')+'</div>';
    const artwork='<div class="builder-layout builder-artwork-layout"><div class="builder-object"><div class="builder-settings"><label class="builder-title-label" for="mixTitle">Edition title</label><input id="mixTitle" maxlength="60" value="'+esc(st.builder.title)+'" aria-label="Custom record title"><div class="theme-switch" role="group" aria-label="Cover color theme">'+(cfg.artworkThemes||[]).map(x=>'<button type="button" title="'+esc(x.label)+'" aria-label="'+esc(x.label)+' artwork" data-theme="'+esc(x.id)+'" class="'+x.className+' '+(x.id===st.builder.theme?'active':'')+'"></button>').join('')+'</div></div>'+preview+'<div class="object-meta">'+esc(fmt.label)+' • custom edition'+(activeDraft?' • draft #'+activeDraft.id:'')+'</div></div></div>'+vinylArtPicker()+'<div data-builder-player-options></div>';
    const songs='<div class="builder-catalog" aria-label="Stonefellow songs"><div class="builder-catalog-head"><strong>STONEFELLOW SONGS</strong><span class="builder-song-count">'+catalog.length+' available</span></div><p class="player-note">Browse every available track. Add eligible songs to your record or preview a song first.</p><div class="builder-song-list"></div></div>';
    const songLists=(dups.length?'<div class="builder-warning"><strong>Duplicate tracks detected.</strong><span>Each song can appear only once on a physical build.</span><button type="button" id="dedupeBuild">Remove duplicates</button></div>':'')+'<div class="builder-sides-heading"><h3>YOUR RECORD SIDES</h3><button class="text-control" id="agentChoose" type="button">Fill remaining time</button></div><div class="sides" aria-label="Vinyl sides"><div class="side-card" data-side="A"></div><div class="side-card" data-side="B"></div></div>';
    const notes='<div class="builder-personal-note"><h3>Your personal dedication</h3><p class="player-note">Add an optional message to the back of your Stonefellow record. Maximum 280 characters.</p></div>';
    const problems=builderReviewProblems();
    const reviewSide=side=>'<section class="builder-review-side" aria-label="Side '+side+' song review"><h4>Side '+side+' · '+st.builder[side].length+' songs · '+time(sideTime(side))+' / '+time(limit())+'</h4>'+(st.builder[side].length?'<ol>'+st.builder[side].map(id=>{const t=trackById(id);return '<li>'+esc(t?.title||'Unavailable song')+' <small>'+time(Number(t?.duration||0))+'</small></li>'}).join('')+'</ol>':'<p class="player-note">No songs selected.</p>')+'</section>';
    const submit='<div class="builder-submit-review"><h3>Review your custom vinyl</h3><p class="player-note">Confirm the edition title, song order, artwork and dedication before proceeding to your cart.</p><p><strong>Edition:</strong> '+esc(st.builder.title)+'</p><p><strong>Artwork:</strong> '+esc(st.builder.theme)+' · Front: '+esc(st.builder.coverFront||'stonefellow')+' · Back: '+esc(st.builder.coverBack||'stonefellow')+'</p>'+reviewSide('A')+reviewSide('B')+(st.builder.customNote?'<p><strong>Personal dedication:</strong> '+esc(st.builder.customNote)+'</p>':'')+(problems.length?'<div class="builder-warning" role="alert"><strong>Before checkout</strong><ul>'+problems.map(p=>'<li>'+esc(p)+'</li>').join('')+'</ul></div>':'<p class="player-note" role="status">Ready for cart review. No payment or production order is created until checkout.</p>')+'</div><div class="builder-footer"><button class="buy-primary" id="orderBuild" type="button">Review custom vinyl in cart</button><button class="text-control" id="saveBuild" type="button">'+(st.auth.authenticated?(st.builderDrafts.activeId?'Update draft':'Save draft'):'Save locally')+'</button><button class="text-control" id="exportBuild" type="button">Export build</button><button class="text-control" id="clearBuild" type="button">Start over</button><span class="saved-badge">'+(st.builder.savedAt?'Last saved '+esc(String(st.builder.savedAt).replace('T',' ').slice(0,19)):'')+'</span></div>';
    canvas.innerHTML='<article class="module builder-module" aria-label="Custom vinyl record builder"><div class="builder-left-rail">'+nav+'<div class="builder-notice" id="builderFeedback" data-kind="'+esc(builderNotice.kind)+'" role="status" aria-live="polite" '+(builderNotice.text?'':'hidden')+'>'+esc(builderNotice.text)+'</div>'+panel('songs',songs)+panel('song-lists',songLists)+panel('album-artwork',artwork)+panel('personal-notes',notes)+panel('saved-drafts',draftsHtml)+panel('submit',submit)+'</div></article>';
    const buttons=$$('[data-builder-tab]',canvas);
    const activateTab=(id,focus=false)=>{
      if(!tabs.some(([key])=>key===id))return;
      builderTab=id;
      buttons.forEach(button=>{
        const active=button.dataset.builderTab===id;
        button.setAttribute('aria-selected',String(active));button.tabIndex=active?0:-1;
        const region=$('#builder-panel-'+button.dataset.builderTab,canvas);
        if(region)region.hidden=!active;
        if(active&&focus)button.focus();
      });
    };
    buttons.forEach((button,index)=>{
      button.onclick=()=>activateTab(button.dataset.builderTab);
      button.onkeydown=e=>{
        let next=-1;
        if(e.key==='ArrowRight')next=(index+1)%buttons.length;
        if(e.key==='ArrowLeft')next=(index+buttons.length-1)%buttons.length;
        if(e.key==='Home')next=0;
        if(e.key==='End')next=buttons.length-1;
        if(next!==-1){e.preventDefault();activateTab(buttons[next].dataset.builderTab,true);}
      };
    });
    for(const side of ['A','B']){
      const used=sideTime(side),over=used>limit(),remain=Math.max(0,limit()-used),pct=Math.min(100,(used/limit())*100),c=$('[data-side="'+side+'"]',canvas);c.classList.toggle('over',over);c.innerHTML='<div class="side-head"><div><strong>SIDE '+side+'</strong><small>'+(over?'Shorten by '+time(used-limit()):time(remain)+' remaining')+'</small></div><span>'+time(used)+' / '+time(limit())+'</span></div><div class="meter"><span style="width:'+pct+'%"></span></div><button type="button" class="fill-side-control" data-fill-side="'+side+'">Fill remaining</button><div class="side-tracks"></div>';
      const l=$('.side-tracks',c);if(!st.builder[side].length)l.innerHTML='<div class="side-empty">Drop tracks here or use Fill remaining.</div>';
      st.builder[side].forEach((id,i)=>{const t=trackById(id);if(!t)return;const r=document.createElement('div');r.className='side-track';r.draggable=true;r.dataset.id=id;r.dataset.side=side;r.dataset.index=String(i);r.innerHTML='<span class="drag-handle" aria-hidden="true">⋮⋮</span><span><strong>'+(i+1)+'. '+esc(t.title)+'</strong><small class="muted">'+time(t.duration)+' · '+esc(t.release||'Stonefellow')+'</small></span><span class="side-actions"><button type="button" aria-label="Move up">↑</button><button type="button" aria-label="Move down">↓</button><button type="button" aria-label="Move to other side">'+(side==='A'?'B':'A')+'</button><button type="button" aria-label="Remove">×</button></span>';const bs=$$('button',r);bs[0].onclick=()=>{move(side,i,-1);renderBuilder()};bs[1].onclick=()=>{move(side,i,1);renderBuilder()};bs[2].onclick=()=>transfer(id,side);bs[3].onclick=()=>{remove(id);renderBuilder()};r.ondragstart=e=>{r.classList.add('dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',JSON.stringify({id,side,index:i}))};r.ondragend=()=>r.classList.remove('dragging');l.appendChild(r)});
      c.ondragover=e=>{e.preventDefault();e.dataTransfer.dropEffect='move'};c.ondrop=e=>{e.preventDefault();try{const d=JSON.parse(e.dataTransfer.getData('text/plain')),target=e.target.closest('.side-track'),toIndex=target?Number(target.dataset.index):st.builder[side].length;if(relocateTrack(d.id,d.side,side,toIndex))renderBuilder()}catch(_){}};
    }
    $$('[data-fill-side]',canvas).forEach(b=>b.onclick=()=>fillBuild(b.dataset.fillSide));
    $$('[data-theme]',canvas).forEach(b=>b.onclick=()=>{st.builder.theme=b.dataset.theme;persist();renderBuilder()});
    $$('[data-vinyl-side]',canvas).forEach(b=>b.onclick=()=>{vinylArtSide=b.dataset.vinylSide;renderBuilder()});
    $$('[data-vinyl-image]',canvas).forEach(b=>b.onclick=()=>{
      const photo=vinylArtByRef(b.dataset.vinylImage);if(!photo)return;
      st.builder[vinylArtSide]=photo.reference;persist();renderBuilder();
      showBuilderNotice('Applied '+(photo.title||'photo')+' to your '+(vinylArtSide==='coverFront'?'front':'back')+' cover.','success');
    });
    const clearPhoto=$('#vinylClearPhoto',canvas);
    if(clearPhoto)clearPhoto.onclick=()=>{st.builder[vinylArtSide]='stonefellow';persist();renderBuilder()};
    $$('[data-builder-draft-load]',canvas).forEach(b=>b.onclick=()=>loadBuilderDraft(b.dataset.builderDraftLoad));
    $$('[data-builder-draft-manage]',canvas).forEach(b=>b.onclick=()=>openBuilderDraftModal(b.dataset.builderDraftManage));
    const title=$('#mixTitle',canvas);if(title)title.onchange=e=>{st.builder.title=e.target.value.trim()||'My Stonefellow Record';persist();renderBuilder()};
    const sl=$('.builder-song-list',canvas);catalog.forEach(t=>{const b=document.createElement('button');b.type='button';b.className='builder-song';const added=has(t.id),eligible=!!t.podEligible;b.disabled=added||!eligible;b.title=!eligible?'Not available for custom vinyl':added?'Already on your record':'Add to this record';b.innerHTML='<span><strong>'+esc(t.title)+'</strong><small>'+esc((t.mood||[]).join(' • '))+' • '+time(t.duration)+'</small></span><span>'+(added?'✓':eligible?'+':'Preview only')+'</span>';b.onclick=()=>{const r=add(t);if(r.ok){renderBuilder();say('Added “'+t.title+'” to Side '+r.side+'.')}else say(r.why==='full'?'That will not fit on either side.':'That track is already on your build.')};sl.appendChild(b)});
    $('#agentChoose',canvas).onclick=()=>fillBuild('both');$('#orderBuild',canvas).onclick=addBuildToCart;$('#saveBuild',canvas).onclick=()=>{void saveBuilderDraft(false);};$('#exportBuild',canvas).onclick=()=>{persist();download('stonefellow-'+norm(st.builder.title).replace(/ /g,'-')+'-build.json',{artist:'Stonefellow',...st.builder,tracks:{A:st.builder.A.map(trackById),B:st.builder.B.map(trackById)}});say('Exported your build as JSON.')};$('#clearBuild',canvas).onclick=()=>{if(!confirmBuilderReplacement())return;st.builder=defaultBuild();builderCleanFingerprint=builderDraftFingerprint(st.builder);st.builderDrafts.activeId=0;localStorage.removeItem('stonefellow.build.draft.id');persist();renderBuilder();say('Blank build. Start anywhere you want.')};const dd=$('#dedupeBuild',canvas);if(dd)dd.onclick=dedupeBuild;
    window.StonefellowTurntable?.mount?.({canvas,appState:st,catalog,releases,esc,persist,play,trackById,trackArtwork,say,navigate,money,renderBuilder,togglePlayback:()=>{if(st.track)toggle();else{const first=trackById(st.builder.A?.[0])||catalog[0];if(first)play(first,0,'record_builder');else say('Choose a song to start playback.');}},getVolume:()=>playerVolume(),setVolume:value=>setPlayerVolume(value)});
    if(!vinylArtState.loaded&&!vinylArtState.loading)void loadVinylArtCollections();
    if(st.auth.authenticated&&!st.builderDrafts.loaded)loadBuilderDrafts().then(()=>{const u=new URL(location.href);if((u.searchParams.get('view')||'home')==='builder')renderBuilder()});
  }

  async function quoteCart(){return api('quote.php',{cart:st.cart,campaign_code:st.campaignCode||''})}
  function recordConversionReview(stage){if(!st.auth.authenticated||!st.auth.csrf||!st.cart.length)return;secureJsonPost('fan-conversion-intent.php',{stage,cart:st.cart}).catch(()=>{});}
  async function renderCart(){await loadStore();if(!st.cart.length){canvas.innerHTML='<article class="module cart-module"><div class="module-kicker">YOUR CART</div><h2>Nothing here yet.</h2><p class="player-note">Buy a digital track or add your custom record/cassette.</p><button class="text-control" type="button" data-command="show me the catalog">Browse music</button></article>';bind(canvas);return}
    canvas.innerHTML='<article class="module cart-module"><div class="module-kicker">YOUR CART</div><h2>Review exactly what you’re ordering.</h2><p class="player-note">Custom-media track order below is the sequence that will be sent to manufacturing.</p><div class="cart-lines">'+st.cart.map(cartItemMarkup).join('')+'</div><div id="cartQuote" class="quote-box">Calculating…</div><div class="builder-footer"><button class="buy-primary" id="checkoutButton" type="button">Checkout</button><button class="text-control" id="clearCart" type="button">Clear cart</button></div></article>';$$('[data-remove]',canvas).forEach(b=>b.onclick=()=>{st.cart.splice(Number(b.dataset.remove),1);persistCart();renderCart()});$$('[data-merch-cart-qty]',canvas).forEach(q=>q.onchange=()=>{const i=Number(q.dataset.merchCartQty),line=st.cart[i];if(line?.type==='merch'){line.quantity=Math.max(1,Number(q.value||1));persistCart();renderCart()}});$('#clearCart',canvas).onclick=()=>{st.cart=[];persistCart();renderCart()};$('#checkoutButton',canvas).onclick=renderCheckout;try{const q=await quoteCart(),box=$('#cartQuote',canvas);recordConversionReview('cart_review');box.innerHTML='<div><span>Subtotal</span><strong>'+money(q.subtotal_cents)+'</strong></div><div><span>Shipping</span><strong>'+(q.shipping_cents?money(q.shipping_cents):'—')+'</strong></div><div><span>Member discount</span><strong>'+(q.member_discount_cents?'-'+money(q.member_discount_cents):'—')+'</strong></div><div><span>Campaign discount</span><strong>'+(q.campaign_discount_cents?'-'+money(q.campaign_discount_cents):'—')+'</strong></div><div class="quote-total"><span>Total</span><strong>'+money(q.total_cents)+'</strong></div>'+(st.campaignCode?'<small>Campaign code: '+esc(st.campaignCode)+'</small>':'')+(st.store.mode==='test'?'<small>Test checkout mode — no real charge will occur.</small>':'');}catch(e){
      const quote=$('#cartQuote',canvas),checkout=$('#checkoutButton',canvas);
      if(!quote||!checkout)return;
      const message=e.message==='server_error'||e.message==='quote_temporarily_unavailable'
        ?'We could not calculate your total. Your cart is saved; please retry.'
        :e.message;
      quote.innerHTML='<div class="cart-quote-error" role="alert"><span>'+esc(message)+'</span><button type="button" class="text-control" data-retry-quote>Retry total</button></div>';
      quote.querySelector('[data-retry-quote]')?.addEventListener('click',renderCart);
      checkout.disabled=true;
    }}

  function checkoutQuoteBreakdown(q){
    const amounts=[
      ['Subtotal',Number(q.subtotal_cents||0)],
      ['Shipping',Number(q.shipping_cents||0)],
      ['Tax',Number(q.tax_cents||0)]
    ];
    let html=amounts.map(([label,cents])=>'<div><span>'+label+'</span><strong>'+money(cents)+'</strong></div>').join('');
    for(const [label,key] of [['Member discount','member_discount_cents'],['Campaign discount','campaign_discount_cents']]){
      const cents=Number(q[key]||0);
      if(cents>0)html+='<div><span>'+label+'</span><strong>-'+money(cents)+'</strong></div>';
    }
    return '<div class="quote-box checkout-quote-review" aria-label="Order total breakdown">'+html+
      '<div class="quote-total"><span>Total due</span><strong>'+money(Number(q.total_cents||0))+'</strong></div></div>';
  }
  async function renderCheckout(){await loadStore();let q;try{q=await quoteCart()}catch(e){say(e.message,renderCart);return}recordConversionReview('checkout_review');const physical=q.physical,test=st.store.mode==='test',stripeTest=st.store.mode==='stripe_test',review=(q.items||[]).map(item=>item.type==='custom_media'?'<section class="checkout-build-review"><div class="module-kicker">CUSTOM '+esc(String(item.format||'').toUpperCase())+'</div><h3>'+esc(item.builder?.title||item.label||'Custom build')+'</h3><p class="player-note">This validated order is the manufacturing sequence.</p>'+quoteSequencePreview(item)+'</section>':item.type==='turntable_module'?'<div class="checkout-digital-row"><strong>'+esc(item.label||'Player upgrade')+'</strong><span>'+money(item.price_cents||0)+'</span></div>':item.type==='merch'?'<div class="checkout-digital-row checkout-merch-row"><strong>'+esc(item.label)+(item.variant_label?' · '+esc(item.variant_label):'')+' × '+Number(item.quantity||1)+'</strong><span>'+money(item.price_cents||0)+'</span></div>':'<div class="checkout-digital-row"><strong>'+esc(item.label||'Digital track')+'</strong><span>'+money(item.price_cents||0)+'</span></div>').join('');canvas.innerHTML='<article class="module checkout-module"><div class="module-kicker">CHECKOUT</div><h2>'+(test?'Simulate a purchase':'Order details')+'</h2><p class="player-note">'+(test?'Use cash simulation to exercise the full purchase, order, and POD workflow without charging anything.':(stripeTest?'Stripe TEST checkout: no real money will be collected.':'Payment will be handled securely by Stripe Checkout.'))+'</p><div class="checkout-order-review">'+review+'</div><form id="checkoutForm"><div class="checkout-grid"><label>Name<input name="name" autocomplete="name" required maxlength="100" value="'+esc(st.auth.user?.display_name||'')+'"></label><label>Email<input name="email" type="email" autocomplete="email" required maxlength="180" value="'+esc(st.auth.user?.email||'')+'"></label>'+(physical?'<label class="wide">Shipping address<input name="line1" autocomplete="address-line1" required maxlength="120"></label><label class="wide">Address line 2<input name="line2" autocomplete="address-line2" maxlength="120"></label><label>City<input name="city" autocomplete="address-level2" required maxlength="80"></label><label>State / region<input name="region" autocomplete="address-level1" required maxlength="80"></label><label>Postal code<input name="postal" autocomplete="postal-code" required maxlength="32"></label><label>Country<input name="country" value="US" autocomplete="country" required maxlength="2"></label>':'')+'</div>'+(test?'<fieldset class="payment-methods"><legend>Payment method</legend><label class="payment-choice"><input type="radio" name="payment_method" value="cash" checked><span><strong>Pay with cash</strong><small>Simulation only — marks the order paid and creates normal fulfillment records.</small></span></label><label class="payment-choice"><input type="radio" name="payment_method" value="test"><span><strong>Generic test payment</strong><small>Existing test checkout behavior.</small></span></label></fieldset>':'')+checkoutQuoteBreakdown(q)+'<p class="player-note">Prices are confirmed again when you place your order. You remain in control of payment confirmation.</p><div class="checkout-review-actions"><button type="button" class="text-control" id="returnToCart">Review cart and edit items</button></div><div id="checkoutError" class="error-text" role="alert"></div><button class="buy-primary" type="submit">'+(test?'Complete simulated purchase':'Continue to payment')+'</button></form></article>';$('#checkoutForm',canvas).onsubmit=submitOrder;$('#returnToCart',canvas).onclick=renderCart}

  async function submitOrder(e){e.preventDefault();const f=new FormData(e.currentTarget),btn=$('button[type="submit"]',e.currentTarget),err=$('#checkoutError',canvas);btn.disabled=true;err.textContent='';const customer={name:f.get('name'),email:f.get('email'),shipping:{line1:f.get('line1')||'',line2:f.get('line2')||'',city:f.get('city')||'',region:f.get('region')||'',postal:f.get('postal')||'',country:f.get('country')||'US'}},payment_method=f.get('payment_method')||(st.store?.mode==='test'?'test':'provider'),request_id=e.currentTarget.dataset.requestId||(e.currentTarget.dataset.requestId='checkout_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,12));try{const r=await secureJsonPost('order.php',{cart:st.cart,customer,payment_method,request_id,campaign_code:st.campaignCode||''});
    if(r.payment_provider==='stripe'){
      if(r.status==='paid'){localStorage.setItem('stonefellow.lastorder.v051',JSON.stringify(r));renderConfirmation(r);return;}
      if(!/^https:\/\/checkout\.stripe\.com\//.test(String(r.checkout_url||'')))throw new Error('Payment provider returned an invalid checkout address.');
      localStorage.setItem('stonefellow.pending.stripe',JSON.stringify({id:r.order_id,token:r.receipt_token}));
      window.location.assign(r.checkout_url);return;
    }
    localStorage.setItem('stonefellow.lastorder.v051',JSON.stringify(r));window.StonefellowTurntable?.invalidate?.();st.cart=[];st.campaignCode='';localStorage.removeItem('stonefellow.campaign.code');persistCart();renderConfirmation(r)}catch(ex){err.textContent=ex.message;btn.disabled=false}}
  function renderConfirmation(r){const cash=r.payment_method==='cash'||r.status==='paid_cash_simulated';canvas.innerHTML=`<article class="module confirmation-module"><div class="module-kicker">${cash?'CASH PURCHASE SIMULATED':'ORDER CREATED'}</div><h2>${esc(r.order_id)}</h2><p class="player-note">${cash?'Cash payment simulated successfully. No money was collected; the normal order and fulfillment records were created.':r.mode==='test'?'Test payment approved. The order record and POD handoff were written on the server.':'Your order was created and is awaiting payment.'}</p><div class="confirmation-data"><div><span>Status</span><strong>${esc(r.status)}</strong></div><div><span>Payment</span><strong>${esc(r.payment_method||'test')}</strong></div><div><span>Total</span><strong>${money(r.total_cents)}</strong></div><div><span>POD handoffs</span><strong>${r.pod?.length||0}</strong></div></div><p class="tiny-note">Keep this order number for testing. Simulated cash orders are never real charges.</p><button class="text-control" id="downloadReceipt" type="button">Download test receipt</button></article>`;$('#downloadReceipt',canvas).onclick=()=>download(r.order_id+'-receipt.json',r);say('Order '+r.order_id+' is recorded'+(cash?' as a simulated cash purchase':'')+'. '+(r.pod?.length?'The manufacturing handoff is ready.':''))}

  function bind(r=document){$$('[data-command]',r).forEach(b=>b.onclick=()=>command(b.dataset.command));$$('[data-view]',r).forEach(b=>b.onclick=e=>{
    if(e)e.preventDefault();const view=b.dataset.view||'home';
    // Top-level links now begin in the lightweight drawer. The dedicated
    // detail/full-page actions still go through the native modal renderer.
    if(window.SFPublicAgentCanvas?.active?.()&&canvas?.id!=='sfAgentNativePage'){
      const quickViews={music:['music','songs'],releases:['music','albums'],store:['commerce','merch'],tickets:['commerce','tickets'],cart:['commerce','cart']};
      if(quickViews[view]){openExperienceDrawer(...quickViews[view]);return;}
    }
    navigate(view);
  })}
  async function publicKnowledgeAnswer(q){try{const r=await fetch((cfg.apiBase||'api')+'/knowledge-search.php?q='+encodeURIComponent(q),{cache:'no-store'}),j=await r.json();if(j.ok&&j.hits?.length){const h=j.hits[0];say('From the Stonefellow knowledge base: '+(h.snippet||h.name));return true}}catch(_){}return false}
  function releaseAnswer(q){const n=norm(q);const r=releases.find(x=>n.includes(norm(x.title)))||releases.find(x=>(x.state||'published')==='published'&&x.agent_discoverable!==false);if(!r)return false;openRelease(r.id,true);say('Here is “'+r.title+'.”');return true}
  async function command(raw){const text=String(raw||'').trim(),q=norm(text);if(!text||handleAgentVisibilityCommand(text))return;
    if(canvasProjects()?.handleCommand(text)){popupEntry('user',text);input.value='';return;}
    if(/^\/cancel$/i.test(text)&&reuseCanvasImagesOnly){reuseCanvasImagesOnly=null;input.value='';return;}
    const imageSelection=canvasImageEditDraft;
    if(imageSelection&&/^(?:\/cancel|cancel(?: (?:the )?image edit)?)[.!?]?$/i.test(text)){
      setCanvasImageEditDraft(null);input.value='';return;
    }
    if(imageSelection&&/^\/(?!edit(?:\s|$))/i.test(text)){
      popupEntry('notice','Describe the image change or cancel the selected image edit.');return;
    }
    const imageBrief=imageSelection?text.replace(/^\/edit(?:\s+|$)/i,'').trim():'';
    if(imageSelection&&!imageBrief){popupEntry('notice','Describe what you want to change in the selected image.');return;}
    if(imageSelection)popupEntry('user',text,{canvasBrief:true});
    else popupEntry('user',text);
    hide();input.value='';closeMenu();
    if(imageSelection){await handleListeningRoomCommand({kind:'edit_image',selection:imageSelection,brief:imageBrief});return;}
    const listeningRoomCommand=directListeningRoomCommand(text);
    if(listeningRoomCommand){await handleListeningRoomCommand(listeningRoomCommand);return;}
    const atmosphereChoice=window.SFPublicAgentCanvas?.active?.() && window.SFPublicAgentCanvas.applyCommand?.(text);
    if(atmosphereChoice){say('Atmosphere set to '+({ember:'Ember',mesh:'Blue Mesh',violet:'Violet Signal'}[atmosphereChoice])+'.');return}
    if(/^(?:(?:start|show|open|take me through)\s+(?:the\s+)?)?(?:guided\s+)?(?:tour|tutorial|walkthrough)(?:\s+of\s+(?:the\s+)?(?:canvas|site))?$/i.test(text)){
      window.SFPublicAgentCanvas?.startGuide?.();return;
    }
    if(isTemporaryPlaylist()&&/^(?:please\s+)?save\s+(?:(?:this|my|the|current)\s+)?(?:playlist|mix)(?:\s+for\s+later)?[.!?]?$/i.test(text)){
      await say(await saveTemporaryPlaylist());return;
    }
    if(!st.auth.authenticated&&nameFromTurn(text)){await acceptVisitorName(text);return}
    if(welcomeWaitingForName&&/^(?:skip|not now|prefer not to say)$/i.test(text)){await skipVisitorName();return}
    if(/^(?:(?:play|listen to|put on|show me)\s+(?:the\s+)?)?(?:latest|newest)\s+release(?:\s+music)?[.!?]?$/i.test(text)){
      await handleWelcomeAction('latest-release',false);return;
    }
    const named=findTitle(q);if(directSongListeningCommand(text)||directRandomPlaylistCommand(text)){await liveAgent(text);return}await loadAuth();if(st.auth.authenticated){await liveAgent(text);return}
    if(q.includes('artist world')||q.includes('explore the world')||q.includes('connected stories')){navigate('world');return}if(q.includes('community')){if(fanCommunityEnabled){navigate('community')}else{say('The fan community is not open yet. CRM and the Stonefellow newsletter are still active.',()=>navigate('newsletter'))}return}if(q.includes('newsletter')||q.includes('mailing list')){navigate('newsletter');return}if(q.includes('merch')||q.includes('shop')||q.includes('store')){navigate('store');return}if(q.includes('ticket')||q.includes('rsvp')||q.includes('vip')||q.includes('presale')){navigate('tickets');return}if(q.includes('tour')||q.includes('show dates')||q.includes('shows')){navigate('shows');return}
    if(q.includes('album')||q.includes('release')||q.includes('ep')||q.includes('single')){if(releaseAnswer(q))return}
    if(q.includes('knowledge')||q.includes('notes')||q.includes('document')||q.includes('photo')||q.includes('image')){if(await publicKnowledgeAnswer(text))return}
    if(q.includes('login')||q.includes('sign in')){say('Here is the login.',()=>navigate('login'));return}
    if(q.includes('create account')||q.includes('register')){say('You can create an account without interrupting playback.',()=>navigate('register'));return}if(q.includes('plan')||q.includes('package')||q.includes('subscription')||q.includes('free trial')||q.includes('active token')||q.includes('tokens')){say('Here are the Stonefellow plans and Active Token options.',()=>navigate('plans'));return}
    if(q.includes('my account')||q.includes('my purchases')||q.includes('my library')){say('Here is your Stonefellow account.',()=>navigate('account'));return}
    if(q.includes('cart')||q.includes('checkout')||q.includes('buy my record')||q.includes('buy my cassette')){say('Here is your cart.',()=>navigate('cart'));return}
    if((q.includes('buy')||q.includes('purchase'))&&named){addTrackToCart(named);return}
    if((q.includes('buy')||q.includes('purchase'))&&st.track){addTrackToCart(st.track);return}
    if((q.includes('play')||q.startsWith('listen'))&&named){play(named);return}
    if(q.includes('play this song')&&st.track){play(st.track);return}
    if(q.includes('something like this')||q.includes('similar')){play(related(st.track));return}
    if((q.includes('make')||q.includes('create')||q.includes('continue'))&&(q.includes('record')||q.includes('vinyl')||q.includes('cassette')||q.includes('mixtape'))){st.builder.format='vinyl';if(st.track&&q.includes('this song'))add(st.track);persist();say(st.track&&q.includes('this song')?'I started the build with “'+st.track.title+'.”':'Here is your current build. Pick songs yourself or let me sequence it.',()=>navigate('builder'));return}
    if(q.includes('side a')||q.includes('side b')){const side=q.includes('side b')?'B':'A',names=st.builder[side].map(id=>trackById(id)?.title).filter(Boolean);say(names.length?'Side '+side+' is '+names.join(', ')+'. Total time '+time(sideTime(side))+'.':'Side '+side+' is empty.',()=>navigate('builder'));return}
    if(q.includes('catalog')||q.includes('look around')||q==='music'||q.includes('show songs')){say('Here is the catalog. Search it, choose a mood, or ask me naturally.',()=>navigate('music'));return}
    if(q.includes('who wrote')||q.includes('who produced')||q.includes('isrc')||q.includes('bmi')||q.includes('ascap')||q.includes('credits')){const t=named||st.track||catalog[0],m=t.metadata||{};let answer='Here are the credits I have for “'+t.title+'.”';if(q.includes('who wrote'))answer=(m.words_by||m.music_by)?'“'+t.title+'” credits '+[m.words_by&&'words by '+m.words_by,m.music_by&&'music by '+m.music_by].filter(Boolean).join(' and ')+'.':'Writer metadata has not been added for this recording yet.';else if(q.includes('who produced'))answer=m.producer?'“'+t.title+'” was produced by '+m.producer+(m.co_producer?' with '+m.co_producer:'')+'.':'Producer metadata has not been added for this recording yet.';else if(q.includes('isrc'))answer=m.isrc?'The ISRC for “'+t.title+'” is '+m.isrc+'.':'No ISRC has been assigned to this recording yet.';else if(q.includes('bmi')||q.includes('ascap'))answer=m.pro_affiliation?'The PRO listed for “'+t.title+'” is '+m.pro_affiliation+'.':'No PRO affiliation has been added for this recording yet.';say(answer,()=>{renderStory(t);setUrl('track',{id:t.id})});return}
    if(q.includes('tell me about')||q.includes('story')||q.includes('what is this')||q.includes('whats this')){const t=named||st.track||catalog[0];say('Here is the context I have for “'+t.title+'.”',()=>{renderStory(t);setUrl('track',{id:t.id})});return}
    if(q.includes('about stonefellow')){say('Stonefellow is David Evans’ music project. This site is a listening room where the agent helps you explore the catalog, create custom physical releases, and buy music directly.',()=>navigate('about'));return}
    if(q.includes('recommend')||q.includes('what should')||q.includes('play')||q.includes('listen')||q.includes('quiet')||q.includes('mellow')||q.includes('driving')||q.includes('dark')||q.includes('warm')||q.includes('acoustic')){play(recommend(q,st.track?[st.track.id]:[]));return}
    if(q.includes('add')&&named){const r=add(named);say(r.ok?'Added “'+named.title+'” to Side '+r.side+'.':r.why==='full'?'That one will not fit on either side.':'That track is already on your build.',renderBuilder);return}
    await liveAgent(text)}

  function closeQuickMenu(restoreFocus=false){if(!quickMenu)return;quickMenu.hidden=true;quickButton?.setAttribute('aria-expanded','false');if(restoreFocus)quickButton?.focus({preventScroll:true})}
  function openQuickMenu(){if(!quickMenu)return;closeMenu();closeChatPopup();quickMenu.hidden=false;quickButton?.setAttribute('aria-expanded','true');quickMenu.querySelector('[data-quick-action]')?.focus({preventScroll:true})}
  function handleQuickAction(action){closeQuickMenu();if(action==='record'){navigate('builder');return}if(action==='playlist'){void startRandomTemporaryPlaylist().then(result=>popupEntry('notice',result));return}if(action==='shows'){navigate('shows');return}if(action==='store'){window.SFPublicAgentCanvas?.active?.()?openExperienceDrawer('commerce','merch'):navigate('store');return}if(action==='membership'){navigate('membership');return}if(action==='tickets'){window.SFPublicAgentCanvas?.active?.()?openExperienceDrawer('commerce','tickets'):navigate('tickets');return}if(action==='community'){fanCommunityEnabled?navigate('community'):navigate('newsletter');return}if(action==='newsletter'){navigate('newsletter');return}}
  // Both screen sizes launch with the composer hidden. Desktop zzz and the
  // small restore chip can reveal it; mobile users have an explicit hide X.
  function setChatFooterHidden(hidden){
    if(hidden){setCanvasImageEditDraft(null);closeChatPopup();closeQuickMenu()}
    document.body.classList.toggle('sf-agent-chatbar-collapsed',!!hidden);
  }
  // The server renders the collapsed class before CSS and JS arrive, so the
  // footer cannot flash on first paint. A zzz reveal lasts for this page only.
  document.body.classList.add('sf-agent-chatbar-collapsed');
  if(chatFooterRestore)chatFooterRestore.onclick=()=>setChatFooterHidden(false);
  if(chatFooterHide)chatFooterHide.onclick=()=>setChatFooterHidden(true);
  let zSequence=0,lastZ=0;
  window.addEventListener('keydown',event=>{
    if(!document.body.classList.contains('sf-agent-canvas-active')||!window.matchMedia('(min-width:721px)').matches)return;
    if(!manualAgentHidden&&document.body.classList.contains('sf-agent-hidden-by-scene')){zSequence=0;return;}
    if(event.repeat||event.ctrlKey||event.metaKey||event.altKey||event.target?.closest?.('input,textarea,select,[contenteditable="true"],[role="textbox"]')){zSequence=0;return}
    if(event.key.toLowerCase()!=='z'){zSequence=0;return}
    const now=Date.now();zSequence=now-lastZ<1000?zSequence+1:1;lastZ=now;
    if(zSequence===3){zSequence=0;event.preventDefault();setChatFooterHidden(!document.body.classList.contains('sf-agent-chatbar-collapsed'))}
  },true);
  bindCanvasDebug();
  bindChatImagePicker();
  bindChatMusicPicker();
  bindChatResourcePicker();
  form.onsubmit=submitAgentChat;if(quickButton)quickButton.onclick=e=>{e.stopPropagation();quickMenu?.hidden?openQuickMenu():closeQuickMenu()};if(chatToggle)chatToggle.onclick=()=>chatPopup?.hidden?openChatPopup():closeChatPopup();if(chatPopupClose)chatPopupClose.onclick=closeChatPopup;if(chatPopupNew)chatPopupNew.onclick=()=>{popupHistoryEpoch++;st.conversationId='';localStorage.removeItem('stonefellow.agent.conversation');popupEntries=[];popupHistoryLoaded=true;renderPopupEntries();input.focus()};if(orb)orb.onclick=()=>{if(document.body.classList.contains('artist-home-chat-hidden')){document.body.classList.remove('artist-home-chat-hidden');syncAgentDockLayout()}chatPopup?.hidden?openChatPopup():closeChatPopup()};if(agentInterrupt)agentInterrupt.onclick=interruptVoicePlayback;$$('[data-quick-action]',quickMenu||document).forEach(b=>b.onclick=()=>handleQuickAction(b.dataset.quickAction));document.addEventListener('click',e=>{if(quickMenu&&!quickMenu.hidden&&!quickMenu.contains(e.target)&&e.target!==quickButton)closeQuickMenu()});bind();bindAgentVisibilityMenu();syncAuthMenu();syncAgentDockLayout();
  function updateVoiceReplyToggle(){if(!voiceReplyToggle)return;voiceReplyToggle.textContent=voiceRepliesEnabled?'Voice on':'Voice off';voiceReplyToggle.setAttribute('aria-pressed',String(voiceRepliesEnabled));voiceReplyToggle.title=voiceRepliesEnabled?'Mute spoken Agent replies':'Enable spoken Agent replies'}
  updateVoiceReplyToggle();
  function setVoiceRepliesEnabled(enabled,persist=true){
    if(voiceRepliesEnabled===enabled)return;
    voiceRepliesEnabled=enabled;
    if(persist)localStorage.setItem('stonefellow.voice.replies.v1',enabled?'on':'off');
    if(!enabled)interruptVoicePlayback();
    if(speechPilotActive){
      window.StonefellowSpeechEngine?.setOutputMuted?.(!enabled);
      setSpeechPilotSpeaking(enabled&&speechPilotReplyInProgress,true);
    }
    updateVoiceReplyToggle();
  }
  if(voiceReplyToggle)voiceReplyToggle.onclick=()=>setVoiceRepliesEnabled(!voiceRepliesEnabled);
  window.addEventListener('storage',event=>{
    if(event.key==='stonefellow.voice.replies.v1')setVoiceRepliesEnabled(event.newValue!=='off',false);
  });
  $('#homeLink').onclick=e=>{e.preventDefault();navigate('home')};const menuQueueLink=$('#menuQueueLink');if(menuQueueLink)menuQueueLink.onclick=e=>{e.preventDefault();closeMenu();openQueue()};
  bindCanvasCodingModelMenu();
  window.SFTokenMeter?.create?.({getOwner:canvasOwner,read:signal=>api('token-meter.php',undefined,signal)});
  if(menu?.tagName==='DETAILS'){menu.addEventListener('toggle',()=>{
    $('#menuButton')?.setAttribute('aria-expanded',String(menu.open));
    window.SFTokenMeter?.setOpen?.(menu.open);
    if(menu.open){closeQuickMenu();void refreshCanvasCodingModelMenu();}
  })}else{$('#menuButton').onclick=()=>{const open=!menu.classList.contains('open');menu.classList.toggle('open',open);menu.setAttribute('aria-hidden',String(!open));$('#menuButton').setAttribute('aria-expanded',String(open));window.SFTokenMeter?.setOpen?.(open);if(open)void refreshCanvasCodingModelMenu()}};$('#menuClose').onclick=closeMenu;
  document.addEventListener('click',event=>{
    if(menu?.open&&!event.composedPath().includes(menu))closeMenu();
  });
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&menu?.open){event.preventDefault();closeMenu();$('#menuButton')?.focus({preventScroll:true})}
  });
  menu?.addEventListener('click',event=>{
    const choice=event.target.closest?.('[data-sf-atmosphere]');
    if(!choice)return;
    if(window.SFPublicAgentCanvas?.setAtmosphere?.(choice.dataset.sfAtmosphere))closeMenu();
  });
  if(notificationDrawer){
    if(notificationButton)notificationButton.onclick=openNotificationDrawer;
    notificationClose.onclick=closeNotificationDrawer;
    if(notificationBackdrop)notificationBackdrop.onclick=closeNotificationDrawer;
    $$('[data-drawer-tab]',notificationDrawer).forEach(button=>button.onclick=()=>{st.drawer.tab=button.dataset.drawerTab;renderDrawer()});
    const markAll=$('#markAllNotifications');if(markAll)markAll.onclick=markAllNotifications;
    const refresh=$('#refreshNotifications');if(refresh)refresh.onclick=loadNotificationDrawer;
    if(st.auth.authenticated){void loadNotificationDrawer();setInterval(loadNotificationDrawer,60000)}
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!notificationDrawer.hidden){event.preventDefault();event.stopPropagation();closeNotificationDrawer()}});
  }
  playerToggle.onclick=toggle;playerPrev.onclick=()=>stepTrack(-1);playerNext.onclick=()=>stepTrack(1);if(playerQueueButton)playerQueueButton.onclick=openQueue;if(queueClose)queueClose.onclick=closeQueue;if(queueBackdrop)queueBackdrop.onclick=closeQueue;if(queueClear)queueClear.onclick=()=>clearQueue(true);playerClose.onclick=()=>{songCaptureSkill?.discard();if(songListeningSkill?.status().active)void songListeningSkill.stop('switch');invalidatePlayback();audio.pause();audio.removeAttribute('src');audio.load();st.track=null;st.playing=false;st.playSource='player';songMoments?.setTrack(null);clearPlaylistPlayback();footerPlayer.hidden=true;window.SFPublicAgentCanvas?.syncPlayer?.();document.body.classList.remove('has-player');agent('idle','Player closed. Your place in the site is unchanged.')};
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&queueDrawer&&!queueDrawer.hidden){event.preventDefault();closeQueue()}});
  playerTitle.onclick=playerArtwork.onclick=()=>{if(st.track){renderStory(st.track);setUrl('track',{id:st.track.id})}};
  playerSeek.oninput=()=>seekPlayback(Number(playerSeek.value)/1000);
  listeningTelemetryMain();
  audio.addEventListener('loadedmetadata',updateFooterPlayer);
  audio.addEventListener('timeupdate',()=>{rememberPlaybackPosition();songCaptureSkill?.playbackPosition();playerCurrent.textContent=time(audio.currentTime);playerDuration.textContent=time(audio.duration||st.track?.duration||0);playerSeek.value=audio.duration?String(Math.round((audio.currentTime/audio.duration)*1000)):'0';const now=Date.now();if(st.track&&st.playing&&now-st.lastProgressAt>30000){st.lastProgressAt=now;recordListen('progress')}});
  audio.addEventListener('seeked',rememberPlaybackPosition);
  audio.addEventListener('seeking',()=>{if(songCaptureSkill?.status().active)songCaptureSkill.discard();if(songListeningSkill?.status().active)void songListeningSkill.stop('seek')});
  audio.addEventListener('waiting',()=>{
    if(!playbackWantsAudio||audio.paused||!st.track)return;
    clearPlaybackBufferWatch();setPlaybackStatus('Buffering audio…','buffering');
    const token=playbackLifecycle.token(),at=Number(audio.currentTime)||0;
    playbackBufferTimer=setTimeout(()=>{
      playbackBufferTimer=null;
      if(playbackLifecycle.isCurrent(token)&&playbackWantsAudio&&!audio.paused&&Math.abs((Number(audio.currentTime)||0)-at)<0.25)
        setPlaybackStatus('Still buffering · checking the connection','buffering');
    },9000);
  });
  audio.addEventListener('stalled',()=>{
    if(playbackWantsAudio&&!audio.paused&&st.track)setPlaybackStatus('Audio connection stalled · waiting to resume','buffering');
  });
  audio.addEventListener('playing',()=>{clearPlaybackBufferWatch();setPlaybackStatus()});
  audio.addEventListener('canplay',()=>{if(!pendingNetworkRecovery){clearPlaybackBufferWatch();if(footerPlayer?.dataset.playbackPhase==='buffering')setPlaybackStatus()}});
  audio.addEventListener('play',()=>{st.playing=true;updateFooterPlayer();updateQueueUi();syncConversationMusic();scheduleSpeechContextSync()});
  audio.addEventListener('pause',()=>{if(!audio.ended){st.playing=false;updateFooterPlayer()}updateQueueUi();if(!pendingNetworkRecovery){if(songCaptureSkill?.status().active)void songCaptureSkill.stop('pause');if(songListeningSkill?.status().active)void songListeningSkill.stop('pause')}syncConversationMusic();scheduleSpeechContextSync()});
  audio.addEventListener('ended',()=>{
    if(pendingNetworkRecovery)return;
    const endedToken=playbackLifecycle.token();
    if(!st.track||!playbackLifecycle.claimEnded(endedToken))return;
    playbackWantsAudio=false;clearPlaybackBufferWatch();cancelNetworkRecovery();setPlaybackStatus();syncConversationMusic();
    if(songCaptureSkill?.status().active)void songCaptureSkill.stop('end');if(songListeningSkill?.status().active)void songListeningSkill.stop('end');
    if(playbackEndedEarly()){
      st.playing=false;updateFooterPlayer();
      setPlaybackStatus('Audio ended early · check Media Health','error');
      agent('idle','“'+(st.track?.title||'This song')+'” ended at '+time(audio.currentTime)+' of the published '+time(st.track.duration)+'. The uploaded audio file or stream may be incomplete. Check Media Health.');
      return;
    }
    recordListen('complete');st.playing=false;updateFooterPlayer();
    if(agentSessionActive()){advanceAgentSession('complete',endedToken).then(session=>{if(!session||!playbackLifecycle.isCurrent(endedToken))return;if(session.status==='active'){const ids=sessionTrackIds(),idx=Number(session.current_index||0),next=trackById(ids[idx]);st.playlistPlayback.index=idx;st.playlistPlayback.trackIds=ids;if(next){const previous=st.track,comment=st.agentSession.mode==='guided_release'&&previous?.story?'That was “'+previous.title+'.” '+String(previous.story).slice(0,180):'Next up: “'+next.title+'.”';agent('thinking',comment);setTimeout(()=>{if(playbackLifecycle.isCurrent(endedToken))void play(next,0,'agent_session',true)},650)}renderAgentSessionPanel()}else{clearPlaylistPlayback();renderAgentSessionPanel();if(st.queue.items.length){void playNextFromQueue(endedToken);return}agent('idle','Finished “'+st.agentSession.title+'.” You can save it as a playlist from the listening session panel.')}});return}
    if(st.playlistPlayback.playlistId&&st.playlistPlayback.trackIds.length&&st.playlistPlayback.index<st.playlistPlayback.trackIds.length-1){st.playlistPlayback.index++;updateQueueUi();const t=trackById(st.playlistPlayback.trackIds[st.playlistPlayback.index]);if(t){play(t,0,isTemporaryPlaylist()?'temporary_playlist':'playlist',true);return}}
    if(isTemporaryPlaylist()&&st.queue.items.length===0){updateQueueUi();agent('idle','Finished “'+st.playlistPlayback.playlistName+'.” Open the playlist icon to replay any song.');return}
    const finished=st.playlistPlayback.playlistName;clearPlaylistPlayback();if(st.queue.items.length){void playNextFromQueue(endedToken);return}agent('idle',finished?'Finished “'+finished+'.”':'That was “'+(st.track?.title||'the song')+'.” Want another, the story, or should we put it on a record?')
  });
  audio.addEventListener('error',()=>{
    if(!st.track)return;
    if(recoverNetworkPlayback())return;
    songCaptureSkill?.discard();if(songListeningSkill?.status().active)void songListeningSkill.stop('error');
    const code=Number(audio.error?.code||0);
    playbackProblem(code===3?'Audio decode failed · check Media Health':code===4?'Audio source unavailable · check Media Health':'Audio connection failed · tap Play to retry.');
    agent('idle','The player could not finish loading “'+st.track.title+'.” Review its audio in Media Health.');
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&chatPopup&&!chatPopup.hidden&&!document.body.classList.contains('sf-agent-hidden-by-scene')){closeChatPopup();chatToggle?.focus();return}const tag=String(e.target?.tagName||'').toLowerCase(),editing=['input','textarea','select'].includes(tag)||e.target?.isContentEditable;if((e.key==='/'&&!editing)||((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k')){e.preventDefault();if(new URL(location.href).searchParams.get('view')!=='search'&&!String(location.pathname).replace(/\/+$/,'').endsWith('/search'))navigate('search',{});else $('#siteSearchInput',canvas)?.focus()}});
  window.addEventListener('popstate',routeFromLocation);
  const R=window.SpeechRecognition||window.webkitSpeechRecognition;
  const isIOSVoiceBrowser=/iPad|iPhone|iPod/i.test(navigator.userAgent||'')||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
  const voiceEngineKey='stonefellow.voice.engine.v1.'+String(window.STONEFELLOW_AUTH_BOOTSTRAP?.user?.id||'guest');
  let speechContextInterval=null,speechContextDebounce=null,speechContextInFlight=false,speechContextConversationId='',speechContextEpoch=0;
  async function pushSpeechContext(){
    if(!speechPilotActive||!window.StonefellowSpeechEngine?.active||speechContextInFlight)return;
    speechContextInFlight=true;const epoch=speechContextEpoch;
    try{
      const result=await secureJsonPost('speech-engine.php',{
        action:'sync_context',conversation_id:speechContextConversationId,client_state:agentClientState()
      });
      if(epoch===speechContextEpoch&&speechPilotActive&&typeof result.conversation_id==='string')
        speechContextConversationId=result.conversation_id;
    }
    catch(error){console.warn('[Stonefellow] Live voice context unavailable:',error?.message||'sync failed')}
    finally{speechContextInFlight=false}
  }
  function scheduleSpeechContextSync(delay=240){
    if(!speechPilotActive||!window.StonefellowSpeechEngine?.active)return;
    clearTimeout(speechContextDebounce);
    speechContextDebounce=setTimeout(()=>{speechContextDebounce=null;void pushSpeechContext()},delay);
  }
  function startSpeechContextSync(){
    stopSpeechContextSync();scheduleSpeechContextSync(0);
    speechContextInterval=setInterval(()=>void pushSpeechContext(),9000);
  }
  function stopSpeechContextSync(){
    speechContextEpoch++;speechContextConversationId='';
    if(speechContextInterval!==null)clearInterval(speechContextInterval);
    speechContextInterval=null;clearTimeout(speechContextDebounce);speechContextDebounce=null;
  }
  // iOS Safari's SpeechRecognition owns the microphone. Do not open another
  // getUserMedia/AudioContext stream concurrently for barge-in monitoring.
  const musicWakePhrase=/^(?:(?:hey|hi|okay|ok)\s+)?stone[\s-]?fellow(?:\s*[,.:!?]\s*|\s+|$)/i;
  function shouldDuckConversationInput(recognized=''){
    if(!st.conversationOn||voiceTurnBusy||voicePlaybackPending||voiceSpeechGuard?.blocked)return false;
    // Song vocals and speaker echo can activate microphone speech detection.
    // During music, wait for recognized words, then apply the wake or follow-up rules.
    if(st.playing&&!audio.paused)return !!recognized&&(Date.now()<musicFollowupUntil||musicWakePhrase.test(recognized));
    return true;
  }
  function updateConversationButton(){
    const listening=st.conversationOn&&!iosVoiceNeedsGesture;
    voice.classList.toggle('active',listening);
    voice.setAttribute('aria-pressed',String(listening));
    voice.setAttribute('aria-label',iosVoiceNeedsGesture?'Resume Voice Chat':st.conversationOn?'End Voice Chat':'Start Voice Chat');
    voice.title=iosVoiceNeedsGesture?'Microphone paused · tap to resume':st.conversationOn?'Conversation active · click to stop':'Start hands-free conversation';
    window.SFPublicAgentCanvas?.syncVoice?.();
  }
  function listeningPrompt(){return st.playing&&!audio.paused?(Date.now()<musicFollowupUntil?'Listening · ask a follow-up.':'Listening · say “Stonefellow” to talk over music.'):'Listening · speak naturally.'}
  function greetAfterMicrophoneReady(){
    if(!voiceGreetingArmed)return false;
    voiceGreetingArmed=false;
    // Never greet on page load, denied permission, a recognition restart,
    // a muted session, or a browser where the user disabled greetings.
    if(voiceGreetingDelivered||!voiceRepliesEnabled||localStorage.getItem('stonefellow.voice.greeting.v1')==='off')return false;
    voiceGreetingDelivered=true;
    if(!welcomeStarted)beginWelcome();
    const greeting=welcomeGreeting;
    showTranscript('Stonefellow: '+greeting,'responding');
    void agentSpeak(greeting);
    return true;
  }
  function scheduleConversationListen(delay=320){clearTimeout(conversationRestartTimer);if(!st.conversationOn||!recognition||voiceTurnBusy||isIOSVoiceBrowser)return;
    // Spoken output owns the microphone until its completion/cancel path
    // explicitly rearms recognition. Never poll a live reply in a timer loop.
    if(voicePlaybackPending)return;
    const remain=typeof voiceSpeechGuard!=='undefined'?(voiceSpeechGuard?.remaining()??0):0;
    // A preparing/speaking guard returns Infinity. Browsers clamp an infinite
    // setTimeout delay to ~1 ms, which otherwise creates a rapid restart loop.
    const guardDelay=Number.isFinite(remain)?Math.max(0,remain):500;
    conversationRestartTimer=setTimeout(startConversationListen,Math.max(delay,guardDelay,320));}
  function startConversationListen(){
    if(!st.conversationOn||!recognition||recognitionActive)return;
    if(voiceTurnBusy||voicePlaybackPending||(typeof voiceSpeechGuard!=='undefined'&&voiceSpeechGuard?.blocked)||['THINKING','RESPONDING','SPEAKING'].includes(status.textContent)){
      if(isIOSVoiceBrowser){iosVoiceNeedsGesture=true;updateConversationButton();showTranscript('Wait for Stonefellow to finish, then tap Resume Voice Chat.','waiting')}
      else scheduleConversationListen(450);
      return;
    }
    try{recognition.start()}catch(e){if(e.name==='InvalidStateError'){
      if(isIOSVoiceBrowser){iosVoiceNeedsGesture=true;updateConversationButton();showTranscript('Microphone paused · tap Resume Voice Chat.','waiting')}
      else scheduleConversationListen(600);
      return;
    }stopConversation();showTranscript(isIOSVoiceBrowser?'This browser could not start iPhone speech recognition. Check Siri in iPhone Settings, then reopen the browser and tap Start Voice Chat.':'The microphone could not start. Click the mic to retry.','error')}
  }
  function stopConversation(){
    voiceGreetingArmed=false;iosVoiceNeedsGesture=false;iosProcessedFinalIndex=-1;
    speechPilotEpoch++;speechPilotReplyInProgress=false;stopSpeechContextSync();
    const wasPilot=speechPilotActive;
    speechPilotActive=false;st.conversationOn=false;
    if(wasPilot)void window.StonefellowSpeechEngine?.stop();
    clearTimeout(conversationRestartTimer);
    if(!wasPilot)try{recognition?.abort()}catch(_){}
    recognitionActive=false;setVoiceInputActive(false);setSpeechPilotSpeaking(false,true);
    interruptVoicePlayback();musicFollowupUntil=0;syncConversationMusic();
    showTranscript('');updateConversationButton();agent(st.playing?'playing':'idle','Voice Chat is off.');
  }
  async function submitVoiceTurn(spoken){
    const turn=++voiceTurnGeneration;
    voiceTurnBusy=true;clearTimeout(conversationRestartTimer);setVoiceInputActive(false);
    // Closing iOS recognition here immediately loses the permission-backed
    // session. Keep it open, but ignore input throughout thinking and speech.
    if(!isIOSVoiceBrowser)try{recognition?.stop()}catch(_){}
    showTranscript('You: '+spoken,'heard');agent('thinking','Thinking…');
    try{await command(spoken);await pendingReply}catch(e){await say('I could not process that message. '+e.message)}
    finally{if(turn===voiceTurnGeneration){voiceTurnBusy=false;if(st.conversationOn&&!speechPilotActive){musicFollowupUntil=Date.now()+18000;if(iosVoiceNeedsGesture){agent('idle','Microphone paused.');showTranscript('Microphone paused · tap Resume Voice Chat.','waiting')}else{agent('listening','I’m ready for your next question.');showTranscript(listeningPrompt(),'listening');scheduleConversationListen()}}}}
  }
  function submitRecognizedVoiceTurn(spoken){
    const own=String(voiceSpeechGuard?.reference||'').toLowerCase().replace(/[^a-z0-9 ]/g,'').replace(/\s+/g,' ').trim();
    const heard=String(spoken||'').toLowerCase().replace(/[^a-z0-9 ]/g,'').replace(/\s+/g,' ').trim();
    if(Date.now()<voiceEchoUntil&&heard.length>=9&&own.includes(heard))return;
    const woke=musicWakePhrase.test(spoken),words=spoken.replace(musicWakePhrase,'').trim();
    if(st.playing&&!audio.paused&&!woke&&Date.now()>=musicFollowupUntil){
      showTranscript('Music is playing. Say “Stonefellow” followed by your question.','listening');
      return;
    }
    if(!words){musicFollowupUntil=Date.now()+12000;showTranscript('Yes? I’m listening.','listening');return}
    void submitVoiceTurn(words);
  }
  async function handleVoiceClick(){
    if(isIOSVoiceBrowser&&st.conversationOn&&iosVoiceNeedsGesture){
      if(voiceTurnBusy||voicePlaybackPending||voiceSpeechGuard?.blocked){
        showTranscript('Wait for Stonefellow to finish, then tap Resume Voice Chat.','waiting');return;
      }
      iosVoiceNeedsGesture=false;iosProcessedFinalIndex=-1;
      updateConversationButton();showTranscript('Reconnecting microphone…','connecting');startConversationListen();return;
    }
    if(st.conversationOn){stopConversation();return}
    beginWelcome();
    // Read the last server-synced preference without awaiting network I/O.
    // On iOS WebKit, SpeechRecognition/startSession must begin in the tap turn.
    if((window.STONEFELLOW_VOICE_ENGINE_BOOTSTRAP||localStorage.getItem(voiceEngineKey))==='speech_engine'){
      const epoch=++speechPilotEpoch;
      const isCurrent=()=>epoch===speechPilotEpoch&&speechPilotActive;
      voiceInteractionStarted=true;speechPilotActive=true;st.conversationOn=true;
      updateConversationButton();showTranscript('Connecting ElevenLabs Speech Engine…','connecting');agent('connecting','Connecting…');
      try{await window.StonefellowSpeechEngine.start({
        outputMuted:!voiceRepliesEnabled,
        onStatus(value){
          if(!isCurrent())return;
          if(value==='disconnected'){
            stopConversation();
            showTranscript('Voice Chat disconnected. Tap Start Voice Chat to reconnect.','waiting');
          }else if(value==='ready'){
            window.StonefellowSpeechEngine?.setOutputMuted?.(!voiceRepliesEnabled);
            startSpeechContextSync();agent('listening','Speech Engine connected.');showTranscript('Listening · Voice Chat','listening');
          }
        },
        onMode(mode){
          if(!isCurrent())return;
          speechPilotReplyInProgress=mode==='speaking';
          const audible=speechPilotReplyInProgress&&voiceRepliesEnabled;
          setSpeechPilotSpeaking(audible);
          window.StonefellowSpeechEngine?.setInputMuted?.(speechPilotReplyInProgress);
          agent(audible?'speaking':speechPilotReplyInProgress?'responding':'listening');
          showTranscript(audible?'Stonefellow is speaking…':speechPilotReplyInProgress?'Stonefellow is responding…':'Listening · Voice Chat',speechPilotReplyInProgress?'responding':'listening');
        },
        onMessage(data){if(!isCurrent())return;const content=String(data?.message||'').trim();if(!content)return;const user=data.role==='user'||data.source==='user';popupEntry(user?'user':'assistant',content);if(user&&!st.auth.authenticated){const name=nameFromTurn(content);if(name){saveVisitorName(name);welcomeWaitingForName=false;syncWelcomeActions()}}showTranscript((user?'You: ':'Stonefellow: ')+content.slice(0,240),user?'heard':'responding');scheduleSpeechContextSync();if(!user){if(typeof window!=='undefined')window.SFTokenMeter?.notifyUsage?.();void deliverSpeechEngineAction()}},
        onError(message){if(isCurrent()){popupEntry('notice',String(message));showTranscript(String(message),'error')}}
      });}catch(error){
        if(!isCurrent())return;
        stopConversation();
        agent('idle','Speech Engine unavailable.');
        const message=error.name==='NotAllowedError'?'Microphone access was blocked. Allow the microphone for this site, then tap Start Voice Chat again.'
          :error.name==='NotFoundError'?'This browser could not find a microphone for Voice Chat.'
          :error.name==='NotReadableError'?'The microphone is unavailable. Close other apps using it, then tap Start Voice Chat again.'
          :error.message||'Speech Engine could not connect. You can select the original voice engine in Account Settings.';
        showTranscript(message,'error');
      }
      return;
    }
    if(!recognition){showTranscript('Voice recognition is unavailable here. Select Speech Engine in Account Settings or type a message.','error');return}
    voiceInteractionStarted=true;st.conversationOn=true;voiceGreetingArmed=true;iosVoiceNeedsGesture=false;iosProcessedFinalIndex=-1;recognitionNetworkFailures=0;updateConversationButton();syncConversationMusic();showTranscript('Starting microphone…','connecting');agent('connecting','Starting the microphone…');startConversationListen();
  }
  async function deliverSpeechEngineAction(){
    if(!speechPilotActive||!window.StonefellowSpeechEngine?.active)return;
    const epoch=speechPilotEpoch;
    try{
      const envelope=await window.StonefellowSpeechEngine.takeAction();
      if(epoch!==speechPilotEpoch||!envelope?.action)return;
      const result=await dispatchAgentBrowserAction(String(envelope.message||''),envelope.action,envelope.audio_resolution,false);
      // The Speech Engine dispatches through the same local player as typed
      // chat; report the actual outcome to its own conversation history.
      const receipt=envelope.receipt;
      if(typeof result==='string'&&result.trim()&&receipt&&Number.isSafeInteger(Number(receipt.message_id))&&Number(receipt.message_id)>0){
        try{await secureJsonPost('agent-action-receipt.php',{
          conversation_id:String(receipt.conversation_id||''),message_id:Number(receipt.message_id),
          action_type:String(receipt.action_type||''),text:result.trim()
        })}catch(_){/* No invented success if the receipt cannot be saved. */}
      }
      if(epoch!==speechPilotEpoch)return;
      if(result)popupEntry('notice',String(result));
      scheduleSpeechContextSync();
    }catch(error){if(epoch===speechPilotEpoch)popupEntry('notice','Voice action could not complete: '+String(error.message||'Unknown error'))}
  }
  voice.onclick=()=>{void handleVoiceClick()};
  if(R){
    recognition=new R();recognition.lang='en-US';recognition.interimResults=true;recognition.continuous=isIOSVoiceBrowser;
    recognition.onstart=()=>{if(!st.conversationOn||speechPilotActive)return;recognitionActive=true;setVoiceInputActive(false);if(voicePlaybackPending||(typeof voiceSpeechGuard!=='undefined'&&voiceSpeechGuard?.blocked)){if(!isIOSVoiceBrowser)try{recognition.abort()}catch(_){}return}if(st.conversationOn&&!voiceTurnBusy){if(greetAfterMicrophoneReady())return;agent('listening','I’m listening.');showTranscript(listeningPrompt(),'listening')}};
    recognition.onspeechstart=()=>{if(shouldDuckConversationInput())setVoiceInputActive(true)};
    recognition.onspeechend=()=>setVoiceInputActive(false);
    recognition.onresult=e=>{
      if(!st.conversationOn||speechPilotActive)return;
      const guarded=voiceTurnBusy||voicePlaybackPending||voiceSpeechGuard?.blocked;
      if(isIOSVoiceBrowser){
        // Continuous recognition accumulates old final results. Only process
        // the newly changed result, and discard anything heard while replying.
        const from=Math.max(0,Number(e.resultIndex)||0);
        let spoken='',finalReceived=false,finalIndex=-1;
        for(let i=from;i<e.results.length;i++){
          const result=e.results[i],value=String(result[0]?.transcript||'').trim();
          if(i<=iosProcessedFinalIndex||!value)continue;
          if(result.isFinal){finalIndex=i;spoken=value;finalReceived=true}
          else if(!finalReceived)spoken=value;
        }
        if(guarded||Date.now()<iosVoiceMuteUntil){
          if(finalIndex>=0)iosProcessedFinalIndex=Math.max(iosProcessedFinalIndex,finalIndex);
          setVoiceInputActive(false);return;
        }
        if(!spoken)return;
        // The browser may deliver the Agent's delayed acoustic echo after
        // playback; never feed its own words back into a new conversation.
        const clean=value=>String(value||'').toLowerCase().replace(/[^a-z0-9 ]/g,'').replace(/\s+/g,' ').trim();
        const own=clean(voiceSpeechGuard?.reference),candidate=clean(spoken);
        if(own&&candidate.length>4&&Date.now()<iosVoiceMuteUntil+3500&&own.includes(candidate)){
          if(finalIndex>=0)iosProcessedFinalIndex=Math.max(iosProcessedFinalIndex,finalIndex);
          return;
        }
        recognitionNetworkFailures=0;
        if(finalReceived){iosProcessedFinalIndex=finalIndex;setVoiceInputActive(false)}
        else setVoiceInputActive(shouldDuckConversationInput(spoken));
        showTranscript('You: '+spoken,finalReceived?'heard':'listening');
        if(finalReceived)submitRecognizedVoiceTurn(spoken);
        return;
      }
      if(guarded)return;
      const phrases=[];let finalReceived=false;
      for(let i=0;i<e.results.length;i++){const result=e.results[i],value=String(result[0]?.transcript||'').trim();if(value)phrases.push(value);if(result.isFinal&&value)finalReceived=true}
      const spoken=phrases.join(' ').trim();if(spoken)recognitionNetworkFailures=0;
      if(spoken&&!finalReceived)setVoiceInputActive(shouldDuckConversationInput(spoken));
      if(finalReceived)setVoiceInputActive(false);
      showTranscript(spoken?'You: '+spoken:listeningPrompt(),finalReceived?'heard':'listening');
      if(finalReceived&&spoken)submitRecognizedVoiceTurn(spoken);
    };
    recognition.onerror=e=>{
      if(!st.conversationOn||speechPilotActive)return;
      recognitionActive=false;setVoiceInputActive(false);
      if(e.error==='service-not-allowed'){
        stopConversation();showTranscript(isIOSVoiceBrowser?'iPhone speech recognition is unavailable. Microphone access may already be allowed. Check Siri in iPhone Settings, then reopen the browser and tap Start Voice Chat.':'The browser blocked its speech recognition service. Try another voice engine or type your message.','error');return
      }
      if(e.error==='not-allowed'){
        stopConversation();showTranscript(isIOSVoiceBrowser?'This browser blocked speech recognition. Check microphone access for this site and Siri in iPhone Settings, then tap Start Voice Chat.':'Speech input was blocked. Check browser site permissions and retry.','error');return
      }
      if(e.error==='audio-capture'){stopConversation();showTranscript(isIOSVoiceBrowser?'This browser could not capture microphone audio. Close other apps using the microphone, then tap Start Voice Chat.':'No microphone was detected. Check your audio input and retry.','error');return}
      if(isIOSVoiceBrowser&&st.conversationOn){
        iosVoiceNeedsGesture=true;updateConversationButton();
        showTranscript('Microphone paused · tap Resume Voice Chat.','waiting');return;
      }
      if(e.error==='network'){recognitionNetworkFailures=Math.min(5,recognitionNetworkFailures+1);showTranscript('Speech recognition connection interrupted. Retrying…','waiting');scheduleConversationListen(Math.min(7500,600*2**recognitionNetworkFailures));return}
      if(st.conversationOn&&!voiceTurnBusy)scheduleConversationListen(e.error==='no-speech'?500:750);
    };
    recognition.onend=()=>{if(!st.conversationOn||speechPilotActive)return;recognitionActive=false;setVoiceInputActive(false);
      if(isIOSVoiceBrowser&&st.conversationOn){
        iosVoiceNeedsGesture=true;updateConversationButton();
        if(!voiceTurnBusy&&!voicePlaybackPending){
          agent('idle','Microphone paused.');
          showTranscript('Microphone paused · tap Resume Voice Chat.','waiting');
        }
        return;
      }
      if(st.conversationOn&&!voiceTurnBusy)scheduleConversationListen(recognitionNetworkFailures?Math.min(7500,600*2**recognitionNetworkFailures):320);
    };
    updateConversationButton();
  }else{voice.title='Original voice recognition is unavailable; Speech Engine pilot may be available in Account Settings';showTranscript('Original voice recognition is not supported here. You can still use chat or an enabled Speech Engine pilot.','waiting')}
  window.addEventListener('pagehide',()=>{closeListeningRoom(false);if(st.conversationOn)stopConversation()});
  window.addEventListener('pageshow',event=>{if(event.persisted)void loadAuth(true).then(restoreActiveCanvas);});
  document.addEventListener('stonefellow:navigation',event=>{
    if(event.detail?.view==='builder'){
      window.SFPublicAgentCanvas?.unmount?.();
      document.body.classList.remove('sf-agent-canvas-active');
    }
  });
  if(splash){const revision=splash.dataset.revision||'1',key='stonefellow.splash.seen.'+revision;if(sessionStorage.getItem(key)==='1')splash.hidden=true;const enter=$('#splashEnter');if(enter)enter.onclick=()=>{sessionStorage.setItem(key,'1');splash.hidden=true;input?.focus()}}
  $$('.social-links [data-social]').forEach(a=>{const u=cfg.socials?.[a.dataset.social];if(u&&u!=='#'){a.href=u;a.target='_blank';a.rel='noopener'}else a.onclick=e=>e.preventDefault()});
  bind(document);loadStore();window.StonefellowBoot?.step(32,'Connecting to Stonefellow');
  Promise.allSettled([loadAuth(true).then(()=>Promise.allSettled([loadAgentListeningSession(),loadQueue(true)])),loadPlans(),refreshSiteData()]).finally(async()=>{
    try{
      window.StonefellowBoot?.step(68,'Building Agent Canvas');
      await routeFromLocation();
      window.StonefellowBoot?.step(88,'Loading atmosphere');
      const activeAtmosphere=window.SFPublicAgentCanvas?.active?.()?window.SFPublicAgentCanvas.getAtmosphere?.():null;
      const atmosphereAsset=activeAtmosphere==='lunar'?'assets/images/agent-atmosphere-lunar.webp':activeAtmosphere==='ember'?'assets/images/agent-atmosphere-ember.webp':null;
      if(atmosphereAsset){
        await Promise.race([new Promise(resolve=>{
          const preload=new Image();preload.onload=preload.onerror=resolve;preload.src=atmosphereAsset;
        }),new Promise(resolve=>setTimeout(resolve,4000))]);
      }
      if(document.fonts?.ready)await Promise.race([document.fonts.ready,new Promise(resolve=>setTimeout(resolve,2200))]);
      // Two frames let CSS atmosphere, particle orb and controls lay out before reveal.
      await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
      window.StonefellowBoot?.finish();
    }catch(error){console.error('[Stonefellow] Agent Canvas boot failed:',error);window.StonefellowBoot?.fail()}
    setTimeout(()=>loadFanEngagement(false),900);
  });
})();
