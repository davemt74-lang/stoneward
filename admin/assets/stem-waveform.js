(() => {
  'use strict';
  /** Real decoded amplitude peaks; never invent waveforms or download large masters automatically. */
  const cache=new Map();
  const maxBytes=32*1024*1024; // browser decode safeguard; larger stems still play normally
  function peaks(buffer,buckets=320){
    if(!buffer||!buffer.length||!buffer.numberOfChannels)return [];
    const result=[];
    const count=Math.max(32,Math.min(600,Math.floor(buckets)||320));
    const channels=Math.min(2,buffer.numberOfChannels);
    const data=Array.from({length:channels},(_,i)=>buffer.getChannelData(i));
    for(let i=0;i<count;i++){
      const start=Math.floor(i*buffer.length/count),end=Math.min(buffer.length,Math.floor((i+1)*buffer.length/count));
      const step=Math.max(1,Math.floor((end-start)/60));
      let peak=0;
      for(let j=start;j<end;j+=step)for(const ch of data){
        const v=Math.abs(Number(ch[j])||0);
        if(v>peak)peak=v;
      }
      result.push(Math.max(.015,Math.min(1,peak)));
    }
    return result;
  }
  function draw(canvas,values){
    if(!canvas||!values?.length)return;
    const bounds=canvas.getBoundingClientRect();
    const width=Math.max(1,Math.floor(bounds.width||canvas.clientWidth||320));
    const height=Math.max(1,Math.floor(bounds.height||canvas.clientHeight||60));
    const pixelRatio=Math.min(2,Math.max(1,window.devicePixelRatio||1));
    canvas.width=Math.floor(width*pixelRatio);
    canvas.height=Math.floor(height*pixelRatio);
    const c=canvas.getContext('2d');if(!c)return;
    c.setTransform(pixelRatio,0,0,pixelRatio,0,0);
    c.clearRect(0,0,width,height);
    c.fillStyle='#f0c98c';
    const each=width/values.length,center=height/2;
    values.forEach((v,i)=>{
      const amp=Math.max(1,(height-5)*Math.min(1,v)/2);
      c.fillRect(i*each,center-amp,Math.max(.6,each*.76),amp*2);
    });
  }
  async function load(canvas,url,size){
    if(!url||!canvas)throw new Error('Assign audio to this stem before drawing a waveform.');
    if(Number(size)>maxBytes)throw new Error('Audio exceeds the 32 MiB waveform limit; playback remains available.');
    const key=String(url);
    if(cache.has(key)){draw(canvas,cache.get(key));return cache.get(key);}
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});
    if(!response.ok)throw new Error('Audio cannot be loaded for waveform inspection.');
    const headerSize=Number(response.headers.get('Content-Length')||0);
    if(headerSize>maxBytes)throw new Error('Stem is too large for browser waveform decoding.');
    const raw=await response.arrayBuffer();
    if(raw.byteLength>maxBytes)throw new Error('Stem is too large for browser waveform decoding.');
    const Ctx=window.AudioContext||window.webkitAudioContext;
    if(!Ctx)throw new Error('Web Audio is unavailable in this browser.');
    const ctx=new Ctx();
    let values;
    try{
      const audio=await ctx.decodeAudioData(raw);
      values=peaks(audio,320);
    }finally{await ctx.close();}
    cache.set(key,values);
    // Keep only a small number of decoded peak arrays, never raw audio.
    if(cache.size>24)cache.delete(cache.keys().next().value);
    draw(canvas,values);
    return values;
  }
  window.SFStemWaveform={peaks,draw,load};
})();
