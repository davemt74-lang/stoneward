(() => {
  'use strict';
  /**
   * Non-native browser interpretations of REAPER FX identity.
   * Original VST state remains read-only. This graph never processes a master
   * or alters the rendered public song.
   */
  const specs={
    eq:{frequency:[20,20000,900],gain:[-24,24,0],q:[.1,18,.9]},
    compressor:{threshold:[-60,0,-18],ratio:[1,20,2],attack:[0,1,.01],release:[.01,2,.25]},
    delay:{time:[.01,1.8,.25],feedback:[0,.75,.2],wet:[0,1,.2]},
    reverb:{wet:[0,1,.2],seconds:[.2,4,1.4]}
  };
  function num(x,limits){
    const value=Number(x);
    return Number.isFinite(value)?Math.min(limits[1],Math.max(limits[0],value)):limits[2];
  }
  function parameters(fx){
    const spec=specs[fx?.type];if(!spec)return {};
    const out={};
    for(const [name,ranges] of Object.entries(spec))out[name]=num(fx?.settings?.[name],ranges);
    return out;
  }
  function controlledParam(param,value,context){
    if(!param)return;
    if(typeof param.setTargetAtTime==='function')param.setTargetAtTime(value,context.currentTime,.015);
    else param.value=value;
  }
  function impulse(context,seconds){
    const length=Math.max(1,Math.ceil(context.sampleRate*seconds));
    const buffer=context.createBuffer(2,length,context.sampleRate);
    // Deterministic diffuse tail; browser approximation, NOT REAPER ReaVerb.
    for(let ch=0;ch<2;ch++){
      const data=buffer.getChannelData(ch);
      for(let i=0;i<length;i++){
        const t=i/length;
        const grain=Math.sin((i+1)*(ch===0?4.417:4.731))*
          Math.sin((i+1)*(ch===0?1.831:1.967));
        data[i]=grain*(1-t)**2*.38;
      }
    }
    return buffer;
  }
  function build(context,source,fxList,output){
    if(!Array.isArray(fxList))fxList=[];
    const stages=[];let cursor=source;
    for(let i=0;i<fxList.length;i++){
      const fx=fxList[i],type=String(fx?.type||'');
      if(!specs[type])continue;
      const input=context.createGain(),merge=context.createGain();
      const dry=context.createGain(),wet=context.createGain();
      cursor.connect(input);input.connect(dry);dry.connect(merge);
      let processor,feedback=null;
      if(type==='eq'){
        processor=context.createBiquadFilter();processor.type='peaking';
      }else if(type==='compressor'){
        processor=context.createDynamicsCompressor();
      }else if(type==='delay'){
        processor=context.createDelay(2);feedback=context.createGain();
        processor.connect(feedback);feedback.connect(processor);
      }else if(type==='reverb'){
        processor=context.createConvolver();
      }
      input.connect(processor);processor.connect(wet);wet.connect(merge);
      stages.push({index:i,type,processor,dry,wet,feedback,impulseSeconds:0});
      cursor=merge;
    }
    cursor.connect(output);
    function update(nextFx){
      for(const stage of stages){
        const fx=nextFx?.[stage.index]||{};
        const p=parameters({...fx,type:stage.type});
        const enabled=fx.enabled!==false;
        const dry=(stage.type==='delay'||stage.type==='reverb'||!enabled)?1:0;
        const wet=enabled?((stage.type==='delay'||stage.type==='reverb')?p.wet:1):0;
        controlledParam(stage.dry.gain,dry,context);
        controlledParam(stage.wet.gain,wet,context);
        if(stage.type==='eq'){
          controlledParam(stage.processor.frequency,p.frequency,context);
          controlledParam(stage.processor.gain,p.gain,context);
          controlledParam(stage.processor.Q,p.q,context);
        }else if(stage.type==='compressor'){
          controlledParam(stage.processor.threshold,p.threshold,context);
          controlledParam(stage.processor.ratio,p.ratio,context);
          controlledParam(stage.processor.attack,p.attack,context);
          controlledParam(stage.processor.release,p.release,context);
        }else if(stage.type==='delay'){
          controlledParam(stage.processor.delayTime,p.time,context);
          controlledParam(stage.feedback.gain,enabled?p.feedback:0,context);
        }else if(stage.type==='reverb'&&stage.impulseSeconds!==p.seconds){
          stage.processor.buffer=impulse(context,p.seconds);
          stage.impulseSeconds=p.seconds;
        }
      }
    }
    update(fxList);
    return {update,stages};
  }
  window.SFStemFX={specs,parameters,build};
})();
