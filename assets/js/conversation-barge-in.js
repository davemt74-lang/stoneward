/* Shared local microphone activity monitor for Stonefellow conversation mode.
 * Loudness is only a candidate: it must never cancel Agent speech without
 * a separate, non-echo speech-recognition result.
 */
(() => {
  'use strict';
  const normalized=text=>String(text||'').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').replace(/\s+/g,' ').trim();
  function verifyAgentSpeech(reference, browser=window, signal=null) {
    const Recognition=browser.SpeechRecognition||browser.webkitSpeechRecognition;
    if(!Recognition||signal?.aborted)return Promise.resolve(null);
    return new Promise(resolve=>{
      const recognizer=new Recognition();
      let settled=false;
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
      recognizer.lang='en-US';recognizer.continuous=false;recognizer.interimResults=true;
      recognizer.onresult=event=>{
        let transcript='',final=false;
        for(let i=0;i<event.results.length;i++){
          const entry=event.results[i],alternative=entry[0];
          transcript+=' '+String(alternative?.transcript||'');
          final=final||!!entry.isFinal;
        }
        const candidate=normalized(transcript),words=candidate.split(' ').filter(Boolean);
        if(!candidate)return;
        // Browser ASR can hear the Agent through speakers even with AEC.
        // Never mistake verbatim/near-verbatim response audio for the user.
        const novelWords=words.filter(word=>!expectedWords.has(word)).length;
        const overlap=1-novelWords/words.length;
        const echo=expected.includes(candidate)||(words.length>=2&&novelWords<2)||(words.length>=3&&overlap>=0.85);
        if(echo)return;
        // The final ASR boundary preserves the user's full interruption;
        // sending an interim phrase would cut off their own sentence.
        if(final&&(words.length>=2||novelWords===1)){
          finish(String(transcript).trim());
        }
      };
      recognizer.onerror=()=>finish(null);
      recognizer.onend=()=>finish(null);
      try{recognizer.start()}catch(_){finish(null)}
    });
  }
  function create(onInterrupt, browser=window, confirmCandidate=null) {
    let generation=0, stream=null, context=null, analyser=null, frame=0, confirmation=null;
    let listening=false, speakingSince=0, consecutive=0, noiseFloor=0.008,cooldownUntil=0;
    const readBuffer=new Float32Array(1024);
    const stop=()=>{
      generation++;listening=false;consecutive=0;
      if(confirmation){confirmation.abort();confirmation=null}
      if(frame){browser.cancelAnimationFrame?.(frame);frame=0}
      if(stream){stream.getTracks().forEach(track=>track.stop());stream=null}
      if(context){void context.close?.().catch?.(()=>{});context=null}
      analyser=null;
    };
    const sample=()=>{
      frame=0;if(!listening||!analyser)return;
      analyser.getFloatTimeDomainData(readBuffer);
      let power=0,peak=0;
      for(const x of readBuffer){power+=x*x;peak=Math.max(peak,Math.abs(x))}
      const rms=Math.sqrt(power/readBuffer.length);
      const now=Date.now();
      // AEC handles speaker feedback. Require a sustained foreground voice onset
      // to avoid reacting to clicks, quiet residual echo, and short noise bursts.
      const foreground=now>cooldownUntil&&now-speakingSince>400&&rms>Math.max(0.035,noiseFloor*3.2)&&peak>0.11;
      consecutive=foreground?consecutive+1:Math.max(0,consecutive-2);
      if(!foreground)noiseFloor=Math.max(0.004,Math.min(0.045,noiseFloor*0.995+rms*0.005));
      if(consecutive>=7){
        stop();
        if(typeof confirmCandidate!=='function')return;
        const attempt=generation,controller=new AbortController();
        confirmation=controller;
        Promise.resolve().then(()=>confirmCandidate(controller.signal)).then(phrase=>{
          if(controller.signal.aborted||attempt!==generation)return;
          confirmation=null;
          if(typeof phrase==='string'&&phrase.trim()){onInterrupt(phrase.trim());return}
          cooldownUntil=Date.now()+3500;
          void start();
        }).catch(()=>{
          if(controller.signal.aborted||attempt!==generation)return;
          confirmation=null;cooldownUntil=Date.now()+3500;void start();
        });
        return;
      }
      frame=browser.requestAnimationFrame(sample);
    };
    const start=async()=>{
      stop();const requested=++generation;
      const AudioContextClass=browser.AudioContext||browser.webkitAudioContext;
      if(!AudioContextClass||!browser.navigator?.mediaDevices?.getUserMedia||typeof confirmCandidate!=='function')return false;
      let media;
      try{media=await browser.navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false})}
      catch(_){return false}
      if(requested!==generation){media.getTracks().forEach(track=>track.stop());return false}
      try{
        const audioContext=new AudioContextClass(),source=audioContext.createMediaStreamSource(media);
        const highPass=audioContext.createBiquadFilter();highPass.type='highpass';highPass.frequency.value=160;
        const lowPass=audioContext.createBiquadFilter();lowPass.type='lowpass';lowPass.frequency.value=3500;
        const detector=audioContext.createAnalyser();detector.fftSize=2048;detector.smoothingTimeConstant=0.15;
        source.connect(highPass);highPass.connect(lowPass);lowPass.connect(detector);
        if(requested!==generation){media.getTracks().forEach(track=>track.stop());void audioContext.close();return false}
        stream=media;context=audioContext;analyser=detector;listening=true;
        speakingSince=Date.now();consecutive=0;noiseFloor=0.008;
        frame=browser.requestAnimationFrame(sample);
        return true;
      }catch(_){media.getTracks().forEach(track=>track.stop());return false}
    };
    return {start,stop,get active(){return listening}};
  }
  window.StonefellowBargeIn={create,verifyAgentSpeech};
})();
