import test from 'node:test';
import assert from 'node:assert/strict';
import { readGatewayConfig,pilotReply,startGateway,signBridgeBody,makeBridgeClient } from '../server.mjs';
import { createHmac } from 'node:crypto';

test('gateway refuses missing keys and invalid Engine IDs',()=>{
  assert.throws(()=>readGatewayConfig({}),/ElevenLabs provider key/);
  assert.throws(()=>readGatewayConfig({ELEVENLABS_API_KEY:'existing-valid-key',SF_SPEECH_ENGINE_ID:'wrong'}),/SF_SPEECH_ENGINE_ID/);
  assert.throws(()=>readGatewayConfig({ELEVENLABS_API_KEY:'existing-valid-key',SF_SPEECH_ENGINE_ID:'seng_abc12345',SF_SPEECH_GATEWAY_PORT:'1'}),/port/);
});

test('gateway keeps the credential server-only',()=>{
  const config=readGatewayConfig({ELEVENLABS_API_KEY:'existing-server-key',SF_SPEECH_ENGINE_ID:'seng_abc12345',SF_SPEECH_GATEWAY_PORT:'3101'});
  assert.equal(config.port,3101);
  assert.equal(config.engineId,'seng_abc12345');
  assert.match(pilotReply(),/Agent access is not enabled/);
  assert.equal(JSON.stringify({engineId:config.engineId,port:config.port}).includes(config.apiKey),false);
});

test('gateway health is safe and account bridge stays disabled',async()=>{
  let callbacks,spoken;
  const fakeSDK=class {constructor(config){assert.equal(config.apiKey,'existing-server-key');this.speechEngine={attach:async(id,server,path,handler)=>{
    assert.equal(id,'seng_abc12345');assert.equal(path,'/ws');callbacks=handler;return {close:async()=>{}};
  }};}};
  const instance=await startGateway({apiKey:'existing-server-key',engineId:'seng_abc12345',port:0},fakeSDK);
  try{
    const port=instance.server.address().port;
    const response=await fetch('http://127.0.0.1:'+port+'/health');
    assert.equal(response.status,200);
    assert.deepEqual(await response.json(),{ok:true,service:'stonefellow-speech-gateway',account_bridge:'disabled'});
    assert.equal((await fetch('http://127.0.0.1:'+port+'/private')).status,404);
    assert.equal(callbacks.debug,false);assert.equal('disableAuth' in callbacks,false);
    await callbacks.onTranscript([{role:'user',content:'Private catalog request'}],{aborted:false},{sendResponse(value){spoken=value;}});
    assert.equal(spoken,pilotReply());
    assert.equal(spoken.includes('Private catalog request'),false);
    spoken='';await callbacks.onTranscript([{role:'user',content:'secret'}],{aborted:true},{sendResponse(value){spoken=value;}});
    assert.equal(spoken,'');
  }finally{await instance.close();}
});

test('gateway signs requests with a fresh nonce, timestamp, and shared secret',async()=>{
  const secret='a secure thirty two character secret value';
  const body={action:'register',conversation_id:'conv_abc123456789'};
  const signed=signBridgeBody(body,secret,1772222222,'0123456789abcdef0123456789abcdef');
  const expect=createHmac('sha256',secret+'|0123456789abcdef0123456789abcdef').update('1772222222.'+signed.raw).digest('hex');
  assert.equal(signed.headers['X-SF-Speech-Signature'],expect);
  assert.equal(JSON.parse(signed.raw).conversation_id,'conv_abc123456789');
  let called=false;
  const bridge=makeBridgeClient({bridgeUrl:'https://stonefellow.example/api/speech-engine-bridge.php',bridgeSecret:secret},async(url,options)=>{
    called=true;assert.match(url,/speech-engine-bridge/);assert.equal(options.method,'POST');assert.ok(options.headers['X-SF-Speech-Nonce']);
    return {ok:true,json:async()=>({ok:true,registered:true})};
  });
  assert.equal((await bridge(body)).registered,true);assert.equal(called,true);
});

test('gateway routes authorized voice transcripts only through injected signed bridge',async()=>{
  let callbacks,spoken='',requests=[];
  const fakeSDK=class {constructor(){this.speechEngine={attach:async(id,server,path,handler)=>{callbacks=handler;return {close:async()=>{}};}};}};
  const bridge=async body=>{requests.push(body);if(body.action==='turn')return {ok:true,text:'An authorized answer'};return {ok:true};};
  const gateway=await startGateway({apiKey:'existing-server-key',engineId:'seng_abc12345',port:0},fakeSDK,bridge);
  try{
    const health=await (await fetch('http://127.0.0.1:'+gateway.server.address().port+'/health')).json();
    assert.equal(health.account_bridge,'signed');
    callbacks.onInit('conv_abc123456789');
    await callbacks.onTranscript([{role:'user',content:'A private question'}],{aborted:false},{conversationId:'conv_abc123456789',sendResponse:x=>{spoken=x;}});
    assert.equal(spoken,'An authorized answer');
    assert.equal(requests.find(x=>x.action==='turn').conversation_id,'conv_abc123456789');
    assert.equal(requests.find(x=>x.action==='turn').message,'A private question');
  }finally{await gateway.close();}
});
