<?php
declare(strict_types=1);
$css=file_get_contents(__DIR__.'/../assets/css/turntable-builder.css');
$checks=[
 'Document page scrolls as one viewport'=>str_contains($css,'body.builder-workspace-active{height:auto;min-height:100%;overflow-x:hidden;overflow-y:auto}'),
 'No separate builder scroll zone'=>str_contains($css,'body.builder-workspace-active .builder-module{height:auto;min-height:100dvh;align-items:start;overflow:visible;'),
 'No left-column scroll container'=>str_contains($css,'.builder-left-rail{height:auto;min-height:0;max-height:none;overflow:visible;scrollbar-gutter:auto;'),
 'Right player fixed to viewport'=>str_contains($css,'.builder-turntable-stage{position:fixed!important;'),
 'Fixed stage has no scrollbar'=>str_contains($css,'max-height:none;overflow:clip;pointer-events:none;z-index:1;'),
 'Header and announcement top offsets'=>str_contains($css,'top:76px!important')&&str_contains($css,'has-announcement .builder-turntable-stage{top:108px!important'),
 'Proportioned landscape chassis'=>str_contains($css,'aspect-ratio:1.45;transform:translateY(-50%) rotate(0deg)'),
 'Platter fits landscape chassis'=>str_contains($css,'.turntable-platter{left:3%;top:50%;width:67%;aspect-ratio:1;'),
 'Tonearm pivot upper right'=>str_contains($css,'.turntable-tonearm{top:10%;left:89%;width:5%;height:58%;transform:rotate(47deg)'),
 'Chassis brand text removed'=>str_contains($css,'.turntable-deck-head,.turntable-deck-foot,.turntable-deck-serial{display:none}'),
 'Mobile retains its own scrolling and hidden player'=>str_contains($css,'@media(max-width:1023px)')&&str_contains($css,'.builder-turntable-stage{display:none!important}'),
];
foreach($checks as $label=>$pass){echo ($pass?'PASS ':'FAIL ').$label."\n";if(!$pass)exit(1);}
echo "Section 62G page scroll and fixed player: ".count($checks)."/".count($checks)." passed\n";
