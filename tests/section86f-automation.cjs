'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const src=fs.readFileSync(path.join(__dirname,'../admin/assets/stem-automation.js'),'utf8');
const window={};
vm.runInNewContext(src,{window,console});
const a=window.SFStemAutomation;
const linear=[{time:0,value:1,shape:'linear'},{time:10,value:0,shape:'linear'}];
assert.equal(a.value(linear,5),.5);
assert.equal(a.value(linear,99),0);
assert.equal(a.value([{time:0,value:1,shape:'square'},{time:5,value:0,shape:'square'}],4),1);
const project={automation:[{scope:'track',guid:'t',parameter:'pan',points:[{time:0,value:-.5,shape:'linear'}],source:'REAPER'},
{scope:'track',guid:'t',parameter:'pan',points:[{time:0,value:.7,shape:'square'}],source:'BROWSER'}]};
assert.equal(a.snapshot(project,1).get('track|t|pan||'),.7);
const events=[];
const param={setTargetAtTime(x){events.push(x)}};
const nodes={gain:{gain:param},pan:{pan:param},fx:{update(){}}};
const studio={project:{...project,automation:[],master_fx:[]},context:{currentTime:1},
 audio:new Map([['t',{...nodes,track:{fx:[]}}]]),busNodes:new Map(),master:{gain:param},
 mixing:{t:{volume:.65,pan:0,mute:false,solo:false}},busMix:{}};
a.effective(studio,2);
assert.ok(events.includes(.65)&&events.includes(.82));
console.log('86F browser automation interpolation, override, playback nodes: 6 assertions green');
