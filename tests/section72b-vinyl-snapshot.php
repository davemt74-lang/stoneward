<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-72b-'.bin2hex(random_bytes(5));
mkdir($root.'/config',0770,true);mkdir($root.'/data',0770,true);
copy(__DIR__.'/../config/store.php',$root.'/config/store.php');
file_put_contents($root.'/data/catalog.json',json_encode([
 ['id'=>'one','title'=>'Opening Song','duration'=>189,'podEligible'=>true,'audio'=>'opening.wav'],
 ['id'=>'two','title'=>'Finale','duration'=>201,'podEligible'=>true,'audio'=>'finale.wav']
]));
define('SF_ROOT',$root);
require __DIR__.'/../api/bootstrap.php';
function check72b(bool $pass,string $what):void{if(!$pass){fwrite(STDERR,"FAIL $what\n");exit(1);}echo "PASS $what\n";}
$build=['title'=>'Collectors Edition','theme'=>'desert','format'=>'vinyl','A'=>['one'],'B'=>['two'],
 'coverFront'=>'midnight','coverBack'=>'analog','customNote'=>'For your favorite song',
 'playerModules'=>['base'=>'base-premium','arm'=>'arm-studio','platter'=>'unknown-platter']];
$q=sf_quote([['type'=>'custom_media','format'=>'vinyl','builder'=>$build]],'',null);
check72b($q['ok']===true,'Existing quote accepts custom vinyl');
$item=$q['items'][0];$b=$item['builder'];
check72b($b['title']==='Collectors Edition'&&$b['customNote']==='For your favorite song','Dedication preserved');
check72b($b['coverFront']==='midnight'&&$b['coverBack']==='analog','Both covers preserved');
check72b($b['sides']['A']['tracks'][0]['id']==='one'&&$b['sides']['B']['tracks'][0]['id']==='two','Exact ordered tracks preserved');
check72b($b['sides']['A']['tracks'][0]['audio_master']==='opening.wav','Track master frozen in quote');
check72b(isset($b['playerModules']['base'])&&isset($b['playerModules']['arm']),'Visual player component choices recorded');
check72b($b['playerModules']['base']!== 'base-premium','Unowned paid component never inserted into guest order');
check72b($b['playerModules']['platter']!=='unknown-platter','Unsupported visual component rejected');
$recipient=['name'=>'Fan','shipping'=>['line1'=>'123 Sample Lane','country'=>'US']];
$pod=sf_build_pod_handoffs('SF-20261013-ABC123',$q,$recipient);
check72b(count($pod)===1,'Existing POD handoff created once');
$record=json_decode(file_get_contents($root.'/storage/pod/'.$pod[0]['file']),true);
check72b($record['artwork_front']==='midnight'&&$record['artwork_back']==='analog','Both cover selections reach production handoff');
check72b($record['dedication_note']==='For your favorite song','Personal note reaches production handoff');
check72b($record['sides']['B']['tracks'][0]['id']==='two','Master track order reaches handoff');
check72b($record['customer_player_preview']['visual_only']===true,'Digital player options explicitly marked visual-only, never manufacturing instructions');
check72b($record['customer_player_preview']['components']===$b['playerModules'],'Player preview reflects validated order');
check72b($record['recipient']['line1']==='123 Sample Lane','Shipping snapshot preserved');
$source=file_get_contents(__DIR__.'/../api/bootstrap.php');
check72b(str_contains($source,'sf_validate_builder($builder,$format,$userId??0)'),'Ownership checked with server authenticated user');
unlink($root.'/storage/pod/'.$pod[0]['file']);rmdir($root.'/storage/pod');rmdir($root.'/storage');unlink($root.'/data/catalog.json');rmdir($root.'/data');unlink($root.'/config/store.php');rmdir($root.'/config');rmdir($root);
echo "Section 72B: 16/16 acceptance checks\n";
