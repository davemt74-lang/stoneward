<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-song-intake.php';
require_once dirname(__DIR__).'/api/agent-tool-registry.php';
$passed=0;$failed=0;
$check=function(string $description,bool $pass)use(&$passed,&$failed):void{
    echo ($pass?'PASS ':'FAIL ').$description.PHP_EOL;
    if($pass)$passed++;else $failed++;
};
$throws=function(callable $call):bool{try{$call();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}};
$id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$asset=['id'=>245,'category'=>'audio','created_by'=>7,'sha256'=>str_repeat('1',64),
    'original_name'=>'sample.wav','duration_seconds'=>243.618,'codec'=>'pcm_s16le'];
$payload=['request_key'=>$id,'title'=>' Desert Drive ','year'=>'',
    'genre'=>'Rock','release'=>'Unreleased','words_by'=>'Stonefellow','music_by'=>'Stonefellow'];
$record=sf_agent_song_intake_normalize($payload,$asset,7);
$check('Selected recording belongs to owner and remains unpublished',$record['asset_id']===245&&$record['owner_id']===7&&$record['status']==='draft');
$check('Human-entered song fields are cleaned',$record['title']==='Desert Drive'&&$record['words_by']==='Stonefellow');
$check('Blank year remains valid',$record['year']===0);
$check('Private intake preserves recording identity',$record['asset_sha256']===$asset['sha256']&&$record['duration_seconds']===243.618);
$check('No catalog publishing fields or public URL',!isset($record['audio'],$record['published_at'],$record['public_visible']));
$check('Fan or wrong Admin recording cannot be adopted',$throws(fn()=>sf_agent_song_intake_normalize($payload,$asset,1)));
$check('Image instead of audio is rejected',$throws(fn()=>sf_agent_song_intake_normalize($payload,array_replace($asset,['category'=>'image']),7)));
$check('Unknown or malformed recording cannot be saved',$throws(fn()=>sf_agent_song_intake_normalize($payload,array_replace($asset,['id'=>0]),7)));
$check('Song title is required',$throws(fn()=>sf_agent_song_intake_normalize(array_replace($payload,['title'=>'']),$asset,7)));
$check('Invalid idempotency key is rejected',$throws(fn()=>sf_agent_song_intake_normalize(array_replace($payload,['request_key'=>'../escape']),$asset,7)));
$check('Invalid year is rejected',$throws(fn()=>sf_agent_song_intake_normalize(array_replace($payload,['year'=>'1899']),$asset,7)));
$check('Future year outside bound rejected',$throws(fn()=>sf_agent_song_intake_normalize(array_replace($payload,['year'=>'2101']),$asset,7)));
$dir=sys_get_temp_dir().'/stonefellow-song-intake-test-'.bin2hex(random_bytes(8));
try{
    $initial=sf_agent_song_intake_save(7,$record,$dir);
    $check('Owner creates one private draft',$initial['created']&&is_file($dir.'/sd_'.$id.'.json'));
    $check('Stored record includes durable timestamp',$initial['draft']['created_at']!==''&&$initial['draft']['payload_hash']!=='');
    $check('Only owner can list their drafts',count(sf_agent_song_intake_list(7,$dir))===1&&sf_agent_song_intake_list(8,$dir)===[]);
    $second=sf_agent_song_intake_save(7,$record,$dir);
    $check('Repeat same request returns exact saved record',!$second['created']&&$second['draft']===$initial['draft']);
    $check('Same key with changed title cannot overwrite',$throws(fn()=>sf_agent_song_intake_save(7,array_replace($record,['title'=>'Changed']),$dir)));
    $edited=array_replace($record,['title'=>'Desert Drive (Acoustic)']);
    $updated=sf_agent_song_intake_update(7,$edited,$initial['draft']['payload_hash'],$dir);
    $check('Owner can revise saved private metadata',$updated['updated']&&$updated['draft']['title']==='Desert Drive (Acoustic)');
    $check('Draft revision preserves recording and creation time',$updated['draft']['asset_id']===245
        &&$updated['draft']['created_at']===$initial['draft']['created_at']);
    $check('Concurrent stale revision cannot overwrite',$throws(fn()=>sf_agent_song_intake_update(7,$record,$initial['draft']['payload_hash'],$dir)));
    $check('Repeat update is idempotent',!sf_agent_song_intake_update(7,$edited,$initial['draft']['payload_hash'],$dir)['updated']);
    $check('Edits cannot replace recording',$throws(fn()=>sf_agent_song_intake_update(7,
        array_replace($edited,['asset_id'=>999]),$updated['draft']['payload_hash'],$dir)));
    $check('Different owner cannot edit',$throws(fn()=>sf_agent_song_intake_update(8,$edited,$updated['draft']['payload_hash'],$dir)));
    $check('Same recording with different key cannot duplicate',$throws(fn()=>sf_agent_song_intake_save(7,array_replace($record,['id'=>'sd_'.str_repeat('b',32),'request_key'=>str_repeat('b',32)]),$dir)));
    $check('Owner mismatch cannot write',$throws(fn()=>sf_agent_song_intake_save(8,$record,$dir)));
    $check('Saved file is private permissions',((fileperms($dir.'/sd_'.$id.'.json')&0777)===0600));
    $check('Stored metadata is not an executable public catalog entry',!isset($initial['draft']['audio'],$initial['draft']['public_url']));
}finally{
    foreach(glob($dir.'/sd_*.json')?:[] as $file)unlink($file);
    if(is_file($dir.'/.draft-writer.lock'))unlink($dir.'/.draft-writer.lock');
    if(is_dir($dir))rmdir($dir);
}
$endpoint=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-song-intake.php');
$check('Both reads and writes require administrator auth',str_contains($endpoint,"sf_admin_require_auth(\$method==='POST')"));
$check('Private draft API uses server-selected recording',str_contains($endpoint,'sf_media_asset($assetId)'));
$check('Every created draft is audited',str_contains($endpoint,'sf_log_admin_action($owner'));
$check('No public catalog writer is called',!str_contains($endpoint,'sf_admin_write_catalog')&&!str_contains(file_get_contents(dirname(__DIR__).'/api/agent-song-intake.php'),'sf_admin_write_catalog'));
$check('Updates also require exact revision',str_contains($endpoint,'sf_agent_song_intake_update($owner,$record')
    &&str_contains(file_get_contents(dirname(__DIR__).'/api/agent-song-intake.php'),'hash_equals'));
$check('Song draft tool is visible only with Admin scope and confirmation',sf_agent_tool_allowed('agent_song_draft_save','admin',true)
    &&!sf_agent_tool_allowed('agent_song_draft_save','admin',false)
    &&!sf_agent_tool_allowed('agent_song_draft_save','fan',true)
    &&!sf_agent_tool_executable('agent_song_draft_save','admin',true));
echo "Section 90F private song intake: $passed passed, $failed failed".PHP_EOL;
exit($failed?1:0);
