(() => {
  'use strict';
  let selectedStyleId=0;
  function mount({canvas,styles,references,api,onUseReference,isBusy=()=>false}){
    const library=canvas.querySelector('.imagegen-style-library');if(!library)return;
    const previous=library.querySelector('[data-moodboard-root]');if(previous)previous.remove();
    const host=document.createElement('section');host.className='imagegen-board-workspace';
    host.dataset.moodboardRoot='';host.setAttribute('aria-label','Private style mood boards');
    host.innerHTML='<h3>Private Style Mood Boards</h3><p>Up to eight references per visual identity. Adding, ordering or removing a reference does not generate or publish an image.</p>'+ 
      '<label class="field">Visual style<select data-board-style><option value="0">Choose a visual style</option></select></label>'+ 
      '<div class="imagegen-board-gallery" data-board-items></div>'+ 
      '<form class="imagegen-board-add" data-board-add><label class="field">Approved Media Library image<select name="asset_id" required><option value="">Choose an image</option></select></label>'+ 
      '<label class="field">Private caption<input name="caption" maxlength="160" placeholder="Lighting, framing or texture"></label><button type="submit" class="secondary">Add reference</button></form>'+ 
      '<p class="hint">Use as edit reference selects one source image and resets its permission checkbox. Separate approval is still required before paid generation. No image is uploaded to an AI provider by mood-board actions.</p>'+ 
      '<p role="status" aria-live="polite" data-board-status></p>';
    library.appendChild(host);
    const pick=host.querySelector('[data-board-style]'),gallery=host.querySelector('[data-board-items]');
    const form=host.querySelector('form'),status=host.querySelector('[data-board-status]');let pending=false;
    const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el};
    for(const style of styles)pick.add(new Option(style.name,String(style.id)));
    for(const asset of references)form.elements.asset_id.add(new Option(asset.title||asset.original_name||'Image #'+asset.id,String(asset.id)));
    const current=()=>styles.find(s=>Number(s.id)===selectedStyleId);
    const message=text=>{if(host.isConnected)status.textContent=text;};
    function controls(){
      pick.disabled=pending;
      form.elements.asset_id.disabled=pending||!current();form.elements.caption.disabled=pending||!current();
      form.querySelector('button').disabled=pending||!current()||(current().moodboard||[]).length>=8;
      host.setAttribute('aria-busy',String(pending));
      gallery.querySelectorAll('button').forEach(b=>{b.disabled=pending||b.dataset.boundary==='true';});
    }
    async function mutate(action,extra){
      if(pending||isBusy())return;
      const style=current();if(!style){message('Choose a visual style first.');return;}
      const styleId=Number(style.id);pending=true;controls();message('Saving private mood board…');
      try{
        const result=await api('image-generation.php',{action,style_id:styleId,...extra});
        if(!Array.isArray(result.items))throw new Error('Missing saved board receipt. Refresh Image Studio before retrying.');
        style.moodboard=result.items;pending=false;
        if(host.isConnected){draw();if(action==='board_add')form.reset();message('Private mood board saved. Original media and the image prompt are unchanged.');}
      }catch(error){message((error.message||'Save failed.')+' No automatic retry was made. Refresh Image Studio before retrying an uncertain save.');}
      finally{pending=false;if(host.isConnected)controls();}
    }
    function draw(){
      if(!current())selectedStyleId=0;pick.value=String(selectedStyleId);gallery.replaceChildren();
      const items=current()?.moodboard||[];
      if(!items.length)gallery.appendChild(node('p',current()?'No references on this style yet.':'Choose a style to edit its private board.','hint'));
      items.forEach((item,i)=>{
        const card=node('figure',undefined,'imagegen-board-item');
        if(item.uuid&&item.available!==false){const img=node('img');img.src='../api/media.php?id='+encodeURIComponent(item.uuid);img.alt=item.caption||item.title||'Private visual reference';img.loading='lazy';card.appendChild(img);}
        const caption=node('figcaption');caption.appendChild(node('strong',item.title||'Reference'));
        caption.appendChild(node('small',(i+1)+' / '+items.length+' · '+(item.caption||'No caption')));card.appendChild(caption);
        const actions=node('div',undefined,'imagegen-board-actions');
        const button=(text,kind,handler,blocked=false)=>{const b=node('button',text,'secondary');b.type='button';b.dataset.boardAction=kind;b.dataset.boundary=String(blocked);b.setAttribute('aria-label',text+' — '+(item.title||'reference'));b.onclick=handler;actions.appendChild(b);};
        button('Up','up',()=>mutate('board_move',{item_id:Number(item.id),direction:'up'}),i===0);
        button('Down','down',()=>mutate('board_move',{item_id:Number(item.id),direction:'down'}),i===items.length-1);
        button('Use as edit reference','use',()=>{
          if(pending||isBusy())return;
          if(onUseReference(Number(item.asset_id))!==false)message('Reference selected. Confirm its image-use permission separately; your prompt was preserved.');
          else message('Reference is no longer available. Refresh Image Studio.');
        },!item.uuid||item.available===false||!references.some(a=>Number(a.id)===Number(item.asset_id)));
        button('Remove','remove',()=>{
          if(pending||isBusy()||!window.confirm('Remove this private board reference? The original Media Library image will stay intact.'))return;
          void mutate('board_remove',{item_id:Number(item.id),confirmed:true});
        });
        card.appendChild(actions);gallery.appendChild(card);
      });controls();
    }
    pick.onchange=()=>{selectedStyleId=Number(pick.value)||0;draw();};
    form.onsubmit=e=>{e.preventDefault();void mutate('board_add',{asset_id:Number(form.elements.asset_id.value)||0,caption:form.elements.caption.value});};
    draw();
  }
  window.SFImageMoodBoards={mount};
})();
