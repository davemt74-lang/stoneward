'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const file=path.join(__dirname,'../assets/js/fan-discovery.js'),text=fs.readFileSync(file,'utf8');
const window={};
vm.runInNewContext(text,{window,console});
const ui=window.STONEFELLOW_FAN_DISCOVERY;
let count=0;function check(value,label){assert.ok(value,label);count++;console.log('PASS '+label)}
const data={path:'start_here',paths:[{id:'start_here',title:'Start here'}],summary:'Browse Stonefellow',
 privacy:'Preferences are optional',commercial_terms:'Final totals are calculated in checkout.',
 allow_feedback:true,items:[
 {track_id:'owned',title:'Already Purchased',release:'Album',reason:'Your saved music',
  owned:true,saved:true,save_available:true,digital:{action:'open_library'},vinyl:{eligible:true}},
 {track_id:'new',title:'Song <svg/onload=bad()>',release:'Release & Friends',reason:'Moods & more',
  owned:false,saved:false,save_available:true,digital:{action:'add_to_cart'},vinyl:{eligible:false}}
 ]};
const html=ui.view(data,true);
check(html.includes('data-fan-save="owned"')&&html.includes('Saved for later'),'Saved favorite state is distinct from purchase');
check(html.includes('data-fan-library="owned"'),'Owned track routes to My Library instead of repeat cart');
check(!html.includes('data-fan-cart="owned"'),'Paid ownership blocks duplicate digital purchase CTA');
check(html.includes('data-fan-cart="new"'),'Unowned song routes to cart review');
check(html.includes('data-fan-vinyl="owned"')&&!html.includes('data-fan-vinyl="new"'),'Only eligible vinyl songs get builder CTA');
check(html.includes('Final totals are calculated in checkout.'),'Quote and licensing disclaimer is visible');
check(!html.includes('<svg/onload')&&html.includes('&lt;svg/onload'),'Untrusted song markup is escaped');
check(html.includes('Release &amp; Friends')&&html.includes('Moods &amp; more'),'Album metadata and reasons are escaped');
check(!html.includes('$9.99')&&!html.includes('license available'),'No invented sale price or remaining vinyl rights');
const guest=ui.view({...data,allow_feedback:false,
 items:data.items.map(t=>({...t,save_available:false,owned:false,digital:{action:'add_to_cart'}}))},false);
check(!guest.includes('data-fan-save')&&!guest.includes('data-fan-dislike'),'Guests have no private preference writes');
check(guest.includes('data-fan-cart="owned"'),'Guest may review public digital purchase without signing in first');
check(!guest.includes('data-fan-queue'),'Guest cannot persist a user queue');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
check(app.includes("endpoint:'fan-purchase-journey.php'"),'Music page uses canonical 87B owner-bound projection');
check(app.includes("secureJsonPost('personalization.php'"),'Save uses current CSRF-checked favorites endpoint');
check(app.includes('addTrackToCart(track)')&&app.includes("navigate('cart')"),'Digital CTA uses existing cart and authoritative checkout quote');
check(app.includes("vinyl:()=>navigate('builder')"),'Vinyl CTA enters original protected licensing builder');
check(app.includes("library:()=>navigate('library')"),'Owned songs link to existing private library');
check(!text.includes("action:'approve'")&&!text.includes("action:'execute'"),'Discovery renderer cannot approve or execute Agent actions');
const php=fs.readFileSync(path.join(__dirname,'../api/fan-purchase-journey-core.php'),'utf8');
check(php.includes('sf_fan_purchase_saved_ids')&&php.includes('sf_library_purchase_rows'),'Genuine favorites and paid ownership are separate server signals');
check(php.includes('price_source')&&php.includes('cart_quote_only'),'Final price stays exclusively in live cart quote');
console.log('87B2: '+count+' UI and commerce integration checks green');
