<?php
declare(strict_types=1);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function sf_clean_text(mixed $v,int $max=120): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}
function sf_track_map(): array {return ['one'=>['id'=>'one','title'=>'One','release'=>'A','duration'=>100,'audio'=>'one.mp3'],'two'=>['id'=>'two','title'=>'Two','release'=>'A','duration'=>120,'audio'=>'two.mp3']];}
require dirname(__DIR__).'/api/playlists-core.php';
ok(sf_playlist_clean_visibility('public')==='public','public visibility is accepted');
ok(sf_playlist_clean_visibility('private')==='private','private visibility is accepted');
ok(sf_playlist_clean_visibility('admin')==='private','unknown visibility collapses to private');
ok(sf_playlist_clean_source('agent')==='agent','agent source is retained');
ok(sf_playlist_clean_source('fake')==='user','unknown source collapses to user');
$ids=sf_playlist_validate_track_ids(['one','two']);ok($ids===['one','two'],'valid catalog track order is preserved');
$thrown=false;try{sf_playlist_validate_track_ids(['missing']);}catch(InvalidArgumentException $e){$thrown=true;}ok($thrown,'unknown tracks are rejected');
$p=sf_playlist_track_payload('one');ok(($p['title']??'')==='One'&&($p['duration']??0)===100,'track payload resolves catalog metadata');
ok(sf_playlist_track_payload('missing')===null,'missing track payload returns null');
echo "Stonefellow v1.3.1 playlist pure behavior audit: PASS\n";
