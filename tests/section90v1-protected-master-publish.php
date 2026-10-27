<?php
declare(strict_types=1);
/** Section 90V1: old protected masters and new Catalog review use one guarded writer. */
$root=sys_get_temp_dir().'/sf90v1-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/media',0700,true);mkdir($root.'/data',0700,true);
define('SF_ROOT',$root);
file_put_contents($root.'/data/catalog.json','[]');
$GLOBALS['sf90v1_db']=new PDO('sqlite::memory:');
$GLOBALS['sf90v1_db']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['sf90v1_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sf_db():PDO{return $GLOBALS['sf90v1_db'];}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $value,int $max=160):string{return substr(trim((string)$value),0,$max);}
function sf_admin_public_credits(array $metadata):array{return [] ;}
function sf_catalog():array{return json_decode((string)file_get_contents(SF_ROOT.'/data/catalog.json'),true)?:[];}
function sf_admin_write_catalog(array $catalog,?string $expected=null):void {
    file_put_contents(SF_ROOT.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
}
require dirname(__DIR__).'/api/media-core.php';
require dirname(__DIR__).'/api/media-catalog-intake.php';
$db=sf_db();$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users(id) VALUES(7)');
sf_media_ensure_schema();
function ok90v1(bool $condition,string $label):void {
    if(!$condition)throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
}
function wav90v1(int $phase):string {
    $data='';for($i=0;$i<44100;$i++)$data.=pack('v',(int)(sin(($i+$phase)*.09)*2500)&0xffff);
    return 'RIFF'.pack('V',36+strlen($data)).'WAVEfmt '.pack('V',16)
        .pack('vvVVvv',1,1,44100,88200,2,16).'data'.pack('V',strlen($data)).$data;
}
$key=str_repeat('d',32);
try{
    file_put_contents($root.'/storage/media/private.wav',wav90v1(0));
    file_put_contents($root.'/storage/media/listening.wav',wav90v1(5));
    $source=sf_media_register_existing_file('storage/media/private.wav','private.wav',7);
    $other=sf_media_register_existing_file('storage/media/listening.wav','listening.wav',7);
    sf_media_attach((int)$source['id'],'track','legacy-recording','master_audio',
        ['public_visible'=>false,'download_allowed'=>false]);
    ok90v1(sf_media_protected_master_asset((int)$source['id']),
        'An older Media Library master link protects the source.');
    $record=['request_key'=>$key,'owner_id'=>7,'status'=>'draft',
        'asset_id'=>(int)$source['id'],'asset_sha256'=>$source['sha256'],
        'title'=>'Daily Delusions','release'=>'Unreleased','year'=>2026,
        'words_by'=>'Dave','music_by'=>'Dave','artist_display'=>'Stonefellow',
        'master_owner'=>'Dave','composition_owner'=>'Dave','genre'=>'Rock',
        'isrc'=>'','release_date'=>'','explicit'=>false,'pod_eligible'=>true,
        'digital_purchase_enabled'=>true,'template_metadata'=>['price'=>2.50],
        'imported_asset_ids'=>[(int)$source['id']],'candidate_preview_asset_id'=>0];
    $draft=sf_agent_song_intake_save(7,$record)['draft'];
    $result=sf_media_catalog_publish_draft(7,$key,$draft['payload_hash'],true,
        (int)$other['id']);
    $catalog=sf_catalog();$published=$catalog[0]??[];
    ok90v1($result['publication']==='published'&&count($catalog)===1,
        'A separate administrator-owned listening copy publishes the draft.');
    ok90v1(($published['media_primary_asset']??0)===(int)$other['id']
        &&($published['media_private_master_asset']??0)===(int)$source['id'],
        'Catalog stores distinct public audio and private original.');
    ok90v1(!empty($published['digital_purchase_enabled'])&&!empty($published['podEligible']),
        'Digital downloads and vinyl are independently enabled by Admin.');
    ok90v1(sf_media_protected_master_asset((int)$source['id'])
        &&!sf_media_protected_master_asset((int)$other['id']),
        'Existing master protection survives successful Catalog publication.');
    $public=sf_media_public_links('track',(string)$result['track_id']);
    ok90v1(count($public)===1&&$public[0]['uuid']===$other['uuid'],
        'Public links expose only the selected listening copy.');
    $again=sf_media_catalog_publish_draft(7,$key,$draft['payload_hash'],true,(int)$other['id']);
    ok90v1($again['changed']===false&&$again['track_id']===$result['track_id'],
        'A publication retry retains the original Catalog ID.');
}finally{
    foreach(glob($root.'/storage/agent-song-drafts/7/*')?:[] as $file)@unlink($file);
    @unlink($root.'/storage/agent-song-drafts/7/.draft-writer.lock');
    @rmdir($root.'/storage/agent-song-drafts/7');@rmdir($root.'/storage/agent-song-drafts');
    @unlink($root.'/storage/media/private.wav');@unlink($root.'/storage/media/listening.wav');
    @unlink($root.'/data/catalog.json');@rmdir($root.'/storage/media');@rmdir($root.'/storage');
    @rmdir($root.'/data');@rmdir($root);
}
echo "Section 90V1 protected master publication: PASS\n";
