<?php
declare(strict_types=1);
require_once __DIR__.'/agent-canvas-images.php';

const SF_AGENT_CANVAS_PHOTO_MAX_BYTES=10*1024*1024;

function sf_agent_canvas_photo_ensure_schema(): void {
    static $ready=false;if($ready)return;
    sf_agent_canvas_image_ensure_schema();$db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize photo storage before a transaction.');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_photo_sources (
            user_id INTEGER NOT NULL,image_id TEXT NOT NULL,source_kind TEXT NOT NULL,
            sha256 TEXT NOT NULL,original_sha256 TEXT NOT NULL,original_size_bytes INTEGER NOT NULL,
            original_name TEXT NOT NULL,metadata_stripped INTEGER NOT NULL,created_at TEXT NOT NULL,
            PRIMARY KEY(user_id,image_id),UNIQUE(user_id,original_sha256),FOREIGN KEY(user_id,image_id) REFERENCES agent_canvas_images(user_id,id) ON DELETE CASCADE)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS agent_canvas_photo_sources (
            user_id BIGINT UNSIGNED NOT NULL,image_id CHAR(32) NOT NULL,source_kind VARCHAR(32) NOT NULL,
            sha256 CHAR(64) NOT NULL,original_sha256 CHAR(64) NOT NULL,original_size_bytes INT UNSIGNED NOT NULL,
            original_name VARCHAR(180) NOT NULL,metadata_stripped TINYINT(1) NOT NULL,created_at VARCHAR(40) NOT NULL,
            PRIMARY KEY(user_id,image_id),UNIQUE KEY idx_canvas_photo_source(user_id,original_sha256),CONSTRAINT fk_canvas_photo_image FOREIGN KEY(user_id,image_id)
            REFERENCES agent_canvas_images(user_id,id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}

/** Retain only EXIF's pixel orientation; camera identity, dates and GPS never leave the account. */
function sf_agent_canvas_photo_orientation(string $exif): ?string {
    if(str_starts_with($exif,"Exif\0\0"))$exif=substr($exif,6);
    if(strlen($exif)<8||!in_array(substr($exif,0,2),['II','MM'],true))return null;
    $little=substr($exif,0,2)==='II';$short=$little?'v':'n';$long=$little?'V':'N';
    if(unpack($short,substr($exif,2,2))[1]!==42)return null;
    $offset=unpack($long,substr($exif,4,4))[1];
    if($offset>strlen($exif)-2)return null;$count=unpack($short,substr($exif,$offset,2))[1];
    for($i=0;$i<min(1024,$count);$i++){
        $at=$offset+2+$i*12;if($at>strlen($exif)-12)return null;
        if(unpack($short,substr($exif,$at,2))[1]!==0x0112||unpack($short,substr($exif,$at+2,2))[1]!==3||unpack($long,substr($exif,$at+4,4))[1]!==1)continue;
        $orientation=unpack($short,substr($exif,$at+8,2))[1];
        if($orientation<1||$orientation>8)return null;
        return "Exif\0\0II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00".pack('v',$orientation)."\x00\x00\x00\x00\x00\x00";
    }
    return null;
}

/** Remove metadata containers without forwarding private EXIF/XMP or changing the photographed pixels. */
function sf_agent_canvas_photo_strip_metadata(string $bytes,string $mime): string {
    if($mime==='image/png'){
        $output=substr($bytes,0,8);$offset=8;$ended=false;$pixels=false;
        while($offset+12<=strlen($bytes)){
            $length=unpack('N',substr($bytes,$offset,4))[1];
            if($length>strlen($bytes)-$offset-12)throw new InvalidArgumentException('Invalid PNG photo.');
            $type=substr($bytes,$offset+4,4);$data=substr($bytes,$offset+8,$length);
            if(!hash_equals(pack('N',crc32($type.$data)),substr($bytes,$offset+8+$length,4)))
                throw new InvalidArgumentException('Invalid PNG photo.');
            if(in_array($type,['IHDR','PLTE','IDAT','IEND','tRNS','iCCP','sRGB','gAMA','cHRM'],true))$output.=substr($bytes,$offset,$length+12);
            if($type==='IDAT')$pixels=true;
            $offset+=$length+12;
            if($type==='IEND'){$ended=true;break;}
        }
        if(!$ended||!$pixels)throw new InvalidArgumentException('Invalid PNG photo.');
        return $output;
    }
    if($mime==='image/jpeg'){
        if(substr($bytes,0,2)!=="\xff\xd8")throw new InvalidArgumentException('Invalid JPEG photo.');
        $output="\xff\xd8";$offset=2;$size=strlen($bytes);
        while($offset<$size){
            if($bytes[$offset]!=="\xff")throw new InvalidArgumentException('Invalid JPEG photo.');
            while($offset<$size&&$bytes[$offset]==="\xff")$offset++;
            if($offset>=$size)break;$marker=ord($bytes[$offset++]);
            if($marker===0xd9)return $output."\xff\xd9";
            if($offset+2>$size)break;$length=unpack('n',substr($bytes,$offset,2))[1];
            if($length<2||$length>$size-$offset)break;
            $data=substr($bytes,$offset+2,$length-2);
            if($marker===0xe1&&($orientation=sf_agent_canvas_photo_orientation($data))!==null)
                $output.="\xff\xe1".pack('n',strlen($orientation)+2).$orientation;
            elseif(!(($marker>=0xe1&&$marker<=0xef)||$marker===0xfe)||
                ($marker===0xe2&&str_starts_with($data,"ICC_PROFILE\0"))||($marker===0xee&&str_starts_with($data,'Adobe')))
                $output.="\xff".chr($marker).substr($bytes,$offset,$length);
            $offset+=$length;
            if($marker===0xda){
                $start=$offset;
                while($offset+1<$size){
                    if($bytes[$offset]!=="\xff"){$offset++;continue;}
                    $next=ord($bytes[$offset+1]);
                    if($next===0||($next>=0xd0&&$next<=0xd7)){$offset+=2;continue;}
                    break;
                }
                $output.=substr($bytes,$start,$offset-$start);
            }
        }
        throw new InvalidArgumentException('Invalid JPEG photo.');
    }
    if($mime==='image/webp'){
        if(substr($bytes,0,4)!=='RIFF'||substr($bytes,8,4)!=='WEBP')throw new InvalidArgumentException('Invalid WebP photo.');
        $body='WEBP';$offset=12;$pixels=false;$size=strlen($bytes);
        if(unpack('V',substr($bytes,4,4))[1]+8!==$size)throw new InvalidArgumentException('Invalid WebP photo.');
        while($offset+8<=$size){
            $type=substr($bytes,$offset,4);$length=unpack('V',substr($bytes,$offset+4,4))[1];$padded=$length+($length%2);
            if($padded>$size-$offset-8)throw new InvalidArgumentException('Invalid WebP photo.');
            $data=substr($bytes,$offset+8,$length);
            if($type==='VP8X'&&strlen($data)>0)$data[0]=chr(ord($data[0])&~(0x08|0x04));
            if(in_array($type,['VP8 ','VP8L','VP8X','ALPH','ANIM','ANMF','ICCP'],true))$body.=$type.pack('V',$length).$data.($length%2?"\x00":'');
            if(in_array($type,['VP8 ','VP8L','ANMF'],true))$pixels=true;
            $offset+=8+$padded;
        }
        if(!$pixels||$offset!==$size)throw new InvalidArgumentException('Invalid WebP photo.');
        return 'RIFF'.pack('V',strlen($body)).$body;
    }
    throw new InvalidArgumentException('Choose a JPEG, PNG, or WebP photo.');
}

/** Store an account-owned upload. Client-supplied paths, owner IDs and MIME labels never establish provenance. */
function sf_agent_canvas_photo_store(int $userId,string $bytes,string $name='',string $title=''): array {
    if($userId<1||strlen($bytes)<200||strlen($bytes)>SF_AGENT_CANVAS_PHOTO_MAX_BYTES)
        throw new InvalidArgumentException('Choose a photo no larger than 10 MB.');
    $info=@getimagesizefromstring($bytes);$mime=$info['mime']??'';
    if(!in_array($mime,SF_AGENT_CANVAS_IMAGE_TYPES,true)||(int)$info[0]<128||(int)$info[1]<128||(int)$info[0]>4096||(int)$info[1]>4096)
        throw new InvalidArgumentException('Choose a JPEG, PNG, or WebP photo between 128 and 4096 pixels on each side.');
    if(function_exists('imagecreatefromstring')){
        $decoded=@imagecreatefromstring($bytes);
        if($decoded===false)throw new InvalidArgumentException('The photo could not be decoded.');
        imagedestroy($decoded);
    }
    $originalHash=hash('sha256',$bytes);$originalSize=strlen($bytes);
    $clean=sf_agent_canvas_photo_strip_metadata($bytes,$mime);
    $name=sf_clean_text(basename(str_replace('\\','/',$name)),180);
    $title=sf_clean_text($title,180)?:($name?:'Uploaded photo');
    sf_agent_canvas_photo_ensure_schema();$db=sf_db();$createdFile=null;
    $db->beginTransaction();
    try{
        $db->prepare('UPDATE users SET status=status WHERE id=? AND status=\'active\'')->execute([$userId]);
        $q=$db->prepare('SELECT image_id,sha256 FROM agent_canvas_photo_sources WHERE user_id=? AND original_sha256=?');$q->execute([$userId,$originalHash]);
        if(($existing=$q->fetch())&&($source=sf_agent_canvas_photo_source($userId,$existing['image_id']))!==null&&
            ($image=sf_agent_canvas_image_get($userId,$existing['image_id']))!==null){
            $db->commit();return $image+['source_kind'=>'uploaded_photo','is_own_photo'=>true,'sha256'=>$source['sha256']];
        }
        $id=bin2hex(random_bytes(16));
        $image=sf_agent_canvas_image_store($userId,$id,$title,['bytes'=>$clean],$createdFile);
        $db->prepare('INSERT INTO agent_canvas_photo_sources(user_id,image_id,source_kind,sha256,original_sha256,
            original_size_bytes,original_name,metadata_stripped,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$userId,$id,'actual_uploaded',hash('sha256',$clean),$originalHash,$originalSize,$name,1,gmdate('c')]);
        $db->commit();
        return $image+['source_kind'=>'uploaded_photo','is_own_photo'=>true,'sha256'=>hash('sha256',$clean)];
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();if($createdFile!==null)@unlink($createdFile);throw $error;
    }
}

/** Only a source verified against its current account-owned bytes is evidence of an actual upload. */
function sf_agent_canvas_photo_source(int $userId,string $imageId): ?array {
    if($userId<1||!sf_agent_canvas_image_id($imageId))return null;
    sf_agent_canvas_photo_ensure_schema();
    $q=sf_db()->prepare('SELECT p.* FROM agent_canvas_photo_sources p JOIN users u ON u.id=p.user_id AND u.status=\'active\'
        WHERE p.user_id=? AND p.image_id=? AND p.source_kind=\'actual_uploaded\'');$q->execute([$userId,$imageId]);$row=$q->fetch();
    if(!$row||!($file=sf_agent_canvas_image_file($userId,$imageId))||$file['size']>SF_AGENT_CANVAS_PHOTO_MAX_BYTES||
        !hash_equals((string)$row['sha256'],(string)@hash_file('sha256',$file['path'])))return null;
    return $file+['sha256'=>$row['sha256'],'source_kind'=>'actual_uploaded','original_sha256'=>$row['original_sha256'],'metadata_stripped'=>(bool)$row['metadata_stripped']];
}

/** Batch inventory metadata; generated artwork never becomes evidence of personal life. */
function sf_agent_canvas_photo_metadata(array $images,int $owner): array {
    if($owner<1||!$images)return [];sf_agent_canvas_photo_ensure_schema();
    $ids=[];foreach(array_slice($images,0,200) as $image)if(sf_agent_canvas_image_id($image['id']??null))$ids[]=$image['id'];
    if(!$ids)return [];$ids=array_values(array_unique($ids));
    $q=sf_db()->prepare('SELECT image_id,sha256 FROM agent_canvas_photo_sources WHERE user_id=? AND image_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $q->execute([$owner,...$ids]);$sources=array_column($q->fetchAll(),null,'image_id');$out=[];
    foreach($ids as $id){
        $out[$id]=['source_kind'=>'generated','is_own_photo'=>false];
        if(isset($sources[$id])&&($file=sf_agent_canvas_image_file($owner,$id))&&hash_equals($sources[$id]['sha256'],(string)@hash_file('sha256',$file['path'])))
            $out[$id]=['source_kind'=>'uploaded_photo','is_own_photo'=>true,'sha256'=>$sources[$id]['sha256']];
    }
    return $out;
}

function sf_agent_canvas_photo_missing_table(Throwable $error): bool {
    return $error instanceof PDOException&&($error->getCode()==='42S02'||str_contains(strtolower($error->getMessage()),'no such table'));
}

/** Privacy exports include only this account's metadata, never private storage keys or file paths. */
function sf_agent_canvas_photo_export(int $owner): array {
    $out=[];
    foreach(['agent_canvas_photo_sources','agent_canvas_image_analyses','agent_canvas_image_analysis_versions'] as $table){
        try{
            $q=sf_db()->prepare('SELECT * FROM '.$table.' WHERE user_id=?');$q->execute([$owner]);$rows=$q->fetchAll();
            foreach($rows as &$row){unset($row['reservation_key']);foreach(['analysis_json'=>'image_analysis','suggestions_json'=>'profile_suggestion_ids'] as $field=>$key)if(isset($row[$field])){
                $row[$key]=json_decode($row[$field],true);unset($row[$field]);
            }}unset($row);$out[$table]=$rows;
        }catch(Throwable $error){if(!sf_agent_canvas_photo_missing_table($error))throw $error;}
    }
    return $out;
}

/** Join an existing privacy transaction without introducing schema DDL or restoring cleared data. */
function sf_agent_canvas_photo_clear(int $owner): array {
    $out=[];
    foreach(['agent_canvas_image_analysis_versions','agent_canvas_image_analyses','agent_canvas_photo_sources'] as $table){
        try{$q=sf_db()->prepare('DELETE FROM '.$table.' WHERE user_id=?');$q->execute([$owner]);$out[$table]=$q->rowCount();}
        catch(Throwable $error){if(!sf_agent_canvas_photo_missing_table($error))throw $error;}
    }
    return $out;
}
