<?php
declare(strict_types=1);

require_once __DIR__.'/media-core.php';

const SF_ARTWORK_PALETTE_VERSION='color-thief@3.5.0/sf1';

function sf_media_palette_validate(mixed $palette,string $sha256): ?array {
    if(!is_array($palette)||($palette['version']??null)!==SF_ARTWORK_PALETTE_VERSION||
        !preg_match('/\A[a-f0-9]{64}\z/',$sha256)||($palette['sha256']??null)!==$sha256)return null;
    $status=$palette['status']??null;
    $colors=$palette['colors']??null;
    if(!is_array($colors)||!array_is_list($colors)||count($colors)>6||
        !in_array($status,['ready','insufficient_pixels'],true)||($status==='ready')!==($colors!==[]))return null;
    foreach($colors as $color)if(!is_string($color)||!preg_match('/\A#[a-f0-9]{6}\z/',$color))return null;
    return ['version'=>SF_ARTWORK_PALETTE_VERSION,'sha256'=>$sha256,'status'=>$status,'colors'=>array_values(array_unique($colors))];
}

function sf_media_artwork_palette(array $asset): ?array {
    if(($asset['category']??null)!=='image')return null;
    return sf_media_palette_validate($asset['technical']['artwork_palette']??null,(string)($asset['sha256']??''));
}

function sf_media_artwork_source_matches(array $asset): bool {
    $size=(int)$asset['size_bytes'];
    $root=realpath(SF_ROOT.'/storage/media');
    $file=realpath(SF_ROOT.'/'.ltrim($asset['stored_path'],'/'));
    return $size>0&&$size<=24*1024*1024&&$root&&$file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&
        is_file($file)&&filesize($file)===$size&&@hash_file('sha256',$file)===$asset['sha256'];
}

function sf_media_save_artwork_palette(int $id,mixed $input): array {
    $db=sf_db();
    $comparison=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY technical_json = BINARY ?':'technical_json = ?';
    $save=$db->prepare('UPDATE media_assets SET technical_json=? WHERE id=? AND sha256=? AND '.$comparison);
    for($attempt=0;$attempt<3;$attempt++){
        $asset=sf_media_asset($id);
        if(!$asset||$asset['category']!=='image')throw new InvalidArgumentException('Choose an available image asset.');
        $palette=sf_media_palette_validate($input,$asset['sha256']);
        if(!$palette)throw new InvalidArgumentException('The artwork palette or image changed. Refresh and try again.');
        if(!sf_media_artwork_source_matches($asset))throw new InvalidArgumentException('The artwork file changed or is unavailable.');
        $existing=sf_media_artwork_palette($asset);
        if($existing)return $existing;
        $technical=$asset['technical'];
        $technical['artwork_palette']=$palette;
        $save->execute([sf_media_json($technical),$id,$palette['sha256'],$asset['technical_json']]);
        if($save->rowCount()===1)return $palette;
    }
    throw new RuntimeException('Artwork metadata changed. Refresh and try again.');
}
