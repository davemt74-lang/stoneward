'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const read=name=>fs.readFileSync(path.join(__dirname,'..',name),'utf8');
let passed=0;
const check=(name,run)=>{run();passed++;process.stdout.write('PASS '+name+'\n')};

let now=1000;
const browser={};
vm.runInNewContext(read('assets/js/conversation-barge-in.js'),{window:browser,Date:{now:()=>now},Float32Array,AbortController});
const guard=browser.StonefellowBargeIn.createSpeechGuard({now:()=>now,cooldownMs:1100});
const first=guard.begin('We are playing Earth Girl.');
check('Speech preparation blocks ordinary recognition',()=>assert.equal(guard.blocked,true));
check('Preparation is not misreported as audible speech',()=>assert.equal(guard.phase,'preparing'));
check('Agent output start creates a speaking state',()=>{assert.equal(guard.audible(first),true);assert.equal(guard.phase,'speaking')});
check('Live speech blocks normal recognizer',()=>assert.equal(guard.remaining(),Infinity));
check('Speech completion starts an echo-tail cooldown',()=>{guard.finish(first);assert.equal(guard.remaining(),1100)});
now+=650;
check('Echo tail cannot become a new Agent turn',()=>{assert.equal(guard.blocked,true);assert.equal(guard.remaining(),450)});
now+=451;
check('Normal listening resumes after echo cooldown',()=>{assert.equal(guard.blocked,false);assert.equal(guard.phase,'idle')});
const old=guard.begin('Old speech');const current=guard.begin('Current speech');
check('Late prior speech callbacks cannot finish current reply',()=>{assert.equal(guard.finish(old),false);assert.equal(guard.phase,'preparing')});
guard.audible(current);guard.finish(current);
check('Finishing current response creates one protected cooldown',()=>assert.equal(guard.blocked,true));

const events={},audio={src:'',paused:true,volume:1,loadCount:0,
  addEventListener(name,fn){(events[name]??=[]).push(fn)},
  pause(){this.paused=true;(events.pause||[]).forEach(fn=>fn())},
  load(){this.loadCount++},
  async play(){if(this.rejectPlay)throw Error('autoplay denied');this.paused=false;(events.play||[]).forEach(fn=>fn())}
};
const musicBrowser={};
vm.runInNewContext(read('admin/assets/agent-music-controller.js'),{window:musicBrowser,URL});
let visible=null;
const songs=[
  {id:'earth-girl',title:'Earth Girl',audio:'assets/audio/earth-girl.mp3'},
  {id:'night-drive',title:'Night Drive',audio:'/assets/audio/night-drive.mp3'},
  {id:'private-master',title:'Private Demo',private_master_audio:'storage/private/master.wav'},
  {id:'unsafe',title:'External Audio',audio:'https://external.example/bad.mp3'}
];
const player=musicBrowser.StonefellowAdminMusic.create({audio,tracks:()=>songs,baseUrl:'https://stonefellow.test/admin/index.php',onState:s=>{visible=s}});
async function run(){
  check('Admin player starts empty and hidden',()=>assert.equal(visible.has_track,false));
  assert.match(await player.handle('Stonefellow, play Earth Girl'),/Playing “Earth Girl/);
  check('Voice command selects exact Stonefellow track',()=>assert.equal(player.state().track_id,'earth-girl'));
  check('Admin player uses same-origin catalog audio only',()=>assert.equal(audio.src,'https://stonefellow.test/assets/audio/earth-girl.mp3'));
  check('Playback state is observable while voice listening',()=>assert.equal(player.state().playing,true));
  player.duck(true,true);
  check('Music ducks to 16% during Agent speech',()=>assert.equal(audio.volume,0.16));
  player.duck(true,true,true);
  check('Echo confirmation mutes music so speech is independent',()=>assert.equal(audio.volume,0));
  player.duck(false,false);
  check('User volume returns after Agent speech',()=>assert.equal(audio.volume,0.7));
  assert.match(await player.handle('volume to 35 percent'),/35 percent/);
  check('Voice can set player volume',()=>assert.equal(player.state().volume_percent,35));
  await player.handle('pause music');
  check('Voice can pause music',()=>assert.equal(player.state().playing,false));
  await player.handle('resume music');
  check('Voice can resume the selected song',()=>assert.equal(player.state().playing,true));
  await player.handle('next song');
  check('Next song selects the subsequent playable catalog track',()=>assert.equal(player.state().track_id,'night-drive'));
  await player.handle('previous track');
  check('Previous song selects the earlier track',()=>assert.equal(player.state().track_id,'earth-girl'));
  assert.match(await player.handle('what is playing'),/Earth Girl.*playing/);
  check('Voice can ask for exact current song',()=>assert.equal(player.state().track_id,'earth-girl'));
  assert.match(await player.handle('mute music'),/muted/);
  check('Voice mute is independent from Agent ducking',()=>assert.equal(audio.volume,0));
  await player.handle('unmute music');
  check('Voice unmute restores last audible volume',()=>assert.equal(audio.volume,0.35));
  const unrelated=await player.handle('create a campaign');
  check('Admin commands do not confuse unrelated Agent instructions',()=>assert.equal(unrelated,null));
  const external=await player.handle('play External Audio');
  check('Remote audio does not get a playable local source',()=>assert.equal(external.includes('could not find'),true));
  const privateMaster=await player.handle('play Private Demo');
  check('Private source masters cannot be selected as public previews',()=>assert.equal(privateMaster.includes('could not find'),true));
  audio.pause();audio.rejectPlay=true;
  assert.match(await player.handle('play Earth Girl'),/browser blocked playback/);
  check('Autoplay denial is reported instead of falsely claiming audio played',()=>assert.equal(player.state().playing,false));

  const publicApp=read('assets/js/app.js'),adminApp=read('admin/assets/admin.js');
  check('Public recognition has speech-guarded start and result',()=>assert.match(publicApp,/voiceSpeechGuard\?\.blocked/g));
  check('Admin recognition has speech-guarded start and result',()=>assert.match(adminApp,/adminSpeechGuard\?\.blocked/g));
  check('Public audio tracks output start and completion',()=>{assert.match(publicApp,/voiceSpeechGuard\?\.begin\(text\)/);assert.match(publicApp,/voiceSpeechGuard\?\.finish\(\)/)});
  check('Admin audio tracks output start and completion',()=>{assert.match(adminApp,/adminSpeechGuard\?\.begin\(text\)/);assert.match(adminApp,/adminSpeechGuard\?\.finish\(\)/)});
  check('Both sides wait for speaker echo to fade',()=>{assert.match(publicApp,/echoTailMs:320,echoDeadlineMs:930/);assert.match(adminApp,/echoTailMs:320,echoDeadlineMs:930/)});
  check('Admin voice sends music commands to local playback not to remote actions',()=>assert.match(adminApp,/await adminMusic\?\.handle\(message\)/));
  check('Admin playback is wired to a real audio element',()=>{const shell=read('admin/index.php');assert.match(shell,/id="adminAgentMusicAudio"/);assert.match(shell,/agent-music-controller\.js/)});
  check('Public Agent retains its existing real player executor behind identity validation',()=>{assert.match(publicApp,/publicAudioSkill\?\.dispatch\(verified\.action\)/);assert.match(publicApp,/await executePlayerAgentAction\(verified\.action\)/)});
  process.stdout.write('Section 91B4 voice/music: '+passed+' behavior and integration checks passed\n');
}
run().catch(err=>{console.error(err);process.exit(1)});
