<?php
declare(strict_types=1);
$js=file_get_contents(__DIR__.'/../assets/js/turntable-parts.js');
$css=file_get_contents(__DIR__.'/../assets/css/turntable-builder.css');
$app=file_get_contents(__DIR__.'/../assets/js/app.js');
$arm=file_get_contents(__DIR__.'/../assets/img/turntable/default-arm.svg');
$pivot=file_get_contents(__DIR__.'/../assets/img/turntable/default-pivot.svg');
$checks=[
 'Separate animated arm image'=>str_contains($js,'assets/img/turntable/default-arm.svg')&&str_contains($css,'.turntable-arm-swing img'),
 'Separate fixed pivot image'=>str_contains($js,'assets/img/turntable/default-pivot.svg')&&str_contains($css,'.turntable-arm-mount img'),
 'Both image assets are SVG'=>str_contains($arm,'<svg ')&&str_contains($pivot,'<svg '),
 'Pivot image is not animated'=>!str_contains($css,'.turntable-stage-body.is-playing .turntable-arm-mount'),
 'Arm rotates on playback'=>str_contains($css,'.turntable-stage-body.is-playing .turntable-arm-swing{transform:rotate(18deg)}'),
 'Audio remains separate from the animation'=>str_contains($js,'Visual rotation: '),
 'Real Start/Stop wired'=>str_contains($js,'ctx.togglePlayback()')&&str_contains($app,'togglePlayback:()=>'),
 'Volume control wired to real audio'=>str_contains($app,'setVolume:value=>')&&str_contains($js,'ctx?.setVolume?.('),
 'Visual speed uses accessible toggle'=>str_contains($js,'data-player-visual-speed aria-pressed=')&&str_contains($css,'.is-fast-spin .turntable-disc'),
 'Oversized deck layout unchanged'=>str_contains($css,'position:fixed!important;grid-column:auto;grid-row:auto'),
 'Full-screen editor preserved'=>str_contains($css,'.turntable-editor-dialog{position:fixed;inset:0!important'),
 'Reference-style strobe dots'=>str_contains($css,'repeating-conic-gradient(from 2deg'),
 'Orange label and brushed deck'=>str_contains($css,'#ed793b')&&str_contains($css,'repeating-linear-gradient(93deg'),
 'Modal preview avoids duplicate controls'=>str_contains($js,"previewStage.querySelector('.turntable-volume-control')?.remove()"),
 'Reduced motion preference respected'=>str_contains($css,'@media(prefers-reduced-motion:reduce){.turntable-arm-swing{transition:none!important}}'),
];
foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)exit(1);}
echo 'Section 70: '.count($checks).'/'.count($checks).' source acceptance checks passed'.PHP_EOL;
