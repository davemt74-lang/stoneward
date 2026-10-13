(() => {
 'use strict';
 const ctx=window.STONEFELLOW_ADMIN_CONTEXT;if(!ctx)return;
 const {api,canvas,head,say,esc,money}=ctx;
 const $=(s,r=document)=>r.querySelector(s);
 const slots=['base','platter','arm','label','effect','pack'],visuals=['studio','obsidian','copper','chrome','classic','precision','gold','none','aurora','warm'];
 let catalog=[];
 function launch(){
   const section=document.createElement('section');section.className='panel';
   section.innerHTML='<div class="panel-title"><div><h2>Digital Player Modules</h2><p>Manage branded turntable bases, add-on parts, center labels and visual-effect packs.</p></div><button type="button" id="managePlayerModules" class="secondary">Manage modules</button></div>';
   canvas.append(section);$('#managePlayerModules').onclick=render;
 }
 async function render(){
   try{catalog=(await api('turntable-modules.php')).modules||[];}catch(e){say(e.message);return;}
   canvas.innerHTML='<div class="breadcrumbs"><button type="button" id="playerModulesReturn">Products</button> / Player modules</div>'+
     head('DIGITAL PLAYER STORE','Player modules','Built-in parts are protected; create branded additions with allowed designs and artwork.','<button id="newPlayerModule" type="button" class="primary">New module</button>')+
     '<section class="panel"><div class="table-scroll"><table class="data-table"><thead><tr><th>Module</th><th>Slot</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody>'+
     catalog.map(m=>'<tr><td><strong>'+esc(m.name)+'</strong><small style="display:block">'+esc(m.brand)+' · '+esc(m.id)+'</small></td><td>'+esc(m.slot)+'</td><td>'+money(m.price_cents)+'</td><td>'+esc(m.active?'Active':'Archived')+'</td><td>'+(m.id.startsWith('custom-')?'<button type="button" class="secondary" data-edit-player-module="'+esc(m.id)+'">Edit</button>':'Built-in')+'</td></tr>').join('')+
     '</tbody></table></div></section>';
   $('#playerModulesReturn').onclick=()=>window.SFProductsAdmin?.renderProducts?.();
   $('#newPlayerModule').onclick=()=>edit({id:'custom-',name:'',brand:'Stonefellow',slot:'base',price_cents:0,visual:'studio',accent:'#c5ad86',image:'',includes:[],active:true},true);
   canvas.querySelectorAll('[data-edit-player-module]').forEach(b=>b.onclick=()=>edit(catalog.find(x=>x.id===b.dataset.editPlayerModule),false));
 }
 function edit(m,isNew){
   if(!m)return;
   const options=(values,selected)=>values.map(x=>'<option value="'+x+'" '+(x===selected?'selected':'')+'>'+x+'</option>').join('');
   canvas.innerHTML='<div class="breadcrumbs"><button type="button" id="playerModuleBack">Player modules</button> / Edit</div>'+
     head('DIGITAL PLAYER STORE',isNew?'New branded module':m.name,'Only approved appearance presets and safe hosted images are supported.')+
     '<form id="playerModuleForm" class="panel"><div class="form-grid">'+
     '<label class="field">Module ID<input name="id" required pattern="custom-[a-z0-9-]{3,55}" value="'+esc(m.id)+'" '+(isNew?'':'readonly')+'></label>'+
     '<label class="field">Component slot<select name="slot">'+options(slots,m.slot)+'</select></label>'+
     '<label class="field span2">Name<input name="name" required maxlength="85" value="'+esc(m.name)+'"></label>'+
     '<label class="field">Brand<input name="brand" maxlength="60" value="'+esc(m.brand)+'"></label>'+
     '<label class="field">Price, cents<input name="price_cents" type="number" min="0" max="1000000" value="'+Number(m.price_cents)+'"></label>'+
     '<label class="field">Visual style<select name="visual">'+options(visuals,m.visual)+'</select></label>'+
     '<label class="field">Accent<input name="accent" type="color" value="'+esc(m.accent)+'"></label>'+
     '<label class="field span2">Artwork URL (Media Library)<input name="image" maxlength="350" value="'+esc(m.image||'')+'" placeholder="/media/label.webp"></label>'+
     '<label class="field span2">Pack component IDs (comma-separated)<input name="includes" value="'+esc((m.includes||[]).join(', '))+'"></label>'+
     '<label class="field">Availability<select name="active">'+options(['active','archived'],m.active?'active':'archived')+'</select></label></div>'+
     '<p class="player-note">New modules can be published immediately or archived; existing purchased parts are preserved.</p>'+
     '<div id="playerModuleError" class="error-text" role="alert"></div><div class="actions"><button type="submit" class="primary">Save module</button><button type="button" class="secondary" id="cancelPlayerModule">Cancel</button></div></form>';
   $('#playerModuleBack').onclick=render;$('#cancelPlayerModule').onclick=render;
   $('#playerModuleForm').onsubmit=async e=>{
     e.preventDefault();const f=new FormData(e.currentTarget),btn=$('#playerModuleForm button[type="submit"]'),err=$('#playerModuleError');
     const module={id:String(f.get('id')||'').trim(),slot:f.get('slot'),name:f.get('name'),brand:f.get('brand'),price_cents:Number(f.get('price_cents')),visual:f.get('visual'),accent:f.get('accent'),image:f.get('image'),includes:String(f.get('includes')||'').split(',').map(x=>x.trim()).filter(Boolean),active:f.get('active')==='active'};
     btn.disabled=true;err.textContent='';
     try{await api('turntable-modules.php',{action:'save',module});say('Player module saved.');render();}
     catch(ex){err.textContent=ex.message;btn.disabled=false;}
   };
 }
 window.SFTurntableAdmin={launch,render};
})();
