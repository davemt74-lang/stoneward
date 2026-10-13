(() => {
  'use strict';
  const types=['release','ticket','merch','text'];
  let model=null;
  const esc=s=>model.esc(String(s??''));
  const label=s=>String(s||'').replaceAll('_',' ').replace(/\b\w/g,m=>m.toUpperCase());
  const option=(id,name,current)=>'<option value="'+esc(id)+'" '+(String(id)===String(current)?'selected':'')+'>'+esc(name)+'</option>';
  function choices(kind,current=''){
    const refs=model?.data?.references||{};
    const list=kind==='release'?refs.releases:kind==='ticket'?refs.tickets:kind==='merch'?refs.products:[];
    return option('','Choose published '+kind+'…',current)+(list||[]).map(o=>option(o.id,(o.title||'Untitled')+(o.show_title?' · '+o.show_title:''),current)).join('');
  }
  function blockHtml(item){
    const type=types.includes(item.type)?item.type:'text',id=String(item.id||'');
    return '<div class="homepage-builder-block" draggable="true" data-home-block-id="'+esc(id)+'">'+
      '<header><strong>'+esc(label(type))+' block</strong><div class="actions">'+
      '<button type="button" class="secondary" data-block-move="-1" aria-label="Move block up">↑</button>'+
      '<button type="button" class="secondary" data-block-move="1" aria-label="Move block down">↓</button>'+
      '<button type="button" class="danger" data-block-remove>Remove</button></div></header>'+
      '<div class="homepage-config-grid"><label class="field">Block type<select data-block-field="type">'+types.map(x=>option(x,label(x),type)).join('')+'</select></label>'+
      '<label class="field" data-block-reference '+(type==='text'?'hidden':'')+'>Published item<select data-block-field="reference_id">'+choices(type,item.reference_id||'')+'</select></label>'+
      '<label class="field">Custom heading<input data-block-field="heading" maxlength="140" placeholder="Use linked item title" value="'+esc(item.heading||'')+'"></label>'+
      '<label class="field">Button label<input data-block-field="button_label" maxlength="70" placeholder="Automatic" value="'+esc(item.button_label||'')+'"></label>'+
      '<label class="field">Layout<select data-block-field="layout">'+['card','wide','compact'].map(x=>option(x,label(x),item.layout||'card')).join('')+'</select></label>'+
      '<label class="check-field"><input type="checkbox" data-block-field="enabled" '+(item.enabled?'checked':'')+'> Visible</label>'+
      '<label class="field span2">Supporting copy<textarea data-block-field="body_text" rows="2" maxlength="500">'+esc(item.body_text||'')+'</textarea></label></div></div>';
  }
  function getConfig(form){
    const list=form?.querySelectorAll('[data-home-block-id]')||[];
    return {blocks:Array.from(list).map(el=>{
      const get=k=>el.querySelector('[data-block-field="'+k+'"]'),value=k=>get(k)?.value||'';
      return {id:el.dataset.homeBlockId,type:value('type'),reference_id:value('reference_id'),
        heading:value('heading'),body_text:value('body_text'),button_label:value('button_label'),
        layout:value('layout'),enabled:!!get('enabled')?.checked};
    })};
  }
  function currentForms(){return Array.from((model?.formsRoot||model?.mount)?.querySelectorAll('.homepage-section-list [data-home-section]')||[])}
  function preview(){
    const host=(model?.previewRoot||model?.mount)?.querySelector('#homepageBuilderPreview');
    if(!host)return;
    const forms=currentForms(),hero=forms.find(f=>f.dataset.homeSection==='hero'),site=model.data?.site||{};
    const ordered=forms.filter(f=>f.elements.enabled?.checked)
      .sort((a,b)=>Number(a.elements.position.value)-Number(b.elements.position.value));
    const image=site.media_hero?'<img src="'+esc(site.media_hero)+'" alt="Published hero image">':
      '<div class="homepage-preview-noimage">No published hero image</div>';
    host.innerHTML='<div class="homepage-preview-hero">'+image+
      '<div class="homepage-preview-hero-copy"><small>LOCAL UNSAVED PREVIEW · STONEFELLOW</small>'+
      '<h3>'+esc(hero?.elements.heading.value||'Stonefellow')+'</h3><p>'+esc(hero?.elements.body_text.value||'')+'</p></div></div>'+
      ordered.filter(f=>f.dataset.homeSection!=='hero').map(f=>{
        if(f.dataset.homeSection==='custom_blocks'){
          const refs=model.data.references||{},blocks=getConfig(f).blocks.filter(x=>x.enabled);
          return '<div class="homepage-preview-block-group"><small>BLOCKS</small><strong>'+
            esc(f.elements.heading.value||'Featured')+'</strong><div class="homepage-preview-tiles">'+
            blocks.map(b=>{
              const sources=b.type==='release'?refs.releases:b.type==='ticket'?refs.tickets:b.type==='merch'?refs.products:[];
              const linked=(sources||[]).find(x=>String(x.id)===b.reference_id);
              return '<div class="homepage-preview-tile"><small>'+esc(label(b.type)+' · '+b.layout)+'</small>'+
                '<strong>'+esc(b.heading||(b.type==='text'?'Editorial':linked?.title||'Choose an item'))+'</strong>'+
                '<p>'+esc(b.body_text)+'</p></div>';
            }).join('')+'</div></div>';
        }
        return '<div class="homepage-preview-row"><small>'+esc(label(f.dataset.homeSection))+'</small>'+
          '<strong>'+esc(f.elements.heading.value||'Untitled')+'</strong></div>';
      }).join('');
  }
  let previewQueued=false;
  function requestPreview(){
    if(previewQueued)return;
    previewQueued=true;
    requestAnimationFrame(()=>{previewQueued=false;preview();});
  }
  function initBlocks(){
    const form=currentForms().find(f=>f.dataset.homeSection==='custom_blocks');
    if(!form)return;
    const host=form.querySelector('.homepage-config');
    if(!host)return;
    const blocks=model.data.sections.find(s=>s.section_key==='custom_blocks')?.config?.blocks||[];
    host.innerHTML='<div class="homepage-blocks-panel"><p class="homepage-block-note">Build custom release, ticket/VIP, merch or editorial cards. Products, availability and pricing come from published records.</p>'+
      '<div id="homepageBlockList" class="homepage-builder-block-list">'+blocks.map(blockHtml).join('')+'</div>'+
      '<div class="homepage-builder-add"><label class="field">Block type<select id="homepageBlockNewType">'+
      types.map(x=>option(x,label(x),'release')).join('')+'</select></label>'+
      '<button type="button" class="secondary" id="homepageBlockAdd">+ Add Block</button></div>'+
      '<p class="homepage-block-note">Enable this section and Save section to publish blocks. Drag or use arrows to rearrange.</p></div>';
    const list=host.querySelector('#homepageBlockList');
    function connect(){
      Array.from(list.querySelectorAll('[data-home-block-id]')).forEach(row=>{
        const type=row.querySelector('[data-block-field="type"]');
        type.onchange=()=>{
          const ref=row.querySelector('[data-block-field="reference_id"]'),kind=type.value;
          row.querySelector('[data-block-reference]').hidden=kind==='text';
          ref.innerHTML=choices(kind,'');
          if(kind!=='text')row.querySelector('[data-block-field="enabled"]').checked=false;
          row.querySelector('header strong').textContent=label(kind)+' block';requestPreview();
        };
        row.querySelector('[data-block-remove]').onclick=()=>{
          if(!confirm('Remove this block? Save this section to apply the change.'))return;
          row.remove();requestPreview();
        };
        row.querySelectorAll('[data-block-move]').forEach(b=>b.onclick=()=>{
          const rows=Array.from(list.children),n=rows.indexOf(row),next=n+Number(b.dataset.blockMove);
          if(next<0||next>=rows.length)return;
          if(next<n)list.insertBefore(row,rows[next]);else list.insertBefore(rows[next],row);
          requestPreview();
        });
        row.ondragstart=e=>e.dataTransfer.setData('text/plain',row.dataset.homeBlockId);
        row.ondragover=e=>e.preventDefault();
        row.ondrop=e=>{
          e.preventDefault();
          const from=Array.from(list.children).find(x=>x.dataset.homeBlockId===e.dataTransfer.getData('text/plain'));
          if(from&&from!==row){list.insertBefore(from,row);requestPreview();}
        };
      });
    }
    host.querySelector('#homepageBlockAdd').onclick=()=>{
      if(list.children.length>=24){model.say('Maximum 24 homepage blocks.');return;}
      const type=host.querySelector('#homepageBlockNewType').value;
      // IDs are display identities only. The backend verifies all input data.
      const id='block_'+Math.random().toString(36).slice(2,12)+Date.now().toString(36);
      list.insertAdjacentHTML('beforeend',blockHtml({id,type,enabled:type==='text'}));
      connect();list.lastElementChild.querySelector('[data-block-field="heading"]')?.focus();
      requestPreview();
    };
    connect();
    form.addEventListener('input',requestPreview);
    form.addEventListener('change',requestPreview);
  }
  let moving=false;
  async function moveSection(form,delta){
    if(moving)return;
    const list=model.mount.querySelector('.homepage-section-list');
    const current=Array.from(list.children),i=current.indexOf(form),next=i+delta;
    if(i<0||next<0||next>=current.length)return;
    const desired=current.slice();[desired[i],desired[next]]=[desired[next],desired[i]];
    moving=true;
    try{
      const answer=await model.api('homepage.php',{action:'reorder_sections',keys:desired.map(x=>x.dataset.homeSection)});
      if(delta<0)list.insertBefore(form,current[next]);else list.insertBefore(current[next],form);
      const pos=new Map((answer.sections||[]).map(x=>[x.section_key,Number(x.position||0)]));
      currentForms().forEach(f=>{if(pos.has(f.dataset.homeSection))f.elements.position.value=pos.get(f.dataset.homeSection)});
      requestPreview();model.say('Section order saved.');
    }catch(e){model.say(e.message||'Could not move section.');}
    finally{moving=false;}
  }
  function mount(options){
    model=options;
    const list=model.mount?.querySelector('.homepage-section-list');
    if(!list)return;
    const parent=document.createElement('div');parent.className='homepage-builder-layout';
    list.parentNode.insertBefore(parent,list);
    const editor=document.createElement('div');editor.className='homepage-builder-editors';
    parent.append(editor);editor.append(list);
    const aside=document.createElement('aside');aside.className='homepage-builder-visual';
    aside.setAttribute('aria-label','Live draft homepage preview');
    aside.innerHTML='<div class="homepage-preview-top"><strong>Visual preview</strong><small>Unsaved draft · public page is unchanged until saved</small></div>'+
      '<div id="homepageBuilderPreview"></div>';
    parent.append(aside);
    currentForms().forEach(f=>{
      const head=f.querySelector('.homepage-section-head');
      if(head){
        const buttons=document.createElement('div');buttons.className='homepage-section-arrows';
        buttons.innerHTML='<button type="button" class="secondary" data-section-move="-1" aria-label="Move section up">↑</button>'+
          '<button type="button" class="secondary" data-section-move="1" aria-label="Move section down">↓</button>';
        head.insertBefore(buttons,head.lastElementChild);
        buttons.querySelectorAll('button').forEach(b=>b.onclick=()=>moveSection(f,Number(b.dataset.sectionMove)));
      }
      // Fold longer legacy forms while preserving fields and existing save listeners.
      const formBody=f.querySelector('.homepage-section-form');
      if(formBody){
        const details=document.createElement('details');details.className='homepage-section-details';
        if(['hero','custom_blocks'].includes(f.dataset.homeSection))details.open=true;
        const summary=document.createElement('summary');summary.textContent='Edit section content';
        formBody.parentNode.insertBefore(details,formBody);details.append(summary,formBody);
      }
      f.addEventListener('input',requestPreview);
      f.addEventListener('change',requestPreview);
    });
    initBlocks();
    preview();
  }
  function relocate(roots){
    if(!model)return;
    model.formsRoot=roots?.formsRoot||model.mount;
    model.previewRoot=roots?.previewRoot||model.mount;
    requestPreview();
  }
  window.SFHomepageBuilder={mount,getConfig,relocate,preview:requestPreview};
})();