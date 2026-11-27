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
async function verifyProviderAdmin(){
  const music=require('../assets/js/vp3-music-player.js'),{webcrypto}=require('node:crypto');
  const reference={kind:'vp3',installation_id:'11111111-1111-4111-8111-111111111111',site_id:'22222222-2222-4222-8222-222222222222',recording_id:'33333333-3333-4333-8333-333333333333'};
  const imported={id:'vp3-song',title:'Imported Song',audio:'',audio_source:reference};
  const owned=new EventTarget();Object.assign(owned,{src:'',currentTime:0,duration:120,paused:true,ended:false,readyState:4,playbackRate:1,volume:1,muted:false,seeking:false,error:null,blocked:true,
    getAttribute(name){return name==='src'?this.src:null;},removeAttribute(name){if(name==='src')this.src='';},
    load(){this.error=null;this.dispatchEvent(new Event('loadedmetadata'));},pause(){this.paused=true;this.dispatchEvent(new Event('pause'));},
    async play(){if(this.blocked)throw Object.assign(Error('blocked'),{name:'NotAllowedError'});this.paused=false;this.dispatchEvent(new Event('playing'));}});
  const providerCalls=[],resolverCalls=[];let allocation=null,created=0,hold=null,release;
  const browser={SFVP3Music:{...music,create(settings){created++;return music.create({...settings,crypto:webcrypto,sourceUrl:'https://stonefellow.test/admin/index.php',fetch:async(url,init)=>{
    const body=JSON.parse(init.body);providerCalls.push({url,init,body});
    if(body.action==='playback.begin')allocation??={play_id:body.play_id,session_id:'44444444-4444-4444-8444-444444444444',seq:0,played:0};
    if(body.action==='playback.events'){assert.equal(body.seq,allocation.seq+1);allocation.seq=body.seq;allocation.played=body.cumulative_played_ms;}
    const result={ok:true,protocol:music.protocol,session_id:allocation.session_id,play_id:allocation.play_id,site_id:reference.site_id,recording_id:reference.recording_id,
      asset_id:'55555555-5555-4555-8555-555555555555',identity:{provider_id:reference.installation_id},accepted_seq:allocation.seq,accepted_played_ms:allocation.played};
    if(body.action==='playback.begin')result.media={url:'https://vp3.test/api/music-provider-media-v1.php?ticket=mpd1.fixture',ticket_expires_ms:Date.now()+300000,mime:'audio/mpeg',byte_size:1024,etag:'"sha256-'+('a'.repeat(64))+'"'};
    return {ok:true,status:200,json:async()=>result};
  }});}}};
  vm.runInNewContext(read('admin/assets/agent-music-controller.js'),{window:browser,URL,fetch:async(url,init)=>{
    resolverCalls.push({url,init});if(hold)await hold;
    return {ok:true,status:200,json:async()=>({ok:true,source:{...reference,provider_api:'https://vp3.test/api/music-provider-v1.php'}})};
  }});
  const admin=browser.StonefellowAdminMusic.create({audio:owned,tracks:()=>[imported,{id:'local',title:'Local Song',audio:'assets/local.mp3'},
    {id:'broken',title:'Broken Provider',audio:'assets/private.mp3',audio_publishing_mode:'vp3_stream_only'}],baseUrl:'https://stonefellow.test/admin/index.php'});
  assert.match(await admin.handle('play Imported Song'),/browser blocked playback/);
  check('Admin imported music allocates one pending provider play without claiming listening',()=>{
    assert.equal(providerCalls.filter(call=>call.body.action==='playback.begin').length,1);assert.equal(providerCalls.filter(call=>call.body.action==='playback.events').length,0);
  });
  owned.blocked=false;assert.match(await admin.resume(),/Playing/);await new Promise(resolve=>setImmediate(resolve));
  check('Admin Tap Play reuses the pending provider grant and emits an actual zero-time resume',()=>{
    assert.equal(created,1);assert.equal(providerCalls.filter(call=>call.body.action==='playback.begin').length,1);
    assert.equal(providerCalls.find(call=>call.body.action==='playback.events').body.cumulative_played_ms,0);
  });
  check('Admin resolves a public local track ID and keeps provider requests anonymous',()=>{
    assert.equal(resolverCalls[0].url,'https://stonefellow.test/api/vp3-music-source.php?track_id=vp3-song');assert.equal(resolverCalls[0].init.credentials,'same-origin');
    for(const call of providerCalls){assert.equal(call.init.credentials,'omit');assert.equal(Object.keys(call.body).some(key=>/user|customer|email|account|cookie/.test(key)),false);}
    assert.equal(providerCalls[0].body.source_url,'');
  });
  admin.pause();assert.equal(owned.paused,true);await admin.resume();await new Promise(resolve=>setImmediate(resolve));
  check('Admin pause and resume retain the same provider play',()=>assert.equal(providerCalls.filter(call=>call.body.action==='playback.begin').length,1));
  await admin.handle('play Local Song');
  check('Admin can switch from typed provider to its original same-origin preview transport',()=>assert.equal(owned.src,'https://stonefellow.test/assets/local.mp3'));
  check('Malformed Admin provider rows cannot fall back to local audio',()=>assert.equal(music.hasPlayableSource({audio:'assets/private.mp3',audio_publishing_mode:'vp3_stream_only'}),false));
  assert.match(await admin.handle('play Broken Provider'),/could not find/);
  hold=new Promise(resolve=>{release=resolve;});const pending=admin.play(imported);await Promise.resolve();admin.pause();release();
  assert.match(await pending,/canceled/);await new Promise(resolve=>setImmediate(resolve));
  check('Admin pause cancels a late source resolution before another provider begin',()=>{
    assert.equal(owned.paused,true);assert.equal(providerCalls.filter(call=>call.body.action==='playback.begin').length,1);
  });
  admin.stop();
}
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
  await verifyProviderAdmin();

  const publicApp=read('assets/js/app.js'),adminApp=read('admin/assets/admin.js');
  check('Public recognition has speech-guarded start and result',()=>assert.match(publicApp,/voiceSpeechGuard\?\.blocked/g));
  check('Admin recognition has speech-guarded start and result',()=>assert.match(adminApp,/adminSpeechGuard\?\.blocked/g));
  check('Public audio tracks output start and completion',()=>{assert.match(publicApp,/voiceSpeechGuard\?\.begin\(text\)/);assert.match(publicApp,/voiceSpeechGuard\?\.finish\(\)/)});
  check('Admin audio tracks output start and completion',()=>{assert.match(adminApp,/adminSpeechGuard\?\.begin\(text\)/);assert.match(adminApp,/adminSpeechGuard\?\.finish\(\)/)});
  check('Public speech guard and Admin monitor retain their echo protection',()=>{assert.match(publicApp,/createSpeechGuard\(\{cooldownMs:1100\}\)/);assert.match(adminApp,/echoTailMs:320,echoDeadlineMs:930/)});
  check('Admin voice sends music commands to local playback not to remote actions',()=>assert.match(adminApp,/await adminMusic\?\.handle\(message\)/));
  check('Admin playback is wired to a real audio element',()=>{const shell=read('admin/index.php');assert.match(shell,/id="adminAgentMusicAudio"/);assert.match(shell,/agent-music-controller\.js/)});
  check('Public Agent retains its existing real player executor behind identity validation',()=>{assert.match(publicApp,/publicAudioSkill\?\.dispatch\(verified\.action\)/);assert.match(publicApp,/await executePlayerAgentAction\(verified\.action\)/)});
  process.stdout.write('Section 91B4 voice/music: '+passed+' behavior and integration checks passed\n');
}
run().catch(err=>{console.error(err);process.exit(1)});
