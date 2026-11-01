'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const app=read('assets/js/app.js');
const agent=read('assets/js/public-agent-canvas.js');
const css=read('assets/css/public-agent-canvas.css');
const site=read('assets/css/site.css');
const shell=read('stonefellow-v120.php');
let checks=0;
function check(name,fn){fn();checks++;console.log('91B13A PASS '+name);}
check('Create Record retains native builder route and original action callbacks',()=>{
  assert.match(shell,/<button[^>]+data-quick-action="record"[^>]*>Create Record<\/button>/);
  assert.match(app,/if\(action==='record'\)\{navigate\('builder'\);return\}/);
  assert.match(app,/else if\(view==='builder'\)\{window\.scrollTo\(0,0\);renderBuilder\(\)\}/);
  assert.match(app,/const nativePanel=view!=='home'&&homepageTemplate==='agent_canvas'&&window\.SFPublicAgentCanvas\?\.active\?\.\(\)/);
  assert.match(app,/canvas=nativePanel\?\(window\.SFPublicAgentCanvas\.openPage\?\.\(view\)\|\|mainCanvas\):mainCanvas/);
});
check('Panel renders into original application views, never iframe or external fetch shell',()=>{
  assert.match(agent,/<aside class="sf-agent-page-rail" hidden inert/);
  assert.match(agent,/<div id="sfAgentNativePage" class="sf-agent-page-content"><\/div>/);
  assert.doesNotMatch(agent,/<iframe|srcdoc|contentWindow|fetch\(/i);
  assert.match(agent,/function openPage\(view\)/);
  assert.match(agent,/return pageHost/);
  assert.match(app,/let canvas=mainCanvas/);
  assert.match(app,/SFPublicAgentCanvas\.mount\(mainCanvas/);
});
check('Page close and browser back restore home canvas without replacing the player',()=>{
  assert.match(agent,/function closePage\(\)/);
  assert.match(agent,/pageHost\.replaceChildren\(\)/);
  assert.match(agent,/pageRail\.setAttribute\('inert',''\)/);
  assert.match(agent,/options\.onClosePage\?\.\(\)/);
  assert.match(app,/window\.SFPublicAgentCanvas\.closePage\?\.\(\)/);
  assert.match(app,/void renderHome\(\)\.then\(\(\)=>navigate\(view,params,false\)\)/);
  assert.match(agent,/originalPlayerParent\.insertBefore\(player,before\)/);
});
check('Vertical quick menu, narrow accessible rail, and new futuristic theme',()=>{
  assert.match(site,/\.chat-quick-menu\{[\s\S]*?flex-direction:column;/);
  assert.match(site,/\.chat-quick-menu\{[\s\S]*?overflow-y:auto;/);
  assert.match(css,/\.sf-agent-page-rail\{/);
  assert.match(css,/width:min\(354px,calc\(100% - 20px\)\)/);
  assert.match(css,/\.sf-agent-page-content \:is\(\.store-grid/);
  assert.match(css,/\.sf-agent-page-content \.builder-tablist\{/);
  assert.match(css,/@media\(max-width:720px\)\{[\s\S]*?\.sf-agent-page-rail/);
  assert.match(agent,/aria-label="Close page panel"/);
  assert.match(agent,/class="sf-agent-page-viewport" tabindex="0"/);
});
check('Browser sources parse',()=>{new vm.Script(app);new vm.Script(agent);});
console.log('Section 91B13A: '+checks+' checks passed');
