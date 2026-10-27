/* Shared public/Admin full-duplex speech monitor for Agent barge-in.
 * Recognition captures words. Microphone activity first pauses output for an
 * echo-tail test; only speech that continues without playback can silence it.
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
          // The Agent itself might say "stop". If that word is in its reply,
          // ASR alone cannot identify the speaker; the acoustic probe can.
          const explicitStop=candidate==='stop'&&!expectedWords.has('stop');
          // Short participant instructions often share ordinary words with a
          // long Agent answer. Reject an exact echo, or near-complete overlap
          // only for longer phrases; never suppress "stop now" as one novel word.
          const echo=!explicitStop&&(expected.includes(candidate)||(words.length>=3&&novelWords===0)||(words.length>=4&&(novelWords<=1||overlap>=0.85)));
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
  function create(onInterrupt, browser=window, confirmCandidate=null, onVoiceActivity=null, onStatus=null) {
    let generation=0,resourceGeneration=0,listening=false,confirmation=null,restartTimer=null;
    let stream=null,context=null,analyser=null,readyPromise=null,frame=null,activityTimer=null;
    let voiceDetected=false,consecutive=0,noiseFloor=0.008,fastFailures=0;
    let probing=false,probeReady=false,probeConsecutive=0,pendingPhrase='';
    let probeTailTimer=null,probeDeadlineTimer=null,probeRms=0,echoBaseline=0,echoCooldownUntil=0;
    const readBuffer=new Float32Array(1024);
    const report=state=>{try{onStatus?.(state)}catch(_){}};
    const clearProbe=()=>{
      if(probeTailTimer!==null){browser.clearTimeout?.(probeTailTimer);probeTailTimer=null}
      if(probeDeadlineTimer!==null){browser.clearTimeout?.(probeDeadlineTimer);probeDeadlineTimer=null}
      probing=false;probeReady=false;probeConsecutive=0;pendingPhrase='';
    };
    const resumeEcho=()=>{
      if(!probing)return;
      clearProbe();
      // The candidate disappeared once playback paused: it was speaker echo.
      echoBaseline=Math.max(echoBaseline,Math.min(0.25,probeRms*0.9));
      echoCooldownUntil=Date.now()+900;
      consecutive=0;
      try{onVoiceActivity?.('resume')}catch(_){}
      report('agent-echo-suppressed');
    };
    const release=()=>{
      if(frame!==null){browser.cancelAnimationFrame?.(frame);frame=null}
      if(stream){for(const track of stream.getTracks())track.stop();stream=null}
      if(context){void context.close?.().catch?.(()=>{});context=null}
      analyser=null;
    };
    const stop=()=>{
      generation++;listening=false;
      if(restartTimer!==null){browser.clearTimeout?.(restartTimer);restartTimer=null}
      if(activityTimer!==null){browser.clearTimeout?.(activityTimer);activityTimer=null}
      clearProbe();
      if(confirmation){confirmation.abort();confirmation=null}
      if(frame!==null){browser.cancelAnimationFrame?.(frame);frame=null}
      voiceDetected=false;consecutive=0;
    };
    const close=()=>{stop();resourceGeneration++;release()};
    const prepare=()=>{
      if(stream&&analyser)return Promise.resolve(true);
      if(readyPromise)return readyPromise;
      const getUserMedia=browser.navigator?.mediaDevices?.getUserMedia;
      const AudioContext=browser.AudioContext||browser.webkitAudioContext;
      if(typeof getUserMedia!=='function'||!AudioContext){report('microphone-monitor-unsupported');return Promise.resolve(false)}
      const requested=resourceGeneration;
      readyPromise=(async()=>{
        let media=null,audioContext=null;
        try{
          // When prepare() is called from the microphone button, construct
          // the context during that user gesture, before permission awaits.
          audioContext=new AudioContext();
          media=await getUserMedia.call(browser.navigator.mediaDevices,{audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
          if(requested!==resourceGeneration){for(const track of media.getTracks())track.stop();void audioContext.close();return false}
          const source=audioContext.createMediaStreamSource(media);
          const highPass=audioContext.createBiquadFilter();highPass.type='highpass';highPass.frequency.value=160;
          const lowPass=audioContext.createBiquadFilter();lowPass.type='lowpass';lowPass.frequency.value=3500;
          const detector=audioContext.createAnalyser();detector.fftSize=2048;detector.smoothingTimeConstant=0.15;
          source.connect(highPass);highPass.connect(lowPass);lowPass.connect(detector);
          if(requested!==resourceGeneration){for(const track of media.getTracks())track.stop();void audioContext.close();return false}
          stream=media;context=audioContext;analyser=detector;
          if(audioContext.state==='suspended'){
            try{void audioContext.resume?.().catch?.(()=>report('microphone-monitor-suspended'))}catch(_){report('microphone-monitor-suspended')}
            browser.setTimeout?.(()=>{if(context===audioContext&&audioContext.state==='suspended')report('microphone-monitor-suspended')},650);
          }
          report('microphone-ready');return true;
        }catch(_){
          if(media){for(const track of media.getTracks())track.stop()}
          if(audioContext)void audioContext.close?.().catch?.(()=>{});
          report('microphone-monitor-unavailable');return false;
        }
      })().finally(()=>{readyPromise=null});
      return readyPromise;
    };
    const sample=()=>{
      frame=null;
      if(!listening||!analyser||voiceDetected||typeof onVoiceActivity!=='function')return;
      analyser.getFloatTimeDomainData(readBuffer);
      let power=0,peak=0;
      for(const point of readBuffer){power+=point*point;peak=Math.max(peak,Math.abs(point))}
      const rms=Math.sqrt(power/readBuffer.length);
      if(probing){
        if(probeReady){
          // A browser can report a successful pause request before it has
          // actually stopped output. Never classify that output as a visitor.
          let reallyPaused=false;
          try{reallyPaused=onVoiceActivity('paused')===true}catch(_){}
          if(!reallyPaused){resumeEcho();frame=browser.requestAnimationFrame?.(sample)??null;return}
          // Reject the decaying acoustic tail of the paused speaker. A room
          // participant must remain audible above both noise and a meaningful
          // fraction of the pre-pause output level.
          const independent=rms>Math.max(0.028,noiseFloor*2.8,probeRms*0.58)&&peak>0.095;
          probeConsecutive=independent?probeConsecutive+1:0;
          if(probeConsecutive>=3){
            const confirmedPhrase=pendingPhrase;
            clearProbe();voiceDetected=true;
            report('independent-voice-detected');
            try{onVoiceActivity('confirm')}catch(_){}
            if(confirmedPhrase){stop();onInterrupt(confirmedPhrase);return}
            activityTimer=browser.setTimeout?.(()=>{
              activityTimer=null;
              if(!listening||!voiceDetected)return;
              stop();onInterrupt('');
            },5000)??null;
            return;
          }
        }
        frame=browser.requestAnimationFrame?.(sample)??null;
        return;
      }
      const active=rms>Math.max(0.028,noiseFloor*2.8,echoBaseline*1.55)&&peak>0.095;
      consecutive=active?consecutive+1:Math.max(0,consecutive-1);
      if(!active)noiseFloor=Math.max(0.004,Math.min(0.04,noiseFloor*0.995+rms*0.005));
      if(echoBaseline>0&&rms>noiseFloor*2&&rms<echoBaseline*1.55)echoBaseline=echoBaseline*0.98+rms*0.02;
      if(consecutive>=4&&Date.now()>=echoCooldownUntil){
        consecutive=0;probeRms=rms;pendingPhrase='';
        // Never permanently stop on microphone energy alone. A loud speaker
        // sounds identical to a person until we briefly silence that speaker.
        let paused=false;
        try{paused=onVoiceActivity('probe')===true}catch(_){}
        if(!paused){echoCooldownUntil=Date.now()+350;report('acoustic-probe-unavailable')}
        else{
          probing=true;probeReady=false;probeConsecutive=0;
          probeTailTimer=browser.setTimeout?.(()=>{probeTailTimer=null;if(probing)probeReady=true},180)??null;
          probeDeadlineTimer=browser.setTimeout?.(()=>{probeDeadlineTimer=null;resumeEcho()},640)??null;
        }
      }
      frame=browser.requestAnimationFrame?.(sample)??null;
    };
    const listen=()=>{
      if(!listening||typeof confirmCandidate!=='function')return;
      const attempt=generation,controller=new AbortController();
      const startedAt=Date.now();
      confirmation=controller;
      Promise.resolve().then(()=>confirmCandidate(controller.signal)).then(phrase=>{
        if(controller.signal.aborted||attempt!==generation||!listening)return;
        confirmation=null;
        if(typeof phrase==='string'&&phrase.trim()){
          // ASR hears speaker output as well as humans, and may invent novel
          // words even when the transcript does not resemble the reply. ASR
          // *alone* must never cut off playback. Hold words captured during
          // the silenced probe; release them only after independent speech
          // has physically persisted while output was paused.
          if(voiceDetected){stop();onInterrupt(phrase.trim());return}
          if(probing)pendingPhrase=phrase.trim();
        }
        fastFailures=Date.now()-startedAt<400?fastFailures+1:0;
        if(fastFailures===3)report('speech-recognition-unavailable');
        // Web Speech Recognition often ends after a natural silence. Keep
        // it armed throughout the reply without a manual microphone click.
        restartTimer=browser.setTimeout?.(()=>{restartTimer=null;listen()},250)??null;
      }).catch(()=>{
        if(controller.signal.aborted||attempt!==generation||!listening)return;
        confirmation=null;
        fastFailures++;if(fastFailures===3)report('speech-recognition-unavailable');
        restartTimer=browser.setTimeout?.(()=>{restartTimer=null;listen()},750)??null;
      });
    };
    const start=async()=>{
      stop();
      if(typeof confirmCandidate!=='function'||typeof onVoiceActivity!=='function'||!(browser.SpeechRecognition||browser.webkitSpeechRecognition))return false;
      listening=true;voiceDetected=false;consecutive=0;noiseFloor=0.008;fastFailures=0;
      echoBaseline=0;echoCooldownUntil=0;
      const requested=generation;
      const ready=await prepare();
      if(!ready){if(listening&&requested===generation)stop();return false}
      if(listening&&requested===generation){
        listen();
        if(frame===null)frame=browser.requestAnimationFrame?.(sample)??null;
      }
      return listening;
    };
    return {start,stop,close,prepare,get active(){return listening},get microphoneReady(){return !!analyser}};
  }
  window.StonefellowBargeIn={create,verifyAgentSpeech};
})();
