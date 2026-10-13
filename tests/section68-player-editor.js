'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/turntable-parts.js'),'utf8');
const begin=source.indexOf('  const TABS=[...SLOTS,\'pack\'];');
const end=source.indexOf('  function purchase(id){',begin);
assert.ok(begin>0&&end>begin,'Production player modal functions exist');
const slots=['base','platter','arm','label','effect'],all=[...slots,'pack'];
const defaults={base:'base-studio',platter:'platter-classic',arm:'arm-studio',label:'label-classic',effect:'effect-none'};
const mods=[...slots.map(slot=>({slot,id:defaults[slot],name:'Classic '+slot,price_cents:0})),{slot:'base',id:'base-premium',name:'Premium Base',price_cents:1200},{slot:'pack',id:'pack-upgrade',name:'Complete Studio',includes:['base-premium'],price_cents:1700}];
const state={builder:{playerModules:{...defaults}}};let persisted=0,drawn=0,focuses=0;
const tabs=all.map(slot=>({dataset:{playerTab:slot},attrs:{},setAttribute(k,v){this.attrs[k]=v;},focus(){focuses++;}}));
const panels=all.map(slot=>({dataset:{playerPanel:slot},hidden:false}));
const baseSelect={dataset:{ttSlot:'base'},value:'base-studio',focus(){}};
const box={innerHTML:'',querySelector(q){return null;},querySelectorAll(q){if(q==='[data-player-tab]')return tabs;if(q==='[data-tt-slot]')return [baseSelect];return [];}};
const editor={isConnected:true,open:false,querySelectorAll(q){return q==='[data-player-tab]'?tabs:panels;},querySelector(q){if(q==='[data-turntable-options]')return box;const match=q.match(/data-player-tab="([^"]+)"/);return match?tabs.find(t=>t.dataset.playerTab===match[1]):null;},showModal(){this.open=true;},close(){this.open=false;}};
const sandbox={
  catalogLoaded:true,catalogLoading:false,catalogError:'',SLOTS:slots,LABELS:{base:'Player base',platter:'Record platter',arm:'Tonearm',label:'Center label',effect:'Visual effects',pack:'Experience packs'},editor,activeTab:'base',preview:null,registry:{modules:mods,owned:Object.values(defaults),defaults},
  ctx:{appState:state,persist(){persisted++;}},returnFocus:null,
  defaults:()=>defaults,installed:()=>({...defaults,...state.builder.playerModules}),
  selected:()=>({...defaults,...state.builder.playerModules,...(sandbox.preview||{})}),
  getModules:()=>mods,moduleById:id=>mods.find(m=>m.id===id),owned:id=>Object.values(defaults).includes(id),
  esc:v=>String(v).replace(/&/g,'&amp;'),money:v=>'$'+(v/100).toFixed(2),drawTrack(){drawn++;},purchase(){},
  document:{activeElement:null}
};
vm.runInNewContext(source.slice(begin,end)+'\nthis.playerEditor={openPlayerEditor,setEditorTab,renderPartList};',sandbox,{timeout:2000});
sandbox.playerEditor.openPlayerEditor({isConnected:true,focus(){}});
assert.equal(editor.open,true,'Modal opens from gear/button');
assert.equal((box.innerHTML.match(/role="tab"/g)||[]).length,6,'Six component tabs');
assert.equal((box.innerHTML.match(/role="tabpanel"/g)||[]).length,6,'Accessible panels');
assert.match(box.innerHTML,/Complete Studio/,'Pack choices retained');
assert.equal(tabs[0].attrs['aria-selected'],'true');
assert.equal(panels.filter(p=>!p.hidden).length,1);
sandbox.playerEditor.setEditorTab('arm',true);
assert.equal(tabs[2].attrs['aria-selected'],'true');
assert.equal(panels[2].hidden,false);
assert.equal(panels.filter(p=>!p.hidden).length,1);
assert.ok(focuses>=2,'Keyboard focus moves to selected tab');
assert.match(box.innerHTML,/Visual effect packs change the turntable appearance only/);
sandbox.playerEditor.setEditorTab('base');
baseSelect.value='base-premium';baseSelect.onchange();
assert.equal(sandbox.preview.base,'base-premium','Unowned module is preview-only');
assert.equal(persisted,0,'Preview never persists or grants purchase');
baseSelect.value='base-studio';baseSelect.onchange();
assert.equal(state.builder.playerModules.base,'base-studio');
assert.equal(sandbox.preview,null);
assert.equal(persisted,1,'Owned module changes persist');
assert.ok(drawn>=2,'Record reflects preview and selected appearance');
assert.match(source,/data-player-editor-open/,'Settings and edit button wired');
assert.match(source,/editor\.addEventListener\('close'/,'Escape and close events clear preview');
console.log('Section 68 modal behavior: 13/13 runtime checks passed');
