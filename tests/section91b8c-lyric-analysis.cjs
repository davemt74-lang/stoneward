'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const app=fs.readFileSync(path.join(root,'assets/js/app.js'),'utf8');
let passes=0;
function check(label,fn){fn();passes++;process.stdout.write('91B8C PASS '+label+'\n')}
const start=app.indexOf('  async function analyzeLastSongDraft(');
const end=app.indexOf('  async function executeSongCaptureAction(',start);
check('Analysis action is owned by the authenticated browser agent',()=>assert.ok(start>0&&end>start));
const capture={track_id:'track-1',start_ms:12100,end_ms:27700};
const draft={track_id:'track-1',start_ms:12100,end_ms:27700,captured_at:'2026-10-30T10:12:35Z',
  text:'Recognized but unverified words',title:'Genius of Unhumankind',review_status:'unverified'};
const requests=[];const messages=[];
let available=capture,mode='ok';
const context={songAnalysisBusy:false,songLyricReport:null,songLyricDraft:draft,songLyricCorrected:'',
  songCaptureSkill:{getCapture:()=>available},currentSongSource:()=>available,Intl,
  popupEntry:(role,text)=>messages.push({role,text}),
  updateSongExperience:()=>{},songExperience:{reports:[]},
  secureJsonPost:async(api,payload)=>{requests.push({api,payload});if(mode==='fail')throw Error('Provider unavailable');
    return {ok:true,analysis:{track_id:'track-1',title:'Genius of Unhumankind',start_ms:12100,end_ms:27700,
      report:'The metaphor could be read in two ways.',draft_status:'unverified_user_supplied'}};
  }
};
vm.createContext(context);
vm.runInContext(app.slice(start,end)+'\nthis.analyze=analyzeLastSongDraft;this.getReport=()=>songLyricReport;',context);
async function main(){
  check('No background provider request occurs',()=>assert.equal(requests.length,0));
  const response=await context.analyze('Explain the word choice in this verse');
  check('Explicit analysis returns source-qualified report',()=>{
    assert.match(response,/metaphor/i);assert.match(response,/unverified transcript/i);assert.match(response,/no live web sources/i);
  });
  check('Only opted-in draft, song time range and request are sent',()=>{
    assert.equal(requests.length,1);assert.equal(requests[0].api,'agent-song-analyze.php');
    assert.equal(requests[0].payload.track_id,'track-1');assert.equal(requests[0].payload.start_ms,12100);
    assert.equal(requests[0].payload.end_ms,27700);assert.equal(requests[0].payload.transcript,draft.text);
    assert.equal(requests[0].payload.question,'Explain the word choice in this verse');
    assert.ok(requests[0].payload.timezone);
    assert.equal(Object.hasOwn(requests[0].payload,'blob'),false);
  });
  check('Report remains private in current browser memory',()=>assert.match(context.getReport().report,/metaphor/));
  mode='fail';const failure=await context.analyze('Please explain it again');
  check('Provider failure preserves draft for retry',()=>{
    assert.match(failure,/could not finish/i);assert.equal(context.songLyricDraft,draft);
  });
  available=null;const unavailable=await context.analyze('Explain that verse');
  check('Track change prevents stale draft disclosure',()=>{
    assert.match(unavailable,/capture and transcribe/i);assert.equal(requests.length,2);
  });
  check('Analysis action uses the same authorized agent execution path',()=>{
    assert.match(app,/song_lyric_draft_available:songLyricDraft\?1:0/);
    assert.match(app,/if\(action\.type==='song_capture_analyze'\)return await analyzeLastSongDraft\(action\.question\)/);
    assert.match(app,/executeSongCaptureAction\(verified\.action\)/);
  });
  check('Named-song resolver never treats analysis as a song-title search',()=>{
    const sandbox={window:{}};
    vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/agent-audio-player-skill.js'),'utf8'),sandbox);
    const r=sandbox.window.StonefellowAgentAudioSkill.resolvePublished('Analyze this verse',[],{type:'song_capture_analyze'});
    assert.equal(r.action.type,'song_capture_analyze');
  });
  check('Analysis endpoint authenticates and uses approved context plus consented insights',()=>{
    const src=fs.readFileSync(path.join(root,'api/agent-song-analyze.php'),'utf8');
    assert.match(src,/sf_require_user\(false,true\)/);assert.match(src,/sf_agent_context/);
    assert.match(src,/sf_agent_listening_insights\(\$userId,\$request\['timezone'\]\)/);
    assert.match(src,/sf_ai_llm_candidates\('chat'\)/);assert.doesNotMatch(src,/move_uploaded_file/);
  });
  check('Browser code remains syntactically valid',()=>new vm.Script(app));
  process.stdout.write(`Section 91B8C: ${passes} browser assertions passed\n`);
}
main().catch(e=>{console.error(e);process.exitCode=1});
