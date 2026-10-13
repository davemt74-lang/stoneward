<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$ui=$read('admin/assets/unified-ui.css');
$settings=$read('admin/assets/settings-tabs.css');
$stories=$read('admin/assets/stories.css');
$site=$read('admin/assets/admin.css');
$checks=[
 'Admin canvas adopts border-box sizing'=>str_contains($ui,'.admin-canvas{box-sizing:border-box}'),
 'No global horizontal table bleed'=>str_contains($ui,'overscroll-behavior-x:contain'),
 'Wide cards and sections have min-width zero'=>str_contains($ui,'.admin-canvas :is(.panel,.stats,.toolbar,.form-grid,'),
 'Long identifiers wrap within cards'=>str_contains($ui,'overflow-wrap:anywhere'),
 'Every form input has available width constraint'=>str_contains($ui,'.admin-canvas :is(input,select,textarea)'),
 'Dialog content scrolls vertically'=>str_contains($ui,'overflow-y:auto;overflow-x:hidden'),
 'Dialog cannot scroll outer page'=>str_contains($ui,'.admin-unified-form-open{overflow:hidden}'),
 'Phone dialogs occupy viewport height'=>str_contains($ui,'height:100dvh'),
 'Safe-area padding prevents phone home-bar collision'=>str_contains($ui,'env(safe-area-inset-bottom)'),
 'Header actions adapt to narrow screens'=>str_contains($ui,'grid-template-columns:repeat(auto-fit,minmax(min(100%,140px),1fr))'),
 'Extra-small phones get smaller gutters'=>str_contains($ui,'@media(max-width:380px)'),
 'Settings tabs scroll inside their own container'=>str_contains($settings,'overflow-x:auto;overscroll-behavior-x:contain'),
 'Settings tab content never scrolls main page horizontally'=>str_contains($settings,'.settings-tab-panel{max-width:100%;min-width:0;overflow-wrap:anywhere}'),
 'Story dialog retains mobile full-screen mode'=>str_contains($stories,'.story-modal{height:100dvh;max-height:100dvh'),
 'Sidebar navigation scrolls independently'=>str_contains($site,'.sidebar nav{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden'),
 'Dialog buttons expose keyboard focus styles'=>str_contains($ui,'.admin-unified-form-overlay button:focus-visible'),
];
$errors=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$errors++;}
echo '50D: '.(count($checks)-$errors).'/'.count($checks).' checks passed'.PHP_EOL;
exit($errors?1:0);
