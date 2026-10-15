(() => {
  'use strict';
  const RATE=48000,MAX_SECONDS=480;
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const safe=(n,def=0)=>Number.isFinite(Number(n))?Number(n):def;
  const db=x=>x>1e-6?20*Math.log10(x):-120;
  const fxInfo=project=>{
    let unsupported=0;
    const chains=[project.master_fx||[],...(project.tracks||[]).map(t=>t.fx||[]),
      ...(project.routing?.buses||[]).map(b=>b.fx||[])];
    for(const chain of chains)for(const fx of chain)
      if(fx.enabled!==false && !window.SFStemFX.specs[fx.type])unsupported++;
    return unsupported;
  };
  const filename=s=>String(s||'mix').replace(/[^a-z0-9_-]/gi,'_').slice(0,60)||'mix';
  function wav(buffer){
    if(buffer.numberOfChannels!==2||buffer.sampleRate!==RATE)throw Error('Stereo 48 kHz audio is required.');
    const frames=buffer.length,bytes=44+frames*4;
    if(bytes>MAX_SECONDS*RATE*4+44)throw Error('Export exceeds the 8 minute browser render limit.');
    const out=new ArrayBuffer(bytes),view=new DataView(out);
    let p=0;
    const four=s=>{for(let k=0;k<4;k++)view.setUint8(p++,s.charCodeAt(k));};
    const u32=v=>{view.setUint32(p,v,true);p+=4;};
    const u16=v=>{view.setUint16(p,v,true);p+=2;};
    four('RIFF');u32(bytes-8);four('WAVE');four('fmt ');u32(16);u16(1);u16(2);
    u32(RATE);u32(RATE*4);u16(4);u16(16);four('data');u32(frames*4);
    const left=buffer.getChannelData(0),right=buffer.getChannelData(1);
    for(let i=0;i<frames;i++){
      let v=Number.isFinite(left[i])?Math.max(-1,Math.min(1,left[i])):0;
      view.setInt16(p,v<0?Math.round(v*32768):Math.round(v*32767),true);p+=2;
      v=Number.isFinite(right[i])?Math.max(-1,Math.min(1,right[i])):0;
      view.setInt16(p,v<0?Math.round(v*32768):Math.round(v*32767),true);p+=2;
    }
    return new Blob([out],{type:'audio/wav'});
  }
  /**
   * K-weighting EBU R128 filter coefficients at exactly 48 kHz. 400ms blocks
   * 100ms hop, -70 absolute gate, -10 LU relative gate. Stereo weights = 1.
   * This is a browser BS.1770-style LUFS estimate, NOT a mastering certificate.
   */
  function analyze(buffer){
    if(buffer.numberOfChannels<1||buffer.sampleRate!==RATE)throw Error('Analysis requires a 48 kHz audio buffer.');
    const mono=buffer.numberOfChannels===1;
    const l=buffer.getChannelData(0),r=mono?l:buffer.getChannelData(1);
    const n=l.length,win=19200,hop=4800;
    let peak=0,clips=0,lSum=0,rSum=0;
    const filters=[0,1].map(()=>[
      {b:[1.53512485958697,-2.69169618940638,1.19839281085285],a:[-1.69065929318241,.73248077421585],z1:0,z2:0},
      {b:[1,-2,1],a:[-1.99004745483398,.99007225036621],z1:0,z2:0}
    ]);
    function sample(v,channel){
      for(const f of filters[channel]){
        const y=f.b[0]*v+f.z1;
        f.z1=f.b[1]*v-f.a[0]*y+f.z2;
        f.z2=f.b[2]*v-f.a[1]*y;
        v=y;
      }
      return v;
    }
    const ring=new Float64Array(win),blocks=[];
    let sum=0;
    for(let i=0;i<n;i++){
      const a=Number.isFinite(l[i])?l[i]:0,b=Number.isFinite(r[i])?r[i]:0;
      const ap=Math.abs(a),bp=Math.abs(b);
      peak=Math.max(peak,ap,bp);
      if(ap>=.999)clips++;if(!mono&&bp>=.999)clips++;
      lSum+=a*a;if(!mono)rSum+=b*b;
      const ka=sample(a,0),kb=mono?0:sample(b,1);
      const energy=ka*ka+kb*kb,idx=i%win;
      sum+=energy-ring[idx];ring[idx]=energy;
      if(i+1>=win && (i+1-win)%hop===0)blocks.push(Math.max(0,sum/win));
    }
    const lum=x=>x>0?-0.691+10*Math.log10(x):-120;
    const absolute=blocks.filter(x=>lum(x)>=-70);
    const preliminary=absolute.length?lum(absolute.reduce((a,b)=>a+b,0)/absolute.length):null;
    const threshold=Math.max(-70,(preliminary??-120)-10);
    const gated=blocks.filter(x=>lum(x)>=threshold);
    const lufs=gated.length?lum(gated.reduce((a,b)=>a+b,0)/gated.length):null;
    const rms=Math.sqrt((lSum+rSum)/Math.max(1,(mono?1:2)*n)),lRms=Math.sqrt(lSum/Math.max(1,n)),
      rRms=mono?lRms:Math.sqrt(rSum/Math.max(1,n));
    return {
      duration_seconds:n/RATE,sample_rate:RATE,
      sample_peak_dbfs:db(peak),integrated_lufs:lufs,
      rms_dbfs:db(rms),crest_db:Math.max(0,db(peak)-db(rms)),
      stereo_imbalance_db:Math.abs(db(lRms)-db(rRms)),clipped_samples:clips,
    };
  }
  function paramSchedule(param,base,lanes,scope,guid,name,fxIndex=null,fxParam=''){
    if(!param)return;
    const matched=(lanes||[]).filter(l=>l.enabled!==false&&l.scope===scope&&l.guid===guid&&
      l.parameter===name&&(name!=='fx'||(l.fx_index===fxIndex&&l.fx_parameter===fxParam)));
    // Imported native envelopes may have a browser overlay for the same target.
    const lane=matched.findLast(l=>l.source==='BROWSER')||matched.at(-1);
    param.setValueAtTime(base,0);
    if(!lane?.points?.length)return;
    const p=lane.points;
    param.setValueAtTime(safe(p[0].value,base),0);
    for(let i=0;i<p.length;i++){
      const cur=p[i],next=p[i+1],at=Math.max(0,Math.min(MAX_SECONDS,safe(cur.time)));
      param.setValueAtTime(safe(cur.value,base),at);
      if(next){
        const end=Math.max(at,Math.min(MAX_SECONDS,safe(next.time)));
        if(cur.shape!=='square'&&end>at)param.linearRampToValueAtTime(safe(next.value,base),end);
      }
    }
  }
  function scheduleFx(rack,chain,lanes,scope,guid){
    for(const stage of rack?.stages||[]){
      const fx=chain[stage.index],type=stage.type;
      for(const [key,ranges] of Object.entries(window.SFStemFX.specs[type]||{})){
        const list=(lanes||[]).filter(l=>l.enabled!==false&&l.scope===scope&&l.guid===guid&&l.parameter==='fx'&&
          l.fx_index===stage.index&&l.fx_parameter===key);
        if(!list.length)continue;
        const target={
          eq: {frequency:stage.processor.frequency,gain:stage.processor.gain,q:stage.processor.Q},
          compressor:{threshold:stage.processor.threshold,ratio:stage.processor.ratio,
            attack:stage.processor.attack,release:stage.processor.release},
          limiter:{threshold:stage.processor.threshold,attack:stage.processor.attack,
            release:stage.processor.release,ceiling:stage.ceilingGain?.gain},
          delay:{time:stage.processor.delayTime,feedback:stage.feedback?.gain,wet:stage.wet.gain},
          reverb:{wet:stage.wet.gain}
        };
        let param=type==='eq5'
          ?stage.bands?.[Number(key.slice(1))-1]?.[key[0]==='f'?'frequency':(key[0]==='g'?'gain':'Q')]
          :target[type]?.[key];
        if(type==='limiter'&&key==='ceiling'){
          // Ceiling stored as dB, applied as linear gain.
          const mapped=list.map(l=>({...l,points:l.points.map(p=>({...p,value:Math.pow(10,p.value/20)}))}));
          paramSchedule(param,Math.pow(10,safe(fx.settings?.ceiling,-.1)/20),mapped,scope,guid,'fx',stage.index,key);
          continue;
        }
        if(type==='reverb'&&key==='seconds'){
          throw Error('Dynamic reverb impulse length cannot be rendered accurately. Remove that automation lane.');
        }
        if(!param)throw Error('Unsupported automated FX parameter: '+type+'/'+key);
        paramSchedule(param,safe(fx.settings?.[key],ranges[2]),lanes,scope,guid,'fx',stage.index,key);
      }
    }
  }
  function scheduleMix(project,studio,context,tracks,buses,master,fxRacks){
    const lanes=project.automation||[],globalSolo=Object.values(studio.mixing||{}).some(m=>m.solo);
    for(const t of project.tracks||[]){
      const node=tracks.get(t.guid),mix=studio.mixing[t.guid]||t;
      paramSchedule(node.gain.gain,safe(mix.volume,1),lanes,'track',t.guid,'volume');
      paramSchedule(node.pan.pan,safe(mix.pan,0),lanes,'track',t.guid,'pan');
      const gate=globalSolo&&!mix.solo?0:mix.mute?0:1;
      // Mute envelope 0 means audible and 1 means muted.
      const muteLanes=lanes.filter(l=>l.scope==='track'&&l.guid===t.guid&&l.parameter==='mute').map(l=>
        ({...l,points:l.points.map(p=>({...p,value:p.value>=.5?0:1}))}));
      paramSchedule(node.gate.gain,gate,muteLanes,'track',t.guid,'mute');
      if(globalSolo&&!mix.solo){node.gate.gain.cancelScheduledValues(0);node.gate.gain.setValueAtTime(0,0);}
      scheduleFx(node.fx,t.fx||[],lanes,'track',t.guid);
    }
    for(const bus of project.routing?.buses||[]){
      const node=buses.get(bus.id),mix=studio.busMix[bus.id]||bus;
      paramSchedule(node.gain.gain,safe(mix.volume,1),lanes,'bus',bus.id,'volume');
      paramSchedule(node.pan.pan,safe(mix.pan,0),lanes,'bus',bus.id,'pan');
      const muteLanes=lanes.filter(l=>l.scope==='bus'&&l.guid===bus.id&&l.parameter==='mute').map(l=>
        ({...l,points:l.points.map(p=>({...p,value:p.value>=.5?0:1}))}));
      paramSchedule(node.gate.gain,mix.mute?0:1,muteLanes,'bus',bus.id,'mute');
      scheduleFx(node.fx,bus.fx||[],lanes,'bus',bus.id);
    }
    paramSchedule(master.gain,.82,lanes,'master','__master__','volume');
    scheduleFx(fxRacks,project.master_fx||[],lanes,'master','__master__');
  }
  async function renderOffline(studio,onProgress=()=>{}){
    const project=studio.project,duration=studio.duration;
    if(!project||!Number.isFinite(duration)||duration<0.1||duration>MAX_SECONDS)
      throw Error('Render duration must be between 0.1 second and 8 minutes.');
    if(!window.OfflineAudioContext)throw Error('This browser does not support OfflineAudioContext.');
    const originals=(project.tracks||[]).filter(t=>t.source_file&&t.media_ready!==true);
    if(originals.length)throw Error('Link all '+originals.length+' referenced private stem(s) before rendering.');
    const context=new OfflineAudioContext(2,Math.ceil(duration*RATE),RATE);
    const master=context.createGain(),processor=context.createGain();
    const masterFx=window.SFStemFX.build(context,master,project.master_fx||[],processor);
    processor.connect(context.destination);
    const tracks=new Map(),buses=new Map();
    for(const bus of project.routing?.buses||[]){
      const input=context.createGain(),gain=context.createGain(),gate=context.createGain(),
        pan=context.createStereoPanner();
      const fx=window.SFStemFX.build(context,input,bus.fx||[],gain);
      gain.connect(gate);gate.connect(pan);
      pan.connect(master);
      buses.set(bus.id,{input,gain,gate,pan,fx,out:pan});
    }
    for(const t of project.tracks||[]){
      const input=context.createGain(),gain=context.createGain(),gate=context.createGain(),
        pan=context.createStereoPanner();
      const fx=window.SFStemFX.build(context,input,t.fx||[],gain);
      gain.connect(gate);gate.connect(pan);
      tracks.set(t.guid,{input,gain,gate,pan,fx,out:pan});
    }
    for(const t of project.tracks||[]){
      if(t.main_send_enabled===false)continue;
      const node=tracks.get(t.guid),parent=tracks.get(t.folder_parent_guid)?.input||buses.get(t.folder_parent_guid)?.input;
      node.out.connect(parent||master);
    }
    for(const send of project.routing?.sends||[]){
      if(send.enabled===false||send.mode!=='post-fader')continue;
      const src=tracks.get(send.source_guid)?.out||buses.get(send.source_guid)?.out;
      const dest=tracks.get(send.target_guid)?.input||buses.get(send.target_guid)?.input;
      if(!src||!dest)throw Error('The current routing graph contains an unresolved send.');
      const level=context.createGain();level.gain.value=safe(send.gain,0);
      src.connect(level);level.connect(dest);
    }
    scheduleMix(project,studio,context,tracks,buses,master,masterFx);
    let clipsMade=0;
    const decoding=new Map();
    const decoder=new (window.AudioContext||window.webkitAudioContext)({sampleRate:RATE});
    try{
      let i=0;
      for(const t of project.tracks||[]){
        i++;
        const clips=(t.clips||[]).filter(c=>c.length>0&&
          String(c.file||'').toLowerCase()===String(t.source_file||'').toLowerCase());
        if(!t.audio_url||!clips.length)continue;
        onProgress('Decoding linked stem '+i+' of '+project.tracks.length+'…');
        let buffer=decoding.get(t.audio_url);
        if(!buffer){
          const response=await fetch(t.audio_url,{credentials:'same-origin',cache:'no-store'});
          if(!response.ok)throw Error('Private stem could not be read (HTTP '+response.status+').');
          const bytes=await response.arrayBuffer();
          buffer=await decoder.decodeAudioData(bytes);
          decoding.set(t.audio_url,buffer);
        }
        for(const clip of clips){
          const start=safe(clip.position),offset=safe(clip.offset),length=safe(clip.length);
          const playable=Math.min(length,buffer.duration-offset,duration-start);
          if(start>=duration||offset>=buffer.duration||playable<=0)continue;
          const src=context.createBufferSource();src.buffer=buffer;src.connect(tracks.get(t.guid).input);
          src.start(start,offset,playable);clipsMade++;
        }
      }
    }finally{await decoder.close().catch(()=>{});}
    if(!clipsMade)throw Error('No playable private stem clips were found.');
    onProgress('Rendering '+clipsMade+' audio clips through the supported mixer graph…');
    return await context.startRendering();
  }
  const fmt=v=>Number.isFinite(v)?(Math.round(v*100)/100).toFixed(2):'—';
  function metricsHTML(m){
    return '<div class="sf-g-metrics">'+
      [['Sample peak',m.sample_peak_dbfs,'dBFS'],['Integrated loudness (approx)',m.integrated_lufs,'LUFS'],
       ['RMS',m.rms_dbfs,'dBFS'],['Crest factor',m.crest_db,'dB'],
       ['Stereo imbalance',m.stereo_imbalance_db,'dB'],['Clipped output samples',m.clipped_samples,'']].
      map(([name,value,unit])=>'<div><small>'+name+'</small><strong>'+fmt(value)+' '+unit+'</strong></div>').join('')+
      '</div>';
  }
  function ui(project){
    const prev=(project.mastering_analyses||[]).at(-1),reference=project.mastering_reference;
    return '<section class="sf-g-mastering" data-g-mastering><h3>86G · Render & Mastering Lab</h3>'+
      '<p>Offline stereo 48 kHz / 16-bit WAV mixdown using supported Web Audio effects, buses, sends and automation. Native REAPER VST audio is not executed; always compare against the original REAPER mix.</p>'+
      '<p data-g-warning class="sf-g-warning"></p>'+
      '<div class="sf-g-tools"><button type="button" data-g-render>Render current mix · WAV</button>'+
      '<a data-g-download hidden>Download WAV</a><button type="button" data-g-save disabled>Save measured analysis to Agent Brain</button>'+
      '<label>Private reference WAV/MP3<input data-g-upload type="file" accept=".wav,.mp3,audio/wav,audio/mpeg"></label>'+
      '<button type="button" data-g-ref-analyze>Compare reference loudness</button></div>'+
      '<p data-g-progress role="status" aria-live="polite">Save your mix before analysis for reproducible revision history.</p>'+
      '<div data-g-live-analysis></div><div class="sf-g-tools">'+
      '<button type="button" data-g-agent>Ask Agent Brain about this mix</button>'+
      '<button type="button" data-g-gain disabled>Prepare master gain correction for exact approval</button></div>'+
      '<div class="sf-g-ab"><div><b>A · current rendered mix</b><audio controls data-g-player-a></audio></div>'+
      '<div><b>B · '+esc(reference?.name||'private reference (not uploaded)')+'</b><audio controls data-g-player-b'+(reference?' src="api/stem-mastering.php?track_id='+encodeURIComponent(project.track_id)+'&amp;action=reference"':'')+'></audio></div>'+
      '<button type="button" data-g-switch>Switch A/B at same position</button></div>'+
      '<div data-g-reference-results></div>'+
      (prev?'<details><summary>Last saved analysis · project revision '+prev.revision+'</summary>'+
        '<p>Browser-reported evidence, not server-verified. Cached results may be stale after edits.</p>'+metricsHTML(prev.metrics||{})+'</details>':'')+
      '<p class="sf-g-note">LUFS is a 48 kHz K-weighted, gated BS.1770-style estimate. Sample peak is not true peak; no streaming-platform loudness target or mastering certification is implied. Reference recordings remain private.</p></section>';
  }
  function bind(root,studio){
    const area=root.querySelector('[data-g-mastering]');if(!area)return;
    const q=selector=>area.querySelector(selector),status=s=>q('[data-g-progress]').textContent=s;
    const unsupported=fxInfo(studio.project);
    const receives=safe(studio.project.routing?.unsupported_receives);
    const limits=[];
    if(unsupported)limits.push(unsupported+' enabled native/unsupported FX not processed');
    if(receives)limits.push(receives+' REAPER receive(s) unsupported');
    if(limits.length)q('[data-g-warning]').textContent='Fidelity warning: '+limits.join('; ')+'. Browser render is a working approximation.';
    let last=null,blobURL=null,refAnalysis=null,referenceName=studio.project.mastering_reference?.name||'';
    const a=q('[data-g-player-a]'),b=q('[data-g-player-b]');
    a.addEventListener('play',()=>b.pause());b.addEventListener('play',()=>a.pause());
    const makeURL=blob=>{if(blobURL)URL.revokeObjectURL(blobURL);blobURL=URL.createObjectURL(blob);a.src=blobURL;};
    q('[data-g-render]').onclick=async()=>{
      const button=q('[data-g-render]');button.disabled=true;q('[data-g-save]').disabled=true;
      try{
        const revision=studio.project.revision;
        const buffer=await renderOffline(studio,status);
        const measurements=analyze(buffer);
        last={metrics:measurements,details:{
          unsupported_fx:unsupported,unsupported_routing:receives,
          unlinked_stems:0,reference_name:referenceName},revision};
        const file=wav(buffer);makeURL(file);
        const link=q('[data-g-download]');link.hidden=false;link.href=blobURL;
        link.download=filename(studio.project.project_name)+'-mix-r'+revision+'.wav';
        q('[data-g-live-analysis]').innerHTML=metricsHTML(measurements);
        q('[data-g-save]').disabled=false;
        q('[data-g-gain]').disabled=measurements.sample_peak_dbfs<=-1;
        status('48 kHz WAV rendered. Review the measurements, A/B listen and save the evidence to Agent Brain.');
      }catch(e){status('Render unavailable: '+e.message)}
      finally{button.disabled=false}
    };
    q('[data-g-agent]').onclick=async()=>{
      status('Requesting private Admin Agent Brain review based on saved measurements…');
      try{
        const result=await window.STONEFELLOW_ADMIN_CONTEXT.api('agent-chat.php',{
          message:'Review REAPER Stem Studio mastering evidence for song ID '+studio.id+
            '. Discuss clipping, headroom, loudness uncertainty, and recommendations. No changes without my exact approval.'
        });
        status(result.text||'Agent Brain returned no analysis.');
      }catch(e){status('Agent Brain unavailable: '+e.message)}
    };
    q('[data-g-gain]').onclick=()=>{
      if(!last||last.revision!==studio.project.revision)
        return status('Render a current mix before proposing a gain change.');
      const meter=last.metrics.sample_peak_dbfs;
      if(!Number.isFinite(meter)||meter<=-1)return status('No peak-headroom correction proposed.');
      const factor=Math.pow(10,Math.max(-24,Math.min(0,-1.5-meter))/20);
      const section=studio.overlay?.querySelector('.sf-studio-automation');
      if(!section)return status('Automation editor is unavailable.');
      const target=section.querySelector('[data-auto-target]');
      const parameter=section.querySelector('[data-auto-parameter]');
      const points=section.querySelector('[data-auto-points]');
      const proposal=section.querySelector('[data-auto-propose]');
      if(!target||!parameter||!points||!proposal)return status('Approval workbench is unavailable.');
      target.value='master|__master__';parameter.value='volume';
      points.value='0,'+(0.82*factor).toFixed(4)+',square';
      section.scrollIntoView({behavior:'smooth',block:'start'});
      status('A master gain proposal was prepared for exact review. No DSP values have been saved.');
      proposal.click();
    };
    q('[data-g-save]').onclick=async()=>{
      if(!last)return;
      q('[data-g-save]').disabled=true;
      try{
        if(last.revision!==studio.project.revision)throw Error('Project changed. Render again before saving measurements.');
        const result=await window.STONEFELLOW_ADMIN_CONTEXT.api('stem-mastering.php',{
          action:'save_analysis',track_id:studio.id,revision:last.revision,analysis:last
        });
        studio.project=result.project;
        status('Analysis saved to private Admin Agent Brain history. These measurements remain browser-reported.');
      }catch(e){status('Analysis not saved: '+e.message);q('[data-g-save]').disabled=false;}
    };
    q('[data-g-upload]').onchange=async e=>{
      const file=e.target.files?.[0];if(!file)return;
      if(!/\.(mp3|wav)$/i.test(file.name)||file.size>78643200)return status('Choose WAV/MP3 under 75 MiB.');
      status('Saving the reference to private Admin storage…');
      const fd=new FormData();fd.append('track_id',studio.id);
      fd.append('revision',String(studio.project.revision));fd.append('file',file,file.name);
      try{
        const data=await window.STONEFELLOW_ADMIN_CONTEXT.uploadApi('stem-mastering.php',fd);
        studio.project=data.project;referenceName=data.project.mastering_reference?.name||file.name;
        b.src='api/stem-mastering.php?track_id='+encodeURIComponent(studio.id)+'&action=reference&t='+Date.now();
        status('Private reference saved. The public music library has not changed.');
      }catch(err){status('Reference not saved: '+err.message)}
    };
    q('[data-g-ref-analyze]').onclick=async()=>{
      const src=b.currentSrc||b.src;if(!src)return status('Upload a private reference first.');
      const ctx=new (window.AudioContext||window.webkitAudioContext)({sampleRate:RATE});
      try{
        status('Reading and measuring the private reference…');
        const response=await fetch(src,{credentials:'same-origin',cache:'no-store'});
        if(!response.ok)throw Error('Reference fetch failed.');
        const decoded=await ctx.decodeAudioData(await response.arrayBuffer());
        if(decoded.duration>MAX_SECONDS)throw Error('Reference is longer than the 8 minute comparison limit.');
        refAnalysis=analyze(decoded);
        q('[data-g-reference-results]').innerHTML='<h4>Reference: '+esc(referenceName)+'</h4>'+
          metricsHTML(refAnalysis)+(last?'<p>Relative loudness: '+fmt((last.metrics.integrated_lufs??0)-(refAnalysis.integrated_lufs??0))+
          ' LU (current mix minus reference; approximate, not loudness-normalized).</p>':'');
        status('Reference comparison complete. A/B listening does not normalize levels.');
      }catch(e){status('Reference analysis unavailable: '+e.message)}
      finally{await ctx.close().catch(()=>{})}
    };
    q('[data-g-switch]').onclick=()=>{
      const source=!a.paused?a:!b.paused?b:null;
      if(!source)return status('Play A or B first, then switch at the matching position.');
      const dest=source===a?b:a;
      if(!dest.src)return status('Render the mix and upload a reference before switching.');
      const pos=source.currentTime;source.pause();dest.currentTime=Math.min(pos,Math.max(0,(dest.duration||pos+1)-.05));
      dest.play().catch(e=>status('Audio comparison cannot play: '+e.message));
    };
    // Revoke any previous studio object URL when the owning Studio closes.
    studio._masteringDispose=()=>{a.pause();b.pause();if(blobURL)URL.revokeObjectURL(blobURL);};
  }
  window.SFStemMastering={renderOffline,analyze,wav,ui,bind,fxInfo};
})();