<?php
declare(strict_types=1);
$css=file_get_contents(__DIR__.'/../assets/css/turntable-builder.css');
$checks=[
 'Desktop editor constrained to viewport'=>str_contains($css,'body.builder-workspace-active .builder-module{height:calc(100dvh - 64px - 96px)'),
 'Outer desktop builder does not scroll'=>str_contains($css,'min-height:0;overflow:hidden;align-items:stretch;padding-bottom:0'),
 'Left column independently scrolls'=>str_contains($css,'.builder-left-rail{min-height:0;max-height:100%;overflow-y:auto;overflow-x:hidden'),
 'Right turntable stage remains stationary'=>str_contains($css,'.builder-turntable-stage{position:relative!important;top:auto!important;align-self:stretch;overflow:clip;min-height:0;height:100%;max-height:100%'),
 'Announcement header height accommodated'=>str_contains($css,'has-announcement .builder-module{height:calc(100dvh - 96px - 96px)'),
 'Mobile turntable stays hidden'=>str_contains($css,'.builder-turntable-stage{display:none!important}'),
];
foreach($checks as $label=>$pass){if(!$pass){fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
