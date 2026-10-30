'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.join(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8'),window={};
vm.runInNewContext(read('assets/js/agent-audio-player-skill.js'),{window});
const skill=window.StonefellowAgentAudioSkill;
let checks=0;const check=(name,fn)=>{fn();checks++;process.stdout.write('91B7 PASS '+name+'\n')};
const tracks=[
 {id:'hands',title:'Two Hands Up',release:'Happier Than Hay',audio:'/public/hands.mp3'},
 {id:'genius',title:'Genius of Unhumankind',release:'Happier Than Hay',audio:'/public/genius.mp3'},
 {id:'other',title:'Other Song',release:'Other Album',audio:'/public/other.mp3'}
];
const receipt={method:'release_catalog',status:'verified',track_id:'hands',title:'Two Hands Up',release:'Happier Than Hay'};
check('Spoken album request dispatches exact server-selected published track',()=>assert.equal(skill.resolvePublished('Play something from Happier Than Hey',tracks,{type:'play_track',track_id:'hands'},receipt).action.track_id,'hands'));
check('Choose any song from release dispatches selection',()=>assert.equal(skill.resolvePublished('Choose any song from Happier Than Hey and play it',tracks,{type:'play_track',track_id:'hands'},receipt).action.track_id,'hands'));
check('Wrong server ID blocked',()=>assert.equal(skill.resolvePublished('Play something from Happier Than Hey',tracks,{type:'play_track',track_id:'other'},receipt).action.type,'none'));
check('Wrong release title blocked',()=>assert.equal(skill.resolvePublished('Choose any song from that album',tracks,{type:'play_track',track_id:'hands'},{...receipt,release:'Other Album'}).action.type,'none'));
check('Unpublished or unavailable audio blocked',()=>assert.equal(skill.resolvePublished('Choose any song from that album',tracks.map(t=>({...t,audio:''})),{type:'play_track',track_id:'hands'},receipt).action.type,'none'));
check('Missing track blocked',()=>assert.equal(skill.resolvePublished('Play something from Happier Than Hey',tracks.slice(1),{type:'play_track',track_id:'hands'},receipt).action.type,'none'));
const app=read('assets/js/app.js'),api=read('api/agent-runtime.php'),ops=read('api/operations.php');
check('Browser sends IANA time zone on chat requests',()=>assert.match(app,/timezone:Intl\.DateTimeFormat\(\)\.resolvedOptions\(\)\.timeZone/));
check('Browser sends active release for conversational references',()=>assert.match(app,/active_release_title:st\.track\?\.release/));
check('Song play recorded after successful audio play promise',()=>assert.match(app,/audio\.play\(\)\.then\(\(\)=>\{recordListen\(startAt>0\?'resume':'start'\)/));
check('Server persists UTC playback timestamps',()=>assert.match(ops,/source,created_at\) VALUES\(\?,\?,\?,\?,\?,\?,\?,\?\)/));
check('Server provides verified album playback receipt',()=>assert.match(api,/method'=>'release_catalog','status'=>'verified'/));
check('Server injects listening context into relevant LLM conversation',()=>assert.match(api,/sf_agent_listening_context\(\$timeInsights\)/));
process.stdout.write('Section 91B7: '+checks+' browser receipt and history assertions passed\n');
