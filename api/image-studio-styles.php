<?php
declare(strict_types=1);

require_once __DIR__.'/image-studio-moodboards.php';

/** 91B20H — Admin-only visual identity library. Style metadata is never an image-generation command. */
function sf_image_studio_seed_styles(): array {
    return [
        ['nebula-noir','Nebula Noir','Cosmic / nocturnal','Deep cinematic atmosphere, starfield restraint, sculptural silhouettes, subtle volumetric light and generous dark negative space. No lettering or UI.','#10182E','#614D8C','#7CD5EA'],
        ['desert-afterglow','Desert Afterglow','Warm / organic','Warm desert evening with long shadows, dusty analog texture, rich filmic contrast, tactile stone and atmospheric depth. No text overlays.','#442A2E','#D48454','#F6CE94'],
        ['analog-dream','Analog Dream','Vintage / surreal','Dreamlike 1970s editorial photography, practical effects, gentle film grain, imperfect halation and an intentional, limited analog palette. No logos.','#292638','#C57469','#F2D9AB'],
        ['ocean-signal','Ocean Signal','Luminous / meditative','Submerged cinematic environment, deep teal gradients, refracted shafts of light, quiet scale, luminous particles and soft organic motion. No captions.','#071C2C','#15647A','#7DE7DB']
    ];
}
function sf_image_studio_style_schema(): void {
    $pdo=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite)$pdo->exec("CREATE TABLE IF NOT EXISTS image_studio_styles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,name TEXT NOT NULL,
        mood TEXT NOT NULL,guidance TEXT NOT NULL,primary_hex TEXT NOT NULL,
        secondary_hex TEXT NOT NULL,accent_hex TEXT NOT NULL,
        inspiration_asset_id INTEGER NULL,is_builtin INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,created_by INTEGER NULL,
        created_at TEXT NOT NULL,updated_at TEXT NOT NULL)");
    else $pdo->exec("CREATE TABLE IF NOT EXISTS image_studio_styles (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,mood VARCHAR(80) NOT NULL,guidance TEXT NOT NULL,
        primary_hex CHAR(7) NOT NULL,secondary_hex CHAR(7) NOT NULL,accent_hex CHAR(7) NOT NULL,
        inspiration_asset_id BIGINT UNSIGNED NULL,is_builtin TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT UNSIGNED NULL,
        created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    sf_image_studio_moodboard_schema($pdo,$sqlite);
    $now=gmdate('c');
    $q=$pdo->prepare(($sqlite?'INSERT OR IGNORE':'INSERT IGNORE').' INTO image_studio_styles
        (slug,name,mood,guidance,primary_hex,secondary_hex,accent_hex,is_builtin,is_active,created_at,updated_at)
        VALUES(?,?,?,?,?,?,?,1,1,?,?)');
    foreach(sf_image_studio_seed_styles() as $seed)$q->execute([...$seed,$now,$now]);
}
function sf_image_studio_style_public(array $row): array {
    $row['id']=(int)$row['id'];$row['is_builtin']=(bool)$row['is_builtin'];
    $row['inspiration_asset_id']=(int)($row['inspiration_asset_id']??0);
    $row['inspiration_uuid']='';
    if($row['inspiration_asset_id']>0){
        require_once __DIR__.'/media-core.php';
        $asset=sf_media_asset($row['inspiration_asset_id']);
        if($asset&&($asset['category']??'')==='image')
            $row['inspiration_uuid']=(string)($asset['uuid']??'');
    }
    $row['moodboard']=sf_image_studio_moodboard_items((int)$row['id']);
    unset($row['created_by'],$row['created_at'],$row['updated_at']);
    return $row;
}
function sf_image_studio_styles(): array {
    sf_image_studio_style_schema();
    $q=sf_db()->query('SELECT * FROM image_studio_styles WHERE is_active=1 ORDER BY is_builtin DESC,mood ASC,name ASC');
    return array_map('sf_image_studio_style_public',$q->fetchAll());
}
function sf_image_studio_style(int $id): ?array {
    if($id<1)return null;
    sf_image_studio_style_schema();
    $q=sf_db()->prepare('SELECT * FROM image_studio_styles WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([$id]);$row=$q->fetch();
    return $row?sf_image_studio_style_public($row):null;
}
function sf_image_studio_style_color($value): string {
    $hex=strtoupper(trim((string)$value));
    if(!preg_match('/^#[0-9A-F]{6}$/D',$hex))
        throw new InvalidArgumentException('Choose a six-digit hex color for every palette swatch.');
    return $hex;
}
function sf_image_studio_save_style(int $adminId,array $input): array {
    sf_image_studio_style_schema();
    $name=sf_clean_text((string)($input['name']??''),100);
    $mood=sf_clean_text((string)($input['mood']??''),80);
    $guidance=sf_clean_text((string)($input['guidance']??''),1200);
    if(strlen($name)<3||strlen($mood)<3||strlen($guidance)<30)
        throw new InvalidArgumentException('Style name, mood and at least 30 characters of visual direction are required.');
    $colors=[
        sf_image_studio_style_color($input['primary_hex']??''),
        sf_image_studio_style_color($input['secondary_hex']??''),
        sf_image_studio_style_color($input['accent_hex']??'')
    ];
    $inspirationId=max(0,(int)($input['inspiration_asset_id']??0));
    if($inspirationId>0){
        $eligible=false;
        foreach(sf_image_gen_available_references() as $a)
            if((int)$a['id']===$inspirationId){$eligible=true;break;}
        if(!$eligible)throw new InvalidArgumentException('Choose an available, approved Media Library image for the style preview.');
    }
    $slug='style-'.bin2hex(random_bytes(8));$now=gmdate('c');
    $q=sf_db()->prepare('INSERT INTO image_studio_styles
        (slug,name,mood,guidance,primary_hex,secondary_hex,accent_hex,inspiration_asset_id,
         is_builtin,is_active,created_by,created_at,updated_at)
        VALUES(?,?,?,?,?,?,?,?,0,1,?,?,?)');
    $q->execute([$slug,$name,$mood,$guidance,...$colors,$inspirationId?:null,$adminId,$now,$now]);
    return sf_image_studio_style((int)sf_db()->lastInsertId())?:[];
}
function sf_image_studio_archive_style(int $id): void {
    sf_image_studio_style_schema();
    if($id<1)throw new InvalidArgumentException('Choose a custom style.');
    $q=sf_db()->prepare('UPDATE image_studio_styles SET is_active=0,updated_at=?
        WHERE id=? AND is_builtin=0 AND is_active=1');
    $q->execute([gmdate('c'),$id]);
    if($q->rowCount()!==1)throw new RuntimeException('Only active custom styles may be archived.');
}
/** Adds reusable art direction to an editable prompt; inspiration image bytes never leave Media Library. */
function sf_image_studio_style_direction(array $style): string {
    $colors=implode(', ',[$style['primary_hex'],$style['secondary_hex'],$style['accent_hex']]);
    return 'Visual identity: '.$style['name'].'. Mood: '.$style['mood'].
        '. Palette (primary, secondary, accent): '.$colors.'. Art direction: '.$style['guidance'].'.';
}
