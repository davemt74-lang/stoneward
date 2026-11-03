/* Section 91B17I: one authoritative browser playback epoch per user/player action. */
(() => {
  'use strict';
  function create(){
    let epoch=0,claimedEnd=-1,networkRetryEpoch=-1,position=0;
    return Object.freeze({
      begin(at=0){epoch++;claimedEnd=-1;networkRetryEpoch=-1;position=Number.isFinite(at)?Math.max(0,at):0;return epoch},
      token(){return epoch},
      remember(token,at){if(token===epoch&&Number.isFinite(at)&&at>=0)position=at},
      position(token){return token===epoch?position:0},
      isCurrent(token){return Number.isInteger(token)&&token===epoch},
      claimEnded(token){
        if(token!==epoch||claimedEnd===epoch)return false;
        claimedEnd=epoch;return true;
      },
      allowNetworkRetry(token,errorCode){
        // MediaError 2 is a network interruption. Decode/source errors need inspection.
        if(token!==epoch||errorCode!==2||networkRetryEpoch===epoch)return false;
        networkRetryEpoch=epoch;return true;
      },
      hasRetried(token){return token===epoch&&networkRetryEpoch===epoch}
    });
  }
  /** Resume only after a verified seek. Metadata alone is not proof the offset was restored. */
  function restorePosition({audio,position,isCurrent,onRestored,onFailure,timeoutMs=12000}){
    const target=Number.isFinite(position)?Math.max(0,position):0;
    const events=['loadedmetadata','durationchange','progress','canplay','seeked'];
    let stopped=false,attempting=false,assigned=false,timer=null;
    const cleanup=()=>{
      if(stopped)return;
      stopped=true;clearTimeout(timer);
      for(const name of events)audio.removeEventListener(name,attempt);
    };
    const fail=()=>{cleanup();if(isCurrent())onFailure(target)};
    const atTarget=()=>Number.isFinite(audio.currentTime)&&Math.abs(audio.currentTime-target)<=0.35;
    function attempt(){
      if(stopped||attempting)return;
      if(!isCurrent()){cleanup();return;}
      if(Number(audio.readyState)<1)return;
      attempting=true;
      try{
        if(!assigned){
          // Do not clamp a saved offset to a temporary/short duration and then replay from zero.
          const duration=Number(audio.duration);
          if(Number.isFinite(duration)&&duration>0&&target>=duration)return;
          try{audio.currentTime=target;assigned=true}catch(_){return;}
        }
        // iOS may accept currentTime before the asynchronous seek is complete.
        if(audio.seeking)return;
        if(!atTarget()){assigned=false;return;}
        cleanup();
        if(isCurrent())onRestored(target);
      }finally{attempting=false;}
    }
    for(const name of events)audio.addEventListener(name,attempt);
    timer=setTimeout(()=>{if(!stopped){if(isCurrent())fail();else cleanup()}},timeoutMs);
    // The caller installs listeners before resetting the source, then invokes check when ready.
    return Object.freeze({cancel:cleanup,check:attempt});
  }
  window.StonefellowPlaybackLifecycle=Object.freeze({create,restorePosition});
})();
