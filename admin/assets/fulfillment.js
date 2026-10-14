(() => {
  'use strict';
  const ctx=window.STONEFELLOW_ADMIN_CONTEXT;
  if(!ctx)return;
  const {api,canvas,head,say,esc,money,openView}=ctx;
  const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const state={summary:{},orders:[],cases:[],order:null,case:null,view:'dashboard'};
  const dt=v=>v?String(v).replace('T',' ').slice(0,19):'—';
  const label=v=>String(v||'').replaceAll('_',' ');
  const badge=v=>'<span class="badge '+(['delivered','resolved','closed','confirmed'].includes(String(v))?'good':'')+'">'+esc(String(v||'').toUpperCase())+'</span>';
  async function load(){
    const j=await api('fulfillment.php');state.summary=j.summary||{};state.orders=j.orders||[];state.cases=j.cases||[];return j;
  }
  function renderSummary(){
    const s=state.summary;
    return '<div class="stats"><div class="stat"><strong>'+Number(s.active_shipments||0)+'</strong><span>Active shipments</span></div><div class="stat"><strong>'+Number(s.refund_requests||0)+'</strong><span>Refund requests</span></div><div class="stat"><strong>'+Number(s.open_cases||0)+'</strong><span>Open care cases</span></div><div class="stat"><strong>'+Number(s.high_priority_cases||0)+'</strong><span>High priority</span></div></div>';
  }
  async function renderFulfillment(){
    try{await load()}catch(e){canvas.innerHTML=head('FULFILLMENT + CARE','Unavailable',e.message);return}
    const physical=state.orders.filter(o=>o.physical),needs=physical.filter(o=>!['delivered','canceled','returned'].includes(o.fulfillment_status));
    canvas.innerHTML=head('POST-PURCHASE OPERATIONS','Fulfillment + Care','Ship orders, manage returns/refunds, and resolve fan support cases from one governed workspace.','<button class="secondary" id="fulfillmentRefresh" type="button">Refresh</button>')+
      renderSummary()+
      '<section class="panel"><div class="panel-title"><div><h2>Fulfillment queue</h2><p>'+needs.length+' physical orders still need attention.</p></div></div>'+
      (needs.length?'<table class="data-table"><thead><tr><th>Order</th><th>Fan</th><th>Order</th><th>Fulfillment</th><th>Tracking</th><th></th></tr></thead><tbody>'+needs.map(o=>'<tr><td class="mono">'+esc(o.id)+'</td><td><strong>'+esc(o.customer_name||'Fan')+'</strong><div class="file-list">'+esc(o.customer_email||'')+'</div></td><td>'+badge(o.status)+'</td><td>'+badge(o.fulfillment_status)+'</td><td>'+esc(o.tracking_number||'—')+'</td><td><button class="secondary" type="button" data-fulfill-order="'+esc(o.id)+'">Open</button></td></tr>').join('')+'</tbody></table>':'<div class="empty">No fulfillment work is waiting.</div>')+'</section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Fan care queue</h2><p>Order questions, shipping problems, damaged items and refund requests.</p></div></div>'+
      (state.cases.length?'<table class="data-table"><thead><tr><th>Case</th><th>Fan</th><th>Order</th><th>Issue</th><th>Priority</th><th>Status</th><th></th></tr></thead><tbody>'+state.cases.map(c=>'<tr><td><strong>'+esc(c.case_key)+'</strong><div class="file-list">'+esc(c.subject)+'</div></td><td>'+esc(c.email||'')+'</td><td class="mono">'+esc(c.order_id||'—')+'</td><td>'+esc(label(c.issue_type))+'</td><td>'+badge(c.priority)+'</td><td>'+badge(c.status)+'</td><td><button class="secondary" type="button" data-care-case="'+c.id+'">Open</button></td></tr>').join('')+'</tbody></table>':'<div class="empty">No support cases yet.</div>')+'</section>';
    $('#fulfillmentRefresh').onclick=renderFulfillment;
    $$('[data-fulfill-order]',canvas).forEach(b=>b.onclick=()=>openOrder(b.dataset.fulfillOrder));
    $$('[data-care-case]',canvas).forEach(b=>b.onclick=()=>openCase(Number(b.dataset.careCase)));
  }
  async function openOrder(id){
    try{const j=await api('fulfillment.php?order_id='+encodeURIComponent(id));state.order=j;renderOrder()}catch(e){say(e.message)}
  }
  async function loadVinylProduction(orderId){
    try{
      const data=await api('vinyl-production.php?order_id='+encodeURIComponent(orderId));
      const box=$('#vinylProductionPanel',canvas);if(!box)return;
      const r=data.record||{},stages=data.stages||[],index=stages.indexOf(r.status),next=stages[index+1],events=r.events||[],proof=data.proof||{};
      const proofReady=!!proof.ready,proofLocked=!!r.proof_sha256,proofMatches=proofLocked&&r.proof_sha256===proof.fingerprint;
      const legacyProof=index>0&&!proofLocked&&proofReady;
      const proofNeedsReview=!proofReady||(index>0&&!proofMatches);
      const kunakiItems=Array.isArray(proof.items)?proof.items:[];
      const kunakiBindings=r.kunaki_products||{};
      const kunakiPanel='<h3>Kunaki vinyl publishing &amp; fulfillment</h3>'+
        '<p>Kunaki provides XML and HTTP fulfillment for already-published product IDs. New custom vinyl titles must first be published in Kunaki. No external orders, payments or uploads are sent from here.</p>'+
        '<p><a href="https://kunaki.com/product-vinyl.html" target="_blank" rel="noopener noreferrer">Open Kunaki vinyl publisher ↗</a></p>'+
        (kunakiItems.length?kunakiItems.map(item=>{
          const key=String(item.item_index);
          const existing=kunakiBindings[key]||null;
          return '<form class="form-grid" data-kunaki-bind="'+esc(key)+'"><label class="field">Vinyl item '+(Number(item.item_index)+1)+' · '+esc(item.title||'Custom vinyl')+
            '<input name="product_id" maxlength="10" minlength="10" required autocomplete="off" pattern="[A-Za-z0-9]{10}" placeholder="Published 10-character ID" value="'+esc(existing?.product_id||'')+'" '+(existing?'readonly':'')+'></label>'+
            '<div class="actions"><button type="submit" class="secondary" '+(!proofMatches||existing?'disabled':'')+'>'+(existing?'Bound to purchased design':'Link published Kunaki ID')+'</button></div></form>';
        }).join(''):'<p>No eligible vinyl items.</p>')+
        '<div class="notice">Product IDs are stored against the approved artwork fingerprint. Linking an ID does not verify Kunaki published it and does not submit a manufacturing order. XML/HTTP order submission remains disabled.</div>';

      box.innerHTML='<div class="panel-title"><div><h2>Custom vinyl production</h2><p>Artwork approval, pressing and quality control. Shipping is managed separately.</p></div>'+badge(r.status)+'</div>'+
        '<p>Stage '+(index+1)+' of '+stages.length+' · revision '+Number(r.revision||0)+'</p>'+
        '<div class="notice"><strong>Purchased artwork &amp; POD proof</strong><p>'+(proofReady?(proofMatches?'Approved design is locked to this production record.':'Internal handoff matches the order; staff proof approval is required.'):'Approval blocked until the purchased vinyl design matches the protected production handoff.')+'</p>'+
        '<p>Matched vinyl items: '+Number((proof.items||[]).length)+' · fingerprint: <code>'+esc(proof.fingerprint?String(proof.fingerprint).slice(0,16)+'…':'Not ready')+'</code></p>'+
        (proof.blockers||[]).map(x=>'<p>'+esc(x)+'</p>').join('')+
        '<small>This verifies the internal file handoff, not external manufacturer confirmation.</small></div>'+
        (legacyProof?'<div class="actions"><button type="button" class="secondary" id="vinylProofLegacy">Verify legacy production proof</button></div>':'')+
        (next?'<form id="vinylAdvanceForm" class="form-grid"><label class="field">Next milestone<input readonly value="'+esc(label(next))+'"></label><label class="field">Production note<input name="note" maxlength="500" placeholder="'+(next==='production_complete'?'Required quality-control inspection details':'Optional internal note')+'" '+(next==='production_complete'?'required minlength="10"':'')+'></label>'+
        (next==='production_complete'?'<label class="field">Confirm manual quality inspection <input type="checkbox" name="qc_confirmed" required></label>':'')+
        '<div class="actions span3"><button class="primary" type="submit" '+(proofNeedsReview?'disabled':'')+'>Confirm '+esc(label(next))+'</button></div></form>':'<p>Production stages complete. Arrange shipment through Fulfillment.</p>')+
        kunakiPanel+
        '<h3>Production history</h3>'+(events.length?'<ol>'+events.map(e=>'<li><strong>'+esc(label(e.to))+'</strong> · '+esc(dt(e.at))+' · '+esc(e.note||'No note')+'</li>').join('')+'</ol>':'<div class="empty">No production transitions yet.</div>');
      box.querySelectorAll('[data-kunaki-bind]').forEach(form=>{
        form.onsubmit=async event=>{
          event.preventDefault();
          if(!proofMatches||!proof.fingerprint)return;
          const itemIndex=Number(form.dataset.kunakiBind);
          const productId=String(new FormData(form).get('product_id')||'').trim().toUpperCase();
          if(!/^[A-Z0-9]{10}$/.test(productId)){alert('Enter the 10-character Kunaki product ID.');return;}
          if(!confirm('Confirm '+productId+' is the published Kunaki product for THIS exact vinyl design? This only records a mapping and does NOT place an order.'))return;
          const submit=form.querySelector('button[type="submit"]');submit.disabled=true;
          try{
            await api('vinyl-production.php',{action:'link_kunaki_product',order_id:orderId,
              revision:Number(r.revision),confirmed:true,proof_sha256:proof.fingerprint,
              item_index:itemIndex,product_id:productId});
            say('Kunaki product ID linked to this approved vinyl design. No vendor order was placed.');
            await loadVinylProduction(orderId);
          }catch(e){alert(e.message);submit.disabled=false;}
        };
      });
      const legacy=$('#vinylProofLegacy',box);
      if(legacy)legacy.onclick=async()=>{
        if(!confirm('Verify that this existing production order matches its original internal POD proof? No vendor action is performed.'))return;
        legacy.disabled=true;
        try{
          await api('vinyl-production.php',{action:'verify_existing_proof',order_id:orderId,
            revision:Number(r.revision),confirmed:true,proof_sha256:proof.fingerprint});
          say('Existing artwork proof verified.');await loadVinylProduction(orderId);
        }catch(e){alert(e.message);legacy.disabled=false;}
      };
      const form=$('#vinylAdvanceForm',box);
      if(form)form.onsubmit=async event=>{
        event.preventDefault();
        if(!confirm('Advance this vinyl order to '+label(next)+'?'))return;
        const button=form.querySelector('button[type="submit"]');button.disabled=true;
        try{
          const formData=new FormData(form);
          const updated=await api('vinyl-production.php',{action:'advance',order_id:orderId,
            status:next,revision:Number(r.revision),confirmed:true,proof_sha256:proof.fingerprint||'',
            note:formData.get('note')||'',qc_confirmed:formData.get('qc_confirmed')==='on'});
          say('Vinyl production status saved.');await loadVinylProduction(orderId);
        }catch(e){alert(e.message);button.disabled=false;}
      };
    }catch(e){
      const box=$('#vinylProductionPanel',canvas);
      if(box)box.innerHTML='<div class="empty">Production details unavailable: '+esc(e.message)+'</div>';
    }
  }
  function renderOrder(){
    const j=state.order,o=j.order||{},q=o.quote||{},f=o.fulfillment||{},ships=j.shipments||[],refunds=j.refunds||[],cases=j.cases||[];
    const vinylItems=Array.isArray(q.items)?q.items.filter(item=>item.type==='custom_media'&&(item.format||'vinyl')==='vinyl'):[];
    const sideReview=(build,side)=>{const tracks=build?.sides?.[side]?.tracks||[];return '<div class="fulfillment-vinyl-side"><strong>Side '+side+'</strong> · '+tracks.length+' songs · '+Math.round(Number(build?.sides?.[side]?.duration||0)/60)+' min<ol>'+tracks.map(t=>'<li>'+esc(t.title||t.id)+' <small>('+Math.round(Number(t.duration||0))+' sec)</small></li>').join('')+'</ol></div>';};
    const vinylReview=vinylItems.map((item,i)=>{const b=item.builder||{};return '<div class="fulfillment-card"><div><div class="eyebrow">CUSTOM VINYL '+(i+1)+'</div><h3>'+esc(b.title||'Untitled edition')+'</h3><p><strong>Format:</strong> '+esc(item.format||'vinyl')+'</p><p><strong>Artwork:</strong> '+esc(b.theme||'')+' · Front '+esc(b.coverFront||'')+' · Back '+esc(b.coverBack||'')+'</p><p><strong>Personal dedication:</strong> '+esc(b.customNote||'None')+'</p>'+sideReview(b,'A')+sideReview(b,'B')+'</div></div>';}).join('');
    canvas.innerHTML=head('FULFILLMENT + CARE',o.id||'Order','Shipment, refund and customer-care history.','<button class="secondary" id="fulfillBack" type="button">Back</button><button class="secondary" id="openLegacyOrder" type="button">Open Orders record</button>')+
      '<div class="stats"><div class="stat"><strong>'+esc(o.status||'')+'</strong><span>Order state</span></div><div class="stat"><strong>'+esc(f.status||'pending')+'</strong><span>Fulfillment</span></div><div class="stat"><strong>'+money(q.total_cents||0)+'</strong><span>Total</span></div><div class="stat"><strong>'+ships.length+'</strong><span>Shipments</span></div></div>'+
      (vinylItems.length?'<section class="panel"><div class="panel-title"><div><h2>Vinyl design proof</h2><p>Review the actual submitted artwork selections, dedication, and master track order before approval.</p></div></div>'+vinylReview+'</section><section class="panel" id="vinylProductionPanel"><p>Loading production stages…</p></section>':'')+
      '<section class="fulfillment-grid"><div class="panel"><div class="panel-title"><div><h2>Shipments</h2><p>Each tracking record is independently auditable.</p></div><button class="primary" type="button" id="newShipment">Add shipment</button></div>'+(ships.length?ships.map(shipmentCard).join(''):'<div class="empty">No shipment records yet.</div>')+'</div>'+
      '<div class="panel"><div class="panel-title"><div><h2>Refunds + returns</h2><p>Refund confirmation and inventory restocking are separate decisions.</p></div><button class="secondary" type="button" id="requestRefund">Request refund</button></div>'+(refunds.length?refunds.map(refundCard).join(''):'<div class="empty">No refund records.</div>')+(String(f.status)==='returned'?'<button class="secondary" id="restockReturn" type="button">Restock returned merchandise</button>':'')+'</div></section>'+
      '<section class="panel"><div class="panel-title"><div><h2>Care cases</h2><p>Cases linked to this order.</p></div></div>'+(cases.length?cases.map(c=>'<button class="care-inline" type="button" data-order-case="'+c.id+'"><span><strong>'+esc(c.case_key)+'</strong><small>'+esc(c.subject)+'</small></span>'+badge(c.status)+'</button>').join(''):'<div class="empty">No support cases for this order.</div>')+'</section>';
    if(vinylItems.length)void loadVinylProduction(o.id);
    $('#fulfillBack').onclick=renderFulfillment;
    $('#openLegacyOrder').onclick=()=>{openView('orders');setTimeout(()=>{const b=document.querySelector('[data-order-id="'+CSS.escape(o.id)+'"]');if(b)b.click()},50)};
    $('#newShipment').onclick=()=>shipmentModal(o.id,null);
    $('#requestRefund').onclick=()=>refundModal(o);
    $$('[data-shipment-edit]',canvas).forEach(b=>b.onclick=()=>shipmentModal(o.id,ships.find(x=>Number(x.id)===Number(b.dataset.shipmentEdit))));
    $$('[data-refund-confirm]',canvas).forEach(b=>b.onclick=()=>confirmRefund(Number(b.dataset.refundConfirm),o.id));
    $$('[data-refund-reject]',canvas).forEach(b=>b.onclick=()=>rejectRefund(Number(b.dataset.refundReject),o.id));
    $$('[data-order-case]',canvas).forEach(b=>b.onclick=()=>openCase(Number(b.dataset.orderCase)));
    const rr=$('#restockReturn');if(rr)rr.onclick=()=>restockReturn(o.id);
  }
  function shipmentCard(s){return '<article class="fulfillment-card"><div><div class="eyebrow">SHIPMENT '+s.id+'</div><strong>'+esc(s.carrier||'Carrier not set')+(s.service?' · '+esc(s.service):'')+'</strong><span>'+esc(s.tracking_number||'No tracking number')+'</span><small>'+esc(label(s.status))+' · updated '+esc(dt(s.updated_at))+'</small></div><div>'+badge(s.status)+'<button class="secondary" type="button" data-shipment-edit="'+s.id+'">Edit</button></div></article>'}
  function refundCard(r){return '<article class="fulfillment-card"><div><div class="eyebrow">REFUND '+r.id+'</div><strong>'+money(r.amount_cents||0)+'</strong><span>'+esc(r.reason||'')+'</span><small>'+esc(label(r.restock_status))+' · '+esc(dt(r.requested_at))+'</small></div><div>'+badge(r.status)+(r.status==='requested'?'<button class="primary" type="button" data-refund-confirm="'+r.id+'">Confirm</button><button class="danger" type="button" data-refund-reject="'+r.id+'">Reject</button>':'')+'</div></article>'}
  function modal(title,body){
    const el=document.createElement('div');el.className='fulfillment-modal';el.innerHTML='<div class="fulfillment-modal-card"><div class="panel-title"><h2>'+esc(title)+'</h2><button class="secondary" type="button" data-modal-close>Close</button></div>'+body+'</div>';document.body.appendChild(el);$('[data-modal-close]',el).onclick=()=>el.remove();return el;
  }
  function shipmentModal(orderId,s){
    const sh=s||{status:'label_created',carrier:'',service:'',tracking_number:'',tracking_url:'',estimated_delivery_at:'',note:''},el=modal(s?'Edit shipment':'Add shipment','<form id="shipmentForm" class="form-grid"><label class="field">Status<select name="status">'+['label_created','in_transit','out_for_delivery','delivered','exception','returned','canceled'].map(x=>'<option value="'+x+'" '+(sh.status===x?'selected':'')+'>'+label(x)+'</option>').join('')+'</select></label><label class="field">Carrier<input name="carrier" value="'+esc(sh.carrier||'')+'"></label><label class="field">Service<input name="service" value="'+esc(sh.service||'')+'"></label><label class="field">Tracking number<input name="tracking_number" value="'+esc(sh.tracking_number||'')+'"></label><label class="field span2">Tracking URL<input name="tracking_url" value="'+esc(sh.tracking_url||'')+'"></label><label class="field">Estimated delivery<input type="datetime-local" name="estimated_delivery_at" value="'+esc(String(sh.estimated_delivery_at||'').slice(0,16))+'"></label><label class="field span3">Internal/customer note<textarea name="note">'+esc(sh.note||'')+'</textarea></label><div class="actions span3"><button class="primary" type="submit">Save shipment</button></div></form>');
    $('#shipmentForm',el).onsubmit=async e=>{e.preventDefault();const d=new FormData(e.currentTarget),payload={id:s?Number(s.id):0,request_key:s?s.request_key:(crypto.randomUUID?.()||Date.now().toString()),status:d.get('status'),carrier:d.get('carrier'),service:d.get('service'),tracking_number:d.get('tracking_number'),tracking_url:d.get('tracking_url'),estimated_delivery_at:d.get('estimated_delivery_at'),note:d.get('note')};if(!confirm('Save this shipment update and notify the fan when applicable?'))return;try{const j=await api('fulfillment.php',{action:'save_shipment',order_id:orderId,shipment:payload});state.order=j.snapshot;el.remove();say('Shipment saved.');renderOrder()}catch(x){alert(x.message)}}
  }
  function refundModal(o){
    const total=Number(o.quote?.total_cents||0),el=modal('Request refund','<form id="refundForm" class="form-grid"><label class="field">Amount<input name="amount" type="number" min="0.01" step="0.01" max="'+(total/100).toFixed(2)+'" value="'+(total/100).toFixed(2)+'"></label><label class="field span2">Reason<input name="reason" required placeholder="Reason for refund"></label><div class="notice span3">Creating a refund record does not move money. Confirming the refund is a separate Admin action after the payment provider or manual payment process is complete.</div><div class="actions span3"><button class="primary" type="submit">Create refund request</button></div></form>');
    $('#refundForm',el).onsubmit=async e=>{e.preventDefault();const d=new FormData(e.currentTarget);try{const j=await api('fulfillment.php',{action:'request_refund',order_id:o.id,refund:{amount_cents:Math.round(Number(d.get('amount')||0)*100),reason:d.get('reason'),request_key:crypto.randomUUID?.()||Date.now().toString()}});state.order=j.snapshot;el.remove();say('Refund request recorded.');renderOrder()}catch(x){alert(x.message)}}
  }
  async function confirmRefund(id,orderId){const ref=prompt('Payment-provider refund reference (optional):','')||'';const restock=confirm('Also restock inventory now? Choose Cancel if merchandise shipped/delivered or has not been physically returned.');if(!confirm('Confirm this refund as completed? This is a consequential financial record.'))return;try{const j=await api('fulfillment.php',{action:'confirm_refund',refund_id:id,provider_reference:ref,restock,confirmed:true});state.order=j.snapshot;say('Refund confirmed.');renderOrder()}catch(e){alert(e.message)}}
  async function rejectRefund(id,orderId){const reason=prompt('Reason for rejecting this refund request:','')||'';if(!confirm('Reject this refund request?'))return;try{const j=await api('fulfillment.php',{action:'reject_refund',refund_id:id,reason});state.order=j.snapshot;say('Refund request rejected.');renderOrder()}catch(e){alert(e.message)}}
  async function restockReturn(orderId){if(!confirm('Restock sold inventory for this returned order? This should only be done after physical return is received.'))return;try{const j=await api('fulfillment.php',{action:'restock_return',order_id:orderId,reason:'return_received',confirmed:true});state.order=j.snapshot;say(String(j.units||0)+' unit(s) restocked.');renderOrder()}catch(e){alert(e.message)}}
  async function openCase(id){try{const j=await api('fulfillment.php?case_id='+id);state.case=j.case;renderCase()}catch(e){say(e.message)}}
  function renderCase(){
    const c=state.case||{},msgs=c.messages||[];
    canvas.innerHTML=head('FAN CUSTOMER CARE',c.case_key||'Support case',c.subject||'','<button class="secondary" id="caseBack" type="button">Back</button>')+
      '<div class="stats"><div class="stat"><strong>'+esc(c.status||'')+'</strong><span>Status</span></div><div class="stat"><strong>'+esc(c.priority||'')+'</strong><span>Priority</span></div><div class="stat"><strong>'+esc(c.issue_type||'')+'</strong><span>Issue</span></div><div class="stat"><strong>'+esc(c.order_id||'—')+'</strong><span>Order</span></div></div>'+
      '<section class="fulfillment-grid"><div class="panel"><div class="panel-title"><h2>Conversation</h2></div><div class="care-thread">'+msgs.map(m=>'<article class="care-message '+esc(m.actor_type)+'"><strong>'+esc(label(m.actor_type))+'</strong><p>'+esc(m.message).replace(/\n/g,'<br>')+'</p><small>'+esc(dt(m.created_at))+'</small></article>').join('')+'</div><form id="careReplyForm" class="care-reply"><textarea name="message" required placeholder="Reply to fan"></textarea><button class="primary" type="submit">Send reply</button></form></div>'+
      '<div class="panel"><div class="panel-title"><h2>Case controls</h2></div><form id="caseStateForm" class="form-grid"><label class="field">Status<select name="status">'+['open','waiting_on_fan','waiting_on_admin','resolved','closed'].map(x=>'<option value="'+x+'" '+(c.status===x?'selected':'')+'>'+label(x)+'</option>').join('')+'</select></label><label class="field">Priority<select name="priority">'+['low','normal','high','urgent'].map(x=>'<option value="'+x+'" '+(c.priority===x?'selected':'')+'>'+x+'</option>').join('')+'</select></label><div class="actions span3"><button class="primary" type="submit">Save case</button></div></form>'+(c.order_id?'<button class="secondary" id="caseOpenOrder" type="button">Open related order</button>':'')+'</div></section>';
    $('#caseBack').onclick=renderFulfillment;
    $('#careReplyForm').onsubmit=async e=>{e.preventDefault();const d=new FormData(e.currentTarget);if(!confirm('Send this support reply to the fan?'))return;try{const j=await api('fulfillment.php',{action:'case_reply',case_id:Number(c.id),message:d.get('message'),request_key:crypto.randomUUID?.()||Date.now().toString()});state.case=j.case;say('Support reply sent.');renderCase()}catch(x){alert(x.message)}};
    $('#caseStateForm').onsubmit=async e=>{e.preventDefault();const d=new FormData(e.currentTarget);if(!confirm('Update this support case state?'))return;try{const j=await api('fulfillment.php',{action:'case_update',case_id:Number(c.id),case:{status:d.get('status'),priority:d.get('priority')}});state.case=j.case;say('Case updated.');renderCase()}catch(x){alert(x.message)}};
    const oo=$('#caseOpenOrder');if(oo)oo.onclick=()=>openOrder(c.order_id);
  }
  window.SFFulfillmentAdmin={renderFulfillment,openOrder,openCase};
})();