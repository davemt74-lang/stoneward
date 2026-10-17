<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE photo_galleries(id INTEGER PRIMARY KEY,slug TEXT,status TEXT)');
$db->exec("INSERT INTO photo_galleries VALUES(1,'live','published'),(2,'private','draft')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_gallery_ensure_schema():void{}
function sf_gallery_get(int|string $id,bool $publicOnly=false):?array{
 $q=sf_db()->prepare('SELECT * FROM photo_galleries WHERE id=?');$q->execute([(int)$id]);return $q->fetch()?:null;
}
function sf_media_asset(string|int $id):?array{
 if(in_array($id,['M'.str_repeat('A',20),'M'.str_repeat('B',20)],true))return ['uuid'=>$id,'category'=>'image','extension'=>'jpg'];
 return null;
}
function sf_gallery_public_list():array {
 $photo=static fn(string $id,bool $public,bool $download,int $dim):array=>[
 'asset_id'=>$id==='M'.str_repeat('A',20)?1:2,'uuid'=>$id,'title'=>'Album portrait',
 'alt_text'=>'Stonefellow photo','url'=>'api/media.php?id='.$id,
 'thumb_url'=>'api/media.php?id='.$id.'&variant=thumb',
 'public_visible'=>$public,'download_allowed'=>$download,'width'=>$dim,'height'=>$dim,'credit'=>'Stonefellow'
 ];
 return [
 ['id'=>1,'slug'=>'live','title'=>'Behind the Album','summary'=>'Published story',
 'cover'=>$photo('M'.str_repeat('A',20),true,true,2400),
 'photos'=>[$photo('M'.str_repeat('B',20),true,false,2400),$photo('M'.str_repeat('A',20),true,true,900)],
 'entities'=>[['type'=>'release','id'=>'album1']]],
 ['id'=>2,'slug'=>'private','title'=>'Private Studio','summary'=>'Unpublished',
 'cover'=>$photo('M'.str_repeat('B',20),true,true,2400),'photos'=>[],'entities'=>[]]
 ];
}
require dirname(__DIR__).'/api/artist-world-vinyl.php';
$checks=0;
function gate88(bool $good,string $why):void{
 global $checks;if(!$good)throw new RuntimeException('88A failed: '.$why);
 $checks++;echo "PASS $why\n";
}
$ref='api/media.php?id=M'.str_repeat('A',20);
gate88(sf_world_vinyl_public_collections()===[],'No implicit vinyl reproduction permission');
sf_world_vinyl_set_enabled(1,true,11);
$c=sf_world_vinyl_public_collections();
gate88(count($c)===1&&$c[0]['title']==='Behind the Album','Approved published photo collection');
gate88(count($c[0]['photos'])===1,'Only download-enabled sufficiently large originals');
gate88($c[0]['photos'][0]['reference']===$ref,'Canonical local Media Library original');
gate88(sf_world_vinyl_approved_reference($ref),'Approved original available');
gate88(!sf_world_vinyl_approved_reference('https://external.example/photo.jpg'),'External image never accepted');
gate88(!sf_world_vinyl_approved_reference('api/media.php?id=M'.str_repeat('B',20)),'No unapproved image');
gate88(!sf_world_vinyl_approved_reference($ref.'&variant=thumb'),'No user-controlled variants');
sf_world_vinyl_set_enabled(2,true,11);
gate88(count(sf_world_vinyl_public_collections())===1,'Unpublished galleries excluded');
sf_world_vinyl_set_enabled(1,false,11);
gate88(!sf_world_vinyl_approved_reference($ref),'Revocation immediately closes new quotes');
try{sf_world_vinyl_set_enabled(900,true,11);gate88(false,'Missing gallery should reject');}
catch(InvalidArgumentException $e){gate88(true,'Missing gallery rejects');}
$root=dirname(__DIR__);
$endpoint=(string)file_get_contents($root.'/admin/api/vinyl-artwork-collections.php');
$quote=(string)file_get_contents($root.'/api/bootstrap.php');
$draft=(string)file_get_contents($root.'/api/account-data.php');
$ui=(string)file_get_contents($root.'/assets/js/app.js');
$adminUi=(string)file_get_contents($root.'/admin/assets/gallery.js');
gate88(str_contains($endpoint,"sf_admin_require_auth(\$method!=='GET')")
 &&str_contains($endpoint,"empty(\$b['confirmed'])"),'Admin authentication and deliberate rights approval');
gate88(str_contains($quote,'sf_world_vinyl_approved_reference($front)')
 &&str_contains($quote,'sf_world_vinyl_approved_reference($back)'),'Front/back checkout quote revalidation');
gate88(str_contains($draft,'sf_world_vinyl_approved_reference($reference)'),'Saved drafts cannot silently retain withdrawn artwork');
gate88(str_contains($ui,'vinylArtPicker()')
 &&str_contains($ui,'st.builder[vinylArtSide]=photo.reference'),'Native vinyl picker and preview');
gate88(str_contains($adminUi,'data-gallery-vinyl')
 &&str_contains($adminUi,'vinyl-artwork-collections.php'),'Existing gallery Admin manages selection rights');
$producer=(string)file_get_contents($root.'/api/kunaki-media-transfer.php');
gate88(str_contains($producer,'sf_kunaki_media_resolve')
 &&str_contains($producer,'api/media.php'),'Production originals remain server verified');
echo "88A: $checks regression assertions passed\n";
