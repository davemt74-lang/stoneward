'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const s=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const start=s.indexOf('  function checkoutQuoteBreakdown(q){');
const end=s.indexOf('  async function renderCheckout()',start);
assert.ok(start>0&&end>start,'Real checkout total helper exists');
const scope={money:n=>'$'+(Number(n)/100).toFixed(2)};
vm.runInNewContext(s.slice(start,end)+'\nthis.renderQuote=checkoutQuoteBreakdown;',scope);
const html=scope.renderQuote({subtotal_cents:5000,shipping_cents:800,tax_cents:0,member_discount_cents:500,campaign_discount_cents:200,total_cents:5100});
const checks=[
 ['Itemization includes subtotal',html.includes('Subtotal')&&html.includes('$50.00')],
 ['Shipping visible',html.includes('Shipping')&&html.includes('$8.00')],
 ['Tax visible',html.includes('Tax')&&html.includes('$0.00')],
 ['Member discount negative',html.includes('Member discount')&&html.includes('-$5.00')],
 ['Campaign discount negative',html.includes('Campaign discount')&&html.includes('-$2.00')],
 ['Server total visible',html.includes('Total due')&&html.includes('$51.00')],
 ['Zero discounts not shown',!scope.renderQuote({total_cents:100}).includes('Member discount')],
 ['Accessible total breakdown',html.includes('aria-label="Order total breakdown"')],
 ['Checkout reuses server quote',s.includes('async function renderCheckout(){await loadStore();let q;try{q=await quoteCart()}')],
 ['Checkout maintains server revalidation',s.includes("secureJsonPost('order.php',{cart:st.cart")],
 ['Customer can return to cart',s.includes("$('#returnToCart',canvas).onclick=renderCart")],
 ['Checkout error retains form and retry',s.includes("err.textContent=ex.message;btn.disabled=false")],
];
for(const [name,ok] of checks){assert.ok(ok,name);console.log('PASS '+name);}
console.log('Section 72A: '+checks.length+'/'+checks.length+' acceptance checks');
