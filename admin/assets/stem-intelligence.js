(() => {
  'use strict';
  const MAX_TRACKS=16,MAX_FILE_BYTES=31457280,MAX_DURATION=600,WINDOW=2048,FRAMES=8;
  const BANDS=['sub_bass','bass','low_mid','presence','high'];
  const EDGES=[20,120,500,2000,8000,Infinity];
  const finite=(x,d=0)=>Number.isFinite(x)?x:d;
  const db=x=>x>1e-6?20*Math.log10(x):-120;
  function fftPower(real,imag){
    const n=real.length;
    for(let i=1,j=0;i<n;i++){
      let bit=n>>1;
      for(;j&bit;bit>>=1)j^=bit;
      j^=bit;
      if(i<j){
        let tmp=real[i];real[i]=real[j];real[j]=tmp;
        tmp=imag[i];imag[i]=imag[j];imag[j]=tmp;
      }
    }
    for(let len=2;len<=n;len<<=1){
      const a=-2*Math.PI/len,cr=Math.cos(a),ci=Math.sin(a);
      for(let i=0;i<n;i+=len){
        let wr=1,wi=0;
        for(let j=0;j<len/2;j++){
          const u=i+j,v=i+j+len/2;
          const tr=wr*real[v]-wi*imag[v],ti=wr*imag[v]+wi*real[v];
          real[v]=real[u]-tr;imag[v]=imag[u]-ti;
          real[u]+=tr;imag[u]+=ti;
          const next=wr*cr-wi*ci;wi=wr*ci+wi*cr;wr=next;
        }
      }
    }
  }
  function fftBands(channel,start,rate,energies){
    const real=new Float64Array(WINDOW),imag=new Float64Array(WINDOW);
    for(let i=0;i<WINDOW;i++)
      real[i]=channel[start+i]*(0.5-0.5*Math.cos(2*Math.PI*i/(WINDOW-1)));
    fftPower(real,imag);
    for(let bin=1;bin<WINDOW/2;bin++){
      const hz=bin*rate/WINDOW;
      if(hz<EDGES[0])continue;
      let band=EDGES.length-2;
      for(let k=0;k<EDGES.length-1;k++)if(hz<EDGES[k+1]){band=k;break;}
      energies[band]+=real[bin]*real[bin]+imag[bin]*imag[bin];
    }
  }
  function matchedClips(track,buffer){
    return (track.clips||[]).filter(c=>
      String(c.file||'').toLowerCase()===String(track.source_file||'').toLowerCase()&&
      Number.isFinite(Number(c.offset))&&Number(c.offset)>=0&&
      Number(c.length)>0&&Number(c.offset)+Number(c.length)>0&&
      Number(c.offset)<buffer.duration);
  }
  /**
   * Actually decode linked PRIVATE source media; inspect FFT/RMS on 8 short
   * Hann windows distributed across the first valid clip. This is not
   * native REAPER processing or a stem-separation/recognition neural model.
   */
  function analyzeBuffer(buffer,track){
    if(!buffer||buffer.numberOfChannels<1||buffer.numberOfChannels>2||
       !Number.isFinite(buffer.sampleRate)||buffer.sampleRate<8000||
       buffer.sampleRate>192000||buffer.duration>MAX_DURATION)
      throw Error('Unsupported or oversized decoded source.');
    const clips=matchedClips(track,buffer);
    if(!clips.length)throw Error('No valid linked REAPER clip with a source offset.');
    const c=clips[0],rate=buffer.sampleRate;
    const start=Math.max(0,Math.floor(Number(c.offset)*rate));
    const available=Math.min(Math.max(0,Math.floor(Number(c.length)*rate)),
      buffer.length-start,Math.floor(45*rate));
    if(available<WINDOW)throw Error('The source clip is shorter than an FFT window.');
    const channelCount=buffer.numberOfChannels;
    const channels=Array.from({length:channelCount},(_,i)=>buffer.getChannelData(i));
    const windows=Math.min(FRAMES,Math.max(1,Math.floor(available/WINDOW)));
    const bandEnergy=new Float64Array(5);
    let peak=0,lEnergy=0,rEnergy=0,active=0;
    for(let w=0;w<windows;w++){
      const pos=start+(windows===1?0:Math.floor((available-WINDOW)*w/(windows-1)));
      let frameEnergy=0;
      for(let ch=0;ch<channelCount;ch++){
        const data=channels[ch];
        fftBands(data,pos,rate,bandEnergy);
        for(let i=0;i<WINDOW;i++){
          const v=finite(data[pos+i]);
          peak=Math.max(peak,Math.abs(v));
          const power=v*v;frameEnergy+=power;
          if(ch===0)lEnergy+=power;else rEnergy+=power;
        }
      }
      if(Math.sqrt(frameEnergy/(WINDOW*channelCount))>0.01)active++;
    }
    if(channelCount===1)rEnergy=lEnergy;
    const rms=Math.sqrt((lEnergy+rEnergy)/Math.max(1,2*WINDOW*windows));
    const energy=bandEnergy.reduce((a,b)=>a+b,0);
    const shares={};
    for(let i=0;i<BANDS.length;i++)shares[BANDS[i]]=energy>1e-15?
      Math.round(bandEnergy[i]*10000/energy)/100:0;
    // Coherent rounding avoids rejecting valid 100% distributions.
    return {
      sample_rate:rate,windows,observed_seconds:WINDOW*windows/rate,
      sample_peak_dbfs:db(peak),rms_dbfs:db(rms),
      crest_db:Math.max(0,db(peak)-db(rms)),
      stereo_imbalance_db:Math.abs(db(Math.sqrt(lEnergy/(WINDOW*windows)))-
        db(Math.sqrt(rEnergy/(WINDOW*windows)))),
      active_window_ratio:active/windows,bands_pct:shares
    };
  }
  function render(){
    return '<section class="sf-k-intelligence" data-k-intelligence>'+
      '<h3>86K · Per-Stem Audio Intelligence</h3>'+
      '<p>Measures the actual decoded private WAV/MP3 sources—not native REAPER FX. Shows peaks, dynamics, spectral energy, near-silence and potential overlap across clips. No audio is uploaded to an LLM.</p>'+
      '<div class="sf-j-buttons"><button type="button" data-k-scan>Measure private stems</button>'+
      '<button type="button" data-k-save disabled>Save bounded evidence to Agent Brain</button></div>'+
      '<p data-k-status role="status" aria-live="polite">Up to 16 stems per scan. Source files are decoded locally; audio never leaves its existing private storage.</p>'+
      '<div data-k-results></div>'+
      '<p class="sf-g-note">Each source uses up to eight 2048-sample windows, distributed over at most 45 seconds of one linked clip. Measurements are pre-fader and pre-FX, not the complete processed mix. Frequency-band overlap is a possible masking risk—not proof. Browser evidence is not server certified.</p>'+
      '</section>';
  }
  const fmt=n=>Number.isFinite(n)?n.toFixed(1):'—';
  function bind(root,studio){
    const section=root.querySelector('[data-k-intelligence]');if(!section)return;
    const q=s=>section.querySelector(s),status=s=>q('[data-k-status]').textContent=s;
    const controller=new AbortController();let pending=null;
    studio._stemIntelligenceDispose=()=>controller.abort();
    const show=rows=>{
      const output=q('[data-k-results]');output.replaceChildren();
      const list=document.createElement('div');list.className='sf-k-list';
      for(const row of rows){
        const item=document.createElement('article');item.className='sf-k-item';
        const title=document.createElement('strong');title.textContent=row.name;
        const m=row.metrics;
        const details=document.createElement('p');
        details.textContent='Peak '+fmt(m.sample_peak_dbfs)+' dBFS · RMS '+fmt(m.rms_dbfs)+
          ' dBFS · crest '+fmt(m.crest_db)+' dB · stereo difference '+fmt(m.stereo_imbalance_db)+
          ' dB · active '+fmt(m.active_window_ratio*100)+'% windows';
        const band=document.createElement('small');
        band.textContent=BANDS.map(k=>k.replace('_',' ')+' '+fmt(m.bands_pct[k])+'%').join(' · ');
        item.append(title,details,band);list.append(item);
      }
      output.append(list);
    };
    q('[data-k-scan]').onclick=async()=>{
      const button=q('[data-k-scan]');button.disabled=true;
      q('[data-k-save]').disabled=true;pending=null;
      const revision=studio.project.revision,tracks=(studio.project.tracks||[])
        .filter(t=>t.asset_uuid&&t.media_ready&&t.audio_url&&matchedClips(t,
          {duration:MAX_DURATION}).length).slice(0,MAX_TRACKS);
      if(!tracks.length){status('No playable linked source clips. Link your private stems first.');button.disabled=false;return}
      const reports=[],display=[],skipped=[];
      const Context=window.AudioContext||window.webkitAudioContext;
      if(!Context){status('This browser does not support Web Audio decoding.');button.disabled=false;return}
      const decoder=new Context({sampleRate:48000});
      try{
        for(const [index,track] of tracks.entries()){
          if(controller.signal.aborted)throw Error('Scan was cancelled because Stem Studio closed.');
          if(studio.project.revision!==revision)throw Error('The project changed. Start a fresh scan.');
          status('Decoding private stem '+(index+1)+'/'+tracks.length+'…');
          try{
            const known=Number(track.audio_size_bytes||0);
            if(known>MAX_FILE_BYTES)throw Error('Source exceeds the 30 MiB safe decode limit.');
            const response=await fetch(track.audio_url,{
              credentials:'same-origin',cache:'no-store',signal:controller.signal});
            if(!response.ok)throw Error('Private audio request HTTP '+response.status);
            const length=Number(response.headers?.get('content-length')||0);
            if(length>MAX_FILE_BYTES)throw Error('Source is larger than 30 MiB.');
            const bytes=await response.arrayBuffer();
            if(bytes.byteLength>MAX_FILE_BYTES)throw Error('Source exceeds 30 MiB.');
            const buffer=await decoder.decodeAudioData(bytes);
            const metrics=analyzeBuffer(buffer,track);
            reports.push({guid:track.guid,source:'browser_decoded_private_source',metrics});
            display.push({name:track.name||track.guid,metrics});
          }catch(e){
            if(controller.signal.aborted)throw e;
            skipped.push((track.name||track.guid)+': '+e.message);
          }
        }
        if(studio.project.revision!==revision)throw Error('The saved project changed; measurements must be discarded.');
        if(!reports.length)throw Error('No compatible stems could be measured. '+skipped.slice(0,3).join('; '));
        pending={action:'save_measurements',track_id:studio.id,revision,tracks:reports};
        show(display);q('[data-k-save]').disabled=false;
        status('Measured '+reports.length+' of '+tracks.length+' eligible source stems'+
          (skipped.length?'; skipped '+skipped.length+': '+skipped.slice(0,2).join('; '):'')+
          '. Save to share only bounded measurements with Admin Agent Brain.');
      }catch(e){status('Stem scan stopped: '+e.message)}
      finally{await decoder.close().catch(()=>{});button.disabled=false}
    };
    q('[data-k-save]').onclick=async()=>{
      if(!pending)return;
      const button=q('[data-k-save]');button.disabled=true;
      try{
        if(studio.project.revision!==pending.revision)throw Error('Project changed; rescan.');
        const saved=await window.STONEFELLOW_ADMIN_CONTEXT.api('stem-intelligence.php',pending);
        const summary=saved.summary||{};
        pending=null;
        const items=(summary.masking_candidates||[]).map(p=>
          'Possible '+p.band.replace('_',' ')+' overlap between '+p.left+' and '+p.right);
        status('Saved '+summary.analyzed_stems+' private stems. '+items.slice(0,3).join('; ')+
          '. Measurements are browser-origin evidence, not verified audio.');
        root.querySelector('[data-j-inspect]')?.click();
      }catch(e){status('Stem evidence was not saved: '+e.message);button.disabled=false}
    };
  }
  window.SFStemIntelligence={fftPower,analyzeBuffer,render,bind};
})();