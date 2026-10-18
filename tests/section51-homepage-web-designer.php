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
 ['id'=>'album','state'=>'published','public_visible'=>true,'title'=>'Published Album','release_date'=>'2026-01-01']
];}
function sf_commerce_products(bool $only=false):array{return [['id'=>'merch','slug'=>'merch','title'=>'Public Shirt','description'=>'Cotton','base_price_cents'=>2500,'compare_at_price_cents'=>0,'image'=>'/shirt.jpg','available'=>true,'featured'=>true]];}
function sf_commerce_product(string $id,bool $only=false):?array{return $id==='merch'?['id'=>$id,'available'=>true]:null;}
function sf_ticket_public_offers(?int $uid=null,string $show=''):array{return [['id'=>8,'title'=>'VIP','show_date'=>'2026-12-01','venue'=>'Venue','access'=>['allowed'=>false,'reason'=>'members_only'],'external_url'=>'']];}
require $root.'/api/artist-home-core.php';
$check=[];
$sections=sf_artist_home_sections(false);
$check['Original ten sections retained and new disabled blocks section added']=count($sections)===11&&!sf_artist_home_section('custom_blocks')['enabled'];
sf_artist_home_ensure_schema();
$check['Existing section survives repeated init']=sf_artist_home_section('hero')['heading']==='Music made to be lived with.';
$blocks=[
 ['id'=>'block_album1','type'=>'release','reference_id'=>'album','enabled'=>true,'layout'=>'wide','heading'=>'New Album'],
 ['id'=>'block_ticket','type'=>'ticket','reference_id'=>'8','enabled'=>true],
 ['id'=>'block_merch1','type'=>'merch','reference_id'=>'merch','enabled'=>true],
 ['id'=>'block_story1','type'=>'text','enabled'=>true,'heading'=>'Artist story'],
 ['id'=>'block_draft1','type'=>'release','reference_id'=>'draft','enabled'=>false],
];
function invalid(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException $e){return true;}}
$check['Reject unknown block types']=invalid(fn()=>sf_artist_home_normalize_config('custom_blocks',['blocks'=>[['type'=>'html','enabled'=>true]]]));
$check['Reject duplicate block identities']=invalid(fn()=>sf_artist_home_normalize_config('custom_blocks',['blocks'=>[$blocks[0],$blocks[0]]]));
$check['Reject more than 24 blocks']=invalid(fn()=>sf_artist_home_normalize_config('custom_blocks',['blocks'=>array_fill(0,25,$blocks[0])]));
$check['Reject enabled blocks without reference']=invalid(fn()=>sf_artist_home_normalize_config('custom_blocks',['blocks'=>[['type'=>'release','enabled'=>true]]]));
$check['Reject unpublished release when enabled']=invalid(fn()=>sf_artist_home_save_section(['section_key'=>'custom_blocks','enabled'=>true,'config'=>['blocks'=>[['type'=>'release','id'=>'private_block','reference_id'=>'draft','enabled'=>true]]]]));
$check['Reject nonpublic ticket and merch references']=invalid(fn()=>sf_artist_home_save_section(['section_key'=>'custom_blocks','enabled'=>true,'config'=>['blocks'=>[['type'=>'ticket','id'=>'missing_ticket','reference_id'=>'77','enabled'=>true]]]]))&&invalid(fn()=>sf_artist_home_save_section(['section_key'=>'custom_blocks','enabled'=>true,'config'=>['blocks'=>[['type'=>'merch','id'=>'missing_merch','reference_id'=>'inactive','enabled'=>true]]]]));
$saved=sf_artist_home_save_section(['section_key'=>'custom_blocks','enabled'=>true,'position'=>65,'config'=>['blocks'=>$blocks]]);
$check['Custom blocks persist in existing section row']=$saved['enabled']&&count($saved['config']['blocks'])===5;
$db->exec("UPDATE artist_home_sections SET enabled=0 WHERE section_key!='custom_blocks'");
$public=sf_artist_home_payload();
$list=$public['sections'][0]['data']??[];
$check['Public output includes only four enabled source items']=count($list)===4;
$check['Release item resolves published canonical record']=$list[0]['data']['id']==='album';
$check['Ticket item preserves access restriction']=$list[1]['data']['access']['allowed']===false&&$list[1]['data']['external_url']==='';
$check['Merch item projects only public storefront fields']=$list[2]['data']['title']==='Public Shirt'&&!isset($list[2]['data']['warehouse']);
$check['Editorial item retains safe custom copy']=$list[3]['heading']==='Artist story';
$check['Unpublished draft never leaks to public JSON']=!str_contains(json_encode($public),'Private Draft');
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$web=$read('admin/assets/homepage-web-editor.js');
$builder=$read('admin/assets/homepage-builder.js');
$home=$read('admin/assets/homepage.js');
$css=$read('admin/assets/homepage-web-editor.css');
$shell=$read('admin/index.php');
$app=$read('assets/js/app.js');
$publicBuildText=$read('stonefellow-v120.php');
$publicBuildParts=[];
$siteVersion=preg_match("/define\\('SF_BUILD', '([0-9]+\\.[0-9]+\\.[0-9]+)'\\)/",$publicBuildText,$publicBuildParts)
    ?$publicBuildParts[1]:'';
$checks=[
 'Designer entered from original Settings Homepage panel'=>str_contains($home,'homepageOpenDesigner')&&str_contains($home,'SFHomepageWebEditor?.open?.()'),
 'Main Admin nav replaced only in designer mode'=>str_contains($css,'#sidebar > :not(#homepageWebSidebar)')&&str_contains($web,"shell.classList.add('homepage-web-design-mode')"),
 'Original nav and Admin composer restored on Exit'=>str_contains($web,"shell.classList.remove('homepage-web-design-mode','homepage-editor-tools-open')"),
 'Designer preserves authenticated homepage forms and API'=>str_contains($web,'SFArtistHomeAdmin.renderInto(source)')&&str_contains($home,"api('homepage.php',{action:'save_section',section})"),
 'Moved forms reconnect to preview without cloning'=>str_contains($web,'SFHomepageBuilder.relocate({formsRoot:tools,previewRoot:stage})'),
 'Save all stops on first failed section'=>str_contains($home,'if(!success)return false;'),
 'Unsaved edits require exit confirmation'=>str_contains($web,'Discard unsaved homepage changes'),
 'Sidebar provides Add Block, Hero, Story and Theme controls'=>str_contains($web,'homepageWebAddBlock')&&str_contains($web,"switchToSettings('media')")&&str_contains($web,"switchToSettings('stories')")&&str_contains($web,"switchToSettings('theme')"),
 'Responsive preview supports all three devices'=>str_contains($css,'data-device=desktop')&&str_contains($css,'data-device=tablet')&&str_contains($css,'data-device=phone'),
 'Public render supports four block types and ticket CTA'=>str_contains($read('assets/js/artist-home-renderer.js'),"if(k==='custom_blocks')")&&str_contains($app,'data-home-ticket-offer')&&str_contains($app,'data-home-release')&&str_contains($app,'data-home-store'),
 'Block configuration and source references use original data'=>str_contains($builder,'getConfig')&&str_contains($read('admin/api/homepage.php'),"'tickets'=>\$tickets"),
 'Homepage designer does not add duplicate navigation routes'=>substr_count($shell,'data-view="')===31&&str_contains($shell,'data-view="team"')&&str_contains($shell,'data-view="production-review"'),
 'Release version advances all JS assets'=>$siteVersion!==''&&version_compare($siteVersion,'1.3.75','>=')
    &&str_contains($shell,"SF_ADMIN_BUILD','".$siteVersion."'")
    &&str_contains($read('service-worker.js'),'stonefellow-static-v'.$siteVersion),
 'Additive library schema target retained'=>(preg_match("/'database_schema_target'=>'([0-9.]+)'/",$read('version.php'),$schemaMatch)&&version_compare($schemaMatch[1],'1.3.48','>=')),
];
$check=array_merge($check,$checks);
$failed=0;foreach($check as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
echo 'Section 51: '.(count($check)-$failed).'/'.count($check).' checks passed'.PHP_EOL;
exit($failed?1:0);
