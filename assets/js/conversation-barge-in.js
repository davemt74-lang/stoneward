/* Shared local microphone activity monitor for Stonefellow conversation mode.
 * Uses the browser's acoustic echo cancellation; audio never leaves the device.
 */
(() => {
  'use strict';
  function create(onInterrupt, browser=window) {
    let generation=0, stream=null, context=null, analyser=null, frame=0;
    let listening=false, speakingSince=0, consecutive=0, noiseFloor=0.008;
    const readBuffer=new Float32Array(1024);
    const stop=()=>{
      generation++;listening=false;consecutive=0;
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
      const foreground=now-speakingSince>400&&rms>Math.max(0.035,noiseFloor*3.2)&&peak>0.11;
      consecutive=foreground?consecutive+1:Math.max(0,consecutive-2);
      if(!foreground)noiseFloor=Math.max(0.004,Math.min(0.045,noiseFloor*0.995+rms*0.005));
      if(consecutive>=7){stop();onInterrupt();return}
      frame=browser.requestAnimationFrame(sample);
    };
    const start=async()=>{
      stop();const requested=++generation;
      const AudioContextClass=browser.AudioContext||browser.webkitAudioContext;
      if(!AudioContextClass||!browser.navigator?.mediaDevices?.getUserMedia)return false;
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
  window.StonefellowBargeIn={create};
})();
