(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
  const {api,canvas,esc,say}=ctx,$=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={data:null,mount:null,current:0,dialog:null,dirty:false,returnFocus:null};
  const typeLabels={artist_about:'Artist About',essay:'Editorial Essay',release_story:'Release Story',track_story:'Track Story',show_story:'Show Story',milestone:'Timeline Milestone'};
  const label=s=>typeLabels[s]||String(s||'').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
  async function load(){state.data=await api('stories.php');return state.data}
  const option=(value,text,current)=>'<option value="'+esc(value)+'" '+(String(value)===String(current)?'selected':'')+'>'+esc(text)+'</option>';
  function listRow(e){return '<button type="button" class="story-admin-row '+(Number(e.id)===Number(state.current)?'active':'')+'" data-story-open="'+e.id+'"><span><strong>'+esc(e.title)+'</strong><small>'+esc(label(e.entry_type))+(e.entity?.title?' · '+esc(e.entity.title):'')+'</small></span><span><b>'+esc(String(e.status||'draft').toUpperCase())+'</b><small>'+Number(e.readiness?.score||0)+'%</small></span></button>'}
  function entityFields(e,refs){
    const required=e.entry_type==='release_story'?'release':e.entry_type==='track_story'?'track':e.entry_type==='show_story'?'show':'';
    if(e.entry_type==='artist_about')return '<input type="hidden" name="entity_type" value=""><input type="hidden" name="entity_id" value="">';
    const currentType=required||e.entity_type||'',currentId=e.entity_id||'';
    const typeSelect=required?'<input type="hidden" name="entity_type" value="'+required+'"><div class="story-link-label">'+label(required)+' link required</div>':'<label class="field">Linked content type<select name="entity_type">'+option('','None',currentType)+['track','release','show'].map(v=>option(v,label(v),currentType)).join('')+'</select></label>';
    const choices=currentType==='track'?refs.tracks||[]:currentType==='release'?refs.releases||[]:currentType==='show'?refs.shows||[]:[];
    return typeSelect+'<label class="field">Linked '+(required||'content')+'<select name="entity_id">'+option('',required?'Choose public '+required:'None',currentId)+choices.map(x=>option(x.id,(x.title||x.id)+(x.date?' · '+x.date:'')+(x.release?' · '+x.release:''),currentId)).join('')+'</select></label>';
  }
  function editor(e,refs){
    const ready=e.readiness||{score:0,ready:false,blockers:[],warnings:[]},isNew=!Number(e.id||0),status=e.status||'draft';
    return '<div class="story-editor-head"><div><div class="eyebrow">SINGLE-ARTIST EDITORIAL</div><h2>'+esc(isNew?'New story':e.title)+'</h2><p>Editorial storytelling around Stonefellow’s existing catalog, releases and shows. Music metadata stays authoritative in its original workspace.</p></div><div class="actions">'+(!isNew&&status==='published'?'<a class="secondary" href="'+(e.entry_type==='artist_about'?'../about':'../story/'+encodeURIComponent(e.slug))+'" target="_blank" rel="noopener">Preview public story</a>':'')+'<a class="secondary" href="../about" target="_blank" rel="noopener">Preview About</a></div></div><div class="story-readiness '+(ready.ready?'ready':'blocked')+'"><strong>'+Number(ready.score||0)+'%</strong><div><span class="badge '+(ready.ready?'good':'warn')+'">'+(ready.ready?'READY':'BLOCKED')+'</span><small>'+esc((ready.blockers||[]).concat(ready.warnings||[]).slice(0,4).join(' · ')||'Story publishing checks are green.')+'</small></div></div><form id="storyEditorForm" class="story-editor-form"><input type="hidden" name="id" value="'+Number(e.id||0)+'"><label class="field">Story type<select name="entry_type">'+Object.keys(typeLabels).map(v=>option(v,typeLabels[v],e.entry_type||'essay')).join('')+'</select></label><label class="field">Status<select name="status">'+['draft','published','archived'].map(v=>option(v,label(v),status)).join('')+'</select></label><label class="field span2">Title<input name="title" maxlength="255" required value="'+esc(e.title||'')+'"></label><label class="field">Slug<input name="slug" maxlength="160" value="'+esc(e.slug||'')+'" placeholder="auto-from-title"></label><label class="field">Eyebrow<input name="eyebrow" maxlength="120" value="'+esc(e.eyebrow||'')+'"></label><label class="field span2">Summary<textarea name="summary" rows="3">'+esc(e.summary||'')+'</textarea></label><label class="field span2">Story body<textarea name="body_text" rows="14" required>'+esc(e.body_text||'')+'</textarea></label><div id="storyEntityFields" class="story-editor-linked span2">'+entityFields(e,refs)+'</div><label class="field">Timeline / story date<input name="event_date" type="date" value="'+esc(String(e.event_date||'').slice(0,10))+'"></label><label class="field">Era<input name="era" maxlength="160" value="'+esc(e.era||'')+'" placeholder="e.g. Desert sessions"></label><label class="field">Position<input name="position" type="number" min="0" max="999" value="'+Number(e.position||0)+'"></label><label class="check-field"><input name="featured" type="checkbox" '+(e.featured?'checked':'')+'> Feature this editorial item</label><label class="field span2">SEO title override<input name="seo_title" maxlength="255" value="'+esc(e.seo_title||'')+'" placeholder="Optional — story title used by default"></label><label class="field span2">SEO description override<textarea name="seo_description" rows="2" maxlength="500">'+esc(e.seo_description||'')+'</textarea></label><div class="actions span2"><button class="primary" type="submit">'+(isNew?'Create draft':'Save story')+'</button>'+(!isNew&&status!=='published'?'<button class="danger" id="storyDelete" type="button">Delete</button>':'')+'<span id="storySaveStatus" class="status-line"></span></div></form>'+(!isNew?'<div class="story-media-wrap"><div class="panel-title"><div><h3>Story media</h3><p>Reuse Media Library assets. Hero is the primary share/story image; inline and gallery assets remain editorial media.</p></div></div>'+(window.SFMediaAdmin?.entitySection?.('story_entry',String(e.id),{roles:['hero','inline','gallery'],publishRoles:['hero']})||'<div class="empty compact">Media module loading…</div>')+'</div>':'');
  }
  function blank(){return {id:0,slug:'',entry_type:'essay',title:'',eyebrow:'',summary:'',body_text:'',status:'draft',entity_type:'',entity_id:'',event_date:null,era:'',featured:false,position:0,seo_title:'',seo_description:'',readiness:{score:0,ready:false,blockers:['Save the draft before publishing.'],warnings:[]}}}
  async function renderInto(target='storytellingPanel'){
    state.mount=typeof target==='string'?document.getElementById(target):target;
    if(!state.mount)return;
    state.mount.innerHTML='<div class="empty compact">Loading Storytelling…</div>';
    try{
      const d=await load(),rows=d.stories||[],m=d.metrics||{};
      state.mount.innerHTML='<div class="panel-title"><div><h2>Artist Story, Editorial &amp; Timeline</h2>'+
        '<p>Manage your artist biography, essays, release and track stories. Select a story to edit it in a focused window, or use New Story at the top of Settings.</p></div>'+
        '<a class="secondary" href="../about" target="_blank" rel="noopener">Preview About</a></div>'+
        '<div class="stats story-stats"><div class="stat"><strong>'+Number(m.published||0)+'</strong><span>Published</span></div>'+
        '<div class="stat"><strong>'+Number(m.drafts||0)+'</strong><span>Drafts</span></div>'+
        '<div class="stat"><strong>'+Number(m.events?.view||0)+'</strong><span>Story views · 30d</span></div>'+
        '<div class="stat"><strong>'+Number(m.events?.share||0)+'</strong><span>Shares · 30d</span></div></div>'+
        '<div class="story-admin-list story-admin-list-full" aria-label="Saved stories">'+
        (rows.length?rows.map(listRow).join(''):'<div class="empty compact">No stories yet. Use New Story above to create your first draft.</div>')+'</div>';
      $$('[data-story-open]',state.mount).forEach(b=>b.onclick=()=>{
        const found=rows.find(x=>Number(x.id)===Number(b.dataset.storyOpen));
        if(found){state.current=Number(found.id);openEditor(found,d.references||{});}
      });
    }catch(e){state.mount.innerHTML='<div class="empty" role="alert">'+esc(e.message)+'</div>';}
  }
  function closeEditor(force=false){
    if(!state.dialog)return true;
    if(!force&&state.dirty&&!confirm('Discard unsaved changes to this story?'))return false;
    state.dialog.remove();
    state.dialog=null;
    state.dirty=false;
    document.body.classList.remove('story-modal-open');
    if(state.returnFocus?.isConnected)state.returnFocus.focus();
    state.returnFocus=null;
    return true;
  }
  function openEditor(story,refs){
    if(!state.dialog){
      state.returnFocus=document.activeElement;
      const backdrop=document.createElement('div');
      backdrop.className='story-modal-backdrop';
      backdrop.innerHTML='<div class="story-modal" role="dialog" aria-modal="true" aria-labelledby="storyModalLabel">'+
        '<header class="story-modal-top"><div><div class="eyebrow">STONEFELLOW EDITORIAL</div>'+
        '<strong id="storyModalLabel">Story Editor</strong></div>'+
        '<button type="button" class="secondary" data-story-close aria-label="Close story editor">Close ×</button></header>'+
        '<div class="story-modal-content" id="storyModalContent"></div></div>';
      backdrop.addEventListener('click',ev=>{if(ev.target===backdrop)closeEditor();});
      backdrop.querySelector('[data-story-close]').onclick=()=>closeEditor();
      backdrop.addEventListener('keydown',ev=>{
        if(ev.key==='Escape'){ev.preventDefault();closeEditor();return;}
        if(ev.key!=='Tab')return;
        const items=Array.from(backdrop.querySelectorAll('button:not([disabled]),[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled])')).filter(el=>el.getClientRects().length);
        if(!items.length)return;
        if(ev.shiftKey&&document.activeElement===items[0]){ev.preventDefault();items[items.length-1].focus();}
        else if(!ev.shiftKey&&document.activeElement===items[items.length-1]){ev.preventDefault();items[0].focus();}
      });
      document.body.appendChild(backdrop);
      document.body.classList.add('story-modal-open');
      state.dialog=backdrop;
    }
    state.dirty=false;
    state.dialog.querySelector('#storyModalLabel').textContent=Number(story.id||0)?'Edit Story':'New Story';
    state.dialog.querySelector('#storyModalContent').innerHTML=editor(story,refs);
    state.dialog.querySelector('.story-modal').scrollTop=0;
    bindEditor(story,refs);
    state.dialog.querySelector('input[name="title"]')?.focus();
  }
  async function openNew(){
    if(!state.data)await load();
    state.current=0;
    openEditor(blank(),state.data?.references||{});
  }
  function bindEditor(e,refs){
    const f=$('#storyEditorForm',state.dialog);if(!f)return;const type=f.elements.entry_type;type.onchange=()=>{const temp={...e,entry_type:type.value,entity_type:'',entity_id:''};$('#storyEntityFields',f).innerHTML=entityFields(temp,refs);bindEntityType(f,refs)};bindEntityType(f,refs);f.onsubmit=x=>saveStory(x,e);
    const del=$('#storyDelete',state.dialog);if(del)del.onclick=()=>deleteStory(Number(e.id));
    f.addEventListener('input',()=>{state.dirty=true;});
    f.addEventListener('change',()=>{state.dirty=true;});
    if(e.id)window.SFMediaAdmin?.bindEntityMedia?.('story_entry',String(e.id),{roles:['hero','inline','gallery'],publishRoles:['hero']},state.dialog);
  }
  function bindEntityType(f,refs){const t=f.elements.entity_type;if(t&&t.tagName==='SELECT')t.onchange=()=>{const host=$('#storyEntityFields',f),e={entry_type:f.elements.entry_type.value,entity_type:t.value,entity_id:''};host.innerHTML=entityFields(e,refs);bindEntityType(f,refs)}}
  async function saveStory(ev,existing){
    ev.preventDefault();
    const f=ev.currentTarget,d=new FormData(f),status=String(d.get('status')||'draft');
    const statusChanged=String(existing.status||'draft')!==status;
    const consequential=statusChanged&&['published','archived'].includes(status);
    if(consequential&&!confirm('Change this story status to '+status+'?'))return;
    const story={
      id:Number(d.get('id')||0),entry_type:d.get('entry_type'),status,
      title:d.get('title'),slug:d.get('slug'),eyebrow:d.get('eyebrow'),
      summary:d.get('summary'),body_text:d.get('body_text'),
      entity_type:d.get('entity_type')||'',entity_id:d.get('entity_id')||'',
      event_date:d.get('event_date')||'',era:d.get('era'),
      featured:d.get('featured')==='on',position:Number(d.get('position')||0),
      seo_title:d.get('seo_title'),seo_description:d.get('seo_description')
    };
    const statusEl=$('#storySaveStatus',f),submit=f.querySelector('button[type="submit"]');
    statusEl.textContent='Saving…';submit.disabled=true;
    try{
      const response=await api('stories.php',{action:'save',story,confirmed:consequential});
      state.current=Number(response.story.id);
      state.dirty=false;
      say('Story saved as '+response.story.status+'.');
      // Keep the modal open so the newly saved story can receive hero/gallery
      // assets without navigating away or losing its editing context.
      await renderInto(state.mount);
      openEditor(response.story,state.data?.references||{});
    }catch(err){
      statusEl.textContent=err.message||'Story could not be saved.';
      submit.disabled=false;
    }
  }
  async function deleteStory(id){
    if(!confirm('Delete this draft/archive story permanently?'))return;
    try{
      await api('stories.php',{action:'delete',id,confirmed:true});
      state.current=0;
      closeEditor(true);
      say('Story deleted.');
      await renderInto(state.mount);
    }catch(err){say(err.message);}
  }
  window.SFStoriesAdmin={renderInto,openNew,close:closeEditor};

})();