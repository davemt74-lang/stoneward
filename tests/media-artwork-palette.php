<?php
declare(strict_types=1);

define('SF_ROOT',sys_get_temp_dir().'/stonefellow-palette-'.bin2hex(random_bytes(8)));
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_db(): PDO {
    static $db=null;
    return $db??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function check(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}

require __DIR__.'/../api/media-artwork-palette.php';

$relative='storage/media/art.png';
$original=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDU0AAAAASUVORK5CYII=',true);
$palette=['version'=>SF_ARTWORK_PALETTE_VERSION,'sha256'=>hash('sha256',$original),'status'=>'ready','colors'=>['#112233','#cceeff']];
mkdir(SF_ROOT.'/storage/media',0700,true);
try{
    file_put_contents(SF_ROOT.'/'.$relative,$original);
    sf_media_ensure_schema();
    $db=sf_db();
    $db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,
        technical_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(['MART','image','image/png','png',
        'art.png',$relative,$palette['sha256'],strlen($original),'{"source":"test","data":{"unrelated":true}}',gmdate('c'),gmdate('c')]);
    $id=(int)$db->lastInsertId();
    check(sf_media_artwork_palette(sf_media_asset($id))===null,'an existing image needs no schema migration or preloaded palette');
    check(sf_media_palette_validate(array_replace($palette,['colors'=>['#111111;display:none']]),$palette['sha256'])===null,
        'arbitrary CSS and noncanonical colors cannot enter metadata');
    check(sf_media_palette_validate(array_replace($palette,['status'=>'insufficient_pixels','colors'=>[]]),$palette['sha256'])!==null,
        'a successful extraction with insufficient detail is cacheable');
    check(sf_media_save_artwork_palette($id,$palette)===$palette,'a validated palette is stored for the exact original bytes');
    $asset=sf_media_asset($id);
    check(sf_media_artwork_palette($asset)===$palette&&$asset['technical']['data']===['unrelated'=>true],
        'palette save preserves unrelated technical metadata');
    check(sf_media_save_artwork_palette($id,array_replace($palette,['colors'=>['#123456']]))===$palette,
        'a duplicate extraction for the same bytes and version keeps the saved result');
    check(sf_media_artwork_palette(array_replace($asset,['sha256'=>str_repeat('b',64)]))===null,
        'replacement bytes invalidate the saved palette');
    check(sf_media_artwork_palette(array_replace_recursive($asset,['technical'=>['artwork_palette'=>['version'=>'old']]]))===null,
        'extractor version changes invalidate older palettes');
    file_put_contents(SF_ROOT.'/'.$relative,str_repeat('x',strlen($original)));
    $rejected=false;
    try{sf_media_save_artwork_palette($id,$palette);}catch(InvalidArgumentException){$rejected=true;}
    check($rejected&&!sf_media_artwork_source_matches($asset),'same-size replacements invalidate the cached result even if its database digest is stale');
}finally{
    @unlink(SF_ROOT.'/'.$relative);
    @rmdir(SF_ROOT.'/storage/media');
    @rmdir(SF_ROOT.'/storage');
    @rmdir(SF_ROOT);
}
