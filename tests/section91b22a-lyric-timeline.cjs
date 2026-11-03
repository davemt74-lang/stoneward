'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const code=fs.readFileSync('assets/js/song-moments.js','utf8');
const deferred=()=>{let resolve;const promise=new Promise(r=>{resolve=r});return {promise,resolve};};
const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};

// The production module owns the DOM. This small DOM exercises those real
// controls and handlers without a rendering library or provider connection.
class Element extends EventTarget{
  constructor(tag='div'){super();this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.attributes={};this.dataset={};this.style={};this.className='';this.hidden=false;this.value='';this._text='';
    this.classList={add:(...items)=>{this.className=[...new Set([...this.className.split(/\s+/).filter(Boolean),...items])].join(' ');},remove:(...items)=>{this.className=this.className.split(/\s+/).filter(x=>!items.includes(x)).join(' ');},contains:item=>this.className.split(/\s+/).includes(item),toggle:(item,on)=>{const present=this.classList.contains(item),next=on===undefined?!present:!!on;if(next)this.classList.add(item);else this.classList.remove(item);return next;}};
  }
  set textContent(text){this._text=String(text);this.replaceChildren();}get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
  set innerHTML(html){this._text='';this.replaceChildren();for(const match of html.matchAll(/<([a-z]+)[^>]*\bid="([^"]+)"[^>]*>/gi)){const e=new Element(match[1]);e.id=match[2];this.append(e);}}
  append(...items){for(const item of items){item.remove();item.parentElement=this;this.children.push(item);}}appendChild(item){this.append(item);return item;}
  replaceChildren(...items){for(const item of this.children)item.parentElement=null;this.children=[];this.append(...items);}
  remove(){if(this.parentElement){this.parentElement.children=this.parentElement.children.filter(x=>x!==this);this.parentElement=null;}}
  setAttribute(key,value){this.attributes[key]=String(value);}getAttribute(key){return this.attributes[key]??null;}removeAttribute(key){delete this.attributes[key];}
  querySelectorAll(selector){const matches=node=>selector[0]==='#'?node.id===selector.slice(1):selector[0]==='.'?node.classList.contains(selector.slice(1)):node.tagName.toLowerCase()===selector;const result=[];for(const node of this.children){if(matches(node))result.push(node);result.push(...node.querySelectorAll(selector));}return result;}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}focus(){}getBoundingClientRect(){return {left:0,top:0,width:300,height:16};}
}
function fixture(){
  const body=new Element('body'),document={body,createElement:tag=>new Element(tag),querySelector:selector=>body.querySelector(selector)};
  const window=new EventTarget();window.localStorage={getItem:()=>null,setItem:()=>{},removeItem:()=>{}};
  const audio=new EventTarget();Object.assign(audio,{duration:240,currentTime:0,paused:true});
  const pending=[],references=[],presented=[],narrations=[],timers=new Map();let timerId=0;
  const context={window,document,localStorage:window.localStorage,fetch:url=>{const d=deferred();pending.push({url,...d});return d.promise;},setTimeout:fn=>{timers.set(++timerId,fn);return timerId;},clearTimeout:id=>timers.delete(id),console};
  vm.createContext(context);vm.runInContext(code,context);
  const seekParent=new Element(),seekInput=new Element('input');seekParent.append(seekInput);
  const api=window.StonefellowSongMoments,controller=api.create({audio,seekInput,onLyricReference:row=>references.push(row),onPresent:row=>presented.push(row),onNarrate:row=>narrations.push(row)});
  const response=(at,data)=>pending[at].resolve({ok:true,json:async()=>({ok:true,moments:[],mine:[],...data})});
  return {api,controller,body,audio,pending,references,presented,narrations,response,timers,seekParent};
}
const lyric=(id,at_ms,end_ms,body='These are saved lyric words')=>({id,track_id:'song-a',kind:'lyric',at_ms,end_ms,body,status:'published',lyric_state:'approved',confidence:null,timing_source:'manual'});
test('Timestamped lyrics reject empty/reversed ranges and retain exact millisecond boundaries',()=>{
  const f=fixture(),rows=f.api.normalize([lyric(1,13001,14002),lyric(2,0,0),lyric(3,5000,4000),lyric(4,-1,1000),{id:5,at_ms:1200,kind:'note'}]);
  assert.deepEqual(Array.from(rows,x=>x.id),[5,1]);assert.equal(f.api.range(rows[1]),'0:13.001 – 0:14.002');
  assert.equal(f.api.currentLyrics(rows,13000).length,0);assert.equal(f.api.currentLyrics(rows,13001).length,1);assert.equal(f.api.currentLyrics(rows,14002).length,0);f.controller.destroy();
});
test('Real timeline renders saved lyrics safely, exposes range reference and click-to-seek',async()=>{
  const f=fixture();f.controller.setTrack({id:'song-a',title:'Song A'});f.response(0,{moments:[lyric(1,60001,70002,'<script>lyrics remain text</script>')]});await flush();f.controller.open();
  const row=f.body.querySelector('.sf-song-lyric-entry');assert.ok(row);assert.match(row.textContent,/1:00\.001 – 1:10\.002/);assert.match(row.textContent,/Approved lyrics/);assert.match(row.textContent,/Confidence: Unknown/);assert.match(row.textContent,/<script>lyrics remain text<\/script>/);assert.equal(row.querySelector('script'),null);
  row.querySelector('.sf-song-lyric-reference').dispatchEvent(new Event('click'));assert.equal(f.references.length,1);assert.equal(f.references[0].end_ms,70002);assert.equal(f.references[0].body,'<script>lyrics remain text</script>');
  row.querySelector('.sf-song-moment-jump').dispatchEvent(new Event('click'));assert.equal(f.audio.currentTime,60.001);assert.equal(row.getAttribute('aria-current'),'true');f.controller.destroy();
});
test('Playback and paused seeking highlight [start,end) without replacing timeline controls',async()=>{
  const f=fixture();f.controller.setTrack({id:'song-a'});f.response(0,{moments:[lyric(1,10000,20000),lyric(2,20000,30000)]});await flush();
  const rows=f.body.querySelectorAll('.sf-song-lyric-entry'),button=rows[0].querySelector('.sf-song-lyric-reference');
  f.audio.currentTime=10;f.audio.dispatchEvent(new Event('timeupdate'));assert.equal(rows[0].getAttribute('aria-current'),'true');
  f.audio.currentTime=20;f.audio.dispatchEvent(new Event('seeked'));assert.equal(rows[0].getAttribute('aria-current'),null);assert.equal(rows[1].getAttribute('aria-current'),'true');assert.equal(rows[0].querySelector('.sf-song-lyric-reference'),button);
  f.audio.currentTime=30;f.audio.dispatchEvent(new Event('timeupdate'));assert.equal(rows[1].getAttribute('aria-current'),null);assert.equal(f.controller.getContext().lyrics.length,0);f.controller.destroy();
});
test('Lyric highlighting never triggers automatic narration or a provider submission',async()=>{
  const f=fixture();f.controller.setTrack({id:'song-a'});f.response(0,{moments:[lyric(1,10000,20000)]});await flush();f.audio.paused=false;f.audio.currentTime=9.5;f.audio.dispatchEvent(new Event('timeupdate'));f.audio.currentTime=10.5;f.audio.dispatchEvent(new Event('timeupdate'));
  assert.equal(f.controller.getContext().lyrics.length,1);assert.equal(f.presented.length,0);assert.equal(f.narrations.length,0);assert.equal(f.pending.length,1);f.controller.destroy();
});
test('Full-song phrase timelines retain over 200 segments and report provided confidence accurately',async()=>{
  const f=fixture();f.controller.setTrack({id:'song-a'});const rows=Array.from({length:240},(_,i)=>lyric(i+1,i*1000,(i+1)*1000));rows[0].confidence=0;rows[1].confidence=.93;rows[2].confidence=1.5;rows[1].timing_source='provider';
  f.response(0,{moments:rows});await flush();const entries=f.body.querySelectorAll('.sf-song-lyric-entry');assert.equal(entries.length,240);assert.match(entries[0].textContent,/Confidence: 0%/);assert.match(entries[1].textContent,/Confidence: 93% · Provider-timed range/);assert.match(entries[2].textContent,/Confidence: Unknown/);f.audio.currentTime=239.5;f.audio.dispatchEvent(new Event('seeked'));assert.equal(entries[239].getAttribute('aria-current'),'true');f.controller.destroy();
});
test('A late track load cannot replace the current song or leak its lyric range',async()=>{
  const f=fixture();f.controller.setTrack({id:'song-a'});f.controller.setTrack({id:'song-b'});f.response(1,{moments:[{...lyric(2,1000,3000,'Current song words'),track_id:'song-b'}]});await flush();
  f.response(0,{moments:[lyric(1,1000,3000,'Old song words')]});await flush();assert.match(f.body.querySelector('#sfSongMomentsEntries').textContent,/Current song words/);assert.doesNotMatch(f.body.querySelector('#sfSongMomentsEntries').textContent,/Old song words/);assert.equal(f.controller.getContext().track_id,'song-b');f.controller.destroy();
});
test('Destroy invalidates pending loads and removes timeline controls',async()=>{
  const f=fixture();f.controller.setTrack({id:'song-a'});f.controller.destroy();f.response(0,{moments:[lyric(1,1000,3000)]});await flush();assert.equal(f.body.children.length,0);assert.equal(f.seekParent.children.length,1);assert.equal(f.timers.size,0);
});
