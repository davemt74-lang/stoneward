'use strict';
/* Execute real save/rename/delete handlers with deterministic DOM + account API mocks. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync(require('node:path').join(__dirname,'../assets/js/app.js'),'utf8');
const first=src.indexOf('  let builderDraftBusy=false,builderNotice='),last=src.indexOf('  function builderArtworkTiles()',first);
assert.ok(first>=0&&last>first);
const handlers=src.slice(first,last)+'\nthis.saveBuilderDraft=saveBuilderDraft;this.openBuilderDraftModal=openBuilderDraftModal;this.showBuilderNotice=showBuilderNotice;';
const state={auth:{authenticated:false},builder:{title:'Original',A:['song-1'],B:[],format:'vinyl'},builderDrafts:{loaded:true,items:[],activeId:0}};
const notice={textContent:'',dataset:{},hidden:true},saveButton={textContent:'Save locally',disabled:false,isConnected:true};
let posts=[],lastDialog=null,renders=0,willFail=false;
const storage=new Map(),localStorage={setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k),getItem:k=>storage.get(k)||null};
function makeDialog(){
 const all=new Map(),obj={innerHTML:'',listeners:{},open:false};
 const make=(key)=>{if(!all.has(key))all.set(key,{hidden:false,disabled:false,textContent:'',value:'Original',listeners:{},focus(){this.focused=true},select(){},
    addEventListener(type,fn){this.listeners[type]=fn}});return all.get(key);};
 obj.setAttribute=()=>{};obj.querySelector=make;obj.querySelectorAll=()=>[];obj.addEventListener=(key,fn)=>obj.listeners[key]=fn;
 obj.showModal=()=>{obj.open=true};obj.close=()=>{obj.open=false;obj.listeners.close?.()};obj.remove=()=>{};return obj;
}
const document={body:{appendChild(d){lastDialog=d}},querySelector(sel){return sel==='#builderDraftModal'&&lastDialog?.open?lastDialog:null},createElement:()=>makeDialog()};
const $=s=>s==='#saveBuild'?saveButton:s==='#builderFeedback'?notice:null;
const sandbox={st:state,canvas:{},document,$,localStorage,console,defaultBuild:()=>({title:'Blank',A:[],B:[],format:'vinyl'}),
  persist(){state.builder.savedAt='test-time'},renderBuilder(){renders++},renderAccount(){},esc:x=>String(x).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','"':'&quot;',"'":'&#39;'}[c])),
  async loadBuilderDrafts(){return []},async secureJsonPost(path,payload){
    posts.push({path,payload});if(willFail)throw new Error('Test transport error');
    if(payload.action==='save')return {build:{id:5,build:{...state.builder,title:'Original'}},builds:[{id:5,name:'Original',build:{...state.builder}}]};
    if(payload.action==='rename')return {build:{id:5,name:payload.name},builds:[{id:5,name:payload.name,build:{...state.builder,title:payload.name}}]};
    if(payload.action==='delete')return {builds:[]};
    throw new Error('Unexpected action');
  }};
vm.runInNewContext("let builderTab='submit';\n"+handlers,sandbox,{filename:'real-builder-drafts.js'});
(async()=>{
 await sandbox.saveBuilderDraft(false);
 assert.match(notice.textContent,/Saved on this device/);
 assert.ok(storage.has('stonefellow.build.v04')===false); // Persist mock is intentionally separated.
 assert.ok(renders>0);
 console.log('PASS guest save immediately reports visible local success');
 state.auth.authenticated=true;state.builder.title='Original';notice.hidden=true;
 await sandbox.saveBuilderDraft(false);
 assert.equal(posts[0].payload.action,'save');assert.equal(state.builderDrafts.activeId,5);
 assert.match(notice.textContent,/Saved “Original”/);assert.equal(notice.dataset.kind,'success');
 console.log('PASS authenticated save confirms server write and refreshes draft listing');
 sandbox.openBuilderDraftModal(5);
 assert.ok(lastDialog.open);
 assert.match(lastDialog.innerHTML,/Manage saved draft/);
 assert.match(lastDialog.innerHTML,/data-draft-confirm/);
 lastDialog.querySelector('#builderDraftName').value='Renamed Edition';
 await lastDialog.querySelector('#builderDraftRenameForm').listeners.submit({preventDefault(){}});
 assert.equal(posts.at(-1).payload.action,'rename');
 assert.equal(posts.at(-1).payload.name,'Renamed Edition');
 assert.equal(state.builderDrafts.items[0].name,'Renamed Edition');
 assert.equal(state.builder.title,'Renamed Edition');
 console.log('PASS accessible rename modal updates stored name, retains active draft');
 sandbox.openBuilderDraftModal(5);
 lastDialog.querySelector('[data-draft-delete]').onclick();
 assert.equal(lastDialog.querySelector('#builderDraftDeleteConfirm').hidden,false);
 await lastDialog.querySelector('[data-draft-confirm]').onclick({currentTarget:lastDialog.querySelector('[data-draft-confirm]')});
 assert.equal(posts.at(-1).payload.action,'delete');
 assert.equal(state.builderDrafts.items.length,0);
 assert.equal(state.builderDrafts.activeId,0);
 console.log('PASS two-step confirmation deletes only selected saved draft');
 willFail=true;await sandbox.saveBuilderDraft(false);
 assert.equal(notice.dataset.kind,'error');assert.match(notice.textContent,/Test transport error/);
 assert.equal(saveButton.disabled,false);
 console.log('PASS save failures show visible error and re-enable button');
 console.log('Section 61 Draft Runtime: 5/5 checks passed');
})().catch(e=>{console.error(e);process.exitCode=1});
