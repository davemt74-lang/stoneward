'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const app=fs.readFileSync(__dirname+'/../assets/js/app.js','utf8');
const start=app.indexOf('  function currentSongDraft(');
const end=app.indexOf('  // LLM-resolved titles must carry',start);
assert.ok(start>0&&end>start,'Private annotation commands exist before LLM dispatch');
let passes=0;const check=(name,fn)=>{fn();passes++;console.log('91B8E PASS: '+name)};
const capture={track_id:'one',start_ms:10000,end_ms:24000,captured_at:'2026-10-29T10:12:35Z'};
const draft={...capture,title:'First Track',text:'Unverified guess',review_status:'unverified'};
const post=[],notes=[],scope={songCaptureSkill:{getCapture:()=>capture},songLyricDraft:draft,
  songLyricCorrected:'',songLyricReport:null,songPersonalNote:'',st:{track:{id:'one'},conversationId:'old'},
  localStorage:{removeItem:()=>{}},
  window:{confirm:()=>false},time:s=>Math.floor(s/60)+':'+String(Math.floor(s%60)).padStart(2,'0'),
  secureJsonPost:async(path,payload)=>{post.push({path,payload});if(payload.action==='save')return {annotation:{id:3,title:'First Track',start_ms:10000,end_ms:24000}};
    if(payload.action==='update')return {updated:true};return {deleted:true}},
  api:async()=>({annotations:[{id:3,title:'First Track',...capture,transcript_draft:'Unverified guess',transcript_corrected:'',user_note:'',analysis_report:''}]})
};
vm.createContext(scope);vm.runInContext(app.slice(start,end)+'\nthis.command=executePrivateAnnotationCommand;',scope);
async function main(){
  check('No private upload happens on startup',()=>assert.equal(post.length,0));
  let r=await scope.command('Correct the lyrics to Better words here');
  check('Correction stays in browser and keeps original draft',()=>{
    assert.match(r,/original machine draft is preserved/i);
    assert.equal(scope.songLyricCorrected,'Better words here');assert.equal(scope.songLyricDraft.text,'Unverified guess');
    assert.equal(post.length,0);
  });
  r=await scope.command('Save this lyric note');
  check('Only explicit save writes a bounded private note',()=>{
    assert.match(r,/saved private lyric note #3/i);
    assert.equal(post.length,1);assert.equal(post[0].path,'agent-song-annotations.php');
    assert.equal(post[0].payload.transcript,'Unverified guess');
    assert.equal(post[0].payload.corrected_transcript,'Better words here');
    assert.equal(Object.hasOwn(post[0].payload,'blob'),false);
  });
  r=await scope.command('List my saved lyric notes');
  check('Private list text is marked display-only, not voice TTS',()=>{
    assert.match(r.privateDisplay,/First Track/);assert.match(r.text,/not read/i);assert.equal(post.length,1);
  });
  r=await scope.command('Show lyric note #3');
  check('Full private draft is displayed but not returned for speech',()=>{
    assert.match(r.privateDisplay,/Unverified guess/);assert.doesNotMatch(r.text,/Unverified guess/);
  });
  r=await scope.command('Edit saved note #3 to My corrected verse');
  check('Explicit saved correction uses owner-scoped endpoint',()=>{
    assert.match(r,/updated your private note/i);
    assert.equal(post[1].payload.action,'update');assert.equal(post[1].payload.id,3);
  });
  r=await scope.command('Delete lyric note #3');
  check('Deletion cannot happen without browser confirmation',()=>{
    assert.match(r,/cancelled/i);assert.equal(post.length,2);
  });
  scope.window.confirm=()=>true;r=await scope.command('Delete lyric note #3');
  check('Confirmed deletion calls the scoped endpoint',()=>{
    assert.match(r,/deleted/i);assert.equal(post[2].payload.action,'delete');assert.equal(post[2].payload.id,3);
  });
  scope.st.track={id:'other'};r=await scope.command('Save this lyric note');
  check('Stale track cannot save an old captured lyric',()=>{
    assert.match(r,/no current/i);assert.equal(post.length,3);
  });
  check('Both typed and spoken chat bypass regular LLM for private commands',()=>{
    assert.match(app,/const privateResult=await executePrivateAnnotationCommand\(text\)/);
    assert.match(app,/if\(privateResult!==null\)/);
    assert.match(app,/popupEntry\('notice',privateResult\.privateDisplay\)/);
  });
  check('No direct LLM or speech provider is called by private command interpreter',()=>{
    const code=app.slice(start,end);assert.doesNotMatch(code,/sf_openai_call|sf_anthropic_call|agentSpeak|agent-chat\.php/);
  });
  check('Entire app script parses',()=>new vm.Script(app));
  console.log('Section 91B8E: '+passes+' browser and privacy assertions passed');
}
main().catch(e=>{console.error(e);process.exitCode=1});
