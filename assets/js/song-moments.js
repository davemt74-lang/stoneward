/* Section 91B18 — orb-led song timeline. No background microphone or uploads. */
(() => {
  'use strict';
  const key='stonefellow.song-moments.narration.v1';
  const draftPrefix='stonefellow.song-moments.draft.v1.';
  const clock=ms=>{const s=Math.max(0,Math.floor((Number(ms)||0)/1000));return Math.floor(s/60)+':'+String(s%60).padStart(2,'0')};
  const stamp=ms=>clock(ms)+'.'+String(Math.max(0,Math.round(Number(ms)||0))%1000).padStart(3,'0');
  const range=m=>stamp(m.at_ms)+' – '+stamp(m.end_ms);
  const validMoment=m=>m&&Number.isInteger(Number(m.at_ms))&&Number(m.at_ms)>=0&&['comment','story','photo','note','lyric'].includes(m.kind)&&
    (m.kind!=='lyric'||Number.isInteger(Number(m.end_ms))&&Number(m.end_ms)>Number(m.at_ms));
  const normalize=items=>(Array.isArray(items)?items:[]).filter(validMoment).slice(0,700).sort((a,b)=>Number(a.at_ms)-Number(b.at_ms)||Number(a.id)-Number(b.id));
  const currentLyrics=(items,at)=>normalize(items).filter(m=>m.kind==='lyric'&&Number(m.at_ms)<=at&&at<Number(m.end_ms));
  function crossed(items,from,to){if(!Number.isFinite(to)||to<0||to<from||to-from>3)return null;return items.find(m=>Number(m.at_ms)/1000>from&&Number(m.at_ms)/1000<=to)||null;}
  const createElement=(tag,className,text)=>{const node=document.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=String(text);return node;};
  function create(options={}){
    const audio=options.audio;
    if(!audio||!document.body)return null;
    let track=null,moments=[],mine=[],canComment=false,loaded=false,loadToken=0,position=-0.25,lastShown=null,bubbleTimer=null,narrate=false;
    let alive=true;
    const lyricNodes=new Map();
    const timeline=()=>normalize(moments);
    let orbHost=null;
    try{narrate=window.localStorage?.getItem(key)==='on'}catch(_){}
    const backdrop=createElement('div','sf-song-moment-backdrop');backdrop.hidden=true;
    const drawer=createElement('aside','sf-song-moment-drawer');drawer.hidden=true;drawer.setAttribute('role','dialog');drawer.setAttribute('aria-modal','true');drawer.setAttribute('aria-labelledby','sfSongMomentsTitle');
    drawer.innerHTML='<header class="sf-song-moment-head"><div><small>STONEFELLOW / SONG EXPERIENCE</small><h2 id="sfSongMomentsTitle">Song Timeline</h2><p id="sfSongMomentsTrack">Play a song to explore moments.</p></div><button type="button" id="sfSongMomentsClose" aria-label="Close song timeline">×</button></header><div class="sf-song-moment-scroll"><div class="sf-song-moment-intro">I’ll bring each published photo, story, note and approved fan comment to the orb as the song reaches that moment.</div><label class="sf-song-moment-narrate"><input id="sfSongMomentsNarrate" type="checkbox"> Narrate artist moments with Stonefellow’s voice <small>Off by default. Music may be gently lowered during speech.</small></label><div class="sf-song-moment-overview"><span>TRACK TIMELINE</span><div id="sfSongMomentsRail" class="sf-song-moment-rail"></div></div><div id="sfSongMomentsEntries" class="sf-song-moment-entries" aria-live="polite"></div><form id="sfSongMomentForm" class="sf-song-moment-form"><strong>Add your song moment</strong><p id="sfSongMomentSignIn">Comments become public only after an admin approves them.</p><label>POSITION <input id="sfSongMomentTime" type="text" pattern="[0-9]{1,3}:[0-5][0-9]" inputmode="numeric" placeholder="0:42" required></label><label>YOUR COMMENT<textarea id="sfSongMomentComment" rows="3" maxlength="800" placeholder="What does this moment mean to you?" required></textarea></label><button id="sfSongMomentSubmit" type="submit">Submit for approval</button><div id="sfSongMomentFormStatus" role="status" aria-live="polite"></div></form><section id="sfSongMomentMine" class="sf-song-moment-private" hidden></section></div>';
    document.body.append(backdrop,drawer);
    const close=$('#sfSongMomentsClose'),entries=$('#sfSongMomentsEntries'),rail=$('#sfSongMomentsRail'),timeInput=$('#sfSongMomentTime'),commentInput=$('#sfSongMomentComment'),feedback=$('#sfSongMomentFormStatus'),form=$('#sfSongMomentForm'),narrateInput=$('#sfSongMomentsNarrate');
    function $(s,r=drawer){return r.querySelector(s)}
    narrateInput.checked=narrate;
    narrateInput.addEventListener('change',()=>{narrate=narrateInput.checked;try{localStorage.setItem(key,narrate?'on':'off')}catch(_){}});
    function readDraft(){if(!track)return null;try{return JSON.parse(localStorage.getItem(draftPrefix+track.id)||'null')}catch(_){return null}}
    function writeDraft(draft){if(!track)return;try{localStorage.setItem(draftPrefix+track.id,JSON.stringify(draft))}catch(_){feedback.textContent='Browser storage is unavailable; copy your comment before closing.'}}
    function clearDraft(){if(track)try{localStorage.removeItem(draftPrefix+track.id)}catch(_){}}
    function duration(){const d=Number(audio.duration);return Number.isFinite(d)&&d>0?d:Number(track?.duration)||0;}
    function seek(ms){if(!track||!Number.isFinite(Number(ms)))return;const d=duration(),target=Math.max(0,Math.min(d||86400,Number(ms)/1000));audio.currentTime=target;position=target;}
    function momentContent(moment,container,compact=false){
      const header=createElement('div','sf-song-moment-card-head');header.append(createElement('span','',(moment.kind==='lyric'?range(moment):clock(moment.at_ms))+' / '+String(moment.kind||'moment').toUpperCase()));
      if(moment.kind==='comment')header.append(createElement('span','',moment.display_name||'Listener'));
      container.append(header);
      if(moment.title)container.append(createElement('strong','sf-song-moment-title',moment.title));
      if(moment.kind==='story'&&moment.story)container.append(createElement('strong','sf-song-moment-title',moment.story.title));
      if(moment.photo?.url){const image=createElement('img','sf-song-moment-photo');image.src=moment.photo.url;image.alt=moment.title||moment.photo.title||'Stonefellow song photo';image.loading='lazy';container.append(image);}
      if(moment.body)container.append(createElement('p','',moment.body.slice(0,compact?280:1600)));
      if(moment.kind==='lyric'){
        const label=moment.lyric_state==='approved'?'Approved lyrics':moment.lyric_state==='corrected'?'Corrected draft · unverified':'Automated draft · unverified';
        container.append(createElement('small','sf-song-lyric-state',label+(moment.status==='private'?' · Private':'')));
        const confidence=Number.isFinite(moment.confidence)&&moment.confidence>=0&&moment.confidence<=1?Math.round(moment.confidence*100)+'%':'Unknown';
        const timing=moment.timing_source==='manual'?'Manually timed range':moment.timing_source==='provider'?'Provider-timed range':moment.timing_source==='catalog_chunk_estimate'?'Estimated block · line alignment unverified':'Whole section estimate; line alignment unverified';
        container.append(createElement('small','sf-song-lyric-evidence','Confidence: '+confidence+' · '+timing));
      }
      if(moment.kind==='story'&&moment.story){
        const p=createElement('p','sf-song-moment-story-summary',String(moment.story.summary||'').slice(0,compact?140:400));container.append(p);
        const action=createElement('button','sf-song-moment-story-button','Open story ↗');action.type='button';action.addEventListener('click',()=>{closeDrawer();options.onStory?.(moment.story.slug)});container.append(action);
      }
    }
    const bubble=createElement('article','sf-song-orb-card');bubble.hidden=true;bubble.setAttribute('role','status');bubble.setAttribute('aria-live','polite');
    function attachOrb(){const target=document.querySelector('.sf-agent-floater')||document.querySelector('.agent-stage');if(target&&bubble.parentNode!==target){target.appendChild(bubble);orbHost=target;}}
    function hideBubble(){if(bubbleTimer)clearTimeout(bubbleTimer);bubbleTimer=null;bubble.hidden=true;lastShown=null;}
    function present(moment,explicit=false,following=[]){
      if(!track||!moment)return;
      attachOrb();lastShown=moment.id;
      bubble.replaceChildren();
      const badge=createElement('span','sf-song-orb-glow','✧');bubble.append(badge);
      const content=createElement('div','sf-song-orb-copy');momentContent(moment,content,true);bubble.append(content);
      const actions=createElement('div','sf-song-orb-actions');
      const dismiss=createElement('button','', '×');dismiss.type='button';dismiss.setAttribute('aria-label','Dismiss moment');dismiss.addEventListener('click',hideBubble);
      const all=createElement('button','','Timeline');all.type='button';all.addEventListener('click',open);
      if(following.length){const next=createElement('button','','Next moment ('+following.length+')');next.type='button';next.addEventListener('click',()=>present(following[0],true,following.slice(1)));actions.append(next);}
      actions.append(all,dismiss);bubble.append(actions);
      bubble.hidden=false;
      if(bubbleTimer)clearTimeout(bubbleTimer);
      bubbleTimer=setTimeout(hideBubble,explicit?19000:12500);
      if(!explicit){options.onPresent?.(moment);if(narrate&&moment.kind!=='comment')options.onNarrate?.(moment);}
    }
    function drawSeekMarkers(){
      const seek=options.seekInput;if(!seek||!markerHost)return;
      markerHost.replaceChildren();if(!track||!timeline().length)return;
      const d=duration();if(d<=0)return;
      const rect=seek.getBoundingClientRect(),parent=markerHost.parentElement?.getBoundingClientRect();if(!parent)return;
      markerHost.style.left=(rect.left-parent.left)+'px';markerHost.style.top=(rect.top-parent.top+rect.height/2-7)+'px';markerHost.style.width=rect.width+'px';
      timeline().forEach((m,index)=>{
        const button=createElement('button','sf-song-moment-dot sf-song-moment-dot-'+m.kind);
        button.type='button';button.style.left=Math.max(0,Math.min(100,Number(m.at_ms)/1000/d*100))+'%';
        button.title=(m.kind==='lyric'?'Lyric '+range(m):'Song Moment · '+clock(m.at_ms))+' · '+(m.title||m.story?.title||m.body||m.kind).slice(0,90);
        button.setAttribute('aria-label','Go to song moment at '+clock(m.at_ms));
        button.addEventListener('click',event=>{event.preventDefault();seekMoment(m);});
        button.dataset.index=String(index);markerHost.appendChild(button);
      });
    }
    function seekMoment(m){seek(m.at_ms);syncLyricHighlight();present(m,true);}
    function syncLyricHighlight(){
      const active=new Set(currentLyrics(timeline(),Math.round((Number(audio.currentTime)||0)*1000)).map(m=>String(m.id)));
      for(const [id,node] of lyricNodes){const selected=active.has(id);node.classList.toggle('sf-song-lyric-current',selected);if(selected)node.setAttribute('aria-current','true');else node.removeAttribute('aria-current');}
    }
    const markerHost=createElement('div','sf-song-moment-markers');markerHost.setAttribute('aria-label','Song timeline moment markers');
    const seekContainer=options.seekInput?.parentElement;if(seekContainer)seekContainer.appendChild(markerHost);
    const resize=()=>drawSeekMarkers();window.addEventListener('resize',resize);
    function render(){
      $('#sfSongMomentsTrack').textContent=track?String(track.title||'Song')+' · '+clock(duration()*1000):'Play a song to explore moments.';
      entries.replaceChildren();rail.replaceChildren();lyricNodes.clear();
      if(!track){entries.append(createElement('p','sf-song-moment-empty','Load a Stonefellow song to explore its timeline.'));form.hidden=true;$('#sfSongMomentMine').hidden=true;drawSeekMarkers();return;}
      form.hidden=false;
      if(!timeline().length)entries.append(createElement('p','sf-song-moment-empty',loaded?'No moments or lyric ranges have been added to this song yet.':'Loading moments…'));
      const d=duration();for(const m of timeline()){
        const article=createElement('article','sf-song-moment-entry'+(m.kind==='lyric'?' sf-song-lyric-entry':''));momentContent(m,article);
        const jump=createElement('button','sf-song-moment-jump','▶ Jump to '+clock(m.at_ms));jump.type='button';jump.addEventListener('click',()=>seekMoment(m));article.append(jump);entries.append(article);
        if(m.kind==='lyric'){
          lyricNodes.set(String(m.id),article);
          if(typeof options.onLyricReference==='function'){
            const ask=createElement('button','sf-song-lyric-reference','Ask about '+range(m));ask.type='button';ask.addEventListener('click',()=>options.onLyricReference(Object.freeze({...m})));article.append(ask);
          }
        }
        if(d>0){const dot=createElement('button','sf-song-moment-rail-dot');dot.type='button';dot.style.left=Math.max(0,Math.min(100,Number(m.at_ms)/1000/d*100))+'%';dot.setAttribute('aria-label','Jump to '+clock(m.at_ms));dot.addEventListener('click',()=>seekMoment(m));rail.append(dot);}
      }
      $('#sfSongMomentSignIn').textContent=canComment?'Your public comment is submitted for admin approval. It will not appear to other listeners until approved.':'You can save a private draft in this browser. Sign in later to submit it for administrator approval.';
      $('#sfSongMomentSubmit').textContent=canComment?'Submit for approval':'Save private draft';
      const notes=$('#sfSongMomentMine');notes.replaceChildren();
      const own=mine.filter(x=>x.status!=='published');
      if(own.length){notes.hidden=false;notes.append(createElement('strong','','Your comment review status'));own.forEach(x=>notes.append(createElement('p','',clock(x.at_ms)+' · '+String(x.status).toUpperCase()+' · '+String(x.body||''))));}
      else notes.hidden=true;
      const draft=readDraft();if(draft){timeInput.value=clock(draft.at_ms);commentInput.value=draft.body||'';}
      else{timeInput.value=clock(audio.currentTime*1000);commentInput.value='';}
      drawSeekMarkers();
      syncLyricHighlight();
    }
    async function load(id){const token=++loadToken;loaded=false;moments=[];mine=[];canComment=false;render();
      if(!id)return;
      try{const response=await fetch((options.apiBase||'api')+'/song-moments.php?track_id='+encodeURIComponent(id),{credentials:'same-origin',cache:'no-store'}),data=await response.json();
        if(!alive||token!==loadToken||String(track?.id)!==String(id))return;if(!response.ok||data.ok===false)throw new Error(data.message||'Moments unavailable.');
        moments=normalize(data.moments);mine=Array.isArray(data.mine)?data.mine:[];canComment=!!data.can_comment;loaded=true;render();
      }catch(e){if(!alive||token!==loadToken||String(track?.id)!==String(id))return;loaded=true;feedback.textContent='Song moments could not be loaded: '+e.message;render();}
    }
    function setTrack(next){
      const id=String(next?.id||'');if(String(track?.id||'')===id)return;
      hideBubble();track=next||null;position=-0.25;lastShown=null;load(id);if(drawer.hidden)return;render();
    }
    function open(){if(!track){options.onNeedTrack?.();return;}attachOrb();backdrop.hidden=false;drawer.hidden=false;document.body.classList.add('sf-song-moments-open');render();close.focus({preventScroll:true});}
    function closeDrawer(){backdrop.hidden=true;drawer.hidden=true;document.body.classList.remove('sf-song-moments-open');}
    close.addEventListener('click',closeDrawer);backdrop.addEventListener('click',closeDrawer);
    function keyboard(event){if(event.key==='Escape'&&!drawer.hidden){event.preventDefault();closeDrawer();}}
    window.addEventListener('keydown',keyboard);
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(!track)return;
      const found=timeInput.value.trim().match(/^(\d{1,3}):([0-5]\d)$/);const body=commentInput.value.trim();
      if(!found){feedback.textContent='Use a timestamp such as 1:24.';return;}
      if(body.length<3||body.length>800){feedback.textContent='Write between 3 and 800 characters.';return;}
      const at_ms=(Number(found[1])*60+Number(found[2]))*1000;
      if(duration()&&at_ms>duration()*1000+2000){feedback.textContent='Choose a timestamp within the song.';return;}
      if(!canComment){writeDraft({at_ms,body});feedback.textContent='Saved privately on this device. Sign in to submit for public review.';return;}
      const button=$('#sfSongMomentSubmit');button.disabled=true;feedback.textContent='Submitting for admin review…';
      try{await options.submit?.({action:'comment',track_id:track.id,at_ms,body});clearDraft();feedback.textContent='Submitted! Hidden from public until an admin approves it.';await load(String(track.id));}
      catch(e){feedback.textContent=e.message||'Could not submit. Your comment was not published.';}finally{button.disabled=false;}
    });
    function playbackTick(){syncLyricHighlight();if(!track||!loaded)return;const now=Number(audio.currentTime);
      if(!Number.isFinite(now)||audio.paused){position=now;return;}
      const from=position,spokenMoments=moments.filter(m=>m.kind!=='lyric'),moment=crossed(spokenMoments,from,now);position=now;
      if(moment){const companions=spokenMoments.filter(m=>m!==moment&&Number(m.at_ms)/1000>from&&Number(m.at_ms)/1000<=now);present(moment,false,companions);}
    }
    function onSeek(){position=Number(audio.currentTime)||0;syncLyricHighlight();}
    function onPlay(){if(position<0)position=(Number(audio.currentTime)||0)-.2;}
    audio.addEventListener('timeupdate',playbackTick);audio.addEventListener('seeked',onSeek);audio.addEventListener('play',onPlay);audio.addEventListener('loadedmetadata',drawSeekMarkers);
    function destroy(){alive=false;++loadToken;hideBubble();closeDrawer();drawer.remove();backdrop.remove();bubble.remove();markerHost.remove();window.removeEventListener('resize',resize);window.removeEventListener('keydown',keyboard);audio.removeEventListener('timeupdate',playbackTick);audio.removeEventListener('seeked',onSeek);audio.removeEventListener('play',onPlay);audio.removeEventListener('loadedmetadata',drawSeekMarkers);}
    return Object.freeze({open,close:closeDrawer,setTrack,attachOrb,getContext:()=>({track_id:track?.id||'',at_ms:Math.round((audio.currentTime||0)*1000),moments:normalize(moments.filter(m=>m.kind!=='lyric'&&Math.abs(m.at_ms-(audio.currentTime||0)*1000)<7000)).slice(0,3),lyrics:currentLyrics(timeline(),Math.round((audio.currentTime||0)*1000)).slice(0,3)}),refresh:()=>load(String(track?.id||'')),destroy});
  }
  window.StonefellowSongMoments=Object.freeze({create,clock,stamp,range,normalize,crossed,currentLyrics});
})();
