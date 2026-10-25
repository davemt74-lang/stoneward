<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-song-intake.php';
require_once dirname(__DIR__).'/api/agent-tool-registry.php';
require_once dirname(__DIR__).'/api/agent-skill-registry.php';
$passed=0;$failed=0;
$check=function(string $name,bool $ok)use(&$passed,&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
    $ok?$passed++:$failed++;
};
$throws=function(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}};
$key=str_repeat('c',32);
$audio=['id'=>321,'category'=>'audio','created_by'=>11,'sha256'=>str_repeat('a',64),
    'original_name'=>'private-song.wav','duration_seconds'=>176,'codec'=>'pcm'];
$art=['id'=>322,'category'=>'image','created_by'=>11,'sha256'=>str_repeat('b',64),
    'original_name'=>'cover.png'];
$base=['request_key'=>$key,'title'=>'Small Desert Song','release'=>'New Single','year'=>'2026',
    'words_by'=>'Stonefellow','music_by'=>'Stonefellow','artist_display'=>'Stonefellow',
    'master_owner'=>'Stonefellow','composition_owner'=>'Stonefellow',
    'rights_confirmed'=>true,'artwork_asset_id'=>322,
    'isrc'=>'USABC2600001','release_date'=>'2026-12-03','explicit'=>false];
$draft=sf_agent_song_intake_normalize($base,$audio,11,$art);
$check('Review normalizes album, owner, credits, optional identifiers and private artwork',
    $draft['artwork_asset_id']===322&&$draft['artwork_sha256']===$art['sha256']
    &&$draft['artist_display']==='Stonefellow'&&$draft['isrc']==='USABC2600001'
    &&$draft['release_date']==='2026-12-03');
$check('Complete original-music private preflight reaches 100/100',
    sf_agent_song_intake_readiness($draft,$audio,$art)['ready']
    &&sf_agent_song_intake_readiness($draft,$audio,$art)['score']===100);
$check('Missing rights confirmation blocks preflight',
    !sf_agent_song_intake_readiness(array_replace($draft,['rights_confirmed'=>false]),$audio,$art)['ready']);
$check('Missing artwork blocks preflight',
    !sf_agent_song_intake_readiness($draft,$audio,null)['ready']);
$check('Tampered audio sha, art sha and foreign owner are rejected',
    !sf_agent_song_intake_readiness($draft,array_replace($audio,['sha256'=>str_repeat('e',64)]),$art)['ready']
    &&!sf_agent_song_intake_readiness($draft,$audio,array_replace($art,['sha256'=>str_repeat('e',64)]))['ready']
    &&!sf_agent_song_intake_readiness($draft,$audio,array_replace($art,['created_by'=>22]))['ready']);
$check('Existing ISRC cannot be prepared again',
    !sf_agent_song_intake_readiness($draft,$audio,$art,[['id'=>'existing-song','metadata'=>['isrc'=>$draft['isrc']]]])['ready']);
$check('Review rejects other Admin artwork',
    $throws(fn()=>sf_agent_song_intake_normalize($base,$audio,11,array_replace($art,['created_by'=>12]))));
$check('Review rejects audio masquerading as cover',
    $throws(fn()=>sf_agent_song_intake_normalize($base,$audio,11,array_replace($art,['category'=>'audio']))));
$check('Review rejects invalid ISRC and invalid calendar dates',
    $throws(fn()=>sf_agent_song_intake_normalize(array_replace($base,['isrc'=>'bad']),$audio,11,$art))
    &&$throws(fn()=>sf_agent_song_intake_normalize(array_replace($base,['release_date'=>'2026-02-30']),$audio,11,$art)));
$check('Literal false cannot forge rights attestation',
    !sf_agent_song_intake_normalize(array_replace($base,['rights_confirmed'=>'false']),$audio,11,$art)['rights_confirmed']);
$dir=sys_get_temp_dir().'/stonefellow-song-preflight-'.bin2hex(random_bytes(7));
try{
    $created=sf_agent_song_intake_save(11,$draft,$dir);
    $hash=(string)$created['draft']['payload_hash'];
    $check('Preparation cannot bypass readiness or owner',
        $throws(fn()=>sf_agent_song_intake_prepare(11,$key,$hash,$audio,null,$dir))
        &&$throws(fn()=>sf_agent_song_intake_prepare(12,$key,$hash,$audio,$art,$dir)));
    $check('Revision mismatch blocks preparation',
        $throws(fn()=>sf_agent_song_intake_prepare(11,$key,str_repeat('f',64),$audio,$art,$dir)));
    $ready=sf_agent_song_intake_prepare(11,$key,$hash,$audio,$art,$dir);
    $check('Explicit preparation is private and status changes exactly once',
        $ready['changed']&&$ready['draft']['status']==='catalog_ready'
        &&isset($ready['draft']['prepared_at'])
        &&!isset($ready['draft']['audio'],$ready['draft']['public_url'],$ready['draft']['published_at']));
    $check('Ready file remains private on disk',
        ((fileperms($dir.'/sd_'.$key.'.json')&0777)===0600));
    $check('Repeated approval is idempotent',
        !sf_agent_song_intake_prepare(11,$key,$hash,$audio,$art,$dir)['changed']);
    $check('Prepared draft is listed to owner but cannot be edited through draft endpoint',
        count(sf_agent_song_intake_list(11,$dir))===1
        &&sf_agent_song_intake_list(11,$dir)[0]['status']==='catalog_ready'
        &&sf_agent_song_intake_list(12,$dir)===[]
        &&$throws(fn()=>sf_agent_song_intake_update(11,$draft,$hash,$dir)));
}finally{
    foreach(glob($dir.'/sd_*.json')?:[] as $file)unlink($file);
    if(is_file($dir.'/.draft-writer.lock'))unlink($dir.'/.draft-writer.lock');
    if(is_dir($dir))rmdir($dir);
}
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-song-intake.php');
$core=(string)file_get_contents(dirname(__DIR__).'/api/agent-song-intake.php');
$check('Read and prepare require authenticated Admin and explicit confirmation',
    str_contains($api,"sf_admin_require_auth(\$method==='POST')")
    &&str_contains($api,"empty(\$body['confirmed'])")
    &&str_contains($api,'sf_agent_song_intake_prepare($owner'));
$check('Private preflight never mutates catalog or releases',
    !str_contains($api,'sf_admin_write_catalog')&&!str_contains($api,'sf_admin_write_releases')
    &&!str_contains($core,'sf_admin_write_catalog')&&!str_contains($core,'sf_admin_write_releases'));
$check('Tool and skill are admin-only and not direct agent-executable',
    sf_agent_tool_allowed('agent_song_catalog_prepare','admin',true)
    &&!sf_agent_tool_allowed('agent_song_catalog_prepare','fan',true)
    &&!sf_agent_tool_allowed('agent_song_catalog_prepare','admin',false)
    &&!sf_agent_tool_executable('agent_song_catalog_prepare','admin',true)
    &&(sf_agent_skill_for_intent('admin','prepare_song_catalog')['tool_id']??'')==='agent_song_catalog_prepare'
    &&sf_agent_skill_for_intent('customer','prepare_song_catalog')===null);
echo "Section 90G song preflight: $passed passed, $failed failed".PHP_EOL;
exit($failed?1:0);
