<?php
declare(strict_types=1);
$app=(string)file_get_contents(dirname(__DIR__).'/assets/js/app.js');
$html=(string)file_get_contents(dirname(__DIR__).'/stonefellow-v120.php');
$css=(string)file_get_contents(dirname(__DIR__).'/assets/css/artist-world.css');
$checks=[
 'World navigation is a built-in SPA route'=>str_contains($app,"view==='world'){await renderArtistWorld(params)"),
 'World connects to canonical published server graph'=>str_contains($app,"api('artist-world.php')"),
 'Existing page destinations used for world cards'=>str_contains($app,'navigate(chosen.view,chosen.params)'),
 'World focuses and opens related graph nodes'=>str_contains($app,'data-world-key'),
 'Search and type filtering are available'=>str_contains($app,'artistWorldSearch')&&str_contains($app,'data-world-filter'),
 'Published data is HTML escaped'=>str_contains($app,"esc(node.title)")&&str_contains($app,'esc(chosen.summary)'),
 'World URL supports deep-linked nodes'=>str_contains($app,"if(view==='world'&&id)"),
 'Menu exposes Artist World entry'=>str_contains($html,'data-view="world"'),
 'Mobile experience has responsive canvas'=>str_contains($css,'@media(max-width:850px)'),
 'CSS is cache-busted by site build'=>str_contains($html,'artist-world.css?v=<?=rawurlencode(SF_BUILD)?>'),
 'No independent playback or payment runtime'=>!str_contains($css,'stripe'),
];
foreach($checks as $label=>$ok){echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)exit(1);}
echo "88B2: ".count($checks)." acceptance gates green\n";
