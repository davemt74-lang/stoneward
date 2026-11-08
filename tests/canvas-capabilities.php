<?php
declare(strict_types=1);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db():PDO{return $GLOBALS['pdo'];}
require dirname(__DIR__).'/api/canvas-capabilities.php';
$n=0;
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['n']++;echo 'PASS '.$label.PHP_EOL;}
function deny(callable $run,string $type,string $label):void{try{$run();}catch(Throwable $e){if($e instanceof $type){verify(true,$label);return;}throw $e;}throw new RuntimeException('Expected denial: '.$label);}
$cards=sf_canvas_capability_cards();$admin=['id'=>7,'role'=>'admin'];
verify(count($cards)===17,'Every existing canvas dataset has a capability card');
verify(count(array_unique(array_column($cards,'permission_id')))===17,'Permission IDs are stable and unique');
verify($cards['my_purchases']['requires_sign_in']&&!$cards['published_catalog']['requires_sign_in'],'Cards distinguish public and current-account scopes');
verify(!isset($cards['my_purchases']['handler'],$cards['my_purchases']['api_key']),'Cards do not expose handlers or credentials');
verify(sf_canvas_permission_policy()['revision']===1&&sf_canvas_permission_enabled('published_catalog'),'Legacy default preserves enabled behavior');
verify(!$pdo->query("SELECT name FROM sqlite_master WHERE name='canvas_permission_policy'")->fetchColumn(),'Ordinary delivery reads create no schema or policy rows');
$pdo->beginTransaction();verify(sf_canvas_permission_enabled('my_purchases'),'Default policy reads work inside existing transactions');
deny(fn()=>sf_canvas_permission_save($admin,1,['canvas.data.my_purchases'=>false]),RuntimeException::class,'Outer transaction is rejected before schema initialization');
verify($pdo->inTransaction()&&!$pdo->query("SELECT name FROM sqlite_master WHERE name='canvas_permission_policy'")->fetchColumn(),'Rejected mutation preserves outer transaction and creates no tables');
$pdo->rollBack();
deny(fn()=>sf_canvas_permission_save(['id'=>1,'role'=>'user'],1,['canvas.data.my_purchases'=>false]),DomainException::class,'Non-admin cannot change policy through core');
deny(fn()=>sf_canvas_permission_save($admin,1,['authentication'=>false]),InvalidArgumentException::class,'Core protections and unknown permissions cannot be disabled');
deny(fn()=>sf_canvas_permission_save($admin,1,['canvas.data.my_purchases'=>'false']),InvalidArgumentException::class,'Boolean permission values are mandatory');
$saved=sf_canvas_permission_save($admin,1,['canvas.data.my_purchases'=>false]);
verify($saved['policy']['revision']===2&&!sf_canvas_permission_enabled('my_purchases'),'Disabled permission persists in authoritative policy');
verify(sf_canvas_permission_enabled('my_tickets'),'Unchanged permission remains enabled');
verify(count($saved['history'])===1&&$saved['history'][0]['updated_by']===7&&$saved['history'][0]['changes']['canvas.data.my_purchases']['after']===false,'Audit stores actor, revision and before/after changes');
deny(fn()=>sf_canvas_permission_save($admin,1,['canvas.data.my_purchases'=>true]),UnexpectedValueException::class,'Stale administrator save cannot overwrite policy');
verify(!sf_canvas_permission_enabled('my_purchases'),'Rejected stale save leaves disablement intact');
$noChange=sf_canvas_permission_save($admin,2,['canvas.data.my_purchases'=>false]);verify($noChange['policy']['revision']===2&&count($noChange['history'])===1,'No-op save creates no duplicate history');
$saved=sf_canvas_permission_save($admin,2,['canvas.data.my_purchases'=>true]);verify(sf_canvas_permission_enabled('my_purchases')&&$saved['policy']['revision']===3,'Re-enable is explicit and versioned');
verify(!sf_canvas_permission_enabled('unknown'),'Unknown capabilities fail closed');
$pdo->beginTransaction();deny(fn()=>sf_canvas_permission_save($admin,3,['canvas.data.my_tickets'=>false]),RuntimeException::class,'Policy mutation cannot commit an outer caller transaction');$pdo->rollBack();
$libraries=array_column(sf_canvas_permission_admin_view()['capabilities'],null,'permission_id');
verify(($libraries['canvas.library.three']['kind']??null)==='library'&&
    ($libraries['canvas.library.color-thief']['kind']??null)==='library'&&
    sf_canvas_library_enabled('three')&&sf_canvas_library_enabled('color-thief'),'Pinned libraries appear as separate administrator controls');
$saved=sf_canvas_permission_save($admin,3,['canvas.library.three'=>false]);
verify($saved['policy']['revision']===4&&!sf_canvas_library_enabled('three')&&sf_canvas_library_enabled('color-thief')&&
    sf_canvas_permission_enabled('published_catalog')&&isset($saved['history'][0]['changes']['canvas.library.three']),
    'Library disablement is versioned, audited and independent from data permissions');
deny(fn()=>sf_canvas_permission_save($admin,4,['canvas.library.unregistered'=>true]),InvalidArgumentException::class,'Unknown library permissions fail closed');
$saved=sf_canvas_permission_save($admin,4,['canvas.library.three'=>true]);
verify($saved['policy']['revision']===5&&sf_canvas_library_enabled('three'),'Re-enabling a pinned library uses the same administrator policy');
echo "$n capability checks passed\n";
