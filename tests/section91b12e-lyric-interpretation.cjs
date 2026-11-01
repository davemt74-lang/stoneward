'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
let checks=0;
function check(name,run){run();checks++;console.log('91B12E PASS '+name);}
class Element{
  constructor(tag){this.tagName=tag;this.children=[];this.listeners={};this.attributes={};this.hidden=false;this.disabled=false;this.value='';this._text='';}
  set textContent(v){this._text=String(v);this.children=[];}
  get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}
  replaceChildren(...children){this.children=children;this._text='';}
  append(...children){this.children.push(...children);}
  setAttribute(k,v){this.attributes[k]=v;}
  addEventListener(k,v){this.listeners[k]=v;}
  all(tag){return [...(this.tagName===tag?[this]:[]),...this.children.flatMap(c=>c.all(tag))];}
}
const scope={window:{},document:{createElement:tag=>new Element(tag)}};
vm.runInNewContext(read('assets/js/agent-song-experience-ui.js'),scope);
const mount=scope.window.SFSongExperienceUI.mount;
const target=new Element('div');
check('An opted-in live session advertises the interpretation after Stop',()=>{
  mount(target,{title:'Earth Girl',phase:'capturing',start_ms:2500,end_ms:null,
    live_enabled:true,auto_interpret:true,reports:[]},()=>{});
  assert.match(target.textContent,/live lyrics → interpretation on Stop/);
  assert.match(target.textContent,/Stop listening/);
  assert.doesNotMatch(target.textContent,/Start live transcription/);
});
check('Interpretation exposes approved writer-source provenance and unverified lyric disclaimer',()=>{
  mount(target,{title:'Earth Girl',phase:'report',start_ms:2500,end_ms:14500,
    transcript:'The stars are in the trees',canAnalyze:true,
    reports:[{text:'A possible metaphor.',sources:['Published songbook annotations — Earth Girl','Approved knowledge — Earth Girl notes']}]},()=>{});
  assert.match(target.textContent,/Lyric interpretation ready/);
  assert.match(target.textContent,/Published songbook annotations/);
  assert.match(target.textContent,/Approved knowledge/);
  assert.match(target.textContent,/unverified/);
  assert.match(target.textContent,/Interpret again/);
});
check('No writer notes means an explicitly labeled reading, never invented intentions',()=>{
  mount(target,{title:'Earth Girl',phase:'report',start_ms:0,end_ms:2000,
    transcript:'Sky',canAnalyze:true,reports:[{text:'Tentative reading',sources:[]}]},()=>{});
  assert.match(target.textContent,/No song-specific published songwriter notes were matched/);
});
check('Quick links form a vertical mesh-overlay rail without modal, title, or redundant actions',()=>{
  const html=read('stonefellow-v120.php'),css=read('assets/css/site.css');
  const menu=html.match(/<nav id="chatQuickMenu"[\s\S]+?<\/nav>/)?.[0]||'';
  assert.ok(menu);
  assert.doesNotMatch(menu,/Explore & create|sf-quick-grid|modal|dialog|control deck|chatQuickClose/);
  for(const label of ['Create Record','Create Playlist','Tour Dates','Merch Store','Membership','Tickets','Newsletter'])assert.ok(menu.includes(label),label);
  assert.match(css,/\.chat-quick-menu\{[\s\S]*?display:flex;flex-direction:column;[\s\S]*?overflow-y:auto/);
  assert.match(css,/\.chat-quick-menu\{[\s\S]*?background:transparent;box-shadow:none/);
  assert.match(css,/\.chat-quick-menu button::before/);
  assert.match(css,/\.chat-quick-menu button::after/);
});
check('Listen & Interpret and Record Clip are independent orb actions',()=>{
  const canvas=read('assets/js/public-agent-canvas.js'),app=read('assets/js/app.js');
  assert.match(canvas,/data-agent-action="listen-interpret"/);
  assert.match(canvas,/data-agent-action="capture"/);
  assert.match(canvas,/action==='listen-interpret'/);
  assert.match(canvas,/action==='stop-listening'/);
  assert.match(app,/onListenInterpret:async\(\)=>/);
  assert.match(app,/song_capture_live_start',interpret_on_stop:true/);
  assert.match(app,/onStartCapture:async\(\)=>/);
  assert.match(app,/song_capture_start/);
  assert.match(app,/songListeningSkill\.begin\(\)/);
  assert.match(app,/songListeningReceipt=result\.receipt/);
  assert.match(app,/if\(songExperience\.auto_interpret\)return await analyzeLastSongDraft/);
  assert.match(app,/if\(action\.type==='song_capture_start'\)\{/);
});
check('The selected auto-interpretation does not persist or publish a recording',()=>{
  const app=read('assets/js/app.js'),ui=read('assets/js/agent-song-experience-ui.js');
  const route=app.slice(app.indexOf("if(action.type==='song_capture_live_start'){"),
    app.indexOf("if(action.type==='song_capture_stop'&&songListeningSkill"));
  assert.match(route,/songListeningSkill\.begin\(\)/);
  assert.doesNotMatch(route,/songCaptureSkill\.begin\(\)|enableLive\(|agent-song-transcribe\.php/);
  assert.doesNotMatch(app,/uploadSongLiveSegment|enqueueSongLiveSegment/);
  assert.doesNotMatch(ui,/localStorage|sessionStorage|indexedDB|fetch\s*\(|XMLHttpRequest/);
  new vm.Script(read('assets/js/public-agent-canvas.js'));
  new vm.Script(read('assets/js/app.js'));
});
console.log('Section 91B12E: '+checks+' browser, source, and privacy checks passed');
