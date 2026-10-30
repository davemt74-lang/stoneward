import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';
import { createHmac, randomBytes } from 'node:crypto';

// Separate opt-in Node process. An authenticated, one-use browser claim is
// required before the signed bridge can access a Stonefellow account.
export function readGatewayConfig(env=process.env) {
  const apiKey=String(env.ELEVENLABS_API_KEY||'').trim();
  const engineId=String(env.SF_SPEECH_ENGINE_ID||'').trim();
  const port=Number(env.SF_SPEECH_GATEWAY_PORT||3001);
  const bridgeUrl=String(env.SF_SPEECH_BRIDGE_URL||'').trim();
  const bridgeSecret=String(env.SF_SPEECH_BRIDGE_SECRET||'');
  if(apiKey.length<8)throw new Error('Set the existing ElevenLabs provider key as ELEVENLABS_API_KEY on the private gateway host.');
  if(!/^seng_[A-Za-z0-9_-]{8,96}$/.test(engineId))throw new Error('Set the registered SF_SPEECH_ENGINE_ID.');
  if(!Number.isInteger(port)||port<1024||port>65535)throw new Error('Choose a valid non-privileged gateway port.');
  if(bridgeUrl&&(!/^https:\/\//.test(bridgeUrl)&&!/^http:\/\/127\.0\.0\.1(?::\d+)?\//.test(bridgeUrl)))throw new Error('Speech bridge requires HTTPS.');
  if((bridgeUrl||bridgeSecret)&&(!bridgeUrl||bridgeSecret.length<32))throw new Error('Configure the complete signed bridge URL and secret together.');
  return Object.freeze({apiKey,engineId,port,bridgeUrl,bridgeSecret});
}

export function pilotReply() {
  return 'The Speech Engine pilot is connected, but private Stonefellow Agent access is not enabled yet. Please use the original chat for now.';
}

export function signBridgeBody(body,secret,now=Math.floor(Date.now()/1000),nonce=randomBytes(16).toString('hex')) {
  const raw=JSON.stringify(body);
  const signature=createHmac('sha256',secret+'|'+nonce).update(now+'.'+raw).digest('hex');
  return {raw,headers:{'Content-Type':'application/json','X-SF-Speech-Timestamp':String(now),'X-SF-Speech-Nonce':nonce,'X-SF-Speech-Signature':signature}};
}

export function makeBridgeClient(config,transport=fetch) {
  if(!config.bridgeUrl||!config.bridgeSecret)return null;
  return async(body,signal)=>{
    const signed=signBridgeBody(body,config.bridgeSecret);
    const response=await transport(config.bridgeUrl,{method:'POST',headers:signed.headers,body:signed.raw,signal});
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.error||'bridge_unavailable');
    return data;
  };
}

export async function startGateway(config=readGatewayConfig(),factory,bridge=makeBridgeClient(config)) {
  const SDK=factory||((await import('@elevenlabs/elevenlabs-js')).ElevenLabsClient);
  const client=new SDK({apiKey:config.apiKey});
  const server=createServer((request,response)=>{
    if(request.method==='GET'&&request.url==='/health'){
      response.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});
      response.end(JSON.stringify({ok:true,service:'stonefellow-speech-gateway',account_bridge:bridge?'signed':'disabled'}));
      return;
    }
    response.writeHead(404,{'Content-Type':'text/plain','Cache-Control':'no-store'});response.end('Not found');
  });
  // attach() enables SDK JWT verification. Never set disableAuth.
  const attachment=await client.speechEngine.attach(config.engineId,server,'/ws',{
    debug:false,
    onInit(conversationId){
      if(bridge)void bridge({action:'register',conversation_id:conversationId}).catch(()=>{});
    },
    async onTranscript(transcript,signal,session){
      if(signal.aborted)return;
      const turn=Array.isArray(transcript)?transcript.at(-1):null;
      if(turn?.role!=='user')return;
      if(!bridge){session.sendResponse(pilotReply());return;}
      try{
        const result=await bridge({action:'turn',conversation_id:session.conversationId,message:String(turn.content||'').slice(0,4000)},signal);
        if(!signal.aborted)session.sendResponse(String(result.text||'Please try again.'));
      }catch(error){
        if(!signal.aborted)session.sendResponse(error.message==='not_bound'?'Your voice session is still connecting. Please try again.':'The voice connection is temporarily unavailable. Please try again.');
      }
    },
    onClose(session){if(bridge)void bridge({action:'close',conversation_id:session.conversationId}).catch(()=>{});},
    onDisconnect(session){if(bridge)void bridge({action:'close',conversation_id:session.conversationId}).catch(()=>{});},
    onError(error){console.error('Speech Engine connection error:',error?.name||'Error');}
  });
  await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(config.port,'127.0.0.1',resolve);});
  return {server,attachment,close:async()=>{await attachment.close();await new Promise(resolve=>server.close(resolve));}};
}

if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
  startGateway().then(({server})=>console.log('Speech gateway listening on localhost:'+server.address().port)).catch(error=>{
    console.error('Speech gateway setup failed:',error?.message||'Unknown error');process.exitCode=1;
  });
}
