<?php
declare(strict_types=1);
define('SF_ROOT',sys_get_temp_dir().'/sf87b3-'.bin2hex(random_bytes(8)));
mkdir(SF_ROOT,0700,true);
$GLOBALS['catalog87']=[
 ['id'=>'owned','title'=>'Owned Ballad','mood'=>['reflective'],'themes'=>['night'],'podEligible'=>true,'private_master_audio'=>'KEEP_PRIVATE.wav'],
 ['id'=>'new','title'=>'New Ballad','mood'=>['quiet'],'themes'=>['home'],'podEligible'=>false],
 ['id'=>'hidden','title'=>'Hidden Ballad','state'=>'draft','mood'=>['reflective']],
];
function sf_catalog():array{return $GLOBALS['catalog87'];}
function sf_track_map():array{$out=[];foreach(sf_catalog() as $t)$out[$t['id']]=$t;return $out;}
function sf_seo_public_track(string $id):?array{
 $map=sf_track_map();return $id==='hidden'?null:($map[$id]??null);
}
function sf_privacy_personalization_enabled(int $id):bool{return false;}
function sf_library_purchase_rows(int $id):array{
 return $id===7?[['item_type'=>'track','track_id'=>'owned',
 'source_order_id'=>'SF-PRIVATE-AAA','metadata'=>['track_id'=>'owned']]]:[];
}
function sf_personalization_favorites(int $id):array{
 return ['tracks'=>$id===7?[['id'=>'new','title'=>'New Ballad']]:[],'releases'=>[]];
}
function sf_clean_text(mixed $x,int $max=120):string{return substr(trim((string)$x),0,$max);}
require dirname(__DIR__).'/api/agent-runtime.php';
$pass=0;
function ok87b3(bool $v,string $message):void{global $pass;if(!$v)throw new RuntimeException('87B3 FAIL '.$message);$pass++;echo "PASS $message\n";}
function invalid87b3(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}
$owned=sf_fan_commerce_decision(7,'owned',false);
ok87b3($owned['ownership']==='verified_paid'&&$owned['next_view']==='open_library',
 'Paid fan sees owned track and My Library route');
ok87b3($owned['quote_required']===false&&!$owned['checkout_executed'],
 'Existing paid ownership never creates another checkout');
$other=sf_fan_commerce_decision(8,'owned');
ok87b3($other['ownership']==='not_found_in_paid_library'&&$other['next_view']==='open_track',
 'Second fan cannot acquire first fan ownership');
$guest=sf_fan_commerce_decision(null,'owned');
ok87b3($guest['ownership']==='not_checked_guest'&&!$guest['saved'],
 'Guest identity never inherits prior fan ownership');
$vinyl=sf_fan_commerce_decision(7,'owned',true);
ok87b3($vinyl['next_view']==='open_builder'&&$vinyl['vinyl_eligible']&&
 !$vinyl['license_balance_verified'],'Vinyl eligibility never fabricates print-license balances');
$notVinyl=sf_fan_commerce_decision(7,'new',true);
ok87b3($notVinyl['next_view']==='open_track'&&!$notVinyl['vinyl_eligible'],
 'Vinyl-ineligible song cannot bypass builder gating');
ok87b3(invalid87b3(fn()=>sf_fan_commerce_decision(7,'hidden')),
 'Draft/unreleased track never exposed as a purchase recommendation');
$brief=sf_fan_commerce_agent_brief($owned);
$serialized=json_encode($brief);
ok87b3(!str_contains($serialized,'KEEP_PRIVATE')&&!str_contains($serialized,'SF-PRIVATE')&&
 !str_contains($serialized,'source_order_id'),
 'Agent model facts contain no private media or order identifiers');
ok87b3(!$brief['changes_executed']&&!$brief['license_balance_verified'],
 'Agent cannot claim it charged money or granted print rights');
$recommend=sf_fan_commerce_decision(7,'new');
ok87b3($recommend['saved']===true&&$recommend['ownership']!=='verified_paid',
 'Explicit favorite remains separate from paid ownership');
$policy=sf_agent_policy('purchase_request','Can I buy Owned Ballad?',[],7);
ok87b3($policy['action']['type']==='open_library'&&
 $policy['context_profile']==='commerce',
 'Original Customer Agent routes an already owned song to My Library');
$policyNew=sf_agent_policy('purchase_request','Can I buy New Ballad?',[],7);
ok87b3($policyNew['action']['type']==='open_view'&&
 $policyNew['action']['view']==='track'&&
 $policyNew['action']['id']==='new',
 'Agent navigates to unowned song instead of pretending it is already in cart');
$policyVinyl=sf_agent_policy('purchase_request','Can I buy Owned Ballad on vinyl?',[],7);
ok87b3($policyVinyl['action']['view']==='builder'&&
 str_contains($policyVinyl['text'],'license'),
 'Vinyl guidance goes to existing rights-checking Builder');
$policyGuest=sf_agent_policy('purchase_request','Buy Owned Ballad',[],null);
ok87b3($policyGuest['action']['view']==='track'&&
 !str_contains($policyGuest['text'],'already own'),
 'Unauthenticated customer Agent cannot disclose signed-in purchases');
$policyUnknown=sf_agent_policy('purchase_request','Buy Hidden Ballad',[],7);
ok87b3($policyUnknown['action']['view']==='cart'&&
 !str_contains($policyUnknown['text'],'Hidden Ballad'),
 'Unknown private song falls back safely to generic cart navigation');
ok87b3(sf_agent_local_route('Can I buy Owned Ballad?')==='purchase_request',
 'Customer message reaches existing purchase route');
$runtime=file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$ui=file_get_contents(dirname(__DIR__).'/assets/js/fan-discovery.js');
ok87b3(str_contains($runtime,'sf_fan_commerce_agent_brief(sf_fan_commerce_decision('),
 'Configured commerce LLM receives only bounded buyer information');
ok87b3(str_contains($ui,'data-fan-buy-help')&&str_contains($ui,'host.ask('),
 'Guided purchase card uses existing Customer Agent chat instead of a parallel agent');
ok87b3(!str_contains(file_get_contents(dirname(__DIR__).'/api/fan-commerce-advisor.php'),'sf_agent_action_execute')
 &&!str_contains(file_get_contents(dirname(__DIR__).'/api/fan-commerce-advisor.php'),'sf_quote('),
 'Agent adviser never executes actions or invents financial quote');
echo "87B3 Agent conversion: $pass assertions green\n";
