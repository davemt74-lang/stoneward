<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/homeserver-music-core.php';
$passed=0;
function check87(bool $condition,string $message):void{
    global $passed;
    if(!$condition)throw new RuntimeException($message);
    $passed++;echo "PASS $message\n";
}
function denies87(callable $fn):bool {
    try{$fn();return false;}catch(Throwable $e){return true;}
}
$owned=[['id'=>'song-one','can_stream'=>true,'can_download'=>true],['id'=>'song-two','can_stream'=>false]];
$catalog=['song-one'=>['audio'=>'api/media.php?id=M12345678901234567890',
    'podEligible'=>true,'vinyl_license_packages'=>[['licenses'=>1,'price_cents'=>250]]],
    'song-two'=>['audio'=>'hidden.wav']];
check87(sf_hs_music_audio_reference(10,'song-one',$owned,$catalog)==='api/media.php?id=M12345678901234567890',
    'Only owned digitally streamable songs resolve');
check87(denies87(fn()=>sf_hs_music_audio_reference(10,'song-two',$owned,$catalog)),
    'Print rights do not grant digital stream rights');
check87(denies87(fn()=>sf_hs_music_audio_reference(10,'../song-one',$owned,$catalog)),
    'No arbitrary media paths through song IDs');
check87(denies87(fn()=>sf_hs_music_audio_reference(10,'song-two',$owned,$catalog,true)),
    'Download permission is independent from streaming ownership');
check87(sf_hs_music_audio_reference(10,'song-one',$owned,$catalog,true)==='api/media.php?id=M12345678901234567890',
    'Purchased track may download only with can_download');
check87(denies87(fn()=>sf_hs_music_audio_reference(11,'song-one',[],$catalog)),
    'No other-account access');
check87(sf_hs_music_range('bytes=0-99',1000)['length']===100,
    'Single byte range bounded');
check87(sf_hs_music_range('bytes=-100',1000)['start']===900,
    'Suffix range bounded');
check87(denies87(fn()=>sf_hs_music_range('bytes=0-1,3-4',1000)),
    'Multi-range rejected');
check87(denies87(fn()=>sf_hs_music_range('bytes=1000-',1000)),
    'Out of bounds range rejected');
$rows=sf_hs_music_rights([['id'=>'song-one'],['id'=>'song-two']],
    ['song-one'=>['granted'=>3,'reserved_or_used'=>2,'available'=>1]],$catalog);
check87($rows[0]['vinyl_print_rights']['available']===1,
    'Read-only print balance retains reserved amounts');
check87($rows[0]['vinyl_print_rights']['packages'][0]['price_cents']===250,
    'Print-license packages sourced from catalog prices');
check87($rows[1]['vinyl_print_rights']['available']===0,
    'Unlicensed song never gets fictional available credits');
echo '84B7 Cloud rights '.$passed.' checks passed'.PHP_EOL;

$tmp87=tempnam(sys_get_temp_dir(),'sf87-');
if($tmp87===false)throw new RuntimeException('Test fixture unavailable');
try {
    file_put_contents($tmp87,'abcdefgh');
    $slice=sf_hs_music_range('bytes=3-5',8);
    $handle=sf_hs_music_open_range($tmp87,$slice);
    check87(fread($handle,$slice['length'])==='def',
        'Private media stream starts at exactly the requested byte offset');
    fclose($handle);
    check87(denies87(fn()=>sf_hs_music_open_range($tmp87.'.missing',$slice)),
        'Missing private files fail before HTTP headers are emitted');
} finally {
    @unlink($tmp87);
}
