(() => {
'use strict';
const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
const esc=ctx.esc;
const ui=(id)=>ctx.canvas.querySelector('#'+id);
const stripeSignup='https://dashboard.stripe.com/register';
const stripeKeys='https://dashboard.stripe.com/test/apikeys';
const stripeWebhooks='https://dashboard.stripe.com/test/webhooks';
function step(good,number,title,description){
 return '<div class="stripe-wizard-step"><span class="stripe-wizard-number '+(good?'done':'')+'">'+(good?'✓':number)+'</span>'+
 '<div><strong>'+esc(title)+'</strong><p>'+esc(description)+'</p></div></div>';
}
function certificationPanel(cert,s){
 const cases=cert.cases||{},run=!!cert.run_id,enabled=run&&!!cert.binding_valid;
 const names={paid:'Completed payment',declined:'Declined card',expired:'Expired or abandoned checkout',refund:'Confirmed refund'};
 const instructions={
   paid:'Pay the $1 test order with a Stripe test card. Find its checkout.session.completed event ID in the Stripe test Events view.',
   declined:'Start a separate $1 test checkout and use a declined Stripe test card. Enter the payment_intent.payment_failed event ID.',
   expired:'Use another test Checkout Session that Stripe confirms expired. Closing the browser alone does NOT count as expiry.',
   refund:'After verifying a successful $1 payment, request its test refund below; enter the refund.updated event ID after Stripe delivers it.'
 };
 const cards=Object.keys(names).map(kind=>{
   const c=cases[kind]||{},ok=!!c.verified;
   return '<form class="stripe-cert-case" data-stripe-cert="'+kind+'"><div class="panel-title"><div><h3>'+(ok?'✓ ':'○ ')+esc(names[kind])+'</h3><p>'+esc(instructions[kind])+'</p></div><span class="stripe-pill '+(ok?'connected':'pending')+'">'+(ok?'Verified by Stripe':'Pending')+'</span></div>'+
    '<div class="form-grid"><label class="field">Stonefellow test order ID<input name="order_id" required placeholder="SF-CERT-…" autocomplete="off" value="'+esc(c.order_id||'')+'"></label>'+
    '<label class="field">Stripe signed webhook event ID<input name="event_id" required placeholder="evt_…" autocomplete="off" value="'+esc(c.event_id||'')+'"></label>'+
    '<div class="actions"><button class="secondary" type="submit" '+(!enabled?'disabled':'')+'>Verify '+esc(kind)+' with Stripe</button></div></div></form>';
 }).join('');
 return '<section class="panel" id="stripeCertificationPanel"><div class="panel-title"><div><h2>Stripe sandbox acceptance</h2><p>Four real Stripe receipts are required. Stonefellow compares Stripe server responses, signed webhook events, and the saved Store order.</p></div><strong class="stripe-pill '+(cert.complete?'connected':'pending')+'">'+(cert.complete?'Certified':'Not certified')+'</strong></div>'+
 '<div class="notice"><strong>Sandbox only</strong> · Checkout sessions are $1.00 in Stripe TEST mode. No real money is charged. The test orders cannot grant customer entitlements, trigger merchandise fulfillment, or record fan purchases. Production payments stay disabled.</div>'+
 '<div class="actions"><button type="button" id="stripeCertBegin" class="primary" '+(!(s.provider==='direct'&&s.webhook_ready&&s.store_mode==='stripe_test')?'disabled':'')+'>'+(run?'Restart verification':'Start verification')+'</button>'+
 '<button type="button" id="stripeCertCreate" class="secondary" '+(!enabled?'disabled':'')+'>Create $1 test Checkout ↗</button>'+
 '<button type="button" id="stripeCertRefund" class="secondary" '+(!enabled?'disabled':'')+'>Request $1 test refund</button>'+
 '<button type="button" id="stripeCertHealth" class="secondary">Webhook diagnostics</button></div>'+
 '<p class="status-line" id="stripeCertMessage" role="status">Run: '+esc(cert.run_id||'Not started')+'. '+(enabled?'Use the buttons to create test orders; enter event IDs after Stripe delivers each webhook.':'Verify a merchant and webhook before starting.')+'</p>'+
 '<div class="stripe-cert-cases">'+cards+'</div>'+
 '<div class="actions"><button type="button" class="primary" id="stripeCertFinalize" '+(!(enabled&&Object.keys(names).every(k=>cases[k]?.verified))?'disabled':'')+'>Recheck all evidence &amp; certify</button>'+
 '<a class="secondary" href="https://dashboard.stripe.com/test/events" target="_blank" rel="noopener noreferrer">View Stripe test events ↗</a></div>'+
 '<div id="stripeCertHealthOutput" class="status-line" role="status"></div></section>';
}

function content(s,cert){
 const direct=s.provider==='direct', linked=!!s.connected, connect=s.provider==='connect', test=s.store_mode==='stripe_test';
 const canWebhook=direct&&!!s.webhook_url&&!s.webhook_ready;
 const steps=step(linked,1,'Create and verify your Stripe account',linked?'Verified merchant '+s.account_id:'Sign up in the official Stripe Dashboard; no Stripe account is required to prepare this wizard.')
 +step(linked,2,'Link Stonefellow to your merchant','For a single artist, verify a Stripe test secret key once; we encrypt it server-side. Optional Connect needs a registered platform.')
 +step(!!s.webhook_ready,3,'Signed payment notifications','Stonefellow registers a webhook and stores the signing secret encrypted. It never trusts a browser success page as proof of payment.')
 +step(!!s.sandbox_certified,4,'Complete real sandbox acceptance','Successful and declined test purchases, signed notifications, and refunds must be verified against Stripe before activation.')
 +step(!!s.payments_enabled,5,'Enable test checkout','Test payments are a separate explicit action. Live payments are never enabled by this wizard.');
 return '<div class="canvas-head"><div><div class="eyebrow">ECOMMERCE · PAYMENTS</div><h1>Connect Stripe</h1><p>Set up direct merchant payments for the Stonefellow single-artist store. Optional Connect is available for registered platforms.</p></div><div class="actions"><a class="secondary" href="index.php?view=orders">Back to orders →</a></div></div>'+
 '<section class="panel sf-stripe-onboarding"><div class="panel-title"><div><h2>Stripe setup checklist</h2><p>Nothing goes live until payments and refunds have passed the required tests.</p></div><span class="stripe-pill '+(linked?'connected':'pending')+'">'+(linked?'Merchant verified':'Not connected')+'</span></div><div class="stripe-wizard-steps">'+steps+'</div>'+
 '<div class="notice"><strong>Recommended: direct Stripe account</strong><p>Stripe Connect authorization is for platforms. A standalone merchant cannot obtain a no-credentials authorization popup without registering a Stripe Connect application. Stripe hosts your account creation and customer checkout either way.</p></div></section>'+
 '<section class="panel"><div class="panel-title"><h2>1 · Create your Stripe account</h2><p>Official Stripe website</p></div><div class="actions"><a class="primary" target="_blank" rel="noopener noreferrer" href="'+stripeSignup+'">Create Stripe account ↗</a><a class="secondary" target="_blank" rel="noopener noreferrer" href="https://dashboard.stripe.com/">Open Stripe Dashboard ↗</a></div>'+
 '<p class="status-line">No account yet? You can leave everything here unconfigured. No charges, refunds, or API calls are sent until you initiate setup.</p></section>'+
 '<section class="panel"><div class="panel-title"><h2>2 · Verify test credentials</h2><p>Stored encrypted in Stonefellow, never in ChatGPT or browser storage.</p></div>'+
 (connect?'<div class="notice">A Stripe Connect merchant is already linked. Direct account switching is blocked.</div>':
 '<p>For the single-artist store, obtain your <strong>secret TEST key</strong> from Stripe. This is a one-time server setup—not customer payment data. Stripe does not support automatic single-merchant OAuth without registering a platform.</p>'+
 '<form id="stripeDirectKeyForm" class="form-grid"><label class="field span3">Stripe test secret key<input type="password" name="secret_test_key" autocomplete="off" spellcheck="false" inputmode="text" placeholder="sk_test_…" required></label><div class="actions span3"><button type="submit" class="primary" '+(!test?'disabled':'')+'>Verify and save test key</button><a class="secondary" href="'+stripeKeys+'" target="_blank" rel="noopener noreferrer">Open Stripe test API keys ↗</a></div></form>'+
 (test?'':'<div class="notice">This server is not in stripe_test mode. Set STONEFELLOW_STORE_MODE=stripe_test before adding test credentials.</div>'))+
 '<p id="stripeDirectMessage" class="status-line" role="status"></p></section>'+
 '<section class="panel"><div class="panel-title"><h2>3 · Verified webhook</h2><p>Direct checkout, subscriptions and refunds use the same signed event pipeline.</p></div>'+
 '<p><strong>Webhook address:</strong> <code class="mono">'+esc(s.webhook_url||'Configure an HTTPS Public Base URL')+'</code></p>'+
 '<div class="actions"><button type="button" class="primary" id="stripeCreateWebhook" '+(!canWebhook?'disabled':'')+'>Register signed webhook</button><a class="secondary" href="'+stripeWebhooks+'" target="_blank" rel="noopener noreferrer">View Stripe test webhooks ↗</a></div>'+
 '<p id="stripeWebhookMessage" class="status-line" role="status">'+(s.webhook_ready?'A signed webhook secret is stored.':'Webhook registration requires a verified merchant account and HTTPS site URL.')+'</p></section>'+
 '<section class="panel"><div class="panel-title"><h2>4 · Certification and payment activation</h2><p>Payment authorization, inventory and refunds must pass real test-mode acceptance.</p></div>'+
 '<div class="stripe-wizard-summary"><div><small>Account mode</small><strong>'+esc(s.environment||'none')+'</strong></div><div><small>Merchant charges</small><strong>'+(s.charges_enabled?'Enabled by Stripe':'Not verified')+'</strong></div><div><small>Webhook</small><strong>'+(s.webhook_ready?'Configured':'Incomplete')+'</strong></div><div><small>Sandbox certification</small><strong>'+(s.sandbox_certified?'Recorded':'Pending')+'</strong></div></div>'+
 '<div class="actions"><button type="button" id="stripeRefresh" class="secondary" '+(!linked?'disabled':'')+'>Refresh merchant verification</button><button type="button" id="stripeEnableTest" class="primary" '+(!s.can_enable_test?'disabled':'')+'>Enable test checkout</button><button type="button" id="stripeDisable" class="secondary" '+(!s.payments_enabled?'disabled':'')+'>Pause Stripe</button></div>'+
 '<p id="stripeActivationStatus" role="status" class="status-line">Live mode cannot be enabled here. No test purchases are certified until real Stripe sandbox tests pass.</p></section>'+
 '<details class="panel stripe-connect-optional"><summary><strong>Optional · Connect with Stripe (registered platforms)</strong></summary>'+
 '<p>If Stonefellow is later used as a platform serving other merchants, you can register a Stripe Connect application and use its official hosted authorization flow. Not required for a single artist.</p>'+
 (s.platform_ready&&!direct?'<button class="secondary" type="button" id="stripeConnect">Authorize with Stripe Connect ↗</button>':
 '<div class="notice">A Stripe Connect platform client ID and platform secret must first be configured on your own server. This does not link ChatGPT to Stripe.</div>')+
 '<p id="stripeConnectStatus" role="status" class="status-line"></p></details>'+certificationPanel(cert||{},s);
}
async function action(kind,body,element,message){
 if(element)element.disabled=true;
 try{await ctx.api('stripe-connect.php',Object.assign({action:kind},body||{}));await render();}
 catch(error){if(message)message.textContent=String(error.message||error);if(element)element.disabled=false;}
}
async function render(){
 ctx.canvas.innerHTML='<div class="canvas-head"><h1>Payments &amp; Stripe</h1></div><section class="panel">Checking merchant configuration…</section>';
 try{
  const res=await ctx.api('stripe-connect.php');const s=res.stripe||{};
  ctx.canvas.innerHTML=content(s,res.certification||{});
  const certMsg=()=>ui('stripeCertMessage');
  const begin=ui('stripeCertBegin');
  if(begin)begin.onclick=async()=>{
    if(!window.confirm('Start a fresh Stripe test certification run? Existing run evidence will be replaced and any previous test activation disabled.'))return;
    await action('cert_begin',{},begin,certMsg());
  };
  const create=ui('stripeCertCreate');
  if(create)create.onclick=async()=>{
    const tab=window.open('about:blank','stonefellow-stripe-test','width=860,height=780,scrollbars=yes');
    create.disabled=true;
    try{
      const result=await ctx.api('stripe-connect.php',{action:'cert_checkout'});
      if(!result.checkout?.checkout_url?.startsWith('https://checkout.stripe.com/'))
        throw new Error('Stripe returned an invalid test Checkout URL.');
      if(tab&&!tab.closed)tab.location.replace(result.checkout.checkout_url);
      else window.location.assign(result.checkout.checkout_url);
      if(certMsg())certMsg().textContent='Test order '+result.checkout.order_id+' created. Complete the checkout using a Stripe TEST card, then verify its event.';
    }catch(error){if(tab&&!tab.closed)tab.close();if(certMsg())certMsg().textContent=String(error.message||error);}
    finally{create.disabled=false;}
  };
  const refund=ui('stripeCertRefund');
  if(refund)refund.onclick=async()=>{
    const orderId=String(ui('stripeCertificationPanel')?.querySelector('[data-stripe-cert="paid"] input[name="order_id"]')?.value||'').trim();
    if(!orderId){if(certMsg())certMsg().textContent='Verify the successful $1 test order before requesting a refund.';return;}
    if(!window.confirm('Request a real Stripe TEST-mode $1.00 refund for '+orderId+'? This is a provider operation with no live-money transfer.'))return;
    refund.disabled=true;
    try{
      const result=await ctx.api('stripe-connect.php',{action:'cert_refund',order_id:orderId});
      if(certMsg())certMsg().textContent='Stripe test refund '+(result.refund?.refund_id||'')+' requested. Wait for the signed refund.updated event.';
    }catch(e){if(certMsg())certMsg().textContent=String(e.message||e);}
    finally{refund.disabled=false;}
  };
  ctx.canvas.querySelectorAll('[data-stripe-cert]').forEach(form=>{
    form.onsubmit=async(event)=>{
      event.preventDefault();
      const button=form.querySelector('button[type="submit"]');
      const values=new FormData(form);
      await action('cert_verify',{case:form.dataset.stripeCert,order_id:String(values.get('order_id')||'').trim(),
        event_id:String(values.get('event_id')||'').trim()},button,certMsg());
    };
  });
  const finalize=ui('stripeCertFinalize');
  if(finalize)finalize.onclick=async()=>{
    if(!window.confirm('Independently revalidate all four Stripe events, order states and merchant account before enabling test certification?'))return;
    await action('cert_finalize',{},finalize,certMsg());
  };
  const health=ui('stripeCertHealth');
  if(health)health.onclick=async()=>{
    health.disabled=true;
    try{
      const result=await ctx.api('stripe-connect.php',{action:'cert_health'});
      const c=result.health?.counts||{};
      const recent=(result.health?.recent||[]).map(x=>'<li>'+esc(x.created_at)+' · '+esc(x.type)+' · '+esc(x.status)+'</li>').join('');
      ui('stripeCertHealthOutput').innerHTML='<p>Last seven days, at most 100 events: <strong>'+
        Number(c.processed||0)+' processed · '+Number(c.failed||0)+' failed · '+Number(c.processing||0)+' processing</strong></p>'+
        (recent?'<ul>'+recent+'</ul>':'<p>No recent webhook events recorded.</p>');
    }catch(e){ui('stripeCertHealthOutput').textContent=String(e.message||e);}
    finally{health.disabled=false;}
  };

  const keyForm=ui('stripeDirectKeyForm');
  if(keyForm)keyForm.onsubmit=async(e)=>{
   e.preventDefault();
   const button=keyForm.querySelector('button[type=submit]');
   const message=ui('stripeDirectMessage');
   const value=String(new FormData(keyForm).get('secret_test_key')||'');
   keyForm.reset(); // key must not remain in the DOM
   await action('save_test_key',{secret_test_key:value},button,message);
  };
  const webhook=ui('stripeCreateWebhook');
  if(webhook)webhook.onclick=()=>action('create_test_webhook',{},webhook,ui('stripeWebhookMessage'));
  const refresh=ui('stripeRefresh');
  if(refresh)refresh.onclick=()=>action(s.provider==='direct'?'refresh_direct':'refresh',{},refresh,ui('stripeActivationStatus'));
  const enable=ui('stripeEnableTest');
  if(enable)enable.onclick=()=>action('enable_test',{},enable,ui('stripeActivationStatus'));
  const disable=ui('stripeDisable');
  if(disable)disable.onclick=()=>action('disable',{},disable,ui('stripeActivationStatus'));
  const connect=ui('stripeConnect');
  if(connect)connect.onclick=async()=>{
    const popup=window.open('about:blank','sf-stripe-connect','width=780,height=760,scrollbars=yes');
    const message=ui('stripeConnectStatus');connect.disabled=true;
    try{
      const next=await ctx.api('stripe-connect.php',{action:'start'});
      if(!next.authorize_url?.startsWith('https://connect.stripe.com/oauth/authorize?'))
       throw new Error('Invalid Stripe authorization URL.');
      if(popup&&!popup.closed)popup.location.replace(next.authorize_url);
      else window.location.assign(next.authorize_url);
      if(message)message.textContent='Complete your authorization on Stripe.';
    }catch(e){if(popup&&!popup.closed)popup.close();if(message)message.textContent=e.message;connect.disabled=false;}
  };
 }catch(e){
  ctx.canvas.innerHTML='<section class="panel"><h2>Stripe configuration unavailable</h2><p>'+esc(String(e.message||e))+'</p><button class="secondary" id="stripeRetry">Retry</button></section>';
  ui('stripeRetry').onclick=render;
 }
}
window.addEventListener('message',event=>{
 if(event.origin!==window.location.origin||event.data?.type!=='stonefellow-stripe-oauth-result')return;
 if(ctx.app.view==='payments')void render();
});
window.SFStripeConnectAdmin={render};
})();
