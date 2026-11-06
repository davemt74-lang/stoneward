<?php
declare(strict_types=1);
require_once __DIR__.'/agent-canvas-image-skill.php';
require_once __DIR__.'/agent-canvas-image-references.php';

/** Initialize the existing stores before the caller acquires its account/entry locks. */
function sf_canvas_gallery_assets_prepare(): void {
    sf_agent_canvas_image_ensure_schema();
    sf_agent_canvas_reference_ensure_schema();
}

function sf_canvas_gallery_assets_id(mixed $id): bool {
    return is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/',$id)===1;
}

/** All paths are constructed from host-generated identities, inside protected storage/. */
function sf_canvas_gallery_assets_directory(?string $id=null,bool $create=false): ?string {
    if($id!==null&&!sf_canvas_gallery_assets_id($id))return null;
    $path=realpath(SF_ROOT);if($path===false)return null;
    foreach(['storage','canvas-gallery',...($id===null?[]:[$id])] as $part){
        $path.='/'.$part;
        if(is_link($path))return null;
        if(!is_dir($path)&&(!$create||(!@mkdir($path,0700)&&!is_dir($path))))return null;
        if(realpath($path)!==$path)return null;
    }
    return $path;
}

/** Read the authorized file once and validate the bytes that will actually be copied. */
function sf_canvas_gallery_assets_read(array $file): array {
    $size=$file['size']??0;$path=$file['path']??null;
    if(!is_int($size)||$size<200||$size>SF_AGENT_CANVAS_IMAGE_MAX_BYTES||!is_string($path)||is_link($path))
        throw new InvalidArgumentException('A canvas image is unavailable for publication. Choose a saved or public reference image.');
    $handle=@fopen($path,'rb');if($handle===false)throw new InvalidArgumentException('A canvas image could not be read.');
    try{
        $stat=fstat($handle);
        if(!$stat||($stat['mode']&0170000)!==0100000||$stat['size']!==$size)throw new InvalidArgumentException('A canvas image changed before it could be copied.');
        $bytes=stream_get_contents($handle,$size+1);
        if(!is_string($bytes)||strlen($bytes)!==$size)throw new InvalidArgumentException('A canvas image could not be read completely.');
    }finally{fclose($handle);}
    $info=@getimagesizefromstring($bytes);$mime=$info['mime']??'';
    $width=(int)($info[0]??0);$height=(int)($info[1]??0);$sha=hash('sha256',$bytes);
    if(!in_array($mime,SF_AGENT_CANVAS_IMAGE_TYPES,true)||$mime!==($file['mime']??null)||$width<1||$height<1||$width*$height>60000000||
        (isset($file['sha256'])&&(!is_string($file['sha256'])||!hash_equals($file['sha256'],$sha))))
        throw new InvalidArgumentException('A canvas image no longer matches its authorized pixels.');
    return ['bytes'=>$bytes,'mime'=>$mime,'size_bytes'=>$size,'width'=>$width,'height'=>$height,'sha256'=>$sha];
}

function sf_canvas_gallery_assets_write(string $path,string $bytes): void {
    $handle=@fopen($path,'xb');if($handle===false)throw new RuntimeException('Gallery image storage is unavailable.');
    try{
        $offset=0;$length=strlen($bytes);
        while($offset<$length){$written=fwrite($handle,substr($bytes,$offset));if($written===false||$written===0)throw new RuntimeException('The gallery image could not be saved.');$offset+=$written;}
        if(!fflush($handle))throw new RuntimeException('The gallery image could not be saved.');
        @chmod($path,0600);
    }finally{fclose($handle);}
}

/** Image identities are rewritten without copying private labels or operational metadata. */
function sf_canvas_gallery_assets_rewrite(array $scene,array $map): array {
    foreach($map as $old=>$new){
        $scene=sf_agent_canvas_image_apply($scene,$new,$old);
        // Canvas programs may query a bound class, CSS property or quoted image identity.
        if(isset($scene['js'])){
            $scene['js']=preg_replace('/(?<![A-Za-z0-9_-])sf-image-'.preg_quote($old,'/').'(?![A-Za-z0-9_-])/','sf-image-'.$new,$scene['js']);
            foreach(['"',"'",'`'] as $quote)$scene['js']=str_replace($quote.$old.$quote,$quote.$new.$quote,$scene['js']);
        }
    }
    if(!empty($scene['js']))$scene['js_hash']=base64_encode(hash('sha256',$scene['js'],true));
    return $scene;
}

/** Only ordinary renderer bindings can be frozen; URLs are never fetched or passed through. */
function sf_canvas_gallery_assets_bindings(array $scene): array {
    $ids=sf_agent_canvas_image_bindings($scene);
    if(count($ids)>SF_AGENT_CANVAS_IMAGE_LIMIT)throw new InvalidArgumentException('A gallery canvas can bind up to four distinct saved images.');
    foreach($ids as $id)if(!sf_agent_canvas_image_asset_id($id))throw new InvalidArgumentException('Finish saving every canvas image before submitting it.');
    preg_match_all('/(?:--)?sf-image-((?:ref-)?[a-f0-9]{32}|new(?:-[0-9]+)?)(?![A-Za-z0-9_-])/',$scene['js']??'',$script);
    if(array_diff(array_unique($script[1]),$ids))throw new InvalidArgumentException('Bind each canvas image in its HTML or CSS before sharing it.');
    if(preg_match('~(?:agent-canvas-image\.php|storage/canvas-images/|storage/canvas-gallery/|(?:https?:)?//[^\s"\x27`]+\.(?:png|jpe?g|webp)(?:[?#][^\s"\x27`]*)?)~i',$scene['js']??''))
        throw new InvalidArgumentException('Use saved-image bindings for canvas images before sharing. Private or external image URLs cannot be published.');
    return $ids;
}

/**
 * Capture existing authorized pixels, never generate or download images. The caller discards
 * source_map after selecting a preview and removes this directory if its later DB write fails.
 */
function sf_canvas_gallery_assets_snapshot(int $uid,array $scene,string $publicId): array {
    if($uid<1||!sf_canvas_gallery_assets_id($publicId)||!is_int($scene['id']??null)||$scene['id']<1||
        sf_agent_scene_history_get($uid,$scene['id'])===null)throw new InvalidArgumentException('Choose your own saved canvas for publication.');
    sf_canvas_gallery_assets_prepare();
    $keys=['canvas_kind','track_id','html','css','js','js_hash','catalog_refs','music_inputs','artist_inputs','agent_design','agent_palette'];
    $copy=array_intersect_key($scene,array_flip($keys));
    if(sf_agent_scene_code($copy)===null)throw new InvalidArgumentException('The saved canvas code is invalid.');
    $ids=sf_canvas_gallery_assets_bindings($copy);$base=sf_canvas_gallery_assets_directory(null,true);
    if($base===null||!@mkdir($base.'/'.$publicId,0700))throw new RuntimeException('This gallery snapshot could not be created.');
    $dir=$base.'/'.$publicId;$map=[];$manifest=[];$sources=[];$images=[];
    try{
        foreach($ids as $offset=>$id){
            $source=sf_agent_canvas_image_asset_file($uid,$id);
            if($source===null)throw new InvalidArgumentException('A bound image is private to another account or no longer approved. Choose an available saved image.');
            $image=sf_canvas_gallery_assets_read($source);$new=bin2hex(random_bytes(16));$map[$id]=$new;
            sf_canvas_gallery_assets_write($dir.'/'.$new.'.bin',$image['bytes']);
            $manifest[]=['id'=>$new]+array_intersect_key($image,array_flip(['mime','size_bytes','width','height','sha256']));
            $images[]=['id'=>$new,'title'=>'Canvas image '.($offset+1),'width'=>$image['width'],'height'=>$image['height'],
                'url'=>'api/canvas-gallery.php?action=asset&id='.$publicId.'&asset_id='.$new];
            if(str_starts_with($id,'ref-'))$sources[$new]=['id'=>$id,'sha256'=>$image['sha256']];
        }
        // Private provenance permits immediate revocation when the artist withdraws a reference.
        sf_canvas_gallery_assets_write($dir.'/.sources.json',json_encode(['version'=>1,'references'=>(object)$sources],JSON_THROW_ON_ERROR));
        $copy=sf_canvas_gallery_assets_rewrite($copy,$map);if($images)$copy['image_assets']=$images;
        if(sf_agent_scene_code($copy)===null)throw new InvalidArgumentException('The copied canvas image bindings are invalid.');
        return ['scene'=>$copy,'assets'=>$manifest,'source_map'=>$map];
    }catch(Throwable $error){sf_canvas_gallery_assets_remove($publicId);throw $error;}
}

/** Private provenance is not a gallery/public DTO and never contains private image identities. */
function sf_canvas_gallery_assets_sources(string $publicId): ?array {
    $dir=sf_canvas_gallery_assets_directory($publicId);if($dir===null)return null;
    $path=$dir.'/.sources.json';if(is_link($path)||!is_file($path)||filesize($path)>4096)return null;
    try{$raw=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
    if(!is_array($raw)||($raw['version']??null)!==1||!is_array($raw['references']??null)||count($raw['references'])>SF_AGENT_CANVAS_IMAGE_LIMIT)return null;
    foreach($raw['references'] as $asset=>$source)if(!sf_canvas_gallery_assets_id($asset)||!is_array($source)||
        !sf_agent_canvas_reference_id($source['id']??null)||!is_string($source['sha256']??null)||preg_match('/\A[a-f0-9]{64}\z/',$source['sha256'])!==1)return null;
    return $raw['references'];
}

/** Fail closed on a revoked, protected, removed or changed artist reference. */
function sf_canvas_gallery_assets_references_available(string $publicId,bool $verifyBytes=false): bool {
    if(!sf_canvas_gallery_assets_id($publicId))return false;
    $entry=sf_canvas_gallery_asset_entry($publicId);if($entry===null)return false;
    $q=sf_db()->prepare('SELECT status FROM users WHERE id=?');$q->execute([(int)$entry['user_id']]);$active=$q->fetchColumn()==='active';$q->closeCursor();if(!$active)return false;
    $sources=sf_canvas_gallery_assets_sources($publicId);
    // Code-only publications have no pixel provenance or files to retain.
    if($sources===null)return ($entry['assets']??null)===[];
    foreach($sources as $source){
        $row=sf_agent_canvas_reference_row($source['id']);
        $file=$row===null?null:sf_agent_canvas_reference_asset_file($row,$verifyBytes);
        if($file===null||!hash_equals($source['sha256'],$file['sha256']))return false;
    }
    return true;
}

function sf_canvas_gallery_assets_manifest(mixed $raw): ?array {
    if(!is_array($raw)||!array_is_list($raw)||count($raw)>SF_AGENT_CANVAS_IMAGE_LIMIT)return null;
    $items=[];
    foreach($raw as $item){
        if(!is_array($item)||array_diff(array_keys($item),['id','mime','size_bytes','width','height','sha256'])||
            !sf_canvas_gallery_assets_id($item['id']??null)||isset($items[$item['id']])||!in_array($item['mime']??null,SF_AGENT_CANVAS_IMAGE_TYPES,true)||
            !is_int($item['size_bytes']??null)||$item['size_bytes']<200||$item['size_bytes']>SF_AGENT_CANVAS_IMAGE_MAX_BYTES||
            !is_int($item['width']??null)||$item['width']<1||!is_int($item['height']??null)||$item['height']<1||$item['width']*$item['height']>60000000||
            !is_string($item['sha256']??null)||preg_match('/\A[a-f0-9]{64}\z/',$item['sha256'])!==1)return null;
        $items[$item['id']]=$item;
    }
    return $items;
}

/** Fresh DB authorization on every request; no public/static storage path is returned. */
function sf_canvas_gallery_assets_file(string $publicId,string $assetId,?array $viewer=null): ?array {
    if(!sf_canvas_gallery_assets_id($publicId)||!sf_canvas_gallery_assets_id($assetId))return null;
    $entry=sf_canvas_gallery_asset_entry($publicId);if(!$entry||($entry['id']??null)!==$publicId)return null;
    $q=sf_db()->prepare('SELECT status FROM users WHERE id=?');$q->execute([(int)$entry['user_id']]);$active=$q->fetchColumn()==='active';$q->closeCursor();if(!$active)return null;
    if(($entry['status']??null)!=='approved'){
        if(!in_array($entry['status']??null,['pending','returned','declined'],true))return null;
        $uid=(int)($viewer['id']??0);if($uid<1)return null;
        $q=sf_db()->prepare('SELECT role,status FROM users WHERE id=?');$q->execute([$uid]);$user=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$user||$user['status']!=='active'||($uid!==(int)$entry['user_id']&&$user['role']!=='admin'))return null;
    }
    $manifest=sf_canvas_gallery_assets_manifest($entry['assets']??null);
    if($manifest===null||!isset($manifest[$assetId])||!sf_canvas_gallery_assets_references_available($publicId,true)||($dir=sf_canvas_gallery_assets_directory($publicId))===null)return null;
    $item=$manifest[$assetId];$path=$dir.'/'.$assetId.'.bin';
    if(is_link($path)||!is_file($path)||realpath($path)!==$path)return null;
    $handle=@fopen($path,'rb');if($handle===false)return null;
    try{
        $stat=fstat($handle);
        if(!$stat||($stat['mode']&0170000)!==0100000||$stat['size']!==$item['size_bytes'])return null;
        $hash=hash_init('sha256');hash_update_stream($hash,$handle);
        if(!hash_equals($item['sha256'],hash_final($hash))||!rewind($handle))return null;
        $result=['handle'=>$handle,'mime'=>$item['mime'],'size'=>$item['size_bytes'],'sha256'=>$item['sha256']];$handle=null;return $result;
    }finally{if(is_resource($handle))fclose($handle);}
}

/** Remove only a single host-generated snapshot; authorization is revoked in DB first. */
function sf_canvas_gallery_assets_remove(string $publicId): void {
    if(!sf_canvas_gallery_assets_id($publicId)||($dir=sf_canvas_gallery_assets_directory($publicId))===null)return;
    foreach(scandir($dir)?:[] as $name)if($name==='.sources.json'||preg_match('/\A[a-f0-9]{32}\.bin\z/',$name)===1)@unlink($dir.'/'.$name);
    @rmdir($dir);
}

/** Trusted import of already-validated gallery pixels, including valid small artist references. */
function sf_canvas_gallery_assets_private_store(int $uid,string $id,array $image,?string &$createdFile): array {
    $createdFile=null;
    if($image['width']>=128&&$image['height']>=128&&$image['width']<=4096&&$image['height']<=4096)
        return sf_agent_canvas_image_store($uid,$id,'Remixed canvas image',['bytes'=>$image['bytes']],$createdFile);
    $db=sf_db();if(!$db->inTransaction())throw new LogicException('Import gallery pixels inside the remix transaction.');
    $q=$db->prepare('SELECT status FROM users WHERE id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid]);$active=$q->fetchColumn()==='active';$q->closeCursor();
    if(!$active||$uid<1||!sf_agent_canvas_image_id($id))throw new InvalidArgumentException('Account unavailable.');
    // The same protected private image storage and schema serve these exact bytes afterwards.
    $root=realpath(SF_ROOT);$dir=sf_agent_canvas_image_dir($uid);
    foreach([$root.'/storage',$root.'/storage/canvas-images',$dir] as $part){
        if(is_link($part)||(!is_dir($part)&&!@mkdir($part,0700))||realpath($part)!==$part)throw new RuntimeException('Canvas image storage is unavailable.');
    }
    $key=bin2hex(random_bytes(24));$path=$dir.'/'.$key.'.bin';
    try{
        sf_canvas_gallery_assets_write($path,$image['bytes']);$now=gmdate('c');
        $db->prepare('INSERT INTO agent_canvas_images(user_id,id,storage_key,title,mime,size_bytes,width,height,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$uid,$id,$key,'Remixed canvas image',$image['mime'],$image['size_bytes'],$image['width'],$image['height'],$now]);
        $createdFile=$path;return sf_agent_canvas_image_metadata(['id'=>$id,'title'=>'Remixed canvas image','width'=>$image['width'],'height'=>$image['height'],'created_at'=>$now]);
    }catch(Throwable $error){@unlink($path);throw $error;}
}

/** Copy approved pixels into this listener's own storage; later gallery withdrawal is independent. */
function sf_canvas_gallery_assets_remix(int $uid,array $entry): array {
    $id=$entry['id']??null;
    if($uid<1||!sf_canvas_gallery_assets_id($id)||!sf_db()->inTransaction())throw new InvalidArgumentException('Create the remix inside your account transaction.');
    $fresh=sf_canvas_gallery_asset_entry($id);$manifest=sf_canvas_gallery_assets_manifest($entry['assets']??null);
    if(!$fresh||$fresh['status']!=='approved'||$manifest===null||$fresh['assets']!==$entry['assets']||!is_array($entry['scene']??null))
        throw new InvalidArgumentException('This gallery canvas is no longer available for remixing.');
    $scene=$entry['scene'];$ids=sf_canvas_gallery_assets_bindings($scene);
    if(array_diff($ids,array_keys($manifest)))throw new InvalidArgumentException('A gallery image is missing from its approved snapshot.');
    $map=[];$created=[];$files=[];$images=[];
    try{
        foreach($ids as $old){
            $file=sf_canvas_gallery_assets_file($id,$old);if($file===null)throw new InvalidArgumentException('A gallery image is no longer available for remixing.');
            try{$bytes=stream_get_contents($file['handle'],$file['size']+1);}finally{fclose($file['handle']);}
            if(!is_string($bytes)||strlen($bytes)!==$file['size']||!hash_equals($file['sha256'],hash('sha256',$bytes)))throw new RuntimeException('The gallery image could not be copied.');
            $image=$manifest[$old]+['bytes'=>$bytes];$new=bin2hex(random_bytes(16));$createdFile=null;
            $images[]=sf_canvas_gallery_assets_private_store($uid,$new,$image,$createdFile);
            if($createdFile!==null)$files[]=$createdFile;$created[]=$new;$map[$old]=$new;
        }
        unset($scene['id'],$scene['previous_id'],$scene['image_assets'],$scene['gallery_id'],$scene['verification_parent_id']);
        $scene=sf_canvas_gallery_assets_rewrite($scene,$map);if($images)$scene['image_assets']=$images;
        if(sf_agent_scene_code($scene)===null)throw new InvalidArgumentException('The remixed canvas bindings are invalid.');
        return ['scene'=>$scene,'created_ids'=>$created,'created_files'=>$files];
    }catch(Throwable $error){foreach($files as $file)@unlink($file);throw $error;}
}
