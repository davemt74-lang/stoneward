(() => {
 'use strict';
 const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
 const esc=ctx.esc;
 function statusCard(s){
   const linked=!!s.connected, ready=!!s.platform_ready, on=!!s.payments_enabled;
   const steps=[
     [linked,'Stripe merchant account',linked?'Linked: '+s.account_id:'Not yet authorized'],
     [s.details_submitted,'Account details','Business verification submitted to Stripe'],
     [s.charges_enabled,'Payment capability','Stripe must approve charges'],
     [s.webhook_ready,'Signed webhooks','Required for payment and refund confirmation'],
     [on,'Test checkout','Deliberately enabled only after setup']
   ];
   const htmlSteps=steps.map(function(step){
     return '<div class="stripe-check"><span class="'+(step[0]?'stripe-good':'stripe-wait')+'">'+(step[0]?'✓':'○')+'</span><div><strong>'+esc(step[1])+'</strong><small>'+esc(step[2])+'</small></div></div>';
   }).join('');
   const cta=ready?
     '<div class="actions"><button type="button" class="primary" id="stripeConnect">'+(linked?'Reconnect with Stripe':'Connect with Stripe ↗')+'</button><button type="button" class="secondary" id="stripeRefresh" '+(!linked?'disabled':'')+'>Refresh status</button><a class="secondary" href="https://dashboard.stripe.com/" target="_blank" rel="noopener noreferrer">Stripe Dashboard ↗</a></div>':
     '<div class="notice"><strong>One-time Stripe platform setup required</strong><p>To use the official Connect authorization, a Stripe Connect application must be registered on your server. Stonefellow is a single-artist site; Stripe Connect is optional, and a direct Stripe merchant account is also supported. There is no credential-free OAuth for an unregistered app.</p></div><div class="actions"><a class="primary" href="https://stripe.com/" target="_blank" rel="noopener noreferrer">Sign up with Stripe ↗</a><a class="secondary" href="https://dashboard.stripe.com/settings/connect/onboarding-options/oauth" target="_blank" rel="noopener noreferrer">Set up Connect platform ↗</a></div>';
   return '<div class="canvas-head"><div><div class="eyebrow">COMMERCE &amp; PAYMENTS</div><h1>Payments &amp; Stripe</h1><p>Merchant onboarding, secure payment status, and test checkout controls.</p></div></div>'+
    '<section class="panel sf-stripe-onboarding"><div class="panel-title"><div><h2>Connect with Stripe</h2><p>Stripe hosts account signup and authorization. Stonefellow never sees your Stripe password.</p></div><strong class="stripe-pill '+(linked?'connected':'pending')+'">'+(linked?'Connected':'Not connected')+'</strong></div>'+
    '<div class="stripe-check-grid">'+htmlSteps+'</div>'+cta+'<p class="status-line" id="stripeConnectStatus" role="status"></p></section>'+
    '<section class="panel"><div class="panel-title"><h2>Payment activation</h2><p>Authorization does not automatically switch on charges.</p></div>'+
    '<p>Connected environment: <strong>'+esc(s.environment||'Not configured')+'</strong></p><div class="actions">'+
    '<button type="button" class="primary" id="stripeEnableTest" '+(!(linked&&s.charges_enabled&&s.webhook_ready&&s.sandbox_certified&&!on&&s.environment==='test')?'disabled':'')+'>Enable test checkout</button>'+
    '<button type="button" class="secondary" id="stripeDisable" '+(!on?'disabled':'')+'>Pause payment processing</button></div>'+
    '<p class="status-line" id="stripeActivationStatus" role="status"></p>'+
    '<div class="notice">Live payments are blocked from this screen. Before activation, complete a real Stripe test checkout, signed webhook and refund in staging.</div></section>';
 }
 async function refresh(){
   const result=await ctx.api('stripe-connect.php');
   ctx.canvas.innerHTML=statusCard(result.stripe||{});
   const connect=ctx.canvas.querySelector('#stripeConnect');
   if(connect)connect.onclick=async function(){
     const popup=window.open('about:blank','sf-stripe-connect','width=780,height=760,scrollbars=yes');
     connect.disabled=true;
     const status=ctx.canvas.querySelector('#stripeConnectStatus');
     try{
       const r=await ctx.api('stripe-connect.php',{action:'start'});
       if(!r.authorize_url||!r.authorize_url.startsWith('https://connect.stripe.com/oauth/authorize?'))throw new Error('Unexpected Stripe authorization address.');
       if(popup&&!popup.closed)popup.location.replace(r.authorize_url);
       else window.location.assign(r.authorize_url);
       status.textContent='Complete the authorization on Stripe and return here.';
     }catch(e){
       if(popup&&!popup.closed)popup.close();
       status.textContent=e.message;connect.disabled=false;
     }
   };
   const scan=ctx.canvas.querySelector('#stripeRefresh');
   if(scan)scan.onclick=async function(){
     scan.disabled=true;
     try{await ctx.api('stripe-connect.php',{action:'refresh'});await refresh();}
     catch(e){ctx.say(e.message);scan.disabled=false;}
   };
   const enable=ctx.canvas.querySelector('#stripeEnableTest');
   if(enable)enable.onclick=async function(){
     enable.disabled=true;try{await ctx.api('stripe-connect.php',{action:'enable_test'});await refresh();}
     catch(e){ctx.canvas.querySelector('#stripeActivationStatus').textContent=e.message;enable.disabled=false;}
   };
   const disable=ctx.canvas.querySelector('#stripeDisable');
   if(disable)disable.onclick=async function(){
     disable.disabled=true;try{await ctx.api('stripe-connect.php',{action:'disable'});await refresh();}
     catch(e){ctx.canvas.querySelector('#stripeActivationStatus').textContent=e.message;disable.disabled=false;}
   };
 }
 async function render(){
   ctx.canvas.innerHTML='<div class="canvas-head"><h1>Payments &amp; Stripe</h1></div><section class="panel">Checking Stripe configuration…</section>';
   try{await refresh();}catch(e){
     ctx.canvas.innerHTML='<section class="panel"><h2>Stripe settings unavailable</h2><p>'+esc(e.message)+'</p><button class="secondary" id="stripeRetry">Retry</button></section>';
     ctx.canvas.querySelector('#stripeRetry').onclick=render;
   }
 }
 window.addEventListener('message',function(event){
   if(event.origin!==window.location.origin||!event.data||event.data.type!=='stonefellow-stripe-oauth-result')return;
   if(ctx.app.view==='payments')render();
 });
 window.SFStripeConnectAdmin={render:render};
})();