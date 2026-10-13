'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const js=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const php=fs.readFileSync(path.join(__dirname,'../api/customer-order.php'),'utf8');
const css=fs.readFileSync(path.join(__dirname,'../assets/css/site.css'),'utf8');
const begin=js.indexOf('  function orderVinylProductionHtml(data){');
const end=js.indexOf('  async function renderOrderDetail(id){',begin);
assert.ok(begin>0&&end>begin,'Actual customer production renderer located');
const escape=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const scope={esc:escape};vm.runInNewContext(js.slice(begin,end)+'\nthis.renderProduction=orderVinylProductionHtml;',scope);
const sample={order_id:'SF-20261013-ABC123',status:'in_production',events:[
 {at:'2026-10-13T12:00:00Z',status:'artwork_approved'},
 {at:'2026-10-13T12:30:00Z',status:'in_production'}]};
const html=scope.renderProduction(sample);
const malicious=scope.renderProduction({order_id:'SF-X',status:'<img src=x onerror=alert(1)>',events:[{status:'<script>alert(1)</script>',at:'2026-10-13T01:00:00Z'}]});
const checks=[
 ['Nil non-vinyl payload produces no section',scope.renderProduction(null)===''],
 ['Vinyl production stage visible',html.includes('Current stage: <strong>in production</strong>')],
 ['Both stages displayed',html.includes('artwork approved')&&html.includes('in production')],
 ['Events preserve chronological presentation',html.indexOf('artwork approved')<html.lastIndexOf('in production')],
 ['No duplicate internal stage scroll',css.includes('.order-vinyl-timeline{overflow:visible}')],
 ['Accessible section label',html.includes('aria-label="Vinyl production status"')],
 ['HTML escaped in production status',!malicious.includes('<img ')&&malicious.includes('&lt;img')],
 ['HTML escaped in event label',!malicious.includes('<script>')&&malicious.includes('&lt;script>')],
 ['Customer fetch uses authenticated endpoint',js.includes("'customer-order.php?id='")],
 ['Order ownership checked before reading vinyl',php.indexOf("if($orderUserId!==")<php.indexOf("sf_vinyl_customer_status(")],
 ['Response sends owner-scoped status',php.includes("'vinyl_production'=>$vinyl")],
 ['Order detail composes existing shipping, production and refunds',js.includes('orderShipmentHtml(ops.shipments||[])+orderVinylProductionHtml(j.vinyl_production)+orderRefundHtml(ops.refunds||[])')],
 ['Customer production data excludes admin notes',!php.includes("['admin_id']")&&!php.includes("['note']")],
 ['Mobile display uses its own responsive gutter',css.includes('@media(max-width:720px){.order-vinyl-timeline')],
];
for(const [label,pass] of checks){assert.ok(pass,label);console.log('PASS '+label);}
console.log('Section 72E: '+checks.length+'/'+checks.length+' checks passed');
