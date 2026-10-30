import test from 'node:test';
import assert from 'node:assert/strict';
import { readGatewayConfig,pilotReply,startGateway } from '../server.mjs';

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
    callbacks.onTranscript([{role:'user',content:'Private catalog request'}],{aborted:false},{sendResponse(value){spoken=value;}});
    assert.equal(spoken,pilotReply());
    assert.equal(spoken.includes('Private catalog request'),false);
    spoken='';callbacks.onTranscript([{role:'user',content:'secret'}],{aborted:true},{sendResponse(value){spoken=value;}});
    assert.equal(spoken,'');
  }finally{await instance.close();}
});
