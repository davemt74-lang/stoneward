<?php
declare(strict_types=1);
require __DIR__.'/api/bootstrap.php';
require_once __DIR__.'/api/homeserver-link-core.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$nonce=base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-".$nonce."'; style-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
$user=sf_current_user();$csrf=$user?sf_user_csrf():'';
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HomeServer Connections · Stonefellow</title>
<style>*{box-sizing:border-box}body{min-height:100vh;margin:0;padding:24px;display:grid;place-items:center;background:#101417;color:#f2f5f6;font:16px/1.5 system-ui,Arial}main{max-width:720px;width:100%;padding:28px;border:1px solid #334151;border-radius:18px;background:#1c2229}p,small{color:#c6d0d9}.item{padding:15px 0;border-bottom:1px solid #43515e;display:flex;justify-content:space-between;align-items:center;gap:16px}button,.button{font:inherit;border:0;border-radius:8px;background:#f3f3f3;color:#111;padding:9px 14px;cursor:pointer}a{color:#bcdffc}strong{color:white}.danger{background:#554044;color:white}</style></head><body><main>
<h1>Connected HomeServers</h1>
<?php if(!$user): ?>
<p>Sign in to Stonefellow to see and revoke HomeServer permissions granted by your account.</p>
<a href="/">Return to Stonefellow sign in</a>
<?php else: ?>
<p>Signed in as <strong><?= $e($user['email']??'') ?></strong>. HomeServer connections expire automatically; you may revoke access at any time.</p>
<div id="links" role="status">Loading your connections…</div><p><a href="/">Back to Stonefellow</a></p>
<script nonce="<?= $e($nonce) ?>">
const csrf=<?= json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP) ?>,box=document.querySelector('#links');
async function api(action,id){
const opts=action?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action,id})}:{credentials:'same-origin'};
const r=await fetch('/api/homeserver-link.php',opts),d=await r.json();
if(!r.ok||!d.ok)throw Error('HomeServer connection request failed.');return d;
}
async function load(){
box.replaceChildren();try{
const data=await api(),links=data.links||[];
if(!links.length){box.textContent='No authorized HomeServer connections.';return}
for(const item of links){const el=document.createElement('div');el.className='item';
const copy=document.createElement('div');const head=document.createElement('strong');
head.textContent=item.label||'VP3 HomeServer';const line=document.createElement('p');
line.textContent=(item.scope==='admin.presence.read'?'Admin read-only presence':'Personal music library')+' · '+item.state+' · expires '+item.expires_at;
copy.append(head,line);el.append(copy);
if(item.state==='active'){const button=document.createElement('button');button.className='danger';button.textContent='Revoke access';button.onclick=async()=>{if(!confirm('Revoke this HomeServer connection immediately?'))return;try{await api('revoke',item.id);await load()}catch(e){alert(e.message)}};el.append(button)}
box.append(el)}
}catch(e){box.textContent=e.message}}
load();
</script>
<?php endif; ?>
</main></body></html>
