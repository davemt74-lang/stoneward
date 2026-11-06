'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const src=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
let checks=0;
function check(label,fn){fn();checks++;console.log('91B13F PASS '+label);}
const begin=src.indexOf('  function syncConversationMusic(){');
const end=src.indexOf('  function playerState(){',begin);
assert.ok(begin>0&&end>begin,'volume controller source must exist');
const context={
  audio:{paused:false,volume:0.82},musicOriginalVolume:null,
  voiceInputActive:false,voiceSpeechAudible:false,speechPilotAgentSpeaking:false,
  voicePlaybackPending:false,voiceSpeechReleaseTimer:null,speechPilotReleaseTimer:null,speechPilotActive:false,
  st:{conversationOn:true},lastAudibleVolume:0.82,
  setTimeout:(fn)=>{context.scheduled=fn;return 1},clearTimeout:()=>{context.scheduled=null}
};
vm.runInNewContext(src.slice(begin,end),context);
check('Simply arming the microphone leaves player volume untouched',()=>{
  context.syncConversationMusic();
  assert.equal(context.audio.volume,0.82);
  assert.equal(context.musicOriginalVolume,null);
});
check('Web Speech detection/transcription ducks while active, restores on silence',()=>{
  context.setVoiceInputActive(true);
  assert.equal(context.audio.volume,0.4);
  assert.equal(context.playerVolume(),0.82);
  context.setVoiceInputActive(false);
  assert.equal(context.audio.volume,0.82);
  assert.equal(context.musicOriginalVolume,null);
});
check('Preparing remote speech does not duck before the Agent is audible',()=>{
  context.voicePlaybackPending=true;
  context.syncConversationMusic();
  assert.equal(context.audio.volume,0.82);
  context.setVoiceSpeechAudible(true);
  assert.equal(context.audio.volume,0.16);
  context.setVoiceSpeechAudible(false);
  assert.equal(context.audio.volume,0.16,'Adjacent TTS chunks retain one duck level');
  context.setVoiceSpeechAudible(true);
  assert.equal(context.scheduled,null,'Next spoken chunk cancels pending restore');
  context.voicePlaybackPending=false;
  context.setVoiceSpeechAudible(false);
  assert.equal(context.audio.volume,0.82);
});
check('Speech Engine only ducks for speaking mode, not idle listening mode',()=>{
  context.speechPilotAgentSpeaking=true;
  context.syncConversationMusic();
  assert.equal(context.audio.volume,0.16);
  context.speechPilotAgentSpeaking=false;
  context.syncConversationMusic();
  assert.equal(context.audio.volume,0.82);
});
check('Stopping Speech Engine audio restores music immediately',()=>{
  context.speechPilotActive=true;
  context.setSpeechPilotSpeaking(true);
  assert.equal(context.audio.volume,0.16);
  context.setSpeechPilotSpeaking(false);
  assert.equal(typeof context.scheduled,'function');
  context.setSpeechPilotSpeaking(false,true);
  assert.equal(context.scheduled,null);
  assert.equal(context.audio.volume,0.82);
  context.speechPilotActive=false;
});
check('Manual volume changes while ducked restore to the new requested level',()=>{
  context.setVoiceInputActive(true);
  assert.equal(context.setPlayerVolume(0.68),68);
  assert.equal(context.audio.volume,0.4);
  assert.equal(context.playerVolume(),0.68);
  context.setVoiceInputActive(false);
  assert.equal(context.audio.volume,0.68);
});
check('Natural song end restores the selected volume even if paused is still false',()=>{
  context.setVoiceInputActive(true);
  assert.equal(context.audio.volume,0.4);
  context.audio.ended=true;
  context.syncConversationMusic();
  assert.equal(context.audio.volume,0.68);
  context.setVoiceInputActive(false);
});
check('An ignored native setter only uses mute when explicitly requested',()=>{
  context.audio={paused:false,muted:false,get volume(){return 1},set volume(value){}};
  context.setVoiceInputActive(true);
  assert.equal(context.playerVolume(),1);
  assert.equal(context.musicOriginalVolume,null);
  assert.equal(context.audio.muted,false);
  assert.equal(context.setPlayerVolume(0.25),null);
  assert.equal(context.playerVolume(),1);
  assert.equal(context.setPlayerVolume(0),0);
  assert.equal(context.audio.muted,true);
  assert.equal(context.playerVolume(),0);
  assert.equal(context.lastAudibleVolume,1);
  assert.equal(context.setPlayerVolume(0.25),null);
  context.setVoiceInputActive(false);
  assert.equal(context.audio.muted,true);
  assert.equal(context.setPlayerVolume(context.lastAudibleVolume),100);
  assert.equal(context.audio.muted,false);
});
check('Detection, interim transcript, end, errors and stop all have activity hooks',()=>{
  assert.match(src,/recognition\.onspeechstart=\(\)=>\{if\(shouldDuckConversationInput\(\)\)/);
  assert.match(src,/recognition\.onspeechend=\(\)=>setVoiceInputActive\(false\)/);
  assert.match(src,/if\(spoken&&!finalReceived\)setVoiceInputActive\(shouldDuckConversationInput\(spoken\)\)/);
  assert.match(src,/if\(finalReceived\)setVoiceInputActive\(false\)/);
  assert.match(src,/recognition\.onend=\(\)=>\{[^\n]*recognitionActive=false;setVoiceInputActive\(false\)/);
  assert.match(src,/recognition\.onerror=e=>\{[\s\S]{0,200}?recognitionActive=false;setVoiceInputActive\(false\)/);
  assert.match(src,/setSpeechPilotSpeaking\((?:audible|speaking)\)/);
  assert.match(src,/StonefellowSpeechEngine\?\.setInputMuted\?\.\(/);
  assert.match(src,/utterance\.onstart=\(\)=>\{started=true;[^\n]*setVoiceSpeechAudible\(true\)/);
  assert.match(src,/a\.play\(\)\.then\(\(\)=>\{[^\n]*setVoiceSpeechAudible\(true\)/);
  assert.doesNotMatch(src,/if\(\(st\.conversationOn\|\|voicePlaybackPending\)&&!audio\.paused\)/);
  new vm.Script(src);
});
console.log('Section 91B13F: '+checks+' conversation volume-ducking checks passed');
