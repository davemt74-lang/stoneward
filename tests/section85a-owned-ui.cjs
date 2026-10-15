/* Section 85A: stand-alone website renderer and interaction acceptance */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const win={};
vm.runInNewContext(fs.readFileSync('assets/js/owned-music.js','utf8'),{window:win});
const wallet=win.StonefellowOwnedMusic;
assert.ok(wallet&&typeof wallet.render==='function'&&typeof wallet.bind==='function');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const called=[],buttons={};
const ctx={
 esc,canvas:{},trackById:id=>id==='song-1'?{id,audio:'preview.mp3'}:null,
 $$:selector=>buttons[selector]||[],
 play:(track,position,from)=>called.push(['play',track.id,position,from]),
 navigate:(page,args)=>called.push(['navigate',page,args.id])
};
const owned={status:'ready',tracks:[
  {id:'song-1',title:'My <Song>',can_play:true,download_ready:true,
   vinyl_print_rights:{available:2,granted:3,reserved_or_used:1,packages:[{licenses:2,price_cents:500}]}},
  {id:'song-2',title:'Other Song',can_play:false,download_ready:false,
   vinyl_print_rights:{available:0,granted:0,reserved_or_used:0,packages:[]}}
]};
const html=wallet.render(ctx,owned,'');
assert.ok(html.includes('My &lt;Song&gt;')&&!html.includes('My <Song>'));
assert.ok(html.includes('fan-owned-download.php?id=song-1'));
assert.ok(!html.includes('fan-owned-download.php?id=song-2'));
assert.ok(html.includes('2 vinyl print licenses available'));
assert.ok(html.includes('1 reserved/used')&&html.includes('3 granted'));
assert.ok(html.includes('data-owned-licenses="song-1"'));
assert.ok(!html.includes('data-owned-licenses="song-2"'));
assert.ok(!wallet.render(ctx,owned,'not found').includes('data-owned-play'));
assert.ok(wallet.render(ctx,{status:'error',error:'<unsafe>'}).includes('&lt;unsafe&gt;'));
assert.ok(wallet.render(ctx,{status:'loading'}).includes('Checking your Stonefellow purchases'));
buttons['[data-owned-play]']=[{dataset:{ownedPlay:'song-1'}}];
buttons['[data-owned-licenses]']=[{dataset:{ownedLicenses:'song-1'}}];
const retry={};buttons['[data-owned-retry]']=[retry];
let retried=0;
wallet.bind(ctx,()=>{retried++});
buttons['[data-owned-play]'][0].onclick();
buttons['[data-owned-licenses]'][0].onclick();
retry.onclick();
assert.deepEqual(called,[['play','song-1',0,'owned'],['navigate','track','song-1']]);
assert.equal(retried,1);
console.log('85A first-party wallet renderer and actions: 12+ checks passed');
