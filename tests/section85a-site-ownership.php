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
  'purchased'=>['title'=>'A Purchased Song','audio'=>$ref,'duration'=>180,
    'podEligible'=>true,'vinyl_license_packages'=>[['licenses'=>1,'price_cents'=>250],
        ['licenses'=>5,'price_cents'=>1100]]],
  'preview'=>['title'=>'Preview Only','audio'=>'assets/audio/preview.mp3'],
  'other'=>['title'=>'Not Mine','audio'=>$ref],
  'vinyl'=>['title'=>'Vinyl License Only','audio'=>$ref],
  'unpaid'=>['title'=>'Unpaid Track','audio'=>$ref],
  'album-track'=>['title'=>'Album Entitlement','audio'=>$ref],
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
$result=sf_fan_owned_project(4,$library,$orders,$catalog,$balances);
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
echo "Section 85A purchased music and vinyl rights: $passes checks passed".PHP_EOL;
