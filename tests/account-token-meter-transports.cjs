'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const apiStart=source.indexOf('  async function api('),api=source.slice(apiStart,source.indexOf('\n',apiStart));
const stream=source.slice(source.indexOf('  async function readSceneResponseStream('),source.indexOf('  function applyQueue('));
const scope={Response,ReadableStream,TextDecoder,AbortController,st:{auth:{csrf:'fixture'}},cfg:{apiBase:'api'},loadAuth:async()=>{},notifications:0,
 window:{SFTokenMeter:{notifyUsage(){scope.notifications++;}}},fetch:async()=>scope.response};
vm.createContext(scope);vm.runInContext(api+'\n'+stream,scope);
const json=(body,status=200)=>new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}});
(async()=>{
 scope.response=json({ok:true,meter:{}});await scope.api('token-meter.php');assert.equal(scope.notifications,0,'meter read must not recursively invalidate itself');
 scope.response=json({ok:true,models:[]});await scope.api('agent-scene.php?models=1');assert.equal(scope.notifications,0,'model catalog read does not spend or refresh tokens');
 scope.response=json({ok:true,scene:{id:1}});await scope.api('agent-scene.php?request_id=fixture');assert.equal(scope.notifications,1,'confirmed recovered canvas refreshes meter');
 scope.response=json({ok:true});await scope.secureJsonPost('canvas-projects.php',{action:'discuss'});assert.equal(scope.notifications,2,'confirmed project/model response refreshes meter');
 scope.response=json({ok:true,generated_images:[{id:'fixture'}]});await scope.secureJsonPost('agent-scene.php',{action:'edit_image'});assert.equal(scope.notifications,3,'confirmed image response refreshes meter');
 const events=[{type:'image',state:'saved',index:1,total:1,image:{}},{type:'delta',text:'<main></main>'},{type:'result',status:200,data:{ok:true,scene:{id:2}}}];
 scope.response=new Response(events.map(value=>JSON.stringify(value)).join('\n')+'\n',{status:200,headers:{'Content-Type':'application/x-ndjson'}});
 let code='';await scope.secureJsonPost('agent-scene.php',{},false,undefined,text=>{code+=text});assert.equal(code,'<main></main>');assert.equal(scope.notifications,4,'confirmed streaming build with image event refreshes meter after final result');
 scope.response=json({ok:true,state:'canceled'});await scope.secureJsonPost('canvas-build-workflow.php',{action:'cancel'});assert.equal(scope.notifications,5,'confirmed cancellation/release refreshes meter');
 scope.response=json({ok:false,error:'model_failed'},503);await assert.rejects(()=>scope.secureJsonPost('agent-scene.php',{}));assert.equal(scope.notifications,6,'confirmed failure can include settled partial usage and refreshes balance');
 console.log('PASS 8 real app transport token refresh contracts');
})().catch(error=>{console.error(error);process.exitCode=1;});
