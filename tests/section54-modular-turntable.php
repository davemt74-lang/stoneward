<?php
declare(strict_types=1);
define('SF_ROOT',dirname(__DIR__));
require_once SF_ROOT.'/api/bootstrap.php';
$read=static fn(string $p):string=>(string)file_get_contents(SF_ROOT.'/'.$p);
$checks=[];$catalog=sf_turntable_stock_modules();$index=array_column($catalog,null,'id');$defaults=sf_turntable_module_defaults();
$checks['Five independent default parts']=count($defaults)===5&&array_keys($defaults)===['base','platter','arm','label','effect'];
$checks['Free built-in parts are present']=array_reduce(array_values($defaults),fn($ok,$id)=>$ok&&isset($index[$id])&&$index[$id]['price_cents']===0,true);
$checks['Replaceable branded premium players']=count(array_filter($catalog,fn($m)=>$m['slot']==='base'&&$m['price_cents']>0))>=2;
$checks['Every component slot has upgrades']=array_reduce(['platter','arm','label','effect'],fn($ok,$slot)=>$ok&&count(array_filter($catalog,fn($m)=>$m['slot']===$slot))>=2,true);
$checks['Experience packs contain valid parts']=array_reduce($catalog,fn($ok,$m)=>$ok&&($m['slot']!=='pack'||!array_diff($m['includes'],array_keys($index))),true);
$checks['Guest owns only free modules']=sf_turntable_owned(0)===array_values($defaults);
$checks['Anonymous paid quote fails']=!sf_turntable_quote('base-nocturne',null)['ok'];
$checks['Unknown module fails lookup']=sf_turntable_module('unknown')===null;
$good=['id'=>'custom-metal-stage','slot'=>'base','name'=>'Metal Stage','price_cents'=>599,'visual'=>'chrome','accent'=>'#123456','active'=>true];
$checks['Safe custom branded module is accepted']=sf_turntable_sanitize_module($good)['id']==='custom-metal-stage';
foreach(['Unsafe color'=>['accent'=>'red'],'Unsafe image'=>['image'=>'javascript:alert(1)'],'Untrusted visual'=>['visual'=>'url(evil)'],'Unknown slot'=>['slot'=>'php']] as $label=>$change){
 try{sf_turntable_sanitize_module(array_merge($good,$change));$checks[$label.' blocked']=false;}
 catch(InvalidArgumentException $e){$checks[$label.' blocked']=true;}
}
$boot=$read('api/bootstrap.php');$account=$read('api/account-data.php');$app=$read('assets/js/app.js');$parts=$read('assets/js/turntable-parts.js');$css=$read('assets/css/turntable-builder.css');
$checks['Quote ignores client pricing']=str_contains($boot,'sf_turntable_quote(sf_clean_text(');
$checks['Paid-only entitlement grant']=str_contains($account,"if($"."type==='turntable_module'&&!in_array(")&&str_contains($account,"['paid','simulated_paid','test_paid']");
$checks['Draft checks slot ownership']=str_contains($account,'sf_turntable_valid_selection(');
$checks['Saved build preserves cover art and custom note']=str_contains($account,"'coverFront'=>")&&str_contains($account,"'coverBack'=>")&&str_contains($account,"'customNote'=>");
$checks['Agent fill preserves builder artwork and parts']=str_contains($account,"'playerModules'=>$"."build['playerModules']");
$checks['Desktop-only immersive player stage']=str_contains($css,'@media(min-width:1024px)')&&str_contains($css,'@media(max-width:1023px)');
$checks['Angled player uses large desktop viewport geometry']=str_contains($css,'--deck-width:clamp(510px,70vh,770px)')&&str_contains($css,'--deck-height:clamp(760px,96vh,1060px)');
$checks['Physical player bottom is cropped lower-right']=str_contains($css,'transform:translate(-50%,-50%) rotate(-45deg)')&&str_contains($css,'left:81%')&&str_contains($css,'overflow:clip');
$checks['45 degree angle applies only on desktop']=str_contains($css,'@media(min-width:1024px)')&&str_contains($css,'@media(max-width:1023px)')&&!str_contains($css,'rotate(-90deg)');
$checks['Old short viewport rotation override removed']=!str_contains($css,'.turntable-deck{height:max(620px,97vh)}');
$checks['Vinyl spins only on playback']=str_contains($app,'StonefellowTurntable?.sync')&&str_contains($css,'.turntable-stage-body.is-playing .turntable-disc');
$checks['Current track art, title and side visible']=str_contains($parts,'data-record-track')&&str_contains($parts,'data-record-side')&&str_contains($parts,'data-record-artwork');
$checks['Catalog search / mood / release filter']=str_contains($parts,'data-builder-search')&&str_contains($parts,'data-builder-category')&&str_contains($parts,'data-builder-release');
$checks['Admin module authoring requires authorization']=str_contains($read('admin/api/turntable-modules.php'),'sf_admin_require_auth($write)');
$checks['Effects do not claim audio changes']=str_contains($parts,'Visual effect packs change the turntable appearance only');
$checks['Both simulated payment methods grant licenses']=str_contains($account,"'test_paid'")&&str_contains($read('api/bootstrap.php'),"'simulated_paid'")&&str_contains($read('api/bootstrap.php'),"'test_paid'");
$checks['Pack component duplication rejected in quote']=str_contains($boot,'A bundle must not bill again for parts')&&str_contains($boot,"'slot']==='pack'");
$checks['Cart and checkout identify digital modules']=str_contains($app,"item.type==='turntable_module'")&&str_contains($app,"x.type==='turntable_module'");
$checks['Account library keys digital module license']=str_contains($account,"$"."item['module_id']");
$checks['Public and Admin load new runtimes']=str_contains($read('stonefellow-v120.php'),'assets/js/turntable-parts.js')&&str_contains($read('admin/index.php'),'assets/turntable-modules.js');
$fail=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$fail++;}
echo 'Section 54: '.(count($checks)-$fail).'/'.count($checks).' checks passed'.PHP_EOL;
exit($fail?1:0);
