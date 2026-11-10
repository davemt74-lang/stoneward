<?php
declare(strict_types=1);

/** Host-selected public artwork. Install before opening any session transaction. */
function sf_jukebox_branding_schema(bool $mysql,string $engine): void {
    $blob=$mysql?'MEDIUMTEXT':'TEXT';
    sf_db()->exec("CREATE TABLE IF NOT EXISTS jukebox_host_branding (
        owner_id BIGINT PRIMARY KEY, image $blob NULL, logo_hash CHAR(64) NULL,
        revision BIGINT NOT NULL DEFAULT 0)$engine");
}

function sf_jukebox_branding_metadata(?array $row,bool $available=false): array {
    $hash=$row['logo_hash']??null;
    $valid=$available&&is_string($hash)&&preg_match('/\A[a-f0-9]{64}\z/',$hash);
    return ['logo_url'=>$valid?'api/jukebox.php?action=host_logo&owner='.(int)$row['owner_id'].'&v='.$hash:null,
        'revision'=>max(0,(int)($row['revision']??0)),'owner_available'=>$available];
}

/** Polling responses never load or serialize the uploaded image bytes. */
function sf_jukebox_branding_map(array $owners): array {
    $owners=array_values(array_unique(array_filter(array_map('intval',$owners),static fn(int $id):bool=>$id>0)));
    if(!$owners)return [];
    $map=[];
    foreach($owners as $id)$map[$id]=sf_jukebox_branding_metadata(null);
    $marks=implode(',',array_fill(0,count($owners),'?'));
    $rows=sf_jukebox_query("SELECT u.id AS owner_id,b.logo_hash,b.revision FROM users u
        LEFT JOIN jukebox_host_branding b ON b.owner_id=u.id WHERE u.status='active' AND u.id IN ($marks)",$owners)->fetchAll();
    foreach($rows as $row)$map[(int)$row['owner_id']]=sf_jukebox_branding_metadata($row,true);
    return $map;
}

function sf_jukebox_branding_get(int $owner): array {
    return sf_jukebox_branding_map([$owner])[$owner]??sf_jukebox_branding_metadata(null);
}

function sf_jukebox_branding_can_edit(int $owner,bool $admin): bool {
    return $owner>0&&($admin
        ||(bool)sf_jukebox_query('SELECT 1 FROM jukebox_venues WHERE owner_id=?',[$owner])->fetchColumn()
        ||(bool)sf_jukebox_query('SELECT 1 FROM jukebox_sessions WHERE owner_id=? LIMIT 1',[$owner])->fetchColumn());
}

function sf_jukebox_branding_image(mixed $input): ?array {
    if($input===null)return null;
    if(!is_string($input)||strlen($input)>1_400_064
        ||!preg_match('/\Adata:(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+\/=]+)\z/D',$input,$parts))
        throw new InvalidArgumentException('Choose a PNG, JPEG, or WebP image under 1 MB.');
    $bytes=base64_decode($parts[2],true);
    $info=$bytes===false?false:@getimagesizefromstring($bytes);
    if($bytes===false||strlen($bytes)<1||strlen($bytes)>1_048_576||base64_encode($bytes)!==$parts[2]
        ||!$info||!in_array($info['mime']??null,['image/png','image/jpeg','image/webp'],true)
        ||$info['mime']!==$parts[1]||$info[0]<1||$info[1]<1||$info[0]*$info[1]>20_000_000)
        throw new InvalidArgumentException('Choose a valid PNG, JPEG, or WebP image under 1 MB and 20 megapixels.');
    // Decode when GD is available, while keeping the existing shared-host image capability optional.
    if(function_exists('imagecreatefromstring')){
        $decoded=@imagecreatefromstring($bytes);
        if($decoded===false)throw new InvalidArgumentException('This image could not be decoded. Choose a different image.');
        imagedestroy($decoded);
    }
    return ['image'=>$info['mime'].';base64,'.base64_encode($bytes),'hash'=>hash('sha256',$bytes)];
}

/** Lock only this host's artwork; a logo edit never touches player/session revisions. */
function sf_jukebox_branding_update(int $owner,array $body,bool $admin): array {
    if(array_diff_key($body,['expected_revision'=>true,'image'=>true])
        ||!array_key_exists('image',$body)||!is_int($body['expected_revision']??null)
        ||$body['expected_revision']<0)
        throw new InvalidArgumentException('Reload the host logo before saving.');
    $image=sf_jukebox_branding_image($body['image']);
    $db=sf_db();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $db->beginTransaction();
    // Account closure takes the same account lock before clearing optional artwork.
    // SQLite must write before its first read to avoid promoting a stale read snapshot.
    if(!$mysql)sf_jukebox_query('UPDATE users SET id=id WHERE id=?',[$owner]);
    $account=sf_jukebox_query('SELECT status,role FROM users WHERE id=?'.($mysql?' FOR UPDATE':''),[$owner])->fetch();
    if(!$account||$account['status']!=='active')
        throw new DomainException('This host account is unavailable.');
    if(!sf_jukebox_branding_can_edit($owner,$admin&&$account['role']==='admin'))throw new DomainException('A host account is required.');
    $insert=$mysql?'INSERT IGNORE INTO jukebox_host_branding (owner_id) VALUES (?)'
        :'INSERT INTO jukebox_host_branding (owner_id) VALUES (?) ON CONFLICT(owner_id) DO NOTHING';
    sf_jukebox_query($insert,[$owner]);
    sf_jukebox_query('UPDATE jukebox_host_branding SET revision=revision WHERE owner_id=?',[$owner]);
    $current=sf_jukebox_query('SELECT owner_id,logo_hash,revision FROM jukebox_host_branding WHERE owner_id=?',[$owner])->fetch();
    if((int)$current['revision']!==$body['expected_revision'])
        throw new DomainException('The host logo changed. Reopen Host logo to review the latest image.');
    $hash=$image['hash']??null;
    if($hash!==$current['logo_hash']){
        sf_jukebox_query('UPDATE jukebox_host_branding SET image=?,logo_hash=?,revision=revision+1 WHERE owner_id=?',
            [$image['image']??null,$hash,$owner]);
        $current['logo_hash']=$hash;$current['revision']=(int)$current['revision']+1;
    }
    $metadata=sf_jukebox_branding_metadata($current,true);
    $db->commit();
    return $metadata;
}

function sf_jukebox_branding_source(mixed $owner,mixed $version): array {
    $owner=is_string($owner)?filter_var($owner,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
    if($owner===false||!is_string($version)||!preg_match('/\A[a-f0-9]{64}\z/',$version))
        throw new OutOfBoundsException('Host logo not found.');
    $image=sf_jukebox_query("SELECT b.image FROM jukebox_host_branding b JOIN users u ON u.id=b.owner_id
        WHERE b.owner_id=? AND b.logo_hash=? AND u.status='active'",[$owner,$version])->fetchColumn();
    if(!is_string($image)||!preg_match('/\A(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+\/=]+)\z/D',$image,$parts))
        throw new OutOfBoundsException('Host logo not found.');
    $bytes=base64_decode($parts[2],true);
    if($bytes===false||!hash_equals($version,hash('sha256',$bytes)))throw new OutOfBoundsException('Host logo not found.');
    return ['mime'=>$parts[1],'bytes'=>$bytes];
}

/** Closure must not create optional Jukebox storage for an account that never used it. */
function sf_jukebox_branding_clear_user(int $owner): array {
    $catalog=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jukebox_host_branding'"
        :"SELECT name FROM sqlite_master WHERE type='table' AND name='jukebox_host_branding'";
    if(!sf_jukebox_query($catalog)->fetchColumn())return [];
    return ['jukebox_host_branding'=>sf_jukebox_query('DELETE FROM jukebox_host_branding WHERE owner_id=?',[$owner])->rowCount()];
}
