(() => {
'use strict';
const MAX_STEMS=16,MAX_BYTES=31457280,N=2048;
const BAND_KEYS=['sub_bass','bass','low_mid','presence','high'];
const BOUNDARIES=[20,120,500,2000,8000,Infinity];
const db=p=>p>1e-12?10*Math.log10(p):-120;
const valid=n=>Number.isFinite(n)?n:0;
function windowsAt(buffer,track,time){
  const choices=(track.clips||[]).filter(clip=>
    String(clip.file||'').toLowerCase()===String(track.source_file||'').toLowerCase()&&
    time>=Number(clip.position)&&time<Number(clip.position)+Number(clip.length)&&
    Number(clip.offset)>=0);
  // Overlapping takes require REAPER playback-mode interpretation. Skip rather
  // than double-counting or silently choosing one audible take.
  if(choices.length!==1)return null;
  const clip=choices[0],rate=buffer.sampleRate;
  const offset=Number(clip.offset)+time-Number(clip.position);
  const frame=Math.floor(offset*rate);
  if(frame<0||frame+N>buffer.length)return null;
  return frame;
}
function measureWindow(buffer,frame){
  const fft=window.SFStemIntelligence?.fftPower;
  if(!fft)throw Error('86K FFT engine is required for time-aligned analysis.');
  const bands=new Float64Array(5);
  const channels=Math.min(buffer.numberOfChannels,2);
  let rmsPower=0;
  for(let c=0;c<channels;c++){
    const real=new Float64Array(N),imag=new Float64Array(N);
    const source=buffer.getChannelData(c);
    for(let i=0;i<N;i++){
      const value=valid(source[frame+i]);
      rmsPower+=value*value/(N*channels);
      real[i]=value*(.5-.5*Math.cos(2*Math.PI*i/(N-1)));
    }
    fft(real,imag);
    for(let bin=1;bin<N/2;bin++){
      const hz=bin*buffer.sampleRate/N;
      if(hz<20)continue;
      let band=4;
      for(let j=0;j<5;j++)if(hz<BOUNDARIES[j+1]){band=j;break;}
      // Parseval single-sided energy estimate correcting the Hann RMS window
      // (mean square ≈ 3/8). This is sparse source PCM, not a mastered FFT.
      const power=2*(real[bin]*real[bin]+imag[bin]*imag[bin])/
        (N*N*.375*channels);
      bands[band]+=power;
    }
  }
  const bandLevels={};
  for(let i=0;i<5;i++)bandLevels[BAND_KEYS[i]]=Math.max(-120,Math.min(12,db(bands[i])));
  return {rms_dbfs:Math.max(-120,Math.min(12,db(rmsPower))),bands_dbfs:bandLevels};
}
function measureTrack(buffer,track,grid){
  if(buffer.sampleRate<8000||buffer.sampleRate>192000||
     buffer.duration>600||buffer.numberOfChannels<1||buffer.numberOfChannels>2)
    throw Error('Unsupported source sample rate, length or channel count.');
  const points=[];
  for(const time of grid){
    const frame=windowsAt(buffer,track,time);
    if(frame===null)continue;
    points.push({time:Math.round(time*1000)/1000,...measureWindow(buffer,frame)});
  }
  return points;
}
function grid(start,end,step){
  const result=[];
  for(let i=0;i<120;i++){
    const time=start+i*step;
    if(time>end+.00001)break;
    result.push(Math.round(time*1000)/1000);
  }
  return result;
}
function setting(mode,duration,anchor){
  const full=Math.max(1,Math.min(480,Number(duration)||1));
  if(mode==='focus'){
    const start=Math.max(0,Math.min(full-1,Math.floor((anchor||0)-6)));
    const end=Math.min(full,start+12);
    return {mode:'focus',start_seconds:start,end_seconds:end,step_seconds:.5};
  }
  const step=Math.max(.5,Math.ceil(full/119*2)/2);
  const count=Math.min(120,Math.floor(full/step)+1);
  return {mode:'overview',start_seconds:0,
    end_seconds:Math.round((count-1)*step*1000)/1000,step_seconds:step};
}
function render(){
  return '<section class="sf-l-alignment" data-l-alignment>'+
    '<h3>86L · Time-Aligned Mix Intelligence</h3>'+
    '<p>Real, project-timed snapshots of decoded private stem audio. Compare the same musical moments—not just whole-stem average frequency profiles.</p>'+
    '<div class="sf-l-actions"><button type="button" data-l-overview>Analyze full-song timeline</button>'+
    '<label>Focus around second <input data-l-focus-at type="number" min="0" step=".5" value="0"></label>'+
    '<button type="button" data-l-focus>Measure 12-second passage</button></div>'+
    '<p data-l-status role="status" aria-live="polite">Start with the overview, then zoom into a flagged passage. No music files are sent to an LLM.</p>'+
    '<div data-l-map></div><div data-l-findings></div>'+
    '<p class="sf-g-note">Time points align to the REAPER clip timeline with 2048-sample source FFTs. Samples are pre-FX and pre-fader; no native plugin processing is claimed. A frequency match is a listening cue, not proof of masking. No automatic edits.</p></section>';
}
function bind(root,studio){
  const section=root.querySelector('[data-l-alignment]');if(!section)return;
  const q=s=>section.querySelector(s),status=t=>q('[data-l-status]').textContent=t;
  let controller=new AbortController(),busy=false;
  studio._stemAlignmentDispose=()=>controller.abort();
  const jump=time=>{
    const at=Math.max(0,Number(time)||0);
    if(typeof studio.seek==='function')studio.seek(at);
    q('[data-l-focus-at]').value=at.toFixed(1);
    status('Timeline moved to '+at.toFixed(1)+'s. Listen with the existing mixer, then refine this passage.');
  };
  function draw(analysis){
    const map=q('[data-l-map]'),list=q('[data-l-findings]');
    map.replaceChildren();list.replaceChildren();
    const scans=(analysis.scans||[]).filter(s=>s.status==='recent');
    if(!scans.length){status('No current analysis. Run a scan on the saved mix.');return}
    const latest=scans.at(-1);
    const start=latest.start_seconds,end=latest.end_seconds;
    const ruler=document.createElement('div');ruler.className='sf-l-ruler';
    ruler.setAttribute('role','group');ruler.setAttribute('aria-label','Clickable time-aligned mix events');
    for(const event of (latest.findings||[]).slice(0,48)){
      const pin=document.createElement('button');pin.type='button';pin.className='sf-l-pin';
      pin.style.left=(Math.max(0,Math.min(100,(event.time-start)/Math.max(.001,end-start)*100)))+'%';
      pin.title='Potential '+event.band+' overlap at '+event.time+'s';
      pin.setAttribute('aria-label',pin.title);
      pin.onclick=()=>jump(event.time);
      ruler.appendChild(pin);
    }
    map.append(ruler);
    const subtitle=document.createElement('p');
    subtitle.textContent='Snapshot '+latest.mode+' · '+start+'–'+end+' s · '+latest.step_seconds+
      ' s interval · '+latest.sampled_stem_count+' stems · '+latest.findings.length+
      ' highest-confidence potential overlaps.';
    map.append(subtitle);
    if(!(latest.findings||[]).length){
      const no=document.createElement('p');
      no.textContent='No simultaneous, sufficiently strong band-energy events in sampled moments. This does not prove the mix is clear everywhere.';
      list.append(no);
    }
    for(const event of (latest.findings||[]).slice(0,24)){
      const item=document.createElement('article');item.className='sf-l-finding';
      const title=document.createElement('strong');
      title.textContent=event.time.toFixed(1)+'s · '+event.band.replace('_',' ')+' · '+event.track_a+' / '+event.track_b;
      const explain=document.createElement('p');
      explain.textContent=event.levels_dbfs.join(' / ')+' dBFS relative sampled band levels. '+
        'Potential conflict only; confirm audibly.';
      const button=document.createElement('button');button.type='button';
      button.textContent='Jump to passage';
      button.onclick=()=>jump(event.time);
      const focus=document.createElement('button');focus.type='button';
      focus.textContent='Inspect this passage in detail';
      focus.onclick=()=>scan('focus',event.time);
      item.append(title,explain,button,focus);list.append(item);
    }
    status('Analysis saved and linked to project revision '+studio.project.revision+
      '. Use timeline markers and 12-second focus to inspect a suspicious passage.');
    root.querySelector('[data-j-inspect]')?.click();
  }
  async function scan(mode,anchor=0){
    if(busy)return;
    busy=true;
    const buttons=section.querySelectorAll('button');
    buttons.forEach(b=>b.disabled=true);
    controller.abort();controller=new AbortController();
    const settingValue=setting(mode,studio.duration,anchor);
    const times=grid(settingValue.start_seconds,settingValue.end_seconds,settingValue.step_seconds);
    const revision=studio.project.revision;
    const tracks=(studio.project.tracks||[]).filter(t=>
      t.asset_uuid&&t.media_ready&&t.audio_url&&t.source_file&&
      (t.clips||[]).some(c=>String(c.file||'').toLowerCase()===String(t.source_file).toLowerCase()))
      .slice(0,MAX_STEMS);
    if(!tracks.length){status('Link playable private stems before analysis.');busy=false;buttons.forEach(b=>b.disabled=false);return}
    const Ctx=window.AudioContext||window.webkitAudioContext;
    if(!Ctx){status('Web Audio decoding is not supported in this browser.');busy=false;buttons.forEach(b=>b.disabled=false);return}
    const decoder=new Ctx({sampleRate:48000});
    const data=[],skipped=[];
    try{
      for(const [index,track] of tracks.entries()){
        if(studio.project.revision!==revision||controller.signal.aborted)
          throw Error('Project changed or scan cancelled.');
        status('Aligning private source '+(index+1)+' of '+tracks.length+'…');
        try{
          const r=await fetch(track.audio_url,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
          if(!r.ok)throw Error('Private media unavailable (HTTP '+r.status+').');
          if(Number(r.headers.get('content-length')||0)>MAX_BYTES)throw Error('Private file exceeds 30 MiB.');
          const bytes=await r.arrayBuffer();
          if(bytes.byteLength>MAX_BYTES)throw Error('Private file exceeds 30 MiB.');
          const buffer=await decoder.decodeAudioData(bytes);
          const points=measureTrack(buffer,track,times);
          if(points.length)data.push({guid:track.guid,origin:'browser_raw_private_clip',points});
          else skipped.push(track.name||track.guid);
        }catch(e){
          if(controller.signal.aborted)throw e;
          skipped.push((track.name||track.guid)+': '+e.message);
        }
      }
      if(!data.length)throw Error('No time-aligned playable private clips in this region.');
      if(studio.project.revision!==revision)throw Error('Mix revision changed; rescan.');
      status('Saving bounded alignment statistics. Raw PCM stays inside your browser…');
      const result=await window.STONEFELLOW_ADMIN_CONTEXT.api('stem-alignment.php',{
        action:'save_alignment',track_id:studio.id,revision,...settingValue,tracks:data
      });
      draw(result.analysis);
      if(skipped.length)status(q('[data-l-status]').textContent+' Skipped '+skipped.length+
        ' unavailable or ambiguous source(s).');
    }catch(e){status('Time-aligned scan not saved: '+e.message)}
    finally{await decoder.close().catch(()=>{});busy=false;buttons.forEach(b=>b.disabled=false);}
  }
  q('[data-l-overview]').onclick=()=>scan('overview',0);
  q('[data-l-focus]').onclick=()=>scan('focus',Number(q('[data-l-focus-at]').value)||0);
  // Existing private status is read-only; no scan until requested by Admin.
  fetch('api/stem-alignment.php?track_id='+encodeURIComponent(studio.id),{
    credentials:'same-origin',cache:'no-store',signal:controller.signal
  }).then(r=>r.ok?r.json():null).then(data=>{
    if(data?.ok&&data.analysis.status==='recent')draw(data.analysis);
  }).catch(()=>{});
}
window.SFStemAlignment={measureWindow,measureTrack,grid,setting,windowsAt,render,bind};
})();