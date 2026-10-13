<?php
declare(strict_types=1);
// Run the real agent intent/policy against two isolated signed-in accounts.
function sf_turntable_owned(int $uid):array{return $uid===7?['base-nocturne','base-studio']:['base-studio'];}
function sf_turntable_catalog():array{return [
 ['id'=>'base-studio','name'=>'Studio Base','price_cents'=>0],
 ['id'=>'base-nocturne','name'=>'Nocturne Base','price_cents'=>1199],
 ['id'=>'arm-precision','name'=>'Precision Tonearm','price_cents'=>299],
 ['id'=>'effect-aurora','name'=>'Aurora Effects','price_cents'=>499]
];}
require __DIR__.'/../api/agent-runtime.php';
function checked72d(bool $ok,string $label):void{if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$queries=['What player parts do I own?','Show my player upgrades','Can I get tonearm upgrades?','Upgrade my record player'];
foreach($queries as $q)checked72d(sf_agent_local_route($q)==='player_parts_support','Player question classified: '.$q);
$mine=sf_agent_policy('player_parts_support','Which parts do I own?',[],7);
checked72d($mine['context_profile']==='account','Scoped to account profile');
checked72d($mine['action']['type']==='open_view'&&$mine['action']['view']==='builder','Only existing builder navigation offered');
checked72d(str_contains($mine['text'],'Nocturne Base'),'Authenticated account sees owned module name');
checked72d(str_contains($mine['text'],'1 premium player upgrade'),'Owned component count correct');
checked72d(str_contains($mine['text'],'Precision Tonearm'),'Unowned available preview described');
checked72d(str_contains($mine['text'],'checkout confirmation'),'Agent cannot silently purchase upgrades');
$other=sf_agent_policy('player_parts_support','Which parts do I own?',[],8);
checked72d(!str_contains($other['text'],'Owned: Nocturne Base'),'Other account cannot see private ownership');
$guest=sf_agent_policy('player_parts_support','My player upgrades',[],null);
checked72d(str_contains($guest['text'],'Sign in')&&!str_contains($guest['text'],'Nocturne Base'),'Anonymous request requires sign-in without private inventory');
checked72d(sf_agent_local_route('Where is my vinyl order?')==='order_support','Existing order tracking route retained');
checked72d(sf_agent_local_route('Show the cart')!=='player_parts_support','Other shopping intent remains separate');
echo "Section 72D: 14/14 agent access and routing checks passed\n";
