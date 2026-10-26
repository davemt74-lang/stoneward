'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const page=read('stonefellow-v120.php');
const siteCss=read('assets/css/site.css');
const adminPage=read('admin/index.php');
const adminCss=read('admin/assets/agent-canvas.css');
const adminJs=read('admin/assets/admin.js');
const agentJs=read('admin/assets/agent-canvas.js');

assert.equal((page.match(/id="menuAdmin"/g)||[]).length,1);
assert.match(page,/<div class="menu-session-actions"[^>]*>[\s\S]*?id="menuAdmin"[\s\S]*?id="menuLogout"/);
assert.match(siteCss,/\.menu-sheet nav\{[^}]*overflow-y:auto/);
assert.match(siteCss,/\.menu-session-actions\{[^}]*flex:none/);
assert.match(siteCss,/\.menu-sheet\{[^}]*overflow:hidden/);
assert.match(adminPage,/id="adminChatPopup"[^>]*hidden/);
assert.match(adminPage,/id="adminChatPopupMessages"[^>]*role="log"/);
assert.match(adminPage,/id="adminChatPopupToggle"[^>]*aria-expanded="false"/);
assert.equal((adminPage.match(/id="adminChatForm"/g)||[]).length,1);
assert.match(adminCss,/\.admin-chat-popup\[hidden\]\{display:none!important/);
assert.match(adminJs,/\$\('#adminChatPopupFull'\)\.onclick=\(\)=>openView\('agent-chat'\)/);
assert.match(adminJs,/send\(message,\{preserveView:true\}\)/);

const elements=new Map();
const element=(id,extra={})=>{
 const attributes={};
 const el={id,hidden:false,innerHTML:'',textContent:'',scrollTop:0,scrollHeight:300,
  classList:{toggle:(name,on)=>{el.classes[name]=on}},classes:{},
  setAttribute:(name,value)=>{attributes[name]=value},getAttribute:name=>attributes[name],...extra};
 elements.set(id,el);return el;
};
element('adminChatPopup',{hidden:true});
element('adminChatPopupMessages');
element('adminChatPopupState');
element('adminApp');
element('adminChatPopupToggle');
element('openAdminAgentChat');
const stored=new Map();
const calls=[],routes=[];
let requestedAction=null;
const context={app:{view:'dashboard'},canvas:{querySelector:()=>null},say(){},openView:view=>routes.push(view),openCatalogTrack:()=>false,
 esc:value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
 async api(route,body){calls.push({route,body});if(body){return {conversation_id:'conversation-1',text:'Reply',action:requestedAction}}
  return {messages:[{role:'user',content:'Hello'},{role:'assistant',content:'Private <script> text'}]};}};
const scope={window:{STONEFELLOW_ADMIN_CONTEXT:context},document:{querySelector:s=>elements.get(s.slice(1))||null,
 getElementById:id=>elements.get(id)||null},sessionStorage:{getItem:key=>stored.get(key)||null,
 setItem:(key,value)=>stored.set(key,value),removeItem:key=>stored.delete(key)},console,
 requestAnimationFrame:fn=>fn()};
vm.runInNewContext(agentJs,scope,{filename:'admin/assets/agent-canvas.js'});
const agent=scope.window.SFAdminAgentCanvas;
const flush=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 agent.togglePopup(true);await flush();
 assert.equal(elements.get('adminChatPopup').hidden,false);
 assert.equal(elements.get('adminChatPopupToggle').getAttribute('aria-expanded'),'true');
 assert.match(elements.get('adminChatPopupMessages').innerHTML,/Private &lt;script&gt; text/);
 assert.doesNotMatch(elements.get('adminChatPopupMessages').innerHTML,/<script>/);
 await agent.send('What can I do here?',{preserveView:true});
 assert.equal(routes.length,0,'ordinary popup reply must not replace the current page');
 assert.equal(calls.find(c=>c.body)?.body.client_state.view,'dashboard');
 assert.equal(stored.get('stonefellow.admin.agent.thread'),'conversation-1');
 requestedAction={auto_navigate:true,type:'navigate_admin_page',view:'catalog',tool_contract:{tool_id:'admin_module_navigate'}};
 await agent.send('Open catalog',{preserveView:true});
 assert.deepEqual(routes,['catalog'],'authorized navigation still changes the Admin module');
 agent.newThread();await flush();
 assert.equal(stored.has('stonefellow.admin.agent.thread'),false);
 agent.togglePopup(false);
 assert.equal(elements.get('adminChatPopup').hidden,true);
 assert.equal(elements.get('adminChatPopupToggle').getAttribute('aria-expanded'),'false');
 requestedAction=null;
 await agent.send('Full chat normal send');
 assert.equal(routes.at(-1),'agent-chat','canonical full chat still works when requested');
 console.log('Section 90K: 17 menu, popup, permission, navigation, and continuity assertions passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
