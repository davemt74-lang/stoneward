'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
let passed=0;
function check(label,fn){fn();passed++;console.log('91B11A PASS '+label);}
class Element {
  constructor(tag){this.tagName=tag;this.children=[];this.listeners={};this.attributes={};this.hidden=false;this.disabled=false;this.value='';this._text='';}
  set textContent(text){this._text=String(text);this.children=[];}
  get textContent(){return this._text+this.children.map(child=>child.textContent).join('');}
  append(...children){this.children.push(...children);}
  replaceChildren(...children){this.children=[...children];this._text='';}
  setAttribute(name,value){this.attributes[name]=value;}
  addEventListener(name,fn){this.listeners[name]=fn;}
  find(tag){if(this.tagName===tag)return this;for(const child of this.children){const result=child.find(tag);if(result)return result;}return null;}
  all(tag){return [...(this.tagName===tag?[this]:[]),...this.children.flatMap(child=>child.all(tag))];}
}
const scope={window:{},document:{createElement:tag=>new Element(tag)}};
vm.runInNewContext(read('assets/js/agent-song-experience-ui.js'),scope);
const {mount}=scope.window.SFSongExperienceUI;
let actions=[];const onAction=(...args)=>actions.push(args);
const host=new Element('div');
const base={title:'Genius of Unhumankind',start_ms:12500,end_ms:27500,transcript:'',reports:[],canAnalyze:false};
check('A captured excerpt stays visible and offers a deliberate transcription action',()=>{
  mount(host,{...base,phase:'captured'},onAction);
  assert.equal(host.hidden,false);
  assert.match(host.textContent,/Genius of Unhumankind/);
  assert.match(host.textContent,/0:12 – 0:27/);
  assert.match(host.textContent,/Transcribe excerpt/);
  assert.equal(actions.length,0);
  host.all('button').find(b=>b.textContent==='Transcribe excerpt').listeners.click();
  assert.deepEqual(actions,[['transcribe']]);
});
check('The live listening state offers Stop without auto-transcribing',()=>{
  actions=[];mount(host,{...base,phase:'capturing',end_ms:null},onAction);
  assert.match(host.textContent,/Listening now/);
  assert.match(host.textContent,/Stop listening/);
  assert.doesNotMatch(host.textContent,/Transcribe excerpt/);
  assert.equal(actions.length,0);
});
const transcript='First line <script>alert(1)</script>\nAnother line';
check('Unverified lyrics are rendered literally, labeled and not automatically analyzed',()=>{
  actions=[];
  mount(host,{...base,phase:'draft',transcript,canAnalyze:true},onAction);
  assert.match(host.textContent,/Automated lyric draft · unverified/);
  assert.match(host.textContent,/<script>alert\(1\)<\/script>/);
  assert.equal(host.all('script').length,0);
  assert.match(host.textContent,/Nothing is saved to Agent Brain automatically/);
  assert.equal(actions.length,0);
  host.all('button').find(b=>b.textContent==='Analyze excerpt').listeners.click();
  assert.deepEqual(actions,[['analyze']]);
});
check('A new question is an explicit follow-up analysis request',()=>{
  actions=[];const form=host.find('form');
  assert.ok(form);
  form.find('input').value='Why was this metaphor used?';
  form.listeners.submit({preventDefault(){}});
  assert.deepEqual(actions,[['ask','Why was this metaphor used?']]);
});
check('Multiple full reports remain readable in the same chat card',()=>{
  mount(host,{...base,phase:'report',transcript,canAnalyze:true,reports:[
    {question:'First interpretation',text:'Interpretation one.\nEvidence and context.'},
    {question:'What about the ending?',text:'Interpretation two.'}
  ]},onAction);
  assert.match(host.textContent,/Analysis 1 · First interpretation/);
  assert.match(host.textContent,/Evidence and context/);
  assert.match(host.textContent,/Analysis 2 · What about the ending\?/);
  assert.match(host.textContent,/No live web sources were searched/);
  assert.match(host.textContent,/Open My Agent Chat/);
});
check('Track changes keep old text visible but disable stale analysis',()=>{
  mount(host,{...base,phase:'report',transcript,canAnalyze:false,reports:[{text:'Prior report'}]},onAction);
  assert.match(host.textContent,/Prior report/);
  assert.equal(host.all('button').find(b=>b.textContent==='Analyze again').disabled,true);
  assert.equal(host.all('form').length,0);
});
check('Private saves are explicit and show the note number',()=>{
  mount(host,{...base,phase:'report',transcript,canAnalyze:true,saved_note_id:42},onAction);
  const save=host.all('button').find(b=>b.textContent==='Saved privately #42');
  assert.ok(save);assert.equal(save.disabled,true);
});
const app=read('assets/js/app.js'),canvas=read('assets/js/agent-chat-canvas.js'),html=read('stonefellow-v120.php');
check('Popup and full-page chat share one browser-memory experience state',()=>{
  assert.match(app,/window\.StonefellowSongExperience=Object\.freeze/);
  assert.match(app,/window\.StonefellowSongExperience\?\.mount\(host\)/);
  assert.match(app,/window\.SFCustomerAgentChat\?\.onSongExperience\?\.\(\)/);
  assert.match(canvas,/id="agentSongExperienceCanvas"/);
  assert.match(canvas,/onSongExperience:syncSongExperience/);
  assert.ok(html.indexOf('agent-song-experience-ui.js')<html.indexOf('agent-chat-canvas.js'));
  assert.ok(html.indexOf('agent-chat-canvas.js')<html.indexOf('assets/js/app.js'));
});
check('Transcription and analysis report update the on-screen session after the request',()=>{
  assert.match(app,/phase:'captured'.*audio/i);
  assert.match(app,/phase:'transcribing'/);
  assert.match(app,/phase:'draft',title:draft.title/);
  assert.match(app,/phase:'analyzing'/);
  assert.match(app,/phase:'report',reports:\[/);
  assert.match(app,/phase:'discarded'/);
});
check('Card rendering does not persist lyrics or trigger inference providers',()=>{
  const ui=read('assets/js/agent-song-experience-ui.js');
  assert.doesNotMatch(ui,/localStorage|sessionStorage|indexedDB|fetch\s*\(|XMLHttpRequest|secureJsonPost/);
  assert.match(ui,/textContent = String\(value\)/);
  assert.match(app,/currentSongDraft\(\)/);
});
check('All changed browser modules parse',()=>{
  for(const file of ['assets/js/app.js','assets/js/agent-song-experience-ui.js','assets/js/agent-chat-canvas.js'])new vm.Script(read(file),{filename:file});
});
console.log('Section 91B11A: '+passed+' UI / privacy / continuity assertions passed');
