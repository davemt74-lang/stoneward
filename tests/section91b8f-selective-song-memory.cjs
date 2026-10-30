'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const root=__dirname+'/../',app=fs.readFileSync(root+'assets/js/app.js','utf8');
const from=app.indexOf('  function currentSongDraft('),to=app.indexOf('  // LLM-resolved titles must carry',from);
assert.ok(from>0&&to>from,'Browser-private command dispatcher exists');
let passes=0;const check=(label,fn)=>{fn();passes++;console.log('91B8F PASS '+label)};
const writes=[];let approved=false,removed=false;
const scope={songCaptureSkill:{getCapture:()=>null},songLyricDraft:null,songLyricCorrected:'',songLyricReport:null,songPersonalNote:'',
  st:{track:null,conversationId:'old_session'},
  window:{confirm:()=>approved},localStorage:{removeItem:key=>{if(key==='stonefellow.agent.conversation')removed=true}},
  secureJsonPost:async(path,payload)=>{writes.push({path,payload});return {sharing:{id:payload.id,scope:payload.scope,changed:true}}},
  api:async()=>({annotations:[{id:3,title:'First Track',agent_share_scope:'summary',agent_shared_at:'2026-10-30T00:00:00Z'}],
    share_history:[{previous_scope:'private',new_scope:'summary',changed_at:'2026-10-30T00:00:00Z'}]}),
  time:s=>Math.floor(s/60)+':'+String(Math.floor(s%60)).padStart(2,'0')
};
vm.createContext(scope);vm.runInContext(app.slice(from,to)+'\nthis.command=executePrivateAnnotationCommand;',scope);
async function main(){
  let r=await scope.command('Share lyric note #3 with Agent Brain');
  check('No sharing mutation before explicit confirmation',()=>{assert.match(r,/cancelled/i);assert.equal(writes.length,0)});
  approved=true;r=await scope.command('Share lyric note #3 with Agent Brain');
  check('Explicit summary opt-in sends only ID and scope',()=>{
    assert.match(r,/summary scope/i);assert.equal(writes[0].path,'agent-song-annotations.php');
    assert.equal(writes[0].payload.action,'share');assert.equal(writes[0].payload.id,3);
    assert.equal(writes[0].payload.scope,'summary');assert.equal(Object.hasOwn(writes[0].payload,'transcript'),false);
  });
  r=await scope.command('Share full lyric note #3 with Agent Brain');
  check('Full-draft sharing is a separate explicit request',()=>{
    assert.match(r,/full scope/i);assert.equal(writes[1].payload.scope,'full');
  });
  r=await scope.command('Which lyric notes are shared with Agent Brain');
  check('Sharing inventory stays in display-only chat, not spoken TTS',()=>{
    assert.match(r.privateDisplay,/summary/);assert.doesNotMatch(r.text,/First Track/);
  });
  r=await scope.command('Show lyric note #3 sharing history');
  check('Consent audit is shown privately without regular LLM calls',()=>{
    assert.match(r.privateDisplay,/private → summary/);assert.equal(writes.length,2);
  });
  r=await scope.command('Stop sharing lyric note #3');
  check('Revocation rotates the browser conversation to avoid stale paraphrase history',()=>{
    assert.match(r,/revoked/i);assert.equal(writes[2].payload.scope,'private');
    assert.equal(scope.st.conversationId,'');assert.equal(removed,true);
  });
  check('Private command flow is handled before sending text to general agent LLM',()=>{
    assert.match(app,/const privateResult=await executePrivateAnnotationCommand\(text\)/);
    assert.doesNotMatch(app.slice(from,to),/agent-chat\.php|sf_openai_call|sf_anthropic_call/);
  });
  const core=fs.readFileSync(root+'api/agent-song-annotations-core.php','utf8');
  const runtime=fs.readFileSync(root+'api/agent-runtime.php','utf8');
  check('SQL owner-scoped consent audit and opt-out gating are present',()=>{
    assert.match(core,/WHERE user_id=\? AND id=\?/);assert.match(core,/sf_song_annotation_require_consent/);
    assert.match(core,/agent_song_annotation_share_events/);assert.match(core,/agent_share_scope IN \('summary','full'\)/);
  });
  check('Agent context excludes unshared drafts and secondary evaluator',()=>{
    assert.match(runtime,/sf_song_annotation_agent_context\(\$userId,\$message/);
    assert.ok(runtime.includes("if($sharedSongNotes==='')"));
    assert.match(runtime,/sf_agent_jev_evaluate\(\$message,\$evaluationContext/);
  });
  check('App remains syntactically parseable',()=>new vm.Script(app));
  console.log('Section 91B8F: '+passes+' browser and privacy assertions passed');
}
main().catch(e=>{console.error(e);process.exitCode=1});
