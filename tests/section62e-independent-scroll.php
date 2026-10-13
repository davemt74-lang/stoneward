<?php
declare(strict_types=1);
$css=file_get_contents(__DIR__.'/../assets/css/turntable-builder.css');
$checks=[
 'Page scroll restored'=>str_contains($css,'body.builder-workspace-active{height:auto;overflow-y:auto}'),
 'Builder height follows content'=>str_contains($css,'body.builder-workspace-active .builder-module{height:auto;min-height:calc(100dvh - 64px)'),
 'Left rail has no private scroll container'=>str_contains($css,'.builder-left-rail{height:auto;min-height:0;max-height:none;overflow:visible;scrollbar-gutter:auto;'),
 'Turntable stays sticky'=>str_contains($css,'.builder-turntable-stage{position:sticky!important;top:76px!important;align-self:start;overflow:clip;'),
 'Announcement offset accounted for'=>str_contains($css,'has-announcement .builder-turntable-stage{top:108px!important'),
 'Rotated chassis orientation'=>str_contains($css,'transform:translate(-50%,-50%) rotate(90deg)'),
 'Reversed tonearm pivot'=>str_contains($css,'.turntable-tonearm{top:9%;left:19%;width:6%;height:27%;transform:rotate(-42deg)'),
 'No visible deck branding'=>str_contains($css,'.turntable-deck-head,.turntable-deck-foot,.turntable-deck-serial{display:none}'),
 'Mobile turntable stays hidden'=>str_contains($css,'.builder-turntable-stage{display:none!important}'),
];
foreach($checks as $label=>$pass){if(!$pass){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
