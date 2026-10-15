<?php
declare(strict_types=1);
/**
 * 86A single ZIP ingest: one REAPER .rpp and its source WAV/MP3 stems.
 * This is a private Admin-only service used from the existing Stem Studio.
 * Streams audio from ZipArchive in 256 KiB chunks; never extracts to web paths.
 */
require_once __DIR__.'/stem-studio-core.php';
const SF_STEM_ZIP_MAX_ENTRIES = 512;
const SF_STEM_ZIP_MAX_BYTES = 2147483648; // 2 GiB decompressed
const SF_STEM_ZIP_MAX_STEM = 536870912; // 512 MiB per WAV/MP3
const SF_STEM_ZIP_MAX_UPLOAD = 2147483648; // 2 GiB compressed upload

function sf_stem_zip_entry_name(string $path):string {
    if($path===''||strlen($path)>1024||str_contains($path,"\0")
       ||str_contains($path,'\\')||str_starts_with($path,'/')
       ||preg_match('/^[A-Za-z]:/', $path))
        throw new InvalidArgumentException('Unsafe ZIP entry name.');
    foreach(explode('/',$path) as $seg)
        if($seg==='.'||$seg==='..')throw new InvalidArgumentException('ZIP traversal path rejected.');
    return sf_stem_safe_filename($path);
}
function sf_stem_zip_bounded_stream(ZipArchive $zip,string $name,int $limit):string {
    $handle=$zip->getStream($name);
    if(!$handle)throw new RuntimeException('ZIP project cannot be read.');
    $raw='';try{
        while(!feof($handle)){
            $chunk=fread($handle,262144);
            if($chunk===false)throw new RuntimeException('ZIP project extraction failed.');
            if($chunk==='')break;
            if(strlen($raw)+strlen($chunk)>$limit)throw new InvalidArgumentException('REAPER project too large.');
            $raw.=$chunk;
        }
    }finally{fclose($handle);}
    return $raw;
}
function sf_stem_zip_stream_asset(ZipArchive $zip,string $name,int $limit,string $stage):string {
    $handle=$zip->getStream($name);
    if(!$handle)throw new RuntimeException('Cannot read zipped audio.');
    $temp=tempnam($stage,'stem-');
    if(!$temp){fclose($handle);throw new RuntimeException('Cannot stage audio.');}
    $out=fopen($temp,'wb');
    if(!$out){fclose($handle);@unlink($temp);throw new RuntimeException('Cannot stage audio.');}
    $size=0;
    try{
        while(!feof($handle)){
            $chunk=fread($handle,262144);
            if($chunk===false)throw new RuntimeException('Zipped audio extraction failed.');
            if($chunk==='')break;
            $size+=strlen($chunk);
            if($size>$limit)throw new InvalidArgumentException('Zipped stem exceeds 512 MiB.');
            if(fwrite($out,$chunk)!==strlen($chunk))throw new RuntimeException('Cannot stage audio bytes.');
        }
        if(!$size)throw new InvalidArgumentException('Stem audio is empty.');
    }catch(Throwable $e){
        fclose($out);fclose($handle);@unlink($temp);throw $e;
    }
    fclose($out);fclose($handle);
    return $temp;
}
function sf_stem_zip_import(string $trackId,string $zipPath,string $uploadName,
                            int $adminId,?callable $ingestAudio=null):array {
    sf_stem_project_track($trackId);
    if(!class_exists(ZipArchive::class))
        throw new RuntimeException('PHP ZIP extension is required for project bundles.');
    if(!preg_match('/\\.zip$/i',$uploadName)||!is_file($zipPath)
       ||filesize($zipPath)===false||filesize($zipPath)>SF_STEM_ZIP_MAX_UPLOAD)
        throw new InvalidArgumentException('Upload a ZIP bundle up to 2 GiB.');
    $zip=new ZipArchive();
    if($zip->open($zipPath)!==true)throw new InvalidArgumentException('ZIP file is invalid.');
    $stage='';
    try{
        if($zip->numFiles<1||$zip->numFiles>SF_STEM_ZIP_MAX_ENTRIES)
            throw new InvalidArgumentException('ZIP must contain 1-512 files.');
        $all=[];$rppNames=[];$byBasename=[];$total=0;
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);
            if(!is_array($stat))throw new InvalidArgumentException('Invalid ZIP metadata.');
            $name=(string)($stat['name']??'');
            if(str_ends_with($name,'/'))continue;
            $base=sf_stem_zip_entry_name($name);
            if($base==='')throw new InvalidArgumentException('Unnamed ZIP file.');
            $rawSize=(int)($stat['size']??0);$compressed=(int)($stat['comp_size']??0);
            if($rawSize<0||$compressed<0)throw new InvalidArgumentException('Invalid entry size.');
            $total+=$rawSize;
            if($total>SF_STEM_ZIP_MAX_BYTES)throw new InvalidArgumentException('ZIP expansion exceeds 2 GiB.');
            if($compressed<1&&$rawSize>0||$rawSize>1000000&&$rawSize>200*max(1,$compressed))
                throw new InvalidArgumentException('Excessive ZIP compression ratio.');
            if(method_exists($zip,'getExternalAttributesIndex')){
                $opsys=0;$attrs=0;
                if($zip->getExternalAttributesIndex($i,$opsys,$attrs)
                   &&$opsys===ZipArchive::OPSYS_UNIX
                   &&(($attrs>>16)&0170000)===0120000)
                    throw new InvalidArgumentException('ZIP symbolic links are not allowed.');
            }
            $lower=strtolower($base);
            if(isset($byBasename[$lower]))throw new InvalidArgumentException('Duplicate ZIP filename: '.$base);
            $byBasename[$lower]=['entry'=>$name,'filename'=>$base,'size'=>$rawSize];
            if(preg_match('/\\.rpp$/i',$base))$rppNames[]=$lower;
            if(preg_match('/\\.(wav|mp3)$/i',$base)&&$rawSize>SF_STEM_ZIP_MAX_STEM)
                throw new InvalidArgumentException('Stem exceeds 512 MiB: '.$base);
            $all[$lower]=true;
        }
        if(count($rppNames)!==1)throw new InvalidArgumentException('ZIP needs exactly one .rpp project.');
        $rpp=$byBasename[$rppNames[0]];
        if($rpp['size']>8388608)throw new InvalidArgumentException('REAPER project exceeds 8 MiB.');
        $content=sf_stem_zip_bounded_stream($zip,$rpp['entry'],8388608);
        $parsed=sf_stem_rpp_parse($content);
        $wanted=[];
        foreach($parsed['tracks'] as $t){
            $file=(string)($t['source_file']??'');
            if($file!=='')$wanted[strtolower(sf_stem_safe_filename($file))]=true;
        }
        $ingestAudio??=static fn(string $path,string $name,int $user):array=>
            sf_stem_zip_ingest_audio($path,$name,$user);
        $assets=[];$unmatched=[];
        $stage=sf_stem_project_dir().'/.zip-stage';
        if(!is_dir($stage)&&!mkdir($stage,0770,true)&&!is_dir($stage))
            throw new RuntimeException('Cannot stage private ZIP contents.');
        foreach($byBasename as $lower=>$row){
            if(!preg_match('/\\.(wav|mp3)$/i',$lower))continue;
            if(!isset($wanted[$lower])){$unmatched[]=$row['filename'];continue;}
            $temp=sf_stem_zip_stream_asset($zip,$row['entry'],SF_STEM_ZIP_MAX_STEM,$stage);
            try{
                $asset=$ingestAudio($temp,$row['filename'],$adminId);
                if(!is_array($asset)||($asset['category']??'')!=='audio'
                    ||!isset($asset['id'],$asset['uuid']))
                    throw new RuntimeException('The existing Media Library rejected a stem.');
                $assets[$lower]=$asset;
            }finally{@unlink($temp);}
        }
        // Project is saved only after ZIP validation and media ingest succeed.
        $project=sf_stem_project_import($trackId,$content,$rpp['filename'],$adminId);
        $assigned=0;
        foreach($project['tracks'] as &$track){
            $key=strtolower(sf_stem_safe_filename((string)($track['source_file']??'')));
            if(!isset($assets[$key]))continue;
            $asset=$assets[$key];
            sf_media_attach((int)$asset['id'],'track',$trackId,'stem_audio',
                ['public_visible'=>false,'download_allowed'=>false]);
            $track['asset_uuid']=(string)$asset['uuid'];
            $assigned++;
        }unset($track);
        if($assigned)$project=sf_stem_project_write($trackId,$project,(int)$project['revision']);
        $missing=[];
        foreach(array_keys($wanted) as $name)if(!isset($assets[$name]))$missing[]=$name;
        return ['project'=>$project,'report'=>[
            'stem_tracks'=>count($project['tracks']),
            'matched_stems'=>$assigned,
            'uploaded_media_assets'=>count($assets),
            'missing_files'=>$missing,
            'unmatched_audio_files'=>$unmatched,
            'project_file'=>$rpp['filename']
        ]];
    }finally{
        $zip->close();
        // Stage directory is shared, so remove only our private temporary files.
    }
}
/** Reuse the existing secure Media Library DB, MIME and analyzer pipeline. */
function sf_stem_zip_ingest_audio(string $path,string $name,int $adminId):array {
    sf_media_ensure_schema();
    $parent=realpath(sf_stem_project_dir().'/.zip-stage');
    $real=realpath($path);
    if(!$parent||!$real||!str_starts_with($real,$parent.DIRECTORY_SEPARATOR)
       ||is_link($path)||!is_file($real))
        throw new RuntimeException('Untrusted ZIP audio staging path.');
    $size=filesize($real);
    if(!$size||$size>SF_STEM_ZIP_MAX_STEM)throw new InvalidArgumentException('Invalid stem size.');
    $type=sf_media_detect_type($real,$name);
    if(($type['category']??'')!=='audio'||!in_array($type['extension']??'', ['wav','mp3'],true))
        throw new InvalidArgumentException('Only verified WAV/MP3 stems are permitted.');
    $sha=hash_file('sha256',$real);
    if(!$sha)throw new RuntimeException('Stem integrity check failed.');
    $q=sf_db()->prepare('SELECT id FROM media_assets WHERE sha256=? AND size_bytes=? LIMIT 1');
    $q->execute([$sha,$size]);$existing=(int)($q->fetchColumn()?:0);
    if($existing)return sf_media_asset($existing)?:[];
    $uuid=sf_media_uuid();$ext=sf_media_safe_ext($type['extension']);
    $folder='storage/media/library/audio/'.substr($sha,0,2);
    $abs=SF_ROOT.'/'.$folder;
    if(!is_dir($abs)&&!mkdir($abs,0770,true)&&!is_dir($abs))
        throw new RuntimeException('Media Library folder unavailable.');
    $rel=$folder.'/'.$uuid.'.'.$ext;$dest=SF_ROOT.'/'.$rel;
    if(!rename($real,$dest))throw new RuntimeException('Cannot transfer stem into Media Library.');
    try{
        $a=sf_media_analyze($dest,'audio',$ext);
        $tags=(array)($a['technical']['normalized_tags']??[]);
        $now=gmdate('c');
        $title=sf_clean_text($tags['title']??pathinfo($name,PATHINFO_FILENAME),255);
        $q=sf_db()->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,width,height,duration_seconds,bitrate_bps,sample_rate_hz,channels,bits_per_sample,codec,title,caption,alt_text,credit,copyright_text,technical_json,variants_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$uuid,'audio',$type['mime'],$ext,sf_clean_text($name,255),$rel,
            $sha,$size,(int)$a['width'],(int)$a['height'],round((float)$a['duration'],3),
            (int)$a['bitrate'],(int)$a['sample_rate'],(int)$a['channels'],(int)$a['bits'],
            sf_clean_text($a['codec'],120),$title,'','','','',
            sf_media_json($a['technical']),sf_media_json([]),$adminId?:null,$now,$now]);
        return sf_media_asset((int)sf_db()->lastInsertId())?:[];
    }catch(Throwable $e){@unlink($dest);throw $e;}
}
