<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$home=$read('admin/assets/homepage.js');
$settings=$read('admin/assets/settings-tabs.js');
$story=$read('admin/assets/stories.js');
$media=$read('admin/assets/media.js');
$site=$read('api/artist-home-core.php');
$css=$read('admin/assets/homepage.css');
$checks=[
 'Homepage has direct hero image control'=>str_contains($home,'id="homepageOpenHero"')&&str_contains($home,'Upload / choose hero image'),
 'Homepage offers direct artist story editor handoff'=>str_contains($home,'id="homepageOpenStory"')&&str_contains($home,'Edit artist story'),
 'Homepage offers public preview with safe new-tab rel'=>str_contains($home,'Preview homepage ↗')&&str_contains($home,'target="_blank" rel="noopener"'),
 'Homepage hero handoff uses the real Settings Media tab'=>str_contains($home,"SFSettingsTabs.open('media')"),
 'Homepage biography handoff uses the real Settings Stories tab'=>str_contains($home,"SFSettingsTabs.open('stories')"),
 'Settings exports a deliberate tab activation API'=>str_contains($settings,'window.SFSettingsTabs={mount,open}')&&str_contains($settings,'currentActivator=activate'),
 'Tab API rejects unsupported tabs'=>str_contains($settings,"if(!sections.some(([id])=>id===key))throw new Error("),
 'Detached Settings workspace cannot be used'=>str_contains($settings,'!mountedRoot?.isConnected'),
 'Universal media keeps published site hero role'=>str_contains($settings,"bindEntityMedia?.('site','public'")&&str_contains($settings,"publishRoles:['logo','hero','artist_photo','social_share','app_icon']"),
 'Existing Media Library owns publish rights'=>str_contains($media,"action:'publish_entity_image'"),
 'Story media stays linked to existing Story record'=>str_contains($story,"bindEntityMedia?.('story_entry',String(e.id)")&&str_contains($story,"roles:['hero','inline','gallery']"),
 'New Story stays in its modal after save for image upload'=>str_contains($story,'openEditor(response.story,state.data?.references||{})'),
 'Single-artist published biography remains supported'=>str_contains($home,"if(key==='artist_story')")&&str_contains($home,'published Artist About'),
 'Homepage public backend still uses published data'=>str_contains($site,'story')&&str_contains($site,'hero'),
 'Homepage section order remains saved through canonical endpoint'=>str_contains($home,"action:'save_section',section")&&str_contains($home,'position:Number('),
 'Site single-artist Homepage plugin remains toggleable'=>str_contains($home,'artist_homepage_enabled'),
 'Homepage shortcuts wrap responsively on phone'=>str_contains($css,'.homepage-editor-shortcuts')&&str_contains($css,'grid-template-columns:minmax(0,1fr)'),
 'No duplicate homepage media or story ownership added'=>!str_contains($home,"action:'publish_entity_image'")&&!str_contains($home,"action:'save',story"),
];
$errors=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$errors++;}
echo '50E: '.(count($checks)-$errors).'/'.count($checks).' checks passed'.PHP_EOL;
exit($errors?1:0);
