'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const browser={};
vm.runInNewContext(read('assets/js/agent-audio-player-skill.js'),{window:browser});
const skill=browser.StonefellowAgentAudioSkill;
let checks=0;
const check=(name,fn)=>{fn();checks++;process.stdout.write('91B5H PASS '+name+'\n')};
const songs=[
  {id:'subtle-surrender',title:'Subtle Surrender',audio:'assets/subtle.mp3'},
  {id:'two-hands-up',title:'Two Hands Up',audio:'assets/hands.mp3'},
  {id:'river-night',title:'River Night',audio:'assets/river.mp3'}
];
const former={type:'play_track',track_id:'subtle-surrender'};
check('Change songs voice phrase is recognized',()=>assert.equal(skill.command('change songs two hands up').query,'two hands up'));
check('Switch to named song is recognized',()=>assert.equal(skill.command('switch to Two Hands Up').query,'two hands up'));
check('Explicit play phrase is recognized',()=>assert.equal(skill.command('play Genius 7 Humankind').query,'genius 7 humankind'));
check('Correct title overrides erroneous server action',()=>assert.equal(skill.resolvePublished('change songs two hands up',songs,former).action.track_id,'two-hands-up'));
check('Unmatched title refuses fallback to active track',()=>assert.equal(skill.resolvePublished('play genius 7 humankind',songs,former).action.type,'none'));
check('Unmatched title says playback has not changed',()=>assert.match(skill.resolvePublished('play genius 7 humankind',songs,former).text,/not changed/));
check('Unrelated command retains server action',()=>assert.equal(skill.resolvePublished('show my account',songs,former).action.track_id,'subtle-surrender'));
check('Generic music command retains server semantics',()=>assert.equal(skill.resolvePublished('play music',songs,former).action.track_id,'subtle-surrender'));
check('Current-song reference retains explicit deictic semantics',()=>assert.equal(skill.resolvePublished('play this song',songs,former).action.track_id,'subtle-surrender'));
check('Duplicate titles cannot silently select an arbitrary release',()=>assert.equal(skill.resolvePublished('play Two Hands Up',[{id:'a',title:'Two Hands Up'},{id:'b',title:'Two Hands Up'}],former).action.type,'none'));
check('Word boundaries avoid partial title hijacking',()=>assert.equal(skill.resolvePublished('play Two Hands Upgrade',songs,former).action.type,'none'));
check('Partial catalog titles cannot hijack a longer unmatched request',()=>assert.equal(skill.resolvePublished('play Genius 7 Humankind',[...songs,{id:'genius',title:'Genius'},{id:'humankind',title:'Humankind'}],former).action.type,'none'));
check('Shared validator still rejects arbitrary action',()=>assert.equal(skill.validate({type:'open_remote_audio',url:'https://example.org/test.mp3'}),null));
const app=read('assets/js/app.js'),php=read('api/agent-runtime.php');
check('Public chat consults published track identity before dispatch',()=>assert.match(app,/dispatchAgentBrowserAction\(text,j\.action,j\.audio_resolution\)/)&&assert.match(app,/verifiedNamedPlayerAction\(message,action,resolution\)/));
check('Public browser confirms real playing state and track ID',()=>assert.match(app,/started&&st\.track\?\.id===track\.id&&!audio\.paused&&!!audio\.src/));
check('Server planner requires named published track plan',()=>assert.match(php,/sf_agent_named_playback_plan\(\$requested\?\?''\,sf_agent_public_catalog\(\),\$active\)/));
check('Server explicit router handles named play before the language model',()=>assert.match(php,/if\(sf_agent_named_playback_query\(\$message\)!==null\)return 'player_play_named'/));
process.stdout.write('Section 91B5H: '+checks+' browser and command integration checks passed\n');
