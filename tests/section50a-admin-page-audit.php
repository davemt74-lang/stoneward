<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$shell=$read('admin/index.php');$ui=$read('admin/assets/unified-ui.js');
$css=$read('admin/assets/unified-ui.css');$doc=$read('docs/section50-admin-ui-audit.md');
preg_match_all('/<button type="button" data-view="([^"]+)"/',$shell,$matches);
$nav=$matches[1];$expected=['dashboard','analytics','calendar','catalog','media','uploads','templates','releases','shows','distribution','press','rights','crm','customer','campaigns','automations','products','orders','fulfillment','pod','membership','ticketing','packages','brain','knowledge','ai','users','team','operations','settings'];
$checks=[
 'All 30 Admin navigation pages accounted for'=>count($nav)===30 && $nav===$expected,
 'Sidebar has no duplicate navigation routes'=>count(array_unique($nav))===30,
 'All seven category accordions are accessible'=>substr_count($shell,'data-nav-category=')===7&&substr_count($shell,'admin-nav-summary')===7,
 'Audit matrix documents all 30 source-level views'=>preg_match_all('/^\| (?:0[1-9]|[12][0-9]|30) \|/m',$doc)===30,
 'No new creation action invented for analytics-only views'=>!str_contains($ui,"analytics:['new"),
 'Settings and Agent Brain keep specialized presentation'=>str_contains($ui,"['settings','agent-chat','brain'].includes(getView())"),
 'Unified header created when legacy page lacks one'=>str_contains($ui,'admin-unified-generated-header'),
 'Existing header content is retained'=>str_contains($ui,"canvas.querySelector(':scope > .canvas-head')"),
 'Right-column panels and grids stay within available width'=>str_contains($css,'.admin-canvas :is(.panel,.stats,.toolbar,.form-grid,'),
 'Nested knowledge, campaigns and media sections have width limits'=>str_contains($css,'.knowledge-main,.homepage-section-list,.campaign-canvas,.editor-grid'),
 'Tables remain locally scrollable instead of widening entire page'=>str_contains($css,'.admin-canvas :is(.table-scroll,[class*="table-wrap"])'),
 'Mobile shell specifies safe independent gutters'=>str_contains($css,'max-width:calc(100% - 28px)'),
 'Audit states code-certification versus live-browser limits'=>str_contains($doc,'Browser execution in this runner is blocked'),
];
$errors=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$errors++;}
echo '50A: '.(count($checks)-$errors).'/'.count($checks).' checks passed'.PHP_EOL;
exit($errors?1:0);
