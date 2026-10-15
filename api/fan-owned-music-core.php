<?php
declare(strict_types=1);
/**
 * 85A Stonefellow first-party purchased-music wallet. Reuses the existing
 * paid-order ownership and vinyl-credit contracts; never trusts browser claims.
 */
require_once __DIR__.'/homeserver-link-core.php';
require_once __DIR__.'/homeserver-music-core.php';

function sf_fan_owned_private_master(mixed $reference):bool {
    // Public previews and arbitrary URLs must never become "owned master" links.
    return is_string($reference)
        && preg_match('/^api\/media\.php\?id=M[A-F0-9]{20}$/D',$reference)===1;
}
function sf_fan_owned_project(int $uid,array $library,array $orders,
                              array $catalog,array $balances):array {
    if($uid<1)throw new RuntimeException('Signed-in account required.');
    $owned=sf_homeserver_owned_tracks($uid,$library,$orders,$catalog);
    $rights=sf_hs_music_rights($owned,$balances,$catalog);
    $tracks=[];
    foreach($rights as $t){
        $id=(string)$t['id'];
        $catalogRow=(array)($catalog[$id]??[]);
        $ready=sf_fan_owned_private_master($catalogRow['audio']??null);
        $tracks[]=[
            'id'=>$id,'title'=>(string)$t['title'],
            'artist'=>'Stonefellow',
            'duration_seconds'=>(int)($t['duration_seconds']??0),
            'artwork'=>(string)($t['artwork']??''),
            'can_play'=>!empty($t['can_stream']),
            'download_ready'=>$ready && !empty($t['can_download']),
            'vinyl_print_rights'=>(array)($t['vinyl_print_rights']??[]),
        ];
    }
    usort($tracks,static fn(array $a,array $b):int=>strcasecmp($a['title'],$b['title']));
    return [
        'contract'=>'stonefellow.fan-owned-music.v1',
        'tracks'=>$tracks,'total'=>count($tracks),
        'download_auth'=>'stonefellow_session_only',
        'vinyl_license_checkout'=>'stonefellow_site_only',
        'digital_streaming_does_not_consume_vinyl_licenses'=>true,
        'vinyl_print_license_per_song_per_pressing'=>1,
    ];
}
function sf_fan_owned_download_reference(int $uid,string $trackId,
                                         array $owned,array $catalog):string {
    $ref=sf_hs_music_audio_reference($uid,$trackId,$owned,$catalog,true);
    if(!sf_fan_owned_private_master($ref))
        throw new RuntimeException('The downloadable master has not been published.');
    return $ref;
}
