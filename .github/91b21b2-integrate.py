from pathlib import Path
import hashlib
p=Path('assets/js/app.js');s=p.read_text()
assert hashlib.sha256(s.encode()).hexdigest()=='4a9543e476ec5fe26a0fad9730f4ae420377c35a20558d01656321b037da5c05'
a=s.index('  function recoverNetworkPlayback(){');b=s.index('  function play(t,',a)
s=s[:a]+'''  function recoverNetworkPlayback(){
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
'''+s[b:]
def replace(a,b,count=1):
 global s
 assert s.count(a)==count,(a[:90],s.count(a))
 s=s.replace(a,b)
replace("    clearTimeout(pendingNetworkRecovery.timer);\n    audio.removeEventListener('loadedmetadata',pendingNetworkRecovery.ready);\n    pendingNetworkRecovery=null;","    const recovery=pendingNetworkRecovery;pendingNetworkRecovery=null;\n    recovery.controller?.cancel();recovery.reject?.(new Error('Playback action replaced'));\n")
replace("  function setPlaybackStatus(message='',phase=''){",'''  function rememberPlaybackPosition(){
    if(!pendingNetworkRecovery&&!audio.error&&!audio.seeking&&Number(audio.readyState)>=2)
      playbackLifecycle.remember(playbackLifecycle.token(),Number(audio.currentTime));
  }
  function savedPlaybackPosition(){
    return Math.max(playbackLifecycle.position(playbackLifecycle.token()),Number(audio.currentTime)||0);
  }
  function setPlaybackStatus(message='',phase=''){''')
replace('playbackWantsAudio=false;playbackLifecycle.begin();clearPlaybackBufferWatch();cancelNetworkRecovery();setPlaybackStatus();','playbackWantsAudio=false;playbackLifecycle.begin(savedPlaybackPosition());clearPlaybackBufferWatch();cancelNetworkRecovery();setPlaybackStatus();')
replace('const token=playbackLifecycle.begin();cancelNetworkRecovery();clearPlaybackBufferWatch();setPlaybackStatus();','const token=playbackLifecycle.begin(Math.max(0,Number(resumeAt)||0));cancelNetworkRecovery();clearPlaybackBufferWatch();setPlaybackStatus();')
replace('play(st.track,Number(audio.currentTime)||0,st.playSource','play(st.track,savedPlaybackPosition(),st.playSource',2)
replace("const changing=!st.track||String(st.track.id)!==String(t.id)||audio.getAttribute('src')!==String(t.audio);","const changing=!st.track||String(st.track.id)!==String(t.id)||audio.getAttribute('src')!==String(t.audio);\n    const reloading=changing||!!audio.error||footerPlayer?.dataset.playbackPhase==='error';")
replace('if(changing){audio.pause();audio.src=t.audio;audio.load()}','if(reloading){audio.pause();audio.src=t.audio;audio.load()}')
a=s.index('    const applyResume=()=>{',s.index('  function play(t,'));b=s.index('    updateFooterPlayer();',a)
s=s[:a]+'''    if(!reloading&&keepPlaylist&&startAt===0)try{audio.currentTime=0}catch(_){}
    const resumeNeeded=startAt>0&&(reloading||!Number.isFinite(audio.currentTime)||Math.abs(audio.currentTime-startAt)>0.35||audio.seeking);
'''+s[b:]
replace('    let attempt;try{attempt=audio.play()}catch(error){attempt=Promise.reject(error)}','''    let attempt;
    if(resumeNeeded){
      // Manual resume uses the same verified-seek gate as automatic network recovery.
      attempt=new Promise((resolve,reject)=>{
        const recovery={token,position:startAt,controller:null,reject};pendingNetworkRecovery=recovery;
        const current=()=>pendingNetworkRecovery===recovery&&playbackWantsAudio&&playbackLifecycle.isCurrent(token)&&String(st.track?.id)===String(t.id);
        recovery.controller=window.StonefellowPlaybackLifecycle.restorePosition({audio,position:startAt,isCurrent:current,
          onFailure:()=>{if(current())reject(new Error('Saved position is not seekable yet'));},
          onRestored:()=>{if(current()){try{Promise.resolve(audio.play()).then(resolve,reject)}catch(error){reject(error)}}}});
        recovery.controller.check();
      });
    }else{try{attempt=audio.play()}catch(error){attempt=Promise.reject(error)}}''')
replace("      st.playing=true;recordListen(startAt>0?'resume':'start');","      cancelNetworkRecovery();st.playing=true;rememberPlaybackPosition();recordListen(startAt>0?'resume':'start');")
replace("audio.addEventListener('timeupdate',()=>{songCaptureSkill","audio.addEventListener('timeupdate',()=>{rememberPlaybackPosition();songCaptureSkill")
replace("  audio.addEventListener('seeking',()=>","  audio.addEventListener('seeked',rememberPlaybackPosition);\n  audio.addEventListener('seeking',()=>")
replace("    if(['preview_only','preview_master_split'].includes(st.track?.audio_publishing_mode))return false;","    const actual=Number(audio.duration),position=Number(audio.currentTime)||0;\n    if(Number.isFinite(actual)&&actual>=10&&position<actual-Math.max(5,actual*.02))return true;\n    if(['preview_only','preview_master_split'].includes(st.track?.audio_publishing_mode))return false;")
replace('invalidatePlayback();audio.pause();try{audio.currentTime=0}catch(_){}','invalidatePlayback();audio.pause();try{audio.currentTime=0}catch(_){}\n        playbackLifecycle.remember(playbackLifecycle.token(),0);')
replace("  audio.addEventListener('ended',()=>{\n    const endedToken","  audio.addEventListener('ended',()=>{\n    if(pendingNetworkRecovery)return;\n    const endedToken")
# Normalize the single replacement's trailing line before exact-source verification.
s=s.replace("recovery.reject?.(new Error('Playback action replaced'));\n\n  }","recovery.reject?.(new Error('Playback action replaced'));\n  }")
s=s.replace('1.3.237','1.3.238')
assert hashlib.sha256(s.encode()).hexdigest()=='4d8be961bc488259d9baa18c05b488c8cd49921cab1eb72be30996888a818230'
p.write_text(s)
p=Path('api/media.php');s=p.read_text()
assert hashlib.sha256(s.encode()).hexdigest()=='ccee0bbf5c9e437f5663dd3da1b8fe4b048c5751ddaf233492971212d7792d04'
s=s.replace('$admin=false;$u=null;','$publicCacheAllowed=false;$admin=false;$u=null;')
s=s.replace('if($allowed){$viewAllowed=true;',"if($allowed){$viewAllowed=true;if(($link['entity_type']??'')!=='member_content')$publicCacheAllowed=true;")
s=s.replace('// Byte-range Content-Length',"// Authorization is complete. Long/overlapping mobile Range requests must not hold the PHP session lock.\nif(session_status()===PHP_SESSION_ACTIVE&&!session_write_close()){\n    http_response_code(503);header('Cache-Control: private, no-store');exit('Media session unavailable.');\n}\n// Byte-range Content-Length")
s=s.replace("($admin?'private, no-store':'public, max-age=3600')","($publicCacheAllowed?'public, max-age=3600':'private, no-store')")
assert hashlib.sha256(s.encode()).hexdigest()=='a1ed9885ebf82e3cd9dd795f130dca8dba7a385d6341b4e0f150d79f528d7f1d'
p.write_text(s)
for path in ['version.php','stonefellow-v120.php','admin/index.php','service-worker.js','.htaccess','assets/css/turntable-builder.css']:
 p=Path(path);s=p.read_text();assert '1.3.237' in s,path;p.write_text(s.replace('1.3.237','1.3.238'))
p=Path('version.php');s=p.read_text().replace("'agent_playback_lifecycle'=>'91B17I:","'agent_playback_lifecycle'=>'91B21B2: verified position restore after network interruption, saved resume checkpoint, session-unlocked range streams and restricted-media cache isolation; 91B17I:");p.write_text(s)
for path in ['tests/section91b14c-ios-lunar-mobile.cjs','tests/section91b20h-visual-style-library.test.cjs']:
 p=Path(path);s=p.read_text();assert '1.3.237' in s or r'1\.3\.237' in s,path;p.write_text(s.replace('1.3.237','1.3.238').replace(r'1\.3\.237',r'1\.3\.238'))
