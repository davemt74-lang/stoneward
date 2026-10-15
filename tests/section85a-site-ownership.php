<?php
declare(strict_types=1);
function sf_order_paid_for_library(array $order):bool {return ($order['status']??'')==='paid';}
require dirname(__DIR__).'/api/fan-owned-music-core.php';
$passes=0;
function ok85(bool $yes,string $why):void{
    global $passes;if(!$yes)throw new RuntimeException('85A: '.$why);
    $passes++;echo 'PASS '.$why.PHP_EOL;
}
function denies85(callable $fn):bool{
    try{$fn();return false;}catch(Throwable $e){return true;}
}
$ref='api/media.php?id=M1234567890ABCDEF1234';
$catalog=[
  'purchased'=>['title'=>'A Purchased Song','audio'=>'api/media.php?id=M99999999999999999999','private_master_audio'=>$ref,'audio_publishing_mode'=>'preview_master_split','duration'=>180,
    'podEligible'=>true,'vinyl_license_packages'=>[['licenses'=>1,'price_cents'=>250],
        ['licenses'=>5,'price_cents'=>1100]]],
  'preview'=>['title'=>'Preview Only','audio'=>'assets/audio/preview.mp3'],
  'other'=>['title'=>'Not Mine','audio'=>$ref],
  'vinyl'=>['title'=>'Vinyl License Only','audio'=>$ref],
  'unpaid'=>['title'=>'Unpaid Track','audio'=>$ref],
  'album-track'=>['title'=>'Album Entitlement','audio'=>$ref,'private_master_audio'=>$ref],
];
$library=[
  ['item_type'=>'track','item_key'=>'purchased','source_order_id'=>'SF-ONE'],
  ['item_type'=>'track','item_key'=>'preview','source_order_id'=>'SF-PREVIEW'],
  ['item_type'=>'track','item_key'=>'other','source_order_id'=>'SF-OTHER'],
  ['item_type'=>'track','item_key'=>'vinyl','source_order_id'=>'SF-VINYL'],
  ['item_type'=>'track','item_key'=>'unpaid','source_order_id'=>'SF-UNPAID'],
  ['item_type'=>'release','item_key'=>'album','source_order_id'=>'SF-ALBUM'],
];
$orders=[
  'SF-ONE'=>['user_id'=>4,'status'=>'paid','quote'=>['items'=>[['type'=>'track','track_id'=>'purchased']]]],
  'SF-PREVIEW'=>['user_id'=>4,'status'=>'paid','quote'=>['items'=>[['type'=>'track','track_id'=>'preview']]]],
  'SF-OTHER'=>['user_id'=>9,'status'=>'paid','quote'=>['items'=>[['type'=>'track','track_id'=>'other']]]],
  'SF-VINYL'=>['user_id'=>4,'status'=>'paid','quote'=>['items'=>[['type'=>'license_pack','track_id'=>'vinyl']]]],
  'SF-UNPAID'=>['user_id'=>4,'status'=>'pending','quote'=>['items'=>[['type'=>'track','track_id'=>'unpaid']]]],
  'SF-ALBUM'=>['user_id'=>4,'status'=>'paid','quote'=>['items'=>[['type'=>'release',
            'release_id'=>'album','track_ids'=>['album-track']]]]],
];
$balances=['purchased'=>['granted'=>5,'reserved_or_used'=>3,'available'=>2],
           'vinyl'=>['granted'=>1,'reserved_or_used'=>0,'available'=>1]];
$result=sf_fan_owned_project(4,$library,$orders,$catalog,$balances,static fn($ref):bool=>$ref==='api/media.php?id=M1234567890ABCDEF1234');
$byId=array_column($result['tracks'],null,'id');
ok85($result['total']===3,'Exactly paid own digital song and album lines are eligible');
ok85(!isset($byId['other'],$byId['vinyl'],$byId['unpaid']),'Other account, vinyl-only, and unpaid cannot masquerade as music ownership');
ok85(isset($byId['purchased'])&&isset($byId['preview'])&&isset($byId['album-track']),
    'Track and full album entitlements are projected');
ok85($byId['purchased']['download_ready']===true,
    'Private published master receives a download action');
ok85($byId['preview']['download_ready']===false,
    'Public previews never pretend to be downloadable purchased masters');
ok85($byId['purchased']['vinyl_print_rights']['available']===2
 &&$byId['purchased']['vinyl_print_rights']['reserved_or_used']===3,
    'Reserved credits reduce vinyl balance without changing digital ownership');
ok85($byId['purchased']['vinyl_print_rights']['packages'][1]['licenses']===5,
    'Packages use existing catalog-defined license pricing');
ok85(!isset($byId['purchased']['audio'])&&!isset($byId['purchased']['source_order_id'])
    &&!str_contains(json_encode($result),$ref),'JSON hides media paths and purchase/provider details');
ok85(sf_fan_owned_download_reference(4,'purchased',sf_homeserver_owned_tracks(4,$library,$orders,$catalog),$catalog)===$ref,
    'Owned masters resolve only after paid account grant');
ok85(denies85(fn()=>sf_fan_owned_download_reference(4,'preview',sf_homeserver_owned_tracks(4,$library,$orders,$catalog),$catalog)),
    'Unpublished private master cannot be downloaded');
ok85(denies85(fn()=>sf_fan_owned_download_reference(4,'vinyl',sf_homeserver_owned_tracks(4,$library,$orders,$catalog),$catalog)),
    'Vinyl-only grants do not confer digital download');
ok85(denies85(fn()=>sf_fan_owned_download_reference(9,'purchased',[],$catalog)),
    'Different signed-in user cannot download original buyer music');
ok85(denies85(fn()=>sf_fan_owned_download_reference(4,'../purchased',[], $catalog)),
    'Arbitrary file paths are rejected');
ok85(!sf_fan_owned_private_master('https://example.com/private.wav')
 &&!sf_fan_owned_private_master('api/media.php?id=M1234567890ABCDEF1234&variant=large')
 &&sf_fan_owned_private_master($ref),'Only exact private first-party media IDs accepted');
$endpoint=file_get_contents(dirname(__DIR__).'/api/fan-owned-download.php');
ok85(str_contains($endpoint,'sf_require_user()')
 &&str_contains($endpoint,'sf_homeserver_user_library($uid)')
 &&str_contains($endpoint,'sf_kunaki_media_resolve')
 &&!str_contains($endpoint,'sf_homeserver_bearer('),
    'Website download uses session identity and vetted masters rather than bridge tokens');

$site=file_get_contents(dirname(__DIR__).'/stonefellow-v120.php');
$ui=file_get_contents(dirname(__DIR__).'/assets/js/library.js');
$wallet=file_get_contents(dirname(__DIR__).'/assets/js/owned-music.js');
$css=file_get_contents(dirname(__DIR__).'/assets/css/site.css');
ok85(str_contains($site,'assets/js/owned-music.js') && str_contains($site,"'1.3.82'"),
    'Versioned storefront loads owned-wallet renderer before My Library');
ok85(str_contains($ui,"owned:'Owned Music'")&&str_contains($ui,"fan-owned-music.php"),
    'My Library displays account-scoped purchased music separately from favorites');
ok85(str_contains($wallet,'api/fan-owned-download.php?id=')&&str_contains($wallet,'data-owned-licenses'),
    'Digital download and additional vinyl-license actions use first-party website routes');
ok85(str_contains($wallet,'reserved/used')&&str_contains($wallet,'never consume licenses'),
    'Customer presentation distinguishes print-credit spending from owned digital playback');
ok85(str_contains($css,'.library-owned-card')&&str_contains($css,'@media(max-width:740px)'),
    'Owned-music controls have responsive mobile styles');
ok85(str_contains(file_get_contents(dirname(__DIR__).'/.github/workflows/release-gate.yml'),
    'php tests/section85a-site-ownership.php'),
    'Final Stonefellow release gate explicitly runs 85A security and entitlement regressions');

$noMaster=sf_fan_owned_project(4,$library,$orders,$catalog,$balances,static fn($ref):bool=>false);
$noMasterById=array_column($noMaster['tracks'],null,'id');
ok85($noMasterById['purchased']['download_ready']===false,
    'Published reference without an available private file cannot create a false download button');
$siteCore=file_get_contents(dirname(__DIR__).'/api/fan-owned-music-core.php');
ok85(str_contains($siteCore,'sf_fan_owned_media_ready')&&str_contains($siteCore,'realpath(')
    &&str_contains($siteCore,'is_link($candidate)'),
    'Private media availability requires safe non-symlink master under local storage');

$fixtureRoot=sys_get_temp_dir().'/sf85a-'.bin2hex(random_bytes(6));
mkdir($fixtureRoot.'/storage/media',0700,true);
$mediaPath=$fixtureRoot.'/storage/media/master.wav';
file_put_contents($mediaPath,'RIFFTESTDATA');
if(!defined('SF_ROOT'))define('SF_ROOT',$fixtureRoot);
$asset=[
    'uuid'=>'M1234567890ABCDEF1234',
    'category'=>'audio','extension'=>'wav',
    'stored_path'=>'storage/media/master.wav',
];
$lookup=static fn(string $id):?array=>$id==='M1234567890ABCDEF1234'?$asset:null;
try{
    ok85(sf_fan_owned_media_ready($ref,$lookup),
        'Download available only when a real private audio asset exists under storage/media');
    ok85(!sf_fan_owned_media_ready($ref,static fn()=>array_replace($asset,['category'=>'image'])),
        'Image media cannot masquerade as an owned audio master');
    ok85(!sf_fan_owned_media_ready($ref,static fn()=>array_replace($asset,['stored_path'=>'../master.wav'])),
        'Traversal through catalog media metadata is rejected');
    ok85(!sf_fan_owned_media_ready($ref,static fn()=>array_replace($asset,['stored_path'=>'storage/media/missing.wav'])),
        'Missing original master hides download rather than advertising a broken file');
    $decoy=$fixtureRoot.'/storage/media/symlink.wav';
    if(function_exists('symlink')&&@symlink($mediaPath,$decoy)){
        ok85(!sf_fan_owned_media_ready($ref,static fn()=>array_replace($asset,['stored_path'=>'storage/media/symlink.wav'])),
            'Symbolic link cannot escape private-media publication checks');
        @unlink($decoy);
    }
} finally {
    @unlink($mediaPath);
    @rmdir($fixtureRoot.'/storage/media');
    @rmdir($fixtureRoot.'/storage');
    @rmdir($fixtureRoot);
}
echo "Section 85A purchased music and vinyl rights: $passes checks passed".PHP_EOL;

$adminBuild=(string)file_get_contents(dirname(__DIR__).'/admin/index.php');
$serverHeaders=(string)file_get_contents(dirname(__DIR__).'/.htaccess');
$serviceWorker=(string)file_get_contents(dirname(__DIR__).'/service-worker.js');
ok85(str_contains($adminBuild,"define('SF_ADMIN_BUILD','1.3.82')")
  &&str_contains($serverHeaders,'X-Stonefellow-Build "1.3.82"'),
  'Website and Admin build markers stay in sync for deployed asset cache invalidation');
ok85(str_contains($serviceWorker,"stonefellow-static-v1.3.82")
  &&str_contains($serviceWorker,'assets/js/owned-music.js'),
  'PWA serves the versioned purchased-music interface rather than a stale offline script');
