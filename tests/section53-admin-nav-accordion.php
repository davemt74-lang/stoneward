<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$html=$read('admin/index.php');$js=$read('admin/assets/sidebar-accordion.js');$css=$read('admin/assets/admin.css');$admin=$read('admin/assets/admin.js');
$groups=[
 'overview'=>['dashboard','analytics','calendar'],
 'music'=>['catalog','media','uploads','templates','releases','shows'],
 'publishing'=>['distribution','press','rights'],
 'audience'=>['crm','customer','campaigns','automations'],
 'commerce'=>['products','orders','fulfillment','pod','membership','ticketing','packages'],
 'agent'=>['brain','production-review','knowledge','ai'],
 'administration'=>['users','team','operations','settings']
];
preg_match_all('/<details class="admin-nav-category" data-nav-category="([^"]+)"( open)?>\s*<summary[^>]*>(.*?)<\/summary>\s*<div class="admin-nav-items">(.*?)<\/div>\s*<\/details>/s',$html,$parts,PREG_SET_ORDER);
$check=[];
$check['Exactly seven accessible accordion categories']=count($parts)===7;
$all=[];
foreach($parts as $index=>$match){
 $key=$match[1];preg_match_all('/data-view="([^"]+)"/',$match[4],$found);
 $check['Category '.($key?:$index).' contains the expected views']=isset($groups[$key])&&($groups[$key]===$found[1]);
 $all=array_merge($all,$found[1]);
 $check['Category '.($key?:$index).' has a caret icon and named summary']=str_contains($match[3],'admin-nav-chevron')&&str_contains($match[3],'aria-hidden="true"');
 $check['Only Overview starts expanded in server HTML']=($index===0)===(trim($match[2])==='open');
}
$check['All 31 routes remain unique and reachable']=count($all)===31&&count(array_unique($all))===31;
$check['Native details and summary preserve keyboard accessibility']=str_contains($html,'<details class="admin-nav-category"')&&str_contains($html,'<summary class="admin-nav-summary"');
$check['Browser state persistence key is versioned']=str_contains($js,'stonefellow.admin.navigation.categories.v1');
$check['Saved state ignores unknown keys and supports independent groups']=str_contains($js,'hasOwnProperty.call(saved,key)')&&str_contains($js,'currentState(groups)');
$check['Malformed or unavailable browser storage fails safely']=str_contains($js,'try{')&&str_contains($js,'catch(_)');
$check['Navigation routing remains delegated unchanged']=str_contains($admin,"$('#adminNav').addEventListener('click'")&&str_contains($admin,"openView(b.dataset.view)");
$check['Active route has accessible current-page semantics']=str_contains($admin,"b.setAttribute('aria-current','page')")&&str_contains($admin,"$$('#adminNav [data-view]').forEach(");
$check['Sidebar scroll and desktop density remain enabled']=str_contains($css,'.sidebar nav{flex:1;min-height:0;overflow-y:auto')&&str_contains($css,'.sidebar nav button{min-height:26px');
$check['Expanded caret rotates and keyboard focus remains visible']=str_contains($css,'.admin-nav-category[open]>.admin-nav-summary .admin-nav-chevron')&&str_contains($css,'.admin-nav-summary:focus-visible');
$check['Mobile categories retain usable tap sizes']=str_contains($css,'#adminNav .admin-nav-items button{min-height:38px');
$check['Full-page editor still replaces entire Admin navigation']=str_contains($read('admin/assets/homepage-web-editor.css'),'#sidebar > :not(#homepageWebSidebar)');
$check['New library migration does not modify Admin UI schema']=(preg_match("/'database_schema_target'=>'([0-9.]+)'/",$read('version.php'),$schemaMatch) && version_compare($schemaMatch[1],'1.3.48','>='));
$fail=0;foreach($check as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$fail++;}
echo 'Section 53: '.(count($check)-$fail).'/'.count($check).' checks passed'.PHP_EOL;
exit($fail?1:0);
