'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');

function extract(name){
  const start=app.search(new RegExp('(?:async )?function '+name+'\\('));
  assert.ok(start>=0,`${name} missing`);
  const opening=app.indexOf('{',start);let depth=0;
  for(let i=opening;i<app.length;i++){
    if(app[i]==='{')depth++;
    if(app[i]==='}'&&!--depth)return app.slice(start,i+1);
  }
  throw new Error('Unbalanced '+name);
}
async function run(){
  const events=[];let failToPlay=false;
  const audio={paused:false,volume:0.8,duration:200,currentTime:75,
    play:async()=>{if(failToPlay)throw Error('autoplay rejected');audio.paused=false},
    pause:()=>{audio.paused=true}};
  const st={track:{id:'song-1',title:'Night Drive',release:'Moonlight',duration:200},playing:true,conversationOn:false};
  const sandbox={audio,st,musicOriginalVolume:null,voicePlaybackPending:false,lastAudibleVolume:0.7,
    recordListen:type=>events.push(type),updateFooterPlayer:()=>events.push('render'),
    stepTrack:delta=>events.push('step:'+delta),
    play:async track=>{st.track=track;return !failToPlay},
    trackById:id=>id==='song-2'?{id,title:'Northern Sky'}:null,
    time:seconds=>`${Math.floor(seconds/60)}:${String(Math.floor(seconds%60)).padStart(2,'0')}`};
  vm.createContext(sandbox);
  const code=['syncConversationMusic','playerVolume','setPlayerVolume','playerState','describePlayer','executePlayerAgentAction'].map(extract).join('\n');
  vm.runInContext(code+';globalThis.run=executePlayerAgentAction;',sandbox);
  assert.match(await sandbox.run({type:'player_status'}),/“Night Drive”.*playing.*1:15 of 3:20.*80 percent/);
  assert.equal((await sandbox.run({type:'set_player_volume',mode:'set',percent:30})), 'Stonefellow player volume is now 30 percent.');
  assert.equal(audio.volume,0.3);
  sandbox.st.conversationOn=true;
  sandbox.syncConversationMusic();
  assert.equal(audio.volume,0.3,'passive ducking never raises user volume');
  assert.equal(await sandbox.run({type:'set_player_volume',mode:'set',percent:80}),'Stonefellow player volume is now 80 percent.');
  assert.equal(audio.volume,0.4,'active conversation stays ducked');
  sandbox.voicePlaybackPending=true;
  sandbox.syncConversationMusic();
  assert.equal(audio.volume,0.16,'Agent speech ducks further');
  assert.equal(await sandbox.run({type:'set_player_volume',mode:'adjust',percent:-10}),'Stonefellow player volume is now 70 percent.');
  assert.equal(audio.volume,0.16,'ducking preserved after volume command');
  assert.equal(await sandbox.run({type:'set_player_volume',mode:'mute'}),'Stonefellow player volume is now 0 percent.');
  assert.equal(audio.volume,0);
  assert.equal(await sandbox.run({type:'set_player_volume',mode:'unmute'}),'Stonefellow player volume is now 70 percent.');
  sandbox.st.conversationOn=false;sandbox.voicePlaybackPending=false;sandbox.syncConversationMusic();
  assert.ok(Math.abs(audio.volume-0.7)<1e-9,'unducking restores updated user volume, not stale level');
  assert.equal(await sandbox.run({type:'pause_player'}),'Paused “Night Drive.”');
  assert.equal(st.playing,false);
  assert.match(await sandbox.run({type:'pause_player'}),/already paused/);
  assert.equal(await sandbox.run({type:'resume_player'}),'Resumed “Night Drive.”');
  assert.equal(st.playing,true);
  audio.pause();failToPlay=true;
  assert.match(await sandbox.run({type:'resume_player'}),/browser blocked playback/);
  failToPlay=false;
  assert.match(await sandbox.run({type:'play_track',track_id:'song-2'}),/Playing “Northern Sky.”/);
  assert.match(await sandbox.run({type:'play_track',track_id:'missing'}),/could not find/);
  await sandbox.run({type:'next_track'});await sandbox.run({type:'previous_track'});
  assert.ok(events.includes('step:1')&&events.includes('step:-1'));
  st.track=null;
  assert.match(await sandbox.run({type:'player_status'}),/No song is loaded/);
  assert.match(await sandbox.run({type:'resume_player'}),/no song loaded/);
  assert.equal(await sandbox.run({type:'open_view'}),null,'unrelated Agent actions still use existing dispatcher');
  assert.match(app,/\.\.\.playerState\(\)/,'voice and typed Agent receive current playback state');
  assert.match(app,/const playerResult=await executePlayerAgentAction\(j\.action\)/,'same Agent action response is executed locally');
  assert.match(app,/getVolume:\(\)=>playerVolume\(\),setVolume:value=>setPlayerVolume\(value\)/,'turntable and Agent share one volume handler');
  console.log('Section 90M player conversation: 24 behavior and integration assertions passed');
}
run().catch(error=>{console.error(error);process.exitCode=1});
