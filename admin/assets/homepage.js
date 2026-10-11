(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
  const {api,esc,say}=ctx,$=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={data:null,mount:null};
  const label=s=>String(s||'').replaceAll('_',' ').replace(/w/g,c=>c.toUpperCase());
  const option=(value,text,current)=>'<option value="'+esc(value)+'" '+(String(value)===String(current)?'selected':'')+'>'+esc(text)+'</option>';
  function configFields(section,refs){
    const c=section.config||{},key=section.section_key;
    if(key==='hero')return '<div class="homepage-config-grid"><label class="field">Primary button<input name="primary_label" value="'+esc(c.primary_label||'Listen now')+'"></label><label class="field">Primary destination<select name="primary_view">'+['music','releases','shows','store','about','newsletter'].map(v=>option(v,label(v),c.primary_view||'music')).join('')+'</select></label><label class="field">Secondary button<input name="secondary_label" value="'+esc(c.secondary_label||'Explore releases')+'"></label><label class="field">Secondary destination<select name="secondary_view">'+['releases','music','shows','store','about','newsletter'].map(v=>option(v,label(v),c.secondary_view||'releases')).join('')+'</select></label></div>';
    if(key==='featured_release')return '<label class="field">Featured release<select name="release_id">'+option('','Auto — newest published release',c.release_id||'')+(refs.releases||[]).map(r=>option(r.id,r.title+(r.date?' · '+r.date:''),c.release_id||'')).join('')+'</select></label>';
    if(key==='featured_track')return '<label class="field">Featured track<select name="track_id">'+option('','Auto — first track from featured release',c.track_id||'')+(refs.tracks||[]).map(t=>option(t.id,t.title+(t.release?' · '+t.release:''),c.track_id||'')).join('')+'</select></label>';
    if(key==='latest_releases')return '<label class="field">Number of releases<select name="limit">'+[2,3,4,5,6].map(v=>option(v,String(v),Number(c.limit||3))).join('')+'</select></label>';
    if(key==='upcoming_show')return '<label class="field">Featured show<select name="show_id">'+option('','Auto — next upcoming show',c.show_id||'')+(refs.shows||[]).map(s=>option(s.id,(s.date?s.date+' · ':'')+(s.venue||s.title),c.show_id||'')).join('')+'</select></label>';
    if(key==='merch')return '<label class="field">Featured product<select name="product_id">'+option('','Auto — featured available merch',c.product_id||'')+(refs.products||[]).map(p=>option(p.id,p.title+(p.available?'':' · unavailable'),c.product_id||'')).join('')+'</select></label>';
    if(key==='artist_story')return '<div class="notice span2"><strong>Single source:</strong> when a published Artist About exists in Storytelling below, its title, summary/body and public hero image automatically replace this section’s fallback heading/body/media. This section copy remains only as a safe fallback.</div><div class="homepage-config-grid"><label class="field">Button label<input name="button_label" value="'+esc(c.button_label||'About Stonefellow')+'"></label><label class="field">Destination<select name="button_view">'+['about','music','archive','shows'].map(v=>option(v,label(v),c.button_view||'about')).join('')+'</select></label></div>';
    if(key==='newsletter')return '<label class="check-field"><input type="checkbox" name="compact" '+(c.compact?'checked':'')+'> Compact newsletter form</label>';
    if(key==='personalized')return '<label class="field">Personalized item limit<select name="limit">'+[2,3,4,5,6].map(v=>option(v,String(v),Number(c.limit||4))).join('')+'</select></label>';
    return '';
  }
  function sectionCard(s,refs){
    return '<form class="homepage-section-card" data-home-section="'+esc(s.section_key)+'"><div class="homepage-section-head"><div><span class="homepage-section-key">'+esc(label(s.section_key))+'</span><small>Position '+Number(s.position||0)+'</small></div><label class="homepage-toggle"><input type="checkbox" name="enabled" '+(s.enabled?'checked':'')+'><span>Enabled</span></label></div><div class="homepage-section-form"><label class="field small-field">Position<input name="position" type="number" min="0" max="999" value="'+Number(s.position||0)+'"></label><label class="field">Eyebrow<input name="eyebrow" maxlength="120" value="'+esc(s.eyebrow||'')+'"></label><label class="field span2">Heading<input name="heading" maxlength="255" value="'+esc(s.heading||'')+'"></label><label class="field span2">Body<textarea name="body_text" rows="3">'+esc(s.body_text||'')+'</textarea></label><div class="span2 homepage-config">'+configFields(s,refs)+'</div><div class="actions span2"><button class="secondary" type="submit">Save section</button><span class="homepage-save-status" aria-live="polite"></span></div></div></form>';
  }
  async function load(){state.data=await api('homepage.php');return state.data}
  async function renderInto(target='artistHomepagePanel'){
    state.mount=typeof target==='string'?document.getElementById(target):target;if(!state.mount)return;
    state.mount.innerHTML='<div class="empty compact">Loading Artist Homepage…</div>';
    try{const d=await load(),m=d.metrics||{},events=m.events||{},sections=d.sections||[],enabled=!!d.site?.artist_homepage_enabled;
      const readiness=m.readiness||{score:0,ready:false,blockers:[],warnings:[]};state.mount.innerHTML='<div class="panel-title"><div><h2>Artist Homepage</h2><p>Single-artist landing experience. The Splash toggle remains separate; when this plugin is off, the existing Agent/Personalized Home remains the fallback.</p></div><div class="actions"><a class="secondary" href="../?view=home" target="_blank" rel="noopener">Preview public home</a><button class="secondary" id="homepageReset" type="button">Reset defaults</button></div></div><div class="homepage-plugin-status '+(enabled?'enabled':'disabled')+'"><strong>'+(enabled?'PLUGIN ON':'PLUGIN OFF')+'</strong><span>'+(enabled?'Public Home uses the Artist Homepage.':'Public Home uses the existing Agent/Personalized Home.')+'</span></div><div class="homepage-readiness '+(readiness.ready?'ready':'blocked')+'"><strong>'+Number(readiness.score||0)+'%</strong><div><span class="badge '+(readiness.ready?'good':'warn')+'">'+(readiness.ready?'CORE READY':'NEEDS ATTENTION')+'</span><small>'+esc((readiness.blockers||[]).concat(readiness.warnings||[]).slice(0,4).join(' · ')||'Homepage public-content checks are green.')+'</small></div></div><div class="stats homepage-stats"><div class="stat"><strong>'+Number(m.views||0).toLocaleString()+'</strong><span>Homepage views · 30d</span></div><div class="stat"><strong>'+Number(events.track_play||0).toLocaleString()+'</strong><span>Track plays</span></div><div class="stat"><strong>'+Number(events.release_open||0).toLocaleString()+'</strong><span>Release opens</span></div><div class="stat"><strong>'+Number(events.newsletter_open||0).toLocaleString()+'</strong><span>Newsletter CTAs</span></div></div><div class="homepage-section-list">'+sections.map(s=>sectionCard(s,d.references||{})).join('')+'</div>';
      $$('[data-home-section]',state.mount).forEach(f=>f.onsubmit=e=>saveSection(e,d.references||{}));
      const reset=$('#homepageReset',state.mount);if(reset)reset.onclick=resetDefaults;
    }catch(e){state.mount.innerHTML='<div class="empty">'+esc(e.message)+'</div>'}
  }
  function readConfig(form,key){
    const v=n=>form.elements[n]?.value??'',checked=n=>!!form.elements[n]?.checked;
    if(key==='hero')return {primary_label:v('primary_label'),primary_view:v('primary_view'),secondary_label:v('secondary_label'),secondary_view:v('secondary_view')};
    if(key==='featured_release')return {release_id:v('release_id')};
    if(key==='featured_track')return {track_id:v('track_id')};
    if(key==='latest_releases'||key==='personalized')return {limit:Number(v('limit')||0)};
    if(key==='upcoming_show')return {show_id:v('show_id')};
    if(key==='merch')return {product_id:v('product_id')};
    if(key==='artist_story')return {button_label:v('button_label'),button_view:v('button_view')};
    if(key==='newsletter')return {compact:checked('compact')};
    return {};
  }
  async function saveSection(e){
    e.preventDefault();const f=e.currentTarget,key=f.dataset.homeSection,status=$('.homepage-save-status',f);if(status)status.textContent='Saving…';
    const section={section_key:key,enabled:f.elements.enabled.checked,position:Number(f.elements.position.value||0),eyebrow:f.elements.eyebrow.value,heading:f.elements.heading.value,body_text:f.elements.body_text.value,config:readConfig(f,key)};
    try{await api('homepage.php',{action:'save_section',section});if(status)status.textContent='Saved';say('Artist Homepage '+label(key)+' section saved.');setTimeout(()=>{if(status)status.textContent=''},1800)}catch(x){if(status)status.textContent=x.message}
  }
  async function resetDefaults(){if(!confirm('Reset every Artist Homepage section to the Stonefellow defaults? Your section copy, order, selections and enabled states will be replaced.'))return;try{await api('homepage.php',{action:'reset_defaults',confirmed:true});say('Artist Homepage reset to the Stonefellow defaults.');renderInto(state.mount)}catch(e){say(e.message)}}
  window.SFArtistHomeAdmin={renderInto};
})();