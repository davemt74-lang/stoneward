<?php
declare(strict_types=1);

define('SF_ROOT', __DIR__);
require_once __DIR__.'/api/bootstrap.php';
require_once __DIR__.'/api/migrations.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow', true);

function sf_upgrade_h(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function sf_upgrade_csrf(): string {
    sf_user_session();
    if(empty($_SESSION['sf_upgrade_csrf']))$_SESSION['sf_upgrade_csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['sf_upgrade_csrf'];
}
function sf_upgrade_csrf_valid(string $v): bool {return $v!==''&&hash_equals(sf_upgrade_csrf(),$v);}
function sf_upgrade_admin_from_session(): ?array {
    sf_user_session();$id=(int)($_SESSION['sf_user_id']??0);if($id<1)return null;
    try{
        $q=sf_db()->prepare('SELECT id,email,display_name,role,status FROM users WHERE id=? LIMIT 1');$q->execute([$id]);$u=$q->fetch();
        if(!$u||($u['status']??'')!=='active'||($u['role']??'')!=='admin')return null;
        return $u;
    }catch(Throwable $e){return null;}
}
function sf_upgrade_admin_login(string $email,string $password): ?array {
    $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password==='')return null;
    $q=sf_db()->prepare('SELECT id,email,display_name,role,status,password_hash FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$u=$q->fetch();
    if(!$u||($u['status']??'')!=='active'||($u['role']??'')!=='admin'||!password_verify($password,(string)$u['password_hash']))return null;
    sf_user_session();session_regenerate_id(true);$_SESSION['sf_user_id']=(int)$u['id'];$_SESSION['sf_csrf']=bin2hex(random_bytes(32));$_SESSION['sf_upgrade_csrf']=bin2hex(random_bytes(32));
    unset($u['password_hash']);return $u;
}
function sf_upgrade_lock_acquire() {
    $path=SF_ROOT.'/storage/upgrade-running.lock';$dir=dirname($path);if(!is_dir($dir))@mkdir($dir,0770,true);
    $fh=@fopen($path,'c+');if(!$fh)throw new RuntimeException('Could not open the upgrade lock file.');
    if(!flock($fh,LOCK_EX|LOCK_NB)){fclose($fh);throw new RuntimeException('Another Stonefellow database upgrade appears to be running.');}
    ftruncate($fh,0);fwrite($fh,'Stonefellow upgrade '.gmdate('c').' pid '.getmypid()."\n");fflush($fh);return $fh;
}
function sf_upgrade_lock_release($fh): void {
    if(is_resource($fh)){flock($fh,LOCK_UN);fclose($fh);}@unlink(SF_ROOT.'/storage/upgrade-running.lock');
}
function sf_upgrade_format_bytes(int $bytes): string {
    if($bytes<=0)return '0 B';$units=['B','KB','MB','GB'];$i=0;$n=(float)$bytes;while($n>=1024&&$i<count($units)-1){$n/=1024;$i++;}return number_format($n,$i?1:0).' '.$units[$i];
}

$installed=false;$fatal='';$admin=null;$message='';$messageType='';$runResult=null;$backup=null;$integrity=null;
try{$installed=sf_installed();if($installed){sf_db()->query('SELECT 1');$admin=sf_upgrade_admin_from_session();}}
catch(Throwable $e){$fatal=$e->getMessage();}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$fatal===''){
    $action=(string)($_POST['action']??'');
    if(!sf_upgrade_csrf_valid((string)($_POST['csrf']??''))){$message='The upgrade security token expired. Reload this page and try again.';$messageType='error';}
    elseif($action==='login'){
        try{$admin=sf_upgrade_admin_login((string)($_POST['email']??''),(string)($_POST['password']??''));if(!$admin){usleep(250000);$message='Administrator email or password is incorrect.';$messageType='error';}}
        catch(Throwable $e){$message=$e->getMessage();$messageType='error';}
    }elseif($action==='logout'){
        sf_user_session();unset($_SESSION['sf_user_id'],$_SESSION['sf_csrf'],$_SESSION['sf_upgrade_csrf']);session_regenerate_id(true);$admin=null;$message='Upgrade session signed out.';$messageType='info';
    }elseif($action==='run'){
        $admin=sf_upgrade_admin_from_session();
        if(!$admin){$message='Administrator authentication is required.';$messageType='error';}
        else{
            $lock=null;
            try{
                $lock=sf_upgrade_lock_acquire();
                $statusBefore=sf_migration_status();
                if($statusBefore['drift']>0)throw new RuntimeException('Migration checksum drift was detected. The database was not changed.');
                if($statusBefore['pending']===0){$message='Database is already current. No migrations were run.';$messageType='success';}
                else{
                    $preflight=sf_migration_core_preflight();
                    if(!$preflight['ok'])throw new RuntimeException('Preflight failed: '.implode(' ',(array)$preflight['errors']));
                    $backup=sf_upgrade_backup_database();
                    $runResult=sf_migration_run_all((int)$admin['id'],(string)$backup['file']);
                    $integrity=sf_migration_integrity_report();
                    if(!$integrity['ok'])throw new RuntimeException('Migrations completed, but final database integrity verification found missing schema items.');
                    try{sf_auth_issue_persistent_session((int)$admin['id']);}catch(Throwable $e){}
                    try{sf_log_admin_action((int)$admin['id'],'database_upgrade_completed','database',SF_DB_SCHEMA_TARGET,['backup'=>$backup['file'],'run_id'=>$runResult['run_id']??null]);}catch(Throwable $e){}
                    $message='Database upgrade completed successfully. Stonefellow schema is current at '.SF_DB_SCHEMA_TARGET.'.';$messageType='success';
                }
            }catch(Throwable $e){
                $message=$e->getMessage();$messageType='error';
                try{if($admin)sf_log_site_event('error','database_upgrade',$e->getMessage());}catch(Throwable $ignore){}
            }finally{sf_upgrade_lock_release($lock);}
        }
    }
}

$status=null;$preflight=null;$runs=[];
if($installed&&$fatal===''&&$admin){
    try{$status=sf_migration_status();$preflight=sf_migration_core_preflight();$runs=sf_upgrade_recent_runs(12);}
    catch(Throwable $e){$fatal=$e->getMessage();}
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stonefellow — Database Upgrade</title>
<style>
:root{--bg:#090807;--panel:#0f0e0c;--panel2:#151310;--ink:#f1ede6;--muted:#9a9388;--line:#29251f;--line2:#3d362c;--accent:#c9a96d;--good:#7baa7b;--bad:#c8776e;--warn:#c7a160}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.shell{width:min(1080px,calc(100% - 32px));margin:0 auto;padding:52px 0 80px}.top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:34px}.brand{font-size:.68rem;font-weight:800;letter-spacing:.27em}.eyebrow{font-size:.61rem;color:var(--accent);letter-spacing:.18em;text-transform:uppercase;margin-top:32px}.top h1{font:400 clamp(2.4rem,6vw,4.8rem)/.96 Georgia,serif;margin:8px 0 12px}.lede{max-width:720px;color:var(--muted);font-size:.94rem}.links{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.button,a.button,button{appearance:none;border:1px solid var(--line2);background:transparent;color:var(--ink);padding:10px 14px;min-height:40px;text-decoration:none;font:inherit;cursor:pointer}.button.primary,button.primary{background:var(--ink);color:var(--bg);border-color:var(--ink)}button:disabled{opacity:.4;cursor:not-allowed}.panel{border:1px solid var(--line);background:var(--panel);padding:22px;margin:14px 0}.panel h2{font:400 1.45rem/1.1 Georgia,serif;margin:0 0 6px}.panel>p,.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.stat{border:1px solid var(--line);padding:14px;background:var(--panel2)}.stat strong{display:block;font:400 1.35rem Georgia,serif}.stat span{display:block;color:var(--muted);font-size:.68rem;margin-top:3px}.notice{padding:13px 15px;border-left:2px solid var(--accent);background:#12100d;color:var(--muted);margin:14px 0}.message{padding:14px 16px;border:1px solid var(--line2);margin:16px 0}.message.success{border-color:#39523b;background:#0e160f}.message.error{border-color:#693e39;background:#170f0e}.message.info{border-color:#4f493d}.login{max-width:520px}.field{display:grid;gap:6px;margin:14px 0}.field span{color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:.08em}input{width:100%;border:1px solid var(--line2);background:#0b0a09;color:var(--ink);padding:12px;font:inherit}table{width:100%;border-collapse:collapse;margin-top:14px}th,td{text-align:left;border-bottom:1px solid var(--line);padding:11px 8px;vertical-align:top}th{font-size:.61rem;color:var(--muted);letter-spacing:.08em;text-transform:uppercase}.pill{display:inline-flex;padding:3px 8px;border-radius:999px;border:1px solid var(--line2);font-size:.62rem;text-transform:uppercase;letter-spacing:.05em}.pill.applied,.pill.pass{border-color:#39523b;color:#9ec49f}.pill.pending{color:var(--warn)}.pill.drift,.pill.error,.pill.failed{border-color:#693e39;color:#df9890}.pill.skip{color:var(--muted)}.checks{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px}.check{display:flex;justify-content:space-between;gap:18px;border-bottom:1px solid var(--line);padding:8px 0}.check small{color:var(--muted)}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:18px}.run-result{margin-top:20px}.foot{margin-top:38px;color:#6f6960;font-size:.68rem}.code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;color:#c8c0b5}.empty{padding:18px 0;color:var(--muted)}
@media(max-width:760px){.shell{width:min(100% - 22px,1080px);padding-top:28px}.top{display:block}.links{justify-content:flex-start;margin-top:18px}.grid{grid-template-columns:1fr 1fr}.checks{grid-template-columns:1fr}table{font-size:.78rem}.hide-mobile{display:none}}
</style>
</head>
<body>
<main class="shell">
<div class="top">
  <div>
    <div class="brand">STONEFELLOW</div>
    <div class="eyebrow">Database lifecycle</div>
    <h1>Database Upgrade</h1>
    <p class="lede">Back up the installed database, apply only missing Stonefellow migrations in order, and verify the finished schema before returning the site to normal operation.</p>
  </div>
  <div class="links"><a class="button" href="./">Public site</a><a class="button" href="admin/">Admin</a></div>
</div>

<?php if($message!==''): ?><div class="message <?=sf_upgrade_h($messageType)?>"><?=sf_upgrade_h($message)?></div><?php endif; ?>

<?php if(!$installed): ?>
<section class="panel"><h2>Stonefellow is not installed</h2><p>This upgrader only works on an existing Stonefellow database. The database configuration file could not be found.</p></section>
<?php elseif($fatal!==''): ?>
<section class="panel"><h2>Database connection error</h2><p><?=sf_upgrade_h($fatal)?></p></section>
<?php elseif(!$admin): ?>
<section class="panel login">
  <h2>Administrator authentication</h2>
  <p>Database upgrades require an active Stonefellow Administrator. This login intentionally uses only the original account fields, so it still works before newer account migrations have been applied.</p>
  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?=sf_upgrade_h(sf_upgrade_csrf())?>">
    <input type="hidden" name="action" value="login">
    <label class="field"><span>Email</span><input type="email" name="email" autocomplete="username" required></label>
    <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
    <div class="actions"><button class="primary" type="submit">Authenticate</button></div>
  </form>
</section>
<?php else: ?>
<div class="grid">
  <div class="stat"><strong><?=sf_upgrade_h((string)(sf_db_config()['driver']??'—'))?></strong><span>Database driver</span></div>
  <div class="stat"><strong><?=sf_upgrade_h((string)($status['target']??SF_DB_SCHEMA_TARGET))?></strong><span>Target schema</span></div>
  <div class="stat"><strong><?=number_format((int)($status['pending']??0))?></strong><span>Pending migrations</span></div>
  <div class="stat"><strong><?=($status['current']??false)?'Current':'Upgrade'?></strong><span>Database state</span></div>
</div>

<section class="panel">
  <h2>Preflight</h2>
  <p>Stonefellow will not begin an upgrade unless the installed database, account foundation, backup path, and disk space pass these checks.</p>
  <div class="checks">
  <?php foreach((array)($preflight['checks']??[]) as $c): ?>
    <div class="check"><span><?=sf_upgrade_h((string)$c['name'])?></span><small><?=($c['ok']??false)?'PASS':'FAIL'?> · <?=sf_upgrade_h((string)$c['detail'])?></small></div>
  <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <h2>Migration plan</h2>
  <p>Already-applied migrations are skipped. A migration checksum mismatch stops the upgrade rather than silently changing history.</p>
  <table>
    <thead><tr><th>Migration</th><th>Introduced</th><th>Description</th><th>Status</th><th class="hide-mobile">Applied</th></tr></thead>
    <tbody>
    <?php foreach((array)($status['migrations']??[]) as $m): ?>
      <tr>
        <td class="code"><?=sf_upgrade_h((string)$m['id'])?></td>
        <td><?=sf_upgrade_h((string)$m['app_version'])?></td>
        <td><?=sf_upgrade_h((string)$m['description'])?></td>
        <td><span class="pill <?=sf_upgrade_h((string)$m['status'])?>"><?=sf_upgrade_h((string)$m['status'])?></span></td>
        <td class="hide-mobile"><?=sf_upgrade_h((string)($m['applied_at']??'—'))?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if(($status['drift']??0)>0): ?><div class="notice">Migration checksum drift is present. The automatic runner is disabled until the changed migration is reviewed.</div><?php endif; ?>
  <form method="post" onsubmit="return confirm('Back up the Stonefellow database and run all pending migrations now?');">
    <input type="hidden" name="csrf" value="<?=sf_upgrade_h(sf_upgrade_csrf())?>">
    <input type="hidden" name="action" value="run">
    <div class="actions">
      <button class="primary" type="submit" <?=(!$preflight['ok']||($status['current']??false)||($status['drift']??0)>0)?'disabled':''?>>Back up &amp; run upgrade</button>
      <?php if($status['current']??false): ?><span class="pill applied">Database is current</span><?php endif; ?>
    </div>
  </form>
</section>

<?php if($backup): ?>
<section class="panel"><h2>Backup created</h2><p class="code"><?=sf_upgrade_h((string)$backup['file'])?></p><p><?=sf_upgrade_h(sf_upgrade_format_bytes((int)$backup['bytes']))?> · <?=sf_upgrade_h(strtoupper((string)$backup['driver']))?></p></section>
<?php endif; ?>

<?php if($runResult): ?>
<section class="panel run-result">
  <h2>Upgrade result</h2>
  <table><thead><tr><th>Migration</th><th>Description</th><th>Status</th><th>Execution</th></tr></thead><tbody>
  <?php foreach((array)$runResult['results'] as $r): ?>
  <tr><td class="code"><?=sf_upgrade_h((string)$r['id'])?></td><td><?=sf_upgrade_h((string)$r['description'])?></td><td><span class="pill <?=sf_upgrade_h((string)$r['status'])?>"><?=sf_upgrade_h((string)$r['status'])?></span></td><td><?=number_format((int)$r['execution_ms'])?> ms</td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>

<?php if($integrity): ?>
<section class="panel"><h2>Final integrity verification</h2><p><?=($integrity['ok']??false)?'All required v1.2 schema objects are present.':'One or more required schema objects are missing.'?></p><div class="checks">
<?php foreach((array)$integrity['checks'] as $c): ?><div class="check"><span><?=sf_upgrade_h((string)$c['name'])?></span><small><?=($c['ok']??false)?'PASS':'FAIL'?> · <?=sf_upgrade_h((string)$c['detail'])?></small></div><?php endforeach; ?>
</div></section>
<?php endif; ?>

<section class="panel">
  <h2>Recent upgrade runs</h2>
  <?php if(!$runs): ?><div class="empty">No recorded upgrade runs yet.</div>
  <?php else: ?><table><thead><tr><th>Started</th><th>Target</th><th>Status</th><th>Backup</th><th>Completed</th></tr></thead><tbody>
  <?php foreach($runs as $r): ?><tr><td><?=sf_upgrade_h((string)$r['started_at'])?></td><td><?=sf_upgrade_h((string)$r['app_version'])?></td><td><span class="pill <?=sf_upgrade_h((string)$r['status'])?>"><?=sf_upgrade_h((string)$r['status'])?></span></td><td class="code"><?=sf_upgrade_h((string)($r['backup_file']?:'—'))?></td><td><?=sf_upgrade_h((string)($r['completed_at']?:'—'))?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>

<form method="post">
  <input type="hidden" name="csrf" value="<?=sf_upgrade_h(sf_upgrade_csrf())?>">
  <input type="hidden" name="action" value="logout">
  <div class="actions"><a class="button primary" href="admin/">Return to Admin</a><button type="submit">Sign out of upgrader</button></div>
</form>
<?php endif; ?>

<div class="foot">Stonefellow database upgrader · target schema <?=sf_upgrade_h(SF_DB_SCHEMA_TARGET)?> · safe to revisit after the database is current.</div>
</main>
</body>
</html>
