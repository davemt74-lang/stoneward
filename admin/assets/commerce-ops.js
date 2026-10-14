(()=>{'use strict';
const ctx=window.STONEFELLOW_ADMIN_CONTEXT,esc=ctx.esc;
let active='all',cache=null;
const money=cents=>'$'+(Number(cents||0)/100).toFixed(2);
const labels={payment_pending_review:'Payment awaiting review',postpayment_incomplete:'Post-payment tasks incomplete',
 refund_pending:'Refund awaiting confirmation',fulfillment_pending:'Ready for fulfillment',refund_completed:'Refund completed'};
function renderRows(data){
 const items=(data.items||[]).filter(o=>{
  if(active==='all')return true;
  if(active==='followups')return o.open_followups>0;
  if(active==='payments')return o.flags.some(x=>['payment_pending_review','postpayment_incomplete'].includes(x));
  if(active==='refunds')return o.flags.some(x=>x.startsWith('refund_'));
  if(active==='fulfillment')return o.flags.includes('fulfillment_pending');
  return true;
 });
 const content=items.map(o=>{
  const chips=o.flags.map(x=>'<span class="commerce-op-chip">'+esc(labels[x]||x)+'</span>').join('');
  const followups=(o.followups||[]).filter(x=>x.status==='open').map(f=>
    '<div class="commerce-op-followup"><div><strong>'+esc(f.priority==='high'?'High priority':'Follow-up')+'</strong>'+
    (f.due_date?' · due '+esc(f.due_date):'')+'<p>'+esc(f.note)+'</p></div>'+
    '<button type="button" class="secondary" data-resolve-order="'+esc(o.id)+'" data-resolve-id="'+esc(f.id)+'">Done</button></div>'
  ).join('');
  const external='<button type="button" class="secondary" data-open-orders="'+esc(o.id)+'">Open Orders</button>';
  return '<article class="panel commerce-op-order"><div class="panel-title"><div><h3>'+esc(o.id)+' · '+esc(o.customer_name||'Customer')+
    '</h3><p>'+esc(o.created_at||'')+' · '+money(o.total_cents)+' · '+esc(o.status)+'</p></div>'+
    '<div class="actions">'+external+(o.payment_provider==='stripe'?
    '<button type="button" class="secondary" data-payment-check="'+esc(o.id)+'">Check Stripe</button>':'')+'</div></div>'+
    '<div class="commerce-op-chips">'+chips+(o.open_followups?'<span class="commerce-op-chip">Open follow-ups: '+o.open_followups+'</span>':'')+'</div>'+
    (followups?'<div class="commerce-op-followups">'+followups+'</div>':'')+
    '<details class="commerce-op-new-note"><summary>Add customer follow-up</summary><form data-note-order="'+esc(o.id)+'">'+
    '<label class="field">Follow-up note<textarea name="note" rows="2" maxlength="600" required placeholder="What needs attention?"></textarea></label>'+
    '<div class="commerce-op-form-row"><label class="field">Priority<select name="priority"><option value="normal">Normal</option><option value="high">High</option></select></label>'+
    '<label class="field">Due date<input name="due_date" type="date"></label>'+
    '<button class="primary" type="submit">Save follow-up</button></div></form></details></article>';
 }).join('');
 return content||'<section class="panel"><p>No orders need attention in this view.</p></section>';
}
function draw(data){
 cache=data;const counts=data.summary||{};
 const filters=[
 ['all','Attention',data.total_attention||0],
 ['payments','Payments',counts.payment_attention||0],
 ['refunds','Refunds',counts.refund_attention||0],
 ['fulfillment','Fulfillment',counts.fulfillment_pending||0],
 ['followups','Follow-ups',counts.open_followups||0]];
 const pills=filters.map(([key,label,count])=>'<button class="'+(active===key?'primary':'secondary')+
 '" type="button" data-commerce-filter="'+key+'">'+esc(label)+' ('+Number(count)+')</button>').join('');
 ctx.canvas.innerHTML=ctx.head('COMMERCE','Commerce Operations','Payment exceptions, fulfillment, refunds and customer follow-ups.', 
 '<button type="button" class="secondary" id="commerceBack">Back to Orders</button>')+
 '<section class="panel"><div class="panel-title"><div><h2>Operations overview</h2><p>Only real customer orders. Internal Stripe test orders are excluded. No automated refunds or shipment changes.</p></div>'+
 '<button class="secondary" type="button" id="commerceRefresh">Refresh queue</button></div>'+
 '<div class="commerce-op-stats"><div><small>Customer orders</small><strong>'+Number(counts.customer_orders||0)+'</strong></div>'+
 '<div><small>Payment attention</small><strong>'+Number(counts.payment_attention||0)+'</strong></div>'+
 '<div><small>Refunds pending</small><strong>'+Number(counts.refund_attention||0)+'</strong></div>'+
 '<div><small>Fulfillment pending</small><strong>'+Number(counts.fulfillment_pending||0)+'</strong></div>'+
 '<div><small>Open follow-ups</small><strong>'+Number(counts.open_followups||0)+'</strong></div></div>'+
 '<div class="actions commerce-op-filters">'+pills+'</div><p class="status-line" role="status" id="commerceStatus">'+
 Number(data.total_attention||0)+' customer orders need attention; displaying '+Number(data.showing||0)+'.</p></section>'+
 '<div class="commerce-op-list">'+renderRows(data)+'</div>';
 const back=ctx.canvas.querySelector('#commerceBack');if(back)back.onclick=()=>ctx.openView('orders');
 ctx.canvas.querySelector('#commerceRefresh').onclick=load;
 ctx.canvas.querySelectorAll('[data-commerce-filter]').forEach(b=>b.onclick=()=>{active=b.dataset.commerceFilter;draw(cache)});
 ctx.canvas.querySelectorAll('[data-open-orders]').forEach(b=>b.onclick=()=>ctx.openView('orders'));
 ctx.canvas.querySelectorAll('[data-payment-check]').forEach(b=>b.onclick=async()=>{
   if(!window.confirm('Verify Stripe status for '+b.dataset.paymentCheck+'? This reads Stripe and may reconcile confirmed events; it does not issue a charge or refund.'))return;
   b.disabled=true;
   try{
    const r=await ctx.api('commerce-ops.php',{action:'check_stripe',order_id:b.dataset.paymentCheck});
    await load();const status=ctx.canvas.querySelector('#commerceStatus');
    if(status)status.textContent='Stripe check: '+(r.reconciliation?.checkout||'reviewed');
   }catch(e){const status=ctx.canvas.querySelector('#commerceStatus');if(status)status.textContent=e.message;b.disabled=false;}
 });
 ctx.canvas.querySelectorAll('form[data-note-order]').forEach(f=>f.onsubmit=async e=>{
   e.preventDefault();const b=f.querySelector('button[type=submit]');b.disabled=true;const fields=new FormData(f);
   const key=f.dataset.requestKey||(f.dataset.requestKey='commerce-'+String(Date.now())+'-'+Math.random().toString(36).slice(2,12));
   try{await ctx.api('commerce-ops.php',{action:'add_followup',order_id:f.dataset.noteOrder,
        note:String(fields.get('note')||''),priority:String(fields.get('priority')||'normal'),
        due_date:String(fields.get('due_date')||''),request_key:key});await load();}
   catch(error){const status=ctx.canvas.querySelector('#commerceStatus');if(status)status.textContent=error.message;b.disabled=false;}
 });
 ctx.canvas.querySelectorAll('[data-resolve-id]').forEach(b=>b.onclick=async()=>{
    b.disabled=true;try{await ctx.api('commerce-ops.php',{action:'resolve_followup',
      order_id:b.dataset.resolveOrder,followup_id:b.dataset.resolveId});await load();}
    catch(e){const status=ctx.canvas.querySelector('#commerceStatus');if(status)status.textContent=e.message;b.disabled=false;}
 });
}
async function load(){
 ctx.canvas.innerHTML='<section class="panel">Loading customer commerce operations…</section>';
 try{const r=await ctx.api('commerce-ops.php');draw(r.operations||{});}
 catch(e){ctx.canvas.innerHTML='<section class="panel"><h2>Commerce operations unavailable</h2><p>'+esc(e.message)+'</p>'+
  '<button class="secondary" id="commerceRetry">Retry</button></section>';
 ctx.canvas.querySelector('#commerceRetry').onclick=load;}
}
window.SFCommerceOperations={render:load};
})();
