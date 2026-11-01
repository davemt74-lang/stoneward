'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
let count=0;
function check(label,fn){fn();count++;console.log('91B13E PASS '+label);}
class Element{
  constructor(tag){this.tagName=tag;this.children=[];this.listeners={};this.attributes={};this.hidden=false;this.disabled=false;this.value='';this._text='';}
  set textContent(value){this._text=String(value);this.children=[];}
  get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}
  replaceChildren(...children){this.children=children;this._text='';}
  append(...children){this.children.push(...children);}
  setAttribute(key,value){this.attributes[key]=value;}
  addEventListener(name,fn){this.listeners[name]=fn;}
  all(tag){return [...(this.tagName===tag?[this]:[]),...this.children.flatMap(c=>c.all(tag))];}
}
const context={window:{},document:{createElement:tag=>new Element(tag)}};
vm.runInNewContext(read('assets/js/agent-song-experience-ui.js'),context);
const mount=context.window.SFSongExperienceUI.mount;
const target=new Element('section'),actions=[];
const state={track_id:'sf-1',title:'Earth Girl',start_ms:58384,end_ms:90132,phase:'draft',
  transcript:'The reality of a perfect mistakes gravity keeps love Harborough light',
  canAnalyze:true,auto_interpret:true,reports:[]};
check('Review gate shows unverified lyrics, pencil and consented interpret action',()=>{
  mount(target,state,(name,value)=>actions.push([name,value]));
  const pencil=target.all('button').find(node=>node.attributes['aria-label']==='Edit lyric draft');
  assert.ok(pencil);
  assert.match(target.textContent,/Review & interpret lyrics/);
  assert.doesNotMatch(target.textContent,/Published catalog credits/);
  assert.equal(actions.length,0);
});
check('Pencil opens a real editable field without invoking an LLM request',()=>{
  target.all('button').find(node=>node.attributes['aria-label']==='Edit lyric draft').listeners.click();
  const field=target.all('textarea')[0];
  assert.ok(field);
  assert.equal(field.value,state.transcript);
  assert.equal(actions.length,0);
  field.value='The reality of our imperfect mistakes keeps love alive';
  field.listeners.input();
  target.all('form')[0].listeners.submit({preventDefault(){}});
  assert.equal(actions.length,1);
  assert.equal(actions[0][0],'edit');
  assert.equal(actions[0][1],field.value);
});
check('A corrected draft takes precedence; metadata is collapsed below analysis',()=>{
  const report={text:'The contrast between imperfection and love suggests resilience.',
    sources:['Published songbook notes'],question:'What do the words mean?'};
  mount(target,{...state,corrected:actions[0][1],phase:'report',reports:[report]},()=>{});
  assert.match(target.textContent,/Listener-edited draft/);
  assert.match(target.textContent,/The contrast between imperfection and love/);
  const details=target.all('details');
  assert.equal(details.length,1);
  assert.match(details[0].textContent,/Published songbook notes/);
  assert.match(details[0].textContent,/Question:/);
  assert.match(target.textContent,/Interpret again/);
  assert.equal(details[0].attributes.open,undefined);
});
check('Automatic interpretation now waits at review and Agent speech is report-only',()=>{
  const app=read('assets/js/app.js');
  assert.match(app,/if\(songExperience\.auto_interpret\)\{\s*updateSongExperience/);
  assert.doesNotMatch(app,/if\(songExperience\.auto_interpret\)return await analyzeLastSongDraft/);
  assert.match(app,/listener_edited:!!songLyricCorrected/);
  assert.match(app,/return report\.report;/);
  assert.match(app,/songLyricReport\?\.report===response\)\s*await say\(response\)/);
  assert.doesNotMatch(app,/popupEntry\('notice','UNVERIFIED LIVE LYRIC DRAFT/);
  new vm.Script(app);
});
console.log('Section 91B13E: '+count+' lyric review, metadata and speech checks passed');