'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=name=>fs.readFileSync(path.join(root,name),'utf8');
let checks=0;
const check=(label,fn)=>{fn();checks++;process.stdout.write('91B5 PASS '+label+'\n')};
const app={};vm.runInNewContext(read('assets/js/agent-audio-player-skill.js'),{window:app});
const skill=app.StonefellowAgentAudioSkill;
const parsed=text=>JSON.parse(JSON.stringify(skill.command(text)));
async function main(){
  check('Shared command contract has seven named transport capabilities',()=>assert.equal(skill.capabilities.length,7));
  check('Unrelated Agent conversation is not treated as a music command',()=>assert.equal(skill.command('Tell me about album artwork'),null));
  check('Wake phrase and song name resolve to local play intent',()=>assert.deepEqual(parsed('Hey Stonefellow, play Earth Girl'),{type:'play_track',query:'earth girl'}));
  check('Direct music play resolves to the current catalog',()=>assert.deepEqual(parsed('Play music'),{type:'play_track',query:''}));
  check('Pause voice command resolves',()=>assert.equal(parsed('pause the music').type,'pause_player'));
  check('Natural resume voice command resolves',()=>assert.equal(parsed('Resume playing the music').type,'resume_player'));
  check('Standard resume phrase resolves',()=>assert.equal(parsed('Resume music').type,'resume_player'));
  check('Next song command resolves',()=>assert.equal(parsed('Next song').type,'next_track'));
  check('Previous song command resolves',()=>assert.equal(parsed('Previous track').type,'previous_track'));
  check('Now-playing command resolves',()=>assert.equal(parsed("What's playing?").type,'player_status'));
  check('Volume percent resolves',()=>assert.deepEqual(parsed('Volume to 35 percent'),{type:'set_player_volume',mode:'set',percent:35}));
  check('Mute resolves',()=>assert.equal(parsed('Mute music').mode,'mute'));
  check('Unmute resolves',()=>assert.equal(parsed('Unmute music').mode,'unmute'));
  check('Volume decrease resolves',()=>assert.equal(parsed('Volume down').percent,-10));
  check('Volume 130 clamps to 100',()=>assert.equal(parsed('Volume to 130 percent').percent,100));
  check('Only allowlisted action types can dispatch',()=>assert.equal(skill.validate({type:'delete_song',track_id:'1'}),null));
  check('Illegal volume mode refused',()=>assert.equal(skill.validate({type:'set_player_volume',mode:'delete'}),null));
  check('Malformed percent refused',()=>assert.equal(skill.validate({type:'set_player_volume',mode:'set',percent:NaN}),null));
  check('Oversized song query refused',()=>assert.equal(skill.validate({type:'play_track',query:'x'.repeat(170)}),null));
  check('No server URL or arbitrary fields are passed to playback',()=>assert.deepEqual(JSON.parse(JSON.stringify(skill.validate({type:'play_track',track_id:'song-1',endpoint:'https://evil.invalid'}))),{type:'play_track',track_id:'song-1'}));
  let calls=0,current={track_id:'',title:'',playing:false,volume_percent:70};
  const browser=skill.create({invoke:async action=>{calls++;if(action.type==='play_track'){current={...current,track_id:'song-1',title:'Earth Girl',playing:true};return {ok:true,text:'Playing “Earth Girl.”'}}if(action.type==='pause_player'){current.playing=false;return {ok:true,text:'Paused “Earth Girl.”'}}return 'The browser blocked playback.'},getState:()=>current});
  const first=await browser.handle('Play Earth Girl');
  check('Shared executor sends typed play action',()=>assert.equal(calls,1));
  check('Shared executor reports confirmed structured playback success',()=>assert.equal(first.ok,true));
  check('Shared executor returns sanitized now-playing state',()=>assert.equal(first.state.title,'Earth Girl'));
  const pause=await browser.handle('Pause music');
  check('Shared executor reports the resulting paused state',()=>assert.equal(pause.state.playing,false));
  const denied=await browser.dispatch({type:'resume_player'});
  check('Legacy adapter speech is reported without inventing successful playback',()=>assert.equal(denied.ok,null));
  check('Legacy adapter preserves actual autoplay error message',()=>assert.match(denied.text,/browser blocked/));
  const unknownText=await browser.handle('Delete this project');
  const unknownAction=await browser.dispatch({type:'publish_track'});
  check('Unknown commands do not invoke the adapter',()=>assert.equal(unknownText,null));
  check('Unknown structured actions do not invoke the adapter',()=>assert.equal(unknownAction,null));
  check('Rejected commands make no extra adapter calls',()=>assert.equal(calls,3));
  const broken=skill.create({invoke:async()=>{throw Error('private auth token should not leak')}});
  const rejected=await broken.dispatch({type:'pause_player'});
  check('Adapter failure does not expose private error details',()=>assert.doesNotMatch(rejected.text,/private auth token/));
  check('Adapter failure is explicitly unsuccessful',()=>assert.equal(rejected.ok,false));

  // Same shared script is loaded before both public and Admin controllers.
  const publicShell=read('stonefellow-v120.php'),adminShell=read('admin/index.php');
  check('Public page loads the shared skill before app.js',()=>assert.ok(publicShell.indexOf('agent-audio-player-skill.js')<publicShell.indexOf('assets/js/app.js')));
  check('Admin loads the shared skill before its player adapter',()=>assert.ok(adminShell.indexOf('agent-audio-player-skill.js')<adminShell.indexOf('agent-music-controller.js')));
  const publicApp=read('assets/js/app.js'),adminPlayer=read('admin/assets/agent-music-controller.js');
  check('Public authenticated agent dispatches returned action through shared skill',()=>assert.match(publicApp,/publicAudioSkill\?\.dispatch\(j\.action\)/));
  check('Public retains real player result fallback',()=>assert.match(publicApp,/sharedPlayerResult\?\.text\?\?await executePlayerAgentAction\(j\.action\)/));
  check('Admin adapter routes normal music phrases through shared skill',()=>assert.match(adminPlayer,/sharedSkill\?\.handle\(message\)/));
  check('Admin adapter implements every canonical action',()=>{for(const action of skill.capabilities)assert.ok(adminPlayer.includes("case '"+action+"'"),action)});
  check('Admin source guard rejects remote, private and unplayable track URLs',()=>{assert.match(adminPlayer,/reference\.origin===new URL\(baseUrl\)\.origin/);assert.match(adminPlayer,/track\?\.audio/)});

  // Real mocked audio element confirms public-safe local playback and errors.
  const events={};const audio={paused:true,src:'',volume:0.7,blocked:false,
    addEventListener:(event,callback)=>{(events[event]??=[]).push(callback)},
    pause(){this.paused=true;for(const cb of events.pause||[])cb()},load(){},
    async play(){if(this.blocked)throw Error('autoplay');this.paused=false;for(const cb of events.play||[])cb()}
  };
  const songs=[{id:'earth',title:'Earth Girl',audio:'assets/audio/earth.mp3'},
    {id:'night',title:'Night Drive',audio:'/assets/audio/night.mp3'},
    {id:'remote',title:'Remote',audio:'https://outside.invalid/media.mp3'},
    {id:'master',title:'Master',private_master_audio:'storage/private/master.wav'}];
  const admin={StonefellowAgentAudioSkill:skill};
  vm.runInNewContext(adminPlayer,{window:admin,URL});
  const player=admin.StonefellowAdminMusic.create({audio,tracks:()=>songs,baseUrl:'https://stonefellow.test/admin/index.php'});
  const playResult=await player.handle('Play Earth Girl');
  check('Admin plays the same-site catalog preview',()=>assert.match(playResult,/Playing/));
  const remoteResult=await player.handle('Play Remote');
  const masterResult=await player.handle('Play Master');
  const statusResult=await player.handle("What's playing?");
  check('Admin player never resolves an external URL',()=>assert.match(remoteResult,/could not find/i));
  check('Admin player never plays a private master without a preview',()=>assert.match(masterResult,/could not find/i));
  check('Admin status reports actual player state',()=>assert.match(statusResult,/Earth Girl/));
  check('Admin voice volume produces actual volume',()=>{return assert.ok(player.setVolume(35)===35)});
  check('Admin audio element reflects requested volume',()=>assert.equal(audio.volume,0.35));
  const next=await player.step(1);
  check('Admin skips to a second playable catalog song',()=>assert.match(next,/Night Drive/));
  audio.blocked=true;
  const blocked=await player.handle('Play Earth Girl');
  check('Admin reports browser-autoplay rejection instead of claiming playback',()=>assert.match(blocked,/browser blocked playback/));
}
main().then(()=>process.stdout.write('91B5: '+checks+' audio skill assertions passed\n')).catch(error=>{console.error(error);process.exitCode=1});
