'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const app=fs.readFileSync(path.join(root,'assets/js/app.js'),'utf8');
let passes=0;
function check(label,fn){fn();passes++;console.log('91B8B PASS '+label)}
const start=app.indexOf('  async function transcribeLastCapturedSong(');
const end=app.indexOf('  async function executeSongCaptureAction(',start);
check('Transcription method exists in the browser-owned agent path',()=>assert.ok(start>0&&end>start));
const source=app.slice(start,end);
const captured={track_id:'published-1',title:'Genius of Unhumankind',mime_type:'audio/webm',
  start_ms:12100,end_ms:27700,captured_at:'2026-10-30T10:12:35.000Z',blob:new Blob(['fake audio'],{type:'audio/webm'})};
const notices=[];const requests=[];const experienceUpdates=[];
let stored=null,available=captured,authRefreshes=0,mode='ok';
const context={songTranscriptionBusy:false,songLyricDraft:null,st:{auth:{csrf:'csrf-demo'}},
  songCaptureSkill:{getCapture:()=>available},
  cfg:{apiBase:'api'},FormData,Blob,
  loadAuth:async()=>{authRefreshes++;context.st.auth.csrf='new-csrf'},
  updateSongExperience:(state)=>experienceUpdates.push(state),
  popupEntry:(role,text)=>notices.push({role,text}),
  fetch:async(url,config)=>{requests.push({url,config});return mode==='fail'
    ?{status:503,ok:false,json:async()=>({ok:false,message:'Provider not enabled.'})}
    :{status:200,ok:true,json:async()=>({ok:true,transcript:{text:'First line\nSecond line',track_id:'published-1',
      title:'Genius of Unhumankind',start_ms:12100,end_ms:27700,review_status:'unverified'}})};
  }
};
vm.createContext(context);
vm.runInContext(source+'\nthis.transcribe=transcribeLastCapturedSong;this.currentDraft=()=>songLyricDraft;',context);
async function main(){
  check('No background upload occurs without explicit transcription',()=>assert.equal(requests.length,0));
  const result=await context.transcribe();
  check('Explicit transcription reports successful draft',()=>assert.match(result,/unverified/i));
  check('Binary clip submitted with session cookie and CSRF header',()=>{
    assert.equal(requests.length,1);assert.equal(requests[0].url,'api/agent-song-transcribe.php');
    assert.equal(requests[0].config.credentials,'same-origin');
    assert.equal(requests[0].config.headers['X-CSRF-Token'],'csrf-demo');
    assert.equal(requests[0].config.body.get('track_id'),'published-1');
    assert.equal(requests[0].config.body.get('start_ms'),'12100');
    assert.equal(requests[0].config.body.get('end_ms'),'27700');
    assert.equal(requests[0].config.body.get('clip').size,10);
  });
  check('Draft text and offset metadata remain in memory',()=>{
    stored=context.currentDraft();assert.equal(stored.text,'First line\nSecond line');
    assert.equal(stored.end_ms,27700);assert.equal(stored.review_status,'unverified');
    assert.equal(experienceUpdates.at(-1).phase,'draft');
    assert.equal(experienceUpdates.at(-1).transcript,'First line\nSecond line');
    assert.match(experienceUpdates.at(-1).message,/Review these estimated words/);
    assert.equal(notices.length,0,'Lyric metadata is not announced in voice/chat');
  });
  mode='fail';const failed=await context.transcribe();
  check('Provider failure retains captured audio for retry',()=>{
    assert.match(failed,/retry/i);assert.equal(available,captured);assert.equal(context.currentDraft(),stored);
  });
  available=null;const notCaptured=await context.transcribe();
  check('Missing capture cannot trigger a new upload',()=>{
    assert.match(notCaptured,/no completed song section/i);assert.equal(requests.length,2);
  });
  check('Named-song resolver preserves explicit transcript action',()=>{
    const scope={window:{}};vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/agent-audio-player-skill.js'),'utf8'),scope);
    const resolved=scope.window.StonefellowAgentAudioSkill.resolvePublished('Transcribe what you heard',[],{type:'song_capture_transcribe'});
    assert.equal(resolved.action.type,'song_capture_transcribe');
  });
  check('Client exposes only capture availability and prevents accidental uploads',()=>{
    assert.match(app,/song_capture_available:songCaptureSkill\?\.getCapture\(\)\?1:0/);
    assert.match(app,/if\(action\.type==='song_capture_transcribe'\)return await transcribeLastCapturedSong\(\)/);
  });
  check('Audio transcript endpoint contains auth, CSRF and bounded multipart checks',()=>{
    const endpoint=fs.readFileSync(path.join(root,'api/agent-song-transcribe.php'),'utf8');
    assert.match(endpoint,/sf_require_user\(false,true\)/);assert.match(endpoint,/is_uploaded_file\(\$path\)/);
    assert.match(endpoint,/20\*1024\*1024/);assert.match(endpoint,/sf_inference_transport/);
    assert.doesNotMatch(endpoint,/move_uploaded_file/);
  });
  check('Frontend bundle remains parseable',()=>new (require('node:vm').Script)(app));
  console.log(`Section 91B8B: ${passes} browser transcription assertions passed`);
}
main().catch(error=>{console.error(error);process.exitCode=1});
