'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const body=app.match(/recognition\.onresult=e=>\{([\s\S]*?)\};recognition\.onerror=/)?.[1];
assert.ok(body,'Recognition result handler must be available for behavioral testing');
const events=[],turns=[];
const context={
  st:{conversationOn:true},recognition:{},input:{value:'Unsent typed message'},
  showTranscript:(text,phase)=>events.push({text,phase}),
  submitVoiceTurn:spoken=>{turns.push(spoken);context.setBusy(true)}
};
vm.createContext(context);
vm.runInContext('let voiceTurnBusy=false;recognition.onresult=e=>{'+body+'};globalThis.setBusy=v=>{voiceTurnBusy=v}',context);

context.recognition.onresult({results:[{0:{transcript:'hello stone'},isFinal:false}]});
assert.deepEqual(events.at(-1),{text:'You: hello stone',phase:'listening'});
assert.equal(context.input.value,'Unsent typed message','Interim voice must not overwrite a typed draft');
assert.equal(turns.length,0,'Interim recognition must not submit a turn');

context.recognition.onresult({results:[{0:{transcript:'hello Stonefellow'},isFinal:true}]});
assert.deepEqual(turns,['hello Stonefellow'],'Final recognition submits exactly once');
assert.equal(context.input.value,'Unsent typed message','Final voice must preserve the typed draft');
assert.deepEqual(events.at(-1),{text:'You: hello Stonefellow',phase:'heard'});
context.recognition.onresult({results:[{0:{transcript:'hello Stonefellow'},isFinal:true}]});
assert.equal(turns.length,1,'Repeated final event cannot duplicate the voice turn');

context.setBusy(false);
context.recognition.onresult({results:[{0:{transcript:'Can you'},isFinal:true},{0:{transcript:'play music'},isFinal:false}]});
assert.equal(turns.at(-1),'Can you play music','Mixed final/interim frames preserve all recognized words');
console.log('Section 90A voice transcript: 9/9 behavioral checks passed');
