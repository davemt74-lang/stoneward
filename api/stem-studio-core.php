<?php
declare(strict_types=1);
require_once __DIR__.'/stem-rpp-core.php';
require_once __DIR__.'/stem-editor-core.php';
require_once __DIR__.'/stem-plugin-operations.php';
/** Per-song private REAPER project + mix manifest, reusing existing media_assets. */
function sf_stem_project_track(string $trackId):array {
    if(!preg_match('/^[A-Za-z0-9._-]{1,100}$/D',$trackId))throw new InvalidArgumentException('Invalid song ID.');
    foreach(sf_catalog() as $row)if(is_array($row)&&($row['id']??null)===$trackId)return $row;
    throw new InvalidArgumentException('Song does not exist.');
}
function sf_stem_project_dir():string {
    $dir=SF_ROOT.'/storage/stem-projects';
    if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Cannot create private project storage.');
    return $dir;
}
function sf_stem_project_path(string $id):string {
    sf_stem_project_track($id);
    return sf_stem_project_dir().'/'.hash('sha256',$id).'.json';
}
function sf_stem_project_read(string $id):?array {
    $path=sf_stem_project_path($id);
    if(!is_file($path))return null;
    $raw=file_get_contents($path);
    if(!is_string($raw)||strlen($raw)>10485760)throw new RuntimeException('Saved stem project is invalid.');
    $value=json_decode($raw,true);
    if(!is_array($value)||($value['track_id']??'')!==$id||($value['format']??'')!=='stonefellow.stem-rpp.v1')throw new RuntimeException('Saved stem project is invalid.');
    return $value;
}
function sf_stem_project_write(string $id,array $manifest,?int $expectedRevision):array {
    $path=sf_stem_project_path($id);$lock=fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock project.');
    try{
        $old=sf_stem_project_read($id);
        $revision=(int)($old['revision']??0);
        if($expectedRevision!==null&&$revision!==$expectedRevision)throw new RuntimeException('Stem project changed. Reload before saving.');
        $manifest['track_id']=$id;$manifest['revision']=$revision+1;
        $manifest['updated_at']=gmdate('c');
        $json=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>10485760)throw new RuntimeException('Project metadata exceeds 10 MB.');
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Unable to write private project.');
        @chmod($tmp,0660);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Unable to publish saved project.');}
        return $manifest;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_stem_project_import(string $id,string $content,string $name,int $adminId):array {
    $old=sf_stem_project_read($id);
    $parsed=sf_stem_rpp_parse($content);
    // Preserve previously linked media and user-edited mix settings by GUID.
    $existing=[];foreach((array)($old['tracks']??[]) as $row)$existing[(string)($row['guid']??'')]=$row;
    foreach($parsed['tracks'] as &$t){
        $prior=$existing[$t['guid']]??null;
        if(!is_array($prior))continue;
        foreach(['asset_uuid','role','name','volume','pan','mute','solo'] as $k)if(array_key_exists($k,$prior))$t[$k]=$prior[$k];
    }unset($t);
    $parsed['project_name']=substr(pathinfo(sf_stem_safe_filename($name),PATHINFO_FILENAME),0,180);
    $parsed['imported_by']=$adminId;$parsed['imported_at']=gmdate('c');
    $parsed['source_sha256']=hash('sha256',$content);
    // Persist the unmodified REAPER source in a private, non-web-readable tree.
    $source=sf_stem_project_dir().'/'.hash('sha256',$id).'.rpp';
    $tmp=$source.'.'.bin2hex(random_bytes(7)).'.tmp';
    if(file_put_contents($tmp,$content,LOCK_EX)===false)throw new RuntimeException('Cannot preserve REAPER project.');
    @chmod($tmp,0660);
    if(!rename($tmp,$source)){@unlink($tmp);throw new RuntimeException('Cannot commit REAPER project source.');}

    $parsed['mixes']=(array)($old['mixes']??[]);
    return sf_stem_project_write($id,$parsed,$old===null?0:(int)$old['revision']);
}
function sf_stem_project_update(string $id,array $payload):array {
    $old=sf_stem_project_read($id);
    if(!$old)throw new RuntimeException('Upload a REAPER project first.');
    $revision=$payload['revision']??null;
    if(!is_int($revision))throw new InvalidArgumentException('Expected integer project revision.');
    $action=(string)($payload['action']??'');
    if($action==='assign'){
        $guid=(string)($payload['guid']??'');$assetId=(int)($payload['asset_id']??0);
        $asset=sf_media_asset($assetId);
        if(!$asset||($asset['category']??'')!=='audio'||!in_array(strtolower((string)($asset['extension']??'')),['wav','mp3'],true))throw new RuntimeException('Choose an uploaded WAV or MP3 asset.');
        // Media Library is authoritative: attach a *private* stem role so the
        // public media route can deny playback to ordinary users.
        sf_media_attach($assetId,'track',$id,'stem_audio',['public_visible'=>false,'download_allowed'=>false]);
        // This is a private project track, never publish its media to the public player.
        $found=false;
        foreach($old['tracks'] as &$t)if(hash_equals((string)$t['guid'],$guid)){
            $t['asset_uuid']=(string)$asset['uuid'];$found=true;break;
        }unset($t);
        if(!$found)throw new InvalidArgumentException('Stem track not found.');
    }elseif($action==='edit_track'){
        $guid=(string)($payload['guid']??'');$found=false;
        foreach($old['tracks'] as &$t)if(hash_equals((string)$t['guid'],$guid)){
            $name=trim((string)($payload['name']??$t['name']));
            $role=trim((string)($payload['role']??$t['role']));
            if($name===''||strlen($name)>180||strlen($role)>80)
                throw new InvalidArgumentException('Invalid stem name or role.');
            $t['name']=$name;$t['role']=$role;$found=true;break;
        }unset($t);
        if(!$found)throw new InvalidArgumentException('Stem track not found.');
    }elseif($action==='save_mix'){
        $mix=(array)($payload['mix']??[]);
        $mixName=trim((string)($mix['name']??'Default'));
        if(strlen($mixName)>100||$mixName==='')throw new InvalidArgumentException('Mix name is required (max 100 characters).');
        $rows=(array)($mix['tracks']??[]);
        if(count($rows)>256)throw new InvalidArgumentException('Too many mix tracks.');
        $values=[];
        foreach($old['tracks'] as $t){
            $guid=(string)$t['guid'];$override=$rows[$guid]??[];
            if(!is_array($override))$override=[];
            $volume=$override['volume']??$t['volume'];$pan=$override['pan']??$t['pan'];
            if(!is_numeric($volume)||!is_numeric($pan)||!is_finite((float)$volume)||!is_finite((float)$pan))throw new InvalidArgumentException('Invalid stem gain or pan.');
            $values[$guid]=['volume'=>max(0,min(4,(float)$volume)),
                'pan'=>max(-1,min(1,(float)$pan)),
                'mute'=>!empty($override['mute']),'solo'=>!empty($override['solo'])];
        }
        $old['mixes']=array_slice((array)($old['mixes']??[]),-19,20,true);
        $old['mixes'][$mixName]=['name'=>$mixName,'tracks'=>$values,'saved_at'=>gmdate('c')];
    }elseif(in_array($action,[
        'plugin_add','plugin_settings','plugin_toggle','plugin_move',
        'plugin_remove','plugin_undo','plugin_redo'
    ],true)){
        sf_stem_plugin_apply($old,$payload);
    }elseif(in_array($action,['edit_clip','edit_fx','undo_edit','redo_edit'],true)){
        sf_stem_editor_apply($old,$payload);
    }else throw new InvalidArgumentException('Unsupported stem editor action.');
    return sf_stem_project_write($id,$old,$revision);
}
function sf_stem_project_with_assets(array $project):array {
    foreach($project['tracks'] as &$t){
        $ref=(string)($t['asset_uuid']??'');$t['audio_url']=null;$t['media_ready']=false;$t['audio_size_bytes']=0;
        if($ref!==''){
            $asset=sf_media_asset($ref);
            if($asset&&($asset['category']??'')==='audio'){
                $t['audio_url']='../api/media.php?id='.rawurlencode($ref);
                $t['media_ready']=true;$t['audio_size_bytes']=(int)($asset['size_bytes']??0);
            }
        }
    }unset($t);
    return $project;
}
