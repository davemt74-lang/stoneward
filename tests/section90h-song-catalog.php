<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/admin/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-song-catalog.php';
require_once dirname(__DIR__).'/api/agent-tool-registry.php';
require_once dirname(__DIR__).'/api/agent-skill-registry.php';
$passed=0;$failed=0;
$check=static function(string $name,bool $ok)use(&$passed,&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
    $ok?$passed++:$failed++;
};
$throws=static function(callable $fn):bool{
    try{$fn();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}
};
$key=str_repeat('c',32);
$audio=['id'=>41,'category'=>'audio','created_by'=>11,'sha256'=>str_repeat('a',64),
    'original_name'=>'private-master.wav','duration_seconds'=>210,'codec'=>'pcm'];
$art=['id'=>42,'category'=>'image','created_by'=>11,'sha256'=>str_repeat('b',64),
    'uuid'=>'M'.str_repeat('A',20),'original_name'=>'cover.png'];
$preview=['id'=>43,'category'=>'audio','extension'=>'mp3','created_by'=>11,
    'sha256'=>str_repeat('c',64),'uuid'=>'M'.str_repeat('B',20),
    'original_name'=>'public-listening.mp3','duration_seconds'=>207.5];
$raw=['request_key'=>$key,'title'=>'Desert Transmission','release'=>'New Single','year'=>2026,
    'words_by'=>'Stonefellow','music_by'=>'Stonefellow','artist_display'=>'Stonefellow',
    'master_owner'=>'Stonefellow','composition_owner'=>'Stonefellow','rights_confirmed'=>true,
    'artwork_asset_id'=>42,'isrc'=>'USABC2600001','release_date'=>'2026-12-03','explicit'=>false,
    'notes'=>'Private notes must never appear in the public catalog.'];
$draft=sf_agent_song_intake_normalize($raw,$audio,11,$art);
$draft['payload_hash']=hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR));
$draft['status']='catalog_ready';$draft['prepared_at']=gmdate('c');
$candidate=sf_agent_song_catalog_candidate($draft,$audio,$preview,$art,11);
$check('Prepared and rights-approved song produces stable published track',
    $candidate['id']==='sf-'.substr($key,0,16)
    &&$candidate['state']==='published'&&!empty($candidate['public_visible'])
    &&$candidate['title']==='Desert Transmission');
$check('Public listen preview uses managed MP3; artwork uses separate managed image',
    $candidate['audio']==='api/media.php?id='.$preview['uuid']
    &&str_starts_with($candidate['artwork'],'api/media.php?id='.$art['uuid'])
    &&$candidate['media_primary_asset']===$preview['id']
    &&$candidate['media_primary_artwork_asset']===$art['id']);
$public=json_encode($candidate,JSON_THROW_ON_ERROR);
$check('Public catalog excludes private original URL, owner scope, draft notes and integrity hashes',
    !str_contains($public,'private-master.wav')&&!str_contains($public,$audio['sha256'])
    &&!str_contains($public,$draft['payload_hash'])&&!str_contains($public,$draft['notes'])
    &&!str_contains($public,'owner_id')&&!str_contains($public,'asset_sha256'));
$check('Public credits and identifiers are retained without auto-creating album',
    in_array('Words and music by Stonefellow',$candidate['credits'],true)
    &&$candidate['metadata']['isrc']==='USABC2600001'
    &&$candidate['release']==='New Single'&&!isset($candidate['release_id'])
    &&empty($candidate['podEligible']));
$first=sf_agent_song_catalog_decide([],$candidate);
$replay=sf_agent_song_catalog_decide($first['catalog'],$candidate);
$check('Catalog publication creates one row, stable replay creates zero',
    $first['created']&&count($first['catalog'])===1
    &&!$replay['created']&&count($replay['catalog'])===1);
$check('Catalog ID collision and duplicated ISRC cannot overwrite another song',
    $throws(fn()=>sf_agent_song_catalog_decide($first['catalog'],array_replace_recursive($candidate,
        ['audio'=>'api/media.php?id=M'.str_repeat('F',20)])))
    &&$throws(fn()=>sf_agent_song_catalog_decide($first['catalog'],array_replace($candidate,['id'=>'another-id']))));
$check('Unprepared, unauthorized, unconfirmed or missing rights cannot publish',
    $throws(fn()=>sf_agent_song_catalog_candidate(array_replace($draft,['status'=>'draft']),$audio,$preview,$art,11))
    &&$throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,$preview,$art,12))
    &&$throws(fn()=>sf_agent_song_catalog_candidate(array_replace($draft,['rights_confirmed'=>false]),$audio,$preview,$art,11))
    &&$throws(fn()=>sf_agent_song_catalog_candidate(array_replace($draft,['payload_hash'=>'']),$audio,$preview,$art,11))
    &&$throws(fn()=>sf_agent_song_catalog_candidate(array_replace($draft,['prepared_at'=>'']),$audio,$preview,$art,11)));
$check('Private original can never be reused as public preview',
    $throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,array_replace($preview,['id'=>41]),$art,11))
    &&$throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,array_replace($preview,['sha256'=>$audio['sha256']]),$art,11)));
$check('Preview requires MP3 format, duration, managed ID, valid UUID and Admin ownership',
    $throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,array_replace($preview,['extension'=>'wav']),$art,11))
    &&$throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,array_replace($preview,['duration_seconds'=>0]),$art,11))
    &&$throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,array_replace($preview,['created_by'=>22]),$art,11))
    &&$throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,array_replace($preview,['uuid'=>'../../private']),$art,11)));
$check('Existing catalog ISRC blocks publishing',
    $throws(fn()=>sf_agent_song_catalog_candidate($draft,$audio,$preview,$art,11,
        [['id'=>'existing','metadata'=>['isrc'=>'USABC2600001']]])));
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-song-catalog.php');
$core=(string)file_get_contents(dirname(__DIR__).'/api/agent-song-catalog.php');
$check('Publication API authenticates writes and requires explicit confirmation',
    str_contains($api,'sf_admin_require_auth(true)')
    &&str_contains($api,"empty(\$body['confirmed'])")
    &&str_contains($api,'sf_agent_song_catalog_publish($owner'));
$check('Original master stays private; preview activated only after catalog writer',
    str_contains($core,"'master_audio'")&&str_contains($core,"'primary_audio'")
    &&str_contains($core,"'download_allowed'=>false")
    &&strpos($core,'sf_admin_write_catalog(')<strpos($core,'sf_media_update_link(')
    &&!str_contains($core,'sf_admin_write_releases('));
$check('Published receipt remains owner-visible and recovery-safe',
    in_array('published',['draft','catalog_ready','published'],true)
    &&str_contains((string)file_get_contents(dirname(__DIR__).'/api/agent-song-intake.php'),"'catalog_ready','published'")
    &&str_contains($core,'sf_agent_song_catalog_decide($catalog,$candidate)')
    &&str_contains($core,'hash_equals((string)($draft[\'payload_hash\']??\'\'),$expectedHash)'));
$check('Governed publication is Admin-only, confirmation-gated and not direct executable',
    sf_agent_tool_allowed('agent_song_catalog_publish','admin',true)
    &&!sf_agent_tool_allowed('agent_song_catalog_publish','admin',false)
    &&!sf_agent_tool_allowed('agent_song_catalog_publish','fan',true)
    &&!sf_agent_tool_executable('agent_song_catalog_publish','admin',true)
    &&(sf_agent_skill_for_intent('admin','publish_song_catalog')['tool_id']??'')==='agent_song_catalog_publish'
    &&sf_agent_skill_for_intent('customer','publish_song_catalog')===null);
echo "Section 90H song catalog: $passed passed, $failed failed".PHP_EOL;
exit($failed?1:0);
