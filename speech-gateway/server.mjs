import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';

// Separate opt-in Node process. No permission to impersonate a Stonefellow
// account in this phase; the existing voice engine remains production default.
export function readGatewayConfig(env=process.env) {
  const apiKey=String(env.ELEVENLABS_API_KEY||'').trim();
  const engineId=String(env.SF_SPEECH_ENGINE_ID||'').trim();
  const port=Number(env.SF_SPEECH_GATEWAY_PORT||3001);
  if(apiKey.length<8)throw new Error('Set the existing ElevenLabs provider key as ELEVENLABS_API_KEY on the private gateway host.');
  if(!/^seng_[A-Za-z0-9_-]{8,96}$/.test(engineId))throw new Error('Set the registered SF_SPEECH_ENGINE_ID.');
  if(!Number.isInteger(port)||port<1024||port>65535)throw new Error('Choose a valid non-privileged gateway port.');
  return Object.freeze({apiKey,engineId,port});
}

export function pilotReply() {
  return 'The Speech Engine pilot is connected, but private Stonefellow Agent access is not enabled yet. Please use the original chat for now.';
}

export async function startGateway(config=readGatewayConfig(),factory) {
  const SDK=factory||((await import('@elevenlabs/elevenlabs-js')).ElevenLabsClient);
  const client=new SDK({apiKey:config.apiKey});
  const server=createServer((request,response)=>{
    if(request.method==='GET'&&request.url==='/health'){
      response.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});
      response.end(JSON.stringify({ok:true,service:'stonefellow-speech-gateway',account_bridge:'disabled'}));
      return;
    }
    response.writeHead(404,{'Content-Type':'text/plain','Cache-Control':'no-store'});response.end('Not found');
  });
  // attach() enables SDK JWT verification. Never set disableAuth.
  const attachment=await client.speechEngine.attach(config.engineId,server,'/ws',{
    debug:false,
    onTranscript(transcript,signal,session){
      if(signal.aborted)return;
      const turn=Array.isArray(transcript)?transcript.at(-1):null;
      if(turn?.role!=='user')return;
      // Fail closed: no private transcript or LLM context is forwarded until
      // a verified conversation-to-account binding exists in 91B9B.
      session.sendResponse(pilotReply());
    },
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
