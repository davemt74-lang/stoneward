<?php
declare(strict_types=1);
require __DIR__.'/api/bootstrap.php';
require_once __DIR__.'/api/homeserver-pair-core.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; script-src \'nonce-'.($nonce=base64_encode(random_bytes(18))).'\'; connect-src \'self\'; base-uri \'none\'; form-action \'self\'; frame-ancestors \'none\'');
$id=(string)($_GET['request_id']??'');
$request=sf_hs_pair_view($id);
$u=sf_current_user();
$csrf=$u?sf_user_csrf():'';
$valid=$request&&$request['status']==='pending';
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Authorize HomeServer · Stonefellow</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111417;color:#f5f3ef;font:16px/1.55 system-ui,Arial;padding:20px}.panel{width:min(100%,520px);background:#1c2228;border:1px solid #35404b;border-radius:18px;padding:30px}h1{margin:.3em 0}p{color:#c9d3dd}strong{color:#fff}.small{font-size:.9rem}.scope{background:#283541;border-radius:10px;padding:12px;color:#e3e9ee}button,a.action{display:inline-block;background:#ededed;border:0;border-radius:9px;color:#111;padding:11px 17px;cursor:pointer;font:inherit;text-decoration:none}button.secondary{background:#3c4753;color:#fff}button:disabled{opacity:.5;cursor:default}.controls{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}#msg{color:#f4d591}
</style></head><body><main class="panel">
<p class="small">STONEFELLOW · SECURE CONNECTION</p><h1>Connect VP3 HomeServer</h1>
<?php if(!$valid): ?>
<p>This connection request is unavailable, expired or already decided. Start a new connection from HomeServer.</p>
<?php elseif(!$u): ?>
<p>You must be <strong>logged into your own Stonefellow account</strong> to authorize HomeServer.</p>
<div class="scope">Requested access: <?= $e($request['scope']==='admin.presence.read'?'Administrator read-only presence':'Your purchased music library') ?></div>
<p>Sign in at Stonefellow and reopen this authorization link from HomeServer.</p><a class="action" href="/">Go to Stonefellow sign in</a>
<?php else: ?>
<p>Stonefellow account: <strong><?= $e($u['email']??'') ?></strong></p>
<p>HomeServer device: <strong><?= $e($request['label']?:'VP3 HomeServer') ?></strong></p>
<div class="scope"><?php if($request['scope']==='admin.presence.read'): ?>
Administrator connection: read-only catalog and publishing job counts. Does not share fan purchases, authorize payments or permit Kunaki publishing.
<?php else: ?>
Personal connection: read-only list of music purchased by THIS Stonefellow account. Does not grant administration or any other customer's library.
<?php endif; ?></div>
<p class="small">You can revoke access from your Stonefellow account. Connection expires after 30 days. Approval does not transfer your password to HomeServer.</p>
<div class="controls"><button id="approve" <?= $request['scope']==='admin.presence.read'&&($u['role']??'')!=='admin'?'disabled':'' ?>>Authorize HomeServer</button><button id="deny" class="secondary">Deny</button></div>
<p id="msg" aria-live="polite"></p>
<script nonce="<?= $e($nonce) ?>">
const id=<?= json_encode($id,JSON_HEX_TAG|JSON_HEX_AMP) ?>;
const csrf=<?= json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP) ?>;
async function decide(operation){
  document.querySelectorAll('button').forEach(x=>x.disabled=true);
  try{
    const r=await fetch('/api/homeserver-pair.php',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({operation,request_id:id})});
    const d=await r.json();
    if(!r.ok||!d.ok)throw Error('Authorization could not be completed. Reopen the HomeServer pairing request.');
    document.querySelector('#msg').textContent=operation==='approve'
      ?'Approved. Return to VP3 HomeServer to finish connecting this account.'
      :'Denied. HomeServer has not been granted access.';
  }catch(e){document.querySelector('#msg').textContent=e.message;}
}
document.querySelector('#approve').addEventListener('click',()=>decide('approve'));
document.querySelector('#deny').addEventListener('click',()=>decide('deny'));
</script>
<?php endif; ?></main></body></html>
