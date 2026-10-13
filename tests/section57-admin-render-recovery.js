/* Section 57: execute actual Admin navigation against a minimal DOM and test crash recovery. */
'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const path=require('node:path');
const raw=fs.readFileSync(path.join(__dirname,'../admin/assets/admin.js'),'utf8');
const marker=/\n\s*startApp\(\);\s*\}\)\(\);\s*$/;
assert.match(raw,marker,'Admin script must start the application normally');
const src=raw.replace(marker,"\n window.__testOpenView=openView;\n window.__testStartApp=startApp;\n})();");
function classes(){const set=new Set();return{toggle(k,force){if(force===undefined?(!set.has(k)):force)set.add(k);else set.delete(k)},add:k=>set.add(k),remove:k=>set.delete(k),contains:k=>set.has(k)}}
function element(more={}){
  const attrs={};
  return Object.assign({dataset:{},classList:classes(),textContent:'',innerHTML:'',hidden:false,onclick:null,
    addEventListener(){},setAttribute(k,v){attrs[k]=String(v)},removeAttribute(k){delete attrs[k]},getAttribute:k=>attrs[k],
    insertAdjacentHTML(where,text){this.innerHTML=text+this.innerHTML},
    querySelector(){return null},querySelectorAll(){return []}},more);
}
const selectors=new Map();
for(const id of ['adminCanvas','viewTitle','adminApp','folderInput','sidebar','adminNav','adminOrb','agentStatus','agentMessage','dashboardRefresh','sidebarToggle','openAdminAgentChat','retryAdminLoad'])selectors.set('#'+id,element());
const nav=['dashboard','catalog','media','products','settings','agent-chat'].map(view=>element({dataset:{view}}));
const document={querySelector:s=>selectors.get(s)||null,querySelectorAll:s=>s==='#adminNav [data-view]'?nav:[]};
const canvas=selectors.get('#adminCanvas'),visited=[];
const window={
 SFMediaAdmin:{renderMediaLibrary(){canvas.innerHTML='<h1>Media content rendered</h1>';visited.push('media');}},
 SFProductsAdmin:{renderProducts(){canvas.innerHTML='<h1>Product content rendered</h1>';visited.push('products');}},
 SFAdminAgentCanvas:{render(){canvas.innerHTML='<h1>Admin Agent content rendered</h1>';visited.push('agent-chat');return Promise.resolve();}}
};
const sandbox={window,document,console,Date,Intl,Math,Number,String,JSON,Array,Object,Promise,Set,Map,URL,
  setTimeout:fn=>{fn();return 1},clearTimeout(){},location:{href:'http://localhost/admin/',reload(){}},
  crypto:{randomUUID:()=> 'test-uuid'},navigator:{},localStorage:{getItem:()=>null,setItem(){}},sessionStorage:{getItem:()=>null,setItem(){}}};
vm.runInNewContext(src,sandbox,{filename:'admin/assets/admin.js',timeout:3000});
assert.equal(typeof window.__testOpenView,'function');
for(const view of ['media','products','agent-chat']){
  window.__testOpenView(view);
  assert.match(canvas.innerHTML,/content rendered/,'Admin view '+view+' must not be blank');
  for(const item of nav){
    assert.equal(item.classList.contains('active'),item.dataset.view===view,view+' active nav '+item.dataset.view);
    assert.equal(item.getAttribute('aria-current'),item.dataset.view===view?'page':undefined,view+' accessible current route');
  }
}
assert.equal(visited.join(','),'media,products,agent-chat');
assert.equal(selectors.get('#viewTitle').textContent,'Admin Agent Chat');
assert.ok(raw.includes('Admin workspace could not start')&&raw.includes("$('#retryAdminLoad').onclick"));
(async()=>{
  await window.__testStartApp(); // Minimal DOM lacks auxiliary chat widgets: verify startup failure becomes visible.
  assert.match(canvas.innerHTML,/Admin workspace could not start/);
  assert.equal(typeof selectors.get('#retryAdminLoad').onclick,'function');
  console.log('PASS: routes display content (Media, Products, Agent Chat)');
  console.log('PASS: navigation active classes and aria-current are updated on every route');
  console.log('PASS: startup failure shows accessible error and wired retry control');
  console.log('Section 57: 3/3 checks passed');
})().catch(e=>{console.error(e);process.exitCode=1});
