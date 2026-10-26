/* Shared public/Admin speech monitor for hands-free Agent barge-in.
 * Recognition begins before playback so a short "stop" is not over before
 * the microphone starts. Agent echo is filtered before any interruption.
 */
(() => {
  'use strict';
  const normalized=text=>String(text||'').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').replace(/\s+/g,' ').trim();
  function verifyAgentSpeech(reference, browser=window, signal=null) {
    const Recognition=browser.SpeechRecognition||browser.webkitSpeechRecognition;
    if(!Recognition||signal?.aborted)return Promise.resolve(null);
    return new Promise(resolve=>{
      const recognizer=new Recognition();
      let settled=false,partialIndependent=null;
      const expected=normalized(reference),expectedWords=new Set(expected.split(' ').filter(Boolean));
      const finish=value=>{
        if(settled)return;
        settled=true;
        signal?.removeEventListener?.('abort',onAbort);
        try{recognizer.abort()}catch(_){}
        resolve(value);
      };
      const onAbort=()=>finish(null);
      signal?.addEventListener?.('abort',onAbort,{once:true});
      recognizer.lang='en-US';recognizer.continuous=true;recognizer.interimResults=true;
      recognizer.onresult=event=>{
        // Treat each recognized utterance separately. A long-lived recognizer
        // may accumulate earlier Agent echo before hearing a visitor's "stop".
        for(let i=0;i<event.results.length;i++){
          const entry=event.results[i],alternative=entry[0];
          const transcript=String(alternative?.transcript||'').trim();
          const candidate=normalized(transcript),words=candidate.split(' ').filter(Boolean);
          if(!candidate)continue;
          // Browser ASR can hear the Agent through speakers even with AEC.
          // Reject complete or nearly complete matches to its actual words.
          const novelWords=words.filter(word=>!expectedWords.has(word)).length;
          const overlap=1-novelWords/words.length;
          const explicitStop=candidate==='stop';
          const echo=!explicitStop&&(expected.includes(candidate)||(words.length>=2&&novelWords<2)||(words.length>=3&&overlap>=0.85));
          if(echo)continue;
          // Final results preserve the user's complete instruction. Interim
          // results are retained only if the browser ends without a final.
          partialIndependent=transcript;
          if(entry.isFinal&&(words.length>=2||novelWords===1||explicitStop)){finish(transcript);return}
        }
      };
      recognizer.onerror=()=>finish(null);
      // Some browsers end recognition without an isFinal event. Retain
      // independent words, but never an echoed Agent fragment.
      recognizer.onend=()=>finish(partialIndependent);
      try{recognizer.start()}catch(_){finish(null)}
    });
  }
  function create(onInterrupt, browser=window, confirmCandidate=null) {
    let generation=0,listening=false,confirmation=null,restartTimer=null;
    const stop=()=>{
      generation++;listening=false;
      if(restartTimer!==null){browser.clearTimeout?.(restartTimer);restartTimer=null}
      if(confirmation){confirmation.abort();confirmation=null}
    };
    const listen=()=>{
      if(!listening||typeof confirmCandidate!=='function')return;
      const attempt=generation,controller=new AbortController();
      confirmation=controller;
      Promise.resolve().then(()=>confirmCandidate(controller.signal)).then(phrase=>{
        if(controller.signal.aborted||attempt!==generation||!listening)return;
        confirmation=null;
        if(typeof phrase==='string'&&phrase.trim()){
          stop();onInterrupt(phrase.trim());return;
        }
        // Web Speech Recognition often ends after a natural silence. Keep
        // it armed throughout the reply without a manual microphone click.
        restartTimer=browser.setTimeout?.(()=>{restartTimer=null;listen()},250)??null;
      }).catch(()=>{
        if(controller.signal.aborted||attempt!==generation||!listening)return;
        confirmation=null;
        restartTimer=browser.setTimeout?.(()=>{restartTimer=null;listen()},750)??null;
      });
    };
    const start=async()=>{
      stop();
      if(typeof confirmCandidate!=='function'||!(browser.SpeechRecognition||browser.webkitSpeechRecognition))return false;
      listening=true;listen();return true;
    };
    return {start,stop,get active(){return listening}};
  }
  window.StonefellowBargeIn={create,verifyAgentSpeech};
})();
