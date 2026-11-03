/* Section 91B17I: one authoritative browser playback epoch per user/player action. */
(() => {
  'use strict';
  function create(){
    let epoch=0,claimedEnd=-1,networkRetryEpoch=-1;
    return Object.freeze({
      begin(){epoch++;claimedEnd=-1;networkRetryEpoch=-1;return epoch},
      token(){return epoch},
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
  window.StonefellowPlaybackLifecycle=Object.freeze({create});
})();
