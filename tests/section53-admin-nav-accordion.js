'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),path=require('path');
const src=fs.readFileSync(path.join(__dirname,'../admin/assets/sidebar-accordion.js'),'utf8');
let pass=0;const verify=(name,fn)=>{fn();pass++;process.stdout.write('PASS '+name+'\n')};
const keys=['overview','music','publishing','audience','commerce','agent','administration'];
function boot(seed,options={}){
  const store={...seed};const groups=keys.map((key,index)=>{
    const listeners={};return {dataset:{navCategory:key},open:index===0,
      addEventListener(name,fn){listeners[name]=fn;},toggle(){listeners.toggle();}};
  });
  const nav={dataset:{},querySelectorAll(selector){assert.equal(selector,'details[data-nav-category]');return groups;}};
  const document={getElementById(id){assert.equal(id,'adminNav');return nav;}};
  const localStorage={getItem(key){if(options.blockStorage)throw new Error('denied');return store[key]??null;},
    setItem(key,value){if(options.blockStorage)throw new Error('denied');store[key]=value;}};
  const window={localStorage};
  vm.runInNewContext(src,{document,window,JSON,Object},{filename:'sidebar-accordion.js'});
  return {groups,nav,store,api:window.SFAdminNavAccordion};
}
verify('Fresh visit opens only first category',()=>{
  const {groups}=boot();assert.deepEqual(groups.map(x=>x.open),[true,false,false,false,false,false,false]);
});
verify('Multiple independent accordion states persist',()=>{
  const b=boot();b.groups[0].open=false;b.groups[3].open=true;b.groups[5].open=true;b.groups[5].toggle();
  const stored=JSON.parse(b.store[b.api.storageKey]);assert.equal(stored.overview,false);assert.equal(stored.audience,true);assert.equal(stored.agent,true);
});
verify('A reload restores every saved open and closed category',()=>{
  const first=boot();first.groups[0].open=false;first.groups[2].open=true;first.groups[6].open=true;first.groups[6].toggle();
  const second=boot(first.store);assert.deepEqual(second.groups.map(x=>x.open),[false,false,true,false,false,false,true]);
});
verify('Unknown keys cannot open a category',()=>{
  const b=boot({'stonefellow.admin.navigation.categories.v1':JSON.stringify({overview:false,ghost:true,music:true})});
  assert.equal(b.groups[0].open,false);assert.equal(b.groups[1].open,true);assert.equal(b.groups[2].open,false);
});
verify('Corrupt state falls back to Overview-only',()=>{
  const b=boot({'stonefellow.admin.navigation.categories.v1':'{broken'});assert.equal(b.groups[0].open,true);
  assert.equal(b.groups.slice(1).every(x=>!x.open),true);
});
verify('Non-object state falls back safely',()=>{
  const b=boot({'stonefellow.admin.navigation.categories.v1':'[]'});assert.equal(b.groups[0].open,true);
});
verify('Browser storage denial never breaks accordions',()=>{
  const b=boot({}, {blockStorage:true});b.groups[0].open=false;assert.doesNotThrow(()=>b.groups[0].toggle());
});
verify('Initialization is idempotent',()=>{
  const b=boot();b.groups[1].open=true;b.api.init();assert.equal(b.groups[1].open,true);
});
verify('No account identifiers stored; only category booleans',()=>{
  const b=boot();b.groups[1].open=true;b.groups[1].toggle();
  const saved=JSON.parse(b.store[b.api.storageKey]);assert.deepEqual(Object.keys(saved),keys);
  assert.equal(Object.values(saved).every(x=>typeof x==='boolean'),true);
});
console.log('Section 53 browser behavior: '+pass+'/9 checks passed');
