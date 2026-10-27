<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function verify90s(bool $ok,string $reason):void {
    if(!$ok){fwrite(STDERR,"FAIL: $reason\n");exit(1);}
    echo "PASS: $reason\n";
}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
require $root.'/api/agent-song-intake.php';
$owner=427;
$key=str_repeat('a',32);
$asset=['id'=>12,'category'=>'audio','created_by'=>$owner,'sha256'=>str_repeat('1',64),
    'original_name'=>'My Song.mp3','duration_seconds'=>188,'codec'=>'mp3'];
$record=sf_agent_song_intake_normalize(['request_key'=>$key,'title'=>'My Song',
    'words_by'=>'Writer','music_by'=>'Writer','pod_eligible'=>true],$asset,$owner);
verify90s($record['status']==='draft'&&!$record['rights_confirmed'],'A per-song draft stays private without assuming rights confirmation.');
verify90s(!empty($record['pod_eligible']),'Per-song vinyl opt-in survives draft normalization.');
$default=sf_agent_song_intake_normalize(['request_key'=>str_repeat('b',32),'title'=>'Other'],
    $asset,$owner);
verify90s(empty($default['pod_eligible']),'Other songs remain ineligible for vinyl by default.');
$record['template_metadata']=['template'=>['id'=>'my-template','version'=>1],
    'pro_affiliation'=>'ASCAP','source'=>['private_import'=>true,'source_files'=>[['name'=>'My Song.mp3']]]];
$record['candidate_preview_asset_id']=0;
$record['imported_asset_ids']=[12];
$dir=sys_get_temp_dir().'/sf90s-'.bin2hex(random_bytes(6));
try {
    $saved=sf_agent_song_intake_save($owner,$record,$dir);
    verify90s(!empty($saved['created'])&&$saved['draft']['template_metadata']['template']['id']==='my-template',
        'Private draft stores the exact selected template snapshot.');
    $repeat=sf_agent_song_intake_save($owner,$record,$dir);
    verify90s(empty($repeat['created']),'Per-song retries are idempotent.');
    $edited=sf_agent_song_intake_normalize(['request_key'=>$key,'title'=>'My Song Remastered',
        'pod_eligible'=>false],$asset,$owner);
    $updated=sf_agent_song_intake_update($owner,$edited,$saved['draft']['payload_hash'],$dir);
    verify90s($updated['draft']['title']==='My Song Remastered' && empty($updated['draft']['pod_eligible']),
        'Draft song details and vinyl choice can be edited before publishing.');
    verify90s(($updated['draft']['template_metadata']['pro_affiliation']??'')==='ASCAP'
        &&($updated['draft']['candidate_preview_asset_id']??-1)===0,
        'Editing preserves private import provenance and scanned media candidates.');
}finally{
    foreach(glob($dir.'/*')?:[] as $file)if(is_file($file))@unlink($file);
    @unlink($dir.'/.draft-writer.lock');@rmdir($dir);
}
$endpoint=file_get_contents($root.'/admin/api/scan-private.php');
verify90s(!str_contains($endpoint,'sf_admin_write_catalog(')
    &&!str_contains($endpoint,'sf_media_attach('),
    'Private scan finalization has no public-catalog or media publication writer.');
$legacy=file_get_contents($root.'/admin/api/finalize.php');
verify90s(str_contains($legacy,"if(empty(\$body['confirmed_publication']))"),
    'Legacy public finalization is blocked without a separate explicit approval.');
echo "Section 90S PHP private intake and metadata persistence: PASS\n";
