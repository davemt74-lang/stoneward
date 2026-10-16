'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/fan-discovery.js'),'utf8');
const window={};vm.runInNewContext(source,{window,console});
const ui=window.STONEFELLOW_FAN_DISCOVERY;
let checks=0;const ok=(x,label)=>{assert.ok(x,label);checks++;console.log('PASS '+label)};
const demo={path:'after_dark',paths:[{id:'after_dark',title:'After dark'},{id:'open_road',title:'Open road'}],
 summary:'From your saved music',privacy:'Personalization is optional',allow_feedback:true,
 items:[{track_id:'abc',title:'Song <script>hello</script>',release:'Release & Co',reason:'Night & reflections'},
 {track_id:'def',title:'The Road',release:'Release',reason:'Road'}]};
const h=ui.view(demo,true);
ok(h.includes('data-fan-path="after_dark"')&&h.includes('aria-pressed="true"'),'Accessible selected discovery path');
ok(h.includes('data-fan-play="abc"')&&h.includes('data-fan-open="abc"'),'Real play and detail actions');
ok(h.includes('data-fan-queue="abc"')&&h.includes('data-fan-start'),'Signed-in Up Next and start-path actions');
ok(h.includes('data-fan-dislike="abc"'),'Consent-enabled negative preference action');
ok(!h.includes('<script>')&&h.includes('&lt;script&gt;'),'Untrusted song title HTML escaped');
ok(h.includes('Night &amp; reflections')&&h.includes('Release &amp; Co'),'Recommendation reasons and releases escaped');
const guest=ui.view({...demo,allow_feedback:false},false);
ok(!guest.includes('data-fan-dislike')&&!guest.includes('data-fan-queue'),'Anonymous guests cannot write personal preferences or queue');
ok(guest.includes('Play the first song'),'Guest action labels accurately describe supported behavior');
async function main(){
 const actions=[];
 const host={authenticated:true,play:id=>actions.push('play:'+id),
  queue:async id=>{actions.push('queue:'+id);return {ok:true}}};
 const done=await ui.playPath(demo.items,host);
 ok(done.started==='abc'&&done.queued===1,'Path playback returns verified queue count');
 ok(actions.join(',')==='play:abc,queue:def','Path preserves track ordering and uses existing player first');
 const alone=[];const guestHost={authenticated:false,play:id=>alone.push(id),
  queue:async()=>{throw Error('Guest queue must never run')}};
 const result=await ui.playPath(demo.items,guestHost);
 ok(result.queued===0&&alone.join()==='abc','Guest playback never writes a queue');
 let threw=false;
 try{await ui.playPath(demo.items,{authenticated:true,play:()=>{},queue:async()=>{throw Error('queue offline')}})}
 catch(e){threw=e.message==='queue offline'}
 ok(threw,'Queue failures are never reported as complete');
 ok((await ui.playPath([],guestHost)).started===null,'Empty catalog path never tries to play');
 const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
 const template=fs.readFileSync(path.join(__dirname,'../stonefellow-v120.php'),'utf8');
 ok(app.includes('id="fanDiscoveryMount"')&&app.includes('STONEFELLOW_FAN_DISCOVERY?.mount'),'Panel integrated into existing Music canvas');
 ok(app.includes('guided_discovery')&&app.includes("secureJsonPost('fan-discovery-feedback.php'"),'Existing player and CSRF client used');
 ok(template.includes('assets/js/fan-discovery.js'),'Discovery module loaded with existing app');
 const endpoint=fs.readFileSync(path.join(__dirname,'../api/fan-discovery-feedback.php'),'utf8');
 ok(endpoint.includes('sf_require_user(false,true)')&&endpoint.includes('sf_privacy_personalization_enabled'),'Server enforces signed-in consent and CSRF');
 ok(!endpoint.includes('sf_crm_newsletter_signup')&&!endpoint.includes('sf_agent_action_execute'),'Feedback never sends marketing or executes Agent actions');
 console.log('87A2: '+checks+' browser/API assertions green');
}
main().catch(e=>{console.error(e);process.exitCode=1});
