<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $limit=120):string{return substr(trim((string)$v),0,$limit);}
function sf_site_settings():array{return ['artist_homepage_enabled'=>true,'media_hero'=>'/hero.jpg'];}
function sf_release_rows():array{return [
 ['id'=>'draft','state'=>'draft','public_visible'=>false,'title'=>'Private Draft'],
 ['id'=>'album','state'=>'published','public_visible'=>true,'title'=>'Public Album','release_date'=>'2026-01-01']
];}
function sf_commerce_products(bool $only=false):array{return [['id'=>'merch','slug'=>'merch','title'=>'Shirt','available'=>true]];}
function sf_commerce_product(string $id,bool $only=false):?array{return $id==='merch'?['id'=>'merch','available'=>true]:null;}
function sf_ticket_public_offers(?int $uid=null,string $show=''):array{return [['id'=>8,'title'=>'VIP','show_date'=>'2026-12-01','venue'=>'Venue','access'=>['allowed'=>false]]];}
require $root.'/api/artist-home-core.php';
require $root.'/api/artist-home-drafts.php';
$check=[];$initial=sf_home_design_state();
$heroInitial=sf_artist_home_section('hero');
$check['Sticky chat is enabled for existing and new homepages']=!empty($heroInitial['config']['sticky_chat_enabled']);
$check['Sticky chat can be disabled without disabling the artist homepage']=sf_artist_home_normalize_config('hero',['sticky_chat_enabled'=>false])['sticky_chat_enabled']===false;
$check['First draft uses currently published snapshot']=$initial['revision']===0&&count($initial['sections'])===11;
$sections=$initial['sections'];$sections[0]['heading']='Unpublished visual edit';
$draft=sf_home_design_save($sections,0,$initial['base_hash']);
$check['Saving draft increments revision']=$draft['revision']===1;
$check['Saving draft leaves public homepage unchanged']=sf_artist_home_section('hero')['heading']==='Music made to be lived with.';
function fails(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}}
$check['Stale revision cannot overwrite newer draft']=fails(fn()=>sf_home_design_save($sections,0,$initial['base_hash']));
$check['Publishing requires current revision']=fails(fn()=>sf_home_design_publish(0,$initial['published_hash']));
$pub=sf_home_design_publish($draft['revision'],$draft['published_hash']);
$check['Publishing updates homepage atomically']=sf_artist_home_section('hero')['heading']==='Unpublished visual edit';
$check['Version history contains published snapshot']=count(sf_home_design_versions())===1&&$pub['version']>0;
$check['Published draft rebases revision and hash']=$pub['draft']['revision']===2&&$pub['draft']['base_hash']===$pub['draft']['published_hash'];
$backup=sf_home_design_restore($pub['version'],2,$pub['draft']['base_hash']);
$check['Restore only creates a private draft']=$backup['revision']===3&&sf_artist_home_section('hero')['heading']==='Unpublished visual edit';
$clean=sf_home_design_snapshot();$bad=$clean;foreach($bad as &$section){if($section['section_key']==='custom_blocks'){$section['enabled']=true;$section['config']=['blocks'=>[['id'=>'badblock1','type'=>'release','reference_id'=>'draft','enabled'=>true]]];}}unset($section);
$staged=sf_home_design_save($bad,3,$backup['base_hash']);
$before=sf_home_design_hash(sf_home_design_snapshot());
$check['Unpublished catalog references block publishing']=fails(fn()=>sf_home_design_publish($staged['revision'],$before));
$check['Failed publish rolls back all section changes']=sf_home_design_hash(sf_home_design_snapshot())===$before;
$recovered=sf_home_design_reset_to_live($staged['revision'],$staged['base_hash']);
$check['Draft reset safely rebases to live state']=$recovered['revision']===$staged['revision']+1&&$recovered['base_hash']===$recovered['published_hash'];
$check['Stale draft reset is rejected']=fails(fn()=>sf_home_design_reset_to_live($staged['revision'],$staged['base_hash']));
$check['Invalid section arrays are rejected']=fails(fn()=>sf_home_design_save([],4,$staged['base_hash']));
$read=static fn(string $f):string=>file_get_contents($root.'/'.$f);
$visual=$read('admin/assets/homepage-visual.js');$web=$read('admin/assets/homepage-web-editor.js');$api=$read('admin/api/homepage.php');
$publicApp=$read('assets/js/app.js');
$check['Public homepage toggles existing Agent composer without disabling player']=str_contains($publicApp,"home.chat_bar_enabled===false")&&str_contains($read('assets/css/site.css'),'artist-home-chat-hidden .chat-footer .chatbar')&&!str_contains($read('assets/css/site.css'),'artist-home-chat-hidden .footer-player');
$check['Designer preserves draft workflow when legacy Save Section is clicked']=str_contains($read('admin/assets/homepage.js'),'if(window.SFHomepageVisual?.active?.())return window.SFHomepageVisual.saveDraft()');
$check['Live homepage iframe fills responsive canvas width']=str_contains($read('admin/assets/homepage-web-editor.css'),'.homepage-visual-public-frame')&&str_contains($read('admin/assets/homepage-visual.js'),'frame.srcdoc');
$check['Homepage sidebar exposes sticky chat toggle']=str_contains($web,'homepageWebChatToggle')&&str_contains($read('admin/assets/homepage.js'),'sticky_chat_enabled');
$check['Canonical renderer loaded for both Admin and public pages']=str_contains($read('admin/index.php'),'artist-home-renderer.js')&&str_contains($read('stonefellow-v120.php'),'artist-home-renderer.js')&&str_contains($visual,'SFArtistHomeRenderer');
$check['Designer exposes private save and explicit publish controls']=str_contains($visual,'save_design_draft')&&str_contains($visual,'publish_design_draft')&&str_contains($visual,'Publish live');
$check['Canvas enables direct drag and keyboard reordering']=str_contains($visual,'ondragstart')&&str_contains($visual,"e.altKey")&&str_contains($visual,'moveCanvasSection');
$check['Editor provides explicit draft rebase after live conflicts']=str_contains($visual,'reset_design_draft')&&str_contains($api,"if(\$action==='reset_design_draft')");
$check['Designer supports inline editable copy and undoable version restore']=str_contains($visual,'contentEditable')&&str_contains($visual,'restore_design_version');
$check['Admin endpoint requires confirmation for publish']=str_contains($api,"if(empty(\$b['confirmed']))");
$check['Published-site code does not read design drafts']=!str_contains($read('api/artist-home-core.php'),'artist_home_design_draft');
$check['Admin build version is current']=str_contains($read('admin/index.php'),"SF_ADMIN_BUILD','1.3.65'");
$check['Service worker cache advanced']=str_contains($read('service-worker.js'),'stonefellow-static-v1.3.65');
$fail=0;foreach($check as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
echo 'Section 52: '.(count($check)-$fail).'/'.count($check).' checks passed'.PHP_EOL;
exit($fail?1:0);
