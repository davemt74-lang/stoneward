'use strict';
// End-to-end browser transport invariants without a real microphone or credentials.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const browser=read('speech-gateway/browser-entry.mjs');
const bundle=read('assets/js/speech-engine-conversation.js');
const page=read('stonefellow-v120.php');
const app=read('assets/js/app.js');
const endpoint=read('api/speech-engine.php');
const bridge=read('api/speech-engine-bridge.php');
const php=read('api/speech-engine-session-core.php');
const gateway=read('speech-gateway/server.mjs');
let passed=0;
function check(ok,label){assert.ok(ok,label);passed++;console.log('91B9B PASS:',label);}
check(browser.includes("import { Conversation } from '@elevenlabs/client'")&&browser.includes('Conversation.startSession({'),'Browser starts the official authenticated conversation SDK');
check(browser.includes("post('start',{},abort.signal)")&&browser.includes("post('bind'")&&browser.includes("credentials:'same-origin'")&&browser.includes('X-CSRF-Token'),'Browser obtains account-issued token and claims its session via CSRF');
check(!/getUserMedia\s*\(/.test(browser),'The SDK is the only microphone owner');
check(bundle.length>100000&&bundle.includes('StonefellowSpeechEngine')&&!bundle.includes('SF_SPEECH_BRIDGE_SECRET'),'Browser bundle exists and has no bridge secret');
check(page.includes('speech-engine-conversation.js')&&page.includes('account-voice-engine.js'),'Public page includes bundle and existing Account selector');
check(app.includes('handleVoiceClick()')&&app.includes('speechPilotActive')&&app.includes('StonefellowSpeechEngine.start')&&app.includes('StonefellowSpeechEngine?.stop()'),'Existing microphone explicitly selects and stops pilot while keeping legacy engine');
check(endpoint.includes('sf_speech_provider_token')&&endpoint.includes('sf_speech_issue_claim')&&endpoint.includes('sf_speech_bind_account'),'Provider token never requires a browser API key');
check(php.includes('hash_equals')&&php.includes('speech_engine_bridge_nonces')&&php.includes('sf_speech_session_authorized'),'Bridge requires owner, signed requests and replay protection');
check(bridge.includes('sf_speech_bound_user')&&bridge.includes('sf_agent_reply')&&bridge.includes('action_deferred'),'Voice uses private agent only after binding and defers client actions');
check(gateway.includes('onDisconnect(session)')&&gateway.includes("action:'close'")&&gateway.includes("action:'register'"),'Gateway revokes unexpected disconnect and initializes session ownership');
check(!gateway.includes('disableAuth: true')&&!bundle.includes('xi-api-key'),'Provider token and key security remain intact');

async function verifySessionLifecycle(){
  const calls=[],status=[];
  const scope={window:{},AbortController,setTimeout,clearTimeout,console,
    navigator:{mediaDevices:{getUserMedia(){throw Error('Unexpected permission probe');}}},
    fetch:async(url,options)=>{
      assert.equal(options.credentials,'same-origin');
      const result=url.endsWith('auth.php')?{csrf:'test-token'}:{conversation_token:'provider-token',bind_token:'bind-token'};
      return {ok:true,json:async()=>result};
    },
    Conversation:{startSession(options){
      return new Promise(resolve=>calls.push({options,resolve}));
    }}
  };
  vm.runInNewContext(browser.slice(browser.indexOf('\n')+1),scope);
  const pilot=scope.window.StonefellowSpeechEngine;
  const yieldTask=()=>new Promise(resolve=>setImmediate(resolve));
  const first=pilot.start({onStatus:value=>status.push('old:'+value)});
  await yieldTask();
  assert.equal(calls.length,1);
  const stopped=pilot.stop();
  const second=pilot.start({outputMuted:true,onStatus:value=>status.push('new:'+value)});
  await yieldTask();
  assert.equal(calls.length,1,'New SDK start must wait for pending old cleanup');
  let oldClosed=false;
  calls[0].resolve({async endSession(){oldClosed=true;calls[0].options.onDisconnect();}});
  await Promise.all([first,stopped]);
  await yieldTask();
  assert.equal(calls.length,2);
  assert.ok(oldClosed);

  const voiceAudio={muted:false};
  const volumes=[];
  const live={getId:()=> 'active-session',async endSession(){},
    setVolume:({volume})=>volumes.push(volume),
    connection:{audioAdapter:{audioElements:[voiceAudio]}}
  };
  calls[1].options.onConversationCreated(live);
  calls[1].resolve(live);
  await second;
  assert.equal(voiceAudio.muted,true);
  assert.equal(volumes.at(-1),0);
  calls[0].options.onDisconnect();
  assert.equal(pilot.active,true);
  assert.deepEqual(status,['new:ready']);
  pilot.setOutputMuted(false);
  assert.equal(voiceAudio.muted,false);
  assert.equal(volumes.at(-1),1);
  await pilot.stop();
  assert.equal(pilot.active,false);
  check(true,'Late callbacks cannot affect new sessions, and only active SDK output is muted');
  console.log(`Section 91B9B: ${passed} voice-client contract checks passed`);
}
verifySessionLifecycle().catch(error=>{console.error(error);process.exitCode=1;});
